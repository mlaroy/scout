<?php

namespace Cascadia\Scout\Console\Commands;

use Cascadia\Scout\Console\Commands\Concerns\ReadsPlanFiles;
use Cascadia\Scout\PageAssembler;
use Illuminate\Console\Command;
use RuntimeException;
use Statamic\Facades\YAML;

class PagesAssemble extends Command
{
    use ReadsPlanFiles;

    protected $signature = 'pages:assemble
        {plan : Path to a YAML page plan, or - to read from STDIN}
        {--dry-run : Validate and print the normalized entry data without saving}';

    protected $description = 'Validate a page plan against the component fieldsets and create a draft entry from it';

    public function handle(PageAssembler $assembler): int
    {
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

        $result = $assembler->validate($plan);

        if ($result['errors']) {
            $this->components->error(count($result['errors']).' plan error(s):');

            foreach ($result['errors'] as $error) {
                $this->components->bulletList([$error]);
            }

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Plan is valid. Normalized entry data:');
            $this->line(YAML::dump($result['data']));

            return self::SUCCESS;
        }

        try {
            $entry = $assembler->assemble($plan);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $sections = $result['data'][config('scout-assistant.page_builder_field')] ?? [];

        $this->components->info(sprintf(
            'Draft created: %s (%d section%s)',
            $entry->slug(),
            count($sections),
            count($sections) === 1 ? '' : 's',
        ));

        $this->components->twoColumnDetail('Edit', url("/cp/collections/{$entry->collectionHandle()}/entries/{$entry->id()}"));

        return self::SUCCESS;
    }
}
