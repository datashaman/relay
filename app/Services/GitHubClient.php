<?php

namespace App\Services;

use App\Models\OauthToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class GitHubClient
{
    private const BASE_URL = 'https://api.github.com';

    private const PER_PAGE = 30;

    public function __construct(
        private OauthToken $token,
        private OauthService $oauth,
    ) {}

    public function listRepos(int $page = 1, int $perPage = self::PER_PAGE): array
    {
        return $this->paginatedGet('/user/repos', [
            'per_page' => $perPage,
            'page' => $page,
            'sort' => 'updated',
        ]);
    }

    public function listIssues(string $owner, string $repo, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        return $this->paginatedGet("/repos/{$owner}/{$repo}/issues", [
            'per_page' => $perPage,
            'page' => $page,
            'state' => 'open',
        ]);
    }

    public function getIssue(string $owner, string $repo, int $number): array
    {
        return $this->request('get', "/repos/{$owner}/{$repo}/issues/{$number}")->json();
    }

    public function createBranch(string $owner, string $repo, string $branch, string $sha): array
    {
        return $this->request('post', "/repos/{$owner}/{$repo}/git/refs", [
            'ref' => "refs/heads/{$branch}",
            'sha' => $sha,
        ])->json();
    }

    public function pushBranch(string $owner, string $repo, string $branch, string $sha, bool $force = false): array
    {
        return $this->request('patch', "/repos/{$owner}/{$repo}/git/refs/heads/{$branch}", [
            'sha' => $sha,
            'force' => $force,
        ])->json();
    }

    public function createPullRequest(string $owner, string $repo, string $title, string $head, string $base, string $body = ''): array
    {
        return $this->request('post', "/repos/{$owner}/{$repo}/pulls", [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ])->json();
    }

    public function addComment(string $owner, string $repo, int $issueNumber, string $body): array
    {
        return $this->request('post', "/repos/{$owner}/{$repo}/issues/{$issueNumber}/comments", [
            'body' => $body,
        ])->json();
    }

    /**
     * Fetch repository metadata (language, topics, default_branch, etc.).
     *
     * @return array<string, mixed>
     */
    public function getRepo(string $owner, string $repo): array
    {
        return $this->request('get', "/repos/{$owner}/{$repo}", [], [
            'Accept' => 'application/vnd.github.mercy-preview+json',
        ])->json();
    }

    /**
     * Fetch the raw, decoded contents of a single file at the repo's default branch.
     * Returns null when the file does not exist (404) or when the response cannot be decoded.
     */
    public function getFileContents(string $owner, string $repo, string $path): ?string
    {
        $response = $this->request(
            'get',
            "/repos/{$owner}/{$repo}/contents/{$path}",
            [],
            [],
            allowNotFound: true,
        );

        if ($response->status() === 404) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body) || ($body['encoding'] ?? null) !== 'base64' || ! isset($body['content'])) {
            return null;
        }

        $decoded = base64_decode(str_replace("\n", '', (string) $body['content']), true);

        return $decoded === false ? null : $decoded;
    }

    public function listRepositoryWebhooks(string $owner, string $repo): array
    {
        return $this->request('get', "/repos/{$owner}/{$repo}/hooks")->json();
    }

    public function createRepositoryWebhook(string $owner, string $repo, array $payload): array
    {
        return $this->request('post', "/repos/{$owner}/{$repo}/hooks", $payload)->json();
    }

    public function updateRepositoryWebhook(string $owner, string $repo, int $hookId, array $payload): array
    {
        return $this->request('patch', "/repos/{$owner}/{$repo}/hooks/{$hookId}", $payload)->json();
    }

    public function allIssues(string $owner, string $repo): array
    {
        // state=all so we can reconcile issues closed on GitHub with local Queued rows.
        // Tradeoff: O(all-time issues) per sync for large repos; revisit with a
        // reconcile-only pass if it becomes a problem.
        return $this->fetchAllPages("/repos/{$owner}/{$repo}/issues", ['state' => 'all']);
    }

    public static function mapToIssueAttributes(array $ghIssue): array
    {
        return [
            'external_id' => (string) ($ghIssue['number'] ?? ''),
            'title' => $ghIssue['title'] ?? '',
            'body' => $ghIssue['body'] ?? '',
            'external_url' => $ghIssue['html_url'] ?? '',
            'assignee' => $ghIssue['assignee']['login'] ?? null,
            'labels' => array_map(fn ($l) => $l['name'], $ghIssue['labels'] ?? []),
            'state' => $ghIssue['state'] ?? 'open',
            'state_reason' => $ghIssue['state_reason'] ?? null,
        ];
    }

    public static function isPullRequest(array $ghIssue): bool
    {
        return isset($ghIssue['pull_request']);
    }

    public function allRepos(): array
    {
        return $this->fetchAllPages('/user/repos', ['sort' => 'updated']);
    }

    public function searchRepos(string $query, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $response = $this->request('get', '/search/repositories', [
            'q' => $query,
            'page' => $page,
            'per_page' => $perPage,
            'sort' => 'updated',
        ]);
        $body = $response->json();

        return [
            'data' => $body['items'] ?? [],
            'total' => $body['total_count'] ?? 0,
            'next_page' => $this->extractNextPage($response),
        ];
    }

    private function paginatedGet(string $path, array $params = []): array
    {
        $response = $this->request('get', $path, $params);

        return [
            'data' => $response->json(),
            'next_page' => $this->extractNextPage($response),
        ];
    }

    private function fetchAllPages(string $path, array $params = []): array
    {
        $all = [];
        $page = 1;
        $params['per_page'] = self::PER_PAGE;

        do {
            $params['page'] = $page;
            $response = $this->request('get', $path, $params);
            $data = $response->json();
            $all = array_merge($all, $data);
            $nextPage = $this->extractNextPage($response);
            $page = $nextPage;
        } while ($nextPage !== null);

        return $all;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $extraHeaders
     */
    private function request(string $method, string $path, array $data = [], array $extraHeaders = [], bool $allowNotFound = false): Response
    {
        $url = self::BASE_URL.$path;

        $this->token = $this->oauth->refreshIfExpired($this->token);

        $headers = array_merge(['Accept' => 'application/vnd.github+json'], $extraHeaders);

        $pending = Http::withToken($this->token->access_token)
            ->withHeaders($headers);

        $response = $method === 'get'
            ? $pending->get($url, $data)
            : $pending->$method($url, $data);

        if ($response->status() === 401 && $this->token->refresh_token) {
            $this->token = $this->oauth->refreshToken($this->token);
            $pending = Http::withToken($this->token->access_token)
                ->withHeaders($headers);
            $response = $method === 'get'
                ? $pending->get($url, $data)
                : $pending->$method($url, $data);
        }

        if ($response->status() === 403 && $this->isRateLimited($response)) {
            $this->waitForRateLimit($response);

            return $this->request($method, $path, $data, $extraHeaders, $allowNotFound);
        }

        if ($allowNotFound && $response->status() === 404) {
            return $response;
        }

        $response->throw();

        return $response;
    }

    private function isRateLimited(Response $response): bool
    {
        return $response->header('X-RateLimit-Remaining') === '0'
            || str_contains($response->body(), 'rate limit');
    }

    private function waitForRateLimit(Response $response): void
    {
        $resetAt = $response->header('X-RateLimit-Reset');

        if ($resetAt) {
            $seconds = max(1, (int) $resetAt - time());
        } else {
            $seconds = 60;
        }

        Sleep::for($seconds)->seconds();
    }

    private function extractNextPage(Response $response): ?int
    {
        $link = $response->header('Link');

        if (! $link) {
            return null;
        }

        if (preg_match('/<[^>]*[?&]page=(\d+)[^>]*>;\s*rel="next"/', $link, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
