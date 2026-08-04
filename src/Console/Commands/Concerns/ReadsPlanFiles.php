<?php

namespace Cascadia\Scout\Console\Commands\Concerns;

use Illuminate\Support\Facades\File;

trait ReadsPlanFiles
{
    protected function readFile(string $path): ?string
    {
        if (! File::exists($path)) {
            $this->components->error("Plan file not found: {$path}");

            return null;
        }

        return File::get($path);
    }
}
