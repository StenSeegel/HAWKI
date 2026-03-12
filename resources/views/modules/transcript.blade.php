@extends('layouts.home')
@section('content')
    <div class="main-panel-grid">
        <div class="dy-sidebar expanded" id="transcript-sidebar">
            <div class="dy-sidebar-wrapper">
                <!-- <div class="welcome-panel">
                        <h1>{{ Auth::user()->name }}</h1>
                       </div> -->
                <div class="header">
                    <h3 class="transcription-title">Transkription</h3>
                </div>
                <div class="dy-sidebar-content-panel">
                    <div class="dy-sidebar-scroll-panel">
                        <div id="file-transcription-options" style="display: none; padding: 15px;">
                            <div class="transcript-sidebar-field border-label-field">
                                <label for="audio-file-display">Datei</label>
                                <div class="file-selection-pill-container" id="sidebar-file-pill-container">
                                    <div class="file-pill" id="sidebar-file-pill" style="display: none;">
                                        <span id="sidebar-file-name">recording xyz.mp3</span>
                                        <button type="button" class="remove-file-btn" onclick="removeSelectedFile()">×</button>
                                    </div>
                                    <span class="file-placeholder" id="sidebar-file-placeholder">Keine Datei ausgewählt</span>
                                </div>
                            </div>

                            <div class="transcript-sidebar-field border-label-field">
                                <label for="start-time">Start</label>
                                <input type="text" name="start_time" id="start-time" placeholder="hh:mm:ss">
                            </div>

                            <div class="transcript-sidebar-field border-label-field">
                                <label for="end-time">Stopp</label>
                                <input type="text" name="end_time" id="end-time" placeholder="hh:mm:ss">
                            </div>

                            <div class="transcript-sidebar-field border-label-field">
                                <label for="language-select">Sprache</label>
                                <div class="select-wrapper">
                                    <x-icon name="world" class="field-icon" />
                                    <select id="language-select" name="language">
                                        <option value="auto">Auto</option>
                                        <option value="de">Deutsch</option>
                                        <option value="en">Englisch</option>
                                    </select>
                                </div>
                            </div>

                            <div class="transcript-sidebar-field border-label-field">
                                <label for="model-select">Modell</label>
                                <div class="select-wrapper">
                                    <x-icon name="layers" class="field-icon" />
                                    <select id="model-select" name="model">
                                        <option value="precise">Präzise</option>
                                        <option value="fast">Schnell</option>
                                    </select>
                                </div>
                            </div>

                            <div class="transcript-sidebar-field border-label-field">
                                <label for="pause-select">Pausen markieren</label>
                                <div class="select-wrapper">
                                    <x-icon name="rotation" class="field-icon" />
                                    <select id="pause-select" name="pause_threshold">
                                        <option value="1">+1 Sekunde</option>
                                        <option value="2">+2 Sekunden</option>
                                    </select>
                                </div>
                            </div>

                            <div class="transcript-sidebar-field border-label-field">
                                <label for="speaker-select">Sprecher*innen erkennen</label>
                                <div class="select-wrapper">
                                    <x-icon name="users" class="field-icon" />
                                    <select id="speaker-count" name="speaker_count">
                                        <option value="auto">Auto</option>
                                        <option value="1">1</option>
                                        <option value="2">2</option>
                                    </select>
                                </div>
                            </div>

                            <div class="transcript-sidebar-checkboxes">
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="overlap" checked>
                                    <span class="checkmark"></span>
                                    Überlappende Sprache
                                </label>
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="filler" checked>
                                    <span class="checkmark"></span>
                                    Füllwörter
                                </label>
                                <label class="custom-checkbox">
                                    <input type="checkbox" name="timestamps" checked>
                                    <span class="checkmark"></span>
                                    Zeitmarken
                                </label>
                            </div>

                            <div class="sidebar-bottom-action">
                                <div class="action-card">
                                    <div class="mascot-icon">
                                        <img src="/img/mascot_transcribe.png" alt="Mascot" id="mascot-img">
                                    </div>
                                    <button id="start-upload-btn" class="btn-primary-blue">Starten</button>
                                </div>
                            </div>
                        </div>

                        <div id="sidebar-history-content">
                            <div class="sidebar-search-container">
                                <div class="search-input-wrapper">
                                    <x-icon name="magnifying-glass" class="search-icon" />
                                    <input type="text" id="history-search" placeholder="Suche Transkriptionen" onkeyup="filterHistory()">
                                </div>
                            </div>

                            <div class="history-category">Vorherige 7 Tage</div>
                            
                            <div class="selection-list transcript-history" id="chats-list">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dy-sidebar-expand-btn" onclick="togglePanelClass('transcript-sidebar', 'expanded')">
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
                        <div class="choice-cards-container">
                            <!-- File Upload Card -->
                            <div class="choice-card" onclick="showTranscriptMode('file')">
                                <div class="info-marker" title="Details zum Datei-Upload">
                                    <x-icon name="info" />
                                </div>
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
                                <div class="info-marker" title="Details zum Audio-Recording">
                                    <x-icon name="info" />
                                </div>
                                <div class="choice-card-body">
                                    <div class="choice-card-icon-wrapper">
                                        <img src="/img/icon_live_transcript.png" alt="Live Transcript" class="choice-card-image">
                                    </div>
                                    <div class="choice-card-content">
                                        <h3>Audio aufnehmen</h3>
                                        <p>Starten einer Sprachaufnahme, die direkt transkribiert wird.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- UI Datei Upload -->
                    <div id="transcript-file-ui" style="display: none;">
                        <div class="transcript-section">
                            <button class="back-icon-button" onclick="showTranscriptChoice()"
                                title="Zurück zur Auswahl">←</button>

                            <div class="drop-zone-container">
                                <div class="drop-zone" id="drop-zone">
                                    <div class="drop-zone-content" id="drop-zone-content">
                                        <div class="drop-icon-wrapper">
                                            <x-icon name="upload" class="drop-icon" />
                                        </div>
                                        <p class="drop-main-text">Dokumente hierher ziehen, oder</p>
                                        <button type="button" class="btn-select-file" onclick="document.getElementById('audio_file').click()">
                                            Vom Computer auswählen
                                        </button>
                                        <div class="drop-sub-text">
                                            <p>Wir unterstützen .mp3, .wav, .m4a und .ogg.</p>
                                            <p>Maximal 25MB pro Datei.</p>
                                        </div>
                                    </div>
                                    <div id="loading-spinner" style="display: none;">
                                        <div class="spinner"></div>
                                        <p class="loading-text">Transkription läuft...</p>
                                    </div>
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
                            
                            
                            <!-- Transkriptions-Ausgabe direkt hier im Upload-Bereich -->
                            <div id="transcription-output-inline" class="transcription-output-container" style="display: none; width: 100%;">
                                <!-- Action Bar -->
                                <div class="transcript-action-bar">
                                    <button class="action-bar-btn" title="Audio abspielen">
                                        <x-icon name="volume" />
                                    </button>
                                    <button id="copy-transcript-btn-inline" class="action-bar-btn" title="In Zwischenablage kopieren">
                                        <x-icon name="copy" />
                                    </button>
                                    <button class="action-bar-btn" title="Herunterladen">
                                        <x-icon name="download" />
                                    </button>
                                </div>
                                
                                <!-- Textcontainer -->
                                <div class="transcription-box" id="transcription-result-container-inline">
                                    <div id="transcription-result-inline"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Separate Transkriptions-Ausgabe für History -->
                    <div id="transcription-output" class="transcription-output-container" style="display: none;">
                        <!-- Action Bar -->
                        <div class="transcript-action-bar">
                            <button class="action-bar-btn" title="Audio abspielen">
                                <x-icon name="volume" />
                            </button>
                            <button id="copy-transcript-btn" class="action-bar-btn" title="In Zwischenablage kopieren">
                                <x-icon name="copy" />
                            </button>
                            <button class="action-bar-btn" title="Herunterladen">
                                <x-icon name="download" />
                            </button>
                        </div>

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
