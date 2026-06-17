            <!-- TAB: VORSCHAU -->
            <div id="tab-vorschau" class="tab-content tab-content-wrapper hidden">
                <div class="transcript-main-view">
                    <div class="transcript-view-header" style="height: 52px; box-sizing: border-box;">
                        <div style="width: 32px; display: flex; align-items: center; flex-shrink: 0;"></div>
                        <div style="flex: 1; display: flex; align-items: center;">
                            <span class="transcript-view-title" style="text-transform: uppercase;">KI-TRANSKRIPT <span class="transcript-view-divider">|</span> v1.2 Generiert</span>
                        </div>
                        <div class="header-actions-container" style="display: flex; align-items: center; gap: 8px; justify-content: flex-end;">
                            <button id="toggle-audio-player-btn" class="btn-header-action" onclick="const p=document.getElementById('global-audio-player'); const isHidden = p.classList.toggle('hidden'); document.getElementById('icon-audio-open').style.display = isHidden ? 'block' : 'none'; document.getElementById('icon-audio-close').style.display = isHidden ? 'none' : 'block';" title="Audio Player umschalten">
                                <svg id="icon-audio-open" style="width: 16px; height: 16px; display: none;" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-panel-top-open"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="m9 14 3 3 3-3"/></svg>
                                <svg id="icon-audio-close" style="width: 16px; height: 16px; display: block;" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-panel-top-close"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="m9 16 3-3 3 3"/></svg>
                            </button>
                        </div>
                    </div>
                    
                    <!-- This will hold the main transcript result -->
                    <div id="transcription-output" class="transcription-output-container transcript-view-content">
                        <div class="transcription-box transcript-view-box" id="transcription-result-container">
                            <div id="transcription-result"></div>
                            <p class="warning transcript-history-warning">Transkription kann Fehler enthalten. Überprüfe wichtige Informationen.</p>
                        </div>
                    </div>
                </div>
            </div>
