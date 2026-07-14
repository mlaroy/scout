<?php

namespace Cascadia\Scout;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Search;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Statamic\Fields\Fields;

/**
 * Orchestrates a CP assistant chat turn. Claude receives the component
 * catalog as context and a draft_page tool whose input is validated by
 * the deterministic assembler — the model can never write entry data
 * directly, only propose plans that survive validation.
 */
class AssistantService
{
    public function __construct(
        protected AssistantClient $client,
        protected SiteContext $site,
        protected PageAssembler $assembler,
    ) {}

    /**
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @param  array{collection?: string, entry_id?: string}  $context  Where the editor currently is in the CP.
     * @return array{reply: string, draft: ?array}
     */
    public function chat(array $messages, array $context = [], ?\Closure $onEvent = null): array
    {
        $draft = null;
        $lastError = null;
        $emit = $onEvent ?? fn () => null;
        $onText = $onEvent ? fn (string $delta) => $emit(['type' => 'text', 'delta' => $delta]) : null;
        $onActivity = $onEvent ? fn (array $activity) => $emit(['type' => 'progress', 'label' => $this->activityLabel($activity)]) : null;

        for ($iteration = 0; $iteration < config('scout.max_iterations'); $iteration++) {
            $response = $this->client->complete($this->systemPrompt($context), $messages, $this->tools(), $onText, $onActivity);

            if ($response['stop_reason'] !== 'tool_use') {
                return ['reply' => $response['text'], 'draft' => $draft];
            }

            $messages[] = ['role' => 'assistant', 'content' => $response['raw_content']];

            $results = [];

            foreach ($response['tool_calls'] as $call) {
                $emit(['type' => 'progress', 'label' => $this->progressLabel($call)]);

                [$content, $isError, $draftResult] = $this->handleToolCall($call);

                if ($isError && in_array($call['name'], ['draft_page', 'draft_entry', 'update_page', 'update_entry'])) {
                    $emit(['type' => 'progress', 'label' => 'Fixing validation issues']);
                }

                if ($isError) {
                    $lastError = $content;
                    Log::info("Assistant tool error [{$call['name']}]: {$content}");
                }

                if ($draftResult) {
                    $draft = $draftResult;
                }

                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $call['id'],
                    'content' => $content,
                    'is_error' => $isError,
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return [
            'reply' => "Even a Sasquatch loses the trail sometimes. The last problem was:\n\n".($lastError ?? 'unknown')."\n\nTry rephrasing, or simplify the request.",
            'draft' => $draft,
        ];
    }

    /**
     * Live labels for model activity while a turn is still streaming.
     */
    protected function activityLabel(array $activity): string
    {
        $writing = in_array($activity['name'] ?? '', ['draft_page', 'draft_entry', 'update_page', 'update_entry']);

        return match ($activity['type']) {
            'thinking' => 'Thinking it through',
            'tool_start' => $writing ? 'Writing the draft' : 'Working',
            'tool_input' => $writing
                ? 'Writing the draft — about '.max(1, (int) round(($activity['chars'] ?? 0) / 6)).' words in'
                : 'Working',
            default => 'Working',
        };
    }

    /**
     * A human label for what a tool call is doing, streamed to the editor.
     */
    protected function progressLabel(array $call): string
    {
        $input = $call['input'];

        return match ($call['name']) {
            'get_component_fields' => 'Checking the '.str($input['component'] ?? 'component')->after('_')->replace('_', ' ').' fields',
            'get_collection_fields' => 'Reading the '.($input['collection'] ?? 'collection').' fields',
            'search_assets' => 'Searching images'.(filled($input['query'] ?? '') ? " for \u{201C}{$input['query']}\u{201D}" : ''),
            'find_pages' => 'Finding '."\u{201C}".($input['query'] ?? '')."\u{201D}",
            'get_page' => 'Reading the current content',
            'draft_page', 'draft_entry' => 'Assembling the draft',
            'update_page', 'update_entry' => 'Applying your changes',
            default => 'Working',
        };
    }

    /**
     * @return array{0: string, 1: bool, 2: ?array}
     */
    protected function handleToolCall(array $call): array
    {
        return match ($call['name']) {
            'get_component_fields' => [$this->componentFields($call['input']['component'] ?? ''), false, null],
            'search_assets' => [$this->searchAssets($call['input']['query'] ?? ''), false, null],
            'find_pages' => [$this->findEntries($call['input']['query'] ?? '', $call['input']['collection'] ?? null, (bool) ($call['input']['search_content'] ?? false)), false, null],
            'get_collection_fields' => [$this->collectionFields($call['input']['collection'] ?? ''), false, null],
            'draft_entry' => $this->draftEntry($call['input']['plan'] ?? null),
            'update_entry' => $this->updateEntry($call['input']['entry_id'] ?? '', $call['input']['plan'] ?? null),
            'get_page' => [$this->pageContents($call['input']['entry_id'] ?? ''), false, null],
            'draft_page' => $this->draftPage($call['input']['plan'] ?? null),
            'update_page' => $this->updatePage($call['input']['entry_id'] ?? '', $call['input']['plan'] ?? null),
            default => ["Unknown tool: {$call['name']}", true, null],
        };
    }

    /**
     * @return array{0: string, 1: bool, 2: ?array}
     */
    protected function draftPage(mixed $plan): array
    {
        if (! is_array($plan)) {
            return ['The plan must be an object with title and sections.', true, null];
        }

        $result = $this->assembler->validate($plan);

        if ($result['errors']) {
            return ["The plan has validation errors — fix these and call draft_page again:\n- ".implode("\n- ", $result['errors']), true, null];
        }

        try {
            $entry = $this->assembler->assemble($plan);
        } catch (RuntimeException $exception) {
            return [$exception->getMessage(), true, null];
        }

        $draft = $this->draftPayload($entry);

        return ["Draft created (unpublished). Edit URL: {$draft['edit_url']}", false, $draft];
    }

    /**
     * @return array{0: string, 1: bool, 2: ?array}
     */
    protected function updatePage(string $entryId, mixed $plan): array
    {
        if (! is_array($plan)) {
            return ['The plan must be an object with title and sections.', true, null];
        }

        $wasPublished = (bool) Entry::find($entryId)?->published();

        try {
            $entry = $this->assembler->update($entryId, $plan);
        } catch (RuntimeException $exception) {
            return [$exception->getMessage(), true, null];
        }

        $draft = $this->draftPayload($entry);

        return [
            $wasPublished
                ? "Changes saved as a working copy — the live page is unchanged until the editor publishes the revision. Edit URL: {$draft['edit_url']}"
                : "Draft updated. Edit URL: {$draft['edit_url']}",
            false,
            $draft,
        ];
    }

    /**
     * @return array{0: string, 1: bool, 2: ?array}
     */
    protected function draftEntry(mixed $plan): array
    {
        if (! is_array($plan)) {
            return ['The plan must be an object with collection, title, and fields.', true, null];
        }

        $plan = $this->applyUserDefaults($plan);

        try {
            $entry = $this->assembler->assembleEntry($plan);
        } catch (RuntimeException $exception) {
            return [$exception->getMessage(), true, null];
        }

        $draft = $this->draftPayload($entry);

        return ["Draft created (unpublished) in {$draft['collection']}. Edit URL: {$draft['edit_url']}", false, $draft];
    }

    /**
     * @return array{0: string, 1: bool, 2: ?array}
     */
    protected function updateEntry(string $entryId, mixed $plan): array
    {
        if (! is_array($plan)) {
            return ['The plan must be an object with the fields to change.', true, null];
        }

        $wasPublished = (bool) Entry::find($entryId)?->published();

        try {
            $entry = $this->assembler->updateEntry($entryId, $plan);
        } catch (RuntimeException $exception) {
            return [$exception->getMessage(), true, null];
        }

        $draft = $this->draftPayload($entry);

        return [
            $wasPublished
                ? "Changes saved as a working copy — the live entry is unchanged until the editor publishes the revision. Edit URL: {$draft['edit_url']}"
                : "Draft updated. Edit URL: {$draft['edit_url']}",
            false,
            $draft,
        ];
    }

    /**
     * Default any unfilled users-type field (author etc.) to the editor
     * driving the chat.
     */
    protected function applyUserDefaults(array $plan): array
    {
        $collection = Collection::find($plan['collection'] ?? '');
        $current = User::current();

        if (! $collection || ! $current) {
            return $plan;
        }

        foreach ($collection->entryBlueprint()->fields()->all() as $handle => $field) {
            if ($field->type() === 'users' && ! isset($plan['fields'][$handle])) {
                $plan['fields'][$handle] = $current->id();
            }
        }

        return $plan;
    }

    /**
     * Blueprint fields for a document-style collection.
     */
    protected function collectionFields(string $handle): string
    {
        $collection = Collection::find($handle);

        if (! $collection || in_array($handle, config('scout.excluded_collections', [])) || ! $this->userCanViewCollection($handle)) {
            return "Unknown or off-limits collection \"{$handle}\".";
        }

        $builderField = $this->site->builderField();

        return json_encode([
            'collection' => $handle,
            'dated' => $collection->dated(),
            'uses_page_builder' => $builderField && $collection->entryBlueprint()->fields()->all()->has($builderField),
            'fields' => $collection->entryBlueprint()->fields()->all()->except(array_filter(['title', 'slug', $builderField]))->map(fn ($field) => [
                'handle' => $field->handle(),
                'type' => $field->type(),
                'config' => collect($field->config())->only([
                    'options', 'default', 'max_items', 'max_files', 'collections', 'taxonomies', 'sets', 'fields', 'if', 'instructions',
                ])->filter()->all(),
            ])->values()->all(),
        ]);
    }

    protected function draftPayload(\Statamic\Contracts\Entries\Entry $entry): array
    {
        return [
            'id' => $entry->id(),
            'title' => $entry->get('title'),
            'slug' => $entry->slug(),
            'collection' => $entry->collectionHandle(),
            'edit_url' => cp_route('collections.entries.edit', [$entry->collectionHandle(), $entry->id()]),
            'sections' => ($builderField = $this->site->builderField())
                ? collect($entry->get($builderField))->pluck('type')->all()
                : [],
        ];
    }

    /**
     * Current contents of an entry, shaped like a page plan so the model
     * can revise it and submit the whole thing back through update_page.
     */
    protected function pageContents(string $entryId): string
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            return "No entry with id \"{$entryId}\".";
        }

        if (in_array($entry->collectionHandle(), config('scout.excluded_collections', [])) || ! $this->userCanViewCollection($entry->collectionHandle())) {
            return "No entry with id \"{$entryId}\".";
        }

        $payload = [
            'entry_id' => $entry->id(),
            'title' => $entry->get('title'),
            'slug' => $entry->slug(),
            'collection' => $entry->collectionHandle(),
            'published' => $entry->published(),
        ];

        // A pending working copy is the latest state of the content — read
        // from it so revisions build on it instead of the stale live version.
        if ($entry->published() && $entry->revisionsEnabled() && $entry->hasWorkingCopy()) {
            $entry = $entry->fromWorkingCopy();
            $payload['title'] = $entry->get('title');
            $payload['has_working_copy'] = true;
        }

        $builderField = $this->site->builderField();

        if ($builderField && $entry->has($builderField)) {
            $payload['sections'] = collect($entry->get($builderField, []))->map(function ($section) {
                $fields = collect($section)->except(['id', 'type', 'enabled'])->all();

                return ['component' => $section['type'], 'fields' => $fields];
            })->values()->all();

            $payload['fields'] = collect($entry->data())
                ->except(['title', $builderField, 'blueprint', 'updated_by', 'updated_at'])
                ->all();
        } else {
            $payload['fields'] = collect($entry->data())->except(['title', 'blueprint', 'updated_by', 'updated_at'])->all();
        }

        return json_encode($payload);
    }

