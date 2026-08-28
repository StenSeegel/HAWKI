<?php

declare(strict_types=1);

namespace App\Orchid\Screens\ModelSettings;

use App\Models\AiModel;
use App\Orchid\Layouts\ModelSettings\AiModelBasicInfoLayout;
use App\Orchid\Layouts\ModelSettings\AiModelInformationLayout;
use App\Orchid\Layouts\ModelSettings\AiModelStatusLayout;
use App\Orchid\Layouts\ModelSettings\AiModelToolsLayout;
use App\Services\Translation\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class AiModelEditScreen extends Screen
{
    /**
     * @var AiModel
     */
    public $model;

    /**
     * Query data.
     *
     *
     * @return array
     */
    public function query(AiModel $model): iterable
    {
        $this->model = $model;

        return [
            'model' => $model,
        ];
    }

    /**
     * Display header name.
     */
    public function name(): ?string
    {
        return 'Edit Model: '.$this->model->label;
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Configure model settings and view information';
    }

    /**
     * The screen's action buttons.
     *
     * @return \Orchid\Screen\Action[]
     */
    public function commandBar(): iterable
    {
        $queryParams = request()->only(['provider_filter', 'active_status', 'visible_status', 'search', 'date_range']);
        $backUrl = route('platform.models.language');

        if (! empty($queryParams)) {
            $backUrl .= '?'.http_build_query($queryParams);
        }

        return [
            Link::make('Back')
                ->href($backUrl)
                ->icon('bs.arrow-left-circle'),

            Button::make('Save')
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * Views.
     *
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(AiModelBasicInfoLayout::class)
                ->title('Model Information')
                ->description('System ID, provider details, and model identification.'),

            Layout::block(AiModelStatusLayout::class)
                ->title('Model Status')
                ->description('Control model availability and visibility for users.'),

            Layout::block(AiModelToolsLayout::class)
                ->title('Model Capabilities (Tools)')
                ->description('Configure which features and tools this model supports.'),

            Layout::block(AiModelInformationLayout::class)
                ->title('Provider Information')
                ->description('Technical specifications and capabilities from the API provider (read-only).'),
        ];
    }

    /**
     * Save model changes.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function save(Request $request)
    {
        try {
            // Validate basic model data
            $request->validate([
                'model.label' => 'required|string|max:255',
                'model.is_active' => 'boolean',
                'model.is_visible' => 'boolean',
                'model.settings.tools.file_upload' => 'nullable|boolean',
                'model.settings.tools.vision' => 'nullable|boolean',
                'model.settings.tools.web_search' => 'nullable|boolean',
                // The month picker submits an ISO month; reject anything else so a
                // hand-edited value cannot break the localized display.
                'model.settings.knowledge_cutoff' => 'nullable|date_format:Y-m',
            ]);

            // Get current settings and merge with tools from UI
            $settings = $this->model->settings ?? [];

            // Merge tools from UI checkboxes into settings
            $modelData = $request->get('model', []);
            if (isset($modelData['settings']['tools'])) {
                $settings['tools'] = $modelData['settings']['tools'];
            }

            // Allow UI metadata fields
            // description_en holds the English card text; without it in this
            // allow-list the new field would be dropped on save.
            $metaFields = ['description', 'description_en', 'context_size', 'cost_indicator', 'capabilities', 'documentation_url', 'knowledge_cutoff'];
            foreach ($metaFields as $metaField) {
                if (isset($modelData['settings']) && array_key_exists($metaField, $modelData['settings'])) {
                    $settings[$metaField] = $modelData['settings'][$metaField];
                }
            }

            // Fill missing English card texts by machine-translating the German ones,
            // so the model card is never half-German for English users.
            $settings = $this->autoTranslateMissingEnglishText($settings);

            // Store original values for change tracking
            $originalLabel = $this->model->label;
            $originalActive = $this->model->is_active;
            $originalVisible = $this->model->is_visible;
            $originalSettings = $this->model->settings;

            // Update model fields (exclude nested settings, we handle it separately)
            unset($modelData['settings']);
            $this->model->fill($modelData);
            $this->model->settings = $settings;
            $this->model->save();

            // Log successful update with change details
            $changes = [];
            if ($originalLabel !== $this->model->label) {
                $changes['label'] = ['from' => $originalLabel, 'to' => $this->model->label];
            }
            if ($originalActive !== $this->model->is_active) {
                $changes['is_active'] = ['from' => $originalActive, 'to' => $this->model->is_active];
            }
            if ($originalVisible !== $this->model->is_visible) {
                $changes['is_visible'] = ['from' => $originalVisible, 'to' => $this->model->is_visible];
            }
            if ($originalSettings !== $this->model->settings) {
                $changes['settings'] = 'updated';
            }

            Log::info('Language model updated successfully', [
                'model_id' => $this->model->id,
                'model_label' => $this->model->label,
                'provider_id' => $this->model->provider_id,
                'changes' => $changes,
                'updated_by' => auth()->id(),
            ]);

            Toast::success("Model '{$this->model->label}' has been updated successfully.");

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validation failed for language model update', [
                'model_id' => $this->model->id,
                'errors' => $e->errors(),
                'updated_by' => auth()->id(),
            ]);

            throw $e; // Re-throw validation exceptions to show form errors
        } catch (\Exception $e) {
            Log::error('Error updating language model', [
                'model_id' => $this->model->id,
                'model_label' => $this->model->label,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'updated_by' => auth()->id(),
            ]);

            Toast::error('An error occurred while saving: '.$e->getMessage());

            return back()->withInput();
        }

        return redirect()->route('platform.models.language.edit', $this->model);
    }

    /**
     * Machine-translate the German model card texts into English whenever the
     * English field was left empty.
     *
     * The admin keeps the last word: once a field holds text it is never
     * overwritten, and the generated text can be edited afterwards. A failing or
     * unconfigured translation service must never block saving the model, so
     * every error is swallowed and only surfaced as a warning toast.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function autoTranslateMissingEnglishText(array $settings): array
    {
        // Base key => English key. Only free prose needs translating; the knowledge
        // cutoff is a date and is formatted per language at display time instead.
        $translatable = [
            'description' => 'description_en',
        ];

        $pending = [];
        foreach ($translatable as $sourceKey => $targetKey) {
            $source = $settings[$sourceKey] ?? null;
            $existing = $settings[$targetKey] ?? null;

            if (is_string($source) && trim($source) !== '' && (! is_string($existing) || trim($existing) === '')) {
                $pending[$targetKey] = $source;
            }
        }

        if ($pending === []) {
            return $settings;
        }

        try {
            $translationService = app(TranslationService::class);
            $model = $this->resolveTranslationModel($translationService);

            // isAvailable() only probes DeepL, so it reports false on installations
            // that translate through an AI model instead. Trust the resolved model.
            if ($model === null && ! $translationService->isAvailable()) {
                Toast::warning('English description was left empty: no translation service or model is configured.');

                return $settings;
            }

            foreach ($pending as $targetKey => $source) {
                $result = $translationService->translate($source, 'DE', 'EN-US', null, $model);
                $translated = is_array($result['text'] ?? null) ? ($result['text'][0] ?? null) : ($result['text'] ?? null);

                if (is_string($translated) && trim($translated) !== '') {
                    $settings[$targetKey] = trim($translated);
                }
            }

            Toast::info('The English description was translated automatically. Please review it.');
        } catch (\Throwable $e) {
            Log::warning('Auto-translation of model card texts failed', [
                'model_id' => $this->model->id ?? null,
                'error' => $e->getMessage(),
            ]);

            Toast::warning('English texts could not be translated automatically: '.$e->getMessage());
        }

        return $settings;
    }

    /**
     * Pick the model used for auto-translation.
     *
     * Preference order: the model configured in the translation extension, then
     * the platform's default chat model. Returning null means "let the service
     * decide", which in practice is DeepL.
     */
    private function resolveTranslationModel(TranslationService $translationService): ?string
    {
        $configured = $translationService->resolveDefaultModelForType('translate', true);

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        try {
            // defaultModels is an AiModelMap, so ids come from toIdArray().
            $available = app(\App\Services\AI\AiService::class)->getAvailableModels();
            $default = $available->defaultModels->toIdArray()['default_model'] ?? null;

            if (is_string($default) && $default !== '') {
                return $default;
            }
        } catch (\Throwable $e) {
            Log::warning('Could not resolve a default model for auto-translation', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * The permissions required to access this screen.
     */
    public function permission(): ?iterable
    {
        return [
            'platform.modelsettings.models',
        ];
    }
}
