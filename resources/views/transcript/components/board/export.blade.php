<!-- UI Export Optionen -->
<div id="transcript-export-ui" class="export-section hidden" style="flex-direction: column; height: 100%; width: 100%; border-radius: 20px;">
    <div class="transcript-section" style="padding: 20px; height: 100%; width: 100%; display: flex; flex-direction: column; align-items: stretch; justify-content: flex-start;">
        <div class="transcription-output-container" style="margin: 0 auto; flex: 1; display: flex; flex-direction: column; width: 100%; max-width: 1200px;">
            <div class="export-header" style="margin-bottom: 24px; flex-shrink: 0;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                    <div>
                        <h1 class="export-title">Export Vorschau</h1>
                        <p class="export-subtitle" id="export-preview-subtitle">Überprüfe das Format vor dem Herunterladen.</p>
                    </div>
                    <button class="btn btn-primary" onclick="triggerExportDownload()" style="display: flex; align-items: center; gap: 10px; border-radius: 12px; padding: 10px 18px; font-weight: 600;">
                        <x-icon name="upload" style="width: 18px; height: 18px;" />
                        Datei herunterladen
                    </button>
                </div>
            </div>
            
            <div class="export-preview-container" style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 16px; padding: 24px; position: relative; overflow-y: auto; max-height: 70vh; flex: 1; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                <pre id="export-preview-content" style="font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 13px; line-height: 1.6; color: #334155; white-space: pre-wrap; margin: 0;"></pre>
            </div>
        </div>
    </div>
</div>
