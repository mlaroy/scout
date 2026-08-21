<?php

namespace Cascadia\Scout\Http\Controllers;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Cascadia\Scout\AssistantService;
use Cascadia\Scout\ProviderManager;
use Cascadia\Scout\SiteContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Statamic\Facades\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantController
{
    /**
     * The full-page assistant (System > Assistant).
     */
    public function page(ProviderManager $providers, SiteContext $site): Response
    {
        abort_unless(User::current()->can('use assistant'), 403);

        return Inertia::render('AssistantPage', [
            'title' => 'Assistant',
            'configured' => $providers->configured(),
            'model' => $providers->model(),
            'provider' => $providers->label(),
            'showBubble' => User::current()->preferences()['assistant']['show_bubble'] ?? true,
            'builderField' => $site->builderField(),
            'catalogCollection' => config('scout-assistant.catalog_collection'),
            'supportsAttachments' => $providers->supportsAttachments(),
        ]);
    }

    public function preferences(Request $request): JsonResponse
    {
        abort_unless(User::current()->can('use assistant'), 403);

        $validated = $request->validate(['show_bubble' => ['required', 'boolean']]);

        User::current()->setPreference('assistant.show_bubble', $validated['show_bubble'])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Widget boot payload: what the current user can do.
     */
    public function boot(ProviderManager $providers): JsonResponse
    {
        $user = User::current();

        return response()->json([
            'configured' => $providers->configured(),
            'can_chat' => $user->can('use assistant'),
            'show_bubble' => $user->preferences()['assistant']['show_bubble'] ?? true,
            'supports_attachments' => $providers->supportsAttachments(),
        ]);
    }

    public function chat(Request $request, AssistantService $assistant, ProviderManager $providers): JsonResponse|StreamedResponse
    {
        abort_unless(User::current()->can('use assistant'), 403);

        abort_unless($providers->configured(), 422, 'No AI provider configured. Set ANTHROPIC_API_KEY, OPENAI_API_KEY, or XAI_API_KEY.');

        $request->validate([
            'messages' => ['required', 'array', 'max:40'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required'],
            'context' => ['sometimes', 'array'],
            'context.entry_id' => ['sometimes', 'string', 'max:64'],
        ]);

        $validated = [
            'messages' => collect($request->input('messages'))
                ->map(fn (array $message) => [
                    'role' => $message['role'],
                    'content' => $this->validateMessageContent($message['content'] ?? null, $providers),
                ])
                ->all(),
            'context' => $request->input('context', []),
        ];

        if ($request->header('Accept') === 'text/event-stream') {
            return $this->streamChat($assistant, $validated);
        }

        try {
            $result = $assistant->chat($validated['messages'], $validated['context'] ?? []);
        } catch (\Throwable $exception) {
            return response()->json(['message' => $this->apiErrorMessage($exception)], 422);
        }

        return response()->json($result);
    }

    /**
     * Stream the chat turn as server-sent events: a progress event per
     * tool call, then done (reply + draft) or error.
     */
    protected function streamChat(AssistantService $assistant, array $validated): StreamedResponse
    {
        return response()->stream(function () use ($assistant, $validated) {
            @set_time_limit(180);
            @ini_set('zlib.output_compression', '0');

            // Close every PHP output buffer so events leave immediately,
            // then pad past FPM/nginx's initial buffer so the first small
            // events aren't held back until a buffer fills. (Skipped in
            // tests, where the harness captures output via its own buffer.)
            if (! app()->runningUnitTests()) {
                while (ob_get_level() > 0) {
                    @ob_end_flush();
                }

                echo ':'.str_repeat(' ', 8192)."\n\n";
                flush();
            }

            $send = function (array $event) {
                echo 'data: '.json_encode($event)."\n\n";
                flush();
            };

            try {
                $result = $assistant->chat(
                    $validated['messages'],
                    $validated['context'] ?? [],
                    fn (array $event) => $send($event),
                );

                $send(['type' => 'done', 'reply' => $result['reply'], 'draft' => $result['draft']]);
            } catch (\Throwable $exception) {
                $send(['type' => 'error', 'message' => $this->apiErrorMessage($exception)]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * A message's content is either a plain string, or — for a provider
     * with attachment support — an array of Anthropic-shaped content
     * blocks (text / document). Rebuilt from validated fields only; the
     * client's raw array is never passed through as-is.
     *
     * @return string|array<int, array<string, mixed>>
     */
    protected function validateMessageContent(mixed $content, ProviderManager $providers): string|array
    {
        if (is_string($content)) {
            abort_unless(mb_strlen($content) <= 20000, 422, 'Message is too long.');

            return $content;
        }

        abort_unless(is_array($content) && $providers->supportsAttachments(), 422, 'Attachments are not supported by the current AI provider.');
        abort_unless(count($content) <= 2, 422, 'Too many attachments — send one file per message.');

        return collect($content)
            ->map(fn ($block) => $this->validateContentBlock($block))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateContentBlock(mixed $block): array
    {
        abort_unless(is_array($block), 422, 'Invalid message content.');

        if (($block['type'] ?? null) === 'text') {
            $text = $block['text'] ?? null;
            abort_unless(is_string($text) && mb_strlen($text) <= 20000, 422, 'Message is too long.');

            return ['type' => 'text', 'text' => $text];
        }

        abort_unless(($block['type'] ?? null) === 'document', 422, 'Invalid message content.');

        $mediaType = $block['source']['media_type'] ?? null;
        abort_unless($mediaType === 'application/pdf', 422, 'Only PDF attachments are supported.');

        $data = $block['source']['data'] ?? null;
        abort_unless(is_string($data) && $data !== '', 422, 'Attachment data is missing.');

        $decoded = base64_decode($data, true);
        abort_unless($decoded !== false && strlen($decoded) > 0, 422, 'Attachment data is invalid.');
        abort_unless(strlen($decoded) <= 10 * 1024 * 1024, 422, 'PDF attachments are limited to 10MB.');

        $title = $block['title'] ?? null;

        return array_filter([
            'type' => 'document',
            'source' => [
                'type' => 'base64',
                'media_type' => 'application/pdf',
                'data' => $data,
            ],
            'title' => is_string($title) ? mb_substr($title, 0, 200) : null,
            'cache_control' => ['type' => 'ephemeral'],
        ], fn ($value) => $value !== null);
    }

    protected function apiErrorMessage(\Throwable $exception): string
    {
        return match (true) {
            $exception instanceof AuthenticationException => 'The Claude API key is invalid — check ANTHROPIC_API_KEY in .env.',
            $exception instanceof RateLimitException => 'The Claude API is rate limiting us — wait a moment and try again.',
            $exception instanceof APIStatusException => str_contains($exception->getMessage(), 'credit balance')
                ? 'The Claude API account is out of credits — top up under Plans & Billing in the Anthropic Console.'
                : tap('The Claude API returned an error — check the logs for details.', fn () => report($exception)),
            $exception instanceof APIConnectionException => 'Could not reach the Claude API — check your connection and try again.',
            $exception instanceof RuntimeException => tap($exception->getMessage(), fn () => report($exception)),
            default => tap('Something went wrong — check the logs for details.', fn () => report($exception)),
        };
    }
}
