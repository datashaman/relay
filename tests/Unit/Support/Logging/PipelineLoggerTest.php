<?php

namespace Tests\Unit\Support\Logging;

use App\Models\Run;
use App\Support\Logging\PipelineLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;
use RuntimeException;
use Tests\TestCase;

class PipelineLoggerTest extends TestCase
{
    use RefreshDatabase;

    private TestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new TestHandler;
        $handler = $this->handler;

        Log::extend('pipeline-test', function () use ($handler) {
            return new Logger(new MonologLogger('pipeline', [$handler]));
        });

        config(['logging.channels.pipeline.driver' => 'pipeline-test']);
        Log::forgetChannel('pipeline');
    }

    public function test_stage_started_emits_expected_shape(): void
    {
        $run = Run::factory()->create();

        PipelineLogger::stageStarted($run, 'preflight', ['autonomy_level' => 'autonomous']);

        $records = $this->handler->getRecords();
        $this->assertCount(1, $records);

        $record = $records[0];
        $this->assertSame('pipeline.stage_started', $record['message']);
        $this->assertSame('pipeline', $record['channel']);
        $this->assertSame(MonologLogger::INFO, $record['level']);
        $this->assertSame('stage_started', $record['context']['event']);
        $this->assertSame($run->id, $record['context']['run_id']);
        $this->assertSame($run->issue_id, $record['context']['issue_id']);
        $this->assertSame('preflight', $record['context']['stage']);
        $this->assertSame('autonomous', $record['context']['autonomy_level']);
    }

    public function test_stage_completed_includes_duration_ms(): void
    {
        $run = Run::factory()->create();

        PipelineLogger::stageCompleted($run, 'verify', 4321, ['iteration' => 2]);

        $record = $this->handler->getRecords()[0];
        $this->assertSame('stage_completed', $record['context']['event']);
        $this->assertSame('verify', $record['context']['stage']);
        $this->assertSame(4321, $record['context']['duration_ms']);
        $this->assertSame(2, $record['context']['iteration']);
    }

    public function test_stage_failed_includes_exception_class_and_message(): void
    {
        $run = Run::factory()->create();
        $exception = new RuntimeException('boom');

        PipelineLogger::stageFailed($run, 'implement', $exception, ['iteration' => 3]);

        $record = $this->handler->getRecords()[0];
        $this->assertSame('stage_failed', $record['context']['event']);
        $this->assertSame(MonologLogger::ERROR, $record['level']);
        $this->assertSame(RuntimeException::class, $record['context']['exception_class']);
        $this->assertSame('boom', $record['context']['exception_message']);
        $this->assertSame(3, $record['context']['iteration']);
    }

    public function test_stage_failed_without_exception_omits_exception_keys(): void
    {
        $run = Run::factory()->create();

        PipelineLogger::stageFailed($run, 'verify');

        $record = $this->handler->getRecords()[0];
        $this->assertArrayNotHasKey('exception_class', $record['context']);
        $this->assertArrayNotHasKey('exception_message', $record['context']);
        $this->assertSame('stage_failed', $record['context']['event']);
    }

    public function test_ai_call_normalises_usage_into_token_keys(): void
    {
        PipelineLogger::aiCall(
            'openai',
            'gpt-4o',
            ['input_tokens' => 120, 'output_tokens' => 45],
            ['run_id' => 7, 'stage' => 'implement', 'duration_ms' => 500],
        );

        $record = $this->handler->getRecords()[0];
        $this->assertSame('ai_call', $record['context']['event']);
        $this->assertSame('openai', $record['context']['provider']);
        $this->assertSame('gpt-4o', $record['context']['model']);
        $this->assertSame(120, $record['context']['tokens_prompt']);
        $this->assertSame(45, $record['context']['tokens_completion']);
        $this->assertSame(7, $record['context']['run_id']);
        $this->assertSame('implement', $record['context']['stage']);
        $this->assertSame(500, $record['context']['duration_ms']);
    }

    public function test_ai_call_defaults_missing_usage_tokens_to_zero(): void
    {
        PipelineLogger::aiCall('anthropic', 'claude', []);

        $record = $this->handler->getRecords()[0];
        $this->assertSame(0, $record['context']['tokens_prompt']);
        $this->assertSame(0, $record['context']['tokens_completion']);
    }

    public function test_ai_error_truncates_error_body_to_two_kb(): void
    {
        $body = str_repeat('x', 3000);

        PipelineLogger::aiError('openai', 'gpt-4o', 500, $body);

        $record = $this->handler->getRecords()[0];
        $this->assertSame('ai_error', $record['context']['event']);
        $this->assertSame(MonologLogger::ERROR, $record['level']);
        $this->assertSame(500, $record['context']['status']);
        $this->assertStringEndsWith('…[truncated]', $record['context']['error_body']);
        $this->assertSame(
            2048,
            strlen(str_replace('…[truncated]', '', $record['context']['error_body'])),
        );
    }

    public function test_ai_error_short_body_is_preserved_verbatim(): void
    {
        PipelineLogger::aiError('openai', 'gpt-4o', 429, 'rate limited');

        $record = $this->handler->getRecords()[0];
        $this->assertSame('rate limited', $record['context']['error_body']);
    }

    public function test_ai_error_accepts_null_status_and_body(): void
    {
        PipelineLogger::aiError('claude_code_cli', 'claude', null, null);

        $record = $this->handler->getRecords()[0];
        $this->assertNull($record['context']['status']);
        $this->assertNull($record['context']['error_body']);
    }

    public function test_event_emits_custom_event_name_with_run_context(): void
    {
        $run = Run::factory()->create();

        PipelineLogger::event($run, 'run_completed', ['duration_ms' => 42]);

        $record = $this->handler->getRecords()[0];
        $this->assertSame('run_completed', $record['context']['event']);
        $this->assertSame($run->id, $record['context']['run_id']);
        $this->assertSame($run->issue_id, $record['context']['issue_id']);
        $this->assertSame(42, $record['context']['duration_ms']);
    }

    public function test_logging_failures_never_propagate(): void
    {
        $run = Run::factory()->create();

        Log::shouldReceive('channel')->andThrow(new RuntimeException('log broken'));

        PipelineLogger::stageStarted($run, 'preflight');
        PipelineLogger::aiCall('openai', 'gpt-4o', ['input_tokens' => 1, 'output_tokens' => 1]);

        $this->assertTrue(true);
    }
}
