<?php

namespace App\Services;

use App\Contracts\AiProvider;
use App\Enums\StageName;
use App\Models\Stage;
use App\Models\StageEvent;
use App\Services\AiProviders\AiProviderManager;

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

    public function __construct(
        private AiProviderManager $providerManager,
        private OrchestratorService $orchestrator,
    ) {}

    public function execute(Stage $stage, array $context = []): void
    {
        $run = $stage->run;
        $issue = $run->issue;

        if ($run->clarification_answers !== null || ($context['skip_to_doc'] ?? false)) {
            $this->completeWithDoc($stage);

            return;
        }

        $provider = $this->providerManager->resolve(null, StageName::Preflight);

        $messages = $this->buildMessages($issue, $context);
        $response = $provider->chat($messages, [self::ASSESS_TOOL]);

        $assessment = $this->parseAssessment($response);

        $run->update([
            'known_facts' => $assessment['known_facts'],
        ]);

        $this->recordEvent($stage, 'assessment_complete', 'preflight_agent', [
            'confidence' => $assessment['confidence'],
            'known_facts_count' => count($assessment['known_facts']),
            'questions_count' => count($assessment['questions'] ?? []),
        ]);

        if ($assessment['confidence'] === 'clear') {
            $this->completeWithDoc($stage);

            return;
        }

        $run->update([
            'clarification_questions' => $assessment['questions'] ?? [],
        ]);

        $this->recordEvent($stage, 'clarification_needed', 'preflight_agent', [
            'questions' => $assessment['questions'] ?? [],
        ]);

        $this->orchestrator->pause($stage);
    }

    private function buildMessages($issue, array $context): array
    {
        $messages = [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
        ];

        $issueContent = "# Issue: {$issue->title}\n\n";
        if ($issue->body) {
            $issueContent .= $issue->body . "\n\n";
        }
        if (! empty($issue->labels)) {
            $issueContent .= 'Labels: ' . implode(', ', $issue->labels) . "\n";
        }
        if ($issue->assignee) {
            $issueContent .= "Assignee: {$issue->assignee}\n";
        }

        $messages[] = ['role' => 'user', 'content' => $issueContent];

        return $messages;
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

    private function completeWithDoc(Stage $stage): void
    {
        $run = $stage->run;
        $issue = $run->issue;

        $doc = "## Known Facts\n";
        foreach ($run->known_facts ?? [] as $fact) {
            $doc .= "- {$fact}\n";
        }

        if ($run->clarification_answers) {
            $doc .= "\n## Clarification Answers\n";
            $questions = $run->clarification_questions ?? [];
            foreach ($run->clarification_answers as $questionId => $answer) {
                $question = collect($questions)->firstWhere('id', $questionId);
                $questionText = $question['text'] ?? $questionId;
                $doc .= "- **{$questionText}**: {$answer}\n";
            }
        }

        $doc .= "\n## Original Issue\n";
        $doc .= "**{$issue->title}**\n\n";
        if ($issue->body) {
            $doc .= $issue->body . "\n";
        }

        $run->update(['preflight_doc' => $doc]);

        $this->orchestrator->complete($stage);
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
