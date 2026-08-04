<?php

namespace Cascadia\Scout\Concerns;

use Illuminate\Support\Collection;

trait FlattensReplicatorSets
{
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
