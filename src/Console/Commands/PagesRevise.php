<?php

namespace Cascadia\Scout\Console\Commands;

use Cascadia\Scout\Console\Commands\Concerns\ReadsPlanFiles;
use Cascadia\Scout\PageAssembler;
use Illuminate\Console\Command;
use RuntimeException;
use Statamic\Facades\Entry;
use Statamic\Facades\YAML;

class PagesRevise extends Command
{
    use ReadsPlanFiles;

    protected $signature = 'pages:revise
        {entry : The id of the entry to revise}
        {plan : Path to a YAML page plan, or - to read from STDIN}
        {--dry-run : Validate and print the normalized entry data without saving}';

    protected $description = 'Apply a revised page plan to an existing entry — drafts update in place, published entries save as a working copy for CP review';

    public function handle(PageAssembler $assembler): int
    {
        $entry = Entry::find($this->argument('entry'));

        if (! $entry) {
            $this->components->error("No entry with id \"{$this->argument('entry')}\".");

            return self::FAILURE;
        }

        $path = $this->argument('plan');

        $raw = $path === '-' ? stream_get_contents(STDIN) : $this->readFile($path);

        if ($raw === null) {
            return self::FAILURE;
        }

        $plan = YAML::parse($raw);

        if (! is_array($plan)) {
            $this->components->error('Could not parse the plan as YAML.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $plan['collection'] = $entry->collectionHandle();
            $plan['slug'] ??= $entry->slug();

            $result = $assembler->validate($plan);

            if ($result['errors']) {
                $this->components->error(count($result['errors']).' plan error(s):');

                foreach ($result['errors'] as $error) {
                    $this->components->bulletList([$error]);
                }

                return self::FAILURE;
            }

            $this->components->info('Plan is valid. Normalized entry data:');
            $this->line(YAML::dump($result['data']));

            return self::SUCCESS;
        }

        $wasPublished = $entry->published();

        try {
            $revised = $assembler->update($entry->id(), $plan);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $sections = $revised->get(config('scout.page_builder_field')) ?? [];

        $this->components->info(sprintf(
            $wasPublished
                ? 'Working copy saved: %s (%d section%s). The live page is unchanged until the revision is published in the CP.'
                : 'Draft updated: %s (%d section%s)',
            $revised->slug(),
            count($sections),
            count($sections) === 1 ? '' : 's',
        ));

        $this->components->twoColumnDetail('Edit', url("/cp/collections/{$revised->collectionHandle()}/entries/{$revised->id()}"));

        return self::SUCCESS;
    }
}
