<?php

namespace Cascadia\Scout;

use Cascadia\Scout\Concerns\FlattensReplicatorSets;
use Illuminate\Support\Collection;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry;

/**
 * Scout's config-driven view of the host site: which replicator field is
 * the page builder (and what sets it offers), and which collection holds
 * the component catalog. Both are optional — a vanilla site with neither
 * still gets blueprint-generic drafting.
 */
class SiteContext
{
    use FlattensReplicatorSets;

    public function builderField(): ?string
    {
        return config('scout-assistant.page_builder_field');
    }

    /**
     * Builder sets available across all collections' entry blueprints
     * (imports resolved, set groups flattened), keyed by set handle.
     * Empty when no page builder field is configured.
     *
     * @return Collection<string, array>
     */
    public function builderSets(): Collection
    {
        if (! ($builderField = $this->builderField())) {
            return collect();
        }

        return CollectionFacade::all()->flatMap(function ($collection) use ($builderField) {
            $field = $collection->entryBlueprint()->fields()->all()->get($builderField);

            return $field ? $this->flattenSets($field->config()['sets'] ?? []) : collect();
        });
    }

    /**
     * Component catalog entries, keyed by slug. Empty when the site
     * maintains no catalog.
     *
     * @return Collection<string, \Statamic\Contracts\Entries\Entry>
     */
    public function catalogEntries(): Collection
    {
        if (! ($catalogCollection = config('scout-assistant.catalog_collection'))) {
            return collect();
        }

        return Entry::query()
            ->where('collection', $catalogCollection)
            ->get()
            ->keyBy(fn ($entry) => $entry->slug());
    }
}
