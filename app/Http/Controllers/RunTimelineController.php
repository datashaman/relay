<?php

namespace App\Http\Controllers;

use App\Models\Run;
use Illuminate\View\View;

class RunTimelineController extends Controller
{
    public function show(Run $run): View
    {
        $run->load([
            'issue.source',
            'stages' => fn ($q) => $q->orderBy('id'),
            'stages.events' => fn ($q) => $q->orderBy('created_at'),
        ]);

        $iterations = $this->groupByIteration($run);

        $prUrl = $this->findPrUrl($run);

        return view('runs.timeline', compact('run', 'iterations', 'prUrl'));
    }

    private function groupByIteration(Run $run): array
    {
        $iterations = [];
        $currentIteration = 1;
        $currentStages = [];

        foreach ($run->stages as $stage) {
            $iterationKey = $stage->iteration ?? $currentIteration;

            if (! isset($iterations[$iterationKey])) {
                $iterations[$iterationKey] = [];
            }

            $iterations[$iterationKey][] = $stage;
        }

        if (empty($iterations)) {
            $iterations[1] = [];
        }

        ksort($iterations);

        return $iterations;
    }

    private function findPrUrl(Run $run): ?string
    {
        foreach ($run->stages as $stage) {
            foreach ($stage->events as $event) {
                if ($event->type === 'release_complete' && ! empty($event->payload['pr_url'])) {
                    return $event->payload['pr_url'];
                }
            }
        }

        return null;
    }
}
