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
            $activeModels = collect($models['models'] ?? [])
                ->filter(fn ($model) => (!isset($model['visible']) || $model['visible']) && (($model['status'] ?? 'unknown') !== 'offline'))
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

                return preg_match('/<svg\b[^>]*>.*<\/svg>/is', $svgMarkup) ? $svgMarkup : null;
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

                        $modelLabel = data_get($model, 'label') ?? data_get($model, 'name') ?? 'Unknown Model';
                        $description = data_get($settings, 'description')
                            ?? data_get($displayInfo, 'description')
                            ?? data_get($info, 'description')
                            ?? 'Keine Beschreibung verfügbar.';

                        $contextValue = data_get($settings, 'context_size')
                            ?? data_get($displayInfo, 'context')
                            ?? data_get($info, 'context_size')
                            ?? data_get($info, 'context')
                            ?? '?';
                        if (is_numeric($contextValue)) {
                            $contextValue = number_format((int) $contextValue, 0, ',', '.');
                        }

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

                        $toolLabels = [
                            'file_upload' => 'File Uploads',
                            'vision'      => 'Image Analysis',
                            'web_search'  => 'Web Searches',
                            'reasoning'   => 'Advanced Reasoning',
                            'image_gen'   => 'Image Generation',
                        ];


                        
                        $knowledgeCutoff = data_get($settings, 'knowledge_cutoff')
                            ?? data_get($info, 'knowledge_cutoff')
                            ?? data_get($displayInfo, 'knowledge_cutoff')
                            ?? '-';

                        $capabilities = data_get($settings, 'tools') ?? data_get($info, 'tools') ?? data_get($displayInfo, 'tools') ?? [];
                        if (empty($capabilities)) {
                            // Fallback to legacy
                            $legacy = data_get($settings, 'capabilities') ?? data_get($info, 'capabilities') ?? '';
                            if (!empty($legacy) && is_string($legacy)) {
                                $capabilities = array_map('trim', explode(',', $legacy));
                            }
                        } else {
                            $mappedCapabilities = [];
                            foreach ($capabilities as $k => $v) {
                                if ($v) {
                                    $mappedCapabilities[] = $toolLabels[$k] ?? ucfirst($k);
                                }
                            }
                            $capabilities = $mappedCapabilities;
                        }

                        if (count($capabilities) === 0) {
                            $capabilities = ['Text generierung'];
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

                    <article class="model-library-card" data-model-name="{{ $modelLabel }}" onclick="localStorage.setItem('definedModel', '{{ $realModelId }}'); window.location.href='/chat';" style="cursor: pointer;">
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
                                <h3 class="model-library-section-title">FÄHIGKEITEN</h3>
                                <div class="model-library-capabilities">
                                    @foreach($capabilities as $cap)
                                        <span class="model-library-capability-tag">{{ $cap }}</span>
                                    @endforeach
                                </div>
                            </section>
                        </div>

                        <aside class="model-library-meta">
                            <div>
                                <h3 class="model-library-section-title">KONTEXT</h3>
                                <p class="model-library-metric-val">{{ $contextValue }} Tokens</p>
                            </div>

                            <div>
                                <h3 class="model-library-section-title">WISSENSGRENZE</h3>
                                <p class="model-library-metric-val">{{ $knowledgeCutoff }}</p>
                            </div>

                            <div>
                                <h3 class="model-library-section-title">KOSTEN</h3>
                                <div class="model-library-cost">
                                    <span class="model-library-cost-active">{{ $cost['active'] }}</span><span class="model-library-cost-inactive">{{ $cost['inactive'] }}</span>
                                </div>
                            </div>

                            @if(!empty($documentationUrl))
                                <a class="model-library-doc-link" href="{{ $documentationUrl }}" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation();">
                                    <span>Dokumentation öffnen →</span>
                                </a>
                            @endif
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
