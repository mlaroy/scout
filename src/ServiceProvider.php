<?php

namespace Cascadia\Scout;

use Cascadia\Scout\Console\Commands\PagesAssemble;
use Cascadia\Scout\Console\Commands\PagesRevise;
use Cascadia\Scout\Mcp\Tools\AssemblePage;
use Cascadia\Scout\Mcp\Tools\RevisePage;
use Cascadia\Scout\Mcp\Tools\ValidatePagePlan;
use Laravel\Mcp\Server\Tool;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
    ];

    protected $commands = [
        PagesAssemble::class,
        PagesRevise::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->singleton(ProviderManager::class);

        $this->app->bind(AssistantClient::class, fn ($app) => $app->make(ProviderManager::class)->makeClient());
    }

    public function bootAddon(): void
    {
        $this->registerPermissions();
        $this->registerNav();
        $this->registerMcpTools();
    }

    /**
     * Merge the write pipeline's tools into Laravel Boost's MCP server
     * when Boost is present — AI coding agents get the same validated
     * write path the CP assistant uses.
     *
     * These tools have no `use assistant` permission check of their own;
     * they trust Boost's own MCP authentication as the boundary, since an
     * MCP client is a developer's local tooling, not a CP user session.
     */
    protected function registerMcpTools(): void
    {
        if (! class_exists(Tool::class)) {
            return;
        }

        config(['boost.mcp.tools.include' => array_merge(config('boost.mcp.tools.include', []), [
            ValidatePagePlan::class,
            AssemblePage::class,
            RevisePage::class,
        ])]);
    }

    protected function registerNav(): void
    {
        Nav::extend(function ($nav) {
            $nav->create('Scout')
                ->section('System')
                ->url('scout')
                ->icon('ai-chat-spark')
                ->can('use assistant');
        });
    }

    /**
     * The permission gating the assistant itself. Scout owns this group
     * exclusively — host sites needing their own permissions register a
     * group of their own rather than extending this one; super users
     * pass all checks regardless.
     */
    protected function registerPermissions(): void
    {
        Permission::extend(function () {
            Permission::group('assistant', 'Assistant', function () {
                Permission::register('use assistant')
                    ->label('Use the assistant (chat, draft pages)');
            });
        });
    }
}
