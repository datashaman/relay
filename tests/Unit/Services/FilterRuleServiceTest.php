<?php

namespace Tests\Unit\Services;

use App\Enums\IssueStatus;
use App\Models\FilterRule;
use App\Models\Source;
use App\Services\FilterRuleService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit coverage for FilterRuleService.
 *
 * Feature tests/Feature/FilterRuleServiceTest.php covers the Eloquent-backed
 * applyToSync() path (with RefreshDatabase). This test exercises the pure
 * decision methods — matchesFilters, isAutoAccepted, validateNoConflict, and
 * evaluate() with in-memory models — without booting the Laravel app or
 * hitting the database.
 */
class FilterRuleServiceTest extends TestCase
{
    private FilterRuleService $service;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // validateNoConflict() calls ValidationException::withMessages(), which
        // resolves the 'validator' binding through the Facade root. Bootstrap
        // just enough container so the facade works without a full Laravel app.
        if (Facade::getFacadeApplication() === null) {
            $container = new Container;
            $translator = new Translator(new ArrayLoader, 'en');
            $container->instance('validator', new ValidationFactory($translator, $container));
            Facade::setFacadeApplication($container);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FilterRuleService;
    }

    private function makeRule(array $attributes = []): FilterRule
    {
        return new FilterRule(array_merge([
            'include_labels' => [],
            'exclude_labels' => [],
            'auto_accept_labels' => [],
            'unassigned_only' => false,
        ], $attributes));
    }

    private function makeSource(?FilterRule $rule = null, int $id = 1): Source
    {
        $source = new Source(['name' => 'test-source']);
        $source->id = $id;
        $source->setRelation('filterRule', $rule);

        return $source;
    }

    private function issueData(array $overrides = []): array
    {
        return array_merge([
            'external_id' => 'GH-123',
            'title' => 'Fix the widget',
            'body' => 'The widget is broken',
            'external_url' => 'https://github.com/org/repo/issues/123',
            'assignee' => null,
            'labels' => [],
            'raw_status' => 'open',
            'repository_id' => 7,
            'component_id' => null,
        ], $overrides);
    }

    // ---- matchesFilters ----------------------------------------------------

    public function test_matches_filters_empty_rule_passes(): void
    {
        $this->assertTrue($this->service->matchesFilters(
            $this->issueData(['labels' => ['anything']]),
            $this->makeRule(),
        ));
    }

    public function test_matches_filters_include_labels_requires_intersection(): void
    {
        $rule = $this->makeRule(['include_labels' => ['bug', 'critical']]);

        $this->assertTrue($this->service->matchesFilters(
            $this->issueData(['labels' => ['bug', 'enhancement']]),
            $rule,
        ));
        $this->assertFalse($this->service->matchesFilters(
            $this->issueData(['labels' => ['enhancement']]),
            $rule,
        ));
    }

    public function test_matches_filters_include_rejects_issue_with_no_labels(): void
    {
        $rule = $this->makeRule(['include_labels' => ['bug']]);

        $this->assertFalse($this->service->matchesFilters(
            $this->issueData(['labels' => []]),
            $rule,
        ));
    }

    public function test_matches_filters_exclude_labels_rejects_any_match(): void
    {
        $rule = $this->makeRule(['exclude_labels' => ['wontfix', 'duplicate']]);

        $this->assertFalse($this->service->matchesFilters(
            $this->issueData(['labels' => ['bug', 'wontfix']]),
            $rule,
        ));
        $this->assertTrue($this->service->matchesFilters(
            $this->issueData(['labels' => ['bug']]),
            $rule,
        ));
    }

    public function test_matches_filters_is_case_insensitive_on_both_sides(): void
    {
        $rule = $this->makeRule([
            'include_labels' => ['Bug'],
            'exclude_labels' => ['WONTFIX'],
        ]);

        $this->assertTrue($this->service->matchesFilters(
            $this->issueData(['labels' => ['BUG']]),
            $rule,
        ));
        $this->assertFalse($this->service->matchesFilters(
            $this->issueData(['labels' => ['bug', 'wontfix']]),
            $rule,
        ));
    }

    public function test_matches_filters_unassigned_only_rejects_assigned(): void
    {
        $rule = $this->makeRule(['unassigned_only' => true]);

        $this->assertFalse($this->service->matchesFilters(
            $this->issueData(['assignee' => 'octocat']),
            $rule,
        ));
        $this->assertTrue($this->service->matchesFilters(
            $this->issueData(['assignee' => null]),
            $rule,
        ));
    }

