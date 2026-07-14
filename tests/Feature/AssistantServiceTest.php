<?php

namespace Cascadia\Scout\Tests\Feature;

use Cascadia\Scout\AssistantClient;
use Cascadia\Scout\AssistantService;
use Cascadia\Scout\Tests\TestCase;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

class AssistantServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.page_builder_field' => 'page_builder']);
    }

    public function test_it_answers_without_tools(): void
    {
        $this->fakeClient([
            ['text' => 'Use the CTA for that.', 'tool_calls' => [], 'stop_reason' => 'end_turn', 'raw_content' => []],
        ]);

        $result = app(AssistantService::class)->chat([
            ['role' => 'user', 'content' => 'Which component ends a page?'],
        ]);

        $this->assertSame('Use the CTA for that.', $result['reply']);
        $this->assertNull($result['draft']);
    }

    public function test_it_self_corrects_a_failing_plan_and_creates_a_draft(): void
    {
        $badPlan = [
            'title' => 'Assistant Test Page',
            'sections' => [
                ['component' => 'cta', 'fields' => ['cta_headline' => 'Nope', 'show_button' => true]],
            ],
        ];

        $goodPlan = [
            'title' => 'Assistant Test Page',
            'sections' => [
                ['component' => 'cta', 'fields' => ['cta_heading' => 'Hello', 'show_button' => true]],
            ],
        ];

        $client = $this->fakeClient([
            ['text' => '', 'tool_calls' => [['id' => 't1', 'name' => 'draft_page', 'input' => ['plan' => $badPlan]]], 'stop_reason' => 'tool_use', 'raw_content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'draft_page', 'input' => ['plan' => $badPlan]]]],
            ['text' => '', 'tool_calls' => [['id' => 't2', 'name' => 'draft_page', 'input' => ['plan' => $goodPlan]]], 'stop_reason' => 'tool_use', 'raw_content' => [['type' => 'tool_use', 'id' => 't2', 'name' => 'draft_page', 'input' => ['plan' => $goodPlan]]]],
            ['text' => 'Done! Your draft is ready.', 'tool_calls' => [], 'stop_reason' => 'end_turn', 'raw_content' => []],
        ]);

        $result = app(AssistantService::class)->chat([
            ['role' => 'user', 'content' => 'Make a test page'],
        ]);

        $this->assertSame('Done! Your draft is ready.', $result['reply']);
        $this->assertNotNull($result['draft']);
        $this->assertSame('assistant-test-page', $result['draft']['slug']);
        $this->assertSame(['cta'], $result['draft']['sections']);

        // The failing plan produced an error tool_result the model could read.
        $errorResult = collect($client->receivedMessages)
            ->flatten(1)
            ->firstWhere('type', 'tool_result');
        $this->assertTrue($errorResult['is_error']);
        $this->assertStringContainsString('did you mean "cta_heading"', $errorResult['content']);

        $entry = Entry::query()->where('collection', 'pages')->where('slug', 'assistant-test-page')->first();
        $this->assertNotNull($entry);
        $this->assertFalse($entry->published());
    }

    public function test_it_drafts_a_document_entry(): void
    {
        $plan = [
            'collection' => 'articles',
            'title' => 'A Guided Tour',
            'fields' => ['count' => 4],
        ];

        $this->fakeClient([
            ['text' => '', 'tool_calls' => [['id' => 't1', 'name' => 'draft_entry', 'input' => ['plan' => $plan]]], 'stop_reason' => 'tool_use', 'raw_content' => [['type' => 'tool_use', 'id' => 't1', 'name' => 'draft_entry', 'input' => ['plan' => $plan]]]],
            ['text' => 'Drafted.', 'tool_calls' => [], 'stop_reason' => 'end_turn', 'raw_content' => []],
        ]);

        $result = app(AssistantService::class)->chat([
            ['role' => 'user', 'content' => 'Write an article'],
        ]);

        $this->assertNotNull($result['draft']);
        $this->assertSame('articles', $result['draft']['collection']);

        $entry = Entry::query()->where('collection', 'articles')->where('slug', 'a-guided-tour')->first();
        $this->assertNotNull($entry);
        $this->assertFalse($entry->published());
    }

    public function test_it_gives_up_gracefully_after_max_iterations(): void
    {
        config(['scout.max_iterations' => 2]);

        $badCall = ['id' => 't', 'name' => 'draft_page', 'input' => ['plan' => ['title' => 'X', 'sections' => [['component' => 'nope', 'fields' => []]]]]];

        $this->fakeClient([
            ['text' => '', 'tool_calls' => [$badCall], 'stop_reason' => 'tool_use', 'raw_content' => [array_merge(['type' => 'tool_use'], $badCall)]],
            ['text' => '', 'tool_calls' => [$badCall], 'stop_reason' => 'tool_use', 'raw_content' => [array_merge(['type' => 'tool_use'], $badCall)]],
        ]);

        $result = app(AssistantService::class)->chat([
            ['role' => 'user', 'content' => 'Make a page'],
        ]);

        $this->assertStringContainsString('loses the trail', $result['reply']);
        $this->assertNull($result['draft']);
    }

    public function test_reads_are_scoped_to_the_users_collection_permissions(): void
    {
        $this->actingAs(User::make()->id('editor')->email('editor@example.com')->save());

        $findCall = ['id' => 't1', 'name' => 'find_pages', 'input' => ['query' => 'about']];
        $getCall = ['id' => 't2', 'name' => 'get_page', 'input' => ['entry_id' => 'pages-about']];

        $client = $this->fakeClient([
            ['text' => '', 'tool_calls' => [$findCall], 'stop_reason' => 'tool_use', 'raw_content' => [array_merge(['type' => 'tool_use'], $findCall)]],
            ['text' => '', 'tool_calls' => [$getCall], 'stop_reason' => 'tool_use', 'raw_content' => [array_merge(['type' => 'tool_use'], $getCall)]],
            ['text' => 'Nothing I can show you.', 'tool_calls' => [], 'stop_reason' => 'end_turn', 'raw_content' => []],
        ]);

        app(AssistantService::class)->chat([
            ['role' => 'user', 'content' => 'Find the about page'],
        ]);

        $results = collect($client->receivedMessages)->flatten(1)->where('type', 'tool_result')->values();

        $this->assertStringContainsString('No pages match', $results[0]['content']);
        $this->assertStringContainsString('No entry with id', $results[1]['content']);
    }

    public function test_super_users_read_everything(): void
    {
        $this->actingAs(User::make()->id('admin')->email('admin@example.com')->makeSuper()->save());

        $findCall = ['id' => 't1', 'name' => 'find_pages', 'input' => ['query' => 'about']];

        $client = $this->fakeClient([
            ['text' => '', 'tool_calls' => [$findCall], 'stop_reason' => 'tool_use', 'raw_content' => [array_merge(['type' => 'tool_use'], $findCall)]],
            ['text' => 'Found it.', 'tool_calls' => [], 'stop_reason' => 'end_turn', 'raw_content' => []],
        ]);

        app(AssistantService::class)->chat([
            ['role' => 'user', 'content' => 'Find the about page'],
        ]);

        $results = collect($client->receivedMessages)->flatten(1)->where('type', 'tool_result')->values();

        $this->assertStringContainsString('pages-about', $results[0]['content']);
    }

    /**
     * @param  array<int, array>  $responses
     */
    protected function fakeClient(array $responses): object
    {
        $fake = new class($responses) implements AssistantClient
        {
            public array $receivedMessages = [];

            public function __construct(protected array $responses) {}

            public function complete(string $system, array $messages, array $tools = [], ?\Closure $onText = null, ?\Closure $onActivity = null): array
            {
                $this->receivedMessages = array_column(
                    array_filter($messages, fn ($message) => is_array($message['content'])),
                    'content'
                );

                return array_shift($this->responses);
            }
        };

        $this->app->instance(AssistantClient::class, $fake);

        return $fake;
    }
}
