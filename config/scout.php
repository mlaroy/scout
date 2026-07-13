<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Claude API
    |--------------------------------------------------------------------------
    |
    | Scout drafts content through a validated write pipeline. It talks to
    | the Claude API via the AssistantClient contract; to use a different
    | provider, bind your own implementation in a service provider.
    |
    */

    'api_key' => env('ANTHROPIC_API_KEY'),

    'model' => env('SCOUT_MODEL', 'claude-opus-4-8'),

    'max_tokens' => env('SCOUT_MAX_TOKENS', 8192),

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
