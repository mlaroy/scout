<?php

namespace Cascadia\Scout;

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
    public function builderField(): ?string
    {
        return config('scout.page_builder_field');
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
        if (! ($catalogCollection = config('scout.catalog_collection'))) {
            return collect();
        }

        return Entry::query()
            ->where('collection', $catalogCollection)
            ->get()
            ->keyBy(fn ($entry) => $entry->slug());
    }

    /**
     * Replicator sets may be grouped one level deep; flatten to set handle => config.
     */
    protected function flattenSets(array $sets): Collection
    {
        return collect($sets)->flatMap(
            fn ($config, $handle) => isset($config['sets'])
                ? $config['sets']
                : [$handle => $config]
        );
    }
}
