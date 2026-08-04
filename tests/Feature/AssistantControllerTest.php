<?php

namespace Cascadia\Scout\Tests\Feature;

use Cascadia\Scout\AssistantService;
use Cascadia\Scout\Http\Controllers\AssistantController;
use Cascadia\Scout\ProviderManager;
use Cascadia\Scout\Tests\TestCase;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssistantControllerTest extends TestCase
{
    public function test_audit_is_forbidden_without_the_use_assistant_permission(): void
    {
        $this->actingAs(User::make()->id('editor')->email('editor@example.com')->save());

        $this->expectException(HttpException::class);

        app(AssistantController::class)->audit();
    }

    public function test_audit_is_allowed_with_the_use_assistant_permission(): void
    {
        $this->actingAs(User::make()->id('admin')->email('admin@example.com')->makeSuper()->save());

        // No components:audit artisan command registered in the test app,
        // so this proves the permission check passed and we reached the
        // (unrelated) "command not found" abort instead of a 403.
        try {
            app(AssistantController::class)->audit();
            $this->fail('Expected a 404 for the missing components:audit command.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_preferences_is_forbidden_without_the_use_assistant_permission(): void
    {
        $this->actingAs(User::make()->id('editor')->email('editor@example.com')->save());

        $this->expectException(HttpException::class);

        app(AssistantController::class)->preferences(Request::create('/', 'POST', ['show_bubble' => true]));
    }

    public function test_preferences_is_allowed_with_the_use_assistant_permission(): void
    {
        $this->actingAs(User::make()->id('admin')->email('admin@example.com')->makeSuper()->save());

        $response = app(AssistantController::class)->preferences(Request::create('/', 'POST', ['show_bubble' => false]));

        $this->assertSame(['ok' => true], $response->getData(true));
    }

    public function test_chat_is_forbidden_without_the_use_assistant_permission(): void
    {
        $this->actingAs(User::make()->id('editor')->email('editor@example.com')->save());

        $this->expectException(HttpException::class);

        app(AssistantController::class)->chat(
            Request::create('/', 'POST', ['messages' => [['role' => 'user', 'content' => 'Hi']]]),
            app(AssistantService::class),
            app(ProviderManager::class),
        );
    }
}