    /**
     * Find entries by title or slug fragment across collections, so the
     * model can resolve "the services draft" or "Joey's profile" to an
     * entry id without asking the editor.
     */
    protected function findEntries(string $query, ?string $collection = null, bool $searchContent = false): string
    {
        $query = mb_strtolower(trim($query));

        $collections = collect(Collection::handles())
            ->reject(fn ($handle) => in_array($handle, config('scout.excluded_collections', [])))
            ->filter(fn ($handle) => $this->userCanViewCollection($handle))
            ->when($collection, fn ($handles) => $handles->filter(fn ($handle) => $handle === $collection))
            ->values()
            ->all();

        $entries = $this->searchEntries($query, $collections, $searchContent)
            ->take(15)
            ->map(fn ($entry) => [
                'entry_id' => $entry->id(),
                'title' => $entry->get('title'),
                'slug' => $entry->slug(),
                'collection' => $entry->collectionHandle(),
                'published' => $entry->published(),
                'edit_url' => cp_route('collections.entries.edit', [$entry->collectionHandle(), $entry->id()]),
                'url' => $entry->url(),
            ])
            ->values();

        if ($entries->isEmpty()) {
            return "No pages match \"{$query}\". Try a broader term.";
        }

        return $entries->toJson();
    }

    /**
     * Entries matching a query: through the configured Statamic search
     * index when one is set (recommended for large sites), otherwise a
     * direct scan of titles, slugs, and optionally content.
     *
     * @return \Illuminate\Support\Collection<int, \Statamic\Contracts\Entries\Entry>
     */
    /**
     * Read-side permission parity: the assistant only surfaces collections
     * the current CP user could open themselves (Statamic's CollectionPolicy,
     * i.e. "view {handle} entries"; supers pass). Outside an authenticated
     * context (CLI, tests) there is no user to scope to — the doorway that
     * invoked us is responsible for access, so nothing is hidden.
     */
    protected function userCanViewCollection(string $handle): bool
    {
        $user = User::current();

        if (! $user) {
            return true;
        }

        $collection = Collection::find($handle);

        return $collection && $user->can('view', $collection);
    }

