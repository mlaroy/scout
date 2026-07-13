<?php

namespace Cascadia\Scout\Tests;

use Cascadia\Scout\ServiceProvider;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function setUp(): void
    {
        parent::setUp();

        // Blueprints are plain files (not stache items), so they load from
        // fixtures; collections and entries are stache items and must be
        // created here — the harness diverts their writes to dev-null.
        Blueprint::setDirectory(__DIR__.'/__fixtures__/resources/blueprints');
        Fieldset::setDirectory(__DIR__.'/__fixtures__/resources/fieldsets');

        Collection::make('pages')->title('Pages')->save();
        Collection::make('articles')->title('Articles')->dated(true)->save();

        Entry::make()
            ->collection('pages')
            ->id('pages-about')
            ->slug('about')
            ->published(true)
            ->data(['title' => 'About', 'page_builder' => []])
            ->save();
    }
}
