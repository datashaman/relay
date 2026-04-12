<?php

namespace App\Http\Controllers;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Source;
use Illuminate\View\View;

class IntakeController extends Controller
{
    public function index(): View
    {
        $sources = Source::with('filterRule')->orderBy('name')->get();

        $pausedCount = $sources->where('is_intake_paused', true)->count();
        $connectedCount = $sources->where('is_active', true)->count();

        $incoming = Issue::with('source')
            ->where('status', IssueStatus::Queued)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        $pendingCount = Issue::where('status', IssueStatus::Queued)->count();

        return view('intake.index', compact(
            'sources',
            'pausedCount',
            'connectedCount',
            'incoming',
            'pendingCount',
        ));
    }
}
