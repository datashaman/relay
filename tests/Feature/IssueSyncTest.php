<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Enums\SourceType;
use App\Jobs\SyncSourceIssuesJob;
use App\Models\Component;
use App\Models\FilterRule;
use App\Models\Issue;
use App\Models\OauthToken;
use App\Models\Source;
use App\Services\GitHubClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IssueSyncTest extends TestCase
{
    use RefreshDatabase;

    private function createGitHubSource(array $config = []): Source
    {
        return Source::factory()->create([
            'type' => SourceType::GitHub,
            'external_account' => 'testuser',
            'is_active' => true,
            'config' => array_merge(['repositories' => ['owner/repo']], $config),
        ]);
    }

    private function createJiraSource(): Source
    {
        return Source::factory()->create([
            'type' => SourceType::Jira,
            'external_account' => 'jira-user',
            'is_active' => true,
            'config' => ['cloud_id' => 'test-cloud-id'],
        ]);
    }

    private function createToken(Source $source): OauthToken
    {
        return OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => $source->type->value,
            'access_token' => 'test-token',
            'expires_at' => now()->addHour(),
        ]);
    }

    private function fakeGitHubIssues(array $issues = []): void
    {
        if (empty($issues)) {
            $issues = [
                [
                    'number' => 1,
                    'title' => 'Bug report',
                    'body' => 'Something is broken',
                    'html_url' => 'https://github.com/owner/repo/issues/1',
                    'assignee' => ['login' => 'dev1'],
                    'labels' => [['name' => 'bug']],
                ],
                [
                    'number' => 2,
                    'title' => 'Feature request',
                    'body' => 'Add dark mode',
                    'html_url' => 'https://github.com/owner/repo/issues/2',
                    'assignee' => null,
                    'labels' => [['name' => 'enhancement']],
                ],
            ];
        }

        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::response($issues),
        ]);
    }

    private function fakeJiraIssues(array $issues = []): void
    {
        if (empty($issues)) {
            $issues = [
                [
                    'id' => '10001',
                    'key' => 'TEST-1',
                    'self' => 'https://jira.example.com/issue/10001',
                    'fields' => [
                        'summary' => 'Jira bug',
                        'description' => null,
                        'assignee' => ['displayName' => 'Jane'],
                        'labels' => ['backend'],
                        'status' => ['name' => 'To Do'],
                    ],
                ],
            ];
        }

        Http::fake([
            'api.atlassian.com/ex/jira/test-cloud-id/rest/api/3/search/jql*' => Http::response([
                'issues' => $issues,
                'isLast' => true,
            ]),
        ]);
    }

    public function test_github_sync_creates_new_issues(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);
        $this->fakeGitHubIssues();

        SyncSourceIssuesJob::dispatchSync($source);

        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseHas('issues', [
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Bug report',
            'assignee' => 'dev1',
        ]);
        $this->assertDatabaseHas('issues', [
            'source_id' => $source->id,
            'external_id' => 'owner/repo#2',
            'title' => 'Feature request',
        ]);
    }

    public function test_github_sync_filters_pull_requests(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        $issues = [
            [
                'number' => 1,
                'title' => 'Real issue',
                'body' => 'A real issue',
                'html_url' => 'https://github.com/owner/repo/issues/1',
                'assignee' => null,
                'labels' => [],
            ],
            [
                'number' => 2,
                'title' => 'A pull request',
                'body' => 'PR body',
                'html_url' => 'https://github.com/owner/repo/pull/2',
                'assignee' => null,
                'labels' => [],
                'pull_request' => ['url' => 'https://api.github.com/repos/owner/repo/pulls/2'],
            ],
        ];

        $this->fakeGitHubIssues($issues);
        SyncSourceIssuesJob::dispatchSync($source);

        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseHas('issues', ['title' => 'Real issue']);
        $this->assertDatabaseMissing('issues', ['title' => 'A pull request']);
    }

    public function test_github_sync_deduplicates_by_external_id(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);
        $this->fakeGitHubIssues();

        SyncSourceIssuesJob::dispatchSync($source);
        $this->assertDatabaseCount('issues', 2);

        SyncSourceIssuesJob::dispatchSync($source->fresh());
        $this->assertDatabaseCount('issues', 2);
    }

    public function test_github_sync_updates_existing_issues_on_change(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Old title',
            'body' => 'Old body',
            'status' => IssueStatus::Queued,
        ]);

        $this->fakeGitHubIssues([
            [
                'number' => 1,
                'title' => 'Updated title',
                'body' => 'Updated body',
                'html_url' => 'https://github.com/owner/repo/issues/1',
                'assignee' => ['login' => 'dev1'],
                'labels' => [['name' => 'bug']],
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $issue = Issue::where('source_id', $source->id)->where('external_id', 'owner/repo#1')->first();
        $this->assertEquals('Updated title', $issue->title);
        $this->assertEquals('Updated body', $issue->body);
        $this->assertEquals('dev1', $issue->assignee);
    }

    public function test_sync_does_not_overwrite_progressed_status(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Bug report',
            'status' => IssueStatus::Accepted,
        ]);

        $this->fakeGitHubIssues([
            [
                'number' => 1,
                'title' => 'Bug report updated',
                'body' => 'New body',
                'html_url' => 'https://github.com/owner/repo/issues/1',
                'assignee' => null,
                'labels' => [],
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $issue = Issue::where('source_id', $source->id)->where('external_id', 'owner/repo#1')->first();
        $this->assertEquals(IssueStatus::Accepted, $issue->status);
        $this->assertEquals('Bug report updated', $issue->title);
    }

    public function test_github_sync_rejects_queued_issues_closed_upstream(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'Will be closed',
            'status' => IssueStatus::Queued,
        ]);

        $this->fakeGitHubIssues([
            [
                'number' => 1,
                'title' => 'Will be closed',
                'body' => '',
                'html_url' => 'https://github.com/owner/repo/issues/1',
                'assignee' => null,
                'labels' => [],
                'state' => 'closed',
                'state_reason' => 'completed',
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $issue = Issue::where('source_id', $source->id)->where('external_id', 'owner/repo#1')->first();
        $this->assertEquals(IssueStatus::Rejected, $issue->status);
        $this->assertEquals('closed:completed', $issue->raw_status);
    }

    public function test_github_sync_preserves_accepted_when_closed_upstream(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'owner/repo#1',
            'title' => 'In flight',
            'status' => IssueStatus::Accepted,
        ]);

        $this->fakeGitHubIssues([
            [
                'number' => 1,
                'title' => 'In flight',
                'body' => '',
                'html_url' => 'https://github.com/owner/repo/issues/1',
                'assignee' => null,
                'labels' => [],
                'state' => 'closed',
                'state_reason' => 'completed',
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $issue = Issue::where('source_id', $source->id)->where('external_id', 'owner/repo#1')->first();
        $this->assertEquals(IssueStatus::Accepted, $issue->status, 'local pipeline state must win over upstream close');
        $this->assertEquals('closed:completed', $issue->raw_status);
    }

    public function test_jira_sync_rejects_queued_issues_closed_upstream(): void
    {
        $source = $this->createJiraSource();
        $this->createToken($source);

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'Will be closed',
            'status' => IssueStatus::Queued,
        ]);

        $this->fakeJiraIssues([
            [
                'id' => '10001',
                'key' => 'TEST-1',
                'self' => 'https://jira.example.com/issue/10001',
                'fields' => [
                    'summary' => 'Will be closed',
                    'description' => null,
                    'assignee' => null,
                    'labels' => [],
                    'status' => ['name' => 'Done'],
                ],
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $issue = Issue::where('source_id', $source->id)->where('external_id', 'TEST-1')->first();
        $this->assertEquals(IssueStatus::Rejected, $issue->status);
        $this->assertEquals('closed:Done', $issue->raw_status);
    }

    public function test_jira_sync_preserves_accepted_when_closed_upstream(): void
    {
        $source = $this->createJiraSource();
        $this->createToken($source);

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'In flight',
            'status' => IssueStatus::Accepted,
        ]);

        $this->fakeJiraIssues([
            [
                'id' => '10001',
                'key' => 'TEST-1',
                'self' => 'https://jira.example.com/issue/10001',
                'fields' => [
                    'summary' => 'In flight',
                    'description' => null,
                    'assignee' => null,
                    'labels' => [],
                    'status' => ['name' => 'Done'],
                ],
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $issue = Issue::where('source_id', $source->id)->where('external_id', 'TEST-1')->first();
        $this->assertEquals(IssueStatus::Accepted, $issue->status, 'local pipeline state must win over upstream close');
        $this->assertEquals('closed:Done', $issue->raw_status);
    }

    public function test_jira_sync_reopens_sync_rejected_issue(): void
    {
        $source = $this->createJiraSource();
        $this->createToken($source);

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'Previously closed',
            'status' => IssueStatus::Rejected,
            'raw_status' => 'closed:Done',
        ]);

        $this->fakeJiraIssues([
            [
                'id' => '10001',
                'key' => 'TEST-1',
                'self' => 'https://jira.example.com/issue/10001',
                'fields' => [
                    'summary' => 'Previously closed',
                    'description' => null,
                    'assignee' => null,
                    'labels' => [],
                    'status' => ['name' => 'To Do'],
                ],
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $issue = Issue::where('source_id', $source->id)->where('external_id', 'TEST-1')->first();
        $this->assertEquals(IssueStatus::Queued, $issue->status);
        // upsertIssue runs after markReopened, so raw_status reflects the current Jira status
        $this->assertEquals('To Do', $issue->raw_status);
    }

    public function test_jira_sync_does_not_reopen_user_rejected_issue(): void
    {
        $source = $this->createJiraSource();
        $this->createToken($source);

        Issue::factory()->create([
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'User rejected',
            'status' => IssueStatus::Rejected,
            'raw_status' => null,
        ]);

        $this->fakeJiraIssues([
            [
                'id' => '10001',
                'key' => 'TEST-1',
                'self' => 'https://jira.example.com/issue/10001',
                'fields' => [
                    'summary' => 'User rejected',
                    'description' => null,
                    'assignee' => null,
                    'labels' => [],
                    'status' => ['name' => 'To Do'],
                ],
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $issue = Issue::where('source_id', $source->id)->where('external_id', 'TEST-1')->first();
        $this->assertEquals(IssueStatus::Rejected, $issue->status, 'user-driven rejection must not resurrect on upstream reopen');
    }

    public function test_jira_sync_creates_issues(): void
    {
        $source = $this->createJiraSource();
        $this->createToken($source);
        $this->fakeJiraIssues();

        SyncSourceIssuesJob::dispatchSync($source);

        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseHas('issues', [
            'source_id' => $source->id,
            'external_id' => 'TEST-1',
            'title' => 'Jira bug',
            'assignee' => 'Jane',
            'raw_status' => 'To Do',
        ]);
    }

    public function test_jira_sync_creates_component_and_links_issue(): void
    {
        $source = $this->createJiraSource();
        $this->createToken($source);
        $this->fakeJiraIssues([
            [
                'id' => '10001',
                'key' => 'CDE-42',
                'self' => 'https://jira.example.com/issue/10001',
                'fields' => [
                    'summary' => 'Cross-repo ticket',
                    'description' => null,
                    'assignee' => null,
                    'labels' => [],
                    'status' => ['name' => 'To Do'],
                    'components' => [
                        ['id' => '500', 'name' => 'yuvee'],
                    ],
                ],
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $this->assertDatabaseHas('components', [
            'source_id' => $source->id,
            'external_id' => '500',
            'name' => 'yuvee',
        ]);

        $issue = Issue::where('source_id', $source->id)->where('external_id', 'CDE-42')->first();
        $this->assertNotNull($issue->component_id);
        $this->assertSame('yuvee', $issue->component->name);
        $this->assertNull($issue->repository_id);
    }

    public function test_jira_sync_reuses_existing_component_and_updates_name(): void
    {
        $source = $this->createJiraSource();
        $this->createToken($source);

        $existing = Component::create([
            'source_id' => $source->id,
            'external_id' => '500',
            'name' => 'old-name',
        ]);

        $this->fakeJiraIssues([
            [
                'id' => '10001',
                'key' => 'CDE-42',
                'self' => 'https://jira.example.com/issue/10001',
                'fields' => [
                    'summary' => 'x',
                    'description' => null,
                    'assignee' => null,
                    'labels' => [],
                    'status' => ['name' => 'To Do'],
                    'components' => [['id' => '500', 'name' => 'yuvee']],
                ],
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $this->assertSame(1, Component::where('source_id', $source->id)->count());
        $this->assertSame('yuvee', $existing->fresh()->name);
    }

    public function test_jira_sync_jql_includes_project_and_filter_clauses(): void
    {
        $source = Source::factory()->create([
            'type' => SourceType::Jira,
            'external_account' => 'jira-user',
            'is_active' => true,
            'config' => [
                'cloud_id' => 'test-cloud-id',
                'projects' => ['ABC', 'XYZ'],
                'statuses' => ['In Review', 'Backlog'],
                'only_mine' => true,
                'only_active_sprint' => true,
            ],
        ]);
        $this->createToken($source);
        $this->fakeJiraIssues();

        SyncSourceIssuesJob::dispatchSync($source);

        Http::assertSent(function ($request) {
            $url = urldecode($request->url());

            return str_contains($url, 'project in ("ABC","XYZ")')
                && str_contains($url, 'status in ("In Review","Backlog")')
                && str_contains($url, 'assignee = currentUser()')
                && str_contains($url, 'sprint in openSprints()')
                && str_contains($url, 'status != Done');
        });
    }

    public function test_sync_records_error_when_no_token(): void
    {
        $source = $this->createGitHubSource();

        SyncSourceIssuesJob::dispatchSync($source);

        $source->refresh();
        $this->assertNotNull($source->sync_error);
        $this->assertStringContainsString('No OAuth token', $source->sync_error);
        $this->assertNotNull($source->next_retry_at);
    }

    public function test_sync_records_error_on_api_failure(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        Http::fake([
            'api.github.com/*' => Http::response('Server error', 500),
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $source->refresh();
        $this->assertNotNull($source->sync_error);
        $this->assertNotNull($source->next_retry_at);
        $this->assertTrue($source->next_retry_at->isFuture());
    }

    public function test_successful_sync_clears_error(): void
    {
        $source = $this->createGitHubSource();
        $source->update(['sync_error' => 'Previous error', 'next_retry_at' => now()->addMinutes(5)]);
        $this->createToken($source);
        $this->fakeGitHubIssues();

        SyncSourceIssuesJob::dispatchSync($source);

        $source->refresh();
        $this->assertNull($source->sync_error);
        $this->assertNull($source->next_retry_at);
        $this->assertNotNull($source->last_synced_at);
    }

    public function test_sync_error_without_configured_repos(): void
    {
        $source = $this->createGitHubSource(['repositories' => []]);
        $this->createToken($source);

        SyncSourceIssuesJob::dispatchSync($source);

        $source->refresh();
        $this->assertNotNull($source->sync_error);
        $this->assertStringContainsString('No repositories configured', $source->sync_error);
    }

    public function test_sync_respects_filter_rules(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        FilterRule::factory()->create([
            'source_id' => $source->id,
            'include_labels' => ['bug'],
            'exclude_labels' => [],
            'unassigned_only' => false,
            'auto_accept_labels' => [],
        ]);

        $this->fakeGitHubIssues([
            [
                'number' => 1,
                'title' => 'Bug issue',
                'body' => 'A bug',
                'html_url' => 'https://github.com/owner/repo/issues/1',
                'assignee' => null,
                'labels' => [['name' => 'bug']],
            ],
            [
                'number' => 2,
                'title' => 'Feature issue',
                'body' => 'A feature',
                'html_url' => 'https://github.com/owner/repo/issues/2',
                'assignee' => null,
                'labels' => [['name' => 'enhancement']],
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseHas('issues', ['title' => 'Bug issue']);
        $this->assertDatabaseMissing('issues', ['title' => 'Feature issue']);
    }

    public function test_sync_auto_accepts_matching_labels(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        FilterRule::factory()->create([
            'source_id' => $source->id,
            'include_labels' => [],
            'exclude_labels' => [],
            'unassigned_only' => false,
            'auto_accept_labels' => ['urgent'],
        ]);

        $this->fakeGitHubIssues([
            [
                'number' => 1,
                'title' => 'Urgent bug',
                'body' => '',
                'html_url' => 'https://github.com/owner/repo/issues/1',
                'assignee' => null,
                'labels' => [['name' => 'urgent']],
            ],
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $issue = Issue::first();
        $this->assertEquals(IssueStatus::InProgress, $issue->status);
        $this->assertTrue($issue->auto_accepted);
        $this->assertNotNull($issue->runs()->first());
    }

    public function test_sync_now_dispatches_job(): void
    {
        Queue::fake();
        $source = $this->createGitHubSource();

        $response = $this->post(route('sources.sync', $source));

        $response->assertRedirect(route('intake.index'));
        $response->assertSessionHas('success');
        Queue::assertPushed(SyncSourceIssuesJob::class, function ($job) use ($source) {
            return $job->source->id === $source->id;
        });
    }

    public function test_sync_now_button_visible_on_sources_page(): void
    {
        $source = $this->createGitHubSource();
        $this->createToken($source);

        $response = $this->get(route('intake.index'));

        $response->assertSee('Sync Now');
    }

    public function test_sync_error_displayed_on_sources_page(): void
    {
        $source = $this->createGitHubSource();
        $source->update([
            'sync_error' => 'Connection timed out',
            'next_retry_at' => now()->addMinutes(5),
        ]);
        $this->createToken($source);

        $response = $this->get(route('intake.index'));

        $response->assertSee('Connection timed out');
        $response->assertSee('Sync Error');
    }

    public function test_github_map_to_issue_attributes(): void
    {
        $ghIssue = [
            'number' => 42,
            'title' => 'Test issue',
            'body' => 'Issue body',
            'html_url' => 'https://github.com/owner/repo/issues/42',
            'assignee' => ['login' => 'devuser'],
            'labels' => [['name' => 'bug'], ['name' => 'priority']],
        ];

        $attrs = GitHubClient::mapToIssueAttributes($ghIssue);

        $this->assertEquals('42', $attrs['external_id']);
        $this->assertEquals('Test issue', $attrs['title']);
        $this->assertEquals('Issue body', $attrs['body']);
        $this->assertEquals('https://github.com/owner/repo/issues/42', $attrs['external_url']);
        $this->assertEquals('devuser', $attrs['assignee']);
        $this->assertEquals(['bug', 'priority'], $attrs['labels']);
    }

    public function test_github_is_pull_request(): void
    {
        $issue = ['number' => 1, 'title' => 'Issue'];
        $pr = ['number' => 2, 'title' => 'PR', 'pull_request' => ['url' => '...']];

        $this->assertFalse(GitHubClient::isPullRequest($issue));
        $this->assertTrue(GitHubClient::isPullRequest($pr));
    }

    public function test_sync_multiple_github_repos(): void
    {
        $source = $this->createGitHubSource(['repositories' => ['owner/repo1', 'owner/repo2']]);
        $this->createToken($source);

        Http::fake([
            'api.github.com/repos/owner/repo1/issues*' => Http::response([
                ['number' => 1, 'title' => 'Repo1 issue', 'body' => '', 'html_url' => 'https://github.com/owner/repo1/issues/1', 'assignee' => null, 'labels' => []],
            ]),
            'api.github.com/repos/owner/repo2/issues*' => Http::response([
                ['number' => 1, 'title' => 'Repo2 issue', 'body' => '', 'html_url' => 'https://github.com/owner/repo2/issues/1', 'assignee' => null, 'labels' => []],
            ]),
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $this->assertDatabaseCount('issues', 2);
        $this->assertDatabaseHas('issues', ['external_id' => 'owner/repo1#1', 'title' => 'Repo1 issue']);
        $this->assertDatabaseHas('issues', ['external_id' => 'owner/repo2#1', 'title' => 'Repo2 issue']);
    }

    public function test_sync_skips_individually_paused_repo(): void
    {
        $source = $this->createGitHubSource([
            'repositories' => ['owner/repo1', 'owner/repo2'],
        ]);
        $source->update(['paused_repositories' => ['owner/repo1']]);
        $this->createToken($source);

        Http::fake([
            'api.github.com/repos/owner/repo1/issues*' => Http::response([
                ['number' => 1, 'title' => 'Repo1 issue', 'body' => '', 'html_url' => 'https://github.com/owner/repo1/issues/1', 'assignee' => null, 'labels' => []],
            ]),
            'api.github.com/repos/owner/repo2/issues*' => Http::response([
                ['number' => 1, 'title' => 'Repo2 issue', 'body' => '', 'html_url' => 'https://github.com/owner/repo2/issues/1', 'assignee' => null, 'labels' => []],
            ]),
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $this->assertDatabaseCount('issues', 1);
        $this->assertDatabaseMissing('issues', ['external_id' => 'owner/repo1#1']);
        $this->assertDatabaseHas('issues', ['external_id' => 'owner/repo2#1', 'title' => 'Repo2 issue']);

        Http::assertNotSent(fn ($request) => str_contains((string) $request->url(), '/repos/owner/repo1/issues'));
    }

    public function test_source_level_pause_takes_precedence_over_per_repo(): void
    {
        $source = $this->createGitHubSource([
            'repositories' => ['owner/repo1', 'owner/repo2'],
        ]);
        $source->update([
            'is_intake_paused' => true,
            'paused_repositories' => [],
        ]);
        $this->createToken($source);

        Http::fake([
            'api.github.com/repos/owner/repo1/issues*' => Http::response([
                ['number' => 1, 'title' => 'Repo1 issue', 'body' => '', 'html_url' => 'https://github.com/owner/repo1/issues/1', 'assignee' => null, 'labels' => []],
            ]),
            'api.github.com/repos/owner/repo2/issues*' => Http::response([
                ['number' => 1, 'title' => 'Repo2 issue', 'body' => '', 'html_url' => 'https://github.com/owner/repo2/issues/1', 'assignee' => null, 'labels' => []],
            ]),
        ]);

        SyncSourceIssuesJob::dispatchSync($source);

        $this->assertDatabaseCount('issues', 0);
    }

    public function test_configurable_sync_interval(): void
    {
        $source = $this->createGitHubSource();
        $source->update(['sync_interval' => 10]);

        $this->assertEquals(10, $source->fresh()->sync_interval);
    }

    public function test_sync_interval_defaults_to_five(): void
    {
        $source = $this->createGitHubSource();

        $this->assertEquals(5, $source->fresh()->sync_interval);
    }
}
