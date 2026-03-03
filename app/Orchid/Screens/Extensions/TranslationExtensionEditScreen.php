<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Extensions;

use App\Models\AiModel;
use App\Models\TranslateSetting;
use App\Orchid\Traits\OrchidSettingsManagementTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Label;
use Orchid\Screen\Fields\Password;
use Orchid\Screen\Fields\Switcher;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class TranslationExtensionEditScreen extends Screen
{
    use OrchidSettingsManagementTrait;

    public function query(): iterable
    {
        return [];
    }

    public function name(): ?string
    {
        return 'Translation Extension';
    }

    public function description(): ?string
    {
        return 'Configure the Translation extension: set your DeepL API key and tune glossary behaviour.';
    }

    public function permission(): ?iterable
    {
        return ['platform.extensions'];
    }

    public function commandBar(): iterable
    {
        return [
            Link::make('Back')
                ->icon('bs.arrow-left-circle')
                ->route('platform.extensions'),

            Button::make('Save')
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    public function layout(): iterable
    {
        $all = TranslateSetting::all()->keyBy('key');

        // ── Model Settings ──────────────────────────────────────────────
        $modelFields = [];
        foreach (['default_model', 'filter_glossary', 'show_beta_message', 'beta_message_text'] as $key) {
            if ($setting = $all->get($key)) {
                $field = $this->createFieldForTranslateSetting($setting, "settings[{$key}]");
                if ($field) {
                    $modelFields[] = $field;
                }
            }
        }

        // ── DeepL Settings ──────────────────────────────────────────────
        $deeplFields = [];
        $deeplSetting = $all->get('deepl_api_key');
        $deeplKeyIsSet = $deeplSetting && ($deeplSetting->getAttributes()['value'] ?? '') !== '';

        if ($deeplKeyIsSet) {
            $deeplFields[] = Button::make('Remove Key')
                ->icon('bs.trash')
                ->class('btn btn-outline-danger ms-2')
                ->confirm('Are you sure you want to remove the DeepL API key? This will disable the DeepL provider.')
                ->method('clearDeeplKey');
        } elseif ($deeplSetting) {
            $field = $this->createFieldForTranslateSetting($deeplSetting, 'settings[deepl_api_key]');
            if ($field) {
                $deeplFields[] = $field;
            }
        }

        // ── AI Model Settings ────────────────────────────────────────────
        $aiFields = [];
        foreach (['allowed_models'] as $key) {
            if ($setting = $all->get($key)) {
                $field = $this->createFieldForTranslateSetting($setting, "settings[{$key}]");
                if ($field) {
                    $aiFields[] = $field;
                }
            }
        }

        return [
            Layout::block([Layout::rows($modelFields)])
                ->title('General Settings')
                ->description('Choose the default model and general translation behaviour.'),

            Layout::block([Layout::rows($deeplFields)])
                ->title('DeepL Settings')
                ->description('Connect the DeepL API for high-quality neural machine translation.'),

            Layout::block([Layout::rows($aiFields)])
                ->title('AI Model Settings')
                ->description('Restrict which AI models users may select for translation.'),
        ];
    }

    public function save(Request $request): void
    {
        $requestSettings = $request->get('settings', []);
        $count = 0;

        foreach ($requestSettings as $key => $value) {
            $setting = TranslateSetting::where('key', $key)->first();

            if (! $setting) {
                Log::warning("TranslateSetting not found: {$key}");

                continue;
            }

            // Skip empty password fields
            if ($setting->is_private && empty($value)) {
                continue;
            }

            // Multi-select fields submit as arrays; normalise them to JSON strings first
            if (is_array($value)) {
                $value = json_encode(array_values($value));
            }

            $normalizedNew = $this->normalizeValueForComparison($value, $setting->type);
            $normalizedExisting = $this->normalizeValueForComparison($setting->value, $setting->type);

            if ($normalizedExisting !== $normalizedNew) {
                $setting->value = $this->formatValueForDatabaseStorage($normalizedNew, $setting->type);
                $setting->save();

                Cache::forget('translate_settings_'.$key);
                $count++;
            }
        }

        if ($count > 0) {
            try {
                Artisan::call('config:clear');
                Cache::flush();
                Toast::success("{$count} translation setting(s) updated.");
            } catch (\Exception $e) {
                Toast::warning('Settings saved, but cache clear failed: '.$e->getMessage());
            }
        } else {
            Toast::info('No changes detected.');
        }
    }

    /**
     * Clear the stored DeepL API key, disabling the DeepL provider.
     */
    public function clearDeeplKey(): void
    {
        $setting = TranslateSetting::where('key', 'deepl_api_key')->first();

        if ($setting) {
            $setting->value = '';
            $setting->save();
            Cache::flush();
        }

        Toast::success('DeepL API key removed.');
    }

    /**
     * Build an Orchid field for a single TranslateSetting row.
     * Matches the Group + Label layout used across all admin settings screens.
     */
    private function createFieldForTranslateSetting(TranslateSetting $setting, string $inputName): mixed
    {
        $label = $setting->description ?? $setting->key;
        $key = $setting->key;

        if ($key === 'allowed_models') {
            return $this->buildAllowedModelsSelect($inputName, $label, $setting);
        }

        if ($key === 'default_model') {
            return $this->buildDefaultModelSelect($inputName, $label, $setting);
        }

        if ($setting->type === 'boolean') {
            return Group::make([
                Label::make("label_{$key}")
                    ->title($label)
                    ->addClass('fw-bold'),
                Switcher::make($inputName)
                    ->sendTrueOrFalse()
                    ->checked((bool) $setting->typed_value),
            ])
                ->alignCenter()
                ->widthColumns('1fr max-content');
        }

        if ($setting->is_private) {
            $hasValue = ($setting->getAttributes()['value'] ?? '') !== '';

            return Group::make([
                Label::make("label_{$key}")
                    ->title($label)
                    ->addClass('fw-bold'),
                Password::make($inputName)
                    ->placeholder($hasValue ? '••••••••' : '')
                    ->help($hasValue ? 'Leave blank to keep the existing key.' : ($setting->description ?? '')),
            ])
                ->alignCenter()
                ->widthColumns('1fr 1fr');
        }

        // Full-width simple input — used for longer text fields like beta_message_text
        return Input::make($inputName)
            ->title($label)
            ->value($setting->value ?? '')
            ->help($setting->description ?? '');
    }

    /**
     * Build a multi-select field listing all active AI models.
     */
    private function buildAllowedModelsSelect(string $inputName, string $label, TranslateSetting $setting): \Orchid\Screen\Fields\Select
    {
        $options = AiModel::query()
            ->where('is_active', true)
            ->orderBy('label')
            ->pluck('label', 'system_id')
            ->all();

        $selected = json_decode($setting->value ?? '[]', true) ?? [];

        return \Orchid\Screen\Fields\Select::make($inputName)
            ->title($label)
            ->options($options)
            ->value($selected)
            ->multiple()
            ->help('Select which AI models may be used for translation. Leave empty to allow all.');
    }

    /**
     * Build a single-select for the default translation model.
     *
     * Options are narrowed to the configured allowlist (same set as /text shows).
     * DeepL is prepended when its API key is configured.
     */
    private function buildDefaultModelSelect(string $inputName, string $label, TranslateSetting $setting): \Orchid\Screen\Fields\Select
    {
        // Resolve the allowed-models allowlist (system_ids)
        $allowedSetting = TranslateSetting::where('key', 'allowed_models')->first();
        $allowedSystemIds = json_decode($allowedSetting?->value ?? '[]', true) ?? [];
        $filterByAllowlist = ! empty($allowedSystemIds);

        // Build AI model options
        $query = AiModel::query()->where('is_active', true)->orderBy('label');
        $aiModels = $query->get(['system_id', 'label']);

        $options = [];

        // Prepend DeepL when the API key is configured
        $deeplKey = TranslateSetting::where('key', 'deepl_api_key')->value('value');
        if (! empty($deeplKey)) {
            $options['deepl'] = 'DeepL API Pro';
        }

        foreach ($aiModels as $model) {
            if ($filterByAllowlist && ! in_array($model->system_id, $allowedSystemIds, true)) {
                continue;
            }
            $options[$model->system_id] = $model->label;
        }

        return \Orchid\Screen\Fields\Select::make($inputName)
            ->title($label)
            ->options($options)
            ->value($setting->value ?? '')
            ->empty('— No default (use first available) —')
            ->help('The model pre-selected for users when they open the translation page.');
    }
}
