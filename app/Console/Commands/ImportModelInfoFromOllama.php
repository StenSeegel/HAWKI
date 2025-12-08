<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\AiModelInfo;
use App\Models\ApiProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportModelInfoFromOllama extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'model-info:import-ollama
                            {--provider= : Spezifischer Ollama Provider (unique_name)}
                            {--all : Alle Ollama-kompatiblen Provider importieren}
                            {--dry-run : Vorschau ohne Änderungen}
                            {--force : Vorhandene Daten überschreiben}';

    /**
     * The console command description.
     */
    protected $description = 'Importiert Modell-Informationen direkt von Ollama API (/api/show)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Disable Observer während Import
        AiModelInfo::unsetEventDispatcher();
        
        $this->info('🚀 Importiere Modell-Informationen von Ollama API...');
        $this->newLine();

        // Hole Ollama Provider
        $ollamaProviders = $this->getOllamaProviders();
        
        if (empty($ollamaProviders)) {
            $this->warn('⚠️  Keine Ollama Provider konfiguriert!');
            return Command::FAILURE;
        }

        $this->info('📋 Gefundene Ollama Provider:');
        foreach ($ollamaProviders as $provider) {
            $this->line("   • {$provider->unique_name} ({$provider->base_url})");
        }
        $this->newLine();

        $totalStats = [
            'new' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // Importiere für jeden Provider
        foreach ($ollamaProviders as $provider) {
            $this->info("🔍 Importiere von {$provider->unique_name}...");
            
            $stats = $this->importFromProvider($provider);
            
            $totalStats['new'] += $stats['new'];
            $totalStats['updated'] += $stats['updated'];
            $totalStats['skipped'] += $stats['skipped'];
            $totalStats['errors'] += $stats['errors'];
            
            $this->newLine();
        }

        $this->info('✅ Import abgeschlossen!');
        $this->newLine();
        $this->table(
            ['Aktion', 'Anzahl'],
            [
                ['Neue Modelle', $totalStats['new']],
                ['Aktualisierte Modelle', $totalStats['updated']],
                ['Übersprungen', $totalStats['skipped']],
                ['Fehler', $totalStats['errors']],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Hole alle Ollama Provider.
     */
    protected function getOllamaProviders()
    {
        $query = ApiProvider::where('is_active', true);
        
        // Option: Spezifischer Provider
        if ($this->option('provider')) {
            $query->where('unique_name', $this->option('provider'));
        }
        
        // Filtere nur Ollama-kompatible Provider
        // Identifiziere anhand base_url (enthält typischerweise "ollama" oder Port 11434)
        $providers = $query->get()->filter(function ($provider) {
            $baseUrl = strtolower($provider->base_url ?? '');
            
            // Ollama Default Port oder URL enthält "ollama"
            return str_contains($baseUrl, 'ollama') || 
                   str_contains($baseUrl, ':11434');
        });

        return $providers;
    }

    /**
     * Importiere Modelle von einem Ollama Provider.
     */
    protected function importFromProvider(ApiProvider $provider): array
    {
        $stats = [
            'new' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // Hole Liste aller Modelle
        $models = $this->getModelList($provider);
        
        if (!$models) {
            $this->error("   ❌ Fehler beim Abrufen der Modellliste");
            return $stats;
        }

        $modelCount = count($models);
        $this->info("   📦 {$modelCount} Modelle gefunden");

        if ($this->option('dry-run')) {
            $this->warn('   🔍 DRY RUN - Keine Änderungen');
            $this->showPreview($models, $provider);
            return $stats;
        }

        $progressBar = $this->output->createProgressBar(count($models));
        $progressBar->start();

        foreach ($models as $modelName) {
            try {
                DB::transaction(function () use ($modelName, $provider, &$stats) {
                    $this->importSingleModel($modelName, $provider, $stats);
                });
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Fehler beim Import von Ollama Modell', [
                    'model' => $modelName,
                    'provider' => $provider->unique_name,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        return $stats;
    }

    /**
     * Hole Liste aller Modelle vom Ollama Provider.
     */
    protected function getModelList(ApiProvider $provider): ?array
    {
        try {
            $response = Http::timeout(10)->get("{$provider->base_url}/api/tags");
            
            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            
            // Extrahiere Modellnamen
            $models = [];
            foreach ($data['models'] ?? [] as $model) {
                $models[] = $model['name'] ?? null;
            }
            
            return array_filter($models);
        } catch (\Exception $e) {
            Log::error('Fehler beim Abrufen der Ollama Modellliste', [
                'provider' => $provider->unique_name,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Hole detaillierte Informationen zu einem Modell via /api/show.
     */
    protected function getModelDetails(string $modelName, ApiProvider $provider): ?array
    {
        try {
            $response = Http::timeout(30)->post("{$provider->base_url}/api/show", [
                'name' => $modelName,
            ]);
            
            if (!$response->successful()) {
                return null;
            }

            return $response->json();
        } catch (\Exception $e) {
            Log::error('Fehler beim Abrufen von Ollama Modell-Details', [
                'model' => $modelName,
                'provider' => $provider->unique_name,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Importiere ein einzelnes Modell.
     */
    protected function importSingleModel(string $modelName, ApiProvider $provider, array &$stats): void
    {
        // Hole detaillierte Informationen
        $details = $this->getModelDetails($modelName, $provider);
        
        if (!$details) {
            $stats['skipped']++;
            return;
        }

        // Extrahiere relevante Informationen
        $modelfile = $details['modelfile'] ?? '';
        $parameters = $details['parameters'] ?? '';
        $template = $details['template'] ?? '';
        $details_data = $details['details'] ?? [];
        
        // Erstelle model_info_id: "provider_unique_name/model_name"
        $modelInfoId = "{$provider->unique_name}/{$modelName}";

        // Erstelle oder aktualisiere AiModelInfo
        $modelInfo = AiModelInfo::updateOrCreate(
            ['model_info_id' => $modelInfoId],
            [
                'base_model_id' => $modelName,
                'provider_id' => $provider->id,
                'model_family' => $this->extractModelFamily($modelName),
                'name' => $this->generateHumanReadableName($modelName),
                'description_en' => $this->extractDescription($details),
                'mode' => 'chat',
                'context_length' => $this->extractContextLength($parameters, $details_data),
                'parameters' => $this->extractAvailableParameters($template),
                'additional_metadata' => [
                    'ollama_modelfile' => $modelfile,
                    'ollama_parameters' => $parameters,
                    'ollama_template' => $template,
                    'ollama_details' => $details_data,
                    'license' => $details['license'] ?? null,
                ],
                'last_imported_at' => now(),
                'import_source' => 'ollama',
            ]
        );

        if ($modelInfo->wasRecentlyCreated) {
            $stats['new']++;
        } else {
            $stats['updated']++;
        }
    }

    /**
     * Extrahiere Context Length aus Parametern.
     */
    protected function extractContextLength(string $parameters, array $details): ?int
    {
        // Prüfe num_ctx in Parameters
        if (preg_match('/num_ctx\s+(\d+)/', $parameters, $matches)) {
            return (int)$matches[1];
        }
        
        // Fallback: Details
        return $details['context_length'] ?? null;
    }

    /**
     * Extrahiere Beschreibung aus Details.
     */
    protected function extractDescription(array $details): ?string
    {
        $license = $details['license'] ?? '';
        
        if ($license) {
            // Nutze ersten Absatz der Lizenz als Beschreibung
            $lines = explode("\n", $license);
            $firstParagraph = '';
            
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line) {
                    $firstParagraph .= $line . ' ';
                    if (strlen($firstParagraph) > 200) {
                        break;
                    }
                }
            }
            
            return trim($firstParagraph);
        }
        
        return null;
    }

    /**
     * Extrahiere verfügbare Parameter aus Template.
     */
    protected function extractAvailableParameters(string $template): ?array
    {
        // Ollama unterstützt standardmäßig diese Parameter
        return [
            'temperature',
            'top_k',
            'top_p',
            'repeat_penalty',
            'seed',
            'num_predict',
            'stop',
        ];
    }

    /**
     * Extrahiere Model Family.
     */
    protected function extractModelFamily(string $modelName): ?string
    {
        // Format: "model-name:tag" oder nur "model-name"
        $baseName = explode(':', $modelName)[0];
        
        // Entferne Größenangaben
        $baseName = preg_replace('/[-_]\d+[bm]?$/i', '', $baseName);
        
        return $baseName;
    }

    /**
     * Generiere human-readable Name.
     */
    protected function generateHumanReadableName(string $modelName): string
    {
        // "llama3.2:3b" → "Llama 3.2 (3b)"
        $parts = explode(':', $modelName);
        $name = $parts[0];
        $tag = $parts[1] ?? null;
        
        $humanName = str($name)
            ->replace('-', ' ')
            ->replace('_', ' ')
            ->title()
            ->toString();
        
        if ($tag) {
            $humanName .= " ({$tag})";
        }
        
        return $humanName;
    }

    /**
     * Zeige Vorschau.
     */
    protected function showPreview(array $models, ApiProvider $provider): void
    {
        $preview = array_slice($models, 0, 10);
        
        $this->table(
            ['Modell', 'Model Info ID'],
            array_map(function ($model) use ($provider) {
                return [
                    $model,
                    "{$provider->unique_name}/{$model}",
                ];
            }, $preview)
        );

        if (count($models) > 10) {
            $this->info('   ... und ' . (count($models) - 10) . ' weitere Modelle');
        }
    }
}

