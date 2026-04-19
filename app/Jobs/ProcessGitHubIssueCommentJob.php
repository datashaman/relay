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
 * Routes a GitHub issue_comment webhook to the active Preflight Run for
 * that issue, applying the bot self-loop guard before resuming.
 */
class ProcessGitHubIssueCommentJob implements ShouldQueue
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
        $action = $payload['action'] ?? null;

        // Only react to newly created comments. Edits and deletes do not
        // resume preflight — the agent only consumes "first reply" content.
        if ($action !== 'created') {
            $delivery->update(['processed_at' => now(), 'error' => 'comment action ignored: '.$action]);

            return;
        }

        $repoFullName = $payload['repository']['full_name'] ?? null;
        $issueNumber = $payload['issue']['number'] ?? null;
        $commentBody = $payload['comment']['body'] ?? null;
        $authorLogin = $payload['comment']['user']['login'] ?? null;

        if (! $repoFullName || ! $issueNumber || $commentBody === null) {
            $delivery->update(['processed_at' => now(), 'error' => 'malformed comment payload']);

            return;
        }

        $botLogin = $source->bot_login ?: config('relay.preflight.bot_identity.github');

        if ($botLogin && $authorLogin && strcasecmp((string) $authorLogin, (string) $botLogin) === 0) {
            $delivery->update(['processed_at' => now(), 'error' => 'comment from bot ignored']);

            return;
        }

        $externalId = $repoFullName.'#'.$issueNumber;

        $issue = Issue::where('source_id', $source->id)
            ->where('external_id', $externalId)
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

        ResumePreflightFromCommentJob::dispatch($run, (string) $commentBody, (string) $authorLogin);

        $delivery->update(['processed_at' => now()]);
    }

    private function findActiveClarificationRun(Issue $issue): ?Run
    {
        return Run::query()
            ->where('issue_id', $issue->id)
            ->where('clarification_channel', 'on_issue')
            ->whereNotNull('clarification_questions')
            ->whereHas('stages', function ($q) {
                $q->where('name', 'preflight')
                    ->where('status', StageStatus::AwaitingApproval);
            })
            ->latest('id')
            ->first();
    }
}
