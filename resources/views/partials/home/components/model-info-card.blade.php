<div id="model-info-card" class="model-info-card" style="display: none;">
    <div class="mic-left">
        <header class="mic-header">
            <div class="mic-icon-container">
                <div id="mic-provider-logo" class="mic-provider-logo" data-provider-logo="default">
                    <x-icon name="layers" class="mic-icon"/>
                </div>
            </div>
            <div>
                <h1 class="mic-title" id="mic-model-name">Model Name</h1>
                <p class="mic-provider" id="mic-provider-name">Provider</p>
            </div>
        </header>

        <section class="mic-description-section">
            <p class="mic-description" id="mic-description">
                Description goes here.
            </p>
        </section>

        <section class="mic-capabilities-section">
            <h3 class="mic-section-title">FÄHIGKEITEN</h3>
            <div class="mic-capabilities" id="mic-capabilities">
                <!-- capabilities injected here -->
            </div>
        </section>
    </div>

    <div class="mic-right">
        <div class="mic-metrics">
            <div data-purpose="context-info">
                <h3 class="mic-section-title">KONTEXT</h3>
                <p class="mic-metric-val" id="mic-context">X Tokens</p>
            </div>

            <div data-purpose="knowledge-info">
                <h3 class="mic-section-title">WISSENSGRENZE</h3>
                <p class="mic-metric-val" id="mic-knowledge-cutoff">-</p>
            </div>

            <div data-purpose="cost-info">
                <h3 class="mic-section-title">KOSTEN</h3>
                <div class="mic-cost" id="mic-cost">
                    <span class="mic-cost-active">€</span><span class="mic-cost-inactive">€€€</span>
                </div>
            </div>
        </div>

        <footer class="mic-footer">
            <a class="mic-doc-link" id="mic-doc-link" href="#" target="_blank">
                <span>Dokumentation öffnen →</span>
            </a>
        </footer>
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
