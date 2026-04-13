<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Enums\StageStatus;
use App\Models\Run;
use App\Models\Stage;
use App\Services\OrchestratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IssueViewController extends Controller
{
    public function __construct(
        private OrchestratorService $orchestrator,
    ) {}

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
}
