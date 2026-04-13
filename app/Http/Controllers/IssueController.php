<?php

namespace App\Http\Controllers;

use App\Enums\IssueStatus;
use App\Models\Issue;
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

    public function accept(Issue $issue): RedirectResponse
    {
        if ($issue->status !== IssueStatus::Queued) {
            return redirect()->route('intake.index')
                ->with('error', 'Only queued issues can be accepted.');
        }

        $this->orchestrator->startRun($issue);

        return redirect()->route('intake.index')
            ->with('success', "Issue \"{$issue->title}\" accepted. Preflight starting.");
    }

    public function reject(Issue $issue): RedirectResponse
    {
        if ($issue->status !== IssueStatus::Queued) {
            return redirect()->route('intake.index')
                ->with('error', 'Only queued issues can be rejected.');
        }

        $issue->update(['status' => IssueStatus::Rejected]);

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
