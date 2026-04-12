<?php

namespace App\Http\Controllers;

use App\Enums\IssueStatus;
use App\Enums\RunStatus;
use App\Enums\StageName;
use App\Enums\StageStatus;
use App\Models\Issue;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function index(): View
    {
        $activeStageCounts = [];
        foreach (StageName::cases() as $name) {
            $activeStageCounts[$name->value] = Stage::query()
                ->where('name', $name)
                ->whereIn('status', [StageStatus::Running, StageStatus::AwaitingApproval, StageStatus::Pending])
                ->whereHas('run', fn ($q) => $q->whereIn('status', [RunStatus::Running, RunStatus::Pending]))
                ->count();
        }

        $totals = [
            'queued' => Issue::where('status', IssueStatus::Queued)->count(),
            'stuck' => Run::where('status', RunStatus::Stuck)->count(),
            'shipped_week' => Run::where('status', RunStatus::Completed)
                ->where('completed_at', '>=', now()->subWeek())
                ->count(),
            'active' => Run::whereIn('status', [RunStatus::Running, RunStatus::Pending, RunStatus::Stuck])->count(),
        ];

        $intakePausedCount = Source::where('is_intake_paused', true)->count();
        $sourceCount = Source::count();

        $activeRuns = Run::with(['issue.source', 'stages' => fn ($q) => $q->orderByDesc('created_at')])
            ->whereIn('status', [RunStatus::Running, RunStatus::Stuck])
            ->orderByDesc('updated_at')
            ->limit(6)
            ->get();

        return view('overview.index', compact(
            'activeStageCounts',
            'totals',
            'intakePausedCount',
            'sourceCount',
            'activeRuns',
        ));
    }
}
