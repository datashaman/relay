<?php

namespace App\Services;

use App\Models\Issue;
use App\Models\OauthToken;
use App\Models\Run;
use App\Models\Source;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Support\Logging\PipelineLogger;

/**
 * Posts preflight clarification questions and abort notices on the source
 * issue (GitHub or Jira) when a Run's clarification_channel is on_issue.
 *
 * Idempotency: a `clarification_posted_on_issue` StageEvent is recorded with
 * the round number; before posting, we check whether the same round already
 * has a marker on the current stage.
 */
class PreflightCommentPoster
{
    public function __construct(
        private OauthService $oauth,
    ) {}

    /**
     * Post the current clarification questions for this run as a comment on
     * the source issue. No-op if the round already has a posted marker.
     *
     * @param  array<int, array<string, mixed>>  $questions
     */
    public function postQuestions(Stage $stage, Run $run, array $questions): void
    {
        $issue = $run->issue;
        $source = $issue->source;
        $round = $run->preflight_round;

        if ($this->alreadyPosted($stage, $round, 'questions')) {
            return;
        }

        $body = $this->formatQuestionsBody($questions, $round);

        try {
            $this->postComment($source, $issue, $body);
        } catch (\Throwable $e) {
            $this->recordEvent($stage, 'clarification_post_failed', [
                'round' => $round,
                'error' => mb_substr($e->getMessage(), 0, 240),
            ]);
            PipelineLogger::event($run, 'preflight.on_issue.post_failed', [
                'round' => $round,
                'error' => mb_substr($e->getMessage(), 0, 240),
            ]);

            return;
        }

        $this->recordEvent($stage, 'clarification_posted_on_issue', [
            'round' => $round,
            'kind' => 'questions',
            'questions_count' => count($questions),
        ]);

        PipelineLogger::event($run, 'preflight.on_issue.posted', [
            'round' => $round,
            'kind' => 'questions',
        ]);
    }

    /**
     * Post a final no-consensus notice with the unresolved questions when the
     * round cap is hit on an on_issue source.
     *
     * @param  array<int, array<string, mixed>>  $unresolvedQuestions
     */
    public function postNoConsensus(Stage $stage, Run $run, int $attemptedRound, int $maxRounds, array $unresolvedQuestions): void
    {
        $issue = $run->issue;
        $source = $issue->source;

        if ($this->alreadyPosted($stage, $attemptedRound, 'no_consensus')) {
            return;
        }

        $body = $this->formatNoConsensusBody($attemptedRound, $maxRounds, $unresolvedQuestions);

        try {
            $this->postComment($source, $issue, $body);
        } catch (\Throwable $e) {
            $this->recordEvent($stage, 'clarification_post_failed', [
                'round' => $attemptedRound,
                'error' => mb_substr($e->getMessage(), 0, 240),
                'kind' => 'no_consensus',
            ]);

            return;
        }

        $this->recordEvent($stage, 'clarification_posted_on_issue', [
            'round' => $attemptedRound,
            'kind' => 'no_consensus',
        ]);
    }

    private function alreadyPosted(Stage $stage, int $round, string $kind): bool
    {
        return $stage->events()
            ->where('type', 'clarification_posted_on_issue')
            ->whereJsonContains('payload->round', $round)
            ->whereJsonContains('payload->kind', $kind)
            ->exists();
    }

    private function postComment(Source $source, Issue $issue, string $body): void
    {
        $token = $this->resolveToken($source);

        match ($source->type->value) {
            'github' => $this->postGitHubComment($token, $issue, $body),
            'jira' => (new JiraClient($token, $this->oauth, $source))->addComment((string) $issue->external_id, $body),
        };
    }

    private function postGitHubComment(OauthToken $token, Issue $issue, string $body): void
    {
        [$owner, $repo, $number] = $this->parseGitHubExternalId($issue->external_id);
        (new GitHubClient($token, $this->oauth))->addComment($owner, $repo, $number, $body);
    }

    private function resolveToken(Source $source): OauthToken
    {
        $token = OauthToken::query()
            ->where('source_id', $source->id)
            ->where('provider', $source->type->value)
            ->first();

        if (! $token) {
            throw new \RuntimeException('No OAuth token available for source '.$source->id);
        }

        return $this->oauth->refreshIfExpired($token);
    }

    /**
     * @return array{string, string, int}
     */
    private function parseGitHubExternalId(string $externalId): array
    {
        // Format: "owner/repo#number"
        if (! preg_match('/^([^\/]+)\/([^#]+)#(\d+)$/', $externalId, $matches)) {
            throw new \RuntimeException("Cannot parse GitHub issue external_id [{$externalId}]");
        }

        return [$matches[1], $matches[2], (int) $matches[3]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $questions
     */
    private function formatQuestionsBody(array $questions, int $round): string
    {
        $body = "**Relay Preflight — clarification round {$round}**\n\n";
        $body .= "I need a few details before I can plan this work. Reply on this issue with answers.\n\n";

        foreach ($questions as $i => $q) {
            $n = $i + 1;
            $text = $q['text'] ?? '';
            $body .= "{$n}. {$text}\n";

            if (($q['type'] ?? null) === 'choice' && ! empty($q['options'])) {
                foreach ($q['options'] as $option) {
                    $body .= "   - {$option}\n";
                }
                $body .= "   - Other (explain)\n";
            }
        }

        return rtrim($body)."\n";
    }

    /**
     * @param  array<int, array<string, mixed>>  $unresolvedQuestions
     */
    private function formatNoConsensusBody(int $attemptedRound, int $maxRounds, array $unresolvedQuestions): string
    {
        $body = "**Relay Preflight — no consensus**\n\n";
        $body .= "I asked for clarification {$maxRounds} times (last attempt round {$attemptedRound}) but the requirements still aren't unambiguous. ";
        $body .= "I'm pausing this issue until a human re-engages.\n\n";

        if ($unresolvedQuestions !== []) {
            $body .= "Unresolved questions:\n";
            foreach ($unresolvedQuestions as $i => $q) {
                $n = $i + 1;
                $text = $q['text'] ?? '';
                $body .= "{$n}. {$text}\n";
            }
        }

        return rtrim($body)."\n";
    }

    private function recordEvent(Stage $stage, string $type, array $payload = []): void
    {
        StageEvent::create([
            'stage_id' => $stage->id,
            'type' => $type,
            'actor' => 'preflight_agent',
            'payload' => $payload === [] ? null : $payload,
        ]);
    }
}
