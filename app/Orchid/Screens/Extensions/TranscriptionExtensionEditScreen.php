<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Extensions;

use App\Models\Transcription\TranscriptionSetting;
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
        TranscriptionSetting::firstOrCreate([
            'key' => 'diarization_base_url',
        ], [
            'value' => '',
            'type' => 'string',
            'description' => 'Base URL of the diarization server (empty = batch transcription server)',
            'is_private' => false,
        ]);

        TranscriptionSetting::firstOrCreate([
            'key' => 'diarization_api_key',
        ], [
            'value' => '',
            'type' => 'string',
            'description' => 'API key for the diarization server (empty = batch transcription key)',
            'is_private' => true,
        ]);

        TranscriptionSetting::firstOrCreate([
            'key' => 'chat_realtime_provider',
        ], [
            'value' => 'onprem',
            'type' => 'string',
            'description' => 'Realtime provider for chat voice input (onprem = on-prem realtime bridge, openai = OpenAI Realtime API)',
            'is_private' => false,
        ]);

        TranscriptionSetting::firstOrCreate([
            'key' => 'onprem_api_provider',
        ], [
            'value' => 'ki-at-jlu',
            'type' => 'string',
            'description' => 'API provider (unique_name) whose base URL and key reach the gateway serving the on-prem realtime STT model',
            'is_private' => false,
        ]);

        TranscriptionSetting::firstOrCreate([
            'key' => 'onprem_realtime_model',
        ], [
            'value' => 'voxtral-mini-realtime',
            'type' => 'string',
            'description' => 'Model name of the on-prem realtime STT model on the gateway',
            'is_private' => false,
        ]);

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
                        ->title('Provider')
                        ->value($getVal('provider', 'custom_speaches')),

                    Input::make('settings[base_url]')
                        ->title('Base URL')
                        ->help('Multiple workers: comma-separated URLs.')
                        ->value($getVal('base_url')),

                    Input::make('settings[api_key]')
                        ->type('password')
                        ->title('API Key')
                        ->help('Leave blank to keep the current key.')
                        // Do not prefill password value
                        ->set('autocomplete', 'new-password'),

                    Input::make('settings[model]')
                        ->title('Model')
                        ->help('e.g. jlu/whisper-1 (gateway) or Systran/faster-whisper-large-v3')
                        ->value($getVal('model')),
                ]),
            ])
                ->title('Batch Transcription')
                ->description('Speech-to-text server for uploaded files.'),

            Layout::block([
                Layout::rows([
                    Input::make('settings[diarization_base_url]')
                        ->title('Base URL')
                        ->help('Empty = same server as batch transcription.')
                        ->value($getVal('diarization_base_url')),

                    Input::make('settings[diarization_api_key]')
                        ->type('password')
                        ->title('API Key')
                        ->help('Empty = same key as batch transcription.')
                        ->set('autocomplete', 'new-password'),

                    Input::make('settings[diarization_model]')
                        ->title('Model')
                        ->value($getVal('diarization_model')),

                ]),
            ])
                ->title('Speaker Detection (Diarization)')
                ->description('pyannote server; may run on a different machine than batch transcription.'),

            Layout::block([
                Layout::rows([
                    Select::make('settings[chat_realtime_provider]')
                        ->options([
                            'onprem' => 'On-Prem (Realtime Bridge)',
                            'openai' => 'OpenAI Realtime API',
                        ])
                        ->title('Chat Voice Provider')
                        ->value($getVal('chat_realtime_provider', 'onprem')),

                    Group::make([
                        Input::make('settings[onprem_api_provider]')
                            ->title('API Provider')
                            ->help('unique_name from API Providers.')
                            ->value($getVal('onprem_api_provider', 'ki-at-jlu')),

                        Input::make('settings[onprem_realtime_model]')
                            ->title('Model')
                            ->value($getVal('onprem_realtime_model', 'voxtral-mini-realtime')),
                    ]),
                ]),
            ])
                ->title('Realtime / Live Transcription')
                ->description('Word-level live streaming via the realtime bridge (chat mic and live transcript page).'),
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

            if ($key === 'chat_realtime_provider' && ! in_array($value, ['onprem', 'openai'], true)) {
                $value = 'onprem';
            }

            // Save using the model's mutator which handles encryption and types
            $setting->value = $value;
            $setting->save();
        }

        Toast::info('Transcription settings saved successfully.');
    }
}
