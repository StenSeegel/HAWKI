            <!-- TAB: VORSCHAU -->
            <div id="tab-vorschau" class="tab-content tab-content-wrapper hidden">
                <div class="transcript-main-view">
                    <div class="transcript-view-header panel-header">
                        <div style="width: 32px; display: flex; align-items: center; flex-shrink: 0;"></div>
                        <div style="flex: 1; display: flex; align-items: center;">
                            <span class="transcript-view-title" style="text-transform: uppercase;">{{ $translation["TranscriptAiTranscriptLabel"] ?? 'KI-TRANSKRIPT' }} <span class="transcript-view-divider">|</span> {{ $translation["TranscriptTabPreview"] ?? 'Vorschau' }}</span>
                        </div>
                        <div class="header-actions-container" style="display: flex; align-items: center; gap: 8px; justify-content: flex-end;">
                            <!-- #sidebar-toggle-btn is moved here by TranscriptUI.moveSharedTabElements() when this tab is active -->
                        </div>
                    </div>
                    
                    <!-- This will hold the main transcript result -->
                    <div id="transcription-output" class="transcription-output-container transcript-view-content">
                        <div class="transcription-box transcript-view-box" id="transcription-result-container">
                            <div id="transcription-result"></div>
                            <p class="warning transcript-history-warning">{{ $translation["TranscriptAccuracyWarning"] ?? 'Transkription kann Fehler enthalten. Überprüfe wichtige Informationen.' }}</p>
                        </div>
                    </div>
                </div>
            </div>
