<div id="model-info-card" class="model-info-card" style="display: none;">
    <div class="mic-left">
        <header class="mic-header">
            <div class="mic-icon-container">
                <div id="mic-provider-logo" class="mic-provider-logo" data-provider-logo="default">
                    <x-icon name="layers" class="mic-icon"/>
                </div>
            </div>
            <div>
                <h1 class="mic-title" id="mic-model-name"></h1>
                <p class="mic-provider" id="mic-provider-name"></p>
            </div>
        </header>

        <section class="mic-description-section">
            <p class="mic-description" id="mic-description"></p>
        </section>

        <section class="mic-capabilities-section">
            <h3 class="mic-section-title">{{ $translation["ModelCard_Capabilities"] ?? "Capabilities" }}</h3>
            <div class="mic-capabilities" id="mic-capabilities">
                {{-- capability tags injected by showModelInfoCard() --}}
            </div>
        </section>
    </div>

    <div class="mic-right">
        <div class="mic-metrics">
            <div data-purpose="context-info">
                <h3 class="mic-section-title">{{ $translation["ModelCard_Context"] ?? "Context" }}</h3>
                <p class="mic-metric-val" id="mic-context"></p>
            </div>

            <div data-purpose="knowledge-info">
                <h3 class="mic-section-title">{{ $translation["ModelCard_KnowledgeCutoff"] ?? "Knowledge cutoff" }}</h3>
                <p class="mic-metric-val" id="mic-knowledge-cutoff">-</p>
            </div>

            <div data-purpose="cost-info">
                <h3 class="mic-section-title">{{ $translation["ModelCard_Cost"] ?? "Cost" }}</h3>
                <div class="mic-cost" id="mic-cost">
                    <span class="mic-cost-active">€</span><span class="mic-cost-inactive">€€€</span>
                </div>
            </div>
        </div>

        <footer class="mic-footer">
            <a class="mic-doc-link" id="mic-doc-link" href="#" target="_blank" rel="noopener noreferrer">
                <span>{{ $translation["ModelCard_OpenDocumentation"] ?? "Open documentation" }}</span>
                <x-icon name="arrow-right" class="mic-doc-icon"/>
            </a>
        </footer>
    </div>
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
        <x-icon name="eye" class="mic-capability-icon"/>
    </div>
    <div data-capability-key="file_upload"
         data-label="{{ $translation['ModelCapabilityTag_FileUpload'] ?? 'File upload' }}"
         data-title="{{ $translation['ModelCapability_FileUpload'] ?? 'Supports file upload' }}">
        <x-icon name="paperclip" class="mic-capability-icon"/>
    </div>
    <div data-capability-key="web_search"
         data-label="{{ $translation['ModelCapabilityTag_WebSearch'] ?? 'Web search' }}"
         data-title="{{ $translation['ModelCapability_WebSearch'] ?? 'Supports web search' }}">
        <x-icon name="world" class="mic-capability-icon"/>
    </div>
    <div data-capability-key="reasoning"
         data-label="{{ $translation['ModelCapabilityTag_Reasoning'] ?? 'Advanced reasoning' }}"
         data-title="{{ $translation['ModelCapability_Reasoning'] ?? 'Supports reasoning' }}">
        <x-icon name="cpu" class="mic-capability-icon"/>
    </div>
    <div data-capability-key="image_gen"
         data-label="{{ $translation['ModelCapabilityTag_ImageGeneration'] ?? 'Image generation' }}"
         data-title="{{ $translation['ModelCapability_ImageGeneration'] ?? 'Supports image generation' }}">
        <x-icon name="stars" class="mic-capability-icon"/>
    </div>
    <div data-capability-key="text_generation"
         data-label="{{ $translation['ModelCapabilityTag_TextGeneration'] ?? 'Text generation' }}"
         data-title="{{ $translation['ModelCapability_TextGeneration'] ?? 'Supports text generation' }}">
        <x-icon name="message" class="mic-capability-icon"/>
    </div>
    {{-- Fallback icon for capability keys that have no dedicated template. --}}
    <div data-capability-key="__fallback">
        <x-icon name="check" class="mic-capability-icon"/>
    </div>
</div>

<div id="mic-provider-logo-templates" style="display: none;" aria-hidden="true">
    <div data-logo-key="default">
        <x-icon name="layers" class="mic-icon"/>
    </div>
    <div data-logo-key="openai">
        <x-icon name="openai" class="mic-icon"/>
    </div>
    <div data-logo-key="google">
        <x-icon name="gemini-color" class="mic-icon"/>
    </div>
    <div data-logo-key="anthropic">
        <x-icon name="claude-color" class="mic-icon"/>
    </div>
    <div data-logo-key="ollama">
        <x-icon name="cpu" class="mic-icon"/>
    </div>
</div>
