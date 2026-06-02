<div id="createBoard" class="translate-board-single" style="display: none;">

    <div class="create-panel">
        
        <!-- Editor Header -->
        <div class="create-panel-header">
            <div class="header-title">
                KI-EDITOR
            </div>
            
            <!-- Mode Switcher -->
            <div class="create-mode-switcher-container">
                <button type="button" class="mode-switch-btn active" id="createEditTabBtn">Bearbeitung</button>
                <button type="button" class="mode-switch-btn" id="createExportTabBtn">Exportieren</button>
            </div>

            <div class="header-actions">
                <button type="button" class="btn-secondary btn-sm" id="improveTargetBtn" style="display: none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sparkles"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
                    Umformulieren
                </button>
                <button type="button" id="createUndoBtn" class="btn-icon-only-sm tooltip-parent">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-undo2-icon lucide-undo-2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>
                    <div class="tooltip">Rückgängig</div>
                </button>
                <button type="button" id="createRedoBtn" class="btn-icon-only-sm tooltip-parent">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-redo2-icon lucide-redo-2"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5A5.5 5.5 0 0 0 9.5 20H13"/></svg>
                    <div class="tooltip">Wiederholen</div>
                </button>


            </div>
        </div>

        <!-- Export View -->
        <div id="createExportView" class="create-export-view hidden">
            <div class="export-view-header">
                EXPORTIEREN ALS:
            </div>
            
            <div class="export-cards-container">
                
                <!-- DOCX -->
                <button type="button" id="exportDocBtn" class="export-card">
                    <div class="export-icon icon-doc">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="16" x2="8" y1="13" y2="13"/>
                            <line x1="16" x2="8" y1="17" y2="17"/>
                            <line x1="10" x2="8" y1="9" y2="9"/>
                        </svg>
                    </div>
                    <div>
                        <div class="export-card-title">Word-Dokument</div>
                        <div class="export-card-subtitle">DOCX</div>
                    </div>
                </button>
                
                <!-- PDF -->
                <button type="button" id="exportPdfBtn" class="export-card">
                    <div class="export-icon icon-pdf">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <path d="M8 18h1c.6 0 1-.4 1-1v-2c0-.6-.4-1-1-1H8v4z"/>
                            <path d="M12 18v-4h1c.6 0 1 .4 1 1v2c0 .6-.4 1-1 1h-1z"/>
                            <path d="M16 18v-4h2"/>
                            <path d="M16 16h1"/>
                        </svg>
                    </div>
                    <div>
                        <div class="export-card-title">PDF-Dokument</div>
                        <div class="export-card-subtitle">PDF</div>
                    </div>
                </button>
                
                <!-- TXT -->
                <button type="button" id="exportTxtBtn" class="export-card">
                    <div class="export-icon icon-txt">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <line x1="8" x2="16" y1="13" y2="13"/>
                            <line x1="8" x2="16" y1="17" y2="17"/>
                        </svg>
                    </div>
                    <div>
                        <div class="export-card-title">Textdatei</div>
                        <div class="export-card-subtitle">TXT</div>
                    </div>
                </button>
                
                <!-- MD -->
                <button type="button" id="exportMdBtn" class="export-card">
                    <div class="export-icon icon-md">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>
                            <polyline points="14 2 14 8 20 8"/>
                            <polyline points="8 17 8 13 10 15 12 13 12 17"/>
                            <path d="M14 17v-4"/>
                            <path d="M14 17l2-2"/>
                            <path d="M14 17l-2-2"/>
                        </svg>
                    </div>
                    <div>
                        <div class="export-card-title">Markdown</div>
                        <div class="export-card-subtitle">MD</div>
                    </div>
                </button>
                
            </div>
        </div>

        <!-- Edit View -->
        <div id="createEditView" class="create-panel-content relative" style="display: flex; flex-direction: column; flex: 1; min-height: 0; padding: 0;">
            <div id="createOutputSkeleton" class="skeleton-screen" style="display: none;">
                <div class="skeleton-line"></div>
                <div class="skeleton-line"></div>
                <div class="skeleton-line"></div>
                <div class="skeleton-line" style="width: 60%;"></div>
            </div>
            
            <!-- Tiptap Toolbar -->
            <div id="tiptapToolbar" class="tiptap-toolbar">
                <button type="button" class="toolbar-btn" data-command="toggleHeading" data-level="1" title="Überschrift 1">H1</button>
                <button type="button" class="toolbar-btn" data-command="toggleHeading" data-level="2" title="Überschrift 2">H2</button>
                <button type="button" class="toolbar-btn" data-command="toggleHeading" data-level="3" title="Überschrift 3">H3</button>
                <div class="toolbar-divider"></div>
                <button type="button" class="toolbar-btn" data-command="toggleBold" title="Fett"><b>B</b></button>
                <button type="button" class="toolbar-btn" data-command="toggleItalic" title="Kursiv"><i>I</i></button>
                <button type="button" class="toolbar-btn" data-command="toggleStrike" title="Durchgestrichen"><s>S</s></button>
                <div class="toolbar-divider"></div>
                <!-- AI Quote Button -->
                <button type="button" class="toolbar-btn" data-command="toggleBlockquote" title="Zitat" spellcheck="false" data-gramm="false" data-lt-active="false">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-message-square-quote-icon lucide-message-square-quote"><path d="M14 14a2 2 0 0 0 2-2V8h-2"/><path d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"/><path d="M8 14a2 2 0 0 0 2-2V8H8"/></svg>
                </button>
                <div class="toolbar-divider"></div>
                <button type="button" class="toolbar-btn" data-command="toggleBulletList" title="Aufzählung">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-list"><line x1="8" x2="21" y1="6" y2="6"/><line x1="8" x2="21" y1="12" y2="12"/><line x1="8" x2="21" y1="18" y2="18"/><line x1="3" x2="3.01" y1="6" y2="6"/><line x1="3" x2="3.01" y1="12" y2="12"/><line x1="3" x2="3.01" y1="18" y2="18"/></svg>
                </button>
                <button type="button" class="toolbar-btn" data-command="toggleOrderedList" title="Nummerierte Liste">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-list-ordered"><line x1="10" x2="21" y1="6" y2="6"/><line x1="10" x2="21" y1="12" y2="12"/><line x1="10" x2="21" y1="18" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/></svg>
                </button>
                <button type="button" class="toolbar-btn" data-command="liftListItem" title="Ausrücken">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-outdent"><polyline points="7 8 3 12 7 16"/><line x1="21" x2="11" y1="6" y2="6"/><line x1="21" x2="11" y1="12" y2="12"/><line x1="21" x2="11" y1="18" y2="18"/></svg>
                </button>
                <button type="button" class="toolbar-btn" data-command="sinkListItem" title="Einrücken">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-indent"><polyline points="3 8 7 12 3 16"/><line x1="21" x2="11" y1="6" y2="6"/><line x1="21" x2="11" y1="12" y2="12"/><line x1="21" x2="11" y1="18" y2="18"/></svg>
                </button>
                <div class="toolbar-divider"></div>
                <button type="button" class="toolbar-btn" data-command="toggleCode" title="Code">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-code-icon lucide-code"><path d="m16 18 6-6-6-6"/><path d="m8 6-6 6 6 6"/></svg>
                </button>
                <button type="button" class="toolbar-btn" data-command="toggleCodeBlock" title="Code Block">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-square-code-icon lucide-square-code"><path d="m10 9-3 3 3 3"/><path d="m14 15 3-3-3-3"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                </button>
                <div class="toolbar-divider"></div>
                <button type="button" class="toolbar-btn" data-command="insertTable" title="Tabelle einfügen">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-table"><path d="M12 3v18"/><path d="M3 12h18"/><path d="M3 3h18v18H3z"/></svg>
                </button>
            </div>
            
            <div id="createText" class="text-input tiptap-container"></div>
            <div id="markdownEditorContainer" class="markdown-editor-container" style="display: none;">
                <textarea id="createTextMarkdown" class="text-input markdown-textarea"></textarea>
            </div>
            
            <!-- Footer -->
            <div class="create-panel-footer">
                <div class="footer-info">
                    <span id="createWordCount">0</span> {{ $translation["Words"] ?? "Wörter" }} | <span id="createCharCount">0</span> {{ $translation["Characters"] ?? "Zeichen" }}
                </div>
                <div class="footer-actions" style="display: flex; gap: 0.5rem;">
                    <button type="button" id="copyCreateBtn" class="btn-xs reaction-button fast-access-btn tooltip-parent" onmousedown="reactionMouseDown(this);" onmouseup="reactionMouseUp(this)" style="border:none;">
                        <x-icon name="copy"/>
                        <div class="reaction">{{ $translation["CopiedToolTip"] ?? "Kopiert!" }}</div>
                        <div class="tooltip">{{ $translation["CopyToolTip"] ?? "Kopieren" }}</div>
                    </button>
                </div>
            </div>
            
        </div>
    </div>
</div>

<script>
    // Inline script to guarantee the mode switcher works immediately and bypasses any JS cache
    document.addEventListener('DOMContentLoaded', function() {
        const editTab = document.getElementById('createEditTabBtn');
        const exportTab = document.getElementById('createExportTabBtn');
        const editView = document.getElementById('createEditView');
        const exportView = document.getElementById('createExportView');

        if (editTab && exportTab && editView && exportView) {
            editTab.addEventListener('click', function() {
                editTab.classList.add('active');
                exportTab.classList.remove('active');
                editView.classList.remove('hidden');
                exportView.classList.add('hidden');
            });

            exportTab.addEventListener('click', function() {
                exportTab.classList.add('active');
                editTab.classList.remove('active');
                editView.classList.add('hidden');
                exportView.classList.remove('hidden');
            });
        }
    });
</script>
