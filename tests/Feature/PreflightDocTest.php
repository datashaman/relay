<?php

namespace Tests\Feature;

use App\Contracts\AiProvider;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Stage;
use App\Services\AiProviders\AiProviderManager;
use App\Services\PreflightAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PreflightDocTest extends TestCase
{
    use RefreshDatabase;

    private function createMockProvider(array $response): AiProvider
    {
        return new class($response) implements AiProvider
        {
            public array $calls = [];

            public function __construct(private array $response) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                $this->calls[] = ['messages' => $messages, 'tools' => $tools];

                return $this->response;
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield $this->response;
            }
        };
    }

    private function createMultiCallProvider(array $responses): AiProvider
    {
        return new class($responses) implements AiProvider
        {
            private int $callIndex = 0;

            public function __construct(private array $responses) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                return $this->responses[$this->callIndex++] ?? end($this->responses);
            }

            public function stream(array $messages, array $tools = [], array $options = []): \Generator
            {
                yield [];
            }
        };
    }

    private function mockDocResponse(array $overrides = []): array
    {
        $defaults = [
            'summary' => 'Add a login page with email/password authentication.',
            'requirements' => ['Login form with email and password fields', 'Form validation', 'Authentication against user database'],
            'acceptance_criteria' => ['User can enter email and password', 'Invalid credentials show error', 'Successful login redirects to dashboard'],
            'affected_files' => ['resources/views/auth/login.blade.php', 'app/Http/Controllers/AuthController.php'],
            'approach' => 'Create a Blade view with a form, wire up a controller, and use Laravel Auth.',
            'scope_assessment' => [
                'size' => 'small',
                'risk_flags' => ['Security-sensitive authentication flow'],
                'suggested_autonomy' => 'supervised',
            ],
        ];

        return [
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_doc',
                    'name' => 'generate_preflight_doc',
                    'arguments' => array_merge($defaults, $overrides),
                ],
            ],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 150],
            'raw' => [],
        ];
    }

    private function mockClearAssessment(array $knownFacts = ['The issue asks for a login page']): array
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

    private function bindMultiCallProvider(array $responses): void
    {
        $mock = $this->createMultiCallProvider($responses);
        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('resolve')->willReturn($mock);
        $this->app->instance(AiProviderManager::class, $manager);
    }

    // === Structured Doc Generation ===

    public function test_clear_issue_generates_structured_doc_with_all_sections(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            $this->mockClearAssessment(['Users need login', 'Email/password auth']),
            $this->mockDocResponse(),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertNotNull($run->preflight_doc);
        $this->assertStringContainsString('## Summary', $run->preflight_doc);
        $this->assertStringContainsString('## Requirements', $run->preflight_doc);
        $this->assertStringContainsString('## Acceptance Criteria', $run->preflight_doc);
        $this->assertStringContainsString('## Affected Files', $run->preflight_doc);
        $this->assertStringContainsString('## Approach', $run->preflight_doc);
        $this->assertStringContainsString('## Scope Assessment', $run->preflight_doc);
    }

    public function test_doc_contains_summary_content(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            $this->mockClearAssessment(),
            $this->mockDocResponse(['summary' => 'Build a login page for user authentication.']),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertStringContainsString('Build a login page for user authentication.', $run->preflight_doc);
    }

    public function test_doc_contains_numbered_acceptance_criteria(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            $this->mockClearAssessment(),
            $this->mockDocResponse([
                'acceptance_criteria' => ['First criterion', 'Second criterion', 'Third criterion'],
            ]),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertStringContainsString('1. First criterion', $run->preflight_doc);
        $this->assertStringContainsString('2. Second criterion', $run->preflight_doc);
        $this->assertStringContainsString('3. Third criterion', $run->preflight_doc);
    }

    public function test_doc_contains_affected_files(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            $this->mockClearAssessment(),
            $this->mockDocResponse([
                'affected_files' => ['src/auth.php', 'tests/AuthTest.php'],
            ]),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertStringContainsString('src/auth.php', $run->preflight_doc);
        $this->assertStringContainsString('tests/AuthTest.php', $run->preflight_doc);
    }

    public function test_doc_contains_scope_assessment(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            $this->mockClearAssessment(),
            $this->mockDocResponse([
                'scope_assessment' => [
                    'size' => 'large',
                    'risk_flags' => ['Database migration', 'API breaking change'],
                    'suggested_autonomy' => 'manual',
                ],
            ]),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertStringContainsString('large', $run->preflight_doc);
        $this->assertStringContainsString('Database migration, API breaking change', $run->preflight_doc);
        $this->assertStringContainsString('manual', $run->preflight_doc);
    }

    public function test_doc_scope_assessment_shows_none_when_no_risk_flags(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            $this->mockClearAssessment(),
            $this->mockDocResponse([
                'scope_assessment' => [
                    'size' => 'small',
                    'risk_flags' => [],
                    'suggested_autonomy' => 'autonomous',
                ],
            ]),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertStringContainsString('**Risk Flags**: None', $run->preflight_doc);
    }

    public function test_doc_includes_issue_title_as_heading(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            $this->mockClearAssessment(),
            $this->mockDocResponse(),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertStringContainsString('# Preflight Doc: Add login page', $run->preflight_doc);
    }

    public function test_doc_generated_event_recorded(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            $this->mockClearAssessment(),
            $this->mockDocResponse(),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $event = $stage->events()->where('type', 'doc_generated')->first();
        $this->assertNotNull($event);
        $this->assertEquals('preflight_agent', $event->actor);
        $this->assertContains('summary', $event->payload['sections']);
        $this->assertContains('scope_assessment', $event->payload['sections']);
        $this->assertEquals(1, $event->payload['version']);
    }

    public function test_no_doc_tool_call_produces_fallback_doc(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            $this->mockClearAssessment(),
            [
                'content' => 'Some text response',
                'tool_calls' => [],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
                'raw' => [],
            ],
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertNotNull($run->preflight_doc);
        $this->assertStringContainsString('No structured doc generated.', $run->preflight_doc);
        $this->assertStringContainsString('## Scope Assessment', $run->preflight_doc);
    }

    // === Resume with Answers generates structured doc ===

    public function test_resume_with_answers_generates_structured_doc(): void
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

        $this->bindMultiCallProvider([
            $this->mockDocResponse(['summary' => 'Build admin dashboard with charts.']),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertStringContainsString('## Summary', $run->preflight_doc);
        $this->assertStringContainsString('Build admin dashboard with charts.', $run->preflight_doc);
        $this->assertEquals(StageStatus::Completed, $stage->fresh()->status);
    }

    public function test_skip_to_doc_generates_structured_doc(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $run->update([
            'known_facts' => ['Some facts'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Question?', 'type' => 'text'],
            ],
        ]);

        $this->bindMultiCallProvider([
            $this->mockDocResponse(),
        ]);

        app(PreflightAgent::class)->execute($stage, ['skip_to_doc' => true]);

        $run->refresh();
        $this->assertStringContainsString('## Summary', $run->preflight_doc);
        $this->assertStringContainsString('## Acceptance Criteria', $run->preflight_doc);
        $this->assertEquals(StageStatus::Completed, $stage->fresh()->status);
    }

    // === Doc Versioning ===

    public function test_rerun_preserves_previous_doc_in_history(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $run->update(['preflight_doc' => '# Old doc content']);

        $run->update([
            'known_facts' => ['New facts'],
            'clarification_answers' => ['q1' => 'answer'],
            'clarification_questions' => [['id' => 'q1', 'text' => 'Q?', 'type' => 'text']],
        ]);

        $this->bindMultiCallProvider([
            $this->mockDocResponse(['summary' => 'New version of the doc.']),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertStringContainsString('New version of the doc.', $run->preflight_doc);
        $this->assertNotNull($run->preflight_doc_history);
        $this->assertCount(1, $run->preflight_doc_history);
        $this->assertEquals('# Old doc content', $run->preflight_doc_history[0]['doc']);
        $this->assertArrayHasKey('created_at', $run->preflight_doc_history[0]);
        $this->assertArrayHasKey('iteration', $run->preflight_doc_history[0]);
    }

    public function test_first_doc_has_no_history(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $this->bindMultiCallProvider([
            $this->mockClearAssessment(),
            $this->mockDocResponse(),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $run->refresh();
        $this->assertNotNull($run->preflight_doc);
        $this->assertNull($run->preflight_doc_history);
    }

    public function test_doc_version_increments_in_event(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $run->update([
            'preflight_doc' => '# V1',
            'preflight_doc_history' => [
                ['doc' => '# V0', 'created_at' => now()->toIso8601String(), 'iteration' => 0],
            ],
            'known_facts' => ['Fact'],
            'clarification_answers' => ['q1' => 'a'],
            'clarification_questions' => [['id' => 'q1', 'text' => 'Q?', 'type' => 'text']],
        ]);

        $this->bindMultiCallProvider([
            $this->mockDocResponse(),
        ]);

        app(PreflightAgent::class)->execute($stage, []);

        $event = $stage->events()->where('type', 'doc_generated')->first();
        $this->assertEquals(3, $event->payload['version']);
    }

    // === Doc Messages include context ===

    public function test_doc_generation_receives_known_facts_and_answers(): void
    {
        Queue::fake();
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $run->update([
            'known_facts' => ['Login needed', 'Email auth'],
            'clarification_questions' => [
                ['id' => 'q1', 'text' => 'Which page?', 'type' => 'text'],
            ],
            'clarification_answers' => ['q1' => 'Homepage'],
        ]);

        $capturedMessages = null;
        $mockProvider = new class($capturedMessages) implements AiProvider
        {
            public function __construct(private &$captured) {}

            public function chat(array $messages, array $tools = [], array $options = []): array
            {
                $this->captured = $messages;

                return [
                    'content' => null,
                    'tool_calls' => [
                        [
                            'id' => 'c1',
                            'name' => 'generate_preflight_doc',
                            'arguments' => [
                                'summary' => 'Test summary',
                                'requirements' => ['Req 1'],
                                'acceptance_criteria' => ['AC 1'],
                                'affected_files' => ['file.php'],
                                'approach' => 'Approach text',
                                'scope_assessment' => ['size' => 'small', 'risk_flags' => [], 'suggested_autonomy' => 'supervised'],
                            ],
                        ],
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

        $manager = $this->createMock(AiProviderManager::class);
        $manager->method('resolve')->willReturn($mockProvider);
        $this->app->instance(AiProviderManager::class, $manager);

        app(PreflightAgent::class)->execute($stage, []);

        $userMessage = collect($capturedMessages)->firstWhere('role', 'user')['content'];
        $this->assertStringContainsString('Login needed', $userMessage);
        $this->assertStringContainsString('Email auth', $userMessage);
        $this->assertStringContainsString('Which page?', $userMessage);
        $this->assertStringContainsString('Homepage', $userMessage);
    }

    // === Controller: Doc View ===

    public function test_show_doc_displays_preflight_doc(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $run->update(['preflight_doc' => "## Summary\n\nTest doc content"]);

        $response = $this->get(route('preflight.doc', $run));

        $response->assertOk();
        $response->assertSee('Preflight Doc');
        $response->assertSee('Test doc content');
        $response->assertSee('Edit Doc');
    }

    public function test_show_doc_redirects_when_no_doc(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $response = $this->get(route('preflight.doc', $run));

        $response->assertRedirect(route('issues.queue'));
        $response->assertSessionHas('error');
    }

    public function test_show_doc_displays_history(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $run->update([
            'preflight_doc' => '## Current doc',
            'preflight_doc_history' => [
                ['doc' => '## Old doc v1', 'created_at' => '2026-04-12T10:00:00+00:00', 'iteration' => 0],
            ],
        ]);

        $response = $this->get(route('preflight.doc', $run));

        $response->assertOk();
        $response->assertSee('Doc History');
        $response->assertSee('Version 1');
        $response->assertSee('Old doc v1');
    }

    public function test_show_doc_hides_history_when_empty(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $run->update(['preflight_doc' => '## Doc content']);

        $response = $this->get(route('preflight.doc', $run));

        $response->assertOk();
        $response->assertDontSee('Doc History');
    }

    // === Controller: Edit Doc ===

    public function test_edit_doc_shows_textarea_with_content(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $run->update(['preflight_doc' => "## Summary\n\nEditable content"]);

        $response = $this->get(route('preflight.doc.edit', $run));

        $response->assertOk();
        $response->assertSee('Edit Preflight Doc');
        $response->assertSee('Editable content');
        $response->assertSee('Save Changes');
        $response->assertSee('Cancel');
    }

    public function test_edit_doc_redirects_when_no_doc(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $response = $this->get(route('preflight.doc.edit', $run));

        $response->assertRedirect(route('issues.queue'));
        $response->assertSessionHas('error');
    }

    public function test_update_doc_saves_changes_and_preserves_history(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $run->update(['preflight_doc' => '## Original doc']);

        $response = $this->put(route('preflight.doc.update', $run), [
            'preflight_doc' => '## Edited doc with changes',
        ]);

        $response->assertRedirect(route('preflight.doc', $run));
        $response->assertSessionHas('success');

        $run->refresh();
        $this->assertEquals('## Edited doc with changes', $run->preflight_doc);
        $this->assertNotNull($run->preflight_doc_history);
        $this->assertCount(1, $run->preflight_doc_history);
        $this->assertEquals('## Original doc', $run->preflight_doc_history[0]['doc']);
    }

    public function test_update_doc_validates_required(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $run->update(['preflight_doc' => '## Doc']);

        $response = $this->put(route('preflight.doc.update', $run), [
            'preflight_doc' => '',
        ]);

        $response->assertSessionHasErrors('preflight_doc');
    }

    public function test_update_doc_redirects_when_no_doc(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();

        $response = $this->put(route('preflight.doc.update', $run), [
            'preflight_doc' => 'Something',
        ]);

        $response->assertRedirect(route('issues.queue'));
        $response->assertSessionHas('error');
    }

    public function test_update_doc_appends_to_existing_history(): void
    {
        [$issue, $run, $stage] = $this->setupRunWithStage();
        $run->update([
            'preflight_doc' => '## V2 doc',
            'preflight_doc_history' => [
                ['doc' => '## V1 doc', 'created_at' => '2026-04-12T09:00:00+00:00', 'iteration' => 0],
            ],
        ]);

        $this->put(route('preflight.doc.update', $run), [
            'preflight_doc' => '## V3 doc',
        ]);

        $run->refresh();
        $this->assertEquals('## V3 doc', $run->preflight_doc);
        $this->assertCount(2, $run->preflight_doc_history);
        $this->assertEquals('## V1 doc', $run->preflight_doc_history[0]['doc']);
        $this->assertEquals('## V2 doc', $run->preflight_doc_history[1]['doc']);
    }

    // === Migration ===

    public function test_preflight_doc_history_column_is_nullable(): void
    {
        $run = Run::factory()->create();

        $this->assertNull($run->preflight_doc_history);
    }

    public function test_preflight_doc_history_stores_json(): void
    {
        $history = [
            ['doc' => '# V1', 'created_at' => '2026-04-12T10:00:00+00:00', 'iteration' => 0],
        ];

        $run = Run::factory()->create([
            'preflight_doc_history' => $history,
        ]);

        $run->refresh();
        $this->assertIsArray($run->preflight_doc_history);
        $this->assertCount(1, $run->preflight_doc_history);
        $this->assertEquals('# V1', $run->preflight_doc_history[0]['doc']);
    }
}
