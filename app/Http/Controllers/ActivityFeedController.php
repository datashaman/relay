<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Enums\StageStatus;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Models\StageEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityFeedController extends Controller
{
    public function index(Request $request): View
    {
        $query = StageEvent::with([
            'stage.run.issue.source',
        ])->orderBy('created_at', 'desc');

        if ($request->filled('source')) {
            $query->whereHas('stage.run.issue', fn ($q) => $q->where('source_id', $request->input('source')));
        }

        if ($request->filled('stage')) {
            $query->whereHas('stage', fn ($q) => $q->where('name', $request->input('stage')));
        }

        if ($request->filled('actor')) {
            $query->where('actor', $request->input('actor'));
        }

        if ($request->filled('autonomy')) {
            $query->where('payload->autonomy_level', $request->input('autonomy'));
        }

        $events = $query->paginate(50)->withQueryString();

        $sources = Source::where('is_active', true)->orderBy('name')->get();

        $dayAgo = now()->subDay();
        $weekAgo = now()->subWeek();

        $completedDay = Stage::where('status', StageStatus::Completed)
            ->where('completed_at', '>=', $dayAgo)
            ->count();
        $completedWeek = Run::where('status', RunStatus::Completed)
            ->where('completed_at', '>=', $weekAgo)
            ->count();
        $failedWeek = Run::where('status', RunStatus::Failed)
            ->where('completed_at', '>=', $weekAgo)
            ->count();
        $stuckCount = Run::where('status', RunStatus::Stuck)->count();

        $successDenominator = $completedWeek + $failedWeek;
        $health = [
            'throughput_per_hour' => round($completedDay / 24, 1),
            'success_rate_pct' => $successDenominator > 0
                ? round($completedWeek / $successDenominator * 100, 1)
                : null,
            'active_agents' => Stage::where('status', StageStatus::Running)->count(),
            'shipped_total' => Run::where('status', RunStatus::Completed)->count(),
        ];

        $stuckRuns = Run::with('issue.source')
            ->where('status', RunStatus::Stuck)
            ->orderByDesc('updated_at')
            ->get();

        return view('activity.index', compact('events', 'sources', 'health', 'stuckCount', 'stuckRuns'));
    }
}
