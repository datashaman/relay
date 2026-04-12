<?php

namespace App\Http\Controllers;

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageStatus;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Stage;
use App\Services\OrchestratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IssueViewController extends Controller
{
    public function __construct(
        private OrchestratorService $orchestrator,
    ) {}

    public function show(Issue $issue): View
    {
        $issues = $this->loadIssueList();

        $issue->load(['source', 'repository', 'runs' => fn ($q) => $q->latest('id')]);

        $latestRun = $issue->runs->first();
        $currentStage = null;

        if ($latestRun) {
            $latestRun->load([
                'stages' => fn ($q) => $q->orderBy('id'),
                'stages.events' => fn ($q) => $q->orderBy('created_at'),
            ]);
            $currentStage = $latestRun->stages->last();
        }

        return view('issues.view', [
            'issues' => $issues,
            'activeIssue' => $issue,
            'latestRun' => $latestRun,
            'currentStage' => $currentStage,
        ]);
    }

    public function approve(Stage $stage): RedirectResponse
    {
        $stage->load('run.issue');

        if ($stage->status !== StageStatus::AwaitingApproval) {
            return redirect()->route('issues.show', $stage->run->issue)
                ->with('error', 'This stage is not awaiting approval.');
        }

        $this->orchestrator->resume($stage);

        return redirect()->route('issues.show', $stage->run->issue)
            ->with('success', ucfirst($stage->name->value) . ' stage approved.');
    }

    public function reject(Stage $stage): RedirectResponse
    {
        $stage->load('run.issue');

        if ($stage->status !== StageStatus::AwaitingApproval) {
            return redirect()->route('issues.show', $stage->run->issue)
                ->with('error', 'This stage is not awaiting approval.');
        }

        $this->orchestrator->fail($stage, 'Rejected by user.');

        return redirect()->route('issues.show', $stage->run->issue)
            ->with('success', ucfirst($stage->name->value) . ' stage rejected.');
    }

    public function guidance(Run $run, Request $request): RedirectResponse
    {
        $run->load('issue');

        if ($run->status !== RunStatus::Stuck) {
            return redirect()->route('issues.show', $run->issue)
                ->with('error', 'This run is not stuck.');
        }

        $validated = $request->validate([
            'guidance' => 'required|string|max:10000',
        ]);

        $this->orchestrator->giveGuidance($run, $validated['guidance']);

        return redirect()->route('issues.show', $run->issue)
            ->with('success', 'Guidance submitted. Stage will retry.');
    }

    private function loadIssueList()
    {
        return Issue::with(['runs' => fn ($q) => $q->latest('id')->limit(1), 'runs.stages' => fn ($q) => $q->latest('id')->limit(1)])
            ->whereIn('status', [
                IssueStatus::Accepted,
                IssueStatus::InProgress,
                IssueStatus::Stuck,
                IssueStatus::Completed,
                IssueStatus::Failed,
            ])
            ->orderByRaw("CASE
                WHEN status = 'stuck' THEN 0
                WHEN status = 'in_progress' THEN 1
                WHEN status = 'accepted' THEN 2
                WHEN status = 'failed' THEN 3
                WHEN status = 'completed' THEN 4
                ELSE 5
            END")
            ->orderBy('updated_at', 'desc')
            ->get();
    }
}
