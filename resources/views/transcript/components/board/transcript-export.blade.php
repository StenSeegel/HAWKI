            <!-- TAB: EXPORT -->
            <div id="tab-export" class="tab-content tab-content-wrapper hidden">
                <!-- Sidebar Export List -->
                <div id="export-tools-sidebar" class="export-options-list sidebar-tools sidebar-export-tools" style="gap: 8px;">
                    <h4 class="section-title field-label-uppercase" style="margin-top: 0; margin-bottom: 12px;">Exportieren als:</h4>
                    
                    <div class="sidebar-export-card export-option-card-compact" data-option="ergebnis" onclick="window.app.exportManager.selectExportOption('ergebnis'); window.app.exportManager.exportToErgebnis();">
                        <div class="card-icon-box blue compact export-card-icon-blue">
                            <x-icon name="book" />
                        </div>
                        <div class="card-details">
                            <h4>Ergebnisprotokoll</h4>
                            <p>Zusammenfassung</p>
                        </div>
                    </div>

                    <div class="sidebar-export-card export-option-card-compact" data-option="verlauf" onclick="window.app.exportManager.selectExportOption('verlauf'); window.app.exportManager.exportToVerlauf();">
                        <div class="card-icon-box tan compact">
                            <x-icon name="paperclip" />
                        </div>
                        <div class="card-details">
                            <h4>Verlaufsprotokoll</h4>
                            <p>Wort-für-Wort</p>
                        </div>
                    </div>

                    <div class="sidebar-export-card export-option-card-compact" data-option="srt" onclick="window.app.exportManager.selectExportOption('srt'); window.app.exportManager.exportToSRT();">
                        <div class="card-icon-box blue compact">
                            <x-icon name="microphone" />
                        </div>
                        <div class="card-details">
                            <h4>Untertitel</h4>
                            <p>SRT / VTT</p>
                        </div>
                    </div>
                </div>

                <!-- Export Preview Area from old export.blade.php -->
                <div class="transcript-main-view">
                    <div class="transcript-view-header" style="height: 52px; box-sizing: border-box;">
                        <div style="flex: 1; display: flex; align-items: center;">
                            <span class="transcript-view-title" style="text-transform: uppercase;">EXPORT VORSCHAU</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px; justify-content: flex-end;">
                            <button onclick="window.app.exportManager.regenerateCurrentExport()" class="btn-header-action" title="Ansicht neu generieren">
                                <x-icon name="rotation" style="width: 14px; height: 14px;" />
                            </button>
                            <button onclick="window.app.exportManager.triggerExportDownload()" class="btn-header-action" title="Export herunterladen">
                                <svg xmlns="http://www.w3.org/2000/svg" style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-download"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            </button>
                        </div>
                    </div>
                    <div id="export-output" class="transcription-output-container transcript-view-content" style="background: transparent; border: none; box-shadow: none;">
                        <div class="transcription-box transcript-view-box" id="export-result-container" style="background: transparent; border: none; box-shadow: none; padding: 0;">
                            <div id="export-preview-content" class="export-preview-text" style="white-space: normal; font-family: inherit; font-size: inherit;"></div>
                        </div>
                    </div>
                </div>
            </div>
