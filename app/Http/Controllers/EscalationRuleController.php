<?php

namespace App\Http\Controllers;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Models\EscalationRule;
use Illuminate\Http\Request;

class EscalationRuleController extends Controller
{
    public function index()
    {
        $rules = EscalationRule::orderBy('order')->get();

        return view('escalation-rules.index', compact('rules'));
    }

    public function create()
    {
        return view('escalation-rules.form', [
            'rule' => null,
            'levels' => AutonomyLevel::cases(),
        ]);
    }

    public function store(Request $request)
    {
        $this->validateRule($request);

        $maxOrder = EscalationRule::max('order') ?? -1;

        EscalationRule::create([
            'name' => $request->name,
            'condition' => $this->buildCondition($request),
            'target_level' => $request->target_level,
            'scope' => AutonomyScope::Global,
            'order' => $maxOrder + 1,
        ]);

        return redirect()->route('escalation-rules.index')
            ->with('success', 'Escalation rule created.');
    }

    public function edit(EscalationRule $escalationRule)
    {
        return view('escalation-rules.form', [
            'rule' => $escalationRule,
            'levels' => AutonomyLevel::cases(),
        ]);
    }

    public function update(Request $request, EscalationRule $escalationRule)
    {
        $this->validateRule($request);

        $escalationRule->update([
            'name' => $request->name,
            'condition' => $this->buildCondition($request),
            'target_level' => $request->target_level,
        ]);

        return redirect()->route('escalation-rules.index')
            ->with('success', 'Escalation rule updated.');
    }

    public function destroy(EscalationRule $escalationRule)
    {
        $escalationRule->delete();

        return redirect()->route('escalation-rules.index')
            ->with('success', 'Escalation rule deleted.');
    }

    public function toggleEnabled(EscalationRule $escalationRule)
    {
        $escalationRule->update(['is_enabled' => ! $escalationRule->is_enabled]);

        $status = $escalationRule->is_enabled ? 'enabled' : 'disabled';

        return redirect()->route('escalation-rules.index')
            ->with('success', "Rule \"{$escalationRule->name}\" {$status}.");
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer|exists:escalation_rules,id',
        ]);

        foreach ($request->ids as $index => $id) {
            EscalationRule::where('id', $id)->update(['order' => $index]);
        }

        return redirect()->route('escalation-rules.index')
            ->with('success', 'Rules reordered.');
    }

    public function moveUp(EscalationRule $escalationRule)
    {
        $swapWith = EscalationRule::where('order', '<', $escalationRule->order)
            ->orderByDesc('order')
            ->first();

        if ($swapWith) {
            $tempOrder = $escalationRule->order;
            $escalationRule->update(['order' => $swapWith->order]);
            $swapWith->update(['order' => $tempOrder]);
        }

        return redirect()->route('escalation-rules.index');
    }

    public function moveDown(EscalationRule $escalationRule)
    {
        $swapWith = EscalationRule::where('order', '>', $escalationRule->order)
            ->orderBy('order')
            ->first();

        if ($swapWith) {
            $tempOrder = $escalationRule->order;
            $escalationRule->update(['order' => $swapWith->order]);
            $swapWith->update(['order' => $tempOrder]);
        }

        return redirect()->route('escalation-rules.index');
    }

    private function validateRule(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'condition_type' => 'required|in:label_match,file_path_match,diff_size,touched_directory_match',
            'condition_value' => 'required|string|max:255',
            'target_level' => 'required|in:'.implode(',', array_map(fn ($l) => $l->value, AutonomyLevel::cases())),
        ]);
    }

    private function buildCondition(Request $request): array
    {
        return [
            'type' => $request->condition_type,
            'value' => $request->condition_value,
        ];
    }
}
