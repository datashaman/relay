<?php

namespace Tests\Feature;

use App\Enums\IssueStatus;
use App\Models\FilterRule;
use App\Models\Issue;
use App\Models\Source;
use App\Services\FilterRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FilterRuleServiceTest extends TestCase
{
    use RefreshDatabase;

    private FilterRuleService $service;
    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FilterRuleService();
        $this->source = Source::factory()->create();
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
        ], $overrides);
    }

    public function test_no_filter_rule_passes_all_issues(): void
    {
        $result = $this->service->evaluate($this->issueData(), $this->source);

        $this->assertNotNull($result);
        $this->assertEquals(IssueStatus::Queued, $result['status']);
        $this->assertFalse($result['auto_accepted']);
    }

    public function test_include_labels_passes_matching_issue(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'include_labels' => ['bug', 'critical'],
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['bug', 'enhancement']]),
            $this->source->refresh(),
        );

        $this->assertNotNull($result);
        $this->assertEquals(IssueStatus::Queued, $result['status']);
    }

    public function test_include_labels_rejects_non_matching_issue(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'include_labels' => ['bug', 'critical'],
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['enhancement']]),
            $this->source->refresh(),
        );

        $this->assertNull($result);
    }

    public function test_exclude_labels_rejects_matching_issue(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'exclude_labels' => ['wontfix', 'duplicate'],
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['bug', 'wontfix']]),
            $this->source->refresh(),
        );

        $this->assertNull($result);
    }

    public function test_exclude_labels_passes_non_matching_issue(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'exclude_labels' => ['wontfix'],
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['bug']]),
            $this->source->refresh(),
        );

        $this->assertNotNull($result);
    }

    public function test_unassigned_only_rejects_assigned_issue(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'unassigned_only' => true,
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['assignee' => 'octocat']),
            $this->source->refresh(),
        );

        $this->assertNull($result);
    }

    public function test_unassigned_only_passes_unassigned_issue(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'unassigned_only' => true,
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['assignee' => null]),
            $this->source->refresh(),
        );

        $this->assertNotNull($result);
    }

    public function test_auto_accept_labels_set_accepted_status(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'auto_accept_labels' => ['relay-auto'],
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['relay-auto', 'bug']]),
            $this->source->refresh(),
        );

        $this->assertNotNull($result);
        $this->assertEquals(IssueStatus::Accepted, $result['status']);
        $this->assertTrue($result['auto_accepted']);
    }

    public function test_auto_accept_without_matching_label_queues(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'auto_accept_labels' => ['relay-auto'],
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['bug']]),
            $this->source->refresh(),
        );

        $this->assertNotNull($result);
        $this->assertEquals(IssueStatus::Queued, $result['status']);
        $this->assertFalse($result['auto_accepted']);
    }

    public function test_label_matching_is_case_insensitive(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'include_labels' => ['Bug'],
            'auto_accept_labels' => ['RELAY-AUTO'],
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['bug', 'relay-auto']]),
            $this->source->refresh(),
        );

        $this->assertNotNull($result);
        $this->assertTrue($result['auto_accepted']);
    }

    public function test_combined_include_and_exclude_filters(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'include_labels' => ['bug'],
            'exclude_labels' => ['wontfix'],
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['labels' => ['bug', 'wontfix']]),
            $this->source->refresh(),
        );

        $this->assertNull($result);
    }

    public function test_combined_include_exclude_and_unassigned(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'include_labels' => ['bug'],
            'unassigned_only' => true,
        ]);

        $assigned = $this->service->evaluate(
            $this->issueData(['labels' => ['bug'], 'assignee' => 'octocat']),
            $this->source->refresh(),
        );
        $this->assertNull($assigned);

        $unassigned = $this->service->evaluate(
            $this->issueData(['labels' => ['bug'], 'assignee' => null]),
            $this->source->refresh(),
        );
        $this->assertNotNull($unassigned);
    }

    public function test_validate_no_conflict_passes_disjoint_labels(): void
    {
        FilterRuleService::validateNoConflict(['bug'], ['wontfix']);
        $this->assertTrue(true);
    }

    public function test_validate_no_conflict_rejects_overlapping_labels(): void
    {
        $this->expectException(ValidationException::class);

        FilterRuleService::validateNoConflict(['bug', 'critical'], ['critical', 'wontfix']);
    }

    public function test_validate_no_conflict_is_case_insensitive(): void
    {
        $this->expectException(ValidationException::class);

        FilterRuleService::validateNoConflict(['Bug'], ['bug']);
    }

    public function test_apply_to_sync_creates_issue_record(): void
    {
        $issue = $this->service->applyToSync($this->issueData(), $this->source);

        $this->assertNotNull($issue);
        $this->assertInstanceOf(Issue::class, $issue);
        $this->assertEquals('GH-123', $issue->external_id);
        $this->assertEquals($this->source->id, $issue->source_id);
        $this->assertEquals(IssueStatus::Queued, $issue->status);
    }

    public function test_apply_to_sync_deduplicates_by_external_id(): void
    {
        $first = $this->service->applyToSync($this->issueData(), $this->source);
        $second = $this->service->applyToSync($this->issueData(['title' => 'Updated title']), $this->source);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals('Fix the widget', $second->title);
    }

    public function test_apply_to_sync_returns_null_for_filtered_issue(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'include_labels' => ['bug'],
        ]);

        $result = $this->service->applyToSync(
            $this->issueData(['labels' => ['enhancement']]),
            $this->source->refresh(),
        );

        $this->assertNull($result);
        $this->assertDatabaseCount('issues', 0);
    }

    public function test_apply_to_sync_auto_accepts_with_matching_label(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'auto_accept_labels' => ['relay-auto'],
        ]);

        $issue = $this->service->applyToSync(
            $this->issueData(['labels' => ['relay-auto']]),
            $this->source->refresh(),
        );

        $this->assertNotNull($issue);
        $this->assertEquals(IssueStatus::Accepted, $issue->status);
        $this->assertTrue($issue->auto_accepted);
    }

    public function test_empty_include_labels_passes_all(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'include_labels' => [],
        ]);

        $result = $this->service->evaluate($this->issueData(), $this->source->refresh());

        $this->assertNotNull($result);
    }

    public function test_issue_with_no_labels_against_include_filter(): void
    {
        FilterRule::factory()->create([
            'source_id' => $this->source->id,
            'include_labels' => ['bug'],
        ]);

        $result = $this->service->evaluate(
            $this->issueData(['labels' => []]),
            $this->source->refresh(),
        );

        $this->assertNull($result);
    }
}
