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



        <div class="dy-sidebar-expand-btn" onclick="togglePanelClass('transcript-sidebar', 'expanded')">
            <x-icon name="chevron-right" />
        </div>
        
        @include('transcript.components.sidebar.settings-subview')
    </div>
</div>
