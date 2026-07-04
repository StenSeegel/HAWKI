<!-- UI Live Aufnahme -->
<div id="transcript-live-ui" class="transcript-workspace-ui hidden">
    <div class="transcript-workspace-section">

        <!-- Header Container -->
        <div class="transcript-workspace-header">
            <!-- Tabs -->
            <div class="transcript-tabs transcript-tabs-compact" id="live-record-tabs">
                <button class="transcript-tab active" data-live-tab="record" onclick="setLiveTab('record')">Aufnahme</button>
                <button class="transcript-tab" data-live-tab="live-transcript" onclick="setLiveTab('live-transcript')">Live-Transkription</button>
            </div>

            <div class="transcript-header-top">
                <div>
                    <h2 class="transcript-title-editable">
                        Aufnahme benennen
                        <button class="edit-title-btn">
                            <x-icon name="edit" />
                        </button>
                    </h2>
                </div>
            </div>
        </div>

        <!-- Content Area -->
        <div class="transcript-content-area">
            <div id="live-record-panel">
                <div id="live-record-card" class="live-record-main-card">
                    <div id="live-record-badge" class="hidden">
                        <span class="recording-indicator-dot"></span>
                        LIVE
                    </div>
                    <div id="live-record-icon-wrap" class="live-record-icon-container">
                        <x-icon name="microphone" class="live-mic-icon" />
                    </div>
                    <div class="live-record-status-container">
                        <div id="live-record-status-title">Starten Sie Ihre Aufnahme</div>
                        <div id="live-record-status-text">Wählen Sie unten ein Mikrofon aus und drücken Sie Aufnahme starten.</div>
                        <div id="live-record-timer">00:00</div>
                    </div>
                </div>
            </div>

            <div id="live-transcript-panel" class="hidden">
                <div id="live-transcript-preview-card" class="live-transcript-preview-card">
                    <div id="live-transcript-preview-text" class="live-transcript-preview-text">
                        Dies ist ein Beispieltext für die Live-Transkription.<br>
                        Er folgt den etablierten Untertitel-Regeln: maximal 42 Zeichen pro Zeile.
                    </div>
                    <button id="live-transcript-maximize-toggle" type="button" class="live-transcript-maximize-button"
                        aria-pressed="false" title="Textansicht maximieren">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M9 4H4v5"></path>
                            <path d="M15 4h5v5"></path>
                            <path d="M20 15v5h-5"></path>
                            <path d="M4 15v5h5"></path>
                            <path d="M10 4 4 10"></path>
                            <path d="m14 4 6 6"></path>
                            <path d="m20 14-6 6"></path>
                            <path d="m10 20-6-6"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="live-record-controls-footer">
                <div class="live-record-btn-group">
                    <button id="live-record-start-btn" type="button" class="btn-record-start">
                        <div class="record-dot"></div>
                        Aufnahme starten
                    </button>

                    <button id="live-record-pause-btn" type="button" class="btn-record-pause" disabled>
                        <svg xmlns="http://www.w3.org/2000/svg" style="width: 14px; height: 14px;" viewBox="0 0 24 24"
                            fill="currentColor" aria-hidden="true">
                            <rect x="6" y="4" width="4" height="16" rx="1"></rect>
                            <rect x="14" y="4" width="4" height="16" rx="1"></rect>
                        </svg>
                        Pause
                    </button>
                </div>

                <div class="select-wrapper live-device-selector">
                    <x-icon name="microphone" class="field-icon" />
                    <select id="live-input-device-select">
                        <option value="">Mikrofone werden geladen...</option>
                    </select>
                </div>
            </div>
        </div>
    </div>
</div>