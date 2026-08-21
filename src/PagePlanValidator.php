<?php

namespace Cascadia\Scout;

use Cascadia\Scout\Concerns\FlattensReplicatorSets;
use Facades\Statamic\Fieldtypes\RowId;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Statamic\Contracts\Entries\Collection as CollectionContract;
use Statamic\Facades\Asset;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;
use Statamic\Facades\Markdown;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\Fields\Field;
use Statamic\Fields\Fields;
use Tiptap\Editor;

/**
 * Validates a structured "page plan" against the real fieldsets and
 * normalizes it into entry data. A plan looks like:
 *
 *   title: 'Our Services'
 *   collection: pages
 *   sections:
 *     - component: content_basic_hero
 *       fields:
 *         heading: 'What we do'
 *         theme: dark
 *
 * Every field is checked against the component's flattened fieldset
 * (imports resolved), values are normalized per fieldtype, and errors
 * are collected with enough context for an AI (or human) to self-correct.
 *
 * Section-style plans require a page builder field, named by
 * config('scout-assistant.page_builder_field'); its sets are discovered from the
 * collection's entry blueprint. Document-style plans (validateEntry)
 * work on any site.
 */
class PagePlanValidator
{
    use FlattensReplicatorSets;

    /** @var array<int, string> */
    protected array $errors = [];

    /**
     * @return array{errors: array<int, string>, data: array}
     */
    public function validate(array $plan): array
    {
        $this->errors = [];

        $builderField = config('scout-assistant.page_builder_field');

        if (! $builderField) {
            $this->error('plan: section-style plans are unavailable on this site — no page builder field is configured (scout.page_builder_field). Use a document-style plan instead.');

            return ['errors' => $this->errors, 'data' => []];
        }

        $title = $plan['title'] ?? null;

        if (! $title) {
            $this->error('plan: missing required "title"');
        }

        $collection = $plan['collection'] ?? 'pages';

        if (! ($found = CollectionFacade::find($collection))) {
            $this->error("plan: collection \"{$collection}\" does not exist");

            return ['errors' => $this->errors, 'data' => []];
        }

        $sections = $plan['sections'] ?? [];

        if (! is_array($sections) || $sections === []) {
            $this->error('plan: "sections" must be a non-empty list of components');
        }

        $builderSets = $this->builderSets($found, $builderField);

        if ($builderSets->isEmpty()) {
            $this->error("plan: the \"{$collection}\" blueprint has no \"{$builderField}\" builder field — use a document-style plan instead");

            return ['errors' => $this->errors, 'data' => []];
        }

        $normalized = collect($sections)->map(function ($section, $index) use ($builderSets) {
            $component = $section['component'] ?? null;
            $context = "sections[{$index}]".($component ? " ({$component})" : '');

            if (! $component) {
                $this->error("{$context}: missing \"component\"");

                return null;
            }

            if (! $builderSets->has($component)) {
                $this->error("{$context}: unknown component. Available: ".$builderSets->keys()->sort()->implode(', '));

                return null;
            }

            $fields = (new Fields($builderSets->get($component)['fields'] ?? []))->all();

            $values = $this->validateFields($fields, $section['fields'] ?? [], $context);

            return array_merge($values, [
                'id' => RowId::generate(),
                'type' => $component,
                'enabled' => $section['enabled'] ?? true,
            ]);
        })->filter()->values()->all();

        // Page-level fields outside the builder (header style, banner, ...)
        // validate against the collection's blueprint.
        $pageFields = [];

        if (! empty($plan['fields'])) {
            $blueprintFields = $found->entryBlueprint()->fields()->all()->except(['title', 'slug', $builderField]);
            $pageFields = $this->validateFields($blueprintFields, $plan['fields'], 'fields');
        }

        return [
            'errors' => $this->errors,
            'data' => [
                'title' => $title,
                'collection' => $collection,
                'slug' => $plan['slug'] ?? str($title ?? '')->slug()->toString(),
                $builderField => $normalized,
                'fields' => $pageFields,
            ],
        ];
    }

    /**
     * Validate a document-style plan ({collection, title, slug?, fields})
     * against the collection's entry blueprint — for collections that are
     * plain documents rather than builder pages (blog, team, ...).
     *
     * @return array{errors: array<int, string>, data: array}
     */
    public function validateEntry(array $plan): array
    {
        $this->errors = [];

        $title = $plan['title'] ?? null;

        if (! $title) {
            $this->error('plan: missing required "title"');
        }

        $collection = CollectionFacade::find($plan['collection'] ?? '');

        if (! $collection) {
            $this->error('plan: collection "'.($plan['collection'] ?? '').'" does not exist');

            return ['errors' => $this->errors, 'data' => []];
        }

        $blueprint = $collection->entryBlueprint();

        $fields = $blueprint->fields()->all()->except(['title', 'slug']);

        $values = $this->validateFields($fields, $plan['fields'] ?? [], 'fields');

        return [
            'errors' => $this->errors,
            'data' => [
                'title' => $title,
                'collection' => $collection->handle(),
                'slug' => $plan['slug'] ?? str($title ?? '')->slug()->toString(),
                'fields' => $values,
            ],
        ];
    }

