<?php

namespace App\Http\Controllers;

use App\Enums\StageName;
use App\Models\Source;
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

        return view('activity.index', compact('events', 'sources'));
    }
}
