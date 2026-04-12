<?php

namespace Database\Seeders;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\SourceType;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Enums\StuckState;
use App\Models\AutonomyConfig;
use App\Models\EscalationRule;
use App\Models\FilterRule;
use App\Models\Issue;
use App\Models\Repository;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Models\StageEvent;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // Don't double-seed.
        if (Source::count() > 0) {
            $this->command?->info('Demo data already seeded, skipping.');
            return;
        }

        $this->command?->info('Seeding demo data...');

        $acme = Source::factory()->create([
            'name' => 'acme/platform',
            'type' => SourceType::GitHub,
            'external_account' => 'acme',
            'last_synced_at' => now()->subMinutes(4),
            'is_active' => true,
            'is_intake_paused' => false,
            'backlog_threshold' => 20,
        ]);

        $ingest = Source::factory()->create([
            'name' => 'acme/ingest',
            'type' => SourceType::GitHub,
            'external_account' => 'acme',
            'last_synced_at' => now()->subMinutes(11),
            'is_active' => true,
            'is_intake_paused' => false,
            'backlog_threshold' => 15,
        ]);

        $jira = Source::factory()->create([
            'name' => 'ACME / Payments',
            'type' => SourceType::Jira,
            'external_account' => 'acme.atlassian.net',
            'last_synced_at' => now()->subHours(2),
            'is_active' => true,
            'is_intake_paused' => true,
            'backlog_threshold' => 25,
        ]);

        // Filter rules per source
        FilterRule::factory()->create([
            'source_id' => $acme->id,
            'include_labels' => ['bug', 'performance'],
            'exclude_labels' => ['wontfix', 'discussion'],
            'unassigned_only' => true,
            'auto_accept_labels' => ['relay/auto'],
        ]);
        FilterRule::factory()->create([
            'source_id' => $ingest->id,
            'include_labels' => ['bug'],
            'exclude_labels' => ['epic'],
            'unassigned_only' => false,
            'auto_accept_labels' => null,
        ]);
        FilterRule::factory()->create([
            'source_id' => $jira->id,
            'include_labels' => null,
            'exclude_labels' => ['Spike'],
            'unassigned_only' => true,
            'auto_accept_labels' => null,
        ]);

        // Repositories
        $platformRepo = Repository::factory()->create([
            'name' => 'acme/platform',
            'path' => '/Users/dev/code/platform',
            'default_branch' => 'main',
            'worktree_root' => '/tmp/relay-worktrees/platform',
        ]);
        $ingestRepo = Repository::factory()->create([
            'name' => 'acme/ingest',
            'path' => '/Users/dev/code/ingest',
            'default_branch' => 'main',
            'worktree_root' => '/tmp/relay-worktrees/ingest',
        ]);

        // Autonomy stage override so there's a "tightened" stage to display.
        AutonomyConfig::create([
            'scope' => AutonomyScope::Global,
            'scope_id' => null,
            'stage' => StageName::Release,
            'level' => AutonomyLevel::Manual,
        ]);

        // Escalation rules
        EscalationRule::factory()->create([
            'name' => 'Security-sensitive paths',
            'condition' => ['type' => 'file_path_match', 'operator' => '~', 'value' => 'app/Services/Auth/*'],
            'target_level' => AutonomyLevel::Manual,
            'scope' => AutonomyScope::Global,
            'order' => 1,
            'is_enabled' => true,
        ]);
        EscalationRule::factory()->create([
            'name' => 'Security label triggers manual',
            'condition' => ['type' => 'label_match', 'operator' => '~', 'value' => 'security'],
            'target_level' => AutonomyLevel::Manual,
            'scope' => AutonomyScope::Global,
            'order' => 2,
            'is_enabled' => true,
        ]);
        EscalationRule::factory()->create([
            'name' => 'Large diff supervised',
            'condition' => ['type' => 'diff_size', 'operator' => '>=', 'value' => '500'],
            'target_level' => AutonomyLevel::Supervised,
            'scope' => AutonomyScope::Global,
            'order' => 3,
            'is_enabled' => true,
        ]);
        EscalationRule::factory()->create([
            'name' => 'Migrations require supervision',
            'condition' => ['type' => 'touched_directory_match', 'operator' => '~', 'value' => 'database/migrations'],
            'target_level' => AutonomyLevel::Supervised,
            'scope' => AutonomyScope::Global,
            'order' => 4,
            'is_enabled' => false,
        ]);

        // Issues — realistic titles/bodies across every status.
        $issueSpecs = [
            // Queued (incoming, awaiting triage)
            ['source' => $acme, 'external_id' => '9042', 'title' => 'Origin Sentry Event #9022 — high memory pressure on ws-cluster', 'labels' => ['bug', 'performance'], 'status' => IssueStatus::Queued, 'assignee' => null, 'age_hours' => 1],
            ['source' => $acme, 'external_id' => '9041', 'title' => 'Flaky test: SubscriptionRenewalTest::testGrandfatheredRate', 'labels' => ['flaky-test'], 'status' => IssueStatus::Queued, 'assignee' => null, 'age_hours' => 3],
            ['source' => $ingest, 'external_id' => '482', 'title' => 'Deduplication drops Jira ticket updates across pages', 'labels' => ['bug'], 'status' => IssueStatus::Queued, 'assignee' => null, 'age_hours' => 5],
            ['source' => $jira, 'external_id' => 'PAY-204', 'title' => 'Receipt PDFs render empty line item rows for voided refunds', 'labels' => ['bug'], 'status' => IssueStatus::Queued, 'assignee' => null, 'age_hours' => 9],
            ['source' => $jira, 'external_id' => 'PAY-198', 'title' => 'Stripe webhook signature mismatch after rotating keys', 'labels' => ['security'], 'status' => IssueStatus::Queued, 'assignee' => null, 'age_hours' => 18, 'auto_accepted' => false],

            // Auto-accepted (shows "auto" badge)
            ['source' => $acme, 'external_id' => '9039', 'title' => 'Bump @acme/logger to 2.4.1 — patches NullPointer on init', 'labels' => ['relay/auto', 'dependencies'], 'status' => IssueStatus::Accepted, 'assignee' => null, 'age_hours' => 2, 'auto_accepted' => true],

            // In-progress (need runs + stages)
            ['source' => $acme, 'external_id' => '9031', 'title' => 'Optimization of Memory-Leaking WebSocket Handlers', 'labels' => ['bug', 'performance'], 'status' => IssueStatus::InProgress, 'assignee' => 'relay-bot', 'age_hours' => 26, 'pipeline' => 'implement_running'],
            ['source' => $acme, 'external_id' => '9024', 'title' => 'Add cache-busting for asset manifest after deploy', 'labels' => ['enhancement'], 'status' => IssueStatus::InProgress, 'assignee' => 'relay-bot', 'age_hours' => 14, 'pipeline' => 'verify_running'],
            ['source' => $ingest, 'external_id' => '461', 'title' => 'Batch sync should respect GitHub secondary rate limits', 'labels' => ['bug'], 'status' => IssueStatus::InProgress, 'assignee' => 'relay-bot', 'age_hours' => 42, 'pipeline' => 'release_awaiting'],
            ['source' => $jira, 'external_id' => 'PAY-192', 'title' => 'Add Idempotency-Key support to charge creation endpoint', 'labels' => ['feature'], 'status' => IssueStatus::InProgress, 'assignee' => 'relay-bot', 'age_hours' => 7, 'pipeline' => 'preflight_clarifying'],

            // Stuck (each one exercises a different StuckState)
            ['source' => $acme, 'external_id' => '9018', 'title' => 'Bouncing test: ChartRenderer snapshots differ on ARM macs', 'labels' => ['bug', 'flaky-test'], 'status' => IssueStatus::Stuck, 'assignee' => 'relay-bot', 'age_hours' => 66, 'pipeline' => 'stuck_iteration'],
            ['source' => $ingest, 'external_id' => '438', 'title' => 'ingest.dev.acme.local DNS intermittently fails in CI', 'labels' => ['infrastructure'], 'status' => IssueStatus::Stuck, 'assignee' => 'relay-bot', 'age_hours' => 55, 'pipeline' => 'stuck_blocker'],
            ['source' => $jira, 'external_id' => 'PAY-179', 'title' => 'Reconcile Stripe balance transactions against ledger nightly', 'labels' => ['feature'], 'status' => IssueStatus::Stuck, 'assignee' => 'relay-bot', 'age_hours' => 120, 'pipeline' => 'stuck_uncertain'],
            ['source' => $acme, 'external_id' => '9007', 'title' => 'Implement agent timeout on wasm-bundling step', 'labels' => ['bug'], 'status' => IssueStatus::Stuck, 'assignee' => 'relay-bot', 'age_hours' => 33, 'pipeline' => 'stuck_timeout'],

            // Completed (shipped)
            ['source' => $acme, 'external_id' => '8991', 'title' => 'Migrate from moment → date-fns in billing widgets', 'labels' => ['refactor'], 'status' => IssueStatus::Completed, 'assignee' => 'relay-bot', 'age_hours' => 48, 'pipeline' => 'completed'],
            ['source' => $ingest, 'external_id' => '412', 'title' => 'Retry strategy for transient 502s on Jira search', 'labels' => ['enhancement'], 'status' => IssueStatus::Completed, 'assignee' => 'relay-bot', 'age_hours' => 96, 'pipeline' => 'completed'],

            // Failed
            ['source' => $acme, 'external_id' => '8984', 'title' => 'Regenerate typed GraphQL client from federated schema', 'labels' => ['infrastructure'], 'status' => IssueStatus::Failed, 'assignee' => 'relay-bot', 'age_hours' => 72, 'pipeline' => 'failed'],

            // Rejected
            ['source' => $jira, 'external_id' => 'PAY-174', 'title' => "Investigate memory regression in Ruby → Node migration spike", 'labels' => ['Spike'], 'status' => IssueStatus::Rejected, 'assignee' => null, 'age_hours' => 200],
        ];

        foreach ($issueSpecs as $idx => $spec) {
            $repository = match ($spec['source']->name) {
                'acme/platform' => $platformRepo,
                'acme/ingest' => $ingestRepo,
                default => null,
            };

            $createdAt = now()->subHours($spec['age_hours']);

            $issue = Issue::factory()->create([
                'source_id' => $spec['source']->id,
                'repository_id' => $repository?->id,
                'external_id' => $spec['external_id'],
                'title' => $spec['title'],
                'body' => $this->bodyFor($spec['title']),
                'status' => $spec['status'],
                'external_url' => $this->externalUrlFor($spec['source'], $spec['external_id']),
                'assignee' => $spec['assignee'],
                'labels' => $spec['labels'],
                'auto_accepted' => $spec['auto_accepted'] ?? false,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            if (isset($spec['pipeline'])) {
                $this->buildPipelineFor($issue, $spec['pipeline'], $createdAt);
            }
        }

        $this->command?->info('Demo data seeded: '.Issue::count().' issues, '.Run::count().' runs, '.Stage::count().' stages, '.StageEvent::count().' events.');
    }

    private function bodyFor(string $title): string
    {
        return "Reported automatically from the intake pipeline.\n\n".
            "Context:\n- Title: {$title}\n- Observed on: production\n- Severity: medium\n\n".
            "Next steps: triage, reproduce in a worktree, patch, verify.";
    }

    private function externalUrlFor(Source $source, string $externalId): string
    {
        return match ($source->type) {
            SourceType::GitHub => "https://github.com/{$source->name}/issues/{$externalId}",
            SourceType::Jira => "https://{$source->external_account}/browse/{$externalId}",
        };
    }

    private function buildPipelineFor(Issue $issue, string $shape, \Carbon\Carbon $issueCreatedAt): void
    {
        $runStart = $issueCreatedAt->copy()->addMinutes(2);

        $run = Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => match (true) {
                str_starts_with($shape, 'stuck_') => RunStatus::Stuck,
                $shape === 'completed' => RunStatus::Completed,
                $shape === 'failed' => RunStatus::Failed,
                default => RunStatus::Running,
            },
            'stuck_state' => match ($shape) {
                'stuck_iteration' => StuckState::IterationCap,
                'stuck_timeout' => StuckState::Timeout,
                'stuck_uncertain' => StuckState::AgentUncertain,
                'stuck_blocker' => StuckState::ExternalBlocker,
                default => null,
            },
            'guidance' => null,
            'stuck_unread' => str_starts_with($shape, 'stuck_'),
            'branch' => 'relay/'.$issue->external_id,
            'worktree_path' => '/tmp/relay-worktrees/'.$issue->external_id,
            'preflight_doc' => $shape === 'preflight_clarifying' ? null : $this->preflightDoc($issue),
            'iteration' => match ($shape) {
                'stuck_iteration' => 5,
                'stuck_timeout', 'stuck_uncertain', 'stuck_blocker' => 2,
                'release_awaiting', 'completed' => 1,
                default => 1,
            },
            'started_at' => $runStart,
            'completed_at' => in_array($shape, ['completed', 'failed']) ? $issueCreatedAt->copy()->addHours(2) : null,
            'created_at' => $runStart,
            'updated_at' => $runStart,
        ]);

        match ($shape) {
            'preflight_clarifying' => $this->stagesPreflightClarifying($run, $runStart),
            'implement_running' => $this->stagesImplementRunning($run, $runStart),
            'verify_running' => $this->stagesVerifyRunning($run, $runStart),
            'release_awaiting' => $this->stagesReleaseAwaiting($run, $runStart),
            'stuck_iteration' => $this->stagesStuckIteration($run, $runStart),
            'stuck_timeout' => $this->stagesStuckTimeout($run, $runStart),
            'stuck_uncertain' => $this->stagesStuckUncertain($run, $runStart),
            'stuck_blocker' => $this->stagesStuckBlocker($run, $runStart),
            'completed' => $this->stagesCompleted($run, $runStart),
            'failed' => $this->stagesFailed($run, $runStart),
        };
    }

    private function preflightDoc(Issue $issue): string
    {
        return <<<MD
# Preflight: {$issue->title}

## Summary
Triage and patch for {$issue->external_id}. Agent has gathered repo context and composed a targeted fix.

## Requirements
- Reproduce the failure locally against the current main.
- Patch must not regress coverage.

## Acceptance Criteria
1. Failing test added for the reported scenario.
2. All existing tests pass.
3. Lint passes.

## Affected Files
- `app/Services/...`
- `tests/Feature/...`

## Approach
Scope is narrow: bounded edit in the service layer and a unit-level test to lock the behaviour.

## Scope Assessment
- Size: S
- Risk: low
- Suggested autonomy: Supervised
MD;
    }

    private function stagesPreflightClarifying(Run $run, \Carbon\Carbon $start): void
    {
        $stage = $this->stage($run, StageName::Preflight, StageStatus::AwaitingApproval, 1, $start, null);
        $this->event($stage, 'started', 'preflight_agent', ['confidence' => 'low'], $start);
        $this->event($stage, 'clarification_requested', 'preflight_agent', ['questions' => 3], $start->copy()->addMinutes(1));
    }

    private function stagesImplementRunning(Run $run, \Carbon\Carbon $start): void
    {
        $pre = $this->stage($run, StageName::Preflight, StageStatus::Completed, 1, $start, $start->copy()->addMinutes(3));
        $this->event($pre, 'started', 'preflight_agent', [], $start);
        $this->event($pre, 'completed', 'preflight_agent', ['doc_sections' => 6], $start->copy()->addMinutes(3));
        $this->event($pre, 'approved', 'user', ['user' => 'Test User'], $start->copy()->addMinutes(4));

        $imp = $this->stage($run, StageName::Implement, StageStatus::Running, 1, $start->copy()->addMinutes(4), null);
        $this->event($imp, 'started', 'implement_agent', [], $start->copy()->addMinutes(4));
        $this->event($imp, 'tool_call', 'implement_agent', ['tool' => 'write_file', 'path' => 'src/websocket/handler.ts'], $start->copy()->addMinutes(6));
        $this->event($imp, 'tool_call', 'implement_agent', ['tool' => 'run_linter'], $start->copy()->addMinutes(9));
    }

    private function stagesVerifyRunning(Run $run, \Carbon\Carbon $start): void
    {
        $pre = $this->stage($run, StageName::Preflight, StageStatus::Completed, 1, $start, $start->copy()->addMinutes(2));
        $this->event($pre, 'completed', 'preflight_agent', [], $start->copy()->addMinutes(2));

        $imp = $this->stage($run, StageName::Implement, StageStatus::Completed, 1, $start->copy()->addMinutes(2), $start->copy()->addMinutes(11));
        $this->event($imp, 'completed', 'implement_agent', [
            'summary' => 'Patched handler cleanup on socket close; added missing removeListener calls.',
            'files_changed' => ['src/websocket/handler.ts', 'src/websocket/pool.ts', 'tests/websocket/handler.test.ts'],
            'lines_added' => 87,
            'lines_removed' => 14,
        ], $start->copy()->addMinutes(11));

        $ver = $this->stage($run, StageName::Verify, StageStatus::Running, 1, $start->copy()->addMinutes(11), null);
        $this->event($ver, 'started', 'verify_agent', [], $start->copy()->addMinutes(11));
        $this->event($ver, 'tool_call', 'verify_agent', ['tool' => 'run_tests', 'count' => 214], $start->copy()->addMinutes(13));
    }

    private function stagesReleaseAwaiting(Run $run, \Carbon\Carbon $start): void
    {
        $pre = $this->stage($run, StageName::Preflight, StageStatus::Completed, 1, $start, $start->copy()->addMinutes(2));
        $this->event($pre, 'completed', 'preflight_agent', [], $start->copy()->addMinutes(2));

        $imp = $this->stage($run, StageName::Implement, StageStatus::Completed, 1, $start->copy()->addMinutes(2), $start->copy()->addMinutes(8));
        $this->event($imp, 'completed', 'implement_agent', [
            'summary' => 'Honour X-RateLimit-Remaining and stagger batch requests.',
            'files_changed' => ['app/Services/Github/BatchSyncer.php', 'tests/Services/Github/BatchSyncerTest.php'],
            'lines_added' => 62,
            'lines_removed' => 21,
        ], $start->copy()->addMinutes(8));

        $ver = $this->stage($run, StageName::Verify, StageStatus::Completed, 1, $start->copy()->addMinutes(8), $start->copy()->addMinutes(13));
        $this->event($ver, 'completed', 'verify_agent', ['tests' => '214 passed'], $start->copy()->addMinutes(13));

        $rel = $this->stage($run, StageName::Release, StageStatus::AwaitingApproval, 1, $start->copy()->addMinutes(13), null);
        $this->event($rel, 'approval_requested', 'release_agent', ['reason' => 'stage override: manual'], $start->copy()->addMinutes(14));
    }

    private function stagesStuckIteration(Run $run, \Carbon\Carbon $start): void
    {
        $pre = $this->stage($run, StageName::Preflight, StageStatus::Completed, 1, $start, $start->copy()->addMinutes(2));
        $this->event($pre, 'completed', 'preflight_agent', [], $start->copy()->addMinutes(2));

        for ($i = 1; $i <= 5; $i++) {
            $impStart = $start->copy()->addMinutes(2 + ($i - 1) * 20);
            $impEnd = $impStart->copy()->addMinutes(8);
            $verStart = $impEnd;
            $verEnd = $verStart->copy()->addMinutes(3);

            $imp = $this->stage($run, StageName::Implement, StageStatus::Completed, $i, $impStart, $impEnd);
            $this->event($imp, 'started', 'implement_agent', ['iteration' => $i], $impStart);
            $this->event($imp, 'completed', 'implement_agent', ['iteration' => $i], $impEnd);

            $ver = $this->stage($run, StageName::Verify, $i === 5 ? StageStatus::Stuck : StageStatus::Bounced, $i, $verStart, $verEnd);
            $this->event($ver, 'started', 'verify_agent', ['iteration' => $i], $verStart);
            if ($i < 5) {
                $this->event($ver, 'bounced', 'verify_agent', [
                    'iteration' => $i,
                    'failed_test' => 'ChartRenderer::renders_identical_snapshot',
                    'assertion' => 'image diff 0.7% exceeds 0.5% threshold',
                ], $verEnd);
            } else {
                $this->event($ver, 'stuck', 'verify_agent', ['iteration' => 5, 'reason' => 'iteration cap reached'], $verEnd);
            }
        }
    }

    private function stagesStuckTimeout(Run $run, \Carbon\Carbon $start): void
    {
        $pre = $this->stage($run, StageName::Preflight, StageStatus::Completed, 1, $start, $start->copy()->addMinutes(3));
        $this->event($pre, 'completed', 'preflight_agent', [], $start->copy()->addMinutes(3));

        $imp = $this->stage($run, StageName::Implement, StageStatus::Stuck, 1, $start->copy()->addMinutes(3), null);
        $this->event($imp, 'started', 'implement_agent', [], $start->copy()->addMinutes(3));
        $this->event($imp, 'stuck', 'implement_agent', ['reason' => 'no progress for 30 minutes during wasm-bundling'], $start->copy()->addMinutes(33));
    }

    private function stagesStuckUncertain(Run $run, \Carbon\Carbon $start): void
    {
        $pre = $this->stage($run, StageName::Preflight, StageStatus::Completed, 1, $start, $start->copy()->addMinutes(2));
        $this->event($pre, 'completed', 'preflight_agent', [], $start->copy()->addMinutes(2));

        $imp = $this->stage($run, StageName::Implement, StageStatus::Stuck, 1, $start->copy()->addMinutes(2), null);
        $this->event($imp, 'started', 'implement_agent', [], $start->copy()->addMinutes(2));
        $this->event($imp, 'stuck', 'implement_agent', [
            'reason' => 'conflicting requirements in preflight doc',
            'confidence' => 0.23,
            'note' => 'Doc says hourly cadence; business rule cited states daily.',
        ], $start->copy()->addMinutes(11));
    }

    private function stagesStuckBlocker(Run $run, \Carbon\Carbon $start): void
    {
        $pre = $this->stage($run, StageName::Preflight, StageStatus::Completed, 1, $start, $start->copy()->addMinutes(2));
        $this->event($pre, 'completed', 'preflight_agent', [], $start->copy()->addMinutes(2));

        $imp = $this->stage($run, StageName::Implement, StageStatus::Stuck, 1, $start->copy()->addMinutes(2), null);
        $this->event($imp, 'stuck', 'implement_agent', [
            'reason' => 'ingest.dev.acme.local resolves intermittently in CI',
            'external' => true,
        ], $start->copy()->addMinutes(4));
    }

    private function stagesCompleted(Run $run, \Carbon\Carbon $start): void
    {
        $pre = $this->stage($run, StageName::Preflight, StageStatus::Completed, 1, $start, $start->copy()->addMinutes(2));
        $this->event($pre, 'completed', 'preflight_agent', [], $start->copy()->addMinutes(2));

        $imp = $this->stage($run, StageName::Implement, StageStatus::Completed, 1, $start->copy()->addMinutes(2), $start->copy()->addMinutes(9));
        $this->event($imp, 'completed', 'implement_agent', [
            'summary' => 'Swapped moment helpers for date-fns across billing widgets.',
            'files_changed' => ['src/widgets/InvoiceSummary.tsx', 'src/widgets/RenewalBanner.tsx', 'src/lib/dates.ts', 'tests/widgets/InvoiceSummary.test.tsx'],
            'lines_added' => 120,
            'lines_removed' => 40,
        ], $start->copy()->addMinutes(9));

        $ver = $this->stage($run, StageName::Verify, StageStatus::Completed, 1, $start->copy()->addMinutes(9), $start->copy()->addMinutes(14));
        $this->event($ver, 'completed', 'verify_agent', ['tests' => '301 passed', 'coverage_delta' => '+0.4%'], $start->copy()->addMinutes(14));

        $rel = $this->stage($run, StageName::Release, StageStatus::Completed, 1, $start->copy()->addMinutes(14), $start->copy()->addMinutes(18));
        $this->event($rel, 'completed', 'release_agent', [
            'pr_url' => 'https://github.com/'.$run->issue->source->name.'/pull/'.rand(1200, 1300),
            'changelog_entry' => 'Fix: '.$run->issue->title,
        ], $start->copy()->addMinutes(18));
    }

    private function stagesFailed(Run $run, \Carbon\Carbon $start): void
    {
        $pre = $this->stage($run, StageName::Preflight, StageStatus::Completed, 1, $start, $start->copy()->addMinutes(2));
        $this->event($pre, 'completed', 'preflight_agent', [], $start->copy()->addMinutes(2));

        $imp = $this->stage($run, StageName::Implement, StageStatus::Failed, 1, $start->copy()->addMinutes(2), $start->copy()->addMinutes(17));
        $this->event($imp, 'failed', 'implement_agent', [
            'reason' => 'federated schema introspection unreachable',
            'last_tool' => 'run_shell',
        ], $start->copy()->addMinutes(17));
    }

    private function stage(Run $run, StageName $name, StageStatus $status, int $iteration, \Carbon\Carbon $startedAt, ?\Carbon\Carbon $completedAt): Stage
    {
        return Stage::factory()->create([
            'run_id' => $run->id,
            'name' => $name,
            'status' => $status,
            'iteration' => $iteration,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'created_at' => $startedAt,
            'updated_at' => $completedAt ?? $startedAt,
        ]);
    }

    private function event(Stage $stage, string $type, string $actor, array $payload, \Carbon\Carbon $at): void
    {
        StageEvent::factory()->create([
            'stage_id' => $stage->id,
            'type' => $type,
            'actor' => $actor,
            'payload' => $payload,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
