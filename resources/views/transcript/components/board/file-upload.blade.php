<!-- UI Datei Upload -->
<div id="transcript-file-ui" class="hidden">
    <div class="transcript-section">
        <div class="drop-zone-container">
            <div class="drop-zone" id="drop-zone">
                <div class="drop-zone-content" id="drop-zone-content">
                    <div class="drop-icon-wrapper">
                        <x-icon name="upload" class="drop-icon" />
                    </div>
                    <p class="drop-main-text">Dokumente hierher ziehen, oder</p>
                    <button type="button" class="btn-select-file">
                        Vom Computer auswählen
                    </button>
                    <div class="drop-sub-text">
                        <p>Wir unterstützen .mp3, .wav, .m4a und .ogg.</p>
                        <p>Maximal 25MB pro Datei.</p>
                    </div>
                </div>
                <div id="loading-spinner" class="hidden">
                    <p class="loading-text">Transkription läuft...</p>
                </div>
            </div>
        </div>
        <form id="transcript-upload-form" enctype="multipart/form-data">
            <input type="file" name="audio_file" id="audio_file" class="hidden" multiple>
        </form>

        <div id="multi-file-panel" class="multi-upload-card hidden">
            <div class="multi-upload-header">
                <h3 id="multi-file-title" class="multi-upload-title">Dateiliste (0)</h3>
                <div class="multi-upload-total" id="multi-file-total-size">Dateigröße: 0 MB gesamt</div>
            </div>
            <div class="multi-upload-group-row">
                <span class="multi-upload-group-label">Transkript-Gruppen</span>
                <button type="button" id="add-group-btn" class="multi-upload-add-btn">+ Gruppe hinzufügen</button>
            </div>
            <div id="multi-file-list" class="multi-upload-list"></div>
        </div>

        <div id="upload-start-center-wrap" class="upload-start-center-wrap hidden">
            <button id="start-upload-btn" class="upload-start-center-btn" type="button">
                <span class="upload-start-play-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M8.6 6.9L17 12L8.6 17.1V6.9Z" fill="currentColor"></path>
                    </svg>
                </span>
                <span>Transkription starten</span>
            </button>
        </div>

        <!-- Container für aktive Hintergrundjobs -->
        <div id="active-jobs-container" class="active-jobs-container hidden" style="margin-top: 20px;">
            <h3 style="font-size: 1.1rem; margin-bottom: 15px; color: var(--text-color, #333);">Aktive Verarbeitungen</h3>
            <div id="active-jobs-list" style="display: flex; flex-direction: column; gap: 10px;">
                <!-- Jobs werden hier via JS gerendert -->
            </div>
        </div>

        <!-- Transkriptions-Ausgabe direkt hier im Upload-Bereich -->
        <h2 id="current-transcript-title-inline" class="transcript-title hidden"></h2>
        <div id="transcription-output-inline" class="transcription-output-container hidden">
            <!-- Textcontainer -->
            <div class="transcription-box" id="transcription-result-container-inline">
                <div id="transcription-result-inline"></div>
            </div>
        </div>
    </div>
</div>
