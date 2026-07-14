<?php

namespace Cascadia\Scout;

use Illuminate\Support\Arr;
use RuntimeException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Turns a validated page plan into a draft entry. The validator is the
 * gate: assembly refuses to run while the plan has errors, so nothing
 * malformed ever reaches content storage.
 *
 * The live version of a published entry is never written to. Revising a
 * published entry lands as a working copy (requires revisions) that a
 * human reviews and publishes in the CP; without revisions, published
 * entries are read-only to the assembler.
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
     * Merge revised fields onto an existing document entry. Unlike builder
     * pages (whole-plan replacement), documents merge so a single field can
     * change without resending the body. Drafts save directly; published
     * entries save as a working copy.
     */
    public function updateEntry(string $entryId, array $plan): EntryContract
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            throw new RuntimeException("No entry with id \"{$entryId}\".");
        }

        $this->guardCollection($entry->collectionHandle());

        $target = $this->reviseTarget($entry);

        $plan['collection'] = $target->collectionHandle();
        $plan['title'] ??= $target->get('title');
        $plan['slug'] ??= $target->slug();

        $result = $this->validator->validateEntry($plan);

        if ($result['errors']) {
            throw new RuntimeException("Plan has errors:\n - ".implode("\n - ", $result['errors']));
        }

        $data = $result['data'];

        $target->merge(array_merge(['title' => $data['title']], $data['fields']));

        $this->saveRevision($target);

        return $target;
    }

    protected function guardCollection(string $handle): void
    {
        if (in_array($handle, config('scout.excluded_collections', []))) {
            throw new RuntimeException("The \"{$handle}\" collection is off-limits to the assistant.");
        }
    }

    /**
     * Replace an existing entry's content with a revised plan. Drafts save
     * directly; published entries save as a working copy — the live version
     * is untouched until a human publishes the revision in the CP.
     */
    public function update(string $entryId, array $plan): EntryContract
    {
        $entry = Entry::find($entryId);

        if (! $entry) {
            throw new RuntimeException("No entry with id \"{$entryId}\".");
        }

        $this->guardCollection($entry->collectionHandle());

        $target = $this->reviseTarget($entry);

        $plan['collection'] = $target->collectionHandle();
        $plan['slug'] ??= $target->slug();

        $result = $this->validate($plan);

        if ($result['errors']) {
            throw new RuntimeException("Plan has errors:\n - ".implode("\n - ", $result['errors']));
        }

        $data = $result['data'];
        $builderField = config('scout.page_builder_field');

        $target
            ->set('title', $data['title'])
            ->set($builderField, $data[$builderField]);

        foreach ($data['fields'] ?? [] as $handle => $value) {
            $target->set($handle, $value);
        }

        $this->saveRevision($target);

        return $target;
    }

    /**
     * The entry object a revision may be applied to: the entry itself when
     * it's an unpublished draft, or its working copy (existing one if a CP
     * user has pending edits, fresh otherwise) when it's published in a
     * revisions-enabled collection. Published entries without revisions are
     * off-limits — the live version is never a write target.
     */
    protected function reviseTarget(EntryContract $entry): EntryContract
    {
        if (! $entry->published()) {
            return $entry;
        }

        if (! $entry->revisionsEnabled()) {
            throw new RuntimeException('This entry is published and its collection does not use revisions, so it can only be edited in the control panel. Enable revisions on the collection to revise published entries as working copies.');
        }

        // Build on a pending working copy when one exists (preserving CP
        // edits); otherwise on a clone, so the in-memory live entry stays
        // pristine — only the working copy is ever saved.
        return $entry->hasWorkingCopy() ? $entry->fromWorkingCopy() : clone $entry;
    }

    /**
     * Persist a revised entry: drafts save in place, published entries save
     * as a working copy that a human reviews and publishes.
     */
    protected function saveRevision(EntryContract $entry): void
    {
        if (! $entry->published()) {
            $entry->save();

            return;
        }

        $workingCopy = $entry->makeWorkingCopy();

        if ($user = User::current()) {
            $workingCopy->user($user);
        }

        $workingCopy->save();
    }
}
