<?php

namespace App\Services;

use App\Enums\AutonomyLevel;
use App\Enums\StageName;
use App\Models\EscalationRule;
use App\Models\Issue;
use App\Models\Stage;
use App\Models\StageEvent;

class EscalationRuleService
{
    public function __construct(
        private AutonomyResolver $autonomyResolver,
    ) {}

    public function resolveWithEscalation(
        Issue $issue,
        StageName $stage,
        array $context = [],
        ?Stage $stageModel = null,
    ): AutonomyLevel {
        $baseLevel = $this->autonomyResolver->resolve($issue->id, $stage);
        $matched = $this->evaluateRules($issue, $context);

        if (empty($matched)) {
            return $baseLevel;
        }

        $tightest = $this->tightestTarget($matched);
        $forced = $tightest->isTighterThanOrEqual($baseLevel) ? $tightest : $baseLevel;

        if ($stageModel) {
            $this->recordEvent($stageModel, $matched, $forced);
        }

        return $forced;
    }

    public function evaluateRules(Issue $issue, array $context = []): array
    {
        $rules = EscalationRule::where('is_enabled', true)
            ->orderBy('order')
            ->get();

        $matched = [];

        foreach ($rules as $rule) {
            if ($this->matchesCondition($rule, $issue, $context)) {
                $matched[] = $rule;
            }
        }

        return $matched;
    }

    public function matchesCondition(EscalationRule $rule, Issue $issue, array $context = []): bool
    {
        $condition = $rule->condition;
        $type = $condition['type'] ?? null;

        return match ($type) {
            'label_match' => $this->matchLabel($condition, $issue),
            'file_path_match' => $this->matchFilePath($condition, $context),
            'diff_size' => $this->matchDiffSize($condition, $context),
            'touched_directory_match' => $this->matchTouchedDirectory($condition, $context),
            default => false,
        };
    }

    private function matchLabel(array $condition, Issue $issue): bool
    {
        $target = strtolower($condition['value'] ?? '');
        $labels = array_map('strtolower', $issue->labels ?? []);

        return in_array($target, $labels);
    }

    private function matchFilePath(array $condition, array $context): bool
    {
        $pattern = $condition['value'] ?? '';
        $files = $context['files'] ?? [];

        foreach ($files as $file) {
            if (fnmatch($pattern, $file)) {
                return true;
            }
        }

        return false;
    }

    private function matchDiffSize(array $condition, array $context): bool
    {
        $threshold = (int) ($condition['value'] ?? 0);
        $operator = $condition['operator'] ?? '>=';
        $diffSize = $context['diff_size'] ?? null;

        if ($diffSize === null) {
            return false;
        }

        return match ($operator) {
            '>' => $diffSize > $threshold,
            '>=' => $diffSize >= $threshold,
            '<' => $diffSize < $threshold,
            '<=' => $diffSize <= $threshold,
            '=' => $diffSize === $threshold,
            default => $diffSize >= $threshold,
        };
    }

    private function matchTouchedDirectory(array $condition, array $context): bool
    {
        $target = rtrim($condition['value'] ?? '', '/');
        $directories = $context['directories'] ?? [];

        foreach ($directories as $dir) {
            $dir = rtrim($dir, '/');
            if ($dir === $target || str_starts_with($dir, $target.'/')) {
                return true;
            }
        }

        return false;
    }

    private function tightestTarget(array $rules): AutonomyLevel
    {
        $tightest = $rules[0]->target_level;

        foreach ($rules as $rule) {
            if ($rule->target_level->isTighterThanOrEqual($tightest)) {
                $tightest = $rule->target_level;
            }
        }

        return $tightest;
    }

    private function recordEvent(Stage $stage, array $matched, AutonomyLevel $forcedLevel): void
    {
        StageEvent::create([
            'stage_id' => $stage->id,
            'type' => 'escalation_matched',
            'actor' => 'system',
            'payload' => [
                'matched_rules' => array_map(fn (EscalationRule $rule) => [
                    'id' => $rule->id,
                    'name' => $rule->name,
                    'condition' => $rule->condition,
                    'target_level' => $rule->target_level->value,
                ], $matched),
                'forced_level' => $forcedLevel->value,
            ],
        ]);
    }
}
