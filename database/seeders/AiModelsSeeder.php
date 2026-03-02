<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\ApiProvider;
use Illuminate\Database\Seeder;

class AiModelsSeeder extends Seeder
{
    /**
     * Seed AI models for configured providers.
     * 
     * IMPORTANT: This seeder adds missing models on every run.
     * It checks for existing models before creating them.
     */
    public function run(): void
    {
        $this->command->info('Seeding AI Models...');

        // OpenAI Models
        $openaiProvider = ApiProvider::where('unique_name', 'openai')->first();
        if ($openaiProvider) {
            $this->seedOpenAiModels($openaiProvider);
        } else {
            $this->command->warn('OpenAI provider not found. Skipping OpenAI models.');
        }

        $this->command->info('AI Models seeding completed!');
    }

    /**
     * Seed OpenAI/Whisper models
     */
    protected function seedOpenAiModels(ApiProvider $provider): void
    {
        $models = [
            [
                'model_id' => 'gpt-4o-transcribe',
                'label' => 'GPT-4o Transcribe',
                'information' => [
                    'description' => 'OpenAI GPT-4o Audio Transcription (json/text only)',
                ],
                'is_active' => true,
                'is_visible' => true,
                'display_order' => 100,
            ],
            [
                'model_id' => 'whisper-1',
                'label' => 'Whisper v1',
                'information' => [
                    'description' => 'OpenAI Whisper (Classic, supports verbose_json)',
                ],
                'is_active' => true,
                'is_visible' => true,
                'display_order' => 101,
            ],
        ];

        foreach ($models as $modelData) {
            $exists = AiModel::where('provider_id', $provider->id)
                ->where('model_id', $modelData['model_id'])
                ->exists();

            if (!$exists) {
                AiModel::create([
                    'provider_id' => $provider->id,
                    ...$modelData,
                ]);
                $this->command->info("  ✓ Created: {$modelData['model_id']}");
            } else {
                $this->command->info("  - Exists: {$modelData['model_id']}");
            }
        }
    }
}
