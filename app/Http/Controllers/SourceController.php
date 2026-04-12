<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Services\GitHubClient;
use App\Services\JiraClient;
use App\Services\OauthService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class SourceController extends Controller
{
    public function __construct(
        private OauthService $oauth,
    ) {}

    public function index(): View
    {
        $sources = Source::with('oauthTokens')->orderBy('created_at', 'desc')->get();

        return view('sources.index', compact('sources'));
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

            return response()->json(['success' => true, 'message' => 'Connection successful.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Connection failed: ' . $e->getMessage()], 422);
        }
    }
}