    /**
     * The sets of the collection's page builder field (imports resolved,
     * set groups flattened), keyed by set handle.
     */
    protected function builderSets(CollectionContract $collection, string $builderField): Collection
    {
        $field = $collection->entryBlueprint()->fields()->all()->get($builderField);

        if (! $field) {
            return collect();
        }

        return $this->flattenSets($field->config()['sets'] ?? []);
    }

    /**
     * @param  Collection<string, Field>  $fields
     */
    protected function validateFields(Collection $fields, array $values, string $context): array
    {
        $excluded = config('scout-assistant.excluded_fields', []);

        $normalized = [];

        foreach ($values as $handle => $value) {
            if (in_array($handle, $excluded)) {
                $this->error("{$context}: the \"{$handle}\" field may not be set by the assistant (scout.excluded_fields)");

                continue;
            }

            if (! $fields->has($handle)) {
                $suggestion = $this->closestHandle($handle, $fields->keys());

                $this->error(
                    "{$context}: unknown field \"{$handle}\"".
                    ($suggestion ? " — did you mean \"{$suggestion}\"?" : '').
                    ' Valid fields: '.$fields->keys()->implode(', ')
                );

                continue;
            }

            $normalized[$handle] = $this->normalizeValue($fields->get($handle), $value, "{$context}.{$handle}");
        }

        return $normalized;
    }

    protected function normalizeValue(Field $field, mixed $value, string $context): mixed
    {
        return match ($field->type()) {
            'select', 'radio' => $this->normalizeOption($field, $value, $context),
            'toggle' => (bool) $value,
            'integer' => $this->normalizeInteger($value, $context),
            'bard' => $this->normalizeBard($field, $value, $context),
            'assets' => $this->normalizeAssets($field, $value, $context),
            'entries' => $this->normalizeEntries($field, $value, $context),
            'terms' => $this->normalizeTerms($field, $value, $context),
            'date' => $this->normalizeDate($value, $context),
            'users' => $this->normalizeUsers($field, $value, $context),
            'replicator' => $this->normalizeReplicator($field, $value, $context),
            'group' => $this->normalizeGroup($field, $value, $context),
            default => $value,
        };
    }

    protected function normalizeOption(Field $field, mixed $value, string $context): mixed
    {
        $options = collect($field->config()['options'] ?? []);

        // Options are either a list of {key, value} or a key => label map.
        $keys = $options->every(fn ($option) => is_array($option))
            ? $options->pluck('key')
            : $options->keys();

        if (! $keys->contains($value)) {
            $this->error("{$context}: \"{$value}\" is not an option. Valid: ".$keys->implode(', '));
        }

        return $value;
    }

    protected function normalizeInteger(mixed $value, string $context): mixed
    {
        if (! is_numeric($value)) {
            $this->error("{$context}: expected a number, got ".json_encode($value));

            return $value;
        }

        return (int) $value;
    }

