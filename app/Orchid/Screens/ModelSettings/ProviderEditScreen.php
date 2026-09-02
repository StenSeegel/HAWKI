<?php

declare(strict_types=1);

namespace App\Orchid\Screens\ModelSettings;

use App\Models\ApiProvider;
use App\Orchid\Layouts\ModelSettings\ProviderAdvancedSettingsLayout;
use App\Orchid\Layouts\ModelSettings\ProviderAuthenticationLayout;
use App\Orchid\Layouts\ModelSettings\ProviderBasicInfoLayout;
use App\Orchid\Layouts\ModelSettings\ProviderHawkiToolsLayout;
use App\Orchid\Layouts\ModelSettings\ProviderStatusLayout;
use App\Orchid\Traits\AiConnectionTrait;
use App\Orchid\Traits\OrchidLoggingTrait;
use App\Orchid\Traits\OrchidSettingsManagementTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class ProviderEditScreen extends Screen
{
    use AiConnectionTrait, OrchidLoggingTrait, OrchidSettingsManagementTrait {
        OrchidLoggingTrait::logBatchOperation insteadof OrchidSettingsManagementTrait;
    }

    /**
     * @var ApiProvider
     */
    public $provider;

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(ApiProvider $provider): iterable
    {
        $this->provider = $provider;

        // Load API format relationship
        $provider->load('apiFormat');

        // Convert additional_settings to string for form display
        $providerData = $provider->toArray();

        // The hawki_tools section of additional_settings is edited through its own
        // switches, so it is lifted out of the raw JSON field and back in on save.
        $additionalSettings = is_array($providerData['additional_settings'] ?? null)
            ? $providerData['additional_settings']
            : [];
        $providerData['hawki_tools'] = $additionalSettings['hawki_tools'] ?? [];

        if (isset($providerData['additional_settings']) && is_array($providerData['additional_settings'])) {
            $providerData['additional_settings'] = json_encode($providerData['additional_settings'], JSON_PRETTY_PRINT);
        } elseif (is_null($providerData['additional_settings'])) {
            $providerData['additional_settings'] = '';
        }

        return [
            'provider' => $providerData,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        $provider = $this->provider;
        // Fallback: Versuche Provider aus Route oder Request zu laden, falls nicht gesetzt
        if (! $provider || empty($provider->provider_name)) {
            $routeProvider = request()->route('provider');
            if ($routeProvider && $routeProvider instanceof \App\Models\ApiProvider) {
                $provider = $routeProvider;
            } elseif ($routeProvider && is_numeric($routeProvider)) {
                $provider = \App\Models\ApiProvider::find($routeProvider);
            }
        }
        $providerName = $provider && $provider->provider_name ? $provider->provider_name : 'Unknown';

        return 'Edit Provider: '.$providerName;
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Modify the settings for this API provider.';
    }

    /**
     * Permission required to access this screen.
     */
    public function permission(): ?iterable
    {
        return [
            'platform.modelsettings.providers',
        ];
    }

    /**
     * The screen's action buttons.
     *
     * @return \Orchid\Screen\Action[]
     */
    public function commandBar(): iterable
    {
        $provider = $this->provider;

        return [
            

            Button::make('Test Connection')
                ->icon('wifi')
                ->method('testProviderConnection')
                ->canSee($provider && $provider instanceof ApiProvider && $provider->is_active),

            Link::make('Back')
                ->icon('bs.arrow-left-circle')
                ->route('platform.models.api.providers'),

            Button::make('Save')
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(ProviderBasicInfoLayout::class)
                ->title('Basic Information')
                ->description('Configure the provider name, API format, display order, and logo.'),

            Layout::block(ProviderAuthenticationLayout::class)
                ->title('Authentication')
                ->description('Set up authentication credentials for this provider.'),

            Layout::block(ProviderStatusLayout::class)
                ->title('Provider Status')
                ->description('Control whether this provider is active and available for use.'),

            Layout::block(ProviderHawkiToolsLayout::class)
                ->title('HAWKI Tools')
                ->description('Serve single tools through HAWKI instead of the provider. Leave these off for providers that bring their own tools (e.g. Anthropic, Google or the OpenAI Responses API); enable them for providers without a native implementation.'),

            Layout::block(ProviderAdvancedSettingsLayout::class)
                ->title('Advanced Settings')
                ->description('Additional configuration options in JSON format.'),
        ];
    }

    /**
     * Save the provider settings.
     */
    public function save(Request $request, ApiProvider $provider)
    {
        try {
            $request->validate([
                'provider.provider_name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique(ApiProvider::class, 'provider_name')->ignore($provider),
                ],
                'provider.api_format_id' => [
                    'required',
                    'integer',
                    'exists:api_formats,id',
                ],
                'provider.base_url' => [
                    'required',
                    'string',
                    'url',
                    'max:500',
                ],
                'provider.api_key' => 'nullable|string|max:500',
                'provider.is_active' => 'boolean',
                'provider.display_order' => [
                    'required',
                    'integer',
                    'min:0',
                    'max:999',
                ],
                'provider.provider_logo_svg' => 'nullable|string|max:65535',
                'provider.additional_settings' => 'nullable|string',
                'provider.hawki_tools' => 'nullable|array',
            ]);

            // Store original values for change tracking
            $originalValues = $provider->getOriginal();

            $providerData = $request->input('provider');

            // Backward compatibility: if migration was not executed yet, skip persisting logo field.
            if (!Schema::hasColumn('api_providers', 'provider_logo_svg')) {
                unset($providerData['provider_logo_svg']);
                Toast::warning('Provider logo column is missing in database. Please run migrations (php artisan migrate).');
            }

            // Handle password field - only update if not empty
            if (empty($providerData['api_key'])) {
                unset($providerData['api_key']);
            }

            // Ensure display_order is treated as integer
            if (isset($providerData['display_order'])) {
                $providerData['display_order'] = (int) $providerData['display_order'];
            }

            // Normalize optional SVG logo field
            if (isset($providerData['provider_logo_svg']) && trim((string) $providerData['provider_logo_svg']) === '') {
                $providerData['provider_logo_svg'] = null;
            }

            // The HAWKI tool switches are edited separately from the raw JSON field,
            // so they are taken out of the request before the JSON is parsed and
            // merged back in afterwards. This keeps the switches authoritative for
            // the hawki_tools section and leaves the rest of the JSON untouched.
            $hawkiToolsInput = $providerData['hawki_tools'] ?? [];
            unset($providerData['hawki_tools']);

            // Validate and process JSON field
            if (! empty($providerData['additional_settings'])) {
                $decoded = json_decode($providerData['additional_settings'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    Toast::error('Additional settings must be valid JSON format.');

                    return back()->withInput();
                }
                $providerData['additional_settings'] = $decoded;
            } else {
                $providerData['additional_settings'] = null;
            }

            $hawkiTools = $this->normalizeHawkiToolsInput(
                is_array($hawkiToolsInput) ? $hawkiToolsInput : [],
                is_array($providerData['additional_settings'] ?? null)
                    ? ($providerData['additional_settings']['hawki_tools'] ?? [])
                    : []
            );

            if (! empty($hawkiTools)) {
                $settings = is_array($providerData['additional_settings'] ?? null)
                    ? $providerData['additional_settings']
                    : [];
                $settings['hawki_tools'] = $hawkiTools;
                $providerData['additional_settings'] = $settings;
            } elseif (is_array($providerData['additional_settings'] ?? null)) {
                unset($providerData['additional_settings']['hawki_tools']);
                if (empty($providerData['additional_settings'])) {
                    $providerData['additional_settings'] = null;
                }
            }

            // Use trait method for save with change detection
            $result = $this->saveModelWithChangeDetection(
                $provider,
                $providerData,
                $provider->provider_name,
                $originalValues
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('Validation failed for provider update', [
                'provider_id' => $provider->id,
                'errors' => $e->errors(),
                'updated_by' => auth()->id(),
            ]);

            throw $e; // Re-throw validation exceptions to show form errors
        } catch (\Exception $e) {
            Log::error('Error updating provider settings', [
                'provider_id' => $provider->id,
                'provider_name' => $provider->provider_name ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'updated_by' => auth()->id(),
            ]);

            Toast::error('An error occurred while saving: '.$e->getMessage());

            return back()->withInput();
        }

        return redirect()->route('platform.models.api.providers.edit', $provider);
    }

    /**
     * Reduce the HAWKI tool switches to the tools that are actually registered and
     * actually enabled, preserving any binding that was configured for them before.
     * Tools that are switched off are dropped entirely instead of being stored as
     * 'override' => false, so the stored JSON only ever lists real overrides.
     *
     * @param  array  $input  The submitted switches, keyed by tool.
     * @param  array  $stored  The previously stored hawki_tools section.
     */
    private function normalizeHawkiToolsInput(array $input, array $stored): array
    {
        $normalized = [];

        foreach (array_keys(config('hawki_tools.tools', [])) as $toolKey) {
            $override = filter_var(
                $input[$toolKey]['override'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            if (! $override) {
                continue;
            }

            $tool = ['override' => true];

            $binding = $stored[$toolKey]['binding'] ?? null;
            if (is_string($binding) && $binding !== '') {
                $tool['binding'] = $binding;
            }

            $normalized[$toolKey] = $tool;
        }

        return $normalized;
    }

    /**
     * Test connection to the provider.
     */
    public function testProviderConnection(Request $request): void
    {
        $providerId = $request->get('id') ?? ($this->provider ? $this->provider->id : null);
        $provider = $providerId ? ApiProvider::find($providerId) : $this->provider;

        if (!$provider) {
            Toast::error('Provider not found.');
            return;
        }

        if (!$provider->is_active) {
            Toast::warning("Provider '{$provider->provider_name}' is currently inactive.");
            return;
        }

        $startTime = microtime(true);

        try {
            // Use trait method for connection testing - same as ApiProvidersScreen
            $result = $this->testConnection($provider);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            if ($result['success']) {
                $responseTime = $result['response_time_ms'] ?? $duration;
                
                $this->logInfo("Provider connection test successful", [
                    'provider_id' => $provider->id,
                    'provider_name' => $provider->provider_name,
                    'response_time_ms' => $responseTime,
                    'endpoint' => $result['endpoint'] ?? 'unknown'
                ]);

                Toast::success("Connection to '{$provider->provider_name}' successful! Response time: {$responseTime}ms");
            } else {
                $this->logWarning("Provider connection test failed", [
                    'provider_id' => $provider->id,
                    'provider_name' => $provider->provider_name,
                    'error' => $result['error'],
                    'endpoint' => $result['endpoint'] ?? 'unknown',
                    'duration_ms' => $duration
                ]);

                Toast::error("Connection to '{$provider->provider_name}' failed: {$result['error']}");
            }

        } catch (\Exception $e) {
            $this->logError("Provider connection test exception", [
                'provider_id' => $provider->id,
                'provider_name' => $provider->provider_name,
                'exception' => $e->getMessage(),
                'duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ]);

            Toast::error("Failed to test provider connection: {$e->getMessage()}");
        }
    }
}
