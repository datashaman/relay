<?php

namespace Tests\Unit\Services\AiProviders;

use App\Contracts\AiProvider;
use App\Services\AiProviders\ClaudeCodeCliProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pure-unit coverage for ClaudeCodeCliProvider's pure helpers.
 *
 * Feature tests/Feature/AiProviderTest.php covers the public chat() contract
 * via a mock; this file locks down the privates that shape CLI invocations and
 * parse the NDJSON stream — buildArgs, splitCommand, buildPrompt,
 * pickTerminalTool, synthesizeToolCall, extractJson, normalizeEvent, and
 * parseStreamJsonOutput.
 *
 * No process is spawned; reflection invokes the privates directly so the
 * suite stays fast and isolated.
 */
class ClaudeCodeCliProviderTest extends TestCase
{
    private ClaudeCodeCliProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new ClaudeCodeCliProvider();
    }

    public function test_implements_ai_provider_contract(): void
    {
        $this->assertInstanceOf(AiProvider::class, $this->provider);
    }

    // --- splitCommand ---

    #[DataProvider('splitCommandProvider')]
    public function test_split_command_tokenizes_on_whitespace(string $input, array $expected): void
    {
        $this->assertSame($expected, $this->invoke('splitCommand', [$input]));
    }

    public static function splitCommandProvider(): iterable
    {
        yield 'single word' => ['claude', ['claude']];
        yield 'multi-word' => ['claude --print', ['claude', '--print']];
        yield 'extra whitespace collapsed' => ['  claude   --print  ', ['claude', '--print']];
        yield 'tabs treated as whitespace' => ["claude\t--verbose", ['claude', '--verbose']];
        yield 'default command' => [
            'claude --dangerously-skip-permissions --print --output-format stream-json --verbose',
            ['claude', '--dangerously-skip-permissions', '--print', '--output-format', 'stream-json', '--verbose'],
        ];
    }

    // --- buildArgs ---

    public function test_build_args_appends_terminator_and_prompt(): void
    {
        $args = $this->invoke('buildArgs', [
            [['role' => 'user', 'content' => 'Hello']],
            [],
            [],
        ]);

        $this->assertContains('--', $args);
        $terminator = array_search('--', $args, true);
        $this->assertSame('Hello', $args[$terminator + 1]);
    }

    public function test_build_args_includes_model_option(): void
    {
        $args = $this->invoke('buildArgs', [
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'sonnet-4-6'],
            [],
        ]);

        $modelIdx = array_search('--model', $args, true);
        $this->assertNotFalse($modelIdx);
        $this->assertSame('sonnet-4-6', $args[$modelIdx + 1]);
    }

    public function test_build_args_repeats_allowed_tools_flag(): void
    {
        $args = $this->invoke('buildArgs', [
            [['role' => 'user', 'content' => 'hi']],
            ['allowedTools' => ['Read', 'Write', 'Bash']],
            [],
        ]);

        $allowed = [];
        foreach ($args as $i => $a) {
            if ($a === '--allowedTools') {
                $allowed[] = $args[$i + 1];
            }
        }
        $this->assertSame(['Read', 'Write', 'Bash'], $allowed);
    }

    public function test_build_args_omits_model_when_not_provided(): void
    {
        $args = $this->invoke('buildArgs', [
            [['role' => 'user', 'content' => 'hi']],
            [],
            [],
        ]);

        $this->assertNotContains('--model', $args);
    }

    // --- buildPrompt ---

    public function test_build_prompt_concatenates_messages(): void
    {
        $prompt = $this->invoke('buildPrompt', [
            [
                ['role' => 'system', 'content' => 'You are helpful.'],
                ['role' => 'user', 'content' => 'Hello'],
            ],
            [],
        ]);

        $this->assertSame("You are helpful.\n\nHello", $prompt);
    }

    public function test_build_prompt_appends_terminal_tool_schema_for_single_tool(): void
    {
        $prompt = $this->invoke('buildPrompt', [
            [['role' => 'user', 'content' => 'do thing']],
            [['name' => 'preflight_complete', 'parameters' => ['type' => 'object']]],
        ]);

        $this->assertStringContainsString('preflight_complete', $prompt);
        $this->assertStringContainsString('Schema for `preflight_complete`', $prompt);
        $this->assertStringContainsString('"type": "object"', $prompt);
    }

    public function test_build_prompt_picks_complete_suffix_for_multi_tool(): void
    {
        $prompt = $this->invoke('buildPrompt', [
            [['role' => 'user', 'content' => 'do thing']],
            [
                ['name' => 'edit_file', 'parameters' => []],
                ['name' => 'implement_complete', 'parameters' => ['type' => 'object']],
            ],
        ]);

        $this->assertStringContainsString('implement_complete', $prompt);
        $this->assertStringNotContainsString('Schema for `edit_file`', $prompt);
    }

    public function test_build_prompt_omits_schema_when_no_tools(): void
    {
        $prompt = $this->invoke('buildPrompt', [
            [['role' => 'user', 'content' => 'hi']],
            [],
        ]);

        $this->assertSame('hi', $prompt);
        $this->assertStringNotContainsString('Schema for', $prompt);
    }

    // --- pickTerminalTool ---

    public function test_pick_terminal_tool_returns_null_for_empty(): void
    {
        $this->assertNull($this->invoke('pickTerminalTool', [[]]));
    }

    public function test_pick_terminal_tool_returns_only_tool(): void
    {
        $tool = ['name' => 'preflight', 'parameters' => []];
        $this->assertSame($tool, $this->invoke('pickTerminalTool', [[$tool]]));
    }

    public function test_pick_terminal_tool_finds_complete_suffix(): void
    {
        $tools = [
            ['name' => 'edit_file'],
            ['name' => 'verify_complete'],
            ['name' => 'run_tests'],
        ];
        $picked = $this->invoke('pickTerminalTool', [$tools]);
        $this->assertSame('verify_complete', $picked['name']);
    }

    public function test_pick_terminal_tool_returns_null_when_no_complete_suffix_in_multi(): void
    {
        $tools = [
            ['name' => 'edit_file'],
            ['name' => 'run_tests'],
        ];
        $this->assertNull($this->invoke('pickTerminalTool', [$tools]));
    }

    // --- extractJson ---

    public function test_extract_json_parses_bare_object(): void
    {
        $this->assertSame(
            ['ok' => true, 'count' => 3],
            $this->invoke('extractJson', ['{"ok":true,"count":3}']),
        );
    }

    public function test_extract_json_strips_fenced_block(): void
    {
        $text = "Here you go:\n```json\n{\"name\":\"hi\"}\n```\nDone.";
        $this->assertSame(['name' => 'hi'], $this->invoke('extractJson', [$text]));
    }

    public function test_extract_json_handles_unfenced_embedded_object(): void
    {
        $text = "All set! {\"status\":\"ok\"} cheers";
        $this->assertSame(['status' => 'ok'], $this->invoke('extractJson', [$text]));
    }

    public function test_extract_json_returns_null_for_no_json(): void
    {
        $this->assertNull($this->invoke('extractJson', ['no braces here']));
    }

    public function test_extract_json_returns_null_for_invalid_json(): void
    {
        $this->assertNull($this->invoke('extractJson', ['{not valid json}']));
    }

    public function test_extract_json_returns_null_for_empty_string(): void
    {
        $this->assertNull($this->invoke('extractJson', ['']));
    }

    // --- synthesizeToolCall ---

    public function test_synthesize_tool_call_builds_entry_from_text(): void
    {
        $call = $this->invoke('synthesizeToolCall', [
            '```json
{"foo":"bar"}
```',
            ['name' => 'preflight_complete'],
        ]);

        $this->assertIsArray($call);
        $this->assertSame('preflight_complete', $call['name']);
        $this->assertSame(['foo' => 'bar'], $call['arguments']);
        $this->assertStringStartsWith('synth-', $call['id']);
    }

    public function test_synthesize_tool_call_returns_null_when_no_json(): void
    {
        $this->assertNull($this->invoke('synthesizeToolCall', [
            'just prose, no json',
            ['name' => 'preflight_complete'],
        ]));
    }

    // --- normalizeEvent ---

    public function test_normalize_event_maps_assistant_to_content(): void
    {
        $event = $this->invoke('normalizeEvent', [[
            'type' => 'assistant',
            'message' => ['content' => [['text' => 'hello']]],
        ]]);

        $this->assertSame('content', $event['type']);
        $this->assertSame('hello', $event['content']);
        $this->assertNull($event['tool_calls']);
        $this->assertNull($event['usage']);
    }

    public function test_normalize_event_maps_result_to_done(): void
    {
        $event = $this->invoke('normalizeEvent', [[
            'type' => 'result',
            'result' => 'all done',
        ]]);

        $this->assertSame('done', $event['type']);
        $this->assertSame('all done', $event['content']);
    }

    public function test_normalize_event_maps_unknown_to_other(): void
    {
        $event = $this->invoke('normalizeEvent', [['type' => 'system_init']]);

        $this->assertSame('other', $event['type']);
    }

    // --- parseStreamJsonOutput (the most complex pure path) ---

    public function test_parse_stream_json_collects_assistant_text(): void
    {
        $output = implode("\n", [
            json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'Hello ']]]]),
            json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'world!']]]]),
            json_encode(['type' => 'result', 'usage' => ['input_tokens' => 5, 'output_tokens' => 3]]),
        ]);

        $result = $this->invoke('parseStreamJsonOutput', [$output, []]);

        $this->assertSame('Hello world!', $result['content']);
        $this->assertSame([], $result['tool_calls']);
        $this->assertSame(['input_tokens' => 5, 'output_tokens' => 3], $result['usage']);
        $this->assertCount(3, $result['raw']);
    }

    public function test_parse_stream_json_collects_tool_use_blocks(): void
    {
        $output = json_encode([
            'type' => 'assistant',
            'message' => ['content' => [
                ['type' => 'text', 'text' => 'Reading...'],
                ['type' => 'tool_use', 'id' => 'tool_1', 'name' => 'Read', 'input' => ['path' => '/a']],
            ]],
        ]);

        $result = $this->invoke('parseStreamJsonOutput', [$output, []]);

        $this->assertSame('Reading...', $result['content']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame([
            'id' => 'tool_1',
            'name' => 'Read',
            'arguments' => ['path' => '/a'],
        ], $result['tool_calls'][0]);
    }

    public function test_parse_stream_json_falls_back_to_result_field_when_no_assistant_text(): void
    {
        $output = json_encode(['type' => 'result', 'result' => 'final answer', 'usage' => []]);

        $result = $this->invoke('parseStreamJsonOutput', [$output, []]);

        $this->assertSame('final answer', $result['content']);
        $this->assertSame(0, $result['usage']['input_tokens']);
    }

    public function test_parse_stream_json_synthesizes_terminal_tool_call(): void
    {
        $output = json_encode([
            'type' => 'assistant',
            'message' => ['content' => [
                ['type' => 'text', 'text' => '```json
{"summary":"ok"}
```'],
            ]],
        ]);

        $tools = [['name' => 'preflight_complete', 'parameters' => ['type' => 'object']]];
        $result = $this->invoke('parseStreamJsonOutput', [$output, $tools]);

        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame('preflight_complete', $result['tool_calls'][0]['name']);
        $this->assertSame(['summary' => 'ok'], $result['tool_calls'][0]['arguments']);
    }

    public function test_parse_stream_json_skips_blank_and_invalid_lines(): void
    {
        $output = "\n\n   \nnot-json\n".json_encode(['type' => 'result', 'result' => 'ok'])."\n";

        $result = $this->invoke('parseStreamJsonOutput', [$output, []]);

        $this->assertSame('ok', $result['content']);
        $this->assertCount(1, $result['raw']);
    }

    public function test_parse_stream_json_returns_zero_usage_when_no_result(): void
    {
        $output = json_encode([
            'type' => 'assistant',
            'message' => ['content' => [['type' => 'text', 'text' => 'partial']]],
        ]);

        $result = $this->invoke('parseStreamJsonOutput', [$output, []]);

        $this->assertSame(['input_tokens' => 0, 'output_tokens' => 0], $result['usage']);
    }

    public function test_parse_stream_json_skips_synthesis_when_no_terminal_tool(): void
    {
        $output = json_encode([
            'type' => 'assistant',
            'message' => ['content' => [['type' => 'text', 'text' => '{"x":1}']]],
        ]);

        $result = $this->invoke('parseStreamJsonOutput', [$output, []]);

        $this->assertSame([], $result['tool_calls']);
    }

    private function invoke(string $method, array $args): mixed
    {
        $m = new ReflectionMethod(ClaudeCodeCliProvider::class, $method);

        return $m->invokeArgs($this->provider, $args);
    }
}
