<?php

namespace App\Console\Commands;

use App\Models\ApiProvider;
use App\Models\AiModel;
use App\Services\AI\TranscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestTranscriptionService extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'transcription:test 
                            {--create-config : Erstellt die fehlende Datenbank-Konfiguration}
                            {--base-url= : Base URL für Ollama (Standard: http://localhost:11434)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testet den Transkriptions-Service und die Verbindung zu Ollama';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🎤 Transkriptions-Service Test');
        $this->newLine();

        // 1. Prüfe Provider
        $this->info('1. Provider-Konfiguration prüfen...');
        $provider = $this->checkProvider();
        
        if (!$provider) {
            if ($this->option('create-config')) {
                $provider = $this->createProvider();
            } else {
                $this->error('   ❌ Provider "ollama-jlu" nicht gefunden');
                $this->info('   💡 Verwenden Sie --create-config um die Konfiguration zu erstellen');
                return 1;
            }
        }

        $this->info('   ✅ Provider gefunden: ' . $provider->provider_name);
        $this->line('      Base URL: ' . $provider->base_url);
        $this->line('      Status: ' . ($provider->is_active ? 'Aktiv' : 'Inaktiv'));
        $this->newLine();

        // 2. Prüfe Modell
        $this->info('2. Modell-Konfiguration prüfen...');
        $model = $this->checkModel($provider);
        
        if (!$model) {
            if ($this->option('create-config')) {
                $model = $this->createModel($provider);
            } else {
                $this->error('   ❌ Whisper-Modell nicht gefunden');
                $this->info('   💡 Verwenden Sie --create-config um die Konfiguration zu erstellen');
                return 1;
            }
        }

        $this->info('   ✅ Modell gefunden: ' . $model->label);
        $this->line('      Modell-ID: ' . $model->model_id);
        $this->line('      Status: ' . ($model->is_active ? 'Aktiv' : 'Inaktiv'));
        $this->newLine();

        // 3. Teste Ollama-Verbindung
        $this->info('3. Ollama-Verbindung testen...');
        $ollamaConnected = $this->testOllamaConnection($provider->base_url);
        
        if (!$ollamaConnected) {
            $this->error('   ❌ Verbindung zu Ollama fehlgeschlagen');
            $this->info('   💡 Stellen Sie sicher, dass Ollama läuft: ollama serve');
            return 1;
        }
        $this->newLine();

        // 4. Prüfe Whisper-Modell in Ollama
        $this->info('4. Whisper-Modell in Ollama prüfen...');
        $modelAvailable = $this->checkOllamaModel($provider->base_url, $model->model_id);
        
        if (!$modelAvailable) {
            $this->warn('   ⚠️  Whisper-Modell nicht in Ollama gefunden');
            $this->info('   💡 Laden Sie das Modell: ollama pull ' . $model->model_id);
            $this->newLine();
            
            if ($this->confirm('Soll das Modell jetzt geladen werden?', true)) {
                $this->pullOllamaModel($provider->base_url, $model->model_id);
            }
        } else {
            $this->info('   ✅ Whisper-Modell in Ollama verfügbar');
        }
        $this->newLine();

        // 5. Test TranscriptionService initialisieren
        $this->info('5. TranscriptionService testen...');
        try {
            $service = app(TranscriptionService::class);
            $config = $service->getConfiguration();
            $this->info('   ✅ TranscriptionService erfolgreich initialisiert');
            
            $testResult = $service->testConnection();
            if ($testResult['success']) {
                $this->info('   ✅ Verbindungstest erfolgreich');
                $this->line('      Verfügbare Modelle in Ollama: ' . count($testResult['models'] ?? []));
            } else {
                $this->warn('   ⚠️  Verbindungstest fehlgeschlagen: ' . $testResult['message']);
            }
        } catch (\Exception $e) {
            $this->error('   ❌ Fehler: ' . $e->getMessage());
            return 1;
        }
        $this->newLine();

        // Zusammenfassung
        $this->info('═══════════════════════════════════════');
        $this->info('✅ Alle Tests erfolgreich!');
        $this->info('═══════════════════════════════════════');
        $this->newLine();
        $this->info('Der Transkriptions-Service ist einsatzbereit.');
        $this->info('Testen Sie mit: curl -X POST http://localhost:8000/req/transcribe -F "audio=@test.mp3"');
        
        return 0;
    }

    protected function checkProvider()
    {
        return ApiProvider::where('unique_name', 'ollama-jlu')->first();
    }

    protected function createProvider()
    {
        $baseUrl = $this->option('base-url') ?? 'http://localhost:11434';
        
        $this->info('   📝 Erstelle Provider "ollama-jlu"...');
        
        $provider = ApiProvider::create([
            'unique_name' => 'ollama-jlu',
            'provider_name' => 'Ollama JLU',
            'base_url' => $baseUrl,
            'is_active' => true,
            'display_order' => 0,
        ]);

        $this->info('   ✅ Provider erstellt (ID: ' . $provider->id . ')');
        return $provider;
    }

    protected function checkModel(ApiProvider $provider)
    {
        return AiModel::where('provider_id', $provider->id)
            ->where('model_id', 'karanchopda333/whisper:latest')
            ->first();
    }

    protected function createModel(ApiProvider $provider)
    {
        $this->info('   📝 Erstelle Whisper-Modell...');
        
        $model = AiModel::create([
            'model_id' => 'karanchopda333/whisper:latest',
            'label' => 'Whisper Audio Transkription',
            'provider_id' => $provider->id,
            'is_active' => true,
            'is_visible' => true,
            'display_order' => 0,
        ]);

        $this->info('   ✅ Modell erstellt (ID: ' . $model->id . ')');
        return $model;
    }

    protected function testOllamaConnection($baseUrl)
    {
        try {
            $response = Http::timeout(5)->get($baseUrl . '/api/tags');
            
            if ($response->successful()) {
                $this->info('   ✅ Verbindung zu Ollama erfolgreich');
                $this->line('      URL: ' . $baseUrl);
                $this->line('      Status: ' . $response->status());
                return true;
            }

            $this->error('   ❌ Ollama antwortet mit Status: ' . $response->status());
            return false;
        } catch (\Exception $e) {
            $this->error('   ❌ Verbindungsfehler: ' . $e->getMessage());
            return false;
        }
    }

    protected function checkOllamaModel($baseUrl, $modelId)
    {
        try {
            $response = Http::timeout(5)->get($baseUrl . '/api/tags');
            
            if ($response->successful()) {
                $data = $response->json();
                $models = $data['models'] ?? [];
                
                foreach ($models as $model) {
                    if ($model['name'] === $modelId || 
                        str_starts_with($model['name'], str_replace(':latest', '', $modelId))) {
                        return true;
                    }
                }
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function pullOllamaModel($baseUrl, $modelId)
    {
        $this->info('   📥 Lade Modell "' . $modelId . '"...');
        $this->warn('   ⏱️  Dies kann einige Minuten dauern...');
        
        try {
            $response = Http::timeout(300)->post($baseUrl . '/api/pull', [
                'name' => $modelId,
                'stream' => false
            ]);

            if ($response->successful()) {
                $this->info('   ✅ Modell erfolgreich geladen');
                return true;
            }

            $this->error('   ❌ Fehler beim Laden: ' . $response->body());
            return false;
        } catch (\Exception $e) {
            $this->error('   ❌ Fehler: ' . $e->getMessage());
            $this->info('   💡 Versuchen Sie manuell: ollama pull ' . $modelId);
            return false;
        }
    }
}