    public function test_matches_filters_unassigned_only_ignores_when_false(): void
    {
        $rule = $this->makeRule(['unassigned_only' => false]);

        $this->assertTrue($this->service->matchesFilters(
            $this->issueData(['assignee' => 'octocat']),
            $rule,
        ));
    }

    public function test_matches_filters_combined_include_and_unassigned(): void
    {
        $rule = $this->makeRule([
            'include_labels' => ['bug'],
            'unassigned_only' => true,
        ]);

        $this->assertFalse($this->service->matchesFilters(
            $this->issueData(['labels' => ['bug'], 'assignee' => 'octocat']),
            $rule,
        ));
        $this->assertTrue($this->service->matchesFilters(
            $this->issueData(['labels' => ['bug'], 'assignee' => null]),
            $rule,
        ));
    }

    public function test_matches_filters_missing_labels_key_is_treated_as_empty(): void
    {
        $issue = $this->issueData();
        unset($issue['labels']);

        $this->assertTrue($this->service->matchesFilters($issue, $this->makeRule()));
        $this->assertFalse($this->service->matchesFilters(
            $issue,
            $this->makeRule(['include_labels' => ['bug']]),
        ));
    }

    // ---- isAutoAccepted ----------------------------------------------------

    public function test_is_auto_accepted_empty_rule_returns_false(): void
    {
        $this->assertFalse($this->service->isAutoAccepted(
            $this->issueData(['labels' => ['relay-auto']]),
            $this->makeRule(),
        ));
    }

    public function test_is_auto_accepted_matching_label_returns_true(): void
    {
        $rule = $this->makeRule(['auto_accept_labels' => ['relay-auto']]);

        $this->assertTrue($this->service->isAutoAccepted(
            $this->issueData(['labels' => ['relay-auto', 'bug']]),
            $rule,
        ));
    }

    public function test_is_auto_accepted_non_matching_label_returns_false(): void
    {
        $rule = $this->makeRule(['auto_accept_labels' => ['relay-auto']]);

        $this->assertFalse($this->service->isAutoAccepted(
            $this->issueData(['labels' => ['bug']]),
            $rule,
        ));
    }

    public function test_is_auto_accepted_is_case_insensitive(): void
    {
        $rule = $this->makeRule(['auto_accept_labels' => ['RELAY-AUTO']]);

        $this->assertTrue($this->service->isAutoAccepted(
            $this->issueData(['labels' => ['relay-auto']]),
            $rule,
        ));
    }

    public function test_is_auto_accepted_missing_labels_key_returns_false(): void
    {
        $rule = $this->makeRule(['auto_accept_labels' => ['relay-auto']]);
        $issue = $this->issueData();
        unset($issue['labels']);

        $this->assertFalse($this->service->isAutoAccepted($issue, $rule));
    }

    // ---- validateNoConflict -----------------------------------------------

    public function test_validate_no_conflict_passes_disjoint_labels(): void
    {
        FilterRuleService::validateNoConflict(['bug', 'critical'], ['wontfix', 'duplicate']);
        $this->assertTrue(true); // reaching here means no exception
    }

    public function test_validate_no_conflict_passes_two_empty_arrays(): void
    {
        FilterRuleService::validateNoConflict([], []);
        $this->assertTrue(true);
    }

    public function test_validate_no_conflict_rejects_overlap(): void
    {
        try {
            FilterRuleService::validateNoConflict(['bug', 'critical'], ['critical', 'wontfix']);
            $this->fail('ValidationException was not thrown');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('exclude_labels', $e->errors());
            $this->assertStringContainsString('critical', $e->errors()['exclude_labels'][0]);
        }
    }

    public function test_validate_no_conflict_is_case_insensitive(): void
    {
        $this->expectException(ValidationException::class);

        FilterRuleService::validateNoConflict(['Bug'], ['BUG']);
    }

    public function test_validate_no_conflict_lists_all_overlapping_labels_in_message(): void
    {
        try {
            FilterRuleService::validateNoConflict(['bug', 'critical', 'wontfix'], ['critical', 'wontfix']);
            $this->fail('ValidationException was not thrown');
        } catch (ValidationException $e) {
            $message = $e->errors()['exclude_labels'][0];
            $this->assertStringContainsString('critical', $message);
            $this->assertStringContainsString('wontfix', $message);
        }
    }

    // ---- evaluate ---------------------------------------------------------

