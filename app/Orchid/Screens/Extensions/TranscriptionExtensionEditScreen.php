<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Extensions;

use App\Models\Transcription\TranscriptionSetting;
use App\Orchid\Layouts\Transcription\BatchCredentialsListener;
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
            'key' => 'batch_api_provider',
        ], [
            'value' => '',
            'type' => 'string',
            'description' => 'API provider (unique_name) whose base URL and key are used for batch transcription (empty = manual base_url/api_key settings)',
            'is_private' => false,
        ]);

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
            'key' => 'realtime_available_modes',
        ], [
            'value' => 'onprem,openai',
            'type' => 'string',
            'description' => 'Comma-separated realtime modes users may pick in the UI (onprem, openai). Modes left out stay visible but disabled, and are rejected server-side.',
            'is_private' => false,
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

        $val = fn (string $key) => $settings->has($key) ? (string) $settings->get($key)->value : '';

        return [
            'settings' => $settings,
            // Scalar keys consumed by BatchCredentialsListener (re-rendered
            // when the batch provider selection changes).
            'batch_api_provider' => $val('batch_api_provider'),
            'batch_model' => $val('model'),
            'batch_base_url' => $val('base_url'),
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
                $providerOptions[$key] = 'OpenAI Compatible: '.($provider['provider_name'] ?? $provider['name'] ?? $key);
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
                ]),
                BatchCredentialsListener::class,
            ])
                ->title('Batch Transcription')
                ->description('Speech-to-text server for uploaded files. Credentials come from the selected API Provider, or from the manual fields when none is selected.'),

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
                    Select::make('settings[realtime_available_modes]')
                        ->options([
                            'onprem' => 'On-Prem (Realtime Bridge)',
                            'openai' => 'OpenAI Realtime API',
                        ])
                        ->multiple()
                        ->title('Modes offered to users')
                        ->help('Unselected modes stay VISIBLE but disabled in the UI so users can see what the system allows, and are rejected server-side.')
                        ->value(array_values(array_filter(explode(',', (string) $getVal('realtime_available_modes', 'onprem,openai'))))),

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

        // When batch credentials reference an api_providers entry, the model
        // must be one of that provider's active ai_models entries — reject the
        // save instead of persisting a combination the provider would refuse.
        $batchProviderKey = (string) ($requestSettings['batch_api_provider'] ?? '');
        if ($batchProviderKey !== '') {
            try {
                $providers = app(AiConfigService::class)->getProviders();
            } catch (\Exception $e) {
                Toast::error('Could not load API providers: '.$e->getMessage());

                return;
            }

            $provider = $providers[$batchProviderKey] ?? null;
            if (! $provider || ! ($provider['active'] ?? true)) {
                Toast::error("API provider '{$batchProviderKey}' is not configured or inactive.");

                return;
            }

            $model = (string) ($requestSettings['model'] ?? '');
            $known = collect($provider['models'] ?? [])
                ->filter(fn ($m) => ($m['active'] ?? true))
                ->first(fn ($m) => ($m['id'] ?? $m['model_id'] ?? '') === $model);
            if ($model === '' || ! $known) {
                Toast::error("Select a model from ai_models for provider '{$batchProviderKey}'.");

                return;
            }
        }

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

            // Multi-select posts an array; store as CSV like every other
            // setting (the column is a plain string). Never persist an empty
            // list — that would leave the UI with no selectable mode at all.
            if ($key === 'realtime_available_modes') {
                $modes = array_values(array_intersect(
                    is_array($value) ? $value : explode(',', (string) $value),
                    ['onprem', 'openai']
                ));
                if ($modes === []) {
                    $modes = ['onprem'];
                    Toast::warning('At least one realtime mode must stay enabled — kept On-Prem.');
                }
                $value = implode(',', $modes);
            }

            // Save using the model's mutator which handles encryption and types
            $setting->value = $value;
            $setting->save();
        }

        Toast::info('Transcription settings saved successfully.');
    }
}
