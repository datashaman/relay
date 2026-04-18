<?php

namespace Tests\Unit\Services;

use App\Enums\AutonomyLevel;
use App\Enums\AutonomyScope;
use App\Enums\StageName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit coverage for the decision logic that drives AutonomyResolver.
 *
 * The resolver itself calls AutonomyConfig Eloquent directly with no injected
 * collaborators, so its methods cannot be exercised in isolation without a
 * repository-pattern refactor (flagged in Phase 01 inspection notes). Feature
 * test tests/Feature/AutonomyResolverTest.php covers the DB-backed paths.
 *
 * This test exhaustively covers the pure pieces every resolver decision is
 * built on: AutonomyLevel ordering, tighter/looser comparisons, and the
 * AutonomyScope / StageName enum shapes.
 */
class AutonomyResolverTest extends TestCase
{
    public function test_autonomy_level_has_exactly_four_cases(): void
    {
        $this->assertCount(4, AutonomyLevel::cases());
    }

    public function test_autonomy_level_string_values(): void
    {
        $this->assertSame('manual', AutonomyLevel::Manual->value);
        $this->assertSame('supervised', AutonomyLevel::Supervised->value);
        $this->assertSame('assisted', AutonomyLevel::Assisted->value);
        $this->assertSame('autonomous', AutonomyLevel::Autonomous->value);
    }

    public function test_autonomy_level_order_is_monotonic(): void
    {
        $this->assertSame(0, AutonomyLevel::Manual->order());
        $this->assertSame(1, AutonomyLevel::Supervised->order());
        $this->assertSame(2, AutonomyLevel::Assisted->order());
        $this->assertSame(3, AutonomyLevel::Autonomous->order());
    }

    public function test_autonomy_level_order_is_strictly_increasing(): void
    {
        $previous = -1;
        foreach (AutonomyLevel::cases() as $level) {
            $this->assertGreaterThan($previous, $level->order(), "{$level->value} should be strictly greater than previous");
            $previous = $level->order();
        }
    }

    #[DataProvider('provideTighterThanOrEqualPairs')]
    public function test_is_tighter_than_or_equal(AutonomyLevel $a, AutonomyLevel $b, bool $expected): void
    {
        $this->assertSame($expected, $a->isTighterThanOrEqual($b));
    }

    public static function provideTighterThanOrEqualPairs(): array
    {
        $cases = [];
        foreach (AutonomyLevel::cases() as $a) {
            foreach (AutonomyLevel::cases() as $b) {
                $expected = $a->order() <= $b->order();
                $cases["{$a->value}_tighter_or_equal_{$b->value}"] = [$a, $b, $expected];
            }
        }

        return $cases;
    }

    #[DataProvider('provideLooserThanOrEqualPairs')]
    public function test_is_looser_than_or_equal(AutonomyLevel $a, AutonomyLevel $b, bool $expected): void
    {
        $this->assertSame($expected, $a->isLooserThanOrEqual($b));
    }

    public static function provideLooserThanOrEqualPairs(): array
    {
        $cases = [];
        foreach (AutonomyLevel::cases() as $a) {
            foreach (AutonomyLevel::cases() as $b) {
                $expected = $a->order() >= $b->order();
                $cases["{$a->value}_looser_or_equal_{$b->value}"] = [$a, $b, $expected];
            }
        }

        return $cases;
    }

    public function test_reflexivity_tighter_and_looser_are_both_true_for_same_level(): void
    {
        foreach (AutonomyLevel::cases() as $level) {
            $this->assertTrue($level->isTighterThanOrEqual($level));
            $this->assertTrue($level->isLooserThanOrEqual($level));
        }
    }

    public function test_manual_is_tightest_and_autonomous_is_loosest(): void
    {
        foreach (AutonomyLevel::cases() as $level) {
            $this->assertTrue(
                AutonomyLevel::Manual->isTighterThanOrEqual($level),
                "Manual must be tighter than or equal to {$level->value}",
            );
            $this->assertTrue(
                AutonomyLevel::Autonomous->isLooserThanOrEqual($level),
                "Autonomous must be looser than or equal to {$level->value}",
            );
        }
    }

    public function test_tighter_and_looser_are_mutually_exclusive_except_for_equality(): void
    {
        foreach (AutonomyLevel::cases() as $a) {
            foreach (AutonomyLevel::cases() as $b) {
                $tighter = $a->isTighterThanOrEqual($b);
                $looser = $a->isLooserThanOrEqual($b);

                if ($a === $b) {
                    $this->assertTrue($tighter && $looser);
                } else {
                    $this->assertNotSame($tighter, $looser, "{$a->value} vs {$b->value}: exactly one of tighter/looser should hold for distinct levels");
                }
            }
        }
    }

    public function test_autonomy_scope_has_three_cases(): void
    {
        $this->assertCount(3, AutonomyScope::cases());
        $this->assertSame('global', AutonomyScope::Global->value);
        $this->assertSame('stage', AutonomyScope::Stage->value);
        $this->assertSame('issue', AutonomyScope::Issue->value);
    }

    public function test_stage_name_has_four_cases(): void
    {
        $this->assertCount(4, StageName::cases());
        $this->assertSame('preflight', StageName::Preflight->value);
        $this->assertSame('implement', StageName::Implement->value);
        $this->assertSame('verify', StageName::Verify->value);
        $this->assertSame('release', StageName::Release->value);
    }

    public function test_stage_override_tightening_invariant_semantics(): void
    {
        // A stage-level override must tighten from global. Given global=Assisted (order 2),
        // only levels with order <= 2 pass the isTighterThanOrEqual check.
        $global = AutonomyLevel::Assisted;

        $this->assertTrue(AutonomyLevel::Manual->isTighterThanOrEqual($global));
        $this->assertTrue(AutonomyLevel::Supervised->isTighterThanOrEqual($global));
        $this->assertTrue(AutonomyLevel::Assisted->isTighterThanOrEqual($global));
        $this->assertFalse(AutonomyLevel::Autonomous->isTighterThanOrEqual($global));
    }

    public function test_issue_override_loosening_invariant_semantics(): void
    {
        // An issue-level override must loosen from the effective stage level.
        // Given stage=Supervised (order 1), only levels with order >= 1 pass.
        $stage = AutonomyLevel::Supervised;

        $this->assertFalse(AutonomyLevel::Manual->isLooserThanOrEqual($stage));
        $this->assertTrue(AutonomyLevel::Supervised->isLooserThanOrEqual($stage));
        $this->assertTrue(AutonomyLevel::Assisted->isLooserThanOrEqual($stage));
        $this->assertTrue(AutonomyLevel::Autonomous->isLooserThanOrEqual($stage));
    }
}
