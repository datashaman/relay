<?php

namespace App\Services;

use App\Models\OauthToken;
use App\Models\Source;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class JiraClient
{
    private const MAX_RESULTS = 50;

    private string $baseUrl;

    public function __construct(
        private OauthToken $token,
        private OauthService $oauth,
        private Source $source,
    ) {
        $cloudId = $source->config['cloud_id'] ?? null;

        if (! $cloudId) {
            throw new \InvalidArgumentException('Source is missing cloud_id in config.');
        }

        $this->baseUrl = "https://api.atlassian.com/ex/jira/{$cloudId}/rest/api/3";
    }

    public function searchIssues(string $jql, int $startAt = 0, int $maxResults = self::MAX_RESULTS): array
    {
        $response = $this->request('get', '/search', [
            'jql' => $jql,
            'startAt' => $startAt,
            'maxResults' => $maxResults,
        ]);

        $data = $response->json();

        return [
            'issues' => $data['issues'] ?? [],
            'total' => $data['total'] ?? 0,
            'startAt' => $data['startAt'] ?? $startAt,
            'maxResults' => $data['maxResults'] ?? $maxResults,
        ];
    }

    public function allIssues(string $jql): array
    {
        $all = [];
        $startAt = 0;

        do {
            $result = $this->searchIssues($jql, $startAt);
            $all = array_merge($all, $result['issues']);
            $startAt += $result['maxResults'];
        } while ($startAt < $result['total']);

        return $all;
    }

    public function getIssue(string $issueIdOrKey): array
    {
        return $this->request('get', "/issue/{$issueIdOrKey}")->json();
    }

    public function listProjects(): array
    {
        return $this->request('get', '/project')->json();
    }

    public function listTransitions(string $issueIdOrKey): array
    {
        $response = $this->request('get', "/issue/{$issueIdOrKey}/transitions");

        return $response->json()['transitions'] ?? [];
    }

    public function transitionIssue(string $issueIdOrKey, string $transitionId): array
    {
        return $this->request('post', "/issue/{$issueIdOrKey}/transitions", [
            'transition' => ['id' => $transitionId],
        ])->json();
    }

    public function addComment(string $issueIdOrKey, string $body): array
    {
        return $this->request('post', "/issue/{$issueIdOrKey}/comment", [
            'body' => [
                'type' => 'doc',
                'version' => 1,
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => $body],
                        ],
                    ],
                ],
            ],
        ])->json();
    }

    public static function mapToIssueAttributes(array $jiraIssue): array
    {
        $fields = $jiraIssue['fields'] ?? [];

        return [
            'external_id' => (string) $jiraIssue['id'],
            'title' => $fields['summary'] ?? '',
            'body' => self::extractDescription($fields['description'] ?? null),
            'external_url' => $jiraIssue['self'] ?? '',
            'assignee' => $fields['assignee']['displayName'] ?? null,
            'labels' => $fields['labels'] ?? [],
            'status' => self::mapStatus($fields['status']['name'] ?? null),
        ];
    }

    private static function extractDescription(?array $doc): string
    {
        if (! $doc || ($doc['type'] ?? null) !== 'doc') {
            return '';
        }

        $text = '';
        foreach ($doc['content'] ?? [] as $block) {
            foreach ($block['content'] ?? [] as $inline) {
                $text .= $inline['text'] ?? '';
            }
            $text .= "\n";
        }

        return trim($text);
    }

    private static function mapStatus(?string $jiraStatus): string
    {
        if (! $jiraStatus) {
            return 'incoming';
        }

        $lower = strtolower($jiraStatus);

        return match (true) {
            in_array($lower, ['done', 'closed', 'resolved']) => 'rejected',
            in_array($lower, ['in progress', 'in review']) => 'accepted',
            default => 'incoming',
        };
    }

    private function request(string $method, string $path, array $data = []): Response
    {
        $url = $this->baseUrl . $path;

        $this->token = $this->oauth->refreshIfExpired($this->token);

        $pending = Http::withToken($this->token->access_token)
            ->accept('application/json')
            ->contentType('application/json');

        $response = $method === 'get'
            ? $pending->get($url, $data)
            : $pending->$method($url, $data);

        if ($response->status() === 401 && $this->token->refresh_token) {
            $this->token = $this->oauth->refreshToken($this->token);
            $pending = Http::withToken($this->token->access_token)
                ->accept('application/json')
                ->contentType('application/json');
            $response = $method === 'get'
                ? $pending->get($url, $data)
                : $pending->$method($url, $data);
        }

        $response->throw();

        return $response;
    }
}
