<?php

namespace Tests\Feature;

use App\Models\OauthToken;
use App\Models\Source;
use App\Services\JiraClient;
use App\Services\OauthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JiraClientTest extends TestCase
{
    use RefreshDatabase;

    private OauthToken $token;
    private Source $source;
    private JiraClient $client;

    private const CLOUD_ID = 'test-cloud-123';
    private const BASE = 'api.atlassian.com/ex/jira/test-cloud-123/rest/api/3';

    private function fixture(string $name): array
    {
        return json_decode(
            file_get_contents(base_path("tests/fixtures/jira/{$name}.json")),
            true
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.jira' => [
                'client_id' => 'test-jira-id',
                'client_secret' => 'test-jira-secret',
                'redirect_uri' => 'http://localhost:8000/oauth/jira/callback',
                'authorize_url' => 'https://auth.atlassian.com/authorize',
                'token_url' => 'https://auth.atlassian.com/oauth/token',
                'scopes' => ['read:jira-work', 'write:jira-work', 'read:jira-user', 'offline_access'],
            ],
        ]);

        $this->source = Source::factory()->create([
            'type' => 'jira',
            'config' => ['cloud_id' => self::CLOUD_ID, 'site_url' => 'https://test.atlassian.net'],
        ]);

        $this->token = OauthToken::factory()->create([
            'source_id' => $this->source->id,
            'provider' => 'jira',
            'access_token' => 'jira_test_token',
            'refresh_token' => 'jira_refresh_token',
            'expires_at' => now()->addHour(),
        ]);

        $this->client = new JiraClient($this->token, app(OauthService::class), $this->source);
    }

    public function test_search_issues_with_jql(): void
    {
        Http::fake([
            self::BASE . '/search/jql*' => Http::response($this->fixture('search_issues')),
        ]);

        $result = $this->client->searchIssues('project = TEST');

        $this->assertCount(2, $result['issues']);
        $this->assertTrue($result['isLast']);
        $this->assertNull($result['nextPageToken']);
        $this->assertEquals('Bug in login', $result['issues'][0]['fields']['summary']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/search/jql')
                && str_contains($request->url(), 'jql=')
                && str_contains($request->url(), 'fields=')
                && $request->hasHeader('Authorization', 'Bearer jira_test_token');
        });
    }

    public function test_get_issue(): void
    {
        Http::fake([
            self::BASE . '/issue/TEST-1' => Http::response($this->fixture('get_issue')),
        ]);

        $issue = $this->client->getIssue('TEST-1');

        $this->assertEquals('10001', $issue['id']);
        $this->assertEquals('Bug in login', $issue['fields']['summary']);
    }

    public function test_list_projects(): void
    {
        Http::fake([
            self::BASE . '/project' => Http::response($this->fixture('list_projects')),
        ]);

        $projects = $this->client->listProjects();

        $this->assertCount(2, $projects);
        $this->assertEquals('TEST', $projects[0]['key']);
    }

    public function test_list_transitions(): void
    {
        Http::fake([
            self::BASE . '/issue/TEST-1/transitions' => Http::response($this->fixture('list_transitions')),
        ]);

        $transitions = $this->client->listTransitions('TEST-1');

        $this->assertCount(3, $transitions);
        $this->assertEquals('In Progress', $transitions[1]['name']);
    }

    public function test_transition_issue(): void
    {
        Http::fake([
            self::BASE . '/issue/TEST-1/transitions' => Http::response([], 204),
        ]);

        $this->client->transitionIssue('TEST-1', '21');

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/issue/TEST-1/transitions')
                && $request['transition']['id'] === '21';
        });
    }

    public function test_add_comment(): void
    {
        Http::fake([
            self::BASE . '/issue/TEST-1/comment' => Http::response($this->fixture('add_comment'), 201),
        ]);

        $result = $this->client->addComment('TEST-1', 'Hello from Relay');

        $this->assertEquals('10100', $result['id']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/issue/TEST-1/comment')
                && $request['body']['type'] === 'doc'
                && $request['body']['content'][0]['content'][0]['text'] === 'Hello from Relay';
        });
    }

    public function test_pagination_with_page_token(): void
    {
        Http::fake([
            self::BASE . '/search/jql*' => Http::response([
                'issues' => [
                    $this->fakeJiraIssue('10003', 'Third issue'),
                ],
                'nextPageToken' => 'page-3',
                'isLast' => false,
            ]),
        ]);

        $result = $this->client->searchIssues('project = TEST', pageToken: 'page-2');

        $this->assertCount(1, $result['issues']);
        $this->assertEquals('page-3', $result['nextPageToken']);
        $this->assertFalse($result['isLast']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'nextPageToken=page-2');
        });
    }

    public function test_all_issues_fetches_all_pages(): void
    {
        Http::fake([
            self::BASE . '/search/jql*' => Http::sequence()
                ->push([
                    'issues' => [
                        $this->fakeJiraIssue('10001', 'First'),
                        $this->fakeJiraIssue('10002', 'Second'),
                    ],
                    'nextPageToken' => 'page-2',
                    'isLast' => false,
                ])
                ->push([
                    'issues' => [
                        $this->fakeJiraIssue('10003', 'Third'),
                    ],
                    'isLast' => true,
                ]),
        ]);

        $issues = $this->client->allIssues('project = TEST');

        $this->assertCount(3, $issues);
        $this->assertEquals('Third', $issues[2]['fields']['summary']);
    }

    public function test_token_refresh_on_401(): void
    {
        Http::fake([
            self::BASE . '/project' => Http::sequence()
                ->push(null, 401)
                ->push([['id' => '10000', 'key' => 'TEST']], 200),
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'jira_refreshed_token',
                'token_type' => 'bearer',
                'expires_in' => 3600,
            ]),
        ]);

        $projects = $this->client->listProjects();

        $this->assertCount(1, $projects);

        $this->token->refresh();
        $this->assertEquals('jira_refreshed_token', $this->token->access_token);
    }

    public function test_cloud_id_scoped_in_url(): void
    {
        Http::fake([
            self::BASE . '/project' => Http::response([]),
        ]);

        $this->client->listProjects();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/ex/jira/' . self::CLOUD_ID . '/rest/api/3');
        });
    }

    public function test_missing_cloud_id_throws(): void
    {
        $source = Source::factory()->create([
            'type' => 'jira',
            'config' => [],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing cloud_id');

        new JiraClient($this->token, app(OauthService::class), $source);
    }

    public function test_map_to_issue_attributes(): void
    {
        $jiraIssue = $this->fakeJiraIssue('10001', 'Bug report', 'TEST-1', [
            'assignee' => ['displayName' => 'Jane Doe', 'accountId' => 'abc'],
            'labels' => ['bug', 'urgent'],
            'status' => ['name' => 'To Do'],
            'description' => [
                'type' => 'doc',
                'version' => 1,
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => 'Login page crashes on submit'],
                        ],
                    ],
                ],
            ],
        ]);

        $attrs = JiraClient::mapToIssueAttributes($jiraIssue);

        $this->assertEquals('10001', $attrs['external_id']);
        $this->assertEquals('Bug report', $attrs['title']);
        $this->assertEquals('Login page crashes on submit', $attrs['body']);
        $this->assertEquals('Jane Doe', $attrs['assignee']);
        $this->assertEquals(['bug', 'urgent'], $attrs['labels']);
        $this->assertEquals('incoming', $attrs['status']);
    }

    public function test_map_status_done_to_rejected(): void
    {
        $jiraIssue = $this->fakeJiraIssue('10001', 'Done issue', 'TEST-1', [
            'status' => ['name' => 'Done'],
        ]);

        $attrs = JiraClient::mapToIssueAttributes($jiraIssue);

        $this->assertEquals('rejected', $attrs['status']);
    }

    public function test_map_status_in_progress_to_accepted(): void
    {
        $jiraIssue = $this->fakeJiraIssue('10001', 'WIP issue', 'TEST-1', [
            'status' => ['name' => 'In Progress'],
        ]);

        $attrs = JiraClient::mapToIssueAttributes($jiraIssue);

        $this->assertEquals('accepted', $attrs['status']);
    }

    public function test_requests_include_accept_and_content_type(): void
    {
        Http::fake([
            self::BASE . '/*' => Http::response([]),
        ]);

        $this->client->listProjects();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('Content-Type', 'application/json');
        });
    }

    private function fakeJiraIssue(string $id, string $summary, string $key = 'TEST-1', array $fieldOverrides = []): array
    {
        return [
            'id' => $id,
            'key' => $key,
            'self' => "https://test.atlassian.net/rest/api/3/issue/{$id}",
            'fields' => array_merge([
                'summary' => $summary,
                'description' => null,
                'assignee' => null,
                'labels' => [],
                'status' => ['name' => 'To Do'],
            ], $fieldOverrides),
        ];
    }
}
