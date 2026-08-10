            <!-- TAB: KORREKTUREN -->
            <div id="tab-korrekturen" class="tab-content tab-content-wrapper hidden">
                
                <div class="transcript-main-view">
                    <div class="transcript-view-header" style="height: 52px; box-sizing: border-box;">
                        <div style="width: 32px; display: flex; align-items: center; flex-shrink: 0;"></div>
                        <div style="flex: 1; display: flex; align-items: center;">
                            <span class="transcript-view-title" style="text-transform: uppercase;">KI-TRANSKRIPT <span class="transcript-view-divider">|</span> Korrekturmodus</span>
                        </div>
                        <div class="header-actions-container" style="display: flex; align-items: center; gap: 8px; justify-content: flex-end;">
                            <button id="undo-global-btn" class="btn-header-action" onclick="window.undoLastMove()" title="Aktion rückgängig machen" disabled>
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-undo2-icon lucide-undo-2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>
                            </button>
                            <button id="optimize-speakers-btn" class="btn-header-action" onclick="window.optimizeSpeakersWithAI()" title="Sprecher per KI optimieren">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-wand-sparkles"><path d="m21.64 3.64-1.28-1.28a1.21 1.21 0 0 0-1.72 0L2.36 18.64a1.21 1.21 0 0 0 0 1.72l1.28 1.28a1.2 1.2 0 0 0 1.72 0L21.64 5.36a1.2 1.2 0 0 0 0-1.72"/><path d="m14 7 3 3"/><path d="M5 6v4"/><path d="M19 14v4"/><path d="M10 2v2"/><path d="M7 8H3"/><path d="M21 16h-4"/><path d="M11 3H9"/></svg>
                            </button>
                            <button class="btn-header-action" id="sidebar-toggle-btn" onclick="const s=document.getElementById('editor-tools-sidebar'); const isHidden = s.classList.toggle('hidden'); document.getElementById('icon-sidebar-open').style.display = isHidden ? 'block' : 'none'; document.getElementById('icon-sidebar-close').style.display = isHidden ? 'none' : 'block';">
                                <svg id="icon-sidebar-open" style="width: 16px; height: 16px; display: block;" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-panel-right-close"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M15 3v18"/><path d="m8 9 3 3-3 3"/></svg>
                                <svg id="icon-sidebar-close" style="width: 16px; height: 16px; display: none;" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-panel-right-open"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M15 3v18"/><path d="m10 15-3-3 3-3"/></svg>
                            </button>
                        </div>
                    </div>

                    <div class="transcription-output-container transcript-view-content">
                        <div class="transcription-box transcript-view-box" id="transcription-result-container-edit">
                            <!-- Handled by JS, we can use a single container and move it via JS, or render twice -->
                        </div>
                    </div>
                </div>

                <!-- Sidebar Tools for Korrekturen (Right Side) -->
                <div id="editor-tools-sidebar" class="sidebar-tools sidebar-edit-tools hidden" style="gap: 8px;">
                    <h4 class="section-title field-label-uppercase" style="margin-top: 0; margin-bottom: 12px;">Sprecher (<span id="sidebar-speaker-count">0</span>)</h4>
                    <div id="sidebar-speaker-list" style="display: flex; flex-direction: column; gap: 8px;">
                        <!-- Speaker items injected here by JS -->
                    </div>
                </div>
            </div>
