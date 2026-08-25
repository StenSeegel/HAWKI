<!-- Separate Transkriptions-Ausgabe für History -->
<div id="transcript-history-ui" class="transcript-workspace-ui hidden">
    <div class="transcript-workspace-section">
        
        <!-- Header Container -->
        <div class="transcript-workspace-header">
            <div class="transcript-header-top">
                <div class="transcript-header-titles">
                    <h2 id="current-transcript-title" class="transcript-title" title="Klicken, um den Titel zu bearbeiten" onclick="window.editWorkspaceTranscriptTitle(event)">Bearbeitung</h2>
                    <p class="transcript-subtitle">
                        <span class="transcript-status-dot"></span>
                        Ergebnisprotokoll bereit zur Prüfung
                    </p>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
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
