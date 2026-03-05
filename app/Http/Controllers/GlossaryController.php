<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GlossaryImportRequest;
use App\Models\TranslateGlossary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class GlossaryController extends Controller
{
    /**
     * List all glossaries for the authenticated user
     */
    public function index()
    {
        $glossaries = TranslateGlossary::where('created_by', Auth::id())
            ->withCount('entries')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'glossaries' => $glossaries,
            ],
        ]);
    }

    /**
     * Store a new glossary with its entries
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'visibility' => 'required|in:private,team,org,public',
            'terms' => 'required|array|min:1',
            'terms.*.source_language' => 'required|string|max:10',
            'terms.*.target_language' => 'required|string|max:10',
            'terms.*.source_term' => 'required|string',
            'terms.*.target_term' => 'required|string',
            'terms.*.case_sensitive' => 'boolean',
        ]);

        try {
            return DB::transaction(function () use ($validated) {
                $glossary = TranslateGlossary::create([
                    'unique_name' => $validated['name'].'_'.uniqid(), // Simple unique name
                    'display_name' => $validated['name'],
                    'domain' => $validated['domain'] ?? 'general',
                    'description' => $validated['description'] ?? '',
                    'visibility' => $validated['visibility'],
                    'created_by' => Auth::id(),
                ]);

                foreach ($validated['terms'] as $term) {
                    $glossary->entries()->create([
                        'source_language' => strtoupper($term['source_language']),
                        'target_language' => strtoupper($term['target_language']),
                        'source_term' => $term['source_term'],
                        'target_term' => $term['target_term'],
                        'case_sensitive' => $term['case_sensitive'] ?? false,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Glossary created successfully',
                    'data' => [
                        'glossary' => $glossary->load('entries'),
                    ],
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create glossary: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show a specific glossary with its entries
     */
    public function show($id)
    {
        $glossary = TranslateGlossary::with('entries')
            ->where('id', $id)
            ->where('created_by', Auth::id())
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'glossary' => $glossary,
            ],
        ]);
    }

    /**
     * Update an existing glossary
     */
    public function update(Request $request, $id)
    {
        $glossary = TranslateGlossary::where('id', $id)
            ->where('created_by', Auth::id())
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'domain' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'visibility' => 'required|in:private,team,org,public',
            'terms' => 'required|array|min:1',
            'terms.*.source_language' => 'required|string|max:10',
            'terms.*.target_language' => 'required|string|max:10',
            'terms.*.source_term' => 'required|string',
            'terms.*.target_term' => 'required|string',
            'terms.*.case_sensitive' => 'boolean',
        ]);

        try {
            return DB::transaction(function () use ($glossary, $validated) {
                $glossary->update([
                    'display_name' => $validated['name'],
                    'domain' => $validated['domain'] ?? 'general',
                    'description' => $validated['description'] ?? '',
                    'visibility' => $validated['visibility'],
                ]);

                // Replace all entries (simple strategy: delete all, recreate all)
                // For a more complex app, we might diff them, but this is sufficient for now.
                $glossary->entries()->delete();

                foreach ($validated['terms'] as $term) {
                    $glossary->entries()->create([
                        'source_language' => strtoupper($term['source_language']),
                        'target_language' => strtoupper($term['target_language']),
                        'source_term' => $term['source_term'],
                        'target_term' => $term['target_term'],
                        'case_sensitive' => $term['case_sensitive'] ?? false,
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Glossary updated successfully',
                    'data' => [
                        'glossary' => $glossary->load('entries'),
                    ],
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update glossary: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove a glossary
     */
    public function destroy(int $id): JsonResponse
    {
        $glossary = TranslateGlossary::where('id', $id)
            ->where('created_by', Auth::id())
            ->firstOrFail();

        $glossary->delete();

        return response()->json([
            'success' => true,
            'message' => 'Glossary deleted successfully',
        ]);
    }

    /**
     * Import a glossary from a CSV file.
     *
     * Expected CSV format: two columns (source term, target term).
     * The CSV delimiter is auto-detected (comma, semicolon, or tab).
     */
    public function import(GlossaryImportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $path = $request->file('file')->getRealPath();
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return response()->json(['success' => false, 'message' => 'Could not open the uploaded file.'], 422);
        }

        // Auto-detect delimiter from first line
        $firstLine = fgets($handle);
        rewind($handle);

        $delimiter = ',';
        if ($firstLine !== false) {
            $counts = [
                ',' => substr_count($firstLine, ','),
                ';' => substr_count($firstLine, ';'),
                "\t" => substr_count($firstLine, "\t"),
            ];
            arsort($counts);
            $delimiter = (string) array_key_first($counts);
        }

        $terms = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            // Skip rows with fewer than 2 non-empty columns
            if (count($row) < 2 || trim($row[0]) === '' || trim($row[1]) === '') {
                continue;
            }

            $terms[] = [
                'source_language' => strtoupper($validated['source_language']),
                'target_language' => strtoupper($validated['target_language']),
                'source_term' => trim($row[0]),
                'target_term' => trim($row[1]),
                'case_sensitive' => false,
            ];
        }

        fclose($handle);

        if (count($terms) === 0) {
            return response()->json(['success' => false, 'message' => 'No valid term pairs found in the CSV file.'], 422);
        }

        try {
            return DB::transaction(function () use ($validated, $terms): JsonResponse {
                $glossary = TranslateGlossary::create([
                    'unique_name' => $validated['name'].'_'.uniqid(),
                    'display_name' => $validated['name'],
                    'domain' => 'general',
                    'description' => $validated['description'] ?? '',
                    'visibility' => $validated['visibility'],
                    'created_by' => Auth::id(),
                ]);

                $glossary->entries()->createMany($terms);

                return response()->json([
                    'success' => true,
                    'message' => 'Glossary imported successfully',
                    'data' => [
                        'glossary' => $glossary->loadCount('entries'),
                        'imported_count' => count($terms),
                    ],
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to import glossary: '.$e->getMessage(),
            ], 500);
        }
    }
}
