<?php

namespace App\Http\Controllers;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class IssueController extends Controller
{
    public function queue(Request $request): View
    {
        $query = Issue::with('source')
            ->whereIn('status', [IssueStatus::Queued, IssueStatus::Accepted]);

        if ($request->filled('source')) {
            $query->where('source_id', $request->input('source'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%");
            });
        }

        $issues = $query->orderByRaw("CASE WHEN status = 'queued' THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->get();

        $sources = Source::where('is_active', true)->orderBy('name')->get();

        $groupedIssues = $issues->groupBy('source_id');

        return view('issues.queue', compact('issues', 'sources', 'groupedIssues'));
    }

    public function accept(Issue $issue): RedirectResponse
    {
        if ($issue->status !== IssueStatus::Queued) {
            return redirect()->route('issues.queue')
                ->with('error', 'Only queued issues can be accepted.');
        }

        $issue->update(['status' => IssueStatus::Accepted]);

        return redirect()->route('issues.queue')
            ->with('success', "Issue \"{$issue->title}\" accepted into preflight.");
    }

    public function reject(Issue $issue): RedirectResponse
    {
        if ($issue->status !== IssueStatus::Queued) {
            return redirect()->route('issues.queue')
                ->with('error', 'Only queued issues can be rejected.');
        }

        $issue->update(['status' => IssueStatus::Rejected]);

        return redirect()->route('issues.queue')
            ->with('success', "Issue \"{$issue->title}\" rejected.");
    }

    public function togglePause(Source $source, Request $request): RedirectResponse
    {
        $paused = ! $source->is_intake_paused;

        $data = ['is_intake_paused' => $paused];

        if ($request->filled('backlog_threshold')) {
            $request->validate(['backlog_threshold' => 'integer|min:1|max:1000']);
            $data['backlog_threshold'] = $request->input('backlog_threshold');
        }

        $source->update($data);

        $status = $paused ? 'paused' : 'resumed';

        return redirect()->route('issues.queue')
            ->with('success', "Intake {$status} for {$source->external_account}.");
    }
}
