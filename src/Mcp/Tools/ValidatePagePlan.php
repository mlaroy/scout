<?php

namespace Cascadia\Scout\Mcp\Tools;

use Cascadia\Scout\PagePlanValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\YAML;

#[IsReadOnly]
class ValidatePagePlan extends Tool
{
    protected string $description = 'Validate a page plan against the real component fieldsets without saving anything. Returns specific, correctable errors (unknown fields with suggestions, invalid options, unresolvable entries/assets) or the normalized entry data. Always run this before assemble_page.';

    public function __construct(protected PagePlanValidator $validator) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'plan' => $schema->string()
                ->description('The page plan as YAML or JSON: title, optional collection/slug, and sections (a list of {component, fields}). See docs/examples/page-plan.example.yaml.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $plan = YAML::parse($request->get('plan'));

        if (! is_array($plan)) {
            return Response::error('Could not parse the plan as YAML/JSON.');
        }

        $result = $this->validator->validate($plan);

        return Response::json([
            'valid' => $result['errors'] === [],
            'errors' => $result['errors'],
            'normalized' => $result['errors'] === [] ? $result['data'] : null,
        ]);
    }
}
