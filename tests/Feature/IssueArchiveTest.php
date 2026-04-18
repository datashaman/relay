<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\SourceType;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Source;
use App\Services\IssueIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IssueArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function createSource(array $overrides = []): Source
    {
        return Source::factory()->create(array_merge([
            'type' => SourceType::GitHub,
            'is_active' => true,
            'external_account' => 'testuser',
        ], $overrides));
    }

    private function createIssue(Source $source, array $overrides = []): Issue
    {
        return Issue::factory()->create(array_merge([
            'source_id' => $source->id,
            'status' => IssueStatus::Queued,
        ], $overrides));
    }

    // ──────────────────────────────────────────────────────────────
    // Model scope + helper tests
    // ──────────────────────────────────────────────────────────────

    public function test_scope_active_excludes_archived_issues(): void
    {
        $source = $this->createSource();
        $active = $this->createIssue($source, ['title' => 'Active issue']);
        $archived = $this->createIssue($source, ['title' => 'Archived issue']);
        $archived->archive();

        $results = Issue::active()->get();

        $this->assertTrue($results->contains($active));
        $this->assertFalse($results->contains($archived));
    }

    public function test_scope_archived_returns_only_archived_issues(): void
    {
        $source = $this->createSource();
        $active = $this->createIssue($source, ['title' => 'Active issue']);
        $archived = $this->createIssue($source, ['title' => 'Archived issue']);
        $archived->archive();

        $results = Issue::archived()->get();

        $this->assertFalse($results->contains($active));
        $this->assertTrue($results->contains($archived));
    }

    public function test_archive_helper_sets_archived_at_and_reason(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source);

        $issue->archive('Too old to matter');

        $issue->refresh();
        $this->assertNotNull($issue->archived_at);
        $this->assertEquals('Too old to matter', $issue->archived_reason);
    }

    public function test_archive_helper_without_reason(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source);

        $issue->archive();

        $issue->refresh();
        $this->assertNotNull($issue->archived_at);
        $this->assertNull($issue->archived_reason);
    }

    public function test_unarchive_helper_clears_archive_fields(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source);
        $issue->archive('Some reason');

        $issue->unarchive();

        $issue->refresh();
        $this->assertNull($issue->archived_at);
        $this->assertNull($issue->archived_reason);
    }

    // ──────────────────────────────────────────────────────────────
    // Factory state tests
    // ──────────────────────────────────────────────────────────────

    public function test_factory_archived_state(): void
    {
        $source = $this->createSource();
        $issue = Issue::factory()->archived('factory reason')->create(['source_id' => $source->id]);

        $this->assertNotNull($issue->archived_at);
        $this->assertEquals('factory reason', $issue->archived_reason);
    }

    // ──────────────────────────────────────────────────────────────
    // Intake queue: active() scope applied by default
    // ──────────────────────────────────────────────────────────────

    public function test_intake_queue_does_not_show_archived_issues(): void
    {
        $source = $this->createSource();
        $this->createIssue($source, ['title' => 'Visible issue']);
        $archived = $this->createIssue($source, ['title' => 'Archived issue']);
        $archived->archive();

        $response = $this->get('/intake');

        $response->assertSee('Visible issue');
        $response->assertDontSee('Archived issue');
    }

    public function test_pending_count_excludes_archived_issues(): void
    {
        $source = $this->createSource();
        $this->createIssue($source);
        $archived = $this->createIssue($source);
        $archived->archive();

        Livewire::test('pages::intake')
            ->assertSee('Pending 01');
    }

    public function test_show_archived_toggle_reveals_archived_issues(): void
    {
        $source = $this->createSource();
        $this->createIssue($source, ['title' => 'Active issue']);
        $archived = $this->createIssue($source, ['title' => 'My archived issue']);
        $archived->archive('Test reason');

        Livewire::test('pages::intake')
            ->assertDontSee('My archived issue')
            ->set('showArchived', true)
            ->assertSee('My archived issue')
            ->assertSee('Test reason');
    }

    // ──────────────────────────────────────────────────────────────
    // Archive action (Livewire)
    // ──────────────────────────────────────────────────────────────

    public function test_archive_issue_via_livewire(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source, ['title' => 'Archive me']);

        Livewire::test('pages::intake')
            ->call('archiveIssue', $issue->id, 'no longer relevant');

        $issue->refresh();
        $this->assertNotNull($issue->archived_at);
        $this->assertEquals('no longer relevant', $issue->archived_reason);
    }

    public function test_archive_issue_without_reason(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source);

        Livewire::test('pages::intake')
            ->call('archiveIssue', $issue->id, '');

        $issue->refresh();
        $this->assertNotNull($issue->archived_at);
        $this->assertNull($issue->archived_reason);
    }

    public function test_archive_blocked_when_run_is_in_flight(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source, ['status' => IssueStatus::InProgress]);
        Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Running,
        ]);

        Livewire::test('pages::intake')
            ->call('archiveIssue', $issue->id);

        // The issue must NOT be archived — in-flight run blocks the action.
        $issue->refresh();
        $this->assertNull($issue->archived_at);
    }

    public function test_archive_allowed_when_run_is_completed(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source, ['status' => IssueStatus::Completed]);
        Run::factory()->create([
            'issue_id' => $issue->id,
            'status' => RunStatus::Completed,
        ]);

        Livewire::test('pages::intake')
            ->call('archiveIssue', $issue->id);

        $issue->refresh();
        $this->assertNotNull($issue->archived_at);
    }

    // ──────────────────────────────────────────────────────────────
    // Unarchive action (Livewire)
    // ──────────────────────────────────────────────────────────────

    public function test_unarchive_issue_via_livewire(): void
    {
        $source = $this->createSource();
        $issue = $this->createIssue($source, ['title' => 'Unarchive me']);
        $issue->archive('old reason');

        Livewire::test('pages::intake')
            ->call('unarchiveIssue', $issue->id);

        $issue->refresh();
        $this->assertNull($issue->archived_at);
        $this->assertNull($issue->archived_reason);
    }

    // ──────────────────────────────────────────────────────────────
    // Auto-unarchive on sync: upsertIssue
    // ──────────────────────────────────────────────────────────────

    public function test_upsert_issue_unarchives_existing_archived_row(): void
    {
        $source = $this->createSource();
        $issue = Issue::factory()->archived('stale')->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#99',
            'title' => 'Old title',
        ]);

        $service = app(IssueIntakeService::class);
        $service->upsertIssue($source, [
            'external_id' => 'owner/repo#99',
            'title' => 'Updated title',
            'body' => 'body',
            'external_url' => 'https://example.com',
            'assignee' => null,
            'labels' => [],
            'raw_status' => null,
        ]);

        $issue->refresh();
        $this->assertNull($issue->archived_at);
        $this->assertNull($issue->archived_reason);
        $this->assertEquals('Updated title', $issue->title);
    }

    // ──────────────────────────────────────────────────────────────
    // Auto-unarchive on sync: markClosed
    // ──────────────────────────────────────────────────────────────

    public function test_mark_closed_unarchives_existing_archived_row(): void
    {
        $source = $this->createSource();
        $issue = Issue::factory()->archived()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#10',
            'status' => IssueStatus::Queued,
        ]);

        $service = app(IssueIntakeService::class);
        $service->markClosed($source, 'owner/repo#10', 'completed');

        $issue->refresh();
        $this->assertNull($issue->archived_at);
        $this->assertEquals('closed:completed', $issue->raw_status);
    }

    // ──────────────────────────────────────────────────────────────
    // Auto-unarchive on sync: markReopened
    // ──────────────────────────────────────────────────────────────

    public function test_mark_reopened_unarchives_existing_archived_row(): void
    {
        $source = $this->createSource();
        $issue = Issue::factory()->archived()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#11',
            'status' => IssueStatus::Rejected,
            'raw_status' => 'closed:completed',
        ]);

        $service = app(IssueIntakeService::class);
        $service->markReopened($source, 'owner/repo#11');

        $issue->refresh();
        $this->assertNull($issue->archived_at);
        $this->assertEquals(IssueStatus::Queued, $issue->status);
    }

    // ──────────────────────────────────────────────────────────────
    // Auto-unarchive on sync: markDeleted
    // ──────────────────────────────────────────────────────────────

    public function test_mark_deleted_unarchives_existing_archived_row(): void
    {
        $source = $this->createSource();
        $issue = Issue::factory()->archived()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#12',
            'status' => IssueStatus::Queued,
        ]);

        $service = app(IssueIntakeService::class);
        $service->markDeleted($source, 'owner/repo#12');

        $issue->refresh();
        $this->assertNull($issue->archived_at);
        $this->assertEquals(IssueStatus::Rejected, $issue->status);
        $this->assertEquals('deleted', $issue->raw_status);
    }
}
