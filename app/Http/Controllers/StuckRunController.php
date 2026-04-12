<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Enums\StuckState;
use App\Models\Run;
use App\Services\OrchestratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StuckRunController extends Controller
{
    public function __construct(
        private OrchestratorService $orchestrator,
    ) {}

    public function index(): View
    {
        $stuckRuns = Run::with(['issue.source', 'stages' => fn ($q) => $q->latest('id')->limit(1)])
            ->where('status', RunStatus::Stuck)
            ->orderBy('updated_at', 'desc')
            ->get();

        Run::where('status', RunStatus::Stuck)
            ->where('stuck_unread', true)
            ->update(['stuck_unread' => false]);

        return view('stuck.index', compact('stuckRuns'));
    }

    public function showGuidance(Run $run): View
    {
        abort_unless($run->status === RunStatus::Stuck, 404);

        $run->load(['issue', 'stages.events']);

        return view('stuck.guidance', compact('run'));
    }

    public function submitGuidance(Run $run, Request $request): RedirectResponse
    {
        abort_unless($run->status === RunStatus::Stuck, 422);

        $validated = $request->validate([
            'guidance' => 'required|string|max:10000',
        ]);

        $this->orchestrator->giveGuidance($run, $validated['guidance']);

        return redirect()->route('stuck.index')
            ->with('success', "Guidance submitted for \"{$run->issue->title}\". Stage will retry.");
    }

    public function restart(Run $run): RedirectResponse
    {
        abort_unless($run->status === RunStatus::Stuck, 422);

        $this->orchestrator->restart($run);

        return redirect()->route('stuck.index')
            ->with('success', "Run restarted for \"{$run->issue->title}\".");
    }
}
