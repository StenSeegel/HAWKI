<div class="dy-main-panel" style="height: 100%; width: 100%;">
    <div class="dy-main-content" id="chat" style="display: flex;">
        <div class="chat-info"></div>

        <div class="chatlog" style="display: flex; flex-direction: column; flex-grow: 1; height: 100%;">
            <div class="transcript-choice" id="transcript-choice" style="display: flex;">
                <div class="choice-cards-container">
                    <!-- File Upload Card -->
                    <div class="choice-card" onclick="showTranscriptMode('file')">
                        <div class="choice-card-body">
                            <div class="choice-card-icon-wrapper">
                                <img src="/img/icon_file_upload.png" alt="File Upload" class="choice-card-image">
                            </div>
                            <div class="choice-card-content">
                                <h3>Datei hochladen</h3>
                                <p>Lade eine Audiodatei von deinem Computer hoch.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Live Record Card -->
                    <div class="choice-card" onclick="showTranscriptMode('live')">
                        <div class="choice-card-body">
                            <div class="choice-card-icon-wrapper">
                                <img src="/img/icon_live_transcript.png" alt="Live Transcript"
                                    class="choice-card-image">
                            </div>
                            <div class="choice-card-content">
                                <h3>Audio aufnehmen</h3>
                                <p>Starten einer Sprachaufnahme, die direkt transkribiert wird.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            @include('transcript.components.board.file-upload')
            @include('transcript.components.board.transcript-workspace')
            @include('transcript.components.board.live-record')
        </div>
    </div>
</div>
