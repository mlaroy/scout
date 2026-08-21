<?php

namespace Cascadia\Scout\Tests\Feature;

use Cascadia\Scout\AssistantClient;
use Cascadia\Scout\AssistantService;
use Cascadia\Scout\Http\Controllers\AssistantController;
use Cascadia\Scout\ProviderManager;
use Cascadia\Scout\Tests\TestCase;
use Illuminate\Http\Request;
use Statamic\Facades\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AssistantControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout-assistant.providers.anthropic.api_key' => null]);
        config(['scout-assistant.providers.openai.api_key' => null]);
        config(['scout-assistant.providers.xai.api_key' => null]);
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

    public function test_chat_rejects_array_content_when_the_active_provider_does_not_support_attachments(): void
    {
        config(['scout-assistant.providers.openai.api_key' => 'sk-test']);
        $this->actingAs(User::make()->id('admin')->email('admin@example.com')->makeSuper()->save());

        $this->expectException(HttpException::class);

        app(AssistantController::class)->chat(
            Request::create('/', 'POST', ['messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ]]),
            app(AssistantService::class),
            app(ProviderManager::class),
        );
    }

    public function test_chat_rejects_a_non_pdf_document_attachment(): void
    {
        config(['scout-assistant.providers.anthropic.api_key' => 'anthropic-test']);
        $this->actingAs(User::make()->id('admin')->email('admin@example.com')->makeSuper()->save());
        $this->fakeClient([]);

        try {
            app(AssistantController::class)->chat(
                Request::create('/', 'POST', ['messages' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'document',
                        'source' => ['media_type' => 'image/png', 'data' => base64_encode('not a pdf')],
                    ]],
                ]]]),
                app(AssistantService::class),
                app(ProviderManager::class),
            );
            $this->fail('Expected an HttpException to be thrown.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_chat_rejects_malformed_attachment_data(): void
    {
        config(['scout-assistant.providers.anthropic.api_key' => 'anthropic-test']);
        $this->actingAs(User::make()->id('admin')->email('admin@example.com')->makeSuper()->save());
        $this->fakeClient([]);

        try {
            app(AssistantController::class)->chat(
                Request::create('/', 'POST', ['messages' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'document',
                        'source' => ['media_type' => 'application/pdf', 'data' => 'not-base64!!!'],
                    ]],
                ]]]),
                app(AssistantService::class),
                app(ProviderManager::class),
            );
            $this->fail('Expected an HttpException to be thrown.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_chat_accepts_a_pdf_attachment_when_anthropic_is_active(): void
    {
        config(['scout-assistant.providers.anthropic.api_key' => 'anthropic-test']);
        $this->actingAs(User::make()->id('admin')->email('admin@example.com')->makeSuper()->save());

        $client = $this->fakeClient([
            ['text' => 'Got it.', 'tool_calls' => [], 'stop_reason' => 'end_turn', 'raw_content' => []],
        ]);

        $response = app(AssistantController::class)->chat(
            Request::create('/', 'POST', ['messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'document', 'source' => ['media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 fake pdf bytes')], 'title' => 'brief.pdf'],
                    ['type' => 'text', 'text' => 'Draft this into a page.'],
                ],
            ]]]),
            app(AssistantService::class),
            app(ProviderManager::class),
        );

        $this->assertSame(200, $response->getStatusCode());

        $sentContent = $client->receivedMessages[0];
        $this->assertSame('document', $sentContent[0]['type']);
        $this->assertSame('application/pdf', $sentContent[0]['source']['media_type']);
        $this->assertSame('ephemeral', $sentContent[0]['cache_control']['type']);
        $this->assertSame('brief.pdf', $sentContent[0]['title']);
    }

    /**
     * @param  array<int, array>  $responses
     */
    protected function fakeClient(array $responses): object
    {
        $fake = new class($responses) implements AssistantClient
        {
            public array $receivedMessages = [];

            public function __construct(protected array $responses) {}

            public function complete(string $system, array $messages, array $tools = [], ?\Closure $onText = null, ?\Closure $onActivity = null): array
            {
                $this->receivedMessages = array_column(
                    array_filter($messages, fn ($message) => is_array($message['content'])),
                    'content'
                );

                return array_shift($this->responses);
            }
        };

        $this->app->instance(AssistantClient::class, $fake);

        return $fake;
    }
}
