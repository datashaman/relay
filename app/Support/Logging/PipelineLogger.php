<?php

namespace App\Support\Logging;

use App\Models\Run;
use Illuminate\Support\Facades\Log;
use Throwable;

class PipelineLogger
{
    public const CHANNEL = 'pipeline';

    public static function stageStarted(Run $run, string $stage, array $context = []): void
    {
        self::emit('info', 'stage_started', array_merge(self::runContext($run), [
            'stage' => $stage,
        ], $context));
    }

    public static function stageCompleted(Run $run, string $stage, int $durationMs, array $context = []): void
    {
        self::emit('info', 'stage_completed', array_merge(self::runContext($run), [
            'stage' => $stage,
            'duration_ms' => $durationMs,
        ], $context));
    }

    public static function stageFailed(Run $run, string $stage, Throwable $exception, array $context = []): void
    {
        self::emit('error', 'stage_failed', array_merge(self::runContext($run), [
            'stage' => $stage,
            'exception_class' => $exception::class,
            'exception_message' => $exception->getMessage(),
        ], $context));
    }

    public static function aiCall(string $provider, string $model, array $usage, array $context = []): void
    {
        self::emit('info', 'ai_call', array_merge([
            'provider' => $provider,
            'model' => $model,
            'tokens_prompt' => (int) ($usage['input_tokens'] ?? 0),
            'tokens_completion' => (int) ($usage['output_tokens'] ?? 0),
        ], $context));
    }

    private static function runContext(Run $run): array
    {
        return [
            'run_id' => $run->id,
            'issue_id' => $run->issue_id,
        ];
    }

    private static function emit(string $level, string $event, array $context): void
    {
        Log::channel(self::CHANNEL)->{$level}('pipeline.'.$event, array_merge(
            ['event' => $event],
            $context,
        ));
    }
}
