<?php

namespace Cascadia\Scout\Tests\Feature;

use Cascadia\Scout\PageAssembler;
use Cascadia\Scout\Tests\TestCase;
use RuntimeException;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

class PageAssemblerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout-assistant.page_builder_field' => 'page_builder']);
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
        config(['scout-assistant.excluded_collections' => ['articles']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/off-limits/');

        app(PageAssembler::class)->assembleEntry([
            'collection' => 'articles',
            'title' => 'Sneaky',
            'fields' => [],
        ]);
    }

    public function test_it_refuses_to_update_a_published_entry_without_revisions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not use revisions/');

        app(PageAssembler::class)->update('pages-about', [
            'title' => 'Hijacked',
            'sections' => [],
        ]);
    }

    public function test_update_saves_a_published_entry_as_a_working_copy(): void
    {
        $this->enableRevisions();

        $revised = app(PageAssembler::class)->update('pages-about', [
            'title' => 'About (Revised)',
            'sections' => [['component' => 'hero', 'fields' => ['heading' => 'New']]],
        ]);

        $live = Entry::find('pages-about');

        $this->assertSame('About', $live->get('title'));
        $this->assertSame([], $live->get('page_builder'));
        $this->assertTrue($live->hasWorkingCopy());
        $this->assertSame('About (Revised)', $live->fromWorkingCopy()->get('title'));
        $this->assertSame('hero', $live->fromWorkingCopy()->get('page_builder')[0]['type']);
        $this->assertSame('About (Revised)', $revised->get('title'));
    }

    public function test_update_entry_builds_on_an_existing_working_copy(): void
    {
        $this->enableRevisions();

        Entry::make()
            ->collection('articles')
            ->id('articles-post')
            ->slug('post')
            ->published(true)
            ->date(now())
            ->data(['title' => 'Post', 'count' => 1])
            ->save();

        $pending = clone Entry::find('articles-post');
        $pending->set('title', 'Post (CP edit)');
        $pending->makeWorkingCopy()->save();

        $revised = app(PageAssembler::class)->updateEntry('articles-post', [
            'fields' => ['count' => 5],
        ]);

        $this->assertSame('Post (CP edit)', $revised->get('title'));
        $this->assertSame(5, $revised->get('count'));
        $this->assertSame('Post', Entry::find('articles-post')->get('title'));
        $this->assertSame(1, Entry::find('articles-post')->get('count'));
    }

    protected function enableRevisions(): void
    {
        config([
            'statamic.editions.pro' => true,
            'statamic.revisions.enabled' => true,
        ]);

        Collection::find('pages')->revisionsEnabled(true)->save();
        Collection::find('articles')->revisionsEnabled(true)->save();
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
