<?php

namespace Cascadia\Scout\Tests\Feature;

use Cascadia\Scout\PagePlanValidator;
use Cascadia\Scout\Tests\TestCase;

class PagePlanValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout-assistant.page_builder_field' => 'page_builder']);
    }

    public function test_it_validates_a_section_plan_and_normalizes_values(): void
    {
        $result = app(PagePlanValidator::class)->validate([
            'title' => 'Our Services',
            'collection' => 'pages',
            'fields' => ['header_style' => 'hero'],
            'sections' => [
                ['component' => 'hero', 'fields' => ['heading' => 'Welcome', 'theme' => 'dark']],
                ['component' => 'cta', 'fields' => ['cta_heading' => 'Go', 'show_button' => 1]],
            ],
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('our-services', $result['data']['slug']);
        $this->assertSame('hero', $result['data']['fields']['header_style']);

        [$hero, $cta] = $result['data']['page_builder'];
        $this->assertSame('hero', $hero['type']);
        $this->assertSame('Welcome', $hero['heading']);
        $this->assertTrue($hero['enabled']);
        $this->assertNotEmpty($hero['id']);
        $this->assertSame('cta', $cta['type']);
        $this->assertTrue($cta['show_button']);
    }

    public function test_unknown_components_list_whats_available(): void
    {
        $result = app(PagePlanValidator::class)->validate([
            'title' => 'Nope',
            'collection' => 'pages',
            'sections' => [['component' => 'jumbotron', 'fields' => []]],
        ]);

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('unknown component', $result['errors'][0]);
        $this->assertStringContainsString('cta, hero', $result['errors'][0]);
    }

    public function test_unknown_fields_get_a_suggestion(): void
    {
        $result = app(PagePlanValidator::class)->validate([
            'title' => 'Typo',
            'collection' => 'pages',
            'sections' => [['component' => 'cta', 'fields' => ['cta_headin' => 'Hi']]],
        ]);

        $this->assertStringContainsString('did you mean "cta_heading"', $result['errors'][0]);
    }

    public function test_invalid_select_options_list_the_valid_ones(): void
    {
        $result = app(PagePlanValidator::class)->validate([
            'title' => 'Neon',
            'collection' => 'pages',
            'sections' => [['component' => 'hero', 'fields' => ['theme' => 'neon']]],
        ]);

        $this->assertStringContainsString('"neon" is not an option', $result['errors'][0]);
        $this->assertStringContainsString('default, dark', $result['errors'][0]);
    }

    public function test_section_plans_are_unavailable_without_a_builder_field(): void
    {
        config(['scout-assistant.page_builder_field' => null]);

        $result = app(PagePlanValidator::class)->validate([
            'title' => 'No Builder',
            'sections' => [['component' => 'hero', 'fields' => []]],
        ]);

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('no page builder field is configured', $result['errors'][0]);
    }

    public function test_collections_without_the_builder_field_reject_section_plans(): void
    {
        $result = app(PagePlanValidator::class)->validate([
            'title' => 'Article As Page',
            'collection' => 'articles',
            'sections' => [['component' => 'hero', 'fields' => []]],
        ]);

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('has no "page_builder" builder field', $result['errors'][0]);
    }

    public function test_missing_title_and_empty_sections_are_reported(): void
    {
        $result = app(PagePlanValidator::class)->validate([
            'collection' => 'pages',
            'sections' => [],
        ]);

        $this->assertContains('plan: missing required "title"', $result['errors']);
        $this->assertContains('plan: "sections" must be a non-empty list of components', $result['errors']);
    }

    public function test_excluded_fields_are_refused_everywhere(): void
    {
        config(['scout-assistant.excluded_fields' => ['secret_flag']]);

        $result = app(PagePlanValidator::class)->validateEntry([
            'collection' => 'articles',
            'title' => 'Sneaky',
            'fields' => ['secret_flag' => true, 'count' => '3'],
        ]);

        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('"secret_flag" field may not be set', $result['errors'][0]);
        $this->assertArrayNotHasKey('secret_flag', $result['data']['fields']);
        $this->assertSame(3, $result['data']['fields']['count']);
    }

    public function test_bard_accepts_markdown_and_inline_sets(): void
    {
        $result = app(PagePlanValidator::class)->validateEntry([
            'collection' => 'articles',
            'title' => 'Rich Post',
            'fields' => ['content' => [
                "## Heading\n\nSome **bold** prose.",
                ['set' => 'quote', 'fields' => ['quote' => 'Quoted.', 'attribution' => 'Someone']],
            ]],
        ]);

        $this->assertSame([], $result['errors']);

        $types = array_column($result['data']['fields']['content'], 'type');
        $this->assertSame(['heading', 'paragraph', 'set'], $types);

        $result = app(PagePlanValidator::class)->validateEntry([
            'collection' => 'articles',
            'title' => 'Bad Post',
            'fields' => ['content' => [['set' => 'nonexistent', 'fields' => []]]],
        ]);

        $this->assertStringContainsString('no set "nonexistent"', $result['errors'][0]);
        $this->assertStringContainsString('quote', $result['errors'][0]);
    }

    public function test_document_plans_reject_unknown_collections(): void
    {
        $result = app(PagePlanValidator::class)->validateEntry([
            'collection' => 'ghosts',
            'title' => 'Boo',
            'fields' => [],
        ]);

        $this->assertStringContainsString('collection "ghosts" does not exist', $result['errors'][0]);
    }
}
