<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AI\Tools\CodeInterpreterTool;
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
        private readonly CodeInterpreterTool $codeInterpreter
    ) {}

    public function execute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:100000',
        ]);

        try {
            $output = $this->codeInterpreter->execute(['code' => $validated['code']]);

            return response()->json([
                'success' => true,
                'output' => $output,
            ]);
        } catch (\Throwable $e) {
            Log::error('[CodeExecutionController] Execution failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'output' => $e->getMessage(),
            ], 200); // The button renders the message; this is not a transport error.
        }
    }
}
