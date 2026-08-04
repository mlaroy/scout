<?php

namespace Cascadia\Scout\Tests\Feature;

use Cascadia\Scout\OpenAIAssistantClient;
use Cascadia\Scout\ProviderManager;
use Cascadia\Scout\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIAssistantClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.providers.openai.api_key' => 'sk-test']);
        config(['scout.providers.openai.base_url' => 'https://api.openai.com/v1']);
        config(['scout.providers.openai.model' => 'gpt-5.1']);
        config(['scout.providers.openai.max_tokens' => 8192]);
    }

    public function test_a_plain_text_reply_is_returned_as_end_turn(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Hello there.'],
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);

        $client = new OpenAIAssistantClient(new ProviderManager);

        $result = $client->complete('You are helpful.', [
            ['role' => 'user', 'content' => 'Hi'],
        ]);

        $this->assertSame('Hello there.', $result['text']);
        $this->assertSame('end_turn', $result['stop_reason']);
        $this->assertSame([], $result['tool_calls']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request['messages'][0] === ['role' => 'system', 'content' => 'You are helpful.']
                && $request['messages'][1] === ['role' => 'user', 'content' => 'Hi'];
        });
    }

    public function test_a_tool_call_reply_round_trips_through_anthropic_shaped_raw_content(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_123',
                            'type' => 'function',
                            'function' => ['name' => 'find_pages', 'arguments' => '{"query":"about"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
        ]);

        $client = new OpenAIAssistantClient(new ProviderManager);

        $result = $client->complete('system', [
            ['role' => 'user', 'content' => 'Find the about page'],
        ], [
            ['name' => 'find_pages', 'description' => 'Find pages', 'input_schema' => ['type' => 'object', 'properties' => []]],
        ]);

        $this->assertSame('tool_use', $result['stop_reason']);
        $this->assertSame([
            ['id' => 'call_123', 'name' => 'find_pages', 'input' => ['query' => 'about']],
        ], $result['tool_calls']);
        $this->assertSame([
            ['type' => 'tool_use', 'id' => 'call_123', 'name' => 'find_pages', 'input' => ['query' => 'about']],
        ], $result['raw_content']);

        // Feeding that raw_content + a tool_result back in (as AssistantService
        // does on the next turn) must translate into OpenAI's tool-call shape
        // without erroring.
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Found it.'], 'finish_reason' => 'stop']],
            ]),
        ]);

        $client->complete('system', [
            ['role' => 'user', 'content' => 'Find the about page'],
            ['role' => 'assistant', 'content' => $result['raw_content']],
            ['role' => 'user', 'content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'call_123', 'content' => 'Found: About', 'is_error' => false],
            ]],
        ]);

        Http::assertSent(function ($request) {
            $messages = $request['messages'];

            return $messages[2]['role'] === 'assistant'
                && $messages[2]['tool_calls'][0]['id'] === 'call_123'
                && $messages[3]['role'] === 'tool'
                && $messages[3]['tool_call_id'] === 'call_123'
                && $messages[3]['content'] === 'Found: About';
        });
    }

    public function test_a_failed_request_throws_with_the_providers_error_message(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401),
        ]);

        $client = new OpenAIAssistantClient(new ProviderManager);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid API key');

        $client->complete('system', [['role' => 'user', 'content' => 'Hi']]);
    }
}
