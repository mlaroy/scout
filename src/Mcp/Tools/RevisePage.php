<?php

namespace Cascadia\Scout\Mcp\Tools;

use Cascadia\Scout\PageAssembler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use RuntimeException;
use Statamic\Facades\Entry;
use Statamic\Facades\YAML;

class RevisePage extends Tool
{
    protected string $description = 'Apply a revised page plan to an existing entry. Send the COMPLETE plan — it replaces the entry\'s content. Unpublished drafts update in place; published entries in revisions-enabled collections save as a working copy for CP review (the live page is untouched, and never published by this tool). Published entries without revisions are refused.';

    public function __construct(protected PageAssembler $assembler) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'entry_id' => $schema->string()
                ->description('The id of the entry to revise.')
                ->required(),
            'plan' => $schema->string()
                ->description('The complete revised page plan as YAML or JSON: title and sections (a list of {component, fields}).')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $entry = Entry::find($request->get('entry_id'));

        if (! $entry) {
            return Response::error("No entry with id \"{$request->get('entry_id')}\".");
        }

        $plan = YAML::parse($request->get('plan'));

        if (! is_array($plan)) {
            return Response::error('Could not parse the plan as YAML/JSON.');
        }

        $wasPublished = $entry->published();

        try {
            $revised = $this->assembler->update($entry->id(), $plan);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json([
            'revised' => true,
            'working_copy' => $wasPublished,
            'note' => $wasPublished
                ? 'Saved as a working copy — the live page is unchanged until a human publishes the revision in the CP.'
                : 'Draft updated in place (still unpublished).',
            'id' => $revised->id(),
            'slug' => $revised->slug(),
            'collection' => $revised->collectionHandle(),
            'edit_url' => url("/cp/collections/{$revised->collectionHandle()}/entries/{$revised->id()}"),
            'sections' => collect($revised->get(config('scout-assistant.page_builder_field')))->pluck('type')->all(),
        ]);
    }
}
