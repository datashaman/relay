<?php

namespace App\Http\Controllers;

use App\Enums\StageStatus;
use App\Models\Run;
use App\Services\OrchestratorService;
use Illuminate\Http\Request;

class PreflightController extends Controller
{
    public function show(Run $run)
    {
        $run->load('issue');
        $stage = $run->stages()
            ->where('name', 'preflight')
            ->where('status', StageStatus::AwaitingApproval)
            ->latest()
            ->first();

        if (! $stage) {
            return redirect()->route('issues.queue')
                ->with('error', 'No pending clarification for this run.');
        }

        return view('preflight.clarification', [
            'run' => $run,
            'stage' => $stage,
            'knownFacts' => $run->known_facts ?? [],
            'questions' => $run->clarification_questions ?? [],
        ]);
    }

    public function submitAnswers(Request $request, Run $run, OrchestratorService $orchestrator)
    {
        $stage = $run->stages()
            ->where('name', 'preflight')
            ->where('status', StageStatus::AwaitingApproval)
            ->latest()
            ->first();

        if (! $stage) {
            return redirect()->route('issues.queue')
                ->with('error', 'No pending clarification for this run.');
        }

        $questions = $run->clarification_questions ?? [];
        $answers = [];

        foreach ($questions as $question) {
            $key = 'answer_' . $question['id'];
            $value = $request->input($key);
            if ($value !== null && $value !== '') {
                $answers[$question['id']] = $value;
            }
        }

        $run->update(['clarification_answers' => $answers]);

        $orchestrator->resume($stage);

        return redirect()->route('issues.queue')
            ->with('success', "Answers submitted for \"{$run->issue->title}\". Preflight resuming.");
    }

    public function skipToDoc(Run $run, OrchestratorService $orchestrator)
    {
        $stage = $run->stages()
            ->where('name', 'preflight')
            ->where('status', StageStatus::AwaitingApproval)
            ->latest()
            ->first();

        if (! $stage) {
            return redirect()->route('issues.queue')
                ->with('error', 'No pending clarification for this run.');
        }

        $orchestrator->resume($stage, ['skip_to_doc' => true]);

        return redirect()->route('issues.queue')
            ->with('success', "Skipped to doc for \"{$run->issue->title}\". Preflight resuming.");
    }

    public function showDoc(Run $run)
    {
        $run->load('issue');

        if (! $run->preflight_doc) {
            return redirect()->route('issues.queue')
                ->with('error', 'No preflight doc generated for this run yet.');
        }

        return view('preflight.doc', [
            'run' => $run,
            'doc' => $run->preflight_doc,
            'history' => $run->preflight_doc_history ?? [],
        ]);
    }

    public function editDoc(Run $run)
    {
        $run->load('issue');

        if (! $run->preflight_doc) {
            return redirect()->route('issues.queue')
                ->with('error', 'No preflight doc generated for this run yet.');
        }

        return view('preflight.edit-doc', [
            'run' => $run,
            'doc' => $run->preflight_doc,
        ]);
    }

    public function updateDoc(Request $request, Run $run)
    {
        if (! $run->preflight_doc) {
            return redirect()->route('issues.queue')
                ->with('error', 'No preflight doc generated for this run yet.');
        }

        $request->validate([
            'preflight_doc' => 'required|string',
        ]);

        $history = $run->preflight_doc_history ?? [];
        $history[] = [
            'doc' => $run->preflight_doc,
            'created_at' => now()->toIso8601String(),
            'iteration' => $run->iteration,
        ];

        $run->update([
            'preflight_doc' => $request->input('preflight_doc'),
            'preflight_doc_history' => $history,
        ]);

        return redirect()->route('preflight.doc', $run)
            ->with('success', 'Preflight doc updated.');
    }
}
