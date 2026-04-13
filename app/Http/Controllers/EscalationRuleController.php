<?php

namespace App\Http\Controllers;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Models\EscalationRule;
use Illuminate\Http\Request;

class EscalationRuleController extends Controller
{
    public function store(Request $request)
    {
        $this->validateRule($request);

        $maxOrder = EscalationRule::max('order') ?? -1;

        $rule = EscalationRule::create([
            'name' => $request->name,
            'condition' => $this->buildCondition($request),
            'target_level' => $request->target_level,
            'scope' => AutonomyScope::Global,
            'order' => $maxOrder + 1,
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'id' => $rule->id,
                'html' => view('escalation-rules._card', ['rule' => $rule])->render(),
            ]);
        }

        return redirect()->route('config.index')
            ->with('success', 'Escalation rule created.');
    }

    public function update(Request $request, EscalationRule $escalationRule)
    {
        $this->validateRule($request);

        $escalationRule->update([
            'name' => $request->name,
            'condition' => $this->buildCondition($request),
            'target_level' => $request->target_level,
        ]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'id' => $escalationRule->id,
                'html' => view('escalation-rules._card', ['rule' => $escalationRule])->render(),
            ]);
        }

        return redirect()->route('config.index')
            ->with('success', 'Escalation rule updated.');
    }

    public function destroy(Request $request, EscalationRule $escalationRule)
    {
        $escalationRule->delete();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'deleted' => true]);
        }

        return redirect()->route('config.index')
            ->with('success', 'Escalation rule deleted.');
    }

    public function toggleEnabled(Request $request, EscalationRule $escalationRule)
    {
        $escalationRule->update(['is_enabled' => ! $escalationRule->is_enabled]);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true, 'enabled' => $escalationRule->is_enabled]);
        }

        $status = $escalationRule->is_enabled ? 'enabled' : 'disabled';

        return redirect()->route('config.index')
            ->with('success', "Rule \"{$escalationRule->name}\" {$status}.");
    }

    public function moveUp(Request $request, EscalationRule $escalationRule)
    {
        $swapWith = EscalationRule::where('order', '<', $escalationRule->order)
            ->orderByDesc('order')
            ->first();

        if ($swapWith) {
            $tempOrder = $escalationRule->order;
            $escalationRule->update(['order' => $swapWith->order]);
            $swapWith->update(['order' => $tempOrder]);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('config.index');
    }

    public function moveDown(Request $request, EscalationRule $escalationRule)
    {
        $swapWith = EscalationRule::where('order', '>', $escalationRule->order)
            ->orderBy('order')
            ->first();

        if ($swapWith) {
            $tempOrder = $escalationRule->order;
            $escalationRule->update(['order' => $swapWith->order]);
            $swapWith->update(['order' => $tempOrder]);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('config.index');
    }

    private function validateRule(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'condition_type' => 'required|in:label_match,file_path_match,diff_size,touched_directory_match',
            'condition_operator' => 'nullable|in:>,>=,<,<=,=,~',
            'condition_value' => 'required|string|max:255',
            'target_level' => 'required|in:'.implode(',', array_map(fn ($l) => $l->value, AutonomyLevel::cases())),
        ]);
    }

    private function buildCondition(Request $request): array
    {
        $type = $request->condition_type;
        $value = $request->condition_value;

        $operator = match ($type) {
            'diff_size' => $request->condition_operator ?: '>=',
            'label_match', 'file_path_match', 'touched_directory_match' => '~',
            default => '=',
        };

        if ($type === 'diff_size') {
            $value = (string) (int) $value;
        }

        return compact('type', 'operator', 'value');
    }
}
