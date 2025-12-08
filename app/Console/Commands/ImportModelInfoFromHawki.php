<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Models\AiModelInfo;
use App\Models\ApiProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImportModelInfoFromHawki extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'model-info:import
                            {--provider=* : Nur bestimmte Provider importieren}
                            {--all-providers : Alle Provider importieren (nicht nur genutzte)}
                            {--all-models : Alle Modelle importieren (nicht nur verwendete)}
                            {--dry-run : Vorschau ohne Änderungen}
                            {--force : Vorhandene Daten überschreiben}
                            {--no-cache : Cache ignorieren und neu laden}';

    /**
     * The console command description.
     */
    protected $description = 'Importiert Modell-Informationen von models.hawki.info (1 Provider = 1 Eintrag)';

    /**
     * HAWKI Models JSON URL.
     */
    protected string $modelsUrl = 'https://models.hawki.info/models.json';

    /**
     * Cache Key für models.hawki.info Daten.
     */
    protected string $cacheKey = 'models_hawki_info_data';

    /**
     * Cache TTL in Sekunden (24 Stunden).
     */
    protected int $cacheTtl = 86400;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Disable Observer während Import
        AiModelInfo::unsetEventDispatcher();
        
        $this->info('🚀 Importiere Modell-Informationen von models.hawki.info...');
        $this->info('    Konzept: 1 Provider = 1 Eintrag in ai_model_infos');
        $this->info('    Hinweis: Ollama-Provider werden übersprungen (nutze model-info:import-ollama)');
        
        if (!$this->option('all-models')) {
            $this->info('    Modus: Nur Modelle, die in ai_models existieren');
        } else {
            $this->warn('    Modus: ALLE Modelle (--all-models aktiv)');
        }
        
        $this->newLine();

        // Hole genutzte HAWKI Provider
        $hawkiProviders = $this->getUsedHawkiProviders();
        
        if (empty($hawkiProviders)) {
            $this->warn('⚠️  Keine HAWKI Provider konfiguriert!');
            return Command::FAILURE;
        }

        $this->info('📋 Genutzte HAWKI Provider:');
        foreach ($hawkiProviders as $provider) {
            $this->line("   • {$provider}");
        }
        $this->newLine();

        // Download models.hawki.info data
        $this->info('📥 Lade HAWKI Models Daten...');
        $modelsData = $this->downloadModelsData();
        
        if (!$modelsData) {
            $this->error('❌ Fehler beim Laden der Models Daten!');
            return Command::FAILURE;
        }

        $this->info('✅ ' . count($modelsData['models']) . ' Modelle geladen');
        $this->info('✅ ' . count($modelsData['providers']) . ' Provider-Definitionen geladen');
        $this->newLine();

        // Filtere nur relevante Modelle
        $relevantModels = $this->filterRelevantModels($modelsData, $hawkiProviders);
        
        $this->info('🔍 ' . count($relevantModels) . ' relevante Modelle gefunden');
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->warn('🔍 DRY RUN - Keine Änderungen werden gespeichert');
            $this->newLine();
            $this->showPreview($relevantModels);
            return Command::SUCCESS;
        }

        // Importiere Modelle
        $stats = $this->importModels($relevantModels);

        $this->newLine();
        $this->info('✅ Import abgeschlossen!');
        $this->newLine();
        $this->table(
            ['Aktion', 'Anzahl'],
            [
                ['Neue Modelle', $stats['new']],
                ['Aktualisierte Modelle', $stats['updated']],
                ['Übersprungen', $stats['skipped']],
                ['Fehler', $stats['errors']],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Hole alle genutzten HAWKI Provider.
     */
    protected function getUsedHawkiProviders(): array
    {
        // Option 1: User-definierte Provider (unique_name)
        if ($this->option('provider')) {
            return $this->option('provider');
        }

        // Option 2: Alle Provider
        if ($this->option('all-providers')) {
            // Lade alle Provider aus models.hawki.info
            $data = $this->downloadModelsData();
            if (!$data || !isset($data['providers'])) {
                return [];
            }
            
            // Gib alle models.hawki.info Provider-IDs zurück
            return array_map(fn($p) => $p['id'] ?? null, $data['providers']);
        }

        // Option 3: Nur Provider, die aktiv in api_providers konfiguriert sind
        $activeProviders = ApiProvider::where('is_active', true)
            ->get()
            ->filter(function ($provider) {
                $baseUrl = strtolower($provider->base_url ?? '');
                // Filtere Ollama-Provider heraus
                return !str_contains($baseUrl, 'ollama') && !str_contains($baseUrl, ':11434');
            })
            ->pluck('unique_name')
            ->toArray();

        return $activeProviders;
    }

    /**
     * Download models.hawki.info Daten (mit Caching).
     */
    protected function downloadModelsData(): ?array
    {
        // Cache-Option prüfen
        if (!$this->option('no-cache')) {
            $cached = Cache::get($this->cacheKey);
            if ($cached) {
                $this->info('📦 Lade Daten aus Cache...');
                return $cached;
            }
        }

        try {
            $this->info('🌐 Lade Daten von models.hawki.info...');
            $response = Http::timeout(30)->get($this->modelsUrl);
            
            if (!$response->successful()) {
                $this->error('HTTP Error: ' . $response->status());
                return null;
            }

            $data = $response->json();
            
            if (!is_array($data)) {
                $this->error('Ungültiges JSON Format');
                return null;
            }

            // Validiere Struktur
            if (!isset($data['models']) || !isset($data['providers'])) {
                $this->error('Ungültige Datenstruktur - erwartet: {models: [], providers: []}');
                return null;
            }

            // In Cache speichern
            Cache::put($this->cacheKey, $data, $this->cacheTtl);
            $this->info('💾 Daten im Cache gespeichert (TTL: 24h)');

            return $data;
        } catch (\Exception $e) {
            $this->error('Exception: ' . $e->getMessage());
            Log::error('models.hawki.info Download Fehler', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Filtere nur Modelle, die zu genutzten HAWKI Providern gehören.
     * Verwendet Fuzzy-Matching für Provider-Zuordnung.
     * 
     * Optional: Filtert auch nur Modelle, die in ai_models existieren.
     */
    protected function filterRelevantModels(array $modelsData, array $hawkiProviders): array
    {
        $filtered = [];
        $models = $modelsData['models'] ?? [];
        
        // Hole verwendete Modelle aus ai_models (falls nicht --all-models)
        $usedModels = null;
        if (!$this->option('all-models')) {
            $usedModels = $this->getUsedModelsFromAiModels($hawkiProviders);
            $this->info("📊 {$usedModels->count()} Modelle in ai_models gefunden");
        }
        
        foreach ($models as $model) {
            $modelId = $model['id'] ?? null;
            
            if (!$modelId) {
                continue;
            }

            // Prüfe, ob Modell bei einem gematchten Provider verfügbar ist
            $providers = $model['providers'] ?? [];
            
            foreach ($providers as $provider) {
                $providerId = $provider['providerId'] ?? null;
                
                if (!$providerId) {
                    continue;
                }
                
                // Fuzzy-Match: Finde passenden HAWKI Provider
                $matchedHawkiProvider = $this->fuzzyMatchProvider($providerId, $hawkiProviders, 'openrouter');
                
                if ($matchedHawkiProvider) {
                    // Wenn --all-models NICHT aktiv: Prüfe ob Modell in ai_models existiert
                    if ($usedModels !== null) {
                        $isUsed = $usedModels->contains(function ($aiModel) use ($modelId, $matchedHawkiProvider) {
                            return $aiModel->model_id === $modelId && 
                                   $aiModel->provider->unique_name === $matchedHawkiProvider;
                        });
                        
                        if (!$isUsed) {
                            continue; // Überspringe ungenutzte Modelle
                        }
                    }
                    
                    // Erstelle eindeutigen Key: hawki_provider/model
                    $key = "{$matchedHawkiProvider}/{$modelId}";
                    
                    $filtered[$key] = [
                        'model' => $model,
                        'provider' => $provider,
                        'hawki_provider_id' => $matchedHawkiProvider,
                        'model_info_provider_id' => $providerId,
                    ];
                }
            }
        }

        return $filtered;
    }

    /**
     * Hole alle verwendeten Modelle aus ai_models für die gegebenen Provider.
     */
    protected function getUsedModelsFromAiModels(array $hawkiProviderUniqueNames)
    {
        // Hole alle Provider-IDs für die gegebenen unique_names
        $providerIds = ApiProvider::whereIn('unique_name', $hawkiProviderUniqueNames)
            ->pluck('id')
            ->toArray();
        
        // Hole alle ai_models mit diesen Provider-IDs
        return AiModel::with('provider')
            ->whereIn('provider_id', $providerIds)
            ->get();
    }

    /**
     * Fuzzy-Match zwischen HAWKI Provider und models.hawki.info Provider-ID.
     * 
     * Matching-Priorität:
     * 1. Exakter Match (z.B. "openai" == "openai")
     * 2. HAWKI Provider enthält models.hawki.info ID (z.B. "openai-usa" enthält "openai")
     * 3. models.hawki.info ID enthält HAWKI Provider (z.B. "openai" in "openai-usa")
     * 4. Fallback zu openrouter (für generische/open-source Provider wie gwdg, ki@jlu)
     * 
     * Beispiele:
     * - "openai-usa" matched "openai" (Priorität 2)
     * - "gwdg-eu" matched "openrouter" (Fallback - keine direkte Übereinstimmung)
     * - "google-usa" matched "google" (Priorität 2)
     * - "ki-at-jlu" matched "openrouter" (Fallback)
     */
    protected function fuzzyMatchProvider(string $modelInfoProviderId, array $hawkiProviders, ?string $preferredFallback = 'openrouter'): ?string
    {
        $modelInfoProviderLower = strtolower($modelInfoProviderId);
        
        // Priorität 1: Exakter Match
        foreach ($hawkiProviders as $hawkiProvider) {
            $hawkiProviderLower = strtolower($hawkiProvider);
            
            if ($hawkiProviderLower === $modelInfoProviderLower) {
                return $hawkiProvider;
            }
        }
        
        // Priorität 2: HAWKI Provider enthält models.hawki.info Provider-ID
        foreach ($hawkiProviders as $hawkiProvider) {
            $hawkiProviderLower = strtolower($hawkiProvider);
            
            if (str_contains($hawkiProviderLower, $modelInfoProviderLower)) {
                return $hawkiProvider;
            }
        }
        
        // Priorität 3: models.hawki.info Provider-ID enthält HAWKI Provider
        foreach ($hawkiProviders as $hawkiProvider) {
            $hawkiProviderLower = strtolower($hawkiProvider);
            
            if (str_contains($modelInfoProviderLower, $hawkiProviderLower)) {
                return $hawkiProvider;
            }
        }
        
        // Kein Match gefunden - verwende Fallback nur wenn es openrouter ist
        // und es generische Provider in der Liste gibt
        if ($preferredFallback && $modelInfoProviderId === $preferredFallback) {
            // openrouter matched nur auf generische Provider (nicht auf spezifische wie openai, google, anthropic)
            $specificProviders = ['openai', 'google', 'anthropic', 'azure', 'cohere', 'mistral', 'groq', 'perplexity', 'deepseek'];
            
            foreach ($hawkiProviders as $hawkiProvider) {
                $hawkiProviderLower = strtolower($hawkiProvider);
                
                // Prüfe ob HAWKI Provider NICHT zu spezifischen Providern gehört
                $isSpecific = false;
                foreach ($specificProviders as $specific) {
                    if (str_contains($hawkiProviderLower, $specific)) {
                        $isSpecific = true;
                        break;
                    }
                }
                
                // Wenn generischer Provider (z.B. gwdg-eu, ki-at-jlu), matched zu openrouter
                if (!$isSpecific) {
                    return $hawkiProvider;
                }
            }
        }
        
        return null;
    }

    /**
     * Zeige Vorschau der zu importierenden Modelle.
     */
    protected function showPreview(array $models): void
    {
        $preview = array_slice($models, 0, 20);
        
        $this->table(
            ['Model Info ID', 'HAWKI Provider', 'Model Info Provider', 'Model Name', 'Context'],
            array_map(function ($key, $data) {
                $model = $data['model'];
                $provider = $data['provider'];
                
                return [
                    $key,
                    $data['hawki_provider_id'],
                    $data['model_info_provider_id'],
                    $model['name'] ?? $model['id'] ?? '-',
                    $provider['contextLength'] ?? '-',
                ];
            }, array_keys($preview), $preview)
        );

        if (count($models) > 20) {
            $this->info('... und ' . (count($models) - 20) . ' weitere Modelle');
        }
    }

    /**
     * Importiere Modelle in die Datenbank.
     */
    protected function importModels(array $models): array
    {
        $stats = [
            'new' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $progressBar = $this->output->createProgressBar(count($models));
        $progressBar->start();

        foreach ($models as $modelInfoId => $data) {
            try {
                DB::transaction(function () use ($modelInfoId, $data, &$stats) {
                    $this->importSingleModel($modelInfoId, $data, $stats);
                });
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error('Fehler beim Import von Modell', [
                    'model_info_id' => $modelInfoId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        return $stats;
    }

    /**
     * Importiere ein einzelnes Modell.
     * 
     * WICHTIG: 1 Provider = 1 Eintrag in ai_model_infos
     * model_info_id Format: "hawki_provider_id/model_id"
     * 
     * Respektiert locked_fields: Gelockte Felder werden nicht überschrieben.
     */
    protected function importSingleModel(string $modelInfoId, array $data, array &$stats): void
    {
        $model = $data['model'];
        $provider = $data['provider'];
        $hawkiProviderUniqueName = $data['hawki_provider_id'];
        $modelInfoProviderId = $data['model_info_provider_id'];
        
        $modelId = $model['id'] ?? null;
        
        if (!$modelId) {
            $stats['skipped']++;
            return;
        }

        // Löse HAWKI Provider unique_name zu ID auf
        $apiProvider = ApiProvider::where('unique_name', $hawkiProviderUniqueName)->first();
        
        if (!$apiProvider) {
            $stats['skipped']++;
            Log::warning('API Provider nicht gefunden', [
                'unique_name' => $hawkiProviderUniqueName,
                'model_info_id' => $modelInfoId,
            ]);
            return;
        }

        // Prüfe ob Modell bereits existiert
        $modelInfo = AiModelInfo::where('model_info_id', $modelInfoId)->first();
        $isNew = !$modelInfo;
        
        if (!$modelInfo) {
            $modelInfo = new AiModelInfo(['model_info_id' => $modelInfoId]);
        }

        // Bereite alle Updates vor
        $updates = [
            'base_model_id' => $modelId,
            'provider_id' => $apiProvider->id,
            'model_family' => $this->extractModelFamily($modelId),
            'name' => $model['name'] ?? $this->generateHumanReadableName($modelId),
            'aliases' => $model['aliases'] ?? null,
            'description_en' => $model['description']['en'] ?? null,
            'description_de' => $model['description']['de'] ?? null,
            'knowledge_cutoff' => isset($model['knowledge']) ? 
                \Carbon\Carbon::parse($model['knowledge']) : null,
            'reasoning' => $model['reasoning'] ?? false,
            'tool_calling' => $model['toolCalling'] ?? false,
            'open_weights' => $model['openWeights'] ?? false,
            'deprecated' => $model['deprecated'] ?? false,
            'deprecation_date' => null,
            'input_types' => $model['input'] ?? null,
            'output_types' => $model['output'] ?? null,
            'parameters' => $model['parameters'] ?? null,
            'default_parameters' => $model['defaultParameters'] ?? null,
            'mode' => 'chat',
            'context_length' => $provider['contextLength'] ?? null,
            'output_limit' => $provider['outputLimit'] ?? null,
            'price_input_usd' => isset($provider['price']['usd']['input']) ? 
                (float)$provider['price']['usd']['input'] : null,
            'price_output_usd' => isset($provider['price']['usd']['output']) ? 
                (float)$provider['price']['usd']['output'] : null,
            'price_input_eur' => isset($provider['price']['eur']['input']) ? 
                (float)$provider['price']['eur']['input'] : null,
            'price_output_eur' => isset($provider['price']['eur']['output']) ? 
                (float)$provider['price']['eur']['output'] : null,
            'source_url' => $provider['sourceUrl'] ?? null,
            'additional_metadata' => $this->extractAdditionalMetadata($model, $provider, $modelInfoProviderId),
            'last_imported_at' => now(),
            'import_source' => 'models.hawki.info',
        ];

        // Filtere gelockte Felder heraus (nur bei Updates)
        if (!$isNew) {
            $lockedFields = $modelInfo->locked_fields ?? [];
            $skippedFields = [];
            
            foreach ($lockedFields as $field) {
                if (isset($updates[$field])) {
                    unset($updates[$field]);
                    $skippedFields[] = $field;
                }
            }
            
            if (!empty($skippedFields)) {
                Log::info('Skipped locked fields during import', [
                    'model_info_id' => $modelInfoId,
                    'locked_fields' => $skippedFields,
                ]);
            }
        }

        // Wende Updates an
        $modelInfo->fill($updates);
        $modelInfo->save();

        if ($isNew) {
            $stats['new']++;
        } else {
            $stats['updated']++;
        }
    }

    /**
     * Extrahiere Model Family (z.B. "gpt-4" aus "gpt-4-turbo").
     */
    protected function extractModelFamily(string $modelId): ?string
    {
        // Einfache Heuristik: Nimm ersten Teil vor "-"
        $parts = explode('-', $modelId);
        
        if (count($parts) >= 2) {
            // Für "gpt-4-turbo" → "gpt-4"
            // Für "claude-3-opus" → "claude-3"
            return $parts[0] . '-' . $parts[1];
        }

        return $parts[0];
    }

    /**
     * Generiere human-readable Name.
     */
    protected function generateHumanReadableName(string $modelId): string
    {
        // Konvertiere kebab-case zu Title Case
        return str($modelId)
            ->replace('-', ' ')
            ->replace('_', ' ')
            ->title()
            ->toString();
    }

    /**
     * Extrahiere zusätzliche Metadaten.
     */
    protected function extractAdditionalMetadata(array $model, array $provider, string $modelInfoProviderId): array
    {
        $metadata = [];

        // Model-Level Capabilities
        $modelFields = [
            'reasoning',
            'toolCalling',
            'openWeights',
            'input',
            'output',
            'parameters',
            'defaultParameters',
        ];

        foreach ($modelFields as $field) {
            if (isset($model[$field])) {
                $metadata[$field] = $model[$field];
            }
        }

        // Provider-Level Info
        if (isset($provider['modelName'])) {
            $metadata['provider_model_name'] = $provider['modelName'];
        }
        
        // models.hawki.info Provider-ID speichern
        $metadata['model_info_provider_id'] = $modelInfoProviderId;

        return $metadata;
    }
}
