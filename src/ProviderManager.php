<?php

namespace Cascadia\Scout;

/**
 * Resolves which configured AI provider (config('scout-assistant.providers')) is
 * active — an explicit config('scout-assistant.provider') override if it has an
 * api_key, otherwise the first provider in config order that does.
 */
class ProviderManager
{
    /**
     * @return array<string, array{client: class-string<AssistantClient>, label: string, api_key: ?string, model: string}>
     */
    protected function providers(): array
    {
        return config('scout-assistant.providers', []);
    }

    public function active(): ?string
    {
        $explicit = config('scout-assistant.provider');

        if ($explicit && $this->hasApiKey($explicit)) {
            return $explicit;
        }

        foreach ($this->providers() as $name => $provider) {
            if ($this->hasApiKey($name)) {
                return $name;
            }
        }

        return null;
    }

    public function configured(): bool
    {
        return $this->active() !== null;
    }

    /**
     * @return array{client: class-string<AssistantClient>, label: string, api_key: ?string, model: string}
     */
    public function config(): array
    {
        return $this->providers()[$this->active()] ?? [];
    }

    public function label(): ?string
    {
        return $this->config()['label'] ?? null;
    }

    public function model(): ?string
    {
        return $this->config()['model'] ?? null;
    }

    public function makeClient(): AssistantClient
    {
        abort_unless($this->configured(), 422, 'No AI provider configured. Set ANTHROPIC_API_KEY, OPENAI_API_KEY, or XAI_API_KEY.');

        return app()->make($this->config()['client']);
    }

    protected function hasApiKey(string $provider): bool
    {
        return (bool) ($this->providers()[$provider]['api_key'] ?? null);
    }
}
