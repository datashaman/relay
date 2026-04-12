<?php

namespace Tests\Feature;

use App\Models\OauthToken;
use App\Models\Source;
use App\Services\GitHubClient;
use App\Services\OauthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class GitHubClientTest extends TestCase
{
    use RefreshDatabase;

    private OauthToken $token;
    private GitHubClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github' => [
                'client_id' => 'test-github-id',
                'client_secret' => 'test-github-secret',
                'redirect_uri' => 'http://localhost:8000/oauth/callback/github',
                'authorize_url' => 'https://github.com/login/oauth/authorize',
                'token_url' => 'https://github.com/login/oauth/access_token',
                'scopes' => ['repo', 'read:org', 'workflow'],
            ],
        ]);

        $source = Source::factory()->create(['type' => 'github']);
        $this->token = OauthToken::factory()->create([
            'source_id' => $source->id,
            'provider' => 'github',
            'access_token' => 'ghp_test_token',
            'expires_at' => now()->addHour(),
        ]);

        $this->client = new GitHubClient($this->token, app(OauthService::class));
    }

    public function test_list_repos(): void
    {
        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['id' => 1, 'full_name' => 'user/repo-a'],
                ['id' => 2, 'full_name' => 'user/repo-b'],
            ]),
        ]);

        $result = $this->client->listRepos();

        $this->assertCount(2, $result['data']);
        $this->assertEquals('user/repo-a', $result['data'][0]['full_name']);
        $this->assertNull($result['next_page']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/user/repos')
                && $request->hasHeader('Authorization', 'Bearer ghp_test_token');
        });
    }

    public function test_list_issues(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::response([
                ['number' => 1, 'title' => 'Bug report'],
                ['number' => 2, 'title' => 'Feature request'],
            ]),
        ]);

        $result = $this->client->listIssues('owner', 'repo');

        $this->assertCount(2, $result['data']);
        $this->assertEquals('Bug report', $result['data'][0]['title']);
    }

    public function test_get_issue(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues/42' => Http::response([
                'number' => 42,
                'title' => 'Fix login',
                'body' => 'Login is broken',
                'labels' => [['name' => 'bug']],
            ]),
        ]);

        $issue = $this->client->getIssue('owner', 'repo', 42);

        $this->assertEquals(42, $issue['number']);
        $this->assertEquals('Fix login', $issue['title']);
    }

    public function test_create_branch(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/git/refs' => Http::response([
                'ref' => 'refs/heads/feature-branch',
                'object' => ['sha' => 'abc123'],
            ], 201),
        ]);

        $result = $this->client->createBranch('owner', 'repo', 'feature-branch', 'abc123');

        $this->assertEquals('refs/heads/feature-branch', $result['ref']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/git/refs')
                && $request['ref'] === 'refs/heads/feature-branch'
                && $request['sha'] === 'abc123';
        });
    }

    public function test_push_branch(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/git/refs/heads/feature*' => Http::response([
                'ref' => 'refs/heads/feature',
                'object' => ['sha' => 'def456'],
            ]),
        ]);

        $result = $this->client->pushBranch('owner', 'repo', 'feature', 'def456');

        $this->assertEquals('def456', $result['object']['sha']);

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && $request['sha'] === 'def456';
        });
    }

    public function test_create_pull_request(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/pulls' => Http::response([
                'number' => 10,
                'title' => 'Add feature',
                'html_url' => 'https://github.com/owner/repo/pull/10',
            ], 201),
        ]);

        $result = $this->client->createPullRequest('owner', 'repo', 'Add feature', 'feature-branch', 'main', 'PR body');

        $this->assertEquals(10, $result['number']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/pulls')
                && $request['title'] === 'Add feature'
                && $request['head'] === 'feature-branch'
                && $request['base'] === 'main'
                && $request['body'] === 'PR body';
        });
    }

    public function test_add_comment(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues/5/comments' => Http::response([
                'id' => 100,
                'body' => 'Hello from Relay',
            ], 201),
        ]);

        $result = $this->client->addComment('owner', 'repo', 5, 'Hello from Relay');

        $this->assertEquals('Hello from Relay', $result['body']);

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/issues/5/comments')
                && $request['body'] === 'Hello from Relay';
        });
    }

    public function test_pagination_extracts_next_page(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::response(
                [['number' => 1]],
                200,
                ['Link' => '<https://api.github.com/repos/owner/repo/issues?page=2>; rel="next", <https://api.github.com/repos/owner/repo/issues?page=5>; rel="last"'],
            ),
        ]);

        $result = $this->client->listIssues('owner', 'repo');

        $this->assertEquals(2, $result['next_page']);
    }

    public function test_pagination_returns_null_on_last_page(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::response(
                [['number' => 1]],
                200,
                ['Link' => '<https://api.github.com/repos/owner/repo/issues?page=1>; rel="first", <https://api.github.com/repos/owner/repo/issues?page=3>; rel="prev"'],
            ),
        ]);

        $result = $this->client->listIssues('owner', 'repo');

        $this->assertNull($result['next_page']);
    }

    public function test_all_issues_fetches_all_pages(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues*' => Http::sequence()
                ->push(
                    [['number' => 1], ['number' => 2]],
                    200,
                    ['Link' => '<https://api.github.com/repos/owner/repo/issues?page=2>; rel="next"'],
                )
                ->push(
                    [['number' => 3]],
                    200,
                ),
        ]);

        $issues = $this->client->allIssues('owner', 'repo');

        $this->assertCount(3, $issues);
        $this->assertEquals(3, $issues[2]['number']);
    }

    public function test_rate_limit_backoff(): void
    {
        Sleep::fake();

        $resetTime = time() + 30;

        Http::fake([
            'api.github.com/repos/owner/repo/issues/1' => Http::sequence()
                ->push('rate limit exceeded', 403, [
                    'X-RateLimit-Remaining' => '0',
                    'X-RateLimit-Reset' => (string) $resetTime,
                ])
                ->push(['number' => 1, 'title' => 'Issue'], 200),
        ]);

        $issue = $this->client->getIssue('owner', 'repo', 1);

        $this->assertEquals(1, $issue['number']);
        Sleep::assertSlept(function ($duration) {
            return $duration->totalSeconds >= 1;
        });
    }

    public function test_token_refresh_on_401(): void
    {
        Http::fake([
            'api.github.com/repos/owner/repo/issues/1' => Http::sequence()
                ->push(null, 401)
                ->push(['number' => 1, 'title' => 'Issue'], 200),
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'ghp_refreshed_token',
                'token_type' => 'bearer',
            ]),
        ]);

        $this->token->update(['refresh_token' => 'refresh_me']);

        $issue = $this->client->getIssue('owner', 'repo', 1);

        $this->assertEquals(1, $issue['number']);

        $this->token->refresh();
        $this->assertEquals('ghp_refreshed_token', $this->token->access_token);
    }

    public function test_requests_include_accept_header(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([]),
        ]);

        $this->client->listRepos();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Accept', 'application/vnd.github+json');
        });
    }

    public function test_rate_limit_without_reset_header_defaults_to_60s(): void
    {
        Sleep::fake();

        Http::fake([
            'api.github.com/repos/owner/repo/issues/1' => Http::sequence()
                ->push('rate limit exceeded', 403, [
                    'X-RateLimit-Remaining' => '0',
                ])
                ->push(['number' => 1], 200),
        ]);

        $this->client->getIssue('owner', 'repo', 1);

        Sleep::assertSleptTimes(1);
    }
}
