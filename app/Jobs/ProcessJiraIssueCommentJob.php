<?php

namespace App\Jobs;

use App\Enums\StageStatus;
use App\Models\Issue;
use App\Models\Run;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Routes a Jira comment_created webhook to the active Preflight Run for
 * that issue, applying the bot self-loop guard before resuming.
 */
class ProcessJiraIssueCommentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public WebhookDelivery $delivery,
    ) {}

    public function handle(): void
    {
        $delivery = $this->delivery;
        $source = $delivery->source;

        if (! $source) {
            $delivery->update(['processed_at' => now(), 'error' => 'source missing']);

            return;
        }

        $payload = $delivery->payload ?? [];
        $event = $payload['webhookEvent'] ?? null;

        // Only react to creates. Edits do not resume preflight.
        if ($event !== 'comment_created') {
            $delivery->update(['processed_at' => now(), 'error' => 'comment event ignored: '.$event]);

            return;
        }

        $issueKey = $payload['issue']['key'] ?? $payload['issue']['id'] ?? null;
        $commentBody = $this->extractCommentBody($payload['comment'] ?? []);
        $authorAccountId = $payload['comment']['author']['accountId'] ?? null;

        if (! $issueKey || $commentBody === null || $commentBody === '') {
            $delivery->update(['processed_at' => now(), 'error' => 'malformed comment payload']);

            return;
        }

        $botAccountId = $source->bot_account_id ?: config('relay.preflight.bot_identity.jira');

        if ($botAccountId && $authorAccountId && (string) $authorAccountId === (string) $botAccountId) {
            $delivery->update(['processed_at' => now(), 'error' => 'comment from bot ignored']);

            return;
        }

        $issue = Issue::where('source_id', $source->id)
            ->where('external_id', (string) $issueKey)
            ->first();

        if (! $issue) {
            $delivery->update(['processed_at' => now(), 'error' => 'issue not tracked']);

            return;
        }

        $run = $this->findActiveClarificationRun($issue);

        if (! $run) {
            $delivery->update(['processed_at' => now(), 'error' => 'no active clarification run']);

            return;
        }

        ResumePreflightFromCommentJob::dispatch($run, $commentBody, (string) $authorAccountId);

        $delivery->update(['processed_at' => now()]);
    }

    private function findActiveClarificationRun(Issue $issue): ?Run
    {
        return Run::query()
            ->where('issue_id', $issue->id)
            ->whereNotNull('clarification_questions')
            ->whereHas('stages', function ($q) {
                $q->where('name', 'preflight')
                    ->where('status', StageStatus::AwaitingApproval);
            })
            ->latest('id')
            ->first();
    }

    /**
     * Extract a plain-text comment body from a Jira comment payload. Jira can
     * deliver either a string (older webhook shape) or an ADF doc.
     *
     * @param  array<string, mixed>  $comment
     */
    private function extractCommentBody(array $comment): ?string
    {
        $body = $comment['body'] ?? null;

        if (is_string($body)) {
            return $body;
        }

        if (is_array($body) && ($body['type'] ?? null) === 'doc') {
            $text = '';
            foreach ($body['content'] ?? [] as $block) {
                foreach ($block['content'] ?? [] as $inline) {
                    $text .= $inline['text'] ?? '';
                }
                $text .= "\n";
            }

            $trimmed = trim($text);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }
}
