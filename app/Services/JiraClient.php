<?php

namespace App\Services;

use App\Models\OauthToken;
use App\Models\Source;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JiraClient
{
    private const MAX_RESULTS = 50;

    private const ISSUE_FIELDS = ['summary', 'description', 'assignee', 'labels', 'status', 'components'];

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

    public function searchIssues(string $jql, ?string $pageToken = null, int $maxResults = self::MAX_RESULTS): array
    {
        $params = [
            'jql' => $jql,
            'maxResults' => $maxResults,
            'fields' => implode(',', self::ISSUE_FIELDS),
        ];

        if ($pageToken !== null) {
            $params['nextPageToken'] = $pageToken;
        }

        $response = $this->request('get', '/search/jql', $params);

        $data = $response->json();

        return [
            'issues' => $data['issues'] ?? [],
            'nextPageToken' => $data['nextPageToken'] ?? null,
            'isLast' => $data['isLast'] ?? ! isset($data['nextPageToken']),
        ];
    }

    public function allIssues(string $jql): array
    {
        $all = [];
        $pageToken = null;
        $seenTokens = [];

        while (true) {
            $result = $this->searchIssues($jql, $pageToken);
            $all = array_merge($all, $result['issues']);

            if ($result['isLast'] || empty($result['nextPageToken'])) {
                break;
            }

            $next = $result['nextPageToken'];

            if (isset($seenTokens[$next])) {
                throw new \RuntimeException('Jira pagination did not advance (nextPageToken repeated).');
            }
            $seenTokens[$next] = true;

            $pageToken = $next;
        }

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

    public function listStatuses(): array
    {
        return $this->request('get', '/status')->json();
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

    public function createWebhooks(string $url, array $webhooks): array
    {
        return $this->request('post', '/webhook', [
            'url' => $url,
            'webhooks' => $webhooks,
        ])->json();
    }

    public function refreshWebhooks(array $webhookIds): array
    {
        return $this->request('put', '/webhook/refresh', [
            'webhookIds' => array_values(array_map('intval', $webhookIds)),
        ])->json();
    }

    public function deleteWebhooks(array $webhookIds): void
    {
        $this->request('delete', '/webhook', [
            'webhookIds' => array_values(array_map('intval', $webhookIds)),
        ]);
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
        $component = self::pickComponent($fields['components'] ?? [], $jiraIssue['key'] ?? $jiraIssue['id'] ?? null);

        return [
            'external_id' => (string) ($jiraIssue['key'] ?? $jiraIssue['id']),
            'title' => $fields['summary'] ?? '',
            'body' => self::extractDescription($fields['description'] ?? null),
            'external_url' => $jiraIssue['self'] ?? '',
            'assignee' => $fields['assignee']['displayName'] ?? null,
            'labels' => $fields['labels'] ?? [],
            'status' => self::mapStatus($fields['status']['name'] ?? null),
            'raw_status' => $fields['status']['name'] ?? null,
            'component_external_id' => $component['id'] ?? null,
            'component_name' => $component['name'] ?? null,
        ];
    }

    private static function pickComponent(array $components, ?string $issueKey): array
    {
        if (empty($components)) {
            return [];
        }

        if (count($components) > 1) {
            Log::warning('Jira issue has multiple components; using lowest id.', [
                'issue_key' => $issueKey,
                'component_ids' => array_map(fn ($c) => $c['id'] ?? null, $components),
            ]);
        }

        usort($components, fn ($a, $b) => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));

        $picked = $components[0];

        return [
            'id' => isset($picked['id']) ? (string) $picked['id'] : null,
            'name' => $picked['name'] ?? null,
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
        $url = $this->baseUrl.$path;

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
