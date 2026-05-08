<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Extensions;

use App\Models\TranscriptionSetting;
use App\Services\AI\Config\AiConfigService;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class TranscriptionExtensionEditScreen extends Screen
{
    public function query(): iterable
    {
        $settings = TranscriptionSetting::all()->keyBy('key');

        return [
            'settings' => $settings,
        ];
    }

    public function name(): ?string
    {
        return 'Transcription Service';
    }

    public function description(): ?string
    {
        return 'Configure the Speech-to-Text transcription service providers.';
    }

    public function permission(): ?iterable
    {
        return ['platform.extensions'];
    }

    public function commandBar(): iterable
    {
        return [
            Button::make('Save')
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    public function layout(): iterable
    {
        $aiConfigService = app(AiConfigService::class);
        $providerOptions = [
            'custom_speaches' => 'Custom Speaches Server',
        ];

        try {
            $apiProviders = $aiConfigService->getProviders();
            foreach ($apiProviders as $key => $provider) {
                $providerOptions[$key] = 'OpenAI Compatible: '.($provider['name'] ?? $key);
            }
        } catch (\Exception $e) {
            // Ignore if DB decryption fails
        }

        $allSettings = $this->query()['settings'];

        $getVal = function ($key, $default = '') use ($allSettings) {
            return $allSettings->has($key) ? $allSettings->get($key)->value : $default;
        };

        return [
            Layout::block([
                Layout::rows([
                    Select::make('settings[provider]')
                        ->options($providerOptions)
                        ->title('Transcription Provider')
                        ->help('Select the backend provider to use for audio transcriptions.')
                        ->value($getVal('provider', 'custom_speaches')),
                ]),
            ])
                ->title('Provider Selection')
                ->description('Choose which service should handle transcription requests.'),

            Layout::block([
                Layout::rows([
                    Input::make('settings[base_url]')
                        ->title('API Base URL')
                        ->help('Base URL for the Speaches Server (e.g. http://134.176.150.177/v1)')
                        ->value($getVal('base_url')),

                    Input::make('settings[api_key]')
                        ->type('password')
                        ->title('API Key')
                        ->help('API Key for the Speaches Server. Leave blank if not changing.')
                        // Do not prefill password value
                        ->set('autocomplete', 'new-password'),

                    Input::make('settings[model]')
                        ->title('Transcription Model')
                        ->help('Model identifier for transcription (e.g. Systran/faster-whisper-large-v3)')
                        ->value($getVal('model')),

                    Input::make('settings[diarization_model]')
                        ->title('Diarization Model')
                        ->help('Model identifier for Speaker Diarization (e.g. pyannote/speaker-diarization-community-1)')
                        ->value($getVal('diarization_model')),

                    Group::make([
                        Input::make('settings[min_speakers]')
                            ->type('number')
                            ->title('Min Speakers')
                            ->help('Minimum number of speakers to detect.')
                            ->value($getVal('min_speakers', 1)),

                        Input::make('settings[max_speakers]')
                            ->type('number')
                            ->title('Max Speakers')
                            ->help('Maximum number of speakers to detect.')
                            ->value($getVal('max_speakers', 5)),
                    ]),
                ]),
            ])
                ->title('Custom Speaches Configuration')
                ->description('These settings only apply if "Custom Speaches Server" is selected as the provider.'),
        ];
    }

    public function save(Request $request): void
    {
        $requestSettings = $request->get('settings', []);

        foreach ($requestSettings as $key => $value) {
            $setting = TranscriptionSetting::where('key', $key)->first();

            if (! $setting) {
                continue;
            }

            // Skip empty password fields
            if ($setting->is_private && empty($value)) {
                continue;
            }

            // Save using the model's mutator which handles encryption and types
            $setting->value = $value;
            $setting->save();
        }

        Toast::info('Transcription settings saved successfully.');
    }
}
