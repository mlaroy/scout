<?php

namespace Cascadia\Scout\Http\Controllers;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Cascadia\Scout\AssistantService;
use Cascadia\Scout\ProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
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
    public function page(ProviderManager $providers): Response
    {
        abort_unless(User::current()->can('use assistant'), 403);

        return Inertia::render('AssistantPage', [
            'title' => 'Assistant',
            'configured' => $providers->configured(),
            'canAudit' => $this->hasCommand('components:audit'),
            'canSync' => User::current()->can('sync component catalog') && $this->hasCommand('components:sync'),
            'model' => $providers->model(),
            'provider' => $providers->label(),
            'showBubble' => User::current()->preferences()['assistant']['show_bubble'] ?? true,
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
            'can_audit' => $this->hasCommand('components:audit'),
            'can_sync' => $user->can('sync component catalog') && $this->hasCommand('components:sync'),
            'show_bubble' => $user->preferences()['assistant']['show_bubble'] ?? true,
        ]);
    }

    /**
     * Quick actions wrap host-site artisan commands (the kit's conventions
     * tooling). On a site without them, the chips simply don't render.
     */
    protected function hasCommand(string $name): bool
    {
        return array_key_exists($name, Artisan::all());
    }

    public function chat(Request $request, AssistantService $assistant, ProviderManager $providers): JsonResponse|StreamedResponse
    {
        abort_unless(User::current()->can('use assistant'), 403);

        abort_unless($providers->configured(), 422, 'No AI provider configured. Set ANTHROPIC_API_KEY, OPENAI_API_KEY, or XAI_API_KEY.');

        $validated = $request->validate([
            'messages' => ['required', 'array', 'max:40'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:20000'],
            'context' => ['sometimes', 'array'],
            'context.entry_id' => ['sometimes', 'string', 'max:64'],
        ]);

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

    public function audit(): JsonResponse
    {
        abort_unless(User::current()->can('use assistant'), 403);

        abort_unless($this->hasCommand('components:audit'), 404);

        $exitCode = Artisan::call('components:audit');

        return response()->json([
            'passed' => $exitCode === 0,
            'output' => trim(Artisan::output()),
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        abort_unless($this->hasCommand('components:sync'), 404);

        abort_unless(User::current()->can('sync component catalog'), 403);

        $exitCode = Artisan::call('components:sync', $request->boolean('prune') ? ['--prune' => true] : []);

        return response()->json([
            'passed' => $exitCode === 0,
            'output' => trim(Artisan::output()),
        ]);
    }
}
