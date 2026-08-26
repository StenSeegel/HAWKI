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
                                <option value="single">Einzelne Person</option>
                                <option value="multi">Mehrere Personen</option>
                            </select>
                        </div>
                    </div>

                    <div class="transcript-sidebar-checkboxes" style="margin-top: 15px; margin-bottom: 5px;">
                        <label class="custom-checkbox">
                            Sprecher per KI optimieren
                            <input type="checkbox" id="llm-correction-toggle" name="llm_correction" value="1" checked>
                            <span class="checkmark"></span>
                        </label>
                    </div>
                </div>

                <div id="live-record-sidebar-options" class="hidden" style="padding: 15px;">
                    <div class="transcript-sidebar-field border-label-field">
                        <label>Status</label>
                        <div id="live-record-status-container-sidebar" class="live-record-status-box">
                            <div id="live-record-badge-sidebar" class="live-record-status-box-dot hidden"></div>
                            <div>
                                <div id="live-record-status-title-sidebar" class="live-record-status-box-title">Aufnahme bereit</div>
                                <div id="live-record-status-text-sidebar" class="live-record-status-box-text">Wählen Sie ein Mikrofon und starten Sie die Aufnahme.</div>
                            </div>
                        </div>
                    </div>

                    <div class="transcript-sidebar-field border-label-field" style="margin-top: 20px;">
                        <label for="live-input-device-select-sidebar">Mikrofon</label>
                        <div class="select-wrapper">
                            <x-icon name="microphone" class="field-icon" />
                            <select id="live-input-device-select-sidebar">
                                <option value="">Suche Geräte...</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="live-transcript-sidebar-options" class="hidden" style="padding: 15px;">
                    <div class="transcript-sidebar-field border-label-field">
                        <label for="live-transcript-mode-select">Modus</label>
                        <div class="select-wrapper">
                            <x-icon name="cpu" class="field-icon" />
                            @php
                                // Which realtime modes the admin allows. Only enabled modes are
                                // offered — disallowed ones are omitted entirely. Also enforced
                                // server-side (RealtimeSignalingController), so hiding here is a
                                // UI convenience, not the security boundary.
                                $availableModes = array_values(array_filter(explode(',', (string) app(\App\Services\Transcription\TranscriptionSettingsService::class)->get('realtime_available_modes', 'onprem,openai'))));
                                if ($availableModes === []) { $availableModes = ['onprem']; }
                                $defaultMode = in_array('onprem', $availableModes, true) ? 'onprem' : $availableModes[0];
                            @endphp
                            <select id="live-transcript-mode-select" name="live_transcript_mode">
                                @foreach (['onprem' => 'Lokal (Standard)', 'openai' => 'OpenAI'] as $modeKey => $modeLabel)
                                    @continue (! in_array($modeKey, $availableModes, true))
                                    <option value="{{ $modeKey }}" @selected($modeKey === $defaultMode)>{{ $modeLabel }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="transcript-sidebar-field" style="margin-top: 15px;">
                        
                        <div style="margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <span class="live-option-label">Schriftgröße</span>
                                <span id="live-font-size-value" class="live-option-value">32px</span>
                            </div>
                            <input type="range" id="live-font-size-slider" min="32" max="100" value="32" style="width: 100%;">
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span class="live-option-label">Kontrast umkehren</span>
                            <label class="switch-toggle">
                                <input type="checkbox" id="live-contrast-toggle">
                                <span class="slider round"></span>
                            </label>
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
