<?php

namespace Cascadia\Scout;

/**
 * The provider boundary for the CP assistant. Everything above this
 * interface (service, endpoints, widget) is provider-agnostic; swap
 * providers by binding a different implementation in a service provider.
 */
interface AssistantClient
{
    /**
     * Send a conversation and get the next assistant turn.
     *
     * @param  array<int, array{role: string, content: mixed}>  $messages
     * @param  array<int, array{name: string, description: string, input_schema: array}>  $tools
     * @param  \Closure(string): void|null  $onText  Called with each text delta as it streams, when the provider supports it.
     * @param  \Closure(array): void|null  $onActivity  Streamed liveness signals: ['type' => 'thinking'], ['type' => 'tool_start', 'name' => ...], or ['type' => 'tool_input', 'name' => ..., 'chars' => ...] (throttled).
     * @return array{text: string, tool_calls: array<int, array{id: string, name: string, input: array}>, stop_reason: ?string}
     */
    public function complete(string $system, array $messages, array $tools = [], ?\Closure $onText = null, ?\Closure $onActivity = null): array;
}
