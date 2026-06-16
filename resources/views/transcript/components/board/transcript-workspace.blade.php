<!-- Separate Transkriptions-Ausgabe für History -->
<div id="transcript-history-ui" class="transcript-workspace-ui hidden">
    <div class="transcript-workspace-section">
        
        <!-- Header Container -->
        <div class="transcript-workspace-header">
            <div class="transcript-header-top">
                <div>
                    <h2 id="current-transcript-title" class="transcript-title">Bearbeitung</h2>
                    <p class="transcript-subtitle">
                        <span class="transcript-status-dot"></span>
                        Ergebnisprotokoll bereit zur Prüfung
                    </p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <button id="toggle-audio-player-btn" class="btn-transcript-save" onclick="const p=document.getElementById('global-audio-player'); const isHidden = p.classList.toggle('hidden'); document.getElementById('icon-audio-open').style.display = isHidden ? 'block' : 'none'; document.getElementById('icon-audio-close').style.display = isHidden ? 'none' : 'block';" title="Audio Player umschalten" style="padding: 10px;">
                        <svg id="icon-audio-open" style="width: 16px; height: 16px; display: none;" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-panel-top-open"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="m9 14 3 3 3-3"/></svg>
                        <svg id="icon-audio-close" style="width: 16px; height: 16px; display: block;" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-panel-top-close"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="m9 16 3-3 3 3"/></svg>
                    </button>
                    <button id="sidebar-save-btn" class="btn-transcript-save" onclick="saveTranscriptChanges()">
                        <x-icon name="download" class="icon-small" style="width: 16px; height: 16px;" />
                        Datei speichern
                    </button>
                </div>
            </div>
            
            <!-- Tabs -->
            <div class="transcript-tabs">
                <button class="transcript-tab" data-tab="korrekturen" onclick="window.app.ui.switchTab('korrekturen')">Korrekturen</button>
                <button class="transcript-tab active" data-tab="vorschau" onclick="window.app.ui.switchTab('vorschau')">Vorschau</button>
                <button class="transcript-tab" data-tab="export" onclick="window.app.ui.switchTab('export')">Export</button>
            </div>
        </div>

        <!-- Audio Player -->
        <div id="global-audio-player" style="height: 64px; background-color: var(--background-secondary); border-bottom: var(--border-stroke-thin); flex-shrink: 0;"></div>

        <!-- Content Area -->
        <div class="transcript-content-area">
            @include('transcript.components.board.transcript-editor')
            @include('transcript.components.board.transcript-preview')
            @include('transcript.components.board.transcript-export')
        </div>
    </div>
</div>
