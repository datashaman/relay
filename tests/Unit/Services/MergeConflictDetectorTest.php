<?php

namespace Tests\Unit\Services;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Services\MergeConflictDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pure-unit coverage for MergeConflictDetector.
 *
 * The detector is Process- and DB-heavy: probe() shells out to git and mutates
 * Run/StageEvent rows. tests/Feature/MergeConflictDetectorTest.php covers the
 * full orchestration end-to-end via Process::fake and RefreshDatabase.
 *
 * The Phase 01 inspection flagged extracting parseConflictFilesFromOutput() as
 * a pure helper as a refactor-requiring-user-approval — we followed the same
 * precedent as AutonomyResolver and did not refactor. That leaves two pure
 * surfaces worth locking down here:
 *   - the OUTCOME_* constant values (public contract callers branch on)
 *   - isProbeable() status gating (via reflection; no DB needed because we
 *     only set the enum-cast attribute on an unsaved Run model)
 */
class MergeConflictDetectorTest extends TestCase
{
    private MergeConflictDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new MergeConflictDetector;
    }

    public function test_outcome_constants_have_stable_string_values(): void
    {
        $this->assertSame('clean', MergeConflictDetector::OUTCOME_CLEAN);
        $this->assertSame('conflict', MergeConflictDetector::OUTCOME_CONFLICT);
        $this->assertSame('skipped', MergeConflictDetector::OUTCOME_SKIPPED);
        $this->assertSame('error', MergeConflictDetector::OUTCOME_ERROR);
    }

    public function test_outcome_constants_are_distinct(): void
    {
        $values = [
            MergeConflictDetector::OUTCOME_CLEAN,
            MergeConflictDetector::OUTCOME_CONFLICT,
            MergeConflictDetector::OUTCOME_SKIPPED,
            MergeConflictDetector::OUTCOME_ERROR,
        ];
        $this->assertSame($values, array_values(array_unique($values)));
    }

    #[DataProvider('probeableStatusProvider')]
    public function test_is_probeable_for_each_run_status(?RunStatus $status, bool $expected): void
    {
        $run = new Run;
        $run->setAttribute('status', $status);

        $method = new ReflectionMethod(MergeConflictDetector::class, 'isProbeable');

        $this->assertSame($expected, $method->invoke($this->detector, $run));
    }

    /**
     * @return iterable<string, array{0: ?RunStatus, 1: bool}>
     */
    public static function probeableStatusProvider(): iterable
    {
        yield 'pending is probeable' => [RunStatus::Pending, true];
        yield 'running is probeable' => [RunStatus::Running, true];
        yield 'stuck is probeable' => [RunStatus::Stuck, true];
        yield 'completed is not probeable' => [RunStatus::Completed, false];
        yield 'failed is not probeable' => [RunStatus::Failed, false];
        yield 'null status is not probeable' => [null, false];
    }

    public function test_is_probeable_covers_every_run_status_case(): void
    {
        // Guard: if a new RunStatus case is added, this test forces a review
        // of whether probe() should gate on it.
        $known = [
            RunStatus::Pending->value,
            RunStatus::Running->value,
            RunStatus::Stuck->value,
            RunStatus::Completed->value,
            RunStatus::Failed->value,
        ];
        $actual = array_map(fn (RunStatus $s) => $s->value, RunStatus::cases());

        sort($known);
        sort($actual);

        $this->assertSame($known, $actual, 'RunStatus cases changed — update isProbeable tests.');
    }
}
