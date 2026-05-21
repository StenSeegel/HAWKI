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
            <input type="file" name="audio_file" id="audio_file" class="hidden">
        </form>

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