    protected function searchEntries(string $query, array $collections, bool $searchContent): \Illuminate\Support\Collection
    {
        // The stache query builder treats whereIn('collection', []) as
        // unconstrained, which would leak everything the filters removed.
        if ($collections === []) {
            return collect();
        }

        if ($query !== '' && ($index = config('scout.search_index'))) {
            try {
                return collect(Search::index($index)->ensureExists()->search($query)->get())
                    ->map(fn ($result) => method_exists($result, 'getSearchable') ? $result->getSearchable() : $result)
                    ->filter(fn ($item) => $item instanceof \Statamic\Contracts\Entries\Entry)
                    ->filter(fn ($entry) => in_array($entry->collectionHandle(), $collections))
                    ->values();
            } catch (\Throwable $exception) {
                report($exception); // fall through to the direct scan
            }
        }

        return Entry::query()
            ->whereIn('collection', $collections)
            ->get()
            ->filter(fn ($entry) => $query === ''
                || str_contains(mb_strtolower((string) $entry->get('title')), $query)
                || str_contains(mb_strtolower((string) $entry->slug()), $query)
                || ($searchContent && str_contains(mb_strtolower(json_encode($entry->data())), $query)))
            ->values();
    }

    /**
     * Search the asset library by filename or alt text. Empty query
     * lists everything (the library is starter-kit sized).
     */
    protected function searchAssets(string $query): string
    {
        $query = mb_strtolower(trim($query));

        $assets = AssetContainer::all()
            ->filter(fn ($container) => ($user = User::current()) === null || $user->can('view', $container))
            ->flatMap(fn ($container) => $container->assets())
            ->filter(fn ($asset) => $query === ''
                || str_contains(mb_strtolower($asset->basename()), $query)
                || str_contains(mb_strtolower((string) $asset->get('alt')), $query))
            ->take(25)
            ->map(fn ($asset) => [
                'path' => $asset->path(),
                'container' => $asset->containerHandle(),
                'alt' => $asset->get('alt'),
                'is_image' => $asset->isImage(),
            ])
            ->values();

        if ($assets->isEmpty()) {
            return "No assets match \"{$query}\". Try a broader term, or an empty query to list everything.";
        }

        return $assets->toJson();
    }

