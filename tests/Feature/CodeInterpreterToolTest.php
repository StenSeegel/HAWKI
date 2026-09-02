<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Tools\CodeInterpreterTool;
use App\Services\AI\Tools\HawkiToolRegistry;
use App\Services\AI\Tools\ToolCallRunner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CodeInterpreterToolTest extends TestCase
{
    private const MCP_URL = 'https://code.test/mcp';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hawki_tools.mcp_servers' => [
                'code-exec-mcp' => ['url' => self::MCP_URL, 'requires_session' => true, 'timeout' => 30],
            ],
            'hawki_tools.bindings.code_interpreter' => [
                'server' => 'code-exec-mcp',
                'tools' => ['run' => 'code_exec'],
            ],
        ]);
    }

    private function fakeSession(string $output): void
    {
        Http::fakeSequence(self::MCP_URL)
            ->push("data: {\"result\":{\"protocolVersion\":\"2024-11-05\"}}\n", 200, ['mcp-session-id' => 'sess-1'])
            ->push('data: {"result":{"content":[{"type":"text","text":'.json_encode($output).'}]}}'."\n", 200);
    }

    public function test_code_is_sent_to_the_bound_mcp_tool(): void
    {
        $this->fakeSession('{"text":"391\\n","meta":{"timed_out":false}}');

        $result = app(CodeInterpreterTool::class)->execute(['code' => 'print(17*23)']);

        // The envelope the server wraps the output in is unwrapped for the model.
        $this->assertSame('391', $result);

        // The handshake comes first, then the tool call.
        $call = Http::recorded()[1][0]->data();
        $this->assertSame('tools/call', $call['method']);
        $this->assertSame('code_exec', $call['params']['name']);
        $this->assertSame(['code' => 'print(17*23)'], $call['params']['arguments']);
    }

    public function test_empty_code_is_rejected(): void
    {
        $result = app(ToolCallRunner::class)->run(
            ['code_interpreter' => app(CodeInterpreterTool::class)],
            'code_interpreter',
            '{"code":"   "}'
        );

        $this->assertStringStartsWith('Error:', $result);
    }

    public function test_an_unbound_server_is_reported_to_the_model(): void
    {
        config(['hawki_tools.mcp_servers' => []]);

        $result = app(ToolCallRunner::class)->run(
            ['code_interpreter' => app(CodeInterpreterTool::class)],
            'code_interpreter',
            '{"code":"print(1)"}'
        );

        $this->assertStringContainsString('not bound to a reachable MCP server', $result);
    }

    public function test_a_timeout_is_reported_in_terms_the_model_can_act_on(): void
    {
        $this->fakeSession('{"text":"partial","meta":{"timed_out":true}}');

        $result = app(CodeInterpreterTool::class)->execute(['code' => 'while True: pass']);

        $this->assertStringContainsString('partial', $result);
        $this->assertStringContainsString('ran too long', $result);
    }

    public function test_silent_code_is_reported_rather_than_returning_nothing(): void
    {
        $this->fakeSession('{"text":"","meta":{"timed_out":false}}');

        $result = app(CodeInterpreterTool::class)->execute(['code' => 'x = 1']);

        $this->assertStringContainsString('no output', $result);
    }

    public function test_plain_output_without_an_envelope_is_passed_through(): void
    {
        $this->fakeSession('just text');

        $this->assertSame('just text', app(CodeInterpreterTool::class)->execute(['code' => 'print(1)']));
    }

    public function test_long_output_is_truncated(): void
    {
        $this->fakeSession(str_repeat('x', 20000));

        $result = app(CodeInterpreterTool::class)->execute(['code' => 'print("x"*20000)']);

        $this->assertStringContainsString('[output truncated', $result);
        $this->assertLessThan(20000, mb_strlen($result));
    }

    public function test_the_function_definition_declares_a_code_argument(): void
    {
        $definition = app(CodeInterpreterTool::class)->getDefinition();

        $this->assertSame('code_interpreter', $definition['function']['name']);
        $this->assertSame(['code'], $definition['function']['parameters']['required']);
        $this->assertNotEmpty($definition['function']['description']);
    }

    public function test_the_registry_knows_which_tools_have_a_runtime(): void
    {
        $registry = app(HawkiToolRegistry::class);

        $this->assertTrue($registry->isImplemented('web_search'));
        $this->assertTrue($registry->isImplemented('code_interpreter'));

        // Configurable in the admin UI, but nothing runs it yet - so it must
        // never be offered to a model.
        $this->assertFalse($registry->isImplemented('image_generation'));
    }

    public function test_every_configured_tool_has_a_prompt_and_a_description(): void
    {
        foreach (config('hawki_tools.tools') as $key => $tool) {
            $this->assertNotEmpty($tool['description'] ?? '', $key.' needs a function description');
            $this->assertNotEmpty($tool['awareness'] ?? '', $key.' needs a tool prompt');
            $this->assertNotEmpty($tool['label'] ?? '', $key.' needs a label');
        }
    }
}
