<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\SourceType;
use App\Events\IntakeQueueChanged;
use App\Events\SourceSynced;
use App\Jobs\SyncSourceIssuesJob;
use App\Models\Issue;
use App\Models\OauthToken;
use App\Models\Source;
use App\Services\IssueIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IntakeBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function createGitHubSource(): Source
    {
        return Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'testuser',
            'is_active' => true,
            'config' => ['repositories' => ['owner/repo']],
        ]);
    }

    private function createToken(Source $source): void
    {
        OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => $source->type->value,
            'access_token' => 'test-token',
            'expires_at' => now()->addHour(),
        ]);
    }

    public function test_sync_job_broadcasts_source_synced_on_success(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();
        $this->createToken($source);

        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::response([]),
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        Event::assertDispatched(SourceSynced::class, function (SourceSynced $event) use ($source) {
            return $event->sourceId === $source->id
                && $event->success === true
                && $event->errorMessage === null;
        });
    }

    public function test_sync_job_broadcasts_source_synced_on_error(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();
        // No token — triggers recordError path.

        SyncSourceIssuesJob::dispatchSync($source);

        Event::assertDispatched(SourceSynced::class, function (SourceSynced $event) use ($source) {
            return $event->sourceId === $source->id
                && $event->success === false
                && $event->errorMessage !== null;
        });
    }

    public function test_sync_job_broadcasts_source_synced_on_api_failure(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();
        $this->createToken($source);

        Http::fake([
            'api.github.com/*' => Http::response('Server error', 500),
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        Event::assertDispatched(SourceSynced::class, function (SourceSynced $event) use ($source) {
            return $event->sourceId === $source->id
                && $event->success === false
                && $event->errorMessage !== null;
        });
    }

    public function test_upsert_issue_broadcasts_intake_queue_changed_for_new_issue(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();

        /** @var IssueIntakeService $service */
        $service = app(IssueIntakeService::class);
        $service->upsertIssue($source, [
            'external_id' => 'owner/repo#42',
            'title' => 'New issue',
            'body' => 'Body text',
            'external_url' => 'https://github.com/owner/repo/issues/42',
            'assignee' => null,
            'labels' => [],
        ]);

        Event::assertDispatched(IntakeQueueChanged::class, function (IntakeQueueChanged $event) use ($source) {
            return $event->sourceId === $source->id && $event->action === 'upsert';
        });
    }

    public function test_upsert_issue_broadcasts_intake_queue_changed_when_existing_issue_changes(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Old title',
        ]);

        /** @var IssueIntakeService $service */
        $service = app(IssueIntakeService::class);
        $service->upsertIssue($source, [
            'external_id' => 'owner/repo#1',
            'title' => 'Updated title',
            'body' => null,
            'external_url' => null,
            'assignee' => null,
            'labels' => [],
        ]);

        Event::assertDispatched(IntakeQueueChanged::class, function (IntakeQueueChanged $event) use ($source) {
            return $event->sourceId === $source->id && $event->action === 'upsert';
        });
    }

    public function test_upsert_issue_does_not_broadcast_when_no_changes(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Same title',
            'body' => null,
            'external_url' => null,
            'assignee' => null,
            'labels' => [],
        ]);

        /** @var IssueIntakeService $service */
        $service = app(IssueIntakeService::class);
        $service->upsertIssue($source, [
            'external_id' => 'owner/repo#1',
            'title' => 'Same title',
            'body' => null,
            'external_url' => null,
            'assignee' => null,
            'labels' => [],
        ]);

        Event::assertNotDispatched(IntakeQueueChanged::class);
    }

    public function test_mark_closed_broadcasts_intake_queue_changed(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Open issue',
            'status' => IssueStatus::Queued,
        ]);

        /** @var IssueIntakeService $service */
        $service = app(IssueIntakeService::class);
        $service->markClosed($source, 'owner/repo#1');

        Event::assertDispatched(IntakeQueueChanged::class, function (IntakeQueueChanged $event) use ($source) {
            return $event->sourceId === $source->id && $event->action === 'close';
        });
    }

    public function test_mark_closed_does_not_broadcast_when_issue_not_found(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();

        /** @var IssueIntakeService $service */
        $service = app(IssueIntakeService::class);
        $service->markClosed($source, 'owner/repo#999');

        Event::assertNotDispatched(IntakeQueueChanged::class);
    }

    public function test_mark_reopened_broadcasts_intake_queue_changed(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Closed issue',
            'status' => IssueStatus::Rejected,
            'raw_status' => 'closed:completed',
        ]);

        /** @var IssueIntakeService $service */
        $service = app(IssueIntakeService::class);
        $service->markReopened($source, 'owner/repo#1');

        Event::assertDispatched(IntakeQueueChanged::class, function (IntakeQueueChanged $event) use ($source) {
            return $event->sourceId === $source->id && $event->action === 'reopen';
        });
    }

    public function test_mark_reopened_does_not_broadcast_when_no_state_change(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();

        // Rejected due to local action, not a sync-driven close — should not reopen.
        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Manually rejected',
            'status' => IssueStatus::Rejected,
            'raw_status' => null,
        ]);

        /** @var IssueIntakeService $service */
        $service = app(IssueIntakeService::class);
        $service->markReopened($source, 'owner/repo#1');

        Event::assertNotDispatched(IntakeQueueChanged::class);
    }

    public function test_mark_deleted_broadcasts_intake_queue_changed(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Existing issue',
            'status' => IssueStatus::Queued,
        ]);

        /** @var IssueIntakeService $service */
        $service = app(IssueIntakeService::class);
        $service->markDeleted($source, 'owner/repo#1');

        Event::assertDispatched(IntakeQueueChanged::class, function (IntakeQueueChanged $event) use ($source) {
            return $event->sourceId === $source->id && $event->action === 'delete';
        });
    }

    public function test_mark_deleted_does_not_broadcast_when_issue_not_found(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();

        /** @var IssueIntakeService $service */
        $service = app(IssueIntakeService::class);
        $service->markDeleted($source, 'owner/repo#999');

        Event::assertNotDispatched(IntakeQueueChanged::class);
    }

    public function test_sync_job_broadcasts_intake_queue_changed_for_each_new_issue(): void
    {
        Event::fake([SourceSynced::class, IntakeQueueChanged::class]);

        $source = $this->createGitHubSource();
        $this->createToken($source);

        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::response([
                [
                    'number' => 1,
                    'title' => 'First issue',
                    'body' => '',
                    'html_url' => 'https://github.com/owner/repo/issues/1',
                    'assignee' => null,
                    'labels' => [],
                ],
                [
                    'number' => 2,
                    'title' => 'Second issue',
                    'body' => '',
                    'html_url' => 'https://github.com/owner/repo/issues/2',
                    'assignee' => null,
                    'labels' => [],
                ],
            ]),
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        Event::assertDispatchedTimes(IntakeQueueChanged::class, 2);
        Event::assertDispatched(SourceSynced::class, function (SourceSynced $event) use ($source) {
            return $event->sourceId === $source->id && $event->success === true;
        });
    }
}
