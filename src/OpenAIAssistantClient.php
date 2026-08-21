<?php

namespace Cascadia\Scout;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to any OpenAI Chat Completions-compatible API (OpenAI itself, or
 * xAI's Grok, which exposes the same wire format at a different base URL —
 * see config('scout-assistant.providers.openai'/'xai')).
 *
 * AssistantService and the rest of the app only ever see Anthropic-shaped
 * content blocks (see AssistantClient::complete()'s docblock), so this
 * client translates in both directions: incoming messages/tool results are
 * converted to OpenAI's role/tool_calls shape before the request, and the
 * reply's tool calls are converted back into Anthropic-style content
 * blocks for `raw_content` — which only this class ever reads back in on
 * the next turn, so the round trip only needs to be internally consistent.
 */
class OpenAIAssistantClient implements AssistantClient
{
    protected array $config;

    public function __construct(ProviderManager $providers)
    {
        $this->config = $providers->config();
    }

    public function complete(string $system, array $messages, array $tools = [], ?\Closure $onText = null, ?\Closure $onActivity = null): array
    {
        $payload = [
            'model' => $this->config['model'],
            'max_tokens' => (int) $this->config['max_tokens'],
            'messages' => $this->toOpenAiMessages($system, $messages),
            'stream' => (bool) $onText,
        ];

        if ($tools) {
            $payload['tools'] = $this->toOpenAiTools($tools);
        }

        return $onText
            ? $this->streamedCompletion($payload, $onText, $onActivity)
            : $this->completion($payload);
    }

    protected function completion(array $payload): array
    {
        $response = $this->request()->post('chat/completions', $payload);

        $this->assertOk($response);

        $choice = $response->json('choices.0');

        return $this->toResult(
            $choice['message']['content'] ?? '',
            $choice['message']['tool_calls'] ?? [],
            $choice['finish_reason'] ?? null,
        );
    }

    protected function streamedCompletion(array $payload, \Closure $onText, ?\Closure $onActivity): array
    {
        $response = $this->request()->withOptions(['stream' => true])->post('chat/completions', $payload);

        $this->assertOk($response);

        $text = '';
        $toolCalls = [];
        $finishReason = null;
        $buffer = '';

        $body = $response->toPsrResponse()->getBody();

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($boundary = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $boundary));
                $buffer = substr($buffer, $boundary + 1);

                if (! str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = substr($line, 6);

                if ($data === '[DONE]') {
                    continue;
                }

                $delta = json_decode($data, true)['choices'][0] ?? null;
                if (! $delta) {
                    continue;
                }

                if ($chunk = $delta['delta']['content'] ?? null) {
                    $text .= $chunk;
                    $onText($chunk);
                }

                foreach ($delta['delta']['tool_calls'] ?? [] as $toolCallDelta) {
                    $index = $toolCallDelta['index'];

                    if (! isset($toolCalls[$index])) {
                        $toolCalls[$index] = ['id' => '', 'name' => '', 'arguments' => ''];

                        if ($onActivity && isset($toolCallDelta['function']['name'])) {
                            $onActivity(['type' => 'tool_start', 'name' => $toolCallDelta['function']['name']]);
                        }
                    }

                    $toolCalls[$index]['id'] .= $toolCallDelta['id'] ?? '';
                    $toolCalls[$index]['name'] .= $toolCallDelta['function']['name'] ?? '';
                    $toolCalls[$index]['arguments'] .= $toolCallDelta['function']['arguments'] ?? '';
                }

                $finishReason ??= $delta['finish_reason'] ?? null;
            }
        }

        return $this->toResult($text, array_map(
            fn ($call) => ['id' => $call['id'], 'function' => ['name' => $call['name'], 'arguments' => $call['arguments']]],
            array_values($toolCalls),
        ), $finishReason);
    }

    /**
     * @param  array<int, array{id: string, function: array{name: string, arguments: string}}>  $toolCalls  OpenAI's raw shape, arguments still a JSON string.
     */
    protected function toResult(string $text, array $toolCalls, ?string $finishReason): array
    {
        $calls = array_map(fn ($call) => [
            'id' => $call['id'],
            'name' => $call['function']['name'],
            'input' => json_decode($call['function']['arguments'], true) ?? [],
        ], $toolCalls);

        $rawContent = [];

        if ($text !== '') {
            $rawContent[] = ['type' => 'text', 'text' => $text];
        }

        foreach ($calls as $call) {
            $rawContent[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['name'], 'input' => $call['input']];
        }

        return [
            'text' => $text,
            'tool_calls' => $calls,
            'stop_reason' => $finishReason === 'tool_calls' || $calls ? 'tool_use' : 'end_turn',
            'raw_content' => $rawContent,
        ];
    }

    /**
     * Anthropic-shaped conversation history in, OpenAI messages out: text
     * content passes through as-is; assistant `tool_use` blocks become an
     * assistant message with `tool_calls`; user `tool_result` blocks each
     * become a `role: tool` message.
     */
    protected function toOpenAiMessages(string $system, array $messages): array
    {
        $openAiMessages = [['role' => 'system', 'content' => $system]];

        foreach ($messages as $message) {
            if (is_string($message['content'])) {
                $openAiMessages[] = ['role' => $message['role'], 'content' => $message['content']];

                continue;
            }

            if ($message['role'] === 'assistant') {
                $openAiMessages[] = $this->toOpenAiAssistantMessage($message['content']);

                continue;
            }

            foreach ($message['content'] as $block) {
                $openAiMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $block['tool_use_id'],
                    'content' => is_string($block['content']) ? $block['content'] : json_encode($block['content']),
                ];
            }
        }

        return $openAiMessages;
    }

    protected function toOpenAiAssistantMessage(array $blocks): array
    {
        $text = collect($blocks)->where('type', 'text')->pluck('text')->implode('');

        $toolCalls = collect($blocks)->where('type', 'tool_use')->map(fn ($block) => [
            'id' => $block['id'],
            'type' => 'function',
            'function' => ['name' => $block['name'], 'arguments' => json_encode($block['input'])],
        ])->values()->all();

        $message = ['role' => 'assistant', 'content' => $text ?: null];

        if ($toolCalls) {
            $message['tool_calls'] = $toolCalls;
        }

        return $message;
    }

    /**
     * @param  array<int, array{name: string, description: string, input_schema: array}>  $tools
     */
    protected function toOpenAiTools(array $tools): array
    {
        return array_map(fn ($tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['input_schema'],
            ],
        ], $tools);
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->config['base_url'])
            ->withToken($this->config['api_key'])
            ->timeout(180)
            ->acceptJson();
    }

    protected function assertOk(Response $response): void
    {
        if ($response->failed()) {
            throw new RuntimeException($response->json('error.message') ?? "The API request failed with status {$response->status()}.");
        }
    }
}
