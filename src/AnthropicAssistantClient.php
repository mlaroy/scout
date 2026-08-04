<?php

namespace Cascadia\Scout;

use Anthropic\Client;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Anthropic\Messages\Message;
use Anthropic\Messages\RawContentBlockDeltaEvent;
use Anthropic\Messages\RawContentBlockStartEvent;
use Anthropic\Messages\TextDelta;
use Anthropic\Messages\ThinkingBlock;
use Anthropic\Messages\ToolUseBlock;

class AnthropicAssistantClient implements AssistantClient
{
    protected Client $client;

    protected array $config;

    public function __construct(ProviderManager $providers)
    {
        $this->config = $providers->config();
        $this->client = new Client(apiKey: $this->config['api_key'] ?? null);
    }

    public function complete(string $system, array $messages, array $tools = [], ?\Closure $onText = null, ?\Closure $onActivity = null): array
    {
        $response = $onText
            ? $this->streamedMessage($system, $messages, $tools, $onText, $onActivity)
            : $this->client->messages->create(
                maxTokens: (int) $this->config['max_tokens'],
                messages: $messages,
                model: $this->config['model'],
                system: $system,
                thinking: ['type' => 'adaptive'],
                tools: $tools ?: null,
            );

        $text = '';
        $toolCalls = [];

        foreach ($response->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }

            if ($block->type === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block->id,
                    'name' => $block->name,
                    'input' => $block->input,
                ];
            }
        }

        return [
            'text' => $text,
            'tool_calls' => $toolCalls,
            'stop_reason' => $response->stopReason,
            'raw_content' => array_map(
                fn ($block) => $block->jsonSerialize(),
                array_values(array_filter(
                    $response->content,
                    fn ($block) => in_array($block->type, ['text', 'tool_use', 'thinking', 'redacted_thinking'])
                ))
            ),
        ];
    }

    protected function streamedMessage(string $system, array $messages, array $tools, \Closure $onText, ?\Closure $onActivity = null): Message
    {
        $stream = $this->client->messages->createStream(
            maxTokens: (int) $this->config['max_tokens'],
            messages: $messages,
            model: $this->config['model'],
            system: $system,
            thinking: ['type' => 'adaptive'],
            tools: $tools ?: null,
        );

        $accumulator = MessageAccumulator::forMessages();

        foreach ($stream as $event) {
            $accumulator->accumulate($event);

            if ($event instanceof RawContentBlockDeltaEvent
                && $event->delta instanceof TextDelta) {
                $onText($event->delta->text);
            }

            if ($onActivity && $event instanceof RawContentBlockStartEvent) {
                if ($event->contentBlock instanceof ThinkingBlock) {
                    $onActivity(['type' => 'thinking']);
                } elseif ($event->contentBlock instanceof ToolUseBlock) {
                    $onActivity(['type' => 'tool_start', 'name' => $event->contentBlock->name]);
                }
            }
        }

        return $accumulator->message();
    }
}
