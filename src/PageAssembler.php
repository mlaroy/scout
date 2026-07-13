<?php

namespace Cascadia\Scout;

use Illuminate\Support\Arr;
use RuntimeException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

/**
 * Turns a validated page plan into a draft entry. The validator is the
 * gate: assembly refuses to run while the plan has errors, so nothing
 * malformed ever reaches content storage.
 */
class PageAssembler
{
    public function __construct(protected PagePlanValidator $validator) {}

    /**
     * @return array{errors: array<int, string>, data: array}
     */
    public function validate(array $plan): array
    {
        return $this->validator->validate($plan);
    }

    public function assemble(array $plan): EntryContract
    {
        $result = $this->validate($plan);

        if ($result['errors']) {
            throw new RuntimeException("Plan has errors:\n - ".implode("\n - ", $result['errors']));
        }

        $data = $result['data'];
        $builderField = config('scout.page_builder_field');

        $this->guardCollection($data['collection']);

        if (Entry::query()->where('collection', $data['collection'])->where('slug', $data['slug'])->first()) {
            throw new RuntimeException("An entry with slug \"{$data['slug']}\" already exists in \"{$data['collection']}\"");
        }

        $entry = Entry::make()
            ->collection($data['collection'])
            ->slug($data['slug'])
            ->published(false)
            ->data(array_merge($data['fields'] ?? [], [
                'title' => $data['title'],
                $builderField => $data[$builderField],
            ]));

        $entry->save();

        return $entry;
    }

    /**
     * Create a draft in a document-style collection (blog, team, ...) from
     * a {collection, title, fields} plan validated against its blueprint.
     */
    public function assembleEntry(array $plan): EntryContract
    {
        $this->guardCollection($plan['collection'] ?? '');

        $result = $this->validator->validateEntry($plan);

        if ($result['errors']) {
            throw new RuntimeException("Plan has errors:\n - ".implode("\n - ", $result['errors']));
        }

        $data = $result['data'];

        if (Entry::query()->where('collection', $data['collection'])->where('slug', $data['slug'])->first()) {
            throw new RuntimeException("An entry with slug \"{$data['slug']}\" already exists in \"{$data['collection']}\"");
        }

        $fields = $data['fields'];

        $entry = Entry::make()
            ->collection($data['collection'])
            ->slug($data['slug'])
            ->published(false);

        if (Collection::find($data['collection'])->dated()) {
            $entry->date(Arr::pull($fields, 'date') ?? now()->format('Y-m-d'));
        }

        $entry->data(array_merge(['title' => $data['title']], $fields));

        $entry->save();

        return $entry;
    }

    /**
     * Merge revised fields onto an existing UNPUBLISHED document entry.
     * Unlike builder pages (whole-plan replacement), documents merge so a
     * single field can change without resending the body.
     */
    public function updateEntry(string $entryId, array $plan): EntryContract
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            throw new RuntimeException("No entry with id \"{$entryId}\".");
        }

        $this->guardCollection($entry->collectionHandle());

        if ($entry->published()) {
            throw new RuntimeException('Only unpublished drafts can be revised. This entry is published — edit it in the control panel instead.');
        }

        $plan['collection'] = $entry->collectionHandle();
        $plan['title'] ??= $entry->get('title');
        $plan['slug'] ??= $entry->slug();

        $result = $this->validator->validateEntry($plan);

        if ($result['errors']) {
            throw new RuntimeException("Plan has errors:\n - ".implode("\n - ", $result['errors']));
        }

        $data = $result['data'];

        $entry->merge(array_merge(['title' => $data['title']], $data['fields']));

        $entry->save();

        return $entry;
    }

    protected function guardCollection(string $handle): void
    {
        if (in_array($handle, config('scout.excluded_collections', []))) {
            throw new RuntimeException("The \"{$handle}\" collection is off-limits to the assistant.");
        }
    }

    /**
     * Replace an existing UNPUBLISHED entry's content with a revised plan.
     * Published entries are never touched — revision is a drafts-only power.
     */
    public function update(string $entryId, array $plan): EntryContract
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            throw new RuntimeException("No entry with id \"{$entryId}\".");
        }

        $this->guardCollection($entry->collectionHandle());

        if ($entry->published()) {
            throw new RuntimeException('Only unpublished drafts can be revised. This entry is published — edit it in the control panel instead.');
        }

        $plan['collection'] = $entry->collectionHandle();
        $plan['slug'] ??= $entry->slug();

        $result = $this->validate($plan);

        if ($result['errors']) {
            throw new RuntimeException("Plan has errors:\n - ".implode("\n - ", $result['errors']));
        }

        $data = $result['data'];
        $builderField = config('scout.page_builder_field');

        $entry
            ->set('title', $data['title'])
            ->set($builderField, $data[$builderField]);

        foreach ($data['fields'] ?? [] as $handle => $value) {
            $entry->set($handle, $value);
        }

        $entry->save();

        return $entry;
    }
}
