@extends('layouts.home')
@section('content')

<div class="scroll-container model-library-page">
    <div class="scroll-panel model-library-container">
        <div class="model-library-toolbar">
            <div>
                <h1 class="zero-b-margin">{{ $translation["ModelLibrary"] ?? "Modell-Bibliotek" }}</h1>
            </div>

            <div class="model-library-search">
                <input
                    id="model-library-provider-search"
                    type="text"
                    class="model-library-search-input"
                    placeholder="{{ $translation["SearchModels"] ?? "Modelle suchen..." }}"
                    aria-label="{{ $translation["SearchModels"] ?? "Modelle suchen..." }}"
                    autocomplete="off"
                />
            </div>
        </div>

        @php
            // Only models with a description get a card - an empty card says nothing
            // useful about the model.
            $activeModels = collect($models['models'] ?? [])
                ->filter(fn ($model) => (!isset($model['visible']) || $model['visible'])
                    && (($model['status'] ?? 'unknown') !== 'offline')
                    && \App\Services\AI\Value\LocalizedModelText::description($model) !== null)
                ->sortBy(function ($model) {
                    $providerOrder = (int) ($model['provider_display_order'] ?? 9999);
                    $displayOrder = (int) ($model['display_order'] ?? 9999);
                    $label = (string) ($model['label'] ?? $model['id'] ?? '');

                    return sprintf('%05d_%05d_%s', $providerOrder, $displayOrder, strtolower($label));
                })
                ->values();

            $normalizeProviderToken = static fn ($value) => strtolower(preg_replace('/[\s_-]+/', '', (string) ($value ?? '')));

            $resolveLogoKey = static function (array $model) use ($normalizeProviderToken): string {
                $providerCandidates = collect([
                    data_get($model, 'provider.id'),
                    data_get($model, 'provider.provider_name'),
                    data_get($model, 'provider_name'),
                    data_get($model, 'provider_id'),
                    data_get($model, 'api_provider'),
                ])->map($normalizeProviderToken)->filter()->values();

                if ($providerCandidates->contains(fn ($token) => str_contains($token, 'openai') || str_contains($token, 'responses'))) {
                    return 'openai';
                }
                if ($providerCandidates->contains(fn ($token) => str_contains($token, 'google') || str_contains($token, 'gemini'))) {
                    return 'google';
                }
                if ($providerCandidates->contains(fn ($token) => str_contains($token, 'anthropic') || str_contains($token, 'claude'))) {
                    return 'anthropic';
                }
                if ($providerCandidates->contains(fn ($token) => str_contains($token, 'ollama'))) {
                    return 'ollama';
                }

                $modelId = $normalizeProviderToken(data_get($model, 'id') ?? data_get($model, 'system_id'));
                $modelLabel = $normalizeProviderToken(data_get($model, 'label') ?? data_get($model, 'name'));

                if (
                    str_starts_with($modelId, 'gpt') ||
                    str_starts_with($modelId, 'o1') ||
                    str_starts_with($modelId, 'o3') ||
                    str_starts_with($modelId, 'o4') ||
                    str_contains($modelLabel, 'gpt')
                ) {
                    return 'openai';
                }
                if (str_contains($modelId, 'gemini') || str_contains($modelLabel, 'gemini')) {
                    return 'google';
                }
                if (str_contains($modelId, 'claude') || str_contains($modelLabel, 'claude')) {
                    return 'anthropic';
                }
                if (str_contains($modelId, 'ollama') || str_contains($modelLabel, 'ollama')) {
                    return 'ollama';
                }

                return 'default';
            };

            $sanitizeSvgMarkup = static function ($svgMarkup): ?string {
                if (!is_string($svgMarkup)) {
                    return null;
                }

                $svgMarkup = trim($svgMarkup);
                if ($svgMarkup === '' || !preg_match('/<svg[\s>]/i', $svgMarkup)) {
                    return null;
                }

                // Remove potentially unsafe constructs before rendering.
                $svgMarkup = preg_replace('/<\?xml[^>]*\?>/i', '', $svgMarkup);
                $svgMarkup = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svgMarkup);
                $svgMarkup = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svgMarkup);
                $svgMarkup = preg_replace('/<foreignObject\b[^>]*>.*?<\/foreignObject>/is', '', $svgMarkup);
                $svgMarkup = preg_replace('/\son[a-z0-9_-]+\s*=\s*(["\']).*?\1/is', '', $svgMarkup);
                $svgMarkup = preg_replace('/\s(?:href|xlink:href)\s*=\s*(["\'])\s*(javascript:|data:).*?\1/i', '', $svgMarkup);

                if (!preg_match('/<svg\b[^>]*>.*<\/svg>/is', $svgMarkup)) {
                    return null;
                }

                // Provider logos come from the API provider settings and must render
                // exactly as authored. A presentation attribute loses to any stylesheet
                // rule, so the app-wide `svg { ... }` rule in style.css would repaint
                // them. An inline style beats author rules, so pin the SVG's own paint
                // values there; attributes it does not set are pinned to the CSS
                // initial, which is what "no stylesheet at all" would produce.
                $paintDefaults = [
                    'fill' => '#000',
                    'stroke' => 'none',
                    'stroke-width' => '1',
                    'stroke-linecap' => 'butt',
                    'stroke-linejoin' => 'miter',
                ];

                return preg_replace_callback('/<svg\b([^>]*)>/i', static function ($match) use ($paintDefaults) {
                    $attributes = $match[1];
                    $declarations = [];

                    foreach ($paintDefaults as $property => $initial) {
                        $pattern = '/\s'.preg_quote($property, '/').'\s*=\s*(["\'])(.*?)\1/i';
                        $value = preg_match($pattern, $attributes, $found) && trim($found[2]) !== ''
                            ? trim($found[2])
                            : $initial;
                        $declarations[] = $property.':'.$value;
                    }

                    $pinned = implode(';', $declarations);

                    // An inline style the author wrote wins, so it is appended last.
                    if (preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/i', $attributes, $existing)) {
                        $merged = $pinned.';'.trim($existing[2]);
                        $attributes = preg_replace(
                            '/\sstyle\s*=\s*(["\']).*?\1/i',
                            ' style="'.htmlspecialchars($merged, ENT_QUOTES).'"',
                            $attributes,
                            1
                        );
                    } else {
                        $attributes .= ' style="'.htmlspecialchars($pinned, ENT_QUOTES).'"';
                    }

                    return '<svg'.$attributes.'>';
                }, $svgMarkup, 1);
            };

            $formatCostIndicator = static function ($costVal): array {
                $costActive = '';
                $costInactive = '';

                if ($costVal !== null && $costVal !== '') {
                    if (is_string($costVal) && str_contains($costVal, '€')) {
                        // Rebuild euro indicator from count to avoid malformed UTF-8 glyphs (�).
                        $euroCount = preg_match_all('/€/u', $costVal, $matches);
                        if ($euroCount === false) {
                            $euroCount = substr_count($costVal, '€');
                        }
                        $euroCount = max(1, min(4, (int) $euroCount));
                        $costActive = str_repeat('€', $euroCount);
                        $costInactive = str_repeat('€', 4 - $euroCount);
                    } elseif (is_numeric($costVal)) {
                        $level = (int) $costVal;
                        $costActive = str_repeat('€', max(0, $level));
                        $costInactive = str_repeat('€', max(0, 4 - $level));
                    } else {
                        $costActive = (string) $costVal;
                    }
                } else {
                    $costActive = '€';
                    $costInactive = '€€';
                }

                return [
                    'active' => $costActive,
                    'inactive' => $costInactive,
                ];
            };
        @endphp

        @if($activeModels->isEmpty())
            <div class="model-library-empty">
                {{ $translation["NoModelsConfigured"] ?? "Keine aktiven Modelle verfügbar." }}
            </div>
        @else
            <div class="model-library-grid">
                @foreach($activeModels as $model)
                    @php
                        $providerName = data_get($model, 'provider_name')
                            ?? data_get($model, 'provider.provider_name')
                            ?? data_get($model, 'provider.name')
                            ?? 'Unknown Provider';

                        $info = data_get($model, 'information', []);
                        $settings = data_get($model, 'settings', []);
                        $displayInfo = data_get($info, 'model_display_info', []);

                        $modelLabel = data_get($model, 'label') ?? data_get($model, 'name')
                            ?? ($translation['ModelCard_UnknownModel'] ?? 'Unknown model');
                        // Same resolver the filter above uses, so a rendered card always
                        // has real text. Admin-entered text is localized via the
                        // settings' *_en variant, not the language JSON files.
                        $description = \App\Services\AI\Value\LocalizedModelText::description($model)
                            ?? ($translation['ModelCard_NoDescription'] ?? 'No description available.');

                        // Compact form: 128000 -> "128K", 1000000 -> "1M".
                        $contextValue = \App\Services\AI\Value\LocalizedModelText::contextSize(
                            data_get($settings, 'context_size')
                            ?? data_get($displayInfo, 'context')
                            ?? data_get($info, 'context_size')
                            ?? data_get($info, 'context')
                        ) ?? '?';

                        $costValue = data_get($settings, 'cost_indicator')
                            ?? data_get($displayInfo, 'cost_indicator')
                            ?? data_get($info, 'cost_indicator')
                            ?? data_get($displayInfo, 'cost')
                            ?? data_get($info, 'costs')
                            ?? data_get($settings, 'costs');
                        $cost = $formatCostIndicator($costValue);

                        $tools = data_get($settings, 'tools') 
                            ?? data_get($displayInfo, 'tools') 
                            ?? data_get($info, 'tools') 
                            ?? [];

                        // Icon name and translation key per capability, matching the
                        // icons used in partials/home/components/models-list.blade.php.
                        $capabilityMeta = [
                            'file_upload'     => ['icon' => 'paperclip', 'label' => 'ModelCapabilityTag_FileUpload',      'title' => 'ModelCapability_FileUpload',      'default' => 'File upload'],
                            'vision'          => ['icon' => 'eye',       'label' => 'ModelCapabilityTag_Vision',          'title' => 'ModelCapability_Vision',          'default' => 'Image analysis'],
                            'web_search'      => ['icon' => 'world',     'label' => 'ModelCapabilityTag_WebSearch',       'title' => 'ModelCapability_WebSearch',       'default' => 'Web search'],
                            'code_interpreter'=> ['icon' => 'square-terminal', 'label' => 'ModelCapabilityTag_CodeInterpreter', 'title' => 'ModelCapability_CodeInterpreter', 'default' => 'Code execution'],
                            'reasoning'       => ['icon' => 'cpu',       'label' => 'ModelCapabilityTag_Reasoning',       'title' => 'ModelCapability_Reasoning',       'default' => 'Advanced reasoning'],
                            'image_gen'       => ['icon' => 'image',     'label' => 'ModelCapabilityTag_ImageGeneration', 'title' => 'ModelCapability_ImageGeneration', 'default' => 'Image generation', 'class' => 'image-generation-icon'],
                            'text_generation' => ['icon' => 'message',   'label' => 'ModelCapabilityTag_TextGeneration',  'title' => 'ModelCapability_TextGeneration',  'default' => 'Text generation'],
                        ];


                        
                        // A date, so it only needs re-formatting for the active language.
                        $knowledgeCutoff = \App\Services\AI\Value\LocalizedModelText::date(
                            data_get($settings, 'knowledge_cutoff')
                            ?? data_get($info, 'knowledge_cutoff')
                            ?? data_get($displayInfo, 'knowledge_cutoff')
                        ) ?? '-';

                        // Each entry is ['key' => <tool key or null>, 'text' => <label>], so the
                        // markup can pick an icon for known keys and still show legacy free text.
                        $rawCapabilities = data_get($settings, 'tools') ?? data_get($info, 'tools') ?? data_get($displayInfo, 'tools') ?? [];
                        $capabilities = [];
                        if (empty($rawCapabilities)) {
                            // Fallback to legacy comma-separated capability text
                            $legacy = data_get($settings, 'capabilities') ?? data_get($info, 'capabilities') ?? '';
                            if (!empty($legacy) && is_string($legacy)) {
                                foreach (array_map('trim', explode(',', $legacy)) as $entry) {
                                    if ($entry !== '') {
                                        $capabilities[] = ['key' => null, 'text' => $entry];
                                    }
                                }
                            }
                        } else {
                            foreach ($rawCapabilities as $k => $v) {
                                // Capabilities the user's role does not grant are not advertised.
                                if (in_array($k, $hiddenCapabilities ?? [], true)) {
                                    continue;
                                }
                                if ($v) {
                                    $meta = $capabilityMeta[$k] ?? null;
                                    $capabilities[] = [
                                        'key'  => $meta ? $k : null,
                                        'text' => $meta
                                            ? ($translation[$meta['label']] ?? $meta['default'])
                                            : ucfirst(str_replace('_', ' ', $k)),
                                    ];
                                }
                            }
                        }

                        // Every model generates text, so say so rather than showing nothing.
                        if (count($capabilities) === 0) {
                            $capabilities = [[
                                'key'  => 'text_generation',
                                'text' => $translation['ModelCapabilityTag_TextGeneration'] ?? 'Text generation',
                            ]];
                        }

                        $documentationUrl = data_get($settings, 'documentation_url')
                            ?? data_get($info, 'documentation_url')
                            ?? data_get($displayInfo, 'documentation_url')
                            ?? null;

                        $logoKey = $resolveLogoKey($model);
                        $providerLogoSvg = $sanitizeSvgMarkup(
                            data_get($model, 'provider_logo_svg')
                            ?? data_get($model, 'provider.logo_svg')
                            ?? data_get($model, 'provider.icon')
                        );
                        $hasCustomProviderLogo = !empty($providerLogoSvg);
                        $realModelId = data_get($model, 'id') ?? data_get($model, 'system_id');
                    @endphp

                    @php($useModelLabel = $translation['UseModel'] ?? 'Use model')
                    <article class="model-library-card" data-model-name="{{ $modelLabel }}" onclick="localStorage.setItem('definedModel', '{{ $realModelId }}'); window.location.href='/chat';" style="cursor: pointer;" role="button" tabindex="0" aria-label="{{ $useModelLabel }}: {{ $modelLabel }}"
                        onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); this.click(); }">
                        <div class="model-library-main">
                            <header class="model-library-header">
                                <div class="model-library-icon-container">
                                    <div class="model-library-provider-logo" data-provider-logo="{{ $hasCustomProviderLogo ? 'custom' : $logoKey }}">
                                        @if($hasCustomProviderLogo)
                                            {!! $providerLogoSvg !!}
                                        @else
                                            @switch($logoKey)
                                                @case('openai')
                                                    <x-icon name="openai" class="model-library-icon"/>
                                                    @break
                                                @case('google')
                                                    <x-icon name="gemini-color" class="model-library-icon"/>
                                                    @break
                                                @case('anthropic')
                                                    <x-icon name="claude-color" class="model-library-icon"/>
                                                    @break
                                                @case('ollama')
                                                    <x-icon name="cpu" class="model-library-icon"/>
                                                    @break
                                                @default
                                                    <x-icon name="layers" class="model-library-icon"/>
                                            @endswitch
                                        @endif
                                    </div>
                                </div>
                                <div>
                                    <h2 class="model-library-title">{{ $modelLabel }}</h2>
                                    <p class="model-library-provider">{{ $providerName }}</p>
                                </div>
                            </header>

                            <section class="model-library-description-section">
                                <p class="model-library-description">{{ $description }}</p>
                            </section>

                            <section class="model-library-capabilities-section">
                                <h3 class="model-library-section-title">{{ $translation["ModelCard_Capabilities"] ?? "Capabilities" }}</h3>
                                <div class="model-library-capabilities">
                                    @foreach($capabilities as $cap)
                                        @php($capMeta = $cap['key'] ? ($capabilityMeta[$cap['key']] ?? null) : null)
                                        <span class="model-library-capability-tag"
                                              @if($capMeta) title="{{ $translation[$capMeta['title']] ?? $capMeta['default'] }}" @endif>
                                            @if($capMeta)
                                                <span class="model-library-capability-icon-wrapper">
                                                    <x-icon :name="$capMeta['icon']" class="model-library-capability-icon {{ $capMeta['class'] ?? '' }}"/>
                                                </span>
                                            @endif
                                            <span>{{ $cap['text'] }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            </section>
                        </div>

                        <aside class="model-library-meta">
                            <div>
                                <h3 class="model-library-section-title">{{ $translation["ModelCard_Context"] ?? "Context" }}</h3>
                                {{-- The unit may be empty (German drops "Tokens"), so trim it off. --}}
                                <p class="model-library-metric-val">{{ trim($contextValue.' '.($translation["ModelCard_Tokens"] ?? "")) }}</p>
                            </div>

                            <div>
                                <h3 class="model-library-section-title">{{ $translation["ModelCard_KnowledgeCutoff"] ?? "Knowledge" }}</h3>
                                <p class="model-library-metric-val">{{ $knowledgeCutoff }}</p>
                            </div>

                            <div>
                                <h3 class="model-library-section-title">{{ $translation["ModelCard_Cost"] ?? "Cost" }}</h3>
                                <div class="model-library-cost">
                                    <span class="model-library-cost-active">{{ $cost['active'] }}</span><span class="model-library-cost-inactive">{{ $cost['inactive'] }}</span>
                                </div>
                            </div>

                            @if(!empty($documentationUrl))
                                <a class="model-library-doc-link" href="{{ $documentationUrl }}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation();">
                                    <span>{{ $translation["ModelCard_OpenDocumentation"] ?? "Documentation" }}</span>
                                    <x-icon name="arrow-right" class="model-library-doc-icon"/>
                                </a>
                            @endif

                            {{-- The whole card already opens a chat with this model, so this
                                 is a visible cue rather than its own control. --}}
                            <span class="model-library-use-link">
                                <span>{{ $useModelLabel }}</span>
                                <x-icon name="arrow-right" class="model-library-doc-icon"/>
                            </span>
                        </aside>
                    </article>
                @endforeach
            </div>

            <div class="model-library-empty" id="model-library-no-results" style="display:none;">
                {{ $translation["NoModelSearchResults"] ?? "Keine Modelle gefunden." }}
            </div>
        @endif
    </div>
</div>

<script>
    window.addEventListener('DOMContentLoaded', function () {
        const searchInput = document.getElementById('model-library-provider-search');
        if (!searchInput) return;

        const cards = Array.from(document.querySelectorAll('.model-library-card'));
        const noResults = document.getElementById('model-library-no-results');

        const normalizeText = (value) => {
            return String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim();
        };

        const applyModelFilter = () => {
            const query = normalizeText(searchInput.value);
            let visibleCards = 0;

            cards.forEach((card) => {
                const modelName = normalizeText(card.dataset.modelName);
                const isVisible = query === '' || modelName.includes(query);
                card.style.display = isVisible ? '' : 'none';
                if (isVisible) visibleCards++;
            });

            if (noResults) {
                noResults.style.display = visibleCards === 0 ? 'block' : 'none';
            }
        };

        searchInput.addEventListener('input', applyModelFilter);
        applyModelFilter();
    });
</script>

@endsection