    protected function componentFields(string $handle): string
    {
        $sets = $this->site->builderSets();

        if (! $sets->has($handle)) {
            return "Unknown component \"{$handle}\". Available: ".$sets->keys()->sort()->implode(', ');
        }

        $fields = (new Fields($sets->get($handle)['fields'] ?? []))->all();

        return json_encode($fields->map(fn ($field) => [
            'handle' => $field->handle(),
            'type' => $field->type(),
            'config' => collect($field->config())->only([
                'options', 'default', 'max_items', 'max_files', 'collections', 'sets', 'fields', 'if',
            ])->filter()->all(),
        ])->values()->all());
    }

    protected function tools(): array
    {
        return [
            [
                'name' => 'get_component_fields',
                'description' => 'Get the exact field handles, types, and options for one component. Call this before drafting a plan that uses a component whose fields you are not certain about.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'component' => ['type' => 'string', 'description' => 'The component handle, e.g. content_basic_hero'],
                    ],
                    'required' => ['component'],
                ],
            ],
            [
                'name' => 'find_pages',
                'description' => 'Find entries in any collection by title or slug fragment. Use this whenever the editor refers to content by name ("the services draft", "Joey\'s profile") — never ask the editor for an entry id. If several match, ask which one they mean by title.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Title or slug fragment, e.g. "services"'],
                        'collection' => ['type' => 'string', 'description' => 'Optional collection handle to narrow the search (pages, blog, team, ...)'],
                        'search_content' => ['type' => 'boolean', 'description' => 'Also search inside entry content — use for topic questions like "have we written about winter tours?"'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_collection_fields',
                'description' => 'Get the blueprint fields for a document-style collection (blog, team, testimonials, jobs). Call this before drafting an entry there so you use real field handles.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'collection' => ['type' => 'string', 'description' => 'The collection handle'],
                    ],
                    'required' => ['collection'],
                ],
            ],
            [
                'name' => 'draft_entry',
                'description' => 'Create an unpublished draft in a document-style collection (blog posts, team members, ...). Not for pages — pages use draft_page with sections. Bard and markdown fields accept plain text. Author-type fields default to the editor.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'plan' => [
                            'type' => 'object',
                            'description' => 'The entry plan',
                            'properties' => [
                                'collection' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                                'fields' => ['type' => 'object', 'description' => 'Field values keyed by blueprint handle'],
                            ],
                            'required' => ['collection', 'title', 'fields'],
                        ],
                    ],
                    'required' => ['plan'],
                ],
            ],
            [
                'name' => 'update_entry',
                'description' => 'Revise a document-style entry (blog post, team member, ...). Send only the fields you are changing — they merge onto the entry. Drafts update in place; published entries save as a working copy the editor reviews and publishes (never live directly). Not for builder pages (use update_page).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'entry_id' => ['type' => 'string'],
                        'plan' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'fields' => ['type' => 'object', 'description' => 'Only the field values to change'],
                            ],
                        ],
                    ],
                    'required' => ['entry_id', 'plan'],
                ],
            ],
            [
                'name' => 'search_assets',
                'description' => 'Search the site\'s asset library by filename or alt text. Use this to find existing images for image fields — use the returned path as the field value. You cannot upload new files.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Filename or alt text fragment, e.g. "hiker". Empty lists everything.'],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'get_page',
                'description' => 'Get the current contents of an entry, shaped like a page plan. Call this before revising a draft so you start from what is actually there.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'entry_id' => ['type' => 'string', 'description' => 'The entry id'],
                    ],
                    'required' => ['entry_id'],
                ],
            ],
            [
                'name' => 'update_page',
                'description' => 'Replace an entry\'s content with a revised plan. Send the complete plan (all sections, not just changed ones) — it replaces everything. Drafts update in place; published entries save as a working copy the editor reviews and publishes (the live page never changes directly). Published entries in collections without revisions are refused.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'entry_id' => ['type' => 'string', 'description' => 'The entry id of the draft to revise'],
                        'plan' => [
                            'type' => 'object',
                            'description' => 'The complete revised page plan: {title, fields?, sections: [{component, fields}]}. Include the fields object (from get_page) to keep or change page-level settings.',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'fields' => ['type' => 'object', 'description' => 'Page-level settings outside the builder (e.g. main_header_style)'],
                                'sections' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'component' => ['type' => 'string'],
                                            'fields' => ['type' => 'object'],
                                        ],
                                        'required' => ['component', 'fields'],
                                    ],
                                ],
                            ],
                            'required' => ['title', 'sections'],
                        ],
                    ],
                    'required' => ['entry_id', 'plan'],
                ],
            ],
            [
                'name' => 'draft_page',
                'description' => 'Create an unpublished draft page from a page plan. The plan is validated against the real fieldsets; on errors, fix them and call again. Never tell the user a page was created unless this tool succeeded.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'plan' => [
                            'type' => 'object',
                            'description' => 'The page plan: {title, collection?, slug?, fields?, sections: [{component, fields}]}',
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'collection' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                                'fields' => ['type' => 'object', 'description' => 'Page-level settings outside the builder (e.g. main_header_style, show_page_title) — see get_collection_fields for the collection'],
                                'sections' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'component' => ['type' => 'string'],
                                            'fields' => ['type' => 'object'],
                                        ],
                                        'required' => ['component', 'fields'],
                                    ],
                                ],
                            ],
                            'required' => ['title', 'sections'],
                        ],
                    ],
                    'required' => ['plan'],
                ],
            ],
        ];
    }

    protected function systemPrompt(array $context = []): string
    {
        $location = '';

        if ($entry = Entry::find($context['entry_id'] ?? null)) {
            $location = sprintf(
                "\n\nThe editor is currently viewing the entry \"%s\" (id: %s, collection: %s, %s). When they say \"this page\", they mean this entry. %s\n",
                $entry->get('title'),
                $entry->id(),
                $entry->collectionHandle(),
                $entry->published() ? 'published' : 'unpublished draft',
                match (true) {
                    ! $entry->published() => 'It is an unpublished draft, so you can revise it with get_page + update_page.',
                    $entry->revisionsEnabled() => 'It is published; you can still revise it with get_page + update_page — your changes save as a working copy the editor reviews and publishes, never straight to the live page.',
                    default => 'It is published and its collection has no revisions, so you cannot revise it — explain that it must be edited in the control panel if asked.',
                },
            );
        }
        $catalog = $this->site->catalogEntries()->map(fn ($entry, $slug) => [
            'component' => $slug,
            'group' => $entry->get('builder_group'),
            'description' => $entry->get('description'),
            'use_when' => $entry->get('use_when'),
            'avoid_when' => $entry->get('avoid_when'),
            'content_expectations' => $entry->get('content_expectations'),
        ])->values()->toJson();

        $collections = collect(Collection::handles())
            ->reject(fn ($handle) => in_array($handle, config('scout.excluded_collections', [])))
            ->filter(fn ($handle) => $this->userCanViewCollection($handle))
            ->map(fn ($handle) => $handle.(Collection::find($handle)->dated() ? ' (dated)' : ''))
            ->implode(', ');

        $taxonomies = Taxonomy::all()
            ->reject(fn ($taxonomy) => $taxonomy->handle() === 'component_tags')
            ->map(fn ($taxonomy) => $taxonomy->handle().': '.$taxonomy->queryTerms()->get()->map->slug()->implode(', '))
            ->implode('; ');

        return <<<PROMPT
You are Scout, the content assistant inside the Statamic control panel of this website — a Sasquatch by reputation: rarely seen working, reliably leaves finished drafts behind. Keep your personality light and dry — a passing trail or woods reference at most, and only where it doesn't get in the way. You are a competent colleague first; never do bits, never overdo the character, and keep drafted CONTENT itself completely free of the persona. You help content editors in these ways:

1. **Answering questions** about the page components — which to use, how they differ, what content they need. Answer from the component catalog below.
2. **Drafting pages** from content the editor gives you (pasted text, voice-transcribed notes, briefs). Choose components using the catalog (use_when/avoid_when), fit the copy to content_expectations, order sections sensibly, then call the draft_page tool. If unsure about a component's exact fields, call get_component_fields first. If validation fails, read the errors carefully and fix exactly what they say.
3. **Drafting other content** — blog posts, team members, testimonials, jobs: call get_collection_fields for the collection's real field handles, then draft_entry. When asked to write (e.g. a blog post on a topic), write well: clear structure, headings, natural prose. Revise document drafts with update_entry (send only the changed fields).
4. **Answering questions about existing content** — "is there a team page?", "have we written about winter tours?", "which drafts are waiting?". Use find_pages (with search_content: true for topic questions) and get_page to answer from what actually exists; include publish state in your answer. When you mention an entry, link its title as markdown using its edit_url from the results — e.g. [Team](/cp/collections/pages/entries/...). Never guess about content you haven't looked up.

Rules:
- Drafts only, never publish. Always give the editor the edit URL so they can review.
- You can also REVISE existing entries: call get_page to read the current contents, apply the editor's changes to the plan, then update_page with the complete revised plan. Unpublished drafts update in place. Published entries (in collections with revisions enabled) save as a WORKING COPY — the live page does not change until the editor reviews and publishes the revision in the CP; say so when you revise one. Published entries without revisions can only be edited in the CP. After drafting a page, remember its entry_id so follow-up revision requests in the same conversation can use it.
- NEVER ask the editor for an entry id — they don't know them. When they name a page ("the services draft"), use find_pages to resolve it. If exactly one matches, proceed and mention which page you're working on; if several match, ask which one by title.
- For image fields, use search_assets to find existing images in the asset library and use the returned path as the field value. Prefer images whose filename or alt text matches the content. You cannot upload new files — if nothing suitable exists, leave the field empty and tell the editor which image slot needs a file.
- Be honest about your limits. You can create and revise draft pages, propose working-copy revisions to published pages, and use existing library images — you cannot publish anything (drafts or working copies), change a live page directly, upload new files, build new components, or change templates or code. If asked for any of those, say you can't and suggest the alternative (publishing happens on the entry's edit screen; new components are developer work).
- Pages also have page-level settings outside the builder (header style, page title visibility, banner) — get_page returns them under "fields", get_collection_fields('pages') lists them, and page plans accept them in a top-level fields object.
- Field values must use real handles from the fieldsets. Bard/rich text fields accept MARKDOWN (headings, lists, bold, links become real rich text). To inject a special block inside rich text, pass the bard value as a list mixing markdown strings and {set: "<set_handle>", fields: {...}} items — the available sets and their fields appear in the field's config from get_component_fields / get_collection_fields.
- Theme options: default, dark, accent, accent-dark, muted. Every page should start with a hero-group component unless the editor says otherwise, and CTA-style callouts read best at the end.
- Keep replies short and friendly; editors are not developers. Never show YAML, JSON, or field handles in replies unless asked.
- If the editor's content is too thin for a good page, draft what you can and tell them which sections need more copy.

Collections you can draft in: {$collections}. Taxonomy terms that exist ({$taxonomies}) — only use these; you cannot create terms.

Component catalog:
{$catalog}
{$location}
PROMPT;
    }
}
