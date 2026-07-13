<?php

namespace Cascadia\Scout;

use Cascadia\Scout\Console\Commands\PagesAssemble;
use Cascadia\Scout\Mcp\Tools\AssemblePage;
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
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(AssistantClient::class, AnthropicAssistantClient::class);
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
     */
    protected function registerMcpTools(): void
    {
        if (! class_exists(Tool::class)) {
            return;
        }

        config(['boost.mcp.tools.include' => array_merge(config('boost.mcp.tools.include', []), [
            ValidatePagePlan::class,
            AssemblePage::class,
        ])]);
    }

    protected function registerNav(): void
    {
        Nav::extend(function ($nav) {
            $nav->create('Assistant')
                ->section('System')
                ->url('assistant')
                ->icon('ai-chat-spark')
                ->can('use assistant');
        });
    }

    /**
     * The permission gating the assistant itself. Host sites may register
     * further permissions into the same group (the kit adds its catalog
     * sync there); super users pass all checks.
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
