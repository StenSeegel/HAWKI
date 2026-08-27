<!-- UI Live Aufnahme -->
<div id="transcript-live-ui" class="transcript-workspace-ui hidden">
    <div class="transcript-workspace-section">

        <!-- Header Container -->
        <div class="transcript-workspace-header">
            <!-- Tabs -->
            <div class="transcript-tabs transcript-tabs-compact" id="live-record-tabs">
                <button class="transcript-tab active" data-live-tab="record" onclick="setLiveTab('record')">{{ $translation["TranscriptTabRecord"] ?? 'Aufnahme' }}</button>
                <button class="transcript-tab" data-live-tab="live-transcript" onclick="setLiveTab('live-transcript')">{{ $translation["TranscriptTabLiveTranscription"] ?? 'Live-Transkription' }}</button>
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
                        <div id="live-record-status-title">{{ $translation["TranscriptStartYourRecording"] ?? 'Starten Sie Ihre Aufnahme' }}</div>
                        <div id="live-record-status-text">{{ $translation["TranscriptSelectMicrophoneBelow"] ?? 'Wählen Sie unten ein Mikrofon aus und drücken Sie Aufnahme starten.' }}</div>
                    </div>
                </div>
            </div>

            <div id="live-transcript-panel" class="hidden">
                <div id="live-transcript-preview-card" class="live-transcript-preview-card">
                    <div id="live-transcript-preview-text" class="live-transcript-preview-text">
                        <!-- Rolling 3-line window: current line is pinned to the vertical center,
                             older lines stack upward above it, each dimmed further, via nested anchors. -->
                        <div class="live-transcript-anchor">
                            <div class="live-transcript-stack-prev">
                                <div class="live-transcript-stack-older">
                                    <div id="live-transcript-line-older" class="live-transcript-line live-transcript-line-older"></div>
                                </div>
                                <div id="live-transcript-line-prev" class="live-transcript-line live-transcript-line-prev">{{ $translation["TranscriptLivePreviewSample"] ?? 'Dies ist ein Beispieltext für die Live-Transkription.' }}</div>
                            </div>
                            <div id="live-transcript-line-current" class="live-transcript-line live-transcript-line-current">{{ $translation["TranscriptLivePreviewPlaceholder"] ?? 'Hier wird der Text stehen.' }}</div>
                        </div>
                    </div>
                    <button id="live-transcript-maximize-toggle" type="button" class="live-transcript-maximize-button"
                        aria-pressed="false" title="{{ $translation["TranscriptMaximizeTextView"] ?? 'Textansicht maximieren' }}">
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

            <div class="live-record-controls-bar">
                <div class="live-record-btn-group">
                    <button id="live-record-start-btn" type="button" class="btn-record-start">
                        <div class="record-dot"></div>
                        {{ $translation["TranscriptStartRecording"] ?? 'Aufnahme starten' }}
                    </button>

                    <button id="live-record-upload-btn" type="button" class="btn-record-upload hidden">
                        <svg xmlns="http://www.w3.org/2000/svg" style="width: 16px; height: 16px;" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="17 8 12 3 7 8"></polyline>
                            <line x1="12" y1="3" x2="12" y2="15"></line>
                        </svg>
                        {{ $translation["TranscriptUploadForTranscription"] ?? 'Zur Transkription hochladen' }}
                    </button>
                </div>

                <div class="select-wrapper live-device-selector">
                    <x-icon name="microphone" class="field-icon" />
                    <select id="live-input-device-select">
                        <option value="">{{ $translation["TranscriptLoadingMicrophones"] ?? 'Mikrofone werden geladen...' }}</option>
                    </select>
                </div>
            </div>

            <!-- Populated by LiveTranscriptionManager with one CustomAudioPlayer ('global' mode) card per recorded file. -->
            <div id="live-record-player-list" class="live-record-player-list hidden"></div>
        </div>
    </div>
</div>