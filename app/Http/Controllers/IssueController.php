<?php

namespace App\Http\Controllers;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Repository;
use App\Models\Source;
use App\Services\OrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IssueController extends Controller
{
    public function __construct(
        private OrchestratorService $orchestrator,
    ) {}

    public function accept(Issue $issue, Request $request): RedirectResponse
    {
        if ($issue->status !== IssueStatus::Queued) {
            return redirect()->route('intake.index')
                ->with('error', 'Only queued issues can be accepted.');
        }

        $repository = $this->resolveAcceptRepository($issue, $request);

        if ($repository === false) {
            return redirect()->route('intake.index')
                ->with('error', 'Pick a repository to start this issue on.');
        }

        $this->orchestrator->startRun($issue, $repository);

        return redirect()->route('intake.index')
            ->with('success', "Issue \"{$issue->title}\" accepted. Preflight starting.");
    }

    private function resolveAcceptRepository(Issue $issue, Request $request): Repository|false|null
    {
        if ($issue->repository_id) {
            return $issue->repository;
        }

        if (! $issue->component_id) {
            return null;
        }

        $repoId = $request->integer('repository_id');

        if (! $repoId) {
            return false;
        }

        $repository = $issue->component->repositories()->whereKey($repoId)->first();

        return $repository ?: false;
    }

    public function reject(Issue $issue): RedirectResponse
    {
        if ($issue->status !== IssueStatus::Queued) {
            return redirect()->route('intake.index')
                ->with('error', 'Only queued issues can be rejected.');
        }

        // Clear raw_status so the row is distinguishable from sync-driven
        // rejections. markReopened() treats null raw_status as "user rejected"
        // and refuses to resurrect the issue on upstream reopen.
        $issue->update([
            'status' => IssueStatus::Rejected,
            'raw_status' => null,
        ]);

        return redirect()->route('intake.index')
            ->with('success', "Issue \"{$issue->title}\" rejected.");
    }

    public function togglePause(Source $source, Request $request): RedirectResponse|JsonResponse
    {
        $paused = ! $source->is_intake_paused;

        $data = ['is_intake_paused' => $paused];

        if ($request->filled('backlog_threshold')) {
            $request->validate(['backlog_threshold' => 'integer|min:1|max:1000']);
            $data['backlog_threshold'] = $request->input('backlog_threshold');
        }

        $source->update($data);

        $status = $paused ? 'paused' : 'resumed';
        $message = "Intake {$status} for {$source->external_account}.";

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'is_intake_paused' => $paused,
            ]);
        }

        return redirect()->route('intake.index')->with('success', $message);
    }
}
