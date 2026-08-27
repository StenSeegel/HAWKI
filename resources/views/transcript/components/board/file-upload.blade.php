<!-- UI Datei Upload -->
<div id="transcript-file-ui" class="transcript-workspace-ui hidden">
    <div class="transcript-section">
        <div class="drop-zone-container">
            <div class="drop-zone" id="drop-zone">
                <div class="drop-zone-content" id="drop-zone-content">
                    <div class="drop-icon-wrapper">
                        <x-icon name="upload" class="drop-icon" />
                    </div>
                    <p class="drop-main-text">{{ $translation["TranscriptDropZoneText"] ?? 'Dokumente hierher ziehen, oder' }}</p>
                    <button type="button" class="btn-select-file">
                        {{ $translation["TranscriptSelectFromComputer"] ?? 'Vom Computer auswählen' }}
                    </button>
                    <div class="drop-sub-text">
                        <p>{{ $translation["TranscriptSupportedFormats"] ?? 'Wir unterstützen .mp3, .wav, .m4a und .ogg.' }}</p>
                        <p>{{ $translation["TranscriptMaxFileSize"] ?? 'Maximal 500MB pro Datei.' }}</p>
                    </div>
                </div>
                <div id="loading-spinner" class="hidden">
                    <p class="loading-text">{{ $translation["TranscriptInProgress"] ?? 'Transkription läuft...' }}</p>
                </div>
            </div>
        </div>
        <form id="transcript-upload-form" enctype="multipart/form-data">
            <input type="file" name="audio_file" id="audio_file" class="hidden" multiple>
        </form>

        <div id="multi-file-panel" class="multi-upload-card hidden">
            <div class="multi-upload-header panel-header">
                <h3 id="multi-file-title" class="multi-upload-title">{{ $translation["TranscriptFileListTitle"] ?? 'Dateiliste' }} (0)</h3>
                <div class="multi-upload-total" id="multi-file-total-size">{{ str_replace('{size}', '0', $translation["TranscriptTotalFileSize"] ?? 'Dateigröße: {size} MB gesamt') }}</div>
            </div>

            <div id="multi-file-list" class="multi-upload-list"></div>
        </div>

        <div id="upload-start-center-wrap" class="upload-start-center-wrap hidden">
            <button id="start-upload-btn" class="upload-start-center-btn" type="button">
                <span>{{ $translation["TranscriptStartTranscription"] ?? 'Transkription starten' }}</span>
            </button>
        </div>

        <!-- Transkriptions-Ausgabe direkt hier im Upload-Bereich -->
        <div id="transcription-output-inline" class="transcription-output-container hidden">
            <!-- Textcontainer -->
            <div class="transcription-box" id="transcription-result-container-inline">
                <div id="transcription-result-inline"></div>
            </div>
        </div>
    </div>
</div>
