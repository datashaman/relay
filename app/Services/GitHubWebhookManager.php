<?php

namespace App\Services;

use App\Models\OauthToken;
use App\Models\Source;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;

class GitHubWebhookManager
{
    private const EVENTS = ['issues'];

    public function provisionForSelectedRepositories(Source $source, OauthToken $token, OauthService $oauth): array
    {
        $repos = array_values($source->config['repositories'] ?? []);

        if ($repos === []) {
            return [
                'managed' => 0,
                'permission_errors' => 0,
                'other_errors' => 0,
                'states' => [],
            ];
        }

        $secret = $source->ensureWebhookSecret();
        $callbackUrl = route('webhooks.github', $source);
        $client = new GitHubClient($token, $oauth);

        $states = Arr::wrap($source->config['managed_webhooks'] ?? []);
        $managed = 0;
        $permissionErrors = 0;
        $otherErrors = 0;

        foreach ($repos as $repoFullName) {
            try {
                [$owner, $repo] = $this->splitRepository($repoFullName);
                $hook = $this->createOrUpdateRelayWebhook($client, $owner, $repo, $callbackUrl, $secret);

                $managed++;
                $states[$repoFullName] = [
                    'state' => 'managed',
                    'hook_id' => $hook['id'] ?? null,
                    'updated_at' => now()->toIso8601String(),
                    'reason' => null,
                ];
            } catch (RequestException $e) {
                $status = $e->response->status();
                $isPermissionError = in_array($status, [401, 403], true);
                $isRepoConstraint = in_array($status, [404, 422], true);

                if ($isPermissionError) {
                    $permissionErrors++;
                } else {
                    $otherErrors++;
                }

                $states[$repoFullName] = [
                    'state' => $isPermissionError
                        ? 'needs_permission'
                        : ($isRepoConstraint ? 'manual' : 'error'),
                    'hook_id' => null,
                    'updated_at' => now()->toIso8601String(),
                    'reason' => $this->truncateReason($e->response->json('message') ?? $e->getMessage()),
                ];
            } catch (\Throwable $e) {
                $otherErrors++;

                $states[$repoFullName] = [
                    'state' => 'error',
                    'hook_id' => null,
                    'updated_at' => now()->toIso8601String(),
                    'reason' => $this->truncateReason($e->getMessage()),
                ];
            }
        }

        $source->update([
            'config' => array_merge($source->config ?? [], [
                'managed_webhooks' => $states,
            ]),
            'webhook_last_error' => $otherErrors > 0
                ? 'Failed to manage one or more repository webhooks. Check intake webhook status for details.'
                : null,
        ]);

        return [
            'managed' => $managed,
            'permission_errors' => $permissionErrors,
            'other_errors' => $otherErrors,
            'states' => $states,
        ];
    }

    private function createOrUpdateRelayWebhook(GitHubClient $client, string $owner, string $repo, string $callbackUrl, string $secret): array
    {
        $payload = [
            'name' => 'web',
            'active' => true,
            'events' => self::EVENTS,
            'config' => [
                'url' => $callbackUrl,
                'content_type' => 'json',
                'secret' => $secret,
                'insecure_ssl' => '0',
            ],
        ];

        $existing = collect($client->listRepositoryWebhooks($owner, $repo))
            ->first(fn (array $hook) => ($hook['config']['url'] ?? null) === $callbackUrl);

        if ($existing) {
            return $client->updateRepositoryWebhook($owner, $repo, (int) $existing['id'], $payload);
        }

        return $client->createRepositoryWebhook($owner, $repo, $payload);
    }

    /**
     * @return array{string, string}
     */
    private function splitRepository(string $repoFullName): array
    {
        if (! str_contains($repoFullName, '/')) {
            throw new \InvalidArgumentException("Invalid repository name [{$repoFullName}].");
        }

        return explode('/', $repoFullName, 2);
    }

    private function truncateReason(string $message): string
    {
        return mb_substr(trim($message), 0, 180);
    }
}
