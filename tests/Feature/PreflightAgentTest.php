<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Jobs\ExecuteStageJob;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Stage;
use App\Services\AiProviders\AiProviderManager;
use App\Services\PreflightAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PreflightAgentTest extends TestCase
{
    use RefreshDatabase;

    private function createMockProvider(array $response): AiProvider
    {
        return new class($response) implements AiProvider
        {
            public function __construct(private array $response) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                return $this->response;
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield $this->response;
            }
        };
    }

    private function mockClearResponse(array $knownFacts = ['The issue asks for a login page']): array
    {
        return [
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'name' => 'assess_issue',
                    'arguments' => [
                        'confidence' => 'clear',
                        'known_facts' => $knownFacts,
                    ],
                ],
            ],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            'raw' => [],
        ];
    }

    private function mockAmbiguousResponse(array $knownFacts = ['The issue mentions a dashboard'], array $questions = null): array
    {
        $questions ??= [
            [
                'id' => 'q1',
                'text' => 'Which dashboard do you mean?',
                'type' => 'choice',
                'options' => ['Admin dashboard', 'User dashboard', 'Analytics dashboard'],
            ],
            [
                'id' => 'q2',
                'text' => 'What specific data should be displayed?',
                'type' => 'text',
            ],
        ];

        return [
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_1',
                    'name' => 'assess_issue',
                    'arguments' => [
                        'confidence' => 'ambiguous',
                        'known_facts' => $knownFacts,
                        'questions' => $questions,
                    ],
                ],
            ],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 80],
            'raw' => [],
        ];
    }

    private function setupRunWithStage(): array
    {
        $issue = Issue::factory()->create([
            'title' => 'Add login page',
            'body' => 'Users should be able to log in with email and password.',
            'status' => IssueStatus::InProgress,
            'labels' => ['feature', 'auth'],
            'assignee' => 'johndoe',
        ]);

        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
            'started_at' => now(),
        ]);

        $stage = Stage::factory()->create([
            'run_id' => $run->id,
            'name' => StageName::Preflight,
            'status' => StageStatus::Running,
            'started_at' => now(),
        ]);

        return [$issue, $run, $stage];
    }

    private function bindMockProvider(array $response): void
    {
        $mock = $this->createMockProvider($response);
        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('resolve')->willReturn($mock);
        $this->app->instance(AiProviderManager::class, $manager);
    }

    // === PreflightAgent Tests ===

    public function test_clear_issue_completes_stage_and_stores_known_facts(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $knownFacts = ['Users need a login page', 'Authentication uses email and password'];
        $this->bindMockProvider($this->mockClearResponse($knownFacts));

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $stage->refresh();

        $this->assertEquals($knownFacts, $run->known_facts);
        $this->assertNotNull($run->preflight_doc);
        $this->assertStringContainsString('Known Facts', $run->preflight_doc);
        $this->assertStringContainsString('Users need a login page', $run->preflight_doc);
        $this->assertEquals(StageStatus::Completed, $stage->status);
    }

    public function test_clear_issue_stores_original_issue_in_doc(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMockProvider($this->mockClearResponse());

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertStringContainsString('Original Issue', $run->preflight_doc);
        $this->assertStringContainsString($issue->title, $run->preflight_doc);
        $this->assertStringContainsString($issue->body, $run->preflight_doc);
    }

    public function test_ambiguous_issue_pauses_stage_and_stores_questions(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMockProvider($this->mockAmbiguousResponse());

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $stage->refresh();

        $this->assertEquals(StageStatus::AwaitingApproval, $stage->status);
        $this->assertNotNull($run->clarification_questions);
        $this->assertCount(2, $run->clarification_questions);
        $this->assertEquals('q1', $run->clarification_questions[0]['id']);
        $this->assertEquals('choice', $run->clarification_questions[0]['type']);
        $this->assertEquals('text', $run->clarification_questions[1]['type']);
        $this->assertNotNull($run->known_facts);
        $this->assertNull($run->preflight_doc);
    }

    public function test_assessment_complete_event_recorded(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMockProvider($this->mockClearResponse(['Fact one', 'Fact two']));

        app(PreflightAgent::class)->execute($stage, []);

        $event = $stage->events()->where('type', 'assessment_complete')->first();
        $this->assertNotNull($event);
        $this->assertEquals('preflight_agent', $event->actor);
        $this->assertEquals('clear', $event->payload['confidence']);
        $this->assertEquals(2, $event->payload['known_facts_count']);
    }

    public function test_clarification_needed_event_recorded(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMockProvider($this->mockAmbiguousResponse());

        app(PreflightAgent::class)->execute($stage, []);

        $event = $stage->events()->where('type', 'clarification_needed')->first();
        $this->assertNotNull($event);
        $this->assertEquals('preflight_agent', $event->actor);
        $this->assertCount(2, $event->payload['questions']);
    }

    public function test_resume_with_answers_completes_stage(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $run->update([
            'known_facts' => ['Dashboard issue'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Which dashboard?', 'type' => 'choice', 'options' => ['Admin', 'User']],
            ],
            'clarification_answers' => ['q1' => 'Admin'],
        ]);

        $this->bindMockProvider($this->mockClearResponse());

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $stage->refresh();

        $this->assertEquals(StageStatus::Completed, $stage->status);
        $this->assertNotNull($run->preflight_doc);
        $this->assertStringContainsString('Clarification Answers', $run->preflight_doc);
        $this->assertStringContainsString('Which dashboard?', $run->preflight_doc);
        $this->assertStringContainsString('Admin', $run->preflight_doc);
    }

    public function test_skip_to_doc_completes_without_answers(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $run->update([
            'known_facts' => ['Some facts here'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Question?', 'type' => 'text'],
            ],
        ]);

        $this->bindMockProvider($this->mockClearResponse());

        app(PreflightAgent::class)->execute($stage, ['skip_to_doc' => true]);

        $run->refresh();
        $stage->refresh();

        $this->assertEquals(StageStatus::Completed, $stage->status);
        $this->assertNotNull($run->preflight_doc);
        $this->assertStringNotContainsString('Clarification Answers', $run->preflight_doc);
    }

    public function test_no_tool_call_defaults_to_clear(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMockProvider([
            'content' => 'The issue looks fine.',
            'tool_calls' => [],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            'raw' => [],
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $stage->refresh();
        $this->assertEquals(StageStatus::Completed, $stage->status);
    }

    public function test_issue_labels_and_assignee_included_in_messages(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $capturedMessages = null;
        $mock = new class($capturedMessages) implements AiProvider
        {
            public function __construct(private &$captured) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                $this->captured = $messages;

                return [
                    'content' => null,
                    'tool_calls' => [
                        ['id' => 'c1', 'name' => 'assess_issue', 'arguments' => ['confidence' => 'clear', 'known_facts' => []]],
                    ],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
                    'raw' => [],
                ];
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield [];
            }
        };

        Queue::fake();
        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('resolve')->willReturn($mock);
        $this->app->instance(AiProviderManager::class, $manager);

        app(PreflightAgent::class)->execute($stage, []);

        $userMessage = collect($capturedMessages)->firstWhere('role', 'user')['content'];
        $this->assertStringContainsString('feature, auth', $userMessage);
        $this->assertStringContainsString('johndoe', $userMessage);
    }

    // === ExecuteStageJob Tests ===

    public function test_execute_stage_job_dispatches_preflight_agent(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMockProvider($this->mockClearResponse());

        $job = new ExecuteStageJob($stage, []);
        $job->handle();

        $stage->refresh();
        $this->assertEquals(StageStatus::Completed, $stage->status);
    }

    public function test_execute_stage_job_ignores_non_preflight_stages(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $stage->update(['name' => StageName::Implement]);

        $job = new ExecuteStageJob($stage, []);
        $job->handle();

        $stage->refresh();
        $this->assertEquals(StageStatus::Running, $stage->status);
    }

    // === PreflightController Tests ===

    public function test_show_clarification_page(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $stage->update(['status' => StageStatus::AwaitingApproval]);
        $run->update([
            'known_facts' => ['Users need login', 'Email-based auth'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Which auth provider?', 'type' => 'choice', 'options' => ['OAuth', 'Local']],
                ['id' => 'q2', 'text' => 'Any design specs?', 'type' => 'text'],
            ],
        ]);

        $response = $this->get(route('preflight.show', $run));

        $response->assertOk();
        $response->assertSee('Known Facts');
        $response->assertSee('Users need login');
        $response->assertSee('Email-based auth');
        $response->assertSee('Which auth provider?');
        $response->assertSee('OAuth');
        $response->assertSee('Local');
        $response->assertSee('Any design specs?');
        $response->assertSee('Submit Answers');
        $response->assertSee('Skip to Doc');
    }

    public function test_show_redirects_when_no_pending_stage(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $response = $this->get(route('preflight.show', $run));

        $response->assertRedirect(route('issues.queue'));
        $response->assertSessionHas('error');
    }

    public function test_submit_answers_stores_and_resumes(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $stage->update(['status' => StageStatus::AwaitingApproval]);
        $run->update([
            'known_facts' => ['Fact'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Which dashboard?', 'type' => 'choice', 'options' => ['Admin', 'User']],
                ['id' => 'q2', 'text' => 'Any constraints?', 'type' => 'text'],
            ],
        ]);

        $response = $this->post(route('preflight.submit-answers', $run), [
            'answer_q1' => 'Admin',
            'answer_q2' => 'Must use React',
        ]);

        $response->assertRedirect(route('issues.queue'));
        $response->assertSessionHas('success');

        $run->refresh();
        $this->assertEquals('Admin', $run->clarification_answers['q1']);
        $this->assertEquals('Must use React', $run->clarification_answers['q2']);

        $stage->refresh();
        $this->assertEquals(StageStatus::Running, $stage->status);

        Queue::assertPushed(ExecuteStageJob::class);
    }

    public function test_submit_answers_skips_empty_answers(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $stage->update(['status' => StageStatus::AwaitingApproval]);
        $run->update([
            'known_facts' => ['Fact'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Question 1?', 'type' => 'text'],
                ['id' => 'q2', 'text' => 'Question 2?', 'type' => 'text'],
            ],
        ]);

        $response = $this->post(route('preflight.submit-answers', $run), [
            'answer_q1' => 'My answer',
            'answer_q2' => '',
        ]);

        $run->refresh();
        $this->assertArrayHasKey('q1', $run->clarification_answers);
        $this->assertArrayNotHasKey('q2', $run->clarification_answers);
    }

    public function test_submit_answers_redirects_when_no_pending_stage(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $response = $this->post(route('preflight.submit-answers', $run), []);

        $response->assertRedirect(route('issues.queue'));
        $response->assertSessionHas('error');
    }

    public function test_skip_to_doc_resumes_with_skip_context(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $stage->update(['status' => StageStatus::AwaitingApproval]);
        $run->update([
            'known_facts' => ['Some fact'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Question?', 'type' => 'text'],
            ],
        ]);

        $response = $this->post(route('preflight.skip', $run));

        $response->assertRedirect(route('issues.queue'));
        $response->assertSessionHas('success');

        $stage->refresh();
        $this->assertEquals(StageStatus::Running, $stage->status);

        Queue::assertPushed(ExecuteStageJob::class, function ($job) {
            return ($job->context['skip_to_doc'] ?? false) === true;
        });
    }

    public function test_skip_to_doc_redirects_when_no_pending_stage(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $response = $this->post(route('preflight.skip', $run));

        $response->assertRedirect(route('issues.queue'));
        $response->assertSessionHas('error');
    }

    public function test_known_facts_panel_rendered_before_questions(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $stage->update(['status' => StageStatus::AwaitingApproval]);
        $run->update([
            'known_facts' => ['First known fact'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'First question?', 'type' => 'text'],
            ],
        ]);

        $response = $this->get(route('preflight.show', $run));

        $content = $response->getContent();
        $factsPos = strpos($content, 'Known Facts');
        $questionsPos = strpos($content, 'First question?');

        $this->assertNotFalse($factsPos);
        $this->assertNotFalse($questionsPos);
        $this->assertLessThan($questionsPos, $factsPos);
    }

    public function test_choice_questions_render_radio_buttons(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $stage->update(['status' => StageStatus::AwaitingApproval]);
        $run->update([
            'known_facts' => ['Fact'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Pick one?', 'type' => 'choice', 'options' => ['Option A', 'Option B']],
            ],
        ]);

        $response = $this->get(route('preflight.show', $run));

        $response->assertSee('type="radio"', false);
        $response->assertSee('Option A');
        $response->assertSee('Option B');
    }

    public function test_text_questions_render_textarea(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $stage->update(['status' => StageStatus::AwaitingApproval]);
        $run->update([
            'known_facts' => ['Fact'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Describe it?', 'type' => 'text'],
            ],
        ]);

        $response = $this->get(route('preflight.show', $run));

        $response->assertSee('<textarea', false);
        $response->assertSee('Describe it?');
    }

    public function test_answers_persist_on_run_and_available_in_context(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $knownFacts = ['Dashboard needs charts'];
        $questions = [
            ['id' => 'q1', 'text' => 'Which charts?', 'type' => 'choice', 'options' => ['Bar', 'Line', 'Pie']],
        ];
        $this->bindMockProvider($this->mockAmbiguousResponse($knownFacts, $questions));

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertNotNull($run->clarification_questions);
        $this->assertNull($run->clarification_answers);

        $run->update(['clarification_answers' => ['q1' => 'Bar']]);

        $this->bindMockProvider($this->mockClearResponse());
        $stage->refresh();
        $stage->update(['status' => StageStatus::Running]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertNotNull($run->preflight_doc);
        $this->assertStringContainsString('Bar', $run->preflight_doc);
        $this->assertEquals(['q1' => 'Bar'], $run->clarification_answers);
    }

    // === Migration Tests ===

    public function test_clarification_columns_are_nullable(): void
    {
        $run = Run::factory()->create();

        $this->assertNull($run->known_facts);
        $this->assertNull($run->clarification_questions);
        $this->assertNull($run->clarification_answers);
    }

    public function test_clarification_columns_store_json(): void
    {
        $run = Run::factory()->create([
            'known_facts' => ['Fact one', 'Fact two'],
            'clarification_questions' => [['id' => 'q1', 'text' => 'Q?', 'type' => 'text']],
            'clarification_answers' => ['q1' => 'Answer'],
        ]);

        $run->refresh();
        $this->assertIsArray($run->known_facts);
        $this->assertCount(2, $run->known_facts);
        $this->assertIsArray($run->clarification_questions);
        $this->assertIsArray($run->clarification_answers);
        $this->assertEquals('Answer', $run->clarification_answers['q1']);
    }
}
