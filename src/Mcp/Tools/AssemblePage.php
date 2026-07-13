<?php

namespace Cascadia\Scout\Mcp\Tools;

use Cascadia\Scout\PageAssembler;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use RuntimeException;
use Statamic\Facades\YAML;

class AssemblePage extends Tool
{
    protected string $description = 'Create an UNPUBLISHED draft entry from a page plan. The plan is validated first; nothing is saved if it has errors. Never publishes — a human reviews the draft in the control panel. Use validate_page_plan to iterate before calling this.';

    public function __construct(protected PageAssembler $assembler) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'plan' => $schema->string()
                ->description('The page plan as YAML or JSON: title, optional collection/slug, and sections (a list of {component, fields}).')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $plan = YAML::parse($request->get('plan'));

        if (! is_array($plan)) {
            return Response::error('Could not parse the plan as YAML/JSON.');
        }

        $result = $this->assembler->validate($plan);

        if ($result['errors']) {
            return Response::json([
                'created' => false,
                'errors' => $result['errors'],
            ]);
        }

        try {
            $entry = $this->assembler->assemble($plan);
        } catch (RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::json([
            'created' => true,
            'id' => $entry->id(),
            'slug' => $entry->slug(),
            'collection' => $entry->collectionHandle(),
            'published' => false,
            'edit_url' => url("/cp/collections/{$entry->collectionHandle()}/entries/{$entry->id()}"),
            'sections' => collect($entry->get(config('scout.page_builder_field')))->pluck('type')->all(),
        ]);
    }
}
