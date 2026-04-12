<?php

namespace App\Http\Controllers;

use App\Models\FilterRule;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FilterRuleController extends Controller
{
    public function edit(Source $source): View
    {
        $rule = $source->filterRule ?? new FilterRule([
            'source_id' => $source->id,
            'include_labels' => [],
            'exclude_labels' => [],
            'auto_accept_labels' => [],
            'unassigned_only' => false,
        ]);

        return view('intake.edit-rules', compact('source', 'rule'));
    }

    public function update(Source $source, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'include_labels' => ['nullable', 'string'],
            'exclude_labels' => ['nullable', 'string'],
            'auto_accept_labels' => ['nullable', 'string'],
            'unassigned_only' => ['nullable', 'boolean'],
        ]);

        $include = $this->parseLabels($data['include_labels'] ?? null);
        $exclude = $this->parseLabels($data['exclude_labels'] ?? null);

        $overlap = array_intersect($include, $exclude);
        if (! empty($overlap)) {
            return back()
                ->withInput()
                ->withErrors(['include_labels' => 'Labels cannot appear in both include and exclude: ' . implode(', ', $overlap)]);
        }

        $source->filterRule()->updateOrCreate(
            ['source_id' => $source->id],
            [
                'include_labels' => $include ?: null,
                'exclude_labels' => $exclude ?: null,
                'auto_accept_labels' => $this->parseLabels($data['auto_accept_labels'] ?? null) ?: null,
                'unassigned_only' => (bool) ($data['unassigned_only'] ?? false),
            ],
        );

        return redirect()->route('intake.index')
            ->with('success', "Intake rules saved for {$source->name}.");
    }

    private function parseLabels(?string $raw): array
    {
        if (! $raw) {
            return [];
        }
        return collect(explode(',', $raw))
            ->map(fn ($s) => trim($s))
            ->filter()
            ->values()
            ->all();
    }
}
