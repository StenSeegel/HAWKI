<div class="dy-main-panel">
    <div class="dy-main-content" id="chat">
        <div class="chat-info"></div>

        <div class="chatlog">
            <div class="transcript-choice" id="transcript-choice">
                <div class="choice-cards-container">
                    <!-- File Upload Card -->
                    <div class="choice-card" onclick="window.app.ui.showTranscriptMode('file')">
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
                    <div class="choice-card" onclick="window.app.ui.showTranscriptMode('live')">
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
