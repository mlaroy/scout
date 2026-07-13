<?php

namespace Cascadia\Scout\Tests\Feature;

use Cascadia\Scout\PageAssembler;
use Cascadia\Scout\Tests\TestCase;
use RuntimeException;
use Statamic\Facades\Entry;

class PageAssemblerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.page_builder_field' => 'page_builder']);
    }

    public function test_it_assembles_an_unpublished_draft(): void
    {
        $entry = app(PageAssembler::class)->assemble([
            'title' => 'Fresh Page',
            'collection' => 'pages',
            'sections' => [['component' => 'hero', 'fields' => ['heading' => 'Hi']]],
        ]);

        $this->assertFalse($entry->published());
        $this->assertSame('fresh-page', $entry->slug());
        $this->assertSame('hero', $entry->get('page_builder')[0]['type']);
    }

    public function test_it_refuses_plans_with_errors(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/unknown component/');

        app(PageAssembler::class)->assemble([
            'title' => 'Broken',
            'collection' => 'pages',
            'sections' => [['component' => 'jumbotron', 'fields' => []]],
        ]);
    }

    public function test_it_refuses_duplicate_slugs(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists/');

        app(PageAssembler::class)->assemble([
            'title' => 'About',
            'slug' => 'about',
            'collection' => 'pages',
            'sections' => [['component' => 'hero', 'fields' => []]],
        ]);
    }

    public function test_excluded_collections_are_off_limits(): void
    {
        config(['scout.excluded_collections' => ['articles']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/off-limits/');

        app(PageAssembler::class)->assembleEntry([
            'collection' => 'articles',
            'title' => 'Sneaky',
            'fields' => [],
        ]);
    }

    public function test_it_refuses_to_update_a_published_entry(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Only unpublished drafts/');

        app(PageAssembler::class)->update('pages-about', [
            'title' => 'Hijacked',
            'sections' => [],
        ]);
    }

    public function test_it_assembles_a_dated_document_entry(): void
    {
        $entry = app(PageAssembler::class)->assembleEntry([
            'collection' => 'articles',
            'title' => 'A Post',
            'fields' => ['count' => 2],
        ]);

        $this->assertFalse($entry->published());
        $this->assertSame(2, $entry->get('count'));
        $this->assertNotNull($entry->date());
    }

    public function test_update_entry_merges_only_the_sent_fields(): void
    {
        $entry = app(PageAssembler::class)->assembleEntry([
            'collection' => 'articles',
            'title' => 'Original Title',
            'fields' => ['count' => 1],
        ]);

        $updated = app(PageAssembler::class)->updateEntry($entry->id(), [
            'fields' => ['count' => 5],
        ]);

        $this->assertSame('Original Title', $updated->get('title'));
        $this->assertSame(5, $updated->get('count'));
    }

    public function test_update_replaces_a_draft_page_plan(): void
    {
        $entry = app(PageAssembler::class)->assemble([
            'title' => 'Draft Page',
            'collection' => 'pages',
            'sections' => [['component' => 'hero', 'fields' => ['heading' => 'Old']]],
        ]);

        $updated = app(PageAssembler::class)->update($entry->id(), [
            'title' => 'Draft Page (Revised)',
            'sections' => [['component' => 'cta', 'fields' => ['cta_heading' => 'New']]],
        ]);

        $this->assertSame('Draft Page (Revised)', $updated->get('title'));
        $this->assertCount(1, $updated->get('page_builder'));
        $this->assertSame('cta', $updated->get('page_builder')[0]['type']);
        $this->assertFalse($updated->published());
        $this->assertNotNull(Entry::find($entry->id()));
    }
}
