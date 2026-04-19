<?php

namespace App\Services;

use App\Enums\StageName;
use App\Enums\StuckState;
use App\Models\Run;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\AiProviders\AiProviderManager;
use App\Support\Logging\PipelineLogger;

class PreflightAgent
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the Preflight Agent for Relay, an agentic issue pipeline. Your job is to assess incoming issues and determine if they are clear enough to implement, or if they need clarification.

## Readiness Rubric

An issue is **ready** when ALL of these are true:
- The desired behavior or change is explicitly stated
- Acceptance criteria are present or can be directly inferred
- The scope is bounded (you know what's in and out)
- No ambiguous pronouns or references that could mean multiple things

An issue is **not ready** when ANY of these are true:
- The requirements are vague or open to multiple interpretations
- Critical context is missing (which component, what data, what platform)
- The scope is unbounded or unclear
- There are implicit assumptions that need confirmation
- Multiple valid approaches exist and the preferred one isn't specified

## Clarification Loop

The user may have already answered earlier questions — those answers appear in the user message under "## Clarification Answers". Use them to revise your assessment. If the answers resolve all ambiguity, mark the issue ready. If they raise new ambiguity or leave gaps, ask follow-up questions targeting the remaining gaps only — do NOT repeat resolved questions.

## Instructions

Analyze the issue and call the `assess_issue` tool with your assessment. Always include known facts extracted from the issue. If not ready, generate clarifying questions — prefer radio-button choices when there are a small number of clear options, use free-text when the answer space is open-ended.
PROMPT;

    private const DOC_SYSTEM_PROMPT = <<<'PROMPT'
You are the Preflight Agent for Relay. Generate a structured preflight document for the implement agent. The document must contain ALL of the following sections — no extras, no omissions:

1. **Summary** — one paragraph describing what needs to be done and why
2. **Requirements** — bullet list of functional requirements
3. **Acceptance Criteria** — numbered list of testable criteria
4. **Affected Files** — bullet list of files/directories likely to be changed (best guess)
5. **Approach** — brief description of the implementation approach
6. **Scope Assessment** — size (small/medium/large), risk flags (list any risks), suggested autonomy (manual/supervised/assisted/autonomous)

Use the known facts, any clarification answers, and the original issue to produce a thorough, implementation-ready document. Call the `generate_preflight_doc` tool with each section.
PROMPT;

    private const ASSESS_TOOL = [
        'name' => 'assess_issue',
        'description' => 'Submit the issue assessment with known facts and optional clarifying questions.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'ready' => [
                    'type' => 'boolean',
                    'description' => 'True when the issue is clear enough to implement, false when clarifying questions are needed.',
                ],
                'known_facts' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Facts extracted from the issue that are known/certain.',
                ],
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'description' => 'Unique question identifier.'],
                            'text' => ['type' => 'string', 'description' => 'The clarifying question.'],
                            'type' => ['type' => 'string', 'enum' => ['choice', 'text'], 'description' => 'Whether to show radio buttons or a text box.'],
                            'options' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                                'description' => 'Options for choice-type questions.',
                            ],
                        ],
                        'required' => ['id', 'text', 'type'],
                    ],
                    'description' => 'Clarifying questions to ask the user (required when ready is false).',
                ],
            ],
            'required' => ['ready', 'known_facts'],
        ],
    ];

    private const DOC_TOOL = [
        'name' => 'generate_preflight_doc',
        'description' => 'Generate the structured preflight document with all required sections.',
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'summary' => [
                    'type' => 'string',
                    'description' => 'One paragraph describing what needs to be done and why.',
                ],
                'requirements' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Functional requirements as bullet points.',
                ],
                'acceptance_criteria' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Numbered testable acceptance criteria.',
                ],
                'affected_files' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Files or directories likely to be changed.',
                ],
                'approach' => [
                    'type' => 'string',
                    'description' => 'Brief description of the implementation approach.',
                ],
                'scope_assessment' => [
                    'type' => 'object',
                    'properties' => [
                        'size' => [
                            'type' => 'string',
                            'enum' => ['small', 'medium', 'large'],
                            'description' => 'Estimated size of the change.',
                        ],
                        'risk_flags' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Risk flags or concerns.',
                        ],
                        'suggested_autonomy' => [
                            'type' => 'string',
                            'enum' => ['manual', 'supervised', 'assisted', 'autonomous'],
                            'description' => 'Suggested autonomy level for implementation.',
                        ],
                    ],
                    'required' => ['size', 'risk_flags', 'suggested_autonomy'],
                ],
            ],
            'required' => ['summary', 'requirements', 'acceptance_criteria', 'affected_files', 'approach', 'scope_assessment'],
        ],
    ];

    public function __construct(
        private AiProviderManager $providerManager,
        private OrchestratorService $orchestrator,
        private PreflightCommentPoster $commentPoster,
    ) {}

    public function execute(Stage $stage, array $context = []): void
    {
        $run = $stage->run;
        $issue = $run->issue;

        PipelineLogger::event($run, 'preflight.execute_started', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
            'preflight_round' => $run->preflight_round,
            'has_clarification_answers' => ! empty($run->clarification_answers),
        ]);

        // Explicit skip-to-doc shortcut (user chose "Proceed without answers").
        if ($context['skip_to_doc'] ?? false) {
            $this->generateAndStoreDoc($stage);

            return;
        }

        // A resume with at least one answer means we're entering a new
        // clarification round. Enforce the round cap before invoking the
        // model again. An empty array (form submitted with no answers)
        // does not consume a round.
        if (! empty($run->clarification_answers)) {
            $maxRounds = (int) config('relay.preflight.max_clarification_rounds', 3);
            $nextRound = $run->preflight_round + 1;

            if ($nextRound > $maxRounds) {
                $this->abortNoConsensus($stage, $nextRound, $maxRounds);

                return;
            }

            $run->update(['preflight_round' => $nextRound]);

            $this->recordEvent($stage, 'clarification_round_started', 'preflight_agent', [
                'round' => $nextRound,
                'max_rounds' => $maxRounds,
            ]);
            PipelineLogger::event($run, 'preflight.clarification_round_started', [
                'stage' => $stage->name->value,
                'round' => $nextRound,
                'max_rounds' => $maxRounds,
            ]);
        }

        $provider = $this->providerManager->resolve(null, StageName::Preflight);

        $messages = $this->buildMessages($issue, $run, $context);
        $response = $provider->chat($messages, [self::ASSESS_TOOL], array_filter([
            'cwd' => $run->worktree_path,
            'log_context' => [
                'run_id' => $run->id,
                'issue_id' => $run->issue_id,
                'stage' => $stage->name->value,
                'purpose' => 'preflight.assess',
            ],
        ]));

        $assessment = $this->parseAssessment($response);

        // Only overwrite known_facts when the model actually returned an
        // assessment. Falling back to the default `ready: true` should not
        // wipe facts accumulated in earlier rounds.
        if ($assessment['parsed']) {
            $run->update([
                'known_facts' => $assessment['known_facts'],
            ]);
        }

        $this->recordEvent($stage, 'assessment_complete', 'preflight_agent', [
            'ready' => $assessment['ready'],
            'round' => $run->preflight_round,
            'known_facts_count' => count($assessment['known_facts']),
            'questions_count' => count($assessment['questions']),
        ]);

        PipelineLogger::event($run, 'preflight.assessment_complete', [
            'stage' => $stage->name->value,
            'ready' => $assessment['ready'],
            'round' => $run->preflight_round,
            'known_facts_count' => count($assessment['known_facts']),
            'questions_count' => count($assessment['questions']),
        ]);

        if ($assessment['ready']) {
            $this->generateAndStoreDoc($stage);

            return;
        }

        // Still ambiguous. Archive current Q&A into history, store the new
        // questions, clear answers so the resume path fires a fresh round.
        $this->archiveClarificationRound($run);

        // Snapshot the source's clarification channel on the Run the first
        // time clarification opens. A mid-flight Source toggle does NOT
        // re-route an in-flight Run between channels.
        $channel = $this->resolveAndSnapshotChannel($run);

        $run->update([
            'clarification_questions' => $assessment['questions'],
            'clarification_answers' => null,
        ]);

        $this->recordEvent($stage, 'clarification_needed', 'preflight_agent', [
            'round' => $run->preflight_round,
            'channel' => $channel,
            'questions' => $assessment['questions'],
        ]);

        PipelineLogger::event($run, 'preflight.clarification_needed', [
            'stage' => $stage->name->value,
            'round' => $run->preflight_round,
            'channel' => $channel,
            'questions_count' => count($assessment['questions']),
        ]);

        if ($channel === 'on_issue') {
            $this->commentPoster->postQuestions($stage, $run, $assessment['questions']);
        }

        $this->orchestrator->pause($stage);
    }

    /**
     * Resolve the clarification channel for this Run. If not yet snapshotted,
     * read it from the Source and persist on the Run; subsequent rounds always
     * use the snapshot (immune to mid-flight Source toggles).
     */
    private function resolveAndSnapshotChannel(Run $run): string
    {
        if ($run->clarification_channel !== null) {
            return $run->clarification_channel;
        }

        $source = $run->issue->source;
        $channel = $source ? $source->clarificationChannel() : 'in_app';

        $run->update(['clarification_channel' => $channel]);

        return $channel;
    }

    /**
     * Push the current Q&A pair into clarification_history (kept in known_facts
     * adjacent storage via a session-scoped round marker) before overwriting
     * with the next round's questions.
     */
    private function archiveClarificationRound(Run $run): void
    {
        if (empty($run->clarification_questions) && empty($run->clarification_answers)) {
            return;
        }

        $history = $run->clarification_history ?? [];
        $history[] = [
            'round' => $run->preflight_round,
            'questions' => $run->clarification_questions ?? [],
            'answers' => $run->clarification_answers ?? [],
            'recorded_at' => now()->toIso8601String(),
        ];

        $run->update(['clarification_history' => $history]);
    }

    private function abortNoConsensus(Stage $stage, int $attemptedRound, int $maxRounds): void
    {
        $run = $stage->run;

        $unresolvedQuestions = $run->clarification_questions ?? [];

        $this->archiveClarificationRound($run);

        $this->recordEvent($stage, 'preflight_no_consensus', 'preflight_agent', [
            'attempted_round' => $attemptedRound,
            'max_rounds' => $maxRounds,
        ]);

        PipelineLogger::event($run, 'preflight.no_consensus', [
            'stage' => $stage->name->value,
            'attempted_round' => $attemptedRound,
            'max_rounds' => $maxRounds,
        ]);

        if ($run->clarification_channel === 'on_issue') {
            $this->commentPoster->postNoConsensus($stage, $run, $attemptedRound, $maxRounds, $unresolvedQuestions);
        }

        $this->orchestrator->markStuck($stage, StuckState::PreflightNoConsensus, [
            'attempted_round' => $attemptedRound,
            'max_rounds' => $maxRounds,
            'raw_status' => 'preflight_no_consensus',
        ]);
    }

    private function generateAndStoreDoc(Stage $stage): void
    {
        $run = $stage->run;
        $issue = $run->issue;

        $provider = $this->providerManager->resolve(null, StageName::Preflight);

        $messages = $this->buildDocMessages($run, $issue);
        $response = $provider->chat($messages, [self::DOC_TOOL], array_filter([
            'cwd' => $run->worktree_path,
            'log_context' => [
                'run_id' => $run->id,
                'issue_id' => $run->issue_id,
                'stage' => $stage->name->value,
                'purpose' => 'preflight.generate_doc',
            ],
        ]));

        $docData = $this->parseDocResponse($response);
        $doc = $this->formatDoc($docData, $issue);

        if ($run->preflight_doc !== null) {
            $history = $run->preflight_doc_history ?? [];
            $history[] = [
                'doc' => $run->preflight_doc,
                'created_at' => now()->toIso8601String(),
                'iteration' => $run->iteration,
            ];
            $run->update([
                'preflight_doc' => $doc,
                'preflight_doc_history' => $history,
            ]);
        } else {
            $run->update(['preflight_doc' => $doc]);
        }

        $this->recordEvent($stage, 'doc_generated', 'preflight_agent', [
            'sections' => array_keys($docData),
            'version' => count($run->preflight_doc_history ?? []) + 1,
        ]);

        PipelineLogger::event($run, 'preflight.doc_generated', [
            'stage' => $stage->name->value,
            'sections' => array_keys($docData),
            'version' => count($run->preflight_doc_history ?? []) + 1,
        ]);

        $this->orchestrator->complete($stage);
    }

    private function buildDocMessages($run, $issue): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::DOC_SYSTEM_PROMPT],
        ];

        $content = "# Issue: {$issue->title}\n\n";
        if ($issue->body) {
            $content .= $issue->body."\n\n";
        }
        if (! empty($issue->labels)) {
            $content .= 'Labels: '.implode(', ', $issue->labels)."\n";
        }
        if ($issue->assignee) {
            $content .= "Assignee: {$issue->assignee}\n";
        }

        $content .= "\n## Known Facts\n";
        foreach ($run->known_facts ?? [] as $fact) {
            $content .= "- {$fact}\n";
        }

        $content .= $this->formatClarificationHistory($run);

        if ($run->clarification_answers) {
            $content .= "\n## Clarification Answers\n";
            $questions = $run->clarification_questions ?? [];
            foreach ($run->clarification_answers as $questionId => $answer) {
                $question = collect($questions)->firstWhere('id', $questionId);
                $questionText = $question['text'] ?? $questionId;
                $content .= "- **{$questionText}**: {$answer}\n";
            }
        }

        $messages[] = ['role' => 'user', 'content' => $content];

        return $messages;
    }

    private function formatClarificationHistory($run): string
    {
        $history = $run->clarification_history ?? [];
        if (empty($history)) {
            return '';
        }

        $content = "\n## Previous Clarification Rounds\n";
        foreach ($history as $round) {
            $questions = collect($round['questions'] ?? [])->keyBy('id');
            $answers = $round['answers'] ?? [];
            $roundNumber = $round['round'] ?? '?';
            $content .= "\n### Round {$roundNumber}\n";
            foreach ($answers as $questionId => $answer) {
                $question = $questions->get($questionId);
                $questionText = is_array($question) ? ($question['text'] ?? $questionId) : $questionId;
                $content .= "- **{$questionText}**: {$answer}\n";
            }
        }

        return $content;
    }

    private function parseDocResponse(array $response): array
    {
        foreach ($response['tool_calls'] ?? [] as $toolCall) {
            if ($toolCall['name'] === 'generate_preflight_doc') {
                return $toolCall['arguments'];
            }
        }

        return [
            'summary' => 'No structured doc generated.',
            'requirements' => [],
            'acceptance_criteria' => [],
            'affected_files' => [],
            'approach' => 'To be determined.',
            'scope_assessment' => [
                'size' => 'medium',
                'risk_flags' => [],
                'suggested_autonomy' => 'supervised',
            ],
        ];
    }

    private function formatDoc(array $data, $issue): string
    {
        $doc = "# Preflight Doc: {$issue->title}\n\n";

        $doc .= "## Summary\n\n{$data['summary']}\n\n";

        $doc .= "## Requirements\n\n";
        foreach ($data['requirements'] as $req) {
            $doc .= "- {$req}\n";
        }
        $doc .= "\n";

        $doc .= "## Acceptance Criteria\n\n";
        foreach ($data['acceptance_criteria'] as $i => $criterion) {
            $doc .= ($i + 1).". {$criterion}\n";
        }
        $doc .= "\n";

        $doc .= "## Affected Files\n\n";
        foreach ($data['affected_files'] as $file) {
            $doc .= "- {$file}\n";
        }
        $doc .= "\n";

        $doc .= "## Approach\n\n{$data['approach']}\n\n";

        $scope = $data['scope_assessment'];
        $doc .= "## Scope Assessment\n\n";
        $doc .= "- **Size**: {$scope['size']}\n";
        $doc .= '- **Risk Flags**: '.(! empty($scope['risk_flags']) ? implode(', ', $scope['risk_flags']) : 'None')."\n";
        $doc .= "- **Suggested Autonomy**: {$scope['suggested_autonomy']}\n";

        return $doc;
    }

    private function buildMessages($issue, $run, array $context): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
        ];

        $issueContent = "# Issue: {$issue->title}\n\n";
        if ($issue->body) {
            $issueContent .= $issue->body."\n\n";
        }
        if (! empty($issue->labels)) {
            $issueContent .= 'Labels: '.implode(', ', $issue->labels)."\n";
        }
        if ($issue->assignee) {
            $issueContent .= "Assignee: {$issue->assignee}\n";
        }

        $repoListing = $this->buildRepoListing($run->worktree_path ?? null);
        if ($repoListing !== '') {
            $issueContent .= "\n## Repository file listing\n\n{$repoListing}";
        }

        // Append previous-round history and the most recent answers so the
        // model can refine its assessment during a clarification loop.
        $issueContent .= $this->formatClarificationHistory($run);

        if (! empty($run->clarification_answers)) {
            $issueContent .= "\n## Clarification Answers\n";
            $questions = $run->clarification_questions ?? [];
            foreach ($run->clarification_answers as $questionId => $answer) {
                $question = collect($questions)->firstWhere('id', $questionId);
                $questionText = $question['text'] ?? $questionId;
                $issueContent .= "- **{$questionText}**: {$answer}\n";
            }
        }

        $messages[] = ['role' => 'user', 'content' => $issueContent];

        return $messages;
    }

    /**
     * Walks key subdirectories of the worktree and returns a newline-delimited
     * file listing relative to the worktree root.
     *
     * The listing is capped at 2000 files or 6000 bytes (whichever is reached
     * first), with a truncation notice appended so the model knows the list
     * may be incomplete. Files are listed in alphabetical order — "mentioned
     * first" ordering is intentionally skipped for v1 to keep the implementation
     * simple; the model still gets the full listing to check against.
     *
     * Returns an empty string when the worktree path is absent or unreadable.
     */
    private function buildRepoListing(?string $worktreePath): string
    {
        if (! $worktreePath || ! is_dir($worktreePath)) {
            return '';
        }

        $subdirs = ['app', 'config', 'routes', 'database/migrations'];
        $maxFiles = 2000;
        $maxBytes = 6000;
        $lines = [];
        $byteCount = 0;
        $truncated = false;

        foreach ($subdirs as $subdir) {
            if ($truncated) {
                break;
            }

            $fullPath = $worktreePath.DIRECTORY_SEPARATOR.$subdir;
            if (! is_dir($fullPath) || ! is_readable($fullPath)) {
                continue;
            }

            $subdirFiles = [];
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $fileInfo) {
                    if (! $fileInfo->isFile()) {
                        continue;
                    }

                    $subdirFiles[] = ltrim(
                        str_replace($worktreePath, '', $fileInfo->getPathname()),
                        DIRECTORY_SEPARATOR
                    );
                }
            } catch (\UnexpectedValueException) {
                // Subtree became unreadable mid-walk; skip it silently and keep
                // whatever we already collected.
                continue;
            }

            sort($subdirFiles);

            foreach ($subdirFiles as $path) {
                $nextLineBytes = strlen($path) + 1;
                if (count($lines) >= $maxFiles || $byteCount + $nextLineBytes > $maxBytes) {
                    $truncated = true;
                    break;
                }

                $lines[] = $path;
                $byteCount += $nextLineBytes;
            }
        }

        $output = implode("\n", $lines);
        if ($truncated) {
            $output .= "\n... (truncated at file/byte cap)";
        }

        return $output;
    }

    /**
     * Parse the assess_issue tool call. Returns a normalised assessment plus
     * a `parsed` flag so callers can distinguish a real response from the
     * fallback default (used when the model didn't call the tool).
     *
     * @return array{ready: bool, known_facts: array<int, string>, questions: array<int, array<string, mixed>>, parsed: bool}
     */
    private function parseAssessment(array $response): array
    {
        foreach ($response['tool_calls'] ?? [] as $toolCall) {
            if ($toolCall['name'] === 'assess_issue') {
                $args = $toolCall['arguments'];

                // Backward-compat: older prompt variants returned `confidence`
                // instead of `ready`. Normalise so downstream logic is simple.
                if (! array_key_exists('ready', $args) && array_key_exists('confidence', $args)) {
                    $args['ready'] = $args['confidence'] === 'clear';
                }

                return [
                    'ready' => (bool) ($args['ready'] ?? true),
                    'known_facts' => $args['known_facts'] ?? [],
                    'questions' => $args['questions'] ?? [],
                    'parsed' => true,
                ];
            }
        }

        return [
            'ready' => true,
            'known_facts' => [],
            'questions' => [],
            'parsed' => false,
        ];
    }

    private function recordEvent(Stage $stage, string $type, string $actor, array $payload = []): void
    {
        StageEvent::create([
            'stage_id' => $stage->id,
            'type' => $type,
            'actor' => $actor,
            'payload' => ! empty($payload) ? $payload : null,
        ]);
    }
}
