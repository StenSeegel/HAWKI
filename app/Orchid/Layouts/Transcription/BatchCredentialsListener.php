<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Transcription;

use App\Services\AI\Config\AiConfigService;
use Illuminate\Http\Request;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Listener;
use Orchid\Screen\Repository;
use Orchid\Support\Facades\Layout;

/**
 * Batch STT credentials block. The admin either references an entry from
 * `api_providers` (credentials are reused, the model is picked from that
 * provider's `ai_models`) or configures a server manually (free-text base
 * URL, key and model — e.g. a Speaches worker whose models are not in
 * `ai_models`). Re-renders when the provider selection changes.
 */
class BatchCredentialsListener extends Listener
{
    /**
     * @var string[]
     */
    protected $targets = ['settings.batch_api_provider'];

    protected function layouts(): iterable
    {
        $selected = (string) $this->query->get('batch_api_provider');

        $providerOptions = ['' => '— Manual configuration (base URL + API key below) —'];
        $modelOptions = [];

        try {
            foreach (app(AiConfigService::class)->getProviders() as $key => $provider) {
                if (! ($provider['active'] ?? true)) {
                    continue;
                }
                $providerOptions[$key] = $provider['provider_name'] ?? $provider['name'] ?? $key;

                if ($key === $selected) {
                    foreach ($provider['models'] ?? [] as $model) {
                        if (! ($model['active'] ?? true)) {
                            continue;
                        }
                        $id = (string) ($model['id'] ?? $model['model_id'] ?? '');
                        if ($id !== '') {
                            $modelOptions[$id] = $model['label'] ?? $id;
                        }
                    }
                }
            }
        } catch (\Exception) {
            // Provider listing must not break the settings screen (e.g. DB
            // decryption failure) — the manual fields below still work.
        }

        $fields = [
            Select::make('settings[batch_api_provider]')
                ->options($providerOptions)
                ->title('API Provider')
                ->help('Reuses the base URL and API key of the selected API Provider — no need to enter credentials again.')
                ->value($selected),
        ];

        if ($selected === '') {
            $fields[] = Input::make('settings[base_url]')
                ->title('Base URL')
                ->help('Multiple workers: comma-separated URLs.')
                ->value((string) $this->query->get('batch_base_url'));

            $fields[] = Input::make('settings[api_key]')
                ->type('password')
                ->title('API Key')
                ->help('Leave blank to keep the current key.')
                ->set('autocomplete', 'new-password');

            $fields[] = Input::make('settings[model]')
                ->title('Model')
                ->help('e.g. Systran/faster-whisper-large-v3 (Speaches worker)')
                ->value((string) $this->query->get('batch_model'));
        } else {
            $fields[] = Select::make('settings[model]')
                ->options($modelOptions)
                ->title('Model')
                ->help('Models configured in ai_models for this provider.')
                ->value((string) $this->query->get('batch_model'))
                ->empty('— select a model —', '');
        }

        return [
            Layout::rows($fields),
        ];
    }

    public function handle(Repository $repository, Request $request): Repository
    {
        return $repository
            ->set('batch_api_provider', (string) $request->input('settings.batch_api_provider', ''))
            ->set('batch_model', (string) $request->input('settings.model', ''))
            ->set('batch_base_url', (string) $request->input('settings.base_url', ''));
    }
}
