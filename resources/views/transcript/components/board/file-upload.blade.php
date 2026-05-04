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
                <div id="loading-spinner" class="hidden" style="padding: 20px; text-align: center;">
                    <p class="loading-text" style="font-weight: bold; font-size: 16px;">Transkription läuft...</p>
                </div>
            </div>
        </div>
        <form id="transcript-upload-form" enctype="multipart/form-data">
            <input type="file" name="audio_file" id="audio_file" class="hidden">
        </form>


        <!-- Transkriptions-Ausgabe direkt hier im Upload-Bereich -->
        <div id="transcription-output-inline" class="transcription-output-container"
            style="width: 100%;" class="hidden">
            <!-- Textcontainer -->
            <div class="transcription-box" id="transcription-result-container-inline">
                <div id="transcription-result-inline"></div>
            </div>
        </div>
    </div>
</div>
