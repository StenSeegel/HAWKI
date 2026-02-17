<div class="header">
    <button id="translationModeBtn" class="btn-md-stroke active">
        <div class="icon">
            <x-icon name="translate-icon"/>
        </div>
        <div class="label"><strong>{{ $translation["Translate"] ?? "Übersetzen" }}</strong></div>
    </button>
        <button id="writingModeBtn" class="btn-md-stroke">
        <div class="icon">
            <x-icon name="edit"/>
        </div>
        <div class="label"><strong>{{ $translation["ImproveText"] ?? "Überarbeiten" }}</strong></div>
    </button>
</div>
<div class="dy-sidebar-content-panel">
        <div class="dy-sidebar-scroll-panel">
        <div class="sidebar-section-container">
            
            <div class="sidebar-section">
                <h4 class="sidebar-group-title">{{ $translation["LanguageModel"] ?? "Sprachmodell" }}</h4>
                <div class="sidebar-item" id="model-selector-btn" style="cursor: pointer; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div class="sidebar-item-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        </div>
                        <span class="sidebar-item-label" id="selectedModelLabel">{{ $translation["SelectModel"] ?? "Select Model" }}</span>
                    </div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-faded-color);"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </div>
            </div>

            <div class="sidebar-section">
                <h4 class="sidebar-group-title">{{ $translation["Customizations"] ?? "Anpassungen" }}</h4>
                
                <div class="sidebar-item" id="glossary-btn" style="cursor: pointer; justify-content: space-between;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div class="sidebar-item-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                        </div>
                        <span class="sidebar-item-label">{{ $translation["Glossaries"] ?? "Glossare" }}</span>
                        <span id="glossaryCountBadge" style="font-size: 0.75rem; color: var(--text-faded-color); font-weight: 500;">0/0</span>
                    </div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--text-faded-color);"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </div>

                <div class="sidebar-item disabled">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <div class="sidebar-item-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                        </div>
                        <span class="sidebar-item-label">{{ $translation["StyleRules"] ?? "Schreibstil" }}</span>
                        <span class="badge-pro">{{ $translation["ToDo"] ?? "toDo" }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
