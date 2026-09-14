<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Tools\CodeInterpreterTool;
use App\Services\AI\Tools\HawkiToolRegistry;
use App\Services\AI\Tools\ToolCallRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CodeInterpreterToolTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * Recorded: gemma sent `const fs = require('fs'); ...` as the whole program.
     * Python fails on line 1 with "invalid syntax", which reads like a typo, so
     * the model retries in JavaScript. The error is passed on with the one line
     * that names the actual problem and the way that works.
     */
    public function test_javascript_sent_as_the_program_is_named_as_the_wrong_language(): void
    {
        $envelope = json_encode([
            'text' => "  File \"/work/code.py\", line 1\n    const fs = require('fs');\n          ^^\nSyntaxError: invalid syntax\n\n---\n",
            'meta' => ['timed_out' => false, 'exit_error' => 'Command failed: docker run ...'],
        ]);

        Http::fakeSequence(self::MCP_URL)
            ->push("data: {\"result\":{\"protocolVersion\":\"2024-11-05\"}}\n", 200, ['mcp-session-id' => 'sess-1'])
            ->push('data: {"result":{"isError":true,"content":[{"type":"text","text":'.json_encode($envelope).'}]}}'."\n", 200);

        $result = app(CodeInterpreterTool::class)->execute([
            'code' => "const fs = require('fs');\nconst PptxGenJS = require('pptxgenjs');\nconst pptx = new PptxGenJS();\n",
        ]);

        $this->assertStringContainsString('SyntaxError', $result, 'The original error has to stay - it names the line.');
        $this->assertStringContainsString('this tool runs PYTHON', $result);
        $this->assertStringContainsString('subprocess.run(["node", "/tmp/deck.js"]', $result);
    }

    /**
     * A Python SyntaxError stays a Python SyntaxError: the hint is only for
     * code that is JavaScript, not for every parse failure.
     */
    public function test_a_python_syntax_error_gets_no_language_hint(): void
    {
        $tool = app(CodeInterpreterTool::class);

        $this->assertNull($tool->wrongLanguageHint("print('unclosed\n", 'SyntaxError: unterminated string literal'));
        $this->assertNull($tool->wrongLanguageHint("const x = 1", 'NameError: name x is not defined'));
        $this->assertNotNull($tool->wrongLanguageHint("const x = require('y');", 'SyntaxError: invalid syntax'));
        $this->assertNotNull($tool->wrongLanguageHint("import fs from 'fs';\nconsole.log(1)", 'SyntaxError: invalid syntax'));
    }

    /**
     * A template the user attached has to reach the sandbox. The model never has
     * its bytes, so the tool reads the newest message's attachments itself and
     * sends them as `files`, which the execution server places at /work/<name>.
     */
    public function test_the_attachments_of_the_newest_message_travel_as_files(): void
    {
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        foreach ([['tpl-uuid', 'Vorlage.potx', 'application/vnd.openxmlformats-officedocument.presentationml.template'],
                  ['old-uuid', 'alt.csv', 'text/csv']] as [$uuid, $name, $mime]) {
            \App\Models\Attachment::create([
                'uuid' => $uuid, 'name' => $name, 'category' => 'private', 'type' => 'document', 'mime' => $mime, 'user_id' => $user->id,
            ]);
        }

        $attachments = $this->createMock(\App\Services\Chat\Attachment\AttachmentService::class);
        $attachments->method('retrieve')->willReturnCallback(
            static fn (\App\Models\Attachment $a) => $a->uuid === 'tpl-uuid' ? "PK\x03\x04template-bytes" : "a,b\n"
        );
        $this->app->instance(\App\Services\Chat\Attachment\AttachmentService::class, $attachments);

        $this->fakeSession('{"text":"ok\\n","meta":{"timed_out":false}}');

        $tool = app(CodeInterpreterTool::class);
        $tool->configureForRequest(['messages' => [
            ['role' => 'user', 'content' => ['text' => 'earlier', 'attachments' => ['old-uuid']]],
            ['role' => 'assistant', 'content' => ['text' => 'ok']],
            ['role' => 'user', 'content' => ['text' => 'build a deck on this', 'attachments' => ['tpl-uuid']]],
        ]]);
        $tool->execute(['code' => 'print(1)']);

        $call = Http::recorded()[1][0]->data();
        $files = $call['params']['arguments']['files'] ?? null;

        $this->assertIsArray($files, 'No files were sent with the code.');
        $this->assertCount(1, $files, 'Only the newest message with attachments counts - the CSV from an earlier turn must not travel.');
        $this->assertSame('Vorlage.potx', $files[0]['name']);
        $this->assertSame("PK\x03\x04template-bytes", base64_decode($files[0]['content_base64']));
    }

    public function test_without_attachments_no_files_argument_is_sent(): void
    {
        $this->fakeSession('{"text":"ok\\n","meta":{"timed_out":false}}');

        $tool = app(CodeInterpreterTool::class);
        $tool->configureForRequest(['messages' => [['role' => 'user', 'content' => ['text' => 'hi']]]]);
        $tool->execute(['code' => 'print(1)']);

        $this->assertArrayNotHasKey('files', Http::recorded()[1][0]->data()['params']['arguments']);
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
        $this->assertTrue($registry->isImplemented('image_generation'));

        // Every tool the admin UI offers has to have a runtime; a configured tool
        // without one can be switched on everywhere and still never runs.
        foreach (array_keys(config('hawki_tools.tools', [])) as $key) {
            $this->assertTrue($registry->isImplemented($key), $key.' has no runtime');
        }
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
