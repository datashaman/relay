<?php

namespace App\Http\Controllers;

use App\Enums\StageName;
use App\Models\Run;
use Illuminate\Http\JsonResponse;

class RunProgressController extends Controller
{
    public function show(Run $run): JsonResponse
    {
        $run->load([
            'stages' => fn ($q) => $q->orderBy('id'),
            'stages.events' => fn ($q) => $q->orderBy('created_at'),
        ]);

        $currentStage = $run->stages->last();

        $stages = $run->stages->map(fn ($stage) => [
            'id' => $stage->id,
            'name' => $stage->name->value,
            'status' => $stage->status->value,
            'iteration' => $stage->iteration,
        ])->values();

        $liveData = [];

        if ($currentStage) {
            $liveData['current_stage'] = $currentStage->name->value;
            $liveData['current_status'] = $currentStage->status->value;

            $liveData['tool_calls'] = $currentStage->events
                ->where('type', 'tool_call')
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'actor' => $e->actor,
                    'tool' => $e->payload['tool'] ?? null,
                    'path' => $e->payload['path'] ?? null,
                    'count' => $e->payload['count'] ?? null,
                    'mode' => $e->payload['mode'] ?? null,
                    'timestamp' => $e->created_at->format('H:i:s'),
                ])
                ->values();

            if ($currentStage->name === StageName::Implement) {
                $diffEvent = $currentStage->events
                    ->whereIn('type', ['diff_updated', 'implement_complete'])
                    ->last();
                if ($diffEvent) {
                    $liveData['diff'] = $diffEvent->payload['diff'] ?? null;
                    $liveData['changed_files'] = $diffEvent->payload['files_changed'] ?? $diffEvent->payload['changed_files'] ?? [];
                    $liveData['implement_summary'] = $diffEvent->payload['summary'] ?? null;
                }
            }

            if ($currentStage->name === StageName::Verify) {
                $testEvent = $currentStage->events
                    ->whereIn('type', ['test_result_updated', 'verify_complete'])
                    ->last();
                if ($testEvent) {
                    $liveData['test_output'] = $testEvent->payload['output'] ?? $testEvent->payload['summary'] ?? null;
                    $liveData['test_status'] = $testEvent->payload['status'] ?? ($testEvent->payload['passed'] ?? false ? 'passed' : 'failed');
                }
            }

            if ($currentStage->name === StageName::Release) {
                $releaseEvents = $currentStage->events
                    ->whereIn('type', ['release_progress_updated', 'release_complete']);
                $releaseSteps = [];
                foreach ($releaseEvents as $event) {
                    $releaseSteps[] = [
                        'step' => $event->payload['step'] ?? $event->type,
                        'detail' => $event->payload['detail'] ?? $event->payload['summary'] ?? '',
                        'pr_url' => $event->payload['pr_url'] ?? null,
                    ];
                }
                $liveData['release_steps'] = $releaseSteps;
                $liveData['pr_url'] = collect($releaseSteps)->pluck('pr_url')->filter()->last();
            }
        }

        return response()->json([
            'run_id' => $run->id,
            'run_status' => $run->status->value,
            'stuck_state' => $run->stuck_state?->value,
            'iteration' => $run->iteration,
            'stages' => $stages,
            'live' => $liveData,
        ]);
    }
}
