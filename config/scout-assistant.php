<?php

use Cascadia\Scout\AnthropicAssistantClient;
use Cascadia\Scout\OpenAIAssistantClient;

return [

    /*
    |--------------------------------------------------------------------------
    | AI provider
    |--------------------------------------------------------------------------
    |
    | Scout drafts content through a validated write pipeline; it talks to
    | whichever AI provider below is configured via the AssistantClient
    | contract (src/AssistantClient.php). To add a provider, implement the
    | interface and register it in the `providers` array.
    |
    | Set this to a key from `providers` (e.g. "openai") to force that
    | provider. Leave it null to auto-detect: the first provider below
    | with an api_key present wins, in array order.
    |
    */

    'provider' => env('SCOUT_PROVIDER'),

    'providers' => [

        'anthropic' => [
            'client' => AnthropicAssistantClient::class,
            'label' => 'Anthropic (Claude)',
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('SCOUT_MODEL', 'claude-opus-4-8'),
            'max_tokens' => env('SCOUT_MAX_TOKENS', 8192),
        ],

        'openai' => [
            'client' => OpenAIAssistantClient::class,
            'label' => 'OpenAI (ChatGPT / Codex)',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'model' => env('SCOUT_OPENAI_MODEL', 'gpt-5.1'),
            'max_tokens' => env('SCOUT_MAX_TOKENS', 8192),
        ],

        'xai' => [
            'client' => OpenAIAssistantClient::class,
            'label' => 'xAI (Grok)',
            'api_key' => env('XAI_API_KEY'),
            'base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),
            'model' => env('SCOUT_XAI_MODEL', 'grok-4'),
            'max_tokens' => env('SCOUT_MAX_TOKENS', 8192),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Loop guard
    |--------------------------------------------------------------------------
    |
    | Maximum tool-use round trips per chat turn (plan validation retries
    | count against this), and a per-turn budget guard.
    |
    */

    'max_iterations' => 10,

    /*
    |--------------------------------------------------------------------------
    | Page builder field
    |--------------------------------------------------------------------------
    |
    | "My site has a page-builder concept; this is the replicator field
    | handle." When null, Scout offers blueprint-generic drafting only —
    | section-style page plans are unavailable.
    |
    */

    'page_builder_field' => null,

    /*
    |--------------------------------------------------------------------------
    | Component catalog
    |--------------------------------------------------------------------------
    |
    | "My site maintains a component catalog; this is the collection
    | handle." When null, Scout drafts without catalog judgment.
    |
    */

    'catalog_collection' => null,

    /*
    |--------------------------------------------------------------------------
    | Write boundaries
    |--------------------------------------------------------------------------
    |
    | Collections Scout may never draft in or revise, and field handles the
    | write pipeline refuses to set anywhere (password protection,
    | membership flags, pricing, ...).
    |
    */

    'excluded_collections' => [],

    'excluded_fields' => [],

    /*
    |--------------------------------------------------------------------------
    | Search index
    |--------------------------------------------------------------------------
    |
    | When set, Scout's content lookups run through this Statamic search
    | index (config/statamic/search.php) instead of scanning entries
    | directly. Recommended for large sites.
    |
    */

    'search_index' => env('SCOUT_SEARCH_INDEX'),

];
