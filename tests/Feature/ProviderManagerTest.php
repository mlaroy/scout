<?php

namespace Cascadia\Scout\Tests\Feature;

use Cascadia\Scout\AnthropicAssistantClient;
use Cascadia\Scout\OpenAIAssistantClient;
use Cascadia\Scout\ProviderManager;
use Cascadia\Scout\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProviderManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout-assistant.provider' => null]);
        config(['scout-assistant.providers.anthropic.api_key' => null]);
        config(['scout-assistant.providers.openai.api_key' => null]);
        config(['scout-assistant.providers.xai.api_key' => null]);
    }

    public function test_it_is_unconfigured_when_no_provider_has_an_api_key(): void
    {
        $providers = new ProviderManager;

        $this->assertFalse($providers->configured());
        $this->assertNull($providers->active());
        $this->assertNull($providers->label());
    }

    public function test_it_auto_detects_the_first_configured_provider_in_array_order(): void
    {
        config(['scout-assistant.providers.openai.api_key' => 'sk-test']);
        config(['scout-assistant.providers.xai.api_key' => 'xai-test']);

        $providers = new ProviderManager;

        // anthropic is first in config('scout-assistant.providers') but has no key,
        // so openai (the next configured one) should win.
        $this->assertSame('openai', $providers->active());
        $this->assertTrue($providers->configured());
    }

    public function test_anthropic_wins_when_configured_since_it_is_first_in_provider_order(): void
    {
        config(['scout-assistant.providers.anthropic.api_key' => 'anthropic-test']);
        config(['scout-assistant.providers.openai.api_key' => 'sk-test']);

        $providers = new ProviderManager;

        $this->assertSame('anthropic', $providers->active());
    }

    public function test_an_explicit_provider_override_wins_when_it_has_an_api_key(): void
    {
        config(['scout-assistant.provider' => 'openai']);
        config(['scout-assistant.providers.anthropic.api_key' => 'anthropic-test']);
        config(['scout-assistant.providers.openai.api_key' => 'sk-test']);

        $providers = new ProviderManager;

        $this->assertSame('openai', $providers->active());
    }

    public function test_an_explicit_provider_override_falls_back_to_auto_detect_when_unconfigured(): void
    {
        config(['scout-assistant.provider' => 'openai']);
        config(['scout-assistant.providers.anthropic.api_key' => 'anthropic-test']);
        config(['scout-assistant.providers.openai.api_key' => null]);

        $providers = new ProviderManager;

        $this->assertSame('anthropic', $providers->active());
    }

    public function test_make_client_resolves_the_bound_class_for_the_active_provider(): void
    {
        config(['scout-assistant.providers.openai.api_key' => 'sk-test']);

        $providers = new ProviderManager;

        $this->assertInstanceOf(OpenAIAssistantClient::class, $providers->makeClient());
    }

    public function test_make_client_aborts_when_no_provider_is_configured(): void
    {
        $providers = new ProviderManager;

        try {
            $providers->makeClient();
            $this->fail('Expected an HttpException to be thrown.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_it_resolves_anthropic_client_when_only_anthropic_is_configured(): void
    {
        config(['scout-assistant.providers.anthropic.api_key' => 'anthropic-test']);

        $providers = new ProviderManager;

        $this->assertInstanceOf(AnthropicAssistantClient::class, $providers->makeClient());
    }
}
