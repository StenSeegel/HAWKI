@extends('layouts.home')
@section('content')
    <div class="main-panel-grid">
        <div class="dy-sidebar expanded" id="transcript-sidebar">
            <div class="dy-sidebar-wrapper">
                <!-- <div class="welcome-panel">
                        <h1>{{ Auth::user()->name }}</h1>
                       </div> -->
                <div class="header">
                    <button class="btn-md-stroke" id="new-transcription-btn" onclick="showTranscriptChoice()">
                        <div class="icon">
                            <x-icon name="plus" />
                        </div>
                        <div class="label"><strong>Neue Transcription</strong></div>
                    </button>
                    <h3 class="title" id="history-title">{{ $translation['History'] }}</h3>

                </div>
                <div class="dy-sidebar-content-panel">
                    <div class="dy-sidebar-scroll-panel">
                        <div class="selection-list" id="chats-list">
                            <div id="file-transcription-options" style="display: none; padding: 10px;">
                                <div class="transcript-sidebar-field">
                                    <label for="file-path">📄 Dateipfad:</label>
                                    <input type="text" name="file_path" id="file-path"
                                        placeholder="Pfad zur Datei eingeben">
                                </div>

                                <div class="transcript-sidebar-field">
                                    <label for="start-time">⏱️ Startzeit:</label>
                                    <input type="text" name="start_time" id="start-time" placeholder="z. B. 00:00:00">
                                </div>

                                <div class="transcript-sidebar-field">
                                    <label for="end-time">⏹️ Stoppzeit:</label>
                                    <input type="text" name="end_time" id="end-time" placeholder="z. B. 00:02:00">
                                </div>

                                <div class="transcript-sidebar-field">
                                    <label for="language-select">🌐 Sprache:</label>
                                    <select id="language-select" name="language">
                                        <option value="de">Deutsch</option>
                                        <option value="en">Englisch</option>
                                        <option value="fr">Französisch</option>
                                    </select>
                                </div>

                                <div class="transcript-sidebar-field">
                                    <label for="api-select">
                                        <x-icon name="link" style="width: 16px; height: 16px; display: inline-block; vertical-align: middle;" />
                                        API auswählen:
                                    </label>
                                    <select id="api-select" name="api">
                                        <option value="whisper">OpenAI Whisper</option>
                                        <!--<option value="google">Google Speech-to-Text</option>
                                        <option value="custom">Eigene API</option>-->
                                    </select>
                                    <div class="transcript-sidebar-section" id="speaker-recognition-wrapper"
                                        style="display: none;">
                                        <p class="transcript-info">Sprecher*innen erkennen</p>
                                        <select id="speaker-count" name="speaker_count" class="sidebar-input">
                                            <option value="auto">auto</option>
                                            <option value="1">+1</option>
                                            <option value="2">+2</option>
                                            <option value="3">+3</option>
                                            <option value="4">+4</option>
                                            <option value="5">+5</option>
                                        </select>
                                    </div>
                                </div>
                                <!-- START-BUTTON: Sichtbar nur bei Dateiupload -->
                                <div id="start-upload-wrapper" class="transcript-sidebar-section" style="display: none;">
                                    <button id="start-upload-btn">Starten</button>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="dy-sidebar-expand-btn" onclick="togglePanelClass('chat-sidebar', 'expanded')">
                    <x-icon name="chevron-right" />
                </div>

            </div>
        </div>



        <div class="dy-main-panel">

            <div class="dy-main-content" id="chat">

                <div class="chat-info">

                </div>


                <div class="chatlog">
                    <!-- Auswahl Transkript -->
                    <div id="back-button-wrapper" style="display: none;">
                        <button class="back-icon-button" onclick="showTranscriptChoice()">←</button>
                    </div>
                    <div class="transcript-choice" id="transcript-choice">
                        <h2>Wählen Sie eine Transkriptionsmethode</h2>
                        <div class="choice-buttons">
                            <button onclick="showTranscriptMode('file')" class="transcript-button">
                                <x-icon name="upload" style="width: 20px; height: 20px; display: inline-block; vertical-align: middle; margin-right: 8px;" />
                                Transkription aus Datei
                            </button>
                            <button onclick="showTranscriptMode('live')" class="transcript-button">
                                <x-icon name="microphone" style="width: 20px; height: 20px; display: inline-block; vertical-align: middle; margin-right: 8px;" />
                                Live-Transkription starten
                            </button>
                        </div>
                    </div>

                    <!-- UI Datei Upload -->
                    <div id="transcript-file-ui" style="display: none;">
                        <div class="transcript-section">
                            <button class="back-icon-button" onclick="showTranscriptChoice()"
                                title="Zurück zur Auswahl">←</button>

                            <p class="transcript-info">Bitte ziehen Sie eine Datei in das Feld oder klicken Sie darauf.</p>
                            <div class="drop-zone" id="drop-zone">
                                <span id="drop-text">Drag-und-Drop</span>
                                <div id="loading-spinner" style="display: none;">
                                    <div class="spinner"></div>
                                </div>
                            </div>
                            <form id="transcript-upload-form" enctype="multipart/form-data">
                                <input type="file" name="audio_file" id="audio_file" style="display: none;">
                            </form>
                            <div id="selected-file-preview" class="transcript-file-preview"
                                style="margin-top: 10px; display: none;">
                                <x-icon name="paperclip" style="width: 16px; height: 16px; display: inline-block; vertical-align: middle;" />
                                <span id="selected-file-name">Keine Datei ausgewählt</span>
                            </div>
                            
                            <!-- Trennstrich -->
                            <hr class="section-divider">
                            
                            <!-- Transkriptions-Ausgabe direkt hier im Upload-Bereich -->
                            <div id="transcription-output-inline" style="display: none; width: 100%;">
                                <!-- Kopierbutton -->
                                <button id="copy-transcript-btn-inline" class="copy-button-inline"
                                    title="In Zwischenablage kopieren" style="position: relative; align-self: flex-end; margin-bottom: 10px;">Kopieren</button>
                                
                                <!-- Textcontainer -->
                                <div class="transcription-box" id="transcription-result-container-inline">
                                    <div id="transcription-result-inline"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Separate Transkriptions-Ausgabe für History -->
                    <div id="transcription-output" style="display: none;">
                        <!-- Kopierbutton oben rechts -->
                        <button id="copy-transcript-btn" class="copy-button-inline"
                            title="In Zwischenablage kopieren">Kopieren</button>

                        <!-- Textcontainer -->
                        <div class="transcription-box" id="transcription-result-container">
                            <div id="transcription-result"></div>
                        </div>
                    </div>


                    <!-- UI Live Aufnahme -->
                    <div id="transcript-live-ui" style="display: none;">
                        <div class="transcript-section live-transcript-ui">
                            <div class="microphone-image">
                                <x-icon name="microphone" style="width: 100px; height: 100px; opacity: 0.8;" />
                            </div>

                            <div class="recording-controls">
                                <button class="control-button record" title="Aufnehmen"></button>
                                <button class="control-button pause" title="Pause"></button>
                                <button class="control-button stop" title="Stop"></button>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="warning">{{ $translation['MistakeWarning'] }}</p>

            </div>
        </div>
    </div>

    <div id="custom-context-menu" class="context-menu"></div>
@endsection
