<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Services\AI\TranscriptionService;
use App\Models\Transcription;
use App\Jobs\GenerateTranscriptionTitle;
use App\Services\AI\AiService;

class TranscriptionController extends Controller
{
    protected $transcriptionService;
    protected $aiService;

    public function __construct(TranscriptionService $transcriptionService, AiService $aiService)
    {
        $this->transcriptionService = $transcriptionService;
        $this->aiService = $aiService;

        // Erhöhe PHP-Limits für Audio-Transkription (funktioniert mit allen Webservern)
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '900');
        @ini_set('max_input_time', '900');
        @ini_set('upload_max_filesize', '100M');
        @ini_set('post_max_size', '100M');
    }

    /**
     * Transkribiert eine Audiodatei
     */
    public function transcribe(Request $request)
    {
        try {
            $request->validate([
                'audio' => 'required|file|mimes:mp3,wav,m4a,ogg,flac,webm|max:25600', // Max 25MB (OpenAI Whisper API Limit)
                'language' => 'nullable|string|max:5'
            ]);

            $result = $this->transcriptionService->transcribeAudio(
                $request->file('audio'),
                $request->input('language')
            );

            // POST-PROCESSING: Diarization
            if (!empty($result['segments'])) {
                $result['segments'] = $this->diarizeSegments($result['segments']);
            }

            return response()->json([
                'success' => true,
                'text' => $result['text'],
                'segments' => $result['segments'] ?? [],
                'language' => $result['language'] ?? null
            ]);
        }
        catch (\Exception $e) {
            Log::error('Transcription error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Fehler bei der Transkription: ' . $e->getMessage()
            ], 500);
        }
    }


    /**
     * Status einer Transkription abrufen
     */
    public function getStatus($jobId)
    {
        try {
            $status = $this->transcriptionService->getTranscriptionStatus($jobId);
            return response()->json([
                'success' => true,
                'data' => $status
            ]);
        }
        catch (\Exception $e) {
            Log::error('Status check error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Statusabruf: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Gibt die aktuelle Konfiguration des Transkriptions-Service zurück
     */
    public function getConfiguration()
    {
        try {
            $config = $this->transcriptionService->getConfiguration();
            return response()->json([
                'success' => true,
                'data' => $config
            ]);
        }
        catch (\Exception $e) {
            Log::error('Configuration retrieval error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Abrufen der Konfiguration: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Testet die Verbindung zum Ollama-Server
     */
    public function testConnection()
    {
        try {
            $result = $this->transcriptionService->testConnection();
            return response()->json($result);
        }
        catch (\Exception $e) {
            Log::error('Connection test error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Verbindungstest: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Speichert eine Transkription in der Datenbank
     */
    public function save(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'transcript_text' => 'required|string',
                'segments' => 'nullable|array',
                'words' => 'nullable|array',
                'language' => 'nullable|string|max:10',
                'duration' => 'nullable|integer',
                'model_used' => 'nullable|string',
                'provider' => 'nullable|string',
                'original_filename' => 'nullable|string',
                'file_size' => 'nullable|integer',
                'metadata' => 'nullable|array',
            ]);

            $transcription = Transcription::create([
                'user_id' => Auth::id(),
                'transcript_text' => $validatedData['transcript_text'],
                'segments' => $validatedData['segments'] ?? null,
                'words' => $validatedData['words'] ?? null,
                'language' => $validatedData['language'] ?? null,
                'user_locale' => app()->getLocale(), // Capture user's locale at request time
                'duration' => $validatedData['duration'] ?? null,
                'model_used' => $validatedData['model_used'] ?? null,
                'provider' => $validatedData['provider'] ?? null,
                'original_filename' => $validatedData['original_filename'] ?? null,
                'file_size' => $validatedData['file_size'] ?? null,
                'metadata' => $validatedData['metadata'] ?? null,
            ]);

            // Trigger automatic title generation (async in queue)
            GenerateTranscriptionTitle::dispatch($transcription);

            return response()->json([
                'success' => true,
                'transcription' => $transcription,
                'message' => 'Transkription erfolgreich gespeichert'
            ], 201);
        }
        catch (\Exception $e) {
            Log::error('Transcription save error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Speichern: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Liste aller Transkriptionen des Benutzers
     */
    public function list(Request $request)
    {
        try {
            $transcriptions = Transcription::forUser(Auth::id())
                ->recent(50)
                ->get(['id', 'slug', 'title', 'language', 'duration', 'original_filename', 'created_at', 'updated_at']);

            return response()->json([
                'success' => true,
                'transcriptions' => $transcriptions
            ]);
        }
        catch (\Exception $e) {
            Log::error('Transcriptions list error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Laden der Liste: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lädt eine einzelne Transkription
     */
    public function load($slug)
    {
        try {
            $transcription = Transcription::where('slug', $slug)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'transcription' => $transcription
            ]);
        }
        catch (\Exception $e) {
            Log::error('Transcription load error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Transkription nicht gefunden'
            ], 404);
        }
    }

    /**
     * Löscht eine Transkription
     */
    public function delete($slug)
    {
        try {
            $transcription = Transcription::where('slug', $slug)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $transcription->delete();

            return response()->json([
                'success' => true,
                'message' => 'Transkription erfolgreich gelöscht'
            ]);
        }
        catch (\Exception $e) {
            Log::error('Transcription delete error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Löschen'
            ], 500);
        }
    }

    /**
     * Aktualisiert den Titel einer Transkription
     */
    public function updateTitle(Request $request, $slug)
    {
        try {
            $validatedData = $request->validate([
                'title' => 'required|string|max:255'
            ]);

            $transcription = Transcription::where('slug', $slug)
                ->where('user_id', Auth::id())
                ->firstOrFail();

            $transcription->update(['title' => $validatedData['title']]);

            return response()->json([
                'success' => true,
                'message' => 'Titel erfolgreich aktualisiert'
            ]);
        }
        catch (\Exception $e) {
            Log::error('Title update error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Fehler beim Aktualisieren des Titels'
            ], 500);
        }
    }

    /**
     * Identifies speakers in segments using GPT-4o
     */
    protected function diarizeSegments(array $segments): array
    {
        try {
            // Group segments into larger chunks for GPT
            $chunks = [];
            $currentChunk = [];
            $currentLength = 0;

            foreach ($segments as $segment) {
                $currentChunk[] = $segment;
                $currentLength += strlen($segment['text']);
                if ($currentLength > 2000 || count($currentChunk) >= 30) {
                    $chunks[] = $currentChunk;
                    $currentChunk = [];
                    $currentLength = 0;
                }
            }
            if (!empty($currentChunk)) {
                $chunks[] = $currentChunk;
            }

            $diarizedSegments = [];

            foreach ($chunks as $chunk) {
                $prompt = "You are an expert in speaker diarization. Below is a list of transcription segments with timestamps. 
Your task is to identify which person is speaking in each segment. 
Assign a speaker ID (e.g., 'Sprecher 1', 'Sprecher 2') to each segment based on context. 

Return ONLY a JSON array of objects, where each object has 'id' (the relative index in this list starting from 0) and 'speaker' (the identified speaker name).

Segments:
";
                foreach ($chunk as $index => $segment) {
                    $prompt .= "ID: {$index} | [{$segment['start']} - {$segment['end']}] | Text: {$segment['text']}\n";
                }

                $response = $this->aiService->sendRequest([
                    'model' => config('model_providers.system_models.title_generator', 'o4-mini'),
                    'messages' => [
                        ['role' => 'system', 'content' => [['text' => 'You are a helpful assistant specialized in JSON output.']]],
                        ['role' => 'user', 'content' => [['text' => $prompt]]]
                    ],
                    'response_format' => ['type' => 'json_object']
                ]);

                $content = $this->extractAiText($response);
                $mappingResult = json_decode($content, true);
                $mapping = is_array($mappingResult) && isset($mappingResult['segments']) ? $mappingResult['segments'] : $mappingResult;
                
                if (is_array($mapping)) {
                    foreach ($mapping as $item) {
                        if (isset($item['id']) && isset($chunk[$item['id']])) {
                            $chunk[$item['id']]['speaker'] = $item['speaker'];
                        }
                    }
                }
                
                $diarizedSegments = array_merge($diarizedSegments, $chunk);
            }

            return $diarizedSegments;
        } catch (\Exception $e) {
            Log::warning('Diarization failed: ' . $e->getMessage());
            return $segments;
        }
    }

    /**
     * Helper to extract text from AiResponse
     */
    protected function extractAiText($response): string
    {
        if (is_object($response) && isset($response->content)) {
            $content = $response->content;
            if (is_array($content)) {
                if (isset($content['text'])) return $content['text'];
                if (isset($content[0]['text'])) return $content[0]['text'];
            }
            if (is_string($content)) return $content;
        }
        return '';
    }
}