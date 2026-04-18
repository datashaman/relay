<?php

namespace App\Services;

use App\Enums\StageName;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\AiProviders\AiProviderManager;
use App\Support\Logging\PipelineLogger;

class PreflightAgent
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the Preflight Agent for Relay, an agentic issue pipeline. Your job is to assess incoming issues and determine if they are clear enough to implement, or if they need clarification.

## Confidence Rubric

An issue is **clear** when ALL of these are true:
- The desired behavior or change is explicitly stated
- Acceptance criteria are present or can be directly inferred
- The scope is bounded (you know what's in and out)
- No ambiguous pronouns or references that could mean multiple things

An issue is **ambiguous** when ANY of these are true:
- The requirements are vague or open to multiple interpretations
- Critical context is missing (which component, what data, what platform)
- The scope is unbounded or unclear
- There are implicit assumptions that need confirmation
- Multiple valid approaches exist and the preferred one isn't specified

## Instructions

Analyze the issue and call the `assess_issue` tool with your assessment. Always include known facts extracted from the issue. If ambiguous, generate clarifying questions — prefer radio-button choices when there are a small number of clear options, use free-text when the answer space is open-ended.
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
                'confidence' => [
                    'type' => 'string',
                    'enum' => ['clear', 'ambiguous'],
                    'description' => 'Whether the issue is clear enough to implement or needs clarification.',
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
                    'description' => 'Clarifying questions to ask the user (only when ambiguous).',
                ],
            ],
            'required' => ['confidence', 'known_facts'],
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
    ) {}

    public function execute(Stage $stage, array $context = []): void
    {
        $run = $stage->run;
        $issue = $run->issue;

        PipelineLogger::event($run, 'preflight.execute_started', [
            'stage' => $stage->name->value,
            'iteration' => $stage->iteration,
            'has_clarification_answers' => $run->clarification_answers !== null,
        ]);

        if ($run->clarification_answers !== null || ($context['skip_to_doc'] ?? false)) {
            $this->generateAndStoreDoc($stage);

            return;
        }

        $provider = $this->providerManager->resolve(null, StageName::Preflight);

        $messages = $this->buildMessages($issue, $run->worktree_path, $context);
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

        $run->update([
            'known_facts' => $assessment['known_facts'],
        ]);

        $this->recordEvent($stage, 'assessment_complete', 'preflight_agent', [
            'confidence' => $assessment['confidence'],
            'known_facts_count' => count($assessment['known_facts']),
            'questions_count' => count($assessment['questions'] ?? []),
        ]);

        PipelineLogger::event($run, 'preflight.assessment_complete', [
            'stage' => $stage->name->value,
            'confidence' => $assessment['confidence'],
            'known_facts_count' => count($assessment['known_facts']),
            'questions_count' => count($assessment['questions'] ?? []),
        ]);

        if ($assessment['confidence'] === 'clear') {
            $this->generateAndStoreDoc($stage);

            return;
        }

        $run->update([
            'clarification_questions' => $assessment['questions'] ?? [],
        ]);

        $this->recordEvent($stage, 'clarification_needed', 'preflight_agent', [
            'questions' => $assessment['questions'] ?? [],
        ]);

        PipelineLogger::event($run, 'preflight.clarification_needed', [
            'stage' => $stage->name->value,
            'questions_count' => count($assessment['questions'] ?? []),
        ]);

        $this->orchestrator->pause($stage);
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

    private function buildMessages($issue, ?string $worktreePath, array $context): array
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

        $repoListing = $this->buildRepoListing($worktreePath);
        if ($repoListing !== '') {
            $issueContent .= "\n## Repository file listing\n\n{$repoListing}";
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
        $filePaths = [];

        foreach ($subdirs as $subdir) {
            $fullPath = $worktreePath.DIRECTORY_SEPARATOR.$subdir;
            if (! is_dir($fullPath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $fileInfo) {
                if (! $fileInfo->isFile()) {
                    continue;
                }

                $filePaths[] = ltrim(
                    str_replace($worktreePath, '', $fileInfo->getPathname()),
                    DIRECTORY_SEPARATOR
                );
            }
        }

        sort($filePaths);

        $maxFiles = 2000;
        $maxBytes = 6000;
        $lines = [];
        $byteCount = 0;
        $omitted = 0;

        foreach ($filePaths as $index => $path) {
            if (count($lines) >= $maxFiles || $byteCount >= $maxBytes) {
                $omitted = count($filePaths) - $index;
                break;
            }

            $lines[] = $path;
            $byteCount += strlen($path) + 1;
        }

        $output = implode("\n", $lines);
        if ($omitted > 0) {
            $output .= "\n... ({$omitted} more files omitted)";
        }

        return $output;
    }

    private function parseAssessment(array $response): array
    {
        foreach ($response['tool_calls'] ?? [] as $toolCall) {
            if ($toolCall['name'] === 'assess_issue') {
                return $toolCall['arguments'];
            }
        }

        return [
            'confidence' => 'clear',
            'known_facts' => [],
            'questions' => [],
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
