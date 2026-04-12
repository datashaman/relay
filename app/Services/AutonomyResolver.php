<?php

namespace App\Services;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\StageName;
use App\Models\AutonomyConfig;
use Illuminate\Validation\ValidationException;

class AutonomyResolver
{
    public function resolve(int $issueId, StageName $stage): AutonomyLevel
    {
        $global = $this->getGlobalDefault();

        $stageConfig = AutonomyConfig::where('scope', AutonomyScope::Stage)
            ->whereNull('scope_id')
            ->where('stage', $stage)
            ->first();

        $effectiveStageLevel = $stageConfig?->level ?? $global;

        $issueStageConfig = AutonomyConfig::where('scope', AutonomyScope::Issue)
            ->where('scope_id', $issueId)
            ->where('stage', $stage)
            ->first();

        if ($issueStageConfig) {
            return $issueStageConfig->level;
        }

        $issueGlobalConfig = AutonomyConfig::where('scope', AutonomyScope::Issue)
            ->where('scope_id', $issueId)
            ->whereNull('stage')
            ->first();

        return $issueGlobalConfig?->level ?? $effectiveStageLevel;
    }

    public function getGlobalDefault(): AutonomyLevel
    {
        $config = AutonomyConfig::where('scope', AutonomyScope::Global)
            ->whereNull('scope_id')
            ->whereNull('stage')
            ->first();

        return $config?->level ?? AutonomyLevel::Supervised;
    }

    public function validateAndSave(
        AutonomyScope $scope,
        ?int $scopeId,
        ?StageName $stage,
        AutonomyLevel $level,
    ): AutonomyConfig {
        $this->validateInvariant($scope, $scopeId, $stage, $level);

        return AutonomyConfig::updateOrCreate(
            [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'stage' => $stage,
            ],
            [
                'level' => $level,
            ],
        );
    }

    public function validateInvariant(
        AutonomyScope $scope,
        ?int $scopeId,
        ?StageName $stage,
        AutonomyLevel $level,
    ): void {
        match ($scope) {
            AutonomyScope::Global => null,
            AutonomyScope::Stage => $this->validateStageTightensFromGlobal($level),
            AutonomyScope::Issue => $this->validateIssueLoosenFromStage($scopeId, $stage, $level),
        };
    }

    private function validateStageTightensFromGlobal(AutonomyLevel $level): void
    {
        $global = $this->getGlobalDefault();

        if (! $level->isTighterThanOrEqual($global)) {
            throw ValidationException::withMessages([
                'level' => "Stage override must tighten from global level ({$global->value}). {$level->value} is looser than {$global->value}.",
            ]);
        }
    }

    private function validateIssueLoosenFromStage(?int $scopeId, ?StageName $stage, AutonomyLevel $level): void
    {
        if ($stage) {
            $effectiveStageLevel = $this->getEffectiveStageLevel($stage);
        } else {
            $tightestStageLevel = $this->getTightestStageLevel();
            $effectiveStageLevel = $tightestStageLevel;
        }

        if (! $level->isLooserThanOrEqual($effectiveStageLevel)) {
            throw ValidationException::withMessages([
                'level' => "Issue override must loosen from stage level ({$effectiveStageLevel->value}). {$level->value} is tighter than {$effectiveStageLevel->value}.",
            ]);
        }
    }

    private function getEffectiveStageLevel(StageName $stage): AutonomyLevel
    {
        $stageConfig = AutonomyConfig::where('scope', AutonomyScope::Stage)
            ->whereNull('scope_id')
            ->where('stage', $stage)
            ->first();

        return $stageConfig?->level ?? $this->getGlobalDefault();
    }

    private function getTightestStageLevel(): AutonomyLevel
    {
        $global = $this->getGlobalDefault();
        $tightest = $global;

        foreach (StageName::cases() as $stage) {
            $effective = $this->getEffectiveStageLevel($stage);
            if ($effective->isTighterThanOrEqual($tightest)) {
                $tightest = $effective;
            }
        }

        return $tightest;
    }
}
