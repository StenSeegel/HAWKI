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
                <div id="file-transcription-options" style="display: none; padding: 15px;">
                    <div class="transcript-sidebar-field border-label-field">
                        <label for="audio-file-display">Datei</label>
                        <div class="file-selection-pill-container" id="sidebar-file-pill-container">
                            <div class="file-pill" id="sidebar-file-pill" style="display: none;">
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

                    <div class="sidebar-bottom-action">
                        <button id="start-upload-btn" class="btn-sidebar-start">
                            <div class="label"><strong>Starten</strong></div>
                        </button>
                    </div>
                </div>

                <div id="sidebar-detail-content" style="display: none; padding: 15px;">
                    <div class="transcript-sidebar-actions" style="display: flex; gap: 8px; margin-bottom: 20px;">
                        <button id="edit-mode-btn" class="btn-sidebar-secondary active" style="flex: 1;"
                            onclick="toggleSidebarMenu('edit')">
                            <x-icon name="edit" style="width:14px;height:14px;margin-right:6px;" />
                            Bearbeiten
                        </button>
                        <button id="export-options-btn" class="btn-sidebar-secondary" style="flex: 1;"
                            onclick="toggleSidebarMenu('export')">
                            <x-icon name="upload" style="width:14px;height:14px;margin-right:6px;" />
                            Export
                        </button>
                    </div>

                    <!-- Edit Mode Instructions Panel -->
                    <div id="edit-mode-panel" style="display: none;">
                        <div class="transcript-sidebar-field" style="margin-top: 8px;">
                            <label
                                style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary, #888); display: block; margin-bottom: 4px;">Modus: Bearbeiten</label>
                            <p style="font-size: 12px; color: var(--text-muted, #999); margin-bottom: 12px;">Markiere Text im Transkript, um ihn zu schwärzen oder Sätze zu verschieben. Sprecher können direkt über ihre Icons angepasst werden.</p>
                        </div>
                    </div>

                    <!-- Redaction Accordion -->
                    <div id="redaction-accordion" class="sidebar-accordion" style="display: none; margin-bottom: 20px;">
                        <div class="accordion-header" onclick="toggleRedactionAccordion()">
                            <div class="accordion-title">
                                <x-icon name="eye-off" style="width:14px;height:14px;" />
                                <span>Schwärzungen (<span id="redaction-count">0</span>)</span>
                            </div>
                            <x-icon name="chevron-down" class="accordion-arrow" />
                        </div>
                        <div class="accordion-content" id="redaction-accordion-content" style="display: none;">
                            <div id="redaction-list">
                                <!-- Redaction items injected here -->
                            </div>
                            <div class="accordion-footer">
                                <button class="btn-sidebar-secondary btn-small" onclick="clearAllRedactions()" style="width: 100%; justify-content: center; color: #ef4444; border-color: rgba(239, 68, 68, 0.2); background: rgba(239, 68, 68, 0.05);">
                                    Alle entfernen
                                </button>
                            </div>
                        </div>
                    </div>


                    <div id="sentence-reorder-panel" style="display: none;">
                        <div class="transcript-sidebar-field" style="margin-top: 8px;">
                            <label
                                style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary, #888); display: block; margin-bottom: 4px;">Sätze
                                umverteilen</label>
                            <p style="font-size: 12px; color: var(--text-muted, #999); margin-bottom: 12px;">Klicke
                                im Transkript auf die Pfeile an den Blockgrenzen, um Sätze zu verschieben.</p>
                            <div id="reorder-mode-controls" style="display: flex; gap: 8px;">
                                <button id="undo-reorder-btn" class="btn-sidebar-secondary" onclick="undoLastMove()"
                                    title="Rückgängig" style="width: 42px; height: 42px; padding: 0;">
                                    <x-icon name="chevron-left" style="width:16px;height:16px;" />
                                </button>
                                <button class="btn-sidebar-action" onclick="finishReorderMode()" style="flex:1;">
                                    Fertig
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="export-options-panel" style="display: none;">
                        <div class="transcript-sidebar-field" style="margin-top: 8px;">
                            <label
                                style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-secondary, #888); display: block; margin-bottom: 8px;">Export-Optionen</label>
                            
                            <h4 class="section-title" style="margin-top: 10px; margin-bottom: 12px; font-size: 11px;">Exportieren als:</h4>
                            
                            <div id="sidebar-export-list" class="sidebar-export-list" style="display: flex; flex-direction: column; gap: 8px;">
                                <!-- Untertitel -->
                                <div class="sidebar-export-card export-option-card-compact" data-option="srt" onclick="selectExportOption('srt'); exportToSRT();">
                                    <div class="card-icon-box blue compact">
                                        <x-icon name="microphone" />
                                    </div>
                                    <div class="card-details">
                                        <h4>Untertitel</h4>
                                        <p>SRT / VTT</p>
                                    </div>
                                </div>

                                <!-- Verlaufsprotokoll -->
                                <div class="sidebar-export-card export-option-card-compact" data-option="verlauf" onclick="selectExportOption('verlauf'); exportToVerlauf();">
                                    <div class="card-icon-box tan compact">
                                        <x-icon name="paperclip" />
                                    </div>
                                    <div class="card-details">
                                        <h4>Verlaufsprotokoll</h4>
                                        <p>Wort-für-Wort</p>
                                    </div>
                                </div>

                                <!-- Ergebnisprotokoll -->
                                <div class="sidebar-export-card export-option-card-compact" data-option="ergebnis" onclick="selectExportOption('ergebnis'); exportToErgebnis();">
                                    <div class="card-icon-box blue compact" style="background-color: #F0F9FF; color: #0EA5E9;">
                                        <x-icon name="book" />
                                    </div>
                                    <div class="card-details">
                                        <h4>Ergebnisprotokoll</h4>
                                        <p>Zusammenfassung</p>
                                    </div>
                                </div>
                            </div>
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

        <div id="transcript-settings-footer-container" class="transcript-settings-footer" style="display: none; margin-top: auto; padding: 20px 0; border-top: 1px solid var(--border-color, #e2e8f0); z-index: 10;">
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