    /**
     * Bard accepts three shapes:
     *  - a markdown string (headings, lists, bold, links become real nodes)
     *  - a mixed list of markdown strings and {set: <handle>, fields: {...}}
     *    items, for injecting the field's configured Bard sets inline
     *  - a raw ProseMirror-shaped array (passed through)
     */
    protected function normalizeBard(Field $field, mixed $value, string $context): mixed
    {
        if (is_string($value)) {
            return $this->markdownToProseMirror($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $isMixedList = collect($value)->every(
            fn ($item) => is_string($item) || (is_array($item) && isset($item['set']))
        );

        if (! $isMixedList) {
            return $value; // raw ProseMirror
        }

        $sets = $this->flattenSets($field->config()['sets'] ?? []);

        return collect($value)->flatMap(function ($item, $index) use ($sets, $context) {
            if (is_string($item)) {
                return $this->markdownToProseMirror($item);
            }

            $handle = $item['set'];

            if (! $sets->has($handle)) {
                $this->error("{$context}[{$index}]: this field has no set \"{$handle}\". Available sets: ".($sets->keys()->implode(', ') ?: 'none'));

                return [];
            }

            $fields = (new Fields($sets->get($handle)['fields'] ?? []))->all();

            $values = $this->validateFields($fields, $item['fields'] ?? [], "{$context}[{$index}] ({$handle})");

            return [[
                'type' => 'set',
                'attrs' => [
                    'id' => RowId::generate(),
                    'enabled' => true,
                    'values' => array_merge(['type' => $handle], $values),
                ],
            ]];
        })->values()->all();
    }

    protected function markdownToProseMirror(string $markdown): array
    {
        $html = (string) Markdown::parse($markdown);

        return (new Editor)->setContent($html)->getDocument()['content'] ?? [];
    }

    protected function normalizeAssets(Field $field, mixed $value, string $context): array
    {
        $container = $field->config()['container'] ?? 'assets';

        return collect(Arr::wrap($value))->map(function ($path) use ($container, $context) {
            if (! Asset::find("{$container}::{$path}")) {
                $this->error("{$context}: asset \"{$path}\" not found in the \"{$container}\" container");
            }

            return $path;
        })->all();
    }

    /**
     * Accept entry IDs or slugs; store IDs.
     */
    protected function normalizeEntries(Field $field, mixed $value, string $context): mixed
    {
        $collections = $field->config()['collections'] ?? [];
        $maxItems = $field->config()['max_items'] ?? null;

        $ids = collect(Arr::wrap($value))->map(function ($reference) use ($collections, $context) {
            if (Entry::find($reference)) {
                return $reference;
            }

            $match = Entry::query()
                ->when($collections, fn ($query) => $query->whereIn('collection', $collections))
                ->where('slug', $reference)
                ->first();

            if (! $match) {
                $this->error("{$context}: no entry \"{$reference}\"".($collections ? ' in '.implode('/', $collections) : ''));

                return null;
            }

            return $match->id();
        })->filter()->values();

        if ($maxItems && $ids->count() > $maxItems) {
            $this->error("{$context}: {$ids->count()} entries given, max_items is {$maxItems}");
        }

        // Single-entry fields store a scalar, matching CP-saved content.
        return $maxItems === 1 ? $ids->first() : $ids->all();
    }

    /**
     * Accept taxonomy term slugs; verify they exist in the field's taxonomies.
     */
    protected function normalizeTerms(Field $field, mixed $value, string $context): array
    {
        $taxonomies = $field->config()['taxonomies'] ?? [];

        return collect(Arr::wrap($value))->map(function ($slug) use ($taxonomies, $context) {
            $exists = Term::query()
                ->when($taxonomies, fn ($query) => $query->whereIn('taxonomy', $taxonomies))
                ->where('slug', $slug)
                ->first();

            if (! $exists) {
                $available = Term::query()
                    ->when($taxonomies, fn ($query) => $query->whereIn('taxonomy', $taxonomies))
                    ->get()
                    ->map->slug()
                    ->take(20)
                    ->implode(', ');

                $this->error("{$context}: no term \"{$slug}\"".($taxonomies ? ' in '.implode('/', $taxonomies) : '').". Available: {$available}");
            }

            return $slug;
        })->all();
    }

    protected function normalizeDate(mixed $value, string $context): mixed
    {
        if (! is_string($value) || strtotime($value) === false) {
            $this->error("{$context}: expected a date (e.g. 2026-07-09), got ".json_encode($value));

            return $value;
        }

        return date('Y-m-d', strtotime($value));
    }

    /**
     * Accept a user id or email; store the id.
     */
    protected function normalizeUsers(Field $field, mixed $value, string $context): mixed
    {
        $maxItems = $field->config()['max_items'] ?? null;

        $ids = collect(Arr::wrap($value))->map(function ($reference) use ($context) {
            $user = User::find($reference) ?? User::findByEmail($reference);

            if (! $user) {
                $this->error("{$context}: no user \"{$reference}\" (use an id or email)");

                return null;
            }

            return $user->id();
        })->filter()->values();

        return $maxItems === 1 ? $ids->first() : $ids->all();
    }

    protected function normalizeReplicator(Field $field, mixed $value, string $context): array
    {
        $sets = $this->flattenSets($field->config()['sets'] ?? []);

        if (! is_array($value)) {
            $this->error("{$context}: expected a list of sets");

            return [];
        }

        return collect($value)->map(function ($row, $index) use ($sets, $context) {
            $type = $row['type'] ?? ($sets->count() === 1 ? $sets->keys()->first() : null);
            $rowContext = "{$context}[{$index}]";

            if (! $type || ! $sets->has($type)) {
                $this->error("{$rowContext}: unknown set type \"{$type}\". Valid: ".$sets->keys()->implode(', '));

                return null;
            }

            $fields = (new Fields($sets->get($type)['fields'] ?? []))->all();

            $values = $this->validateFields($fields, Arr::except($row, ['type', 'enabled', 'id']), $rowContext);

            return array_merge($values, [
                'id' => RowId::generate(),
                'type' => $type,
                'enabled' => $row['enabled'] ?? true,
            ]);
        })->filter()->values()->all();
    }

    protected function normalizeGroup(Field $field, mixed $value, string $context): array
    {
        if (! is_array($value)) {
            $this->error("{$context}: expected a map of fields");

            return [];
        }

        $fields = (new Fields($field->config()['fields'] ?? []))->all();

        return $this->validateFields($fields, $value, $context);
    }

    protected function closestHandle(string $handle, Collection $handles): ?string
    {
        return $handles
            ->map(fn ($candidate) => ['handle' => $candidate, 'distance' => levenshtein($handle, $candidate)])
            ->sortBy('distance')
            ->first(fn ($candidate) => $candidate['distance'] <= 3)['handle'] ?? null;
    }

    protected function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
