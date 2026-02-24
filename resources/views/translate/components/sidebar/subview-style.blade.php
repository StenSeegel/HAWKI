<!-- Subview: Style & Tone Selector -->
<div id="sidebarStyleSubview" class="dy-sidebar-subview" style="display: none; position: absolute; top:0; left:0; width:100%; height:100%; background-color: var(--background-main); z-index: 100; flex-direction: column;">
    <div class="header" style="padding: 1.5rem 1rem; border-bottom: var(--border-stroke-thin); display: flex; align-items: center; gap: 12px;">
        <button class="btn-xs" id="styleSubviewBackBtn" style="padding: 0; color: var(--text-color);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        </button>
        <h3 class="title" style="margin: 0; padding-left: 0; font-size: 1.1rem; flex: 1;">{{ $translation["StyleRules"] ?? "Schreibstil" }}</h3>
    </div>
    
    <div class="dy-sidebar-content-panel" style="flex: 1; margin-right: 0;">
        <div class="dy-sidebar-scroll-panel">
            <div style="padding: 1rem;">
                <!-- Global Standard Option -->
                <button id="global-standard-btn" class="burger-item active" style="margin-bottom: 1.5rem; width: 100%;">
                    <span>{{ $translation["StyleDefault"] ?? "Standard" }}</span>
                </button>
                <!-- Writing Style Section -->
                <div id="style-section">
                    <h4 class="sidebar-group-title" style="margin-top: 0;">{{ $translation["WritingStyle"] ?? "Schreibstil" }}</h4>
                    <div id="styleList" class="selection-list" style="display: flex; flex-direction: column; gap: 4px;">
                    <button class="style-selector burger-item" data-style="business">
                        <span>{{ $translation["StyleBusiness"] ?? "Geschäftlich" }}</span>
                    </button>
                    <button class="style-selector burger-item" data-style="academic">
                        <span>{{ $translation["StyleAcademic"] ?? "Akademisch" }}</span>
                    </button>
                    <button class="style-selector burger-item" data-style="casual">
                        <span>{{ $translation["StyleCasual"] ?? "Locker" }}</span>
                    </button>
                    <button class="style-selector burger-item" data-style="simple">
                        <span>{{ $translation["StyleSimple"] ?? "Einfach" }}</span>
                    </button>
                </div>
                </div>

                <!-- Tone Section -->
                <div id="tone-section">
                    <h4 class="sidebar-group-title" style="margin-top: 1.5rem;">{{ $translation["Tone"] ?? "Tonfall" }}</h4>
                    <div id="toneList" class="selection-list" style="display: flex; flex-direction: column; gap: 4px;">
                    <button class="tone-selector burger-item" data-tone="confident">
                        <span>{{ $translation["ToneConfident"] ?? "Selbstbewusst" }}</span>
                    </button>
                    <button class="tone-selector burger-item" data-tone="diplomatic">
                        <span>{{ $translation["ToneDiplomatic"] ?? "Diplomatisch" }}</span>
                    </button>
                    <button class="tone-selector burger-item" data-tone="enthusiastic">
                        <span>{{ $translation["ToneEnthusiastic"] ?? "Enthusiastisch" }}</span>
                    </button>
                    <button class="tone-selector burger-item" data-tone="friendly">
                        <span>{{ $translation["ToneFriendly"] ?? "Freundlich" }}</span>
                    </button>
                </div>
                </div>

                <!-- Formality Section -->
                <div id="formality-section">
                    <h4 class="sidebar-group-title" style="margin-top: 1.5rem;">{{ $translation["Formality"] ?? "Formalität" }}</h4>
                    <div id="formalityList" class="selection-list" style="display: flex; flex-direction: column; gap: 4px;">
                    <button class="formality-selector burger-item" data-formality="formal">
                        <span>{{ $translation["FormalityFormal"] ?? "Formell" }}</span>
                    </button>
                    <button class="formality-selector burger-item" data-formality="informal">
                        <span>{{ $translation["FormalityInformal"] ?? "Informell" }}</span>
                    </button>
                </div>
                </div>
            </div>
        </div>
    </div>
</div>
