<!-- Settings Subview Overlay -->
<div id="sidebar-settings-subview" class="dy-sidebar-subview" style="display: none; position: absolute; top:0; left:0; width:100%; height:100%; background-color: var(--background-main); z-index: 100; flex-direction: column;">
    <div class="header" style="padding: 1.5rem 1.5rem 1.5rem 1rem; border-bottom: 1px solid var(--border-color, #e2e8f0); display: flex; align-items: center; gap: 12px;">
        <button class="btn-xs" id="settingsSubviewBackBtn" onclick="closeTranscriptSettings()" style="padding: 0; color: var(--text-color); border: none; background: transparent; cursor: pointer;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        </button>
        <h3 class="title" style="margin: 0; padding-left: 0; font-size: 1.1rem; flex: 1;">Erweiterte Einstellungen</h3>
    </div>
    
    <div class="dy-sidebar-content-panel" style="flex: 1; margin-right: 0;">
        <div class="dy-sidebar-scroll-panel" style="padding: 15px;">
            <p style="font-size: 12px; color: var(--text-faded-color); margin-bottom: 20px;">Konfiguriere den Standard-Provider und das Modell für die Transkription.</p>
                            
            <div class="transcript-sidebar-field border-label-field" style="margin-bottom: 20px;">
                <label for="settings-provider-select">Provider</label>
                <div class="select-wrapper">
                    <x-icon name="layers" class="field-icon" />
                    <select id="settings-provider-select" name="settings_provider">
                        <option value="">Wird geladen...</option>
                    </select>
                </div>
            </div>

            <div class="transcript-sidebar-field border-label-field" style="margin-bottom: 30px;">
                <label for="settings-model-select">Modell</label>
                <div class="select-wrapper">
                    <x-icon name="layers" class="field-icon" />
                    <select id="settings-model-select" name="settings_model">
                        <option value="">Wird geladen...</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="subview-footer" style="padding: 15px; border-top: 1px solid var(--border-color, #e2e8f0);">
        <button class="btn-primary-blue" onclick="saveTranscriptSettings()" style="width: 100%; justify-content: center;">
            <x-icon name="upload" style="width: 16px; height: 16px; margin-right: 8px;" />
            Speichern
        </button>
    </div>
</div>
