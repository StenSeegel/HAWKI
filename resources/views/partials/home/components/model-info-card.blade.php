{{-- Hover card for the model picker.

     The model library is the single source of truth for how a model card looks,
     so this markup reuses the .model-library-* classes verbatim and only adds a
     positioning shell (#model-info-card) for the popup behaviour. Do not add
     card styling here - change public/css/model_library.css instead.

     The ids are what showModelInfoCard() in public/js/home_functions.js fills. --}}
<div id="model-info-card" class="model-info-card" style="display: none;">
    <article class="model-library-card">
        <div class="model-library-main">
            <header class="model-library-header">
                <div class="model-library-icon-container">
                    <div id="mic-provider-logo" class="model-library-provider-logo" data-provider-logo="default">
                        <x-icon name="layers" class="model-library-icon"/>
                    </div>
                </div>
                <div>
                    <h2 class="model-library-title" id="mic-model-name"></h2>
                    <p class="model-library-provider" id="mic-provider-name"></p>
                </div>
            </header>

            <section class="model-library-description-section">
                <p class="model-library-description" id="mic-description"></p>
            </section>

            <section class="model-library-capabilities-section">
                <h3 class="model-library-section-title">{{ $translation["ModelCard_Capabilities"] ?? "Capabilities" }}</h3>
                <div class="model-library-capabilities" id="mic-capabilities">
                    {{-- capability tags injected by showModelInfoCard() --}}
                </div>
            </section>
        </div>

        <aside class="model-library-meta">
            <div>
                <h3 class="model-library-section-title">{{ $translation["ModelCard_Context"] ?? "Context" }}</h3>
                <p class="model-library-metric-val" id="mic-context"></p>
            </div>

            <div>
                <h3 class="model-library-section-title">{{ $translation["ModelCard_KnowledgeCutoff"] ?? "Knowledge cutoff" }}</h3>
                <p class="model-library-metric-val" id="mic-knowledge-cutoff">-</p>
            </div>

            <div>
                <h3 class="model-library-section-title">{{ $translation["ModelCard_Cost"] ?? "Cost" }}</h3>
                <div class="model-library-cost" id="mic-cost">
                    <span class="model-library-cost-active">€</span><span class="model-library-cost-inactive">€€€</span>
                </div>
            </div>

            <a class="model-library-doc-link" id="mic-doc-link" href="#" target="_blank" rel="noopener noreferrer">
                <span>{{ $translation["ModelCard_OpenDocumentation"] ?? "Open documentation" }}</span>
                <x-icon name="arrow-right" class="model-library-doc-icon"/>
            </a>
        </aside>
    </article>
</div>

{{-- Localized strings the card needs at runtime, read by showModelInfoCard(). --}}
<div id="mic-strings" style="display: none;" aria-hidden="true"
     data-unknown-model="{{ $translation['ModelCard_UnknownModel'] ?? 'Unknown model' }}"
     data-unknown-provider="{{ $translation['ModelCard_UnknownProvider'] ?? 'Unknown provider' }}"
     data-no-description="{{ $translation['ModelCard_NoDescription'] ?? 'No description available.' }}"
     data-tokens="{{ $translation['ModelCard_Tokens'] ?? 'Tokens' }}"></div>

{{-- Capability tag templates: icon markup plus the localized label and tooltip.
     Keyed by the tool key used in the model's `tools` map. --}}
<div id="mic-capability-templates" style="display: none;" aria-hidden="true">
    <div data-capability-key="vision"
         data-label="{{ $translation['ModelCapabilityTag_Vision'] ?? 'Image analysis' }}"
         data-title="{{ $translation['ModelCapability_Vision'] ?? 'Supports image input' }}">
        <x-icon name="eye" class="model-library-capability-icon"/>
    </div>
    <div data-capability-key="file_upload"
         data-label="{{ $translation['ModelCapabilityTag_FileUpload'] ?? 'File upload' }}"
         data-title="{{ $translation['ModelCapability_FileUpload'] ?? 'Supports file upload' }}">
        <x-icon name="paperclip" class="model-library-capability-icon"/>
    </div>
    <div data-capability-key="web_search"
         data-label="{{ $translation['ModelCapabilityTag_WebSearch'] ?? 'Web search' }}"
         data-title="{{ $translation['ModelCapability_WebSearch'] ?? 'Supports web search' }}">
        <x-icon name="world" class="model-library-capability-icon"/>
    </div>
    <div data-capability-key="reasoning"
         data-label="{{ $translation['ModelCapabilityTag_Reasoning'] ?? 'Advanced reasoning' }}"
         data-title="{{ $translation['ModelCapability_Reasoning'] ?? 'Supports reasoning' }}">
        <x-icon name="cpu" class="model-library-capability-icon"/>
    </div>
    <div data-capability-key="image_gen"
         data-label="{{ $translation['ModelCapabilityTag_ImageGeneration'] ?? 'Image generation' }}"
         data-title="{{ $translation['ModelCapability_ImageGeneration'] ?? 'Supports image generation' }}">
        <x-icon name="stars" class="model-library-capability-icon"/>
    </div>
    <div data-capability-key="text_generation"
         data-label="{{ $translation['ModelCapabilityTag_TextGeneration'] ?? 'Text generation' }}"
         data-title="{{ $translation['ModelCapability_TextGeneration'] ?? 'Supports text generation' }}">
        <x-icon name="message" class="model-library-capability-icon"/>
    </div>
    {{-- Fallback icon for capability keys that have no dedicated template. --}}
    <div data-capability-key="__fallback">
        <x-icon name="check" class="model-library-capability-icon"/>
    </div>
</div>

<div id="mic-provider-logo-templates" style="display: none;" aria-hidden="true">
    <div data-logo-key="default">
        <x-icon name="layers" class="model-library-icon"/>
    </div>
    <div data-logo-key="openai">
        <x-icon name="openai" class="model-library-icon"/>
    </div>
    <div data-logo-key="google">
        <x-icon name="gemini-color" class="model-library-icon"/>
    </div>
    <div data-logo-key="anthropic">
        <x-icon name="claude-color" class="model-library-icon"/>
    </div>
    <div data-logo-key="ollama">
        <x-icon name="cpu" class="model-library-icon"/>
    </div>
</div>
