<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CodeExecutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test successful Python code execution with plain text result.
     */
    public function test_execute_python_success_plain(): void
    {
        Http::fake([
            'https://ki-mcp01.hrz.uni-giessen.de' => Http::sequence()
                // 1. Initialize call response
                ->push(
                    "event: message\ndata: {\"result\":{\"protocolVersion\":\"2024-11-05\",\"capabilities\":{},\"serverInfo\":{\"name\":\"code-exec-mcp\",\"version\":\"1.0.0\"}},\"jsonrpc\":\"2.0\",\"id\":1}\n",
                    200,
                    [
                        'Content-Type' => 'text/event-stream',
                        'mcp-session-id' => 'mock-session-123',
                    ]
                )
                // 2. tools/call response
                ->push(
                    "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"hello from python\\n\"}],\"isError\":false},\"jsonrpc\":\"2.0\",\"id\":2}\n",
                    200,
                    [
                        'Content-Type' => 'text/event-stream',
                        'mcp-session-id' => 'mock-session-123',
                    ]
                ),
        ]);

        $response = $this->withoutMiddleware()->postJson('/req/text/execute-python', [
            'code' => 'print("hello from python")',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'output' => "hello from python\n",
            'meta' => null,
        ]);
    }

    /**
     * Test successful Python code execution with JSON formatted output (stdout + meta).
     */
    public function test_execute_python_success_json_meta(): void
    {
        $innerJson = json_encode([
            'text' => "hello from python\n",
            'meta' => [
                'duration_ms' => 123,
                'stdout_len' => 18,
                'stderr_len' => 0,
                'image' => 'code-exec-sandbox:latest',
                'runtime' => 'runsc',
                'timed_out' => false,
            ],
        ]);

        Http::fake([
            'https://ki-mcp01.hrz.uni-giessen.de' => Http::sequence()
                // 1. Initialize call response
                ->push(
                    "event: message\ndata: {\"result\":{\"protocolVersion\":\"2024-11-05\"},\"jsonrpc\":\"2.0\",\"id\":1}\n",
                    200,
                    [
                        'Content-Type' => 'text/event-stream',
                        'mcp-session-id' => 'mock-session-456',
                    ]
                )
                // 2. tools/call response
                ->push(
                    "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":".json_encode($innerJson)."}],\"isError\":false},\"jsonrpc\":\"2.0\",\"id\":2}\n",
                    200,
                    [
                        'Content-Type' => 'text/event-stream',
                        'mcp-session-id' => 'mock-session-456',
                    ]
                ),
        ]);

        $response = $this->withoutMiddleware()->postJson('/req/text/execute-python', [
            'code' => 'print("hello from python")',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'output' => "hello from python\n",
            'meta' => [
                'duration_ms' => 123,
                'stdout_len' => 18,
                'stderr_len' => 0,
                'image' => 'code-exec-sandbox:latest',
                'runtime' => 'runsc',
                'timed_out' => false,
            ],
        ]);
    }

    /**
     * Test executing code that triggers an error.
     */
    public function test_execute_python_error(): void
    {
        Http::fake([
            'https://ki-mcp01.hrz.uni-giessen.de' => Http::sequence()
                // 1. Initialize call response
                ->push(
                    "event: message\ndata: {\"result\":{\"protocolVersion\":\"2024-11-05\"},\"jsonrpc\":\"2.0\",\"id\":1}\n",
                    200,
                    [
                        'Content-Type' => 'text/event-stream',
                        'mcp-session-id' => 'mock-session-789',
                    ]
                )
                // 2. tools/call response (isError = true)
                ->push(
                    "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"Traceback (most recent call last):\\nZeroDivisionError: division by zero\\n\"}],\"isError\":true},\"jsonrpc\":\"2.0\",\"id\":2}\n",
                    200,
                    [
                        'Content-Type' => 'text/event-stream',
                        'mcp-session-id' => 'mock-session-789',
                    ]
                ),
        ]);

        $response = $this->withoutMiddleware()->postJson('/req/text/execute-python', [
            'code' => '1/0',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
            'output' => "Traceback (most recent call last):\nZeroDivisionError: division by zero\n",
            'meta' => null,
        ]);
    }

    /**
     * Test validation fails when code is missing.
     */
    public function test_execute_python_validation_fails(): void
    {
        $response = $this->withoutMiddleware()->postJson('/req/text/execute-python', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['code']);
    }

    /**
     * Test connection/initialization failure.
     */
    public function test_execute_python_server_error(): void
    {
        Http::fake([
            'https://ki-mcp01.hrz.uni-giessen.de' => Http::response('Internal Server Error', 500),
        ]);

        $response = $this->withoutMiddleware()->postJson('/req/text/execute-python', [
            'code' => 'print("test")',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('Fehler bei der Ausführung', $response->json('output'));
    }

    /**
     * Test successful Python code execution returning multiple base64 images.
     */
    public function test_execute_python_multiple_images(): void
    {
        $multipleImagesOutput = "Here is a scatterplot:\ndata:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==\n".
            "Here is a bar chart:\ndata:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=\nDone.";

        Http::fake([
            'https://ki-mcp01.hrz.uni-giessen.de' => Http::sequence()
                // 1. Initialize call response
                ->push(
                    "event: message\ndata: {\"result\":{\"protocolVersion\":\"2024-11-05\"},\"jsonrpc\":\"2.0\",\"id\":1}\n",
                    200,
                    [
                        'Content-Type' => 'text/event-stream',
                        'mcp-session-id' => 'mock-session-999',
                    ]
                )
                // 2. tools/call response containing multiple images
                ->push(
                    "event: message\ndata: {\"result\":{\"content\":[{\"type\":\"text\",\"text\":".json_encode($multipleImagesOutput)."}],\"isError\":false},\"jsonrpc\":\"2.0\",\"id\":2}\n",
                    200,
                    [
                        'Content-Type' => 'text/event-stream',
                        'mcp-session-id' => 'mock-session-999',
                    ]
                ),
        ]);

        $response = $this->withoutMiddleware()->postJson('/req/text/execute-python', [
            'code' => 'import matplotlib.pyplot as plt; plt.plot([1,2]); plt.show(); plt.figure(); plt.bar([1,2],[3,4]); plt.show();',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'output' => $multipleImagesOutput,
            'meta' => null,
        ]);
    }
}
