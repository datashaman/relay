<?php

namespace App\Http\Controllers;

use App\Jobs\SyncSourceIssuesJob;
use App\Models\Source;
use App\Services\GitHubClient;
use App\Services\JiraClient;
use App\Services\OauthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SourceController extends Controller
{
    public function __construct(
        private OauthService $oauth,
    ) {}

    public function syncNow(Source $source, Request $request): RedirectResponse|JsonResponse
    {
        SyncSourceIssuesJob::dispatch($source);

        $message = 'Sync started for '.($source->external_account ?? $source->name).'.';

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'webhook' => $this->githubWebhookStatus($source),
            ]);
        }

        return redirect()->route('intake.index')->with('success', $message);
    }

    public function testConnection(Source $source): JsonResponse
    {
        $token = $source->oauthTokens()->where('provider', $source->type->value)->first();

        if (! $token) {
            return response()->json(['success' => false, 'message' => 'No OAuth token found for this source.'], 422);
        }

        try {
            $token = $this->oauth->refreshIfExpired($token);

            if ($source->type->value === 'github') {
                $client = new GitHubClient($token, $this->oauth);
                $client->listRepos(page: 1, perPage: 1);
            } elseif ($source->type->value === 'jira') {
                $client = new JiraClient($token, $this->oauth, $source);
                $client->listProjects();
            }

            return response()->json([
                'success' => true,
                'message' => 'Connection successful.',
                'webhook' => $this->githubWebhookStatus($source),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
                'webhook' => $this->githubWebhookStatus($source),
            ], 422);
        }
    }

    private function githubWebhookStatus(Source $source): ?array
    {
        if ($source->type->value !== 'github') {
            return null;
        }

        $repositories = array_values($source->config['repositories'] ?? []);
        $states = $source->config['managed_webhooks'] ?? [];

        $managed = [];
        $needsPermission = [];
        $manual = [];
        $errors = [];

        foreach ($repositories as $repo) {
            $state = $states[$repo]['state'] ?? null;

            if ($state === 'managed') {
                $managed[] = $repo;
            } elseif ($state === 'needs_permission') {
                $needsPermission[] = $repo;
            } elseif ($state === 'manual') {
                $manual[] = $repo;
            } elseif ($state === 'error') {
                $errors[] = $repo;
            }
        }

        $overall = match (true) {
            $repositories === [] => 'unconfigured',
            $needsPermission !== [] => 'needs_permission',
            $errors !== [] => 'error',
            count($managed) === count($repositories) => 'managed',
            default => 'manual',
        };

        return [
            'state' => $overall,
            'repositories' => $repositories,
            'managed' => $managed,
            'needs_permission' => $needsPermission,
            'manual' => $manual,
            'errors' => $errors,
        ];
    }
}
