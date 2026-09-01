<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Extensions;

use App\Models\AiModel;
use App\Models\TranslateGlossary;
use App\Models\TranslateSetting;
use App\Orchid\Layouts\Extensions\GlossaryListLayout;
use App\Orchid\Traits\OrchidSettingsManagementTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Actions\ModalToggle;
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
        return [
            'glossaries' => TranslateGlossary::query()
                ->withCount('entries')
                ->orderBy('display_name')
                ->paginate(20),
        ];
    }

    public function name(): ?string
    {
        return 'Translation Extension';
    }

    public function description(): ?string
    {
        return 'Configure the Translation extension.';
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

        // ── Model Selection Settings ──────────────────────────────────────
        $modelSelectionFields = [];
        foreach (['translate_model', 'detection_model', 'rephrase_model', 'alternative_sentence_model', 'replace_word_model', 'correction_model'] as $key) {
            if ($setting = $all->get($key)) {
                $field = $this->createFieldForTranslateSetting($setting, "settings[{$key}]");
                if ($field) {
                    $modelSelectionFields[] = $field;
                }
            }
        }

        // ── General / UI Settings ──────────────────────────────────────────
        $generalFields = [];
        foreach (['filter_glossary', 'show_debug_infos', 'show_payload', 'show_beta_message', 'beta_message_text', 'enable_live_mode', 'code_execution_mcp_url'] as $key) {
            if ($setting = $all->get($key)) {
                $field = $this->createFieldForTranslateSetting($setting, "settings[{$key}]");
                if ($field) {
                    $generalFields[] = $field;
                }
            }
        }

        // ── DeepL Settings ──────────────────────────────────────────────
        $deeplFields = [];
        $deeplSetting = $all->get('deepl_api_key');
        $deeplAllowedRolesSetting = $all->get('deepl_allowed_roles');
        $deeplKeyIsSet = $deeplSetting && ($deeplSetting->getAttributes()['value'] ?? '') !== '';

        if ($deeplKeyIsSet) {
            $deeplFields[] = Group::make([
                ModalToggle::make('Check Status')
                    ->icon('bs.heart-pulse')
                    ->class('btn btn-outline-info')
                    ->modal('deeplStatusModal'),

                Button::make('Remove Key')
                    ->icon('bs.trash')
                    ->class('btn btn-outline-danger')
                    ->confirm('Are you sure you want to remove the DeepL API key? This will disable the DeepL provider.')
                    ->method('clearDeeplKey'),
            ])->autoWidth();
        } elseif ($deeplSetting) {
            $field = $this->createFieldForTranslateSetting($deeplSetting, 'settings[deepl_api_key]');
            if ($field) {
                $deeplFields[] = $field;
            }
        }

        if ($deeplAllowedRolesSetting) {
            $field = $this->createFieldForTranslateSetting($deeplAllowedRolesSetting, 'settings[deepl_allowed_roles]');
            if ($field) {
                $deeplFields[] = $field;
            }
        }

        // ── AI Model Allowlist & Feature Access ───────────────────────────
        $aiFields = [];
        foreach (['allowed_models', 'create_mode_allowed_roles'] as $key) {
            if ($setting = $all->get($key)) {
                $field = $this->createFieldForTranslateSetting($setting, "settings[{$key}]");
                if ($field) {
                    $aiFields[] = $field;
                }
            }
        }

        return [
            Layout::tabs([
                'API' => [
                    Layout::block([Layout::rows($deeplFields)])
                        ->title('DeepL API')
                        ->description('Manage your DeepL Pro connection.'),

                    Layout::block([Layout::rows($aiFields)])
                        ->title('Access Control')
                        ->description('Restrict available AI models and features for end users.'),
                ],
                'Debug' => [
                    Layout::block([Layout::rows($generalFields)])
                        ->title('UI & Behaviour')
                        ->description('Configure debugging, beta messages, and other general settings.'),
                ],
                'Feature Models' => [
                    Layout::block([Layout::rows($modelSelectionFields)])
                        ->title('AI Model Selection')
                        ->description('Assign specialized models for each translation and improvement task.'),
                ],
                'Glossaries' => [
                    Layout::block([GlossaryListLayout::class])
                        ->title('System Glossaries')
                        ->description('Manage your DeepL and system-wide translation glossaries.'),
                ],
            ]),

            Layout::modal('deeplStatusModal', [
                Layout::view('orchid.screens.deepl-status-modal'),
            ])
                ->title('DeepL API Status')
                ->withoutApplyButton()
                ->async('asyncDeeplStatus'),
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
     * Async data loader for the DeepL status modal.
     *
     * @return array{deeplStatus: array}
     */
    public function asyncDeeplStatus(): array
    {
        $setting = TranslateSetting::where('key', 'deepl_api_key')->first();
        $apiKey = $setting?->value;

        if (empty($apiKey)) {
            return ['deeplStatus' => ['error' => 'No DeepL API key configured.']];
        }

        // Detect Free vs. Pro key — free keys end with ':fx'
        $baseUrl = str_ends_with($apiKey, ':fx')
            ? 'https://api-free.deepl.com/v2/usage'
            : 'https://api.deepl.com/v2/usage';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'DeepL-Auth-Key '.$apiKey,
            ])->timeout(10)->get($baseUrl);

            if (! $response->successful()) {
                return ['deeplStatus' => ['error' => 'HTTP '.$response->status().' — check your API key.']];
            }

            $data = $response->json();

            return [
                'deeplStatus' => [
                    'products' => $data['products'] ?? [],
                    'character_count' => $data['character_count'] ?? 0,
                    'character_limit' => $data['character_limit'] ?? 0,
                    'api_key_character_count' => $data['api_key_character_count'] ?? 0,
                    'api_key_character_limit' => $data['api_key_character_limit'] ?? 0,
                    'stt_minutes_count' => $data['speech_to_text_minutes_count'] ?? 0,
                    'stt_minutes_limit' => $data['speech_to_text_minutes_limit'] ?? 0,
                    'billing_start' => isset($data['start_time'])
                        ? \Carbon\Carbon::parse($data['start_time'])->toDateString()
                        : null,
                    'billing_end' => isset($data['end_time'])
                        ? \Carbon\Carbon::parse($data['end_time'])->toDateString()
                        : null,
                ],
            ];
        } catch (\Exception $e) {
            return ['deeplStatus' => ['error' => $e->getMessage()]];
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
        $key = $setting->key;
        $label = match ($key) {
            'translate_model' => 'Translate Model',
            'rephrase_model' => 'Rephrase Model',
            'alternative_sentence_model' => 'Alternative Sentence Model',
            'replace_word_model' => 'ReplaceWord Model',
            'correction_model' => 'Correction Model',
            'detection_model' => 'Language Detection Model',
            'deepl_allowed_roles' => 'Allowed Roles for DeepL API',
            'create_mode_allowed_roles' => 'Allowed Roles for Create Mode',
            'code_execution_mcp_url' => 'Code Execution MCP Server URL',
            default => Str::headline($key),
        };
        $help = $setting->description ?? '';

        if ($key === 'allowed_models') {
            return $this->buildAllowedModelsSelect($inputName, $label, $setting);
        }

        if ($key === 'deepl_allowed_roles') {
            return $this->buildRolesSelect($inputName, $label, $setting, 'Select which roles can access DeepL API features. Leave empty to allow all roles.');
        }

        if ($key === 'create_mode_allowed_roles') {
            return $this->buildRolesSelect($inputName, $label, $setting, 'Select which roles can access the create mode. Leave empty to allow all roles.');
        }

        if (in_array($key, ['default_model', 'translate_model', 'rephrase_model', 'alternative_sentence_model', 'replace_word_model', 'correction_model', 'detection_model'])) {
            $showDeepl = in_array($key, ['translate_model', 'rephrase_model']);

            return $this->buildDefaultModelSelect($inputName, $label, $setting, $help, $showDeepl);
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

            return Password::make($inputName)
                ->title($label)
                ->placeholder($hasValue ? '••••••••' : '')
                ->help($hasValue ? 'Leave blank to keep the existing key.' : $help);
        }

        // Full-width simple input — used for longer text fields like beta_message_text
        return Input::make($inputName)
            ->title($label)
            ->value($setting->value ?? '')
            ->help($help);
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
     * Build a multi-select field listing all Orchid roles.
     */
    private function buildRolesSelect(string $inputName, string $label, TranslateSetting $setting, string $help): \Orchid\Screen\Fields\Select
    {
        $options = \Orchid\Platform\Models\Role::query()
            ->orderBy('name')
            ->pluck('name', 'slug')
            ->all();

        $selected = json_decode($setting->value ?? '[]', true) ?? [];

        return \Orchid\Screen\Fields\Select::make($inputName)
            ->title($label)
            ->options($options)
            ->value($selected)
            ->multiple()
            ->help($help);
    }

    /**
     * Build a single-select for the default translation model.
     *
     * Options are narrowed to the configured allowlist (same set as /text shows).
     * DeepL is prepended when its API key is configured.
     */
    private function buildDefaultModelSelect(string $inputName, string $label, TranslateSetting $setting, ?string $help = null, bool $showDeepl = true): \Orchid\Screen\Fields\Select
    {
        // Resolve the allowed-models allowlist (system_ids)
        $allowedSetting = TranslateSetting::where('key', 'allowed_models')->first();
        $allowedSystemIds = json_decode($allowedSetting?->value ?? '[]', true) ?? [];
        $filterByAllowlist = ! empty($allowedSystemIds);

        // Build AI model options
        $query = AiModel::query()->where('is_active', true)->orderBy('label');
        $aiModels = $query->get(['system_id', 'label']);

        $options = [];

        // Prepend DeepL when the API key is configured and it's requested
        if ($showDeepl) {
            $deeplKey = TranslateSetting::where('key', 'deepl_api_key')->value('value');
            if (! empty($deeplKey)) {
                $options['deepl'] = 'DeepL API Pro';
            }
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
            ->help($help ?? 'The model pre-selected for users when they open the translation page.');
    }

    /**
     * Delete a glossary entry directly from the list table.
     */
    public function deleteGlossary(Request $request): void
    {
        $glossary = TranslateGlossary::findOrFail($request->get('id'));
        $name = $glossary->display_name;
        $glossary->delete();

        Toast::success("Glossary '{$name}' deleted.");
    }
}
