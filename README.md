# Scout

An AI content assistant for the Statamic control panel, with a validated write pipeline underneath it. Scout goes by a Sasquatch's reputation: rarely seen working, reliably leaves finished drafts behind.

Editors get a chat bubble in the control panel (and a full page under **System → Scout**) where they can paste rough content and get back an unpublished draft page, ask which content structure suits a piece of content, or revise a draft conversationally ("make the hero dark and drop the stats section").

## How it writes

Everything Scout writes goes through the **write pipeline** — `PagePlanValidator` and `PageAssembler`. The AI proposes a *plan*, never entry data; deterministic code validates every field against the real blueprints (unknown fields get "did you mean" suggestions, invalid options list the valid ones, entry and asset references are resolved and checked) and either saves a correct entry or returns specific errors the AI uses to fix its own plan.

The guardrails are absolute:

- **A human publishes, always.** Scout creates and revises unpublished drafts, and on collections with revisions enabled it can revise published entries too — as a working copy the editor reviews and publishes. It never publishes anything and never writes to the live version of a published entry; every write ends with a review link to the normal edit screen. On collections without revisions, published entries are read-only to Scout.
- **Nothing writes around the pipeline.** The chat, the `pages:assemble` command, and the MCP tools are different doorways into the same validation.
- **Explicit boundaries.** `excluded_collections` and `excluded_fields` in the config are refused at validation time, wherever they appear.

## Installation

```bash
composer require mlaroy/scout
```

Set one of `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, or `XAI_API_KEY` in `.env` — Scout auto-detects whichever is present, in that priority order (see [Swapping the AI provider](#swapping-the-ai-provider)). Without a key, the chat input is disabled but everything else still renders.

Publish the config if you want to change it:

```bash
php artisan vendor:publish --tag=scout-assistant-config
```

(The package is still `mlaroy/scout` and CP assets still publish to `public/vendor/scout/`, but the config file and its publish tags are namespaced `scout-assistant` — `config/scout.php` collides with [Laravel Scout](https://laravel.com/docs/scout), the unrelated full-text search package, so this addon deliberately doesn't use that filename.)

## Configuration

Scout adapts to what your site has. Two optional declarations unlock its site-aware behavior:

```php
return [
    // "My site has a page-builder concept; this is the replicator field."
    // Enables sections-style page plans with component-aware validation.
    // Null = blueprint-generic drafting only.
    'page_builder_field' => null,

    // "My site maintains a component catalog; this is the collection."
    // Gives the assistant judgment about which components to use when.
    // Null = the AI chooses on general reasoning.
    'catalog_collection' => null,

    // Collections and fields the assistant may never touch.
    'excluded_collections' => [],
    'excluded_fields' => [],

    // Optional Statamic search index for content lookups on large sites.
    // Falls back to a direct entry scan when unset or failing.
    'search_index' => env('SCOUT_SEARCH_INDEX'),
];
```

A vanilla Statamic site leaves the first two null and still gets a genuinely useful assistant: drafting and revising entries in any collection, validated against blueprints. A site with a component/page-builder system points Scout at it and gets component-aware page composition. Scout ships no assumptions about any particular kit's field or collection handles — including the [Cascadia starter kit](https://statamic.com/starter-kits/mlaroy/cascadia), which doesn't require or configure Scout at all. If you're running both, publish this config yourself and set these two keys to Cascadia's own handles: `'page_builder_field' => 'page_blocks'` and `'catalog_collection' => 'components'`.

The catalog collection, when configured, should hold one entry per builder component with `description`, `use_when`, `avoid_when`, and `content_expectations` fields — Scout reads these when deciding which components fit a brief.

**System → Scout** shows the current state of both — a status table with each field's configured handle (or "Not set"), and a notice explaining exactly what capability is missing and why when either is null. Nothing is silently degraded without telling you.

## Attachments

When Claude is the active provider, editors can drag a PDF into the chat (or use the attach button) and Scout drafts directly from its contents — Claude reads the PDF natively, no separate extraction step. Attachments are capped at 10MB and PDF-only for now. This is gated on Claude's native document support, so it isn't available when OpenAI or xAI is the active provider (`ProviderManager::supportsAttachments()`).

## Permissions

Scout registers a **"use assistant"** permission (chat, drafting), in its own "assistant" permission group. Super users pass automatically. This group is Scout's exclusively — it has no knowledge of, and no dependency on, anything a host site or kit built on top of it does. A host wanting its own permissions (for its own tooling, unrelated to Scout) registers its own group rather than extending this one.

Inside a chat, reads are scoped to the signed-in editor's own CP permissions: search, page contents, and collection field listings only surface collections the user could open themselves ("view {handle} entries"), and asset search only surfaces containers they can view. Collections in `excluded_collections` stay invisible to everyone. All queries run through Statamic's own repositories (Stache / configured search index) — the model can only call Scout's fixed tools; it has no query language and no API access.

## Swapping the AI provider

The code talks to the small `Cascadia\Scout\AssistantClient` interface. Scout ships two implementations: `AnthropicAssistantClient` (Claude) and `OpenAIAssistantClient`, a generic OpenAI Chat Completions-compatible client that also serves xAI's Grok, since it exposes the same wire format at a different base URL.

`config('scout-assistant.providers')` lists each provider's client class, API key, base URL (OpenAI-compatible providers only), and model. `ProviderManager` picks the active one: an explicit `SCOUT_PROVIDER` override if its `api_key` is set, otherwise the first provider in that list with an `api_key` present — `anthropic`, then `openai`, then `xai`.

To add another provider (say, Gemini), implement `AssistantClient` and add an entry to `config('scout-assistant.providers')`:

```php
'gemini' => [
    'client' => \App\GeminiAssistantClient::class,
    'label' => 'Google (Gemini)',
    'api_key' => env('GEMINI_API_KEY'),
    'model' => env('SCOUT_GEMINI_MODEL', 'gemini-3-pro'),
    'max_tokens' => env('SCOUT_MAX_TOKENS', 8192),
],
```

Setting `GEMINI_API_KEY` then makes it eligible for auto-detection like any bundled provider.

## Other doorways

- `php artisan pages:assemble plan.yaml [--dry-run]` — validate a YAML page plan and create a draft from the command line.
- `php artisan pages:revise <entry-id> plan.yaml [--dry-run]` — apply a revised plan to an existing entry: drafts update in place, published entries (revisions required) save as a working copy for CP review.
- MCP tools `validate-page-plan`, `assemble-page`, and `revise-page` register with Laravel Boost's MCP server automatically when `laravel/mcp` is present — AI coding agents get the same validated write path.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for local development setup.
