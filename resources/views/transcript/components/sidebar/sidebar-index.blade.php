<div class="dy-sidebar expanded" id="transcript-sidebar">
    <div class="dy-sidebar-wrapper">
        <div class="header">
            <button id="new-transcription-btn" class="btn-md-stroke" onclick="showTranscriptChoice()">
                <div class="icon">
                    <x-icon name="plus" />
                </div>
                <div class="label"><strong>Neue Transkription starten</strong></div>
            </button>
            <h3 class="title">Transkription</h3>
        </div>
        <div class="dy-sidebar-content-panel">
            <div class="dy-sidebar-scroll-panel">
                <div id="file-transcription-options" class="hidden" style="padding: 15px;">
                    <div class="transcript-sidebar-field border-label-field">
                        <label for="audio-file-display">Datei</label>
                        <div class="file-selection-pill-container" id="sidebar-file-pill-container">
                            <div class="file-pill hidden" id="sidebar-file-pill">
                                <span id="sidebar-file-name">recording xyz.mp3</span>
                                <button type="button" class="remove-file-btn"
                                    onclick="removeSelectedFile()">×</button>
                            </div>
                            <span class="file-placeholder" id="sidebar-file-placeholder">Keine Datei
                                ausgewählt</span>
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
                            <input type="checkbox" name="overlap" checked>
                            <span class="checkmark"></span>
                            Füllwörter
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="timestamps" checked>
                            <span class="checkmark"></span>
                            Zeitmarken
                        </label>
                    </div>

                </div>



                <div id="sidebar-history-content">
                    <div class="sidebar-search-container">
                        <div class="search-input-wrapper">
                            <x-icon name="magnifying-glass" class="search-icon" />
                            <input type="text" id="history-search" placeholder="Suche Transkriptionen"
                                onkeyup="filterHistory()">
                        </div>
                    </div>

                    <div class="selection-list transcript-history" id="chats-list">
                    </div>
                </div>
            </div>
        </div>

        <div id="transcript-settings-footer-container" class="transcript-settings-footer hidden" style="margin-top: auto; padding: 20px 0; border-top: 1px solid var(--border-color, #e2e8f0); z-index: 10;">
            @if(Auth::user()->hasAccess('platform.index'))
                <style>
                    #transcript-settings-btn:hover {
                        background-color: transparent !important;
                        border-color: var(--border-color, #e2e8f0) !important;
                        color: inherit !important;
                        transform: none !important;
                        box-shadow: none !important;
                    }
                </style>
                <button id="transcript-settings-btn" class="btn-md-stroke" style="width: 100%; margin: 0;" onclick="openTranscriptSettings()">
                    <div class="icon">
                        <x-icon name="settings-icon" style="width: 16px; height: 16px;" />
                    </div>
                    <div class="label"><strong>Einstellungen</strong></div>
                </button>
            @endif
        </div>

        <div class="dy-sidebar-expand-btn" onclick="togglePanelClass('transcript-sidebar', 'expanded')">
            <x-icon name="chevron-right" />
        </div>
        
        @include('transcript.components.sidebar.settings-subview')
    </div>
</div>