    public function test_evaluate_without_filter_rule_returns_queued_attributes(): void
    {
        $source = $this->makeSource(null, id: 42);

        $result = $this->service->evaluate($this->issueData(), $source);

        $this->assertIsArray($result);
        $this->assertSame(42, $result['source_id']);
        $this->assertSame('GH-123', $result['external_id']);
        $this->assertSame(IssueStatus::Queued, $result['status']);
        $this->assertFalse($result['auto_accepted']);
    }

    public function test_evaluate_builds_full_attribute_set(): void
    {
        $source = $this->makeSource(null, id: 9);

        $result = $this->service->evaluate($this->issueData([
            'labels' => ['bug', 'priority'],
            'assignee' => 'octocat',
            'component_id' => 3,
        ]), $source);

        $expectedKeys = [
            'source_id', 'external_id', 'title', 'body', 'external_url',
            'assignee', 'labels', 'status', 'raw_status', 'auto_accepted',
            'repository_id', 'component_id',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $result, "missing key: {$key}");
        }
        $this->assertSame(['bug', 'priority'], $result['labels']);
        $this->assertSame('octocat', $result['assignee']);
        $this->assertSame(3, $result['component_id']);
    }

    public function test_evaluate_returns_null_when_rule_filters_issue_out(): void
    {
        $source = $this->makeSource($this->makeRule(['include_labels' => ['bug']]));

        $this->assertNull($this->service->evaluate(
            $this->issueData(['labels' => ['enhancement']]),
            $source,
        ));
    }

    public function test_evaluate_returns_queued_when_rule_matches_without_auto_accept(): void
    {
        $source = $this->makeSource($this->makeRule(['include_labels' => ['bug']]));

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['bug']]),
            $source,
        );

        $this->assertIsArray($result);
        $this->assertSame(IssueStatus::Queued, $result['status']);
        $this->assertFalse($result['auto_accepted']);
    }

    public function test_evaluate_returns_accepted_when_auto_accept_label_matches(): void
    {
        $source = $this->makeSource($this->makeRule([
            'auto_accept_labels' => ['relay-auto'],
        ]));

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['relay-auto', 'bug']]),
            $source,
        );

        $this->assertIsArray($result);
        $this->assertSame(IssueStatus::Accepted, $result['status']);
        $this->assertTrue($result['auto_accepted']);
    }

    public function test_evaluate_auto_accept_still_requires_match_against_include_filter(): void
    {
        $source = $this->makeSource($this->makeRule([
            'include_labels' => ['bug'],
            'auto_accept_labels' => ['relay-auto'],
        ]));

        $this->assertNull($this->service->evaluate(
            $this->issueData(['labels' => ['relay-auto']]),
            $source,
        ));

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['bug', 'relay-auto']]),
            $source,
        );
        $this->assertIsArray($result);
        $this->assertTrue($result['auto_accepted']);
    }

    public function test_evaluate_defaults_optional_fields_to_null_or_empty(): void
    {
        $source = $this->makeSource(null);

        $result = $this->service->evaluate([
            'external_id' => 'GH-1',
            'title' => 'Bare issue',
        ], $source);

        $this->assertSame('Bare issue', $result['title']);
        $this->assertNull($result['body']);
        $this->assertNull($result['external_url']);
        $this->assertNull($result['assignee']);
        $this->assertSame([], $result['labels']);
        $this->assertNull($result['raw_status']);
        $this->assertNull($result['repository_id']);
        $this->assertNull($result['component_id']);
    }

    // ---- regression: label comparison edge cases --------------------------

    #[DataProvider('provideMixedCaseLabelPairs')]
    public function test_label_matching_case_insensitive_matrix(
        string $ruleLabel,
        string $issueLabel,
        bool $shouldMatch,
    ): void {
        $rule = $this->makeRule(['include_labels' => [$ruleLabel]]);

        $this->assertSame(
            $shouldMatch,
            $this->service->matchesFilters(
                $this->issueData(['labels' => [$issueLabel]]),
                $rule,
            ),
        );
    }

    public static function provideMixedCaseLabelPairs(): array
    {
        return [
            'identical lowercase' => ['bug', 'bug', true],
            'identical uppercase' => ['BUG', 'BUG', true],
            'rule upper, issue lower' => ['BUG', 'bug', true],
            'rule lower, issue upper' => ['bug', 'BUG', true],
            'mixed case' => ['Bug', 'bUg', true],
            'different label' => ['bug', 'enhancement', false],
            'substring is not match' => ['bug', 'bugs', false],
        ];
    }
}
