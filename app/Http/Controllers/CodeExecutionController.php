<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AI\Tools\CodeInterpreterTool;
use App\Services\AI\Tools\SandboxImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Runs the code of a chat code box.
 *
 * The user presses the same button the model's own code interpreter uses under
 * the hood, so this goes through the HAWKI tool rather than a second execution
 * path: the same MCP server, the same binding, the same admin settings.
 */
class CodeExecutionController extends Controller
{
    public function __construct(
        private readonly CodeInterpreterTool $codeInterpreter,
        private readonly SandboxImages $images
    ) {}

    public function execute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:100000',
        ]);

        try {
            $output = $this->codeInterpreter->execute(['code' => $validated['code']]);

            /*
             * The tool takes any plot out of the output and stores it, so the
             * base64 no longer travels in the text. The URLs are returned
             * alongside it and the code box renders them as images.
             */
            $images = array_values(array_filter(array_map(
                static fn (array $image) => $image['url'] ?? null,
                $this->images->drain()
            )));

            return response()->json([
                'success' => true,
                'output' => $output,
                'images' => $images,
            ]);
        } catch (\Throwable $e) {
            Log::error('[CodeExecutionController] Execution failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'output' => $e->getMessage(),
                'images' => [],
            ], 200); // The button renders the message; this is not a transport error.
        }
    }
}
