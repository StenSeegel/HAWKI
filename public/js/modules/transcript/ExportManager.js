import { Utils } from './Utils.js';

export class ExportManager {
    constructor(app) {
        this.app = app;
        // Initialize active template states if not set
        if (!this.app.state.selectedTemplate) {
            this.app.state.selectedTemplate = 'Mein Interview-Format';
        }
        if (!this.app.state.selectedTemplateSubtext) {
            this.app.state.selectedTemplateSubtext = 'Zusammenfassung · Entscheidungen · Aufgaben';
        }
        this.templates = [];
        this.previewCache = {};
        this.lastFocusedInput = null;
        this.loadPreviewCache();
        this.loadTemplates();
        this.loadCustomFormats();

        this.ensureTranscriptFormatState();

        this.transcriptPresets = {
            dialog_standard: {
                name: 'Dialog (Standard)',
                speakers: true,
                timestamps: true,
                avatars: true,
                bubbles: true,
                order: 'chronological',
                anonymize: false
            },
            lesefassung: {
                name: 'Lesefassung',
                speakers: true,
                timestamps: false,
                avatars: false,
                bubbles: false,
                order: 'chronological',
                anonymize: false
            },
            zeitcodes: {
                name: 'Mit Zeitcodes',
                speakers: false,
                timestamps: true,
                avatars: false,
                bubbles: false,
                order: 'chronological',
                anonymize: false
            },
            sprecher_gruppiert: {
                name: 'Nach Sprecher gruppiert',
                speakers: true,
                timestamps: false,
                avatars: false,
                bubbles: false,
                order: 'speaker',
                anonymize: false
            },
            fliesstext: {
                name: 'Nur Fließtext',
                speakers: false,
                timestamps: false,
                avatars: false,
                bubbles: false,
                order: 'chronological',
                anonymize: false
            }
        };

        // Global bindings
        window.openTemplateEditor = this.openTemplateEditor.bind(this);
        window.closeTemplateEditor = this.closeTemplateEditor.bind(this);
        window.saveTemplate = this.saveTemplate.bind(this);
        window.testPreview = this.testPreview.bind(this);
        window.refreshSection = this.refreshSection.bind(this);
        window.addNewSectionBlock = this.addNewSectionBlock.bind(this);
        window.deleteBlock = this.deleteBlock.bind(this);
        window.moveBlock = this.moveBlock.bind(this);
        window.insertPlaceholder = this.insertPlaceholder.bind(this);
        window.setFocusedInput = this.setFocusedInput.bind(this);
        window.updateBlockText = this.updateBlockText.bind(this);
        window.updateBlockLevel = this.updateBlockLevel.bind(this);
        window.updateSectionHeading = this.updateSectionHeading.bind(this);
        window.updateSectionInstruction = this.updateSectionInstruction.bind(this);
        
        window.showTranscriptSettings = this.showTranscriptSettings.bind(this);
        window.hideTranscriptSettings = this.hideTranscriptSettings.bind(this);
        window.toggleTranscriptAccordion = this.toggleTranscriptAccordion.bind(this);
        window.setTranscriptOrder = this.setTranscriptOrder.bind(this);
        window.saveCustomTranscriptTemplate = this.saveCustomTranscriptTemplate.bind(this);
        window.deleteCustomTranscriptTemplate = this.deleteCustomTranscriptTemplate.bind(this);

        // Global listener for insertion feedback
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.btn-tag-insert');
            if (btn && !btn.classList.contains('feedback-active')) {
                const originalText = btn.innerHTML;
                btn.classList.add('feedback-active');
                btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px; vertical-align:text-bottom;"><polyline points="20 6 9 17 4 12"></polyline></svg> Eingefügt`;
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.classList.remove('feedback-active');
                }, 1200);
            }
        });
    }

    ensureTranscriptFormatState() {
        if (!this.app.state.transcriptFormat) {
            this.app.state.transcriptFormat = {};
        }
        const f = this.app.state.transcriptFormat;
        if (f.speakers === undefined) f.speakers = true;
        if (f.timestamps === undefined) f.timestamps = true;
        if (f.avatars === undefined) f.avatars = true;
        if (f.bubbles === undefined) f.bubbles = true;
        if (f.anonymize === undefined) f.anonymize = false;
        if (!f.order) f.order = 'chronological';
        if (!f.visibleSpeakers) f.visibleSpeakers = {};
        
        if (!this.app.state.selectedTranscriptPreset) {
            this.app.state.selectedTranscriptPreset = 'dialog_standard';
        }
        if (!Array.isArray(this.app.state.customTranscriptTemplates)) {
            this.app.state.customTranscriptTemplates = [];
        }
    }

    getSpeakerColorId(speakerName) {
        if (this.app.state.speakerColorMap && this.app.state.speakerColorMap.has(speakerName)) {
            return this.app.state.speakerColorMap.get(speakerName).colorId;
        }
        if (!this.app.state.speakerColorMap) {
            this.app.state.speakerColorMap = new Map();
        }
        if (!this.app.state.speakerColorMap.has(speakerName)) {
            this.app.state.speakerColorMap.set(speakerName, {
                colorId: (this.app.state.speakerColorMap.size % 10) + 1,
                speakerIndex: this.app.state.speakerColorMap.size
            });
        }
        return this.app.state.speakerColorMap.get(speakerName).colorId;
    }

    updateActiveTranscriptTemplateIcon() {
        const iconContainer = document.getElementById('export-active-transcript-template-icon-container');
        if (!iconContainer) return;
        
        const currentPreset = this.app.state.selectedTranscriptPreset || 'dialog_standard';
        
        // Check if there is an active card in the DOM first
        const activeCard = document.querySelector('#export-transcript-settings-panel .template-select-card.active');
        if (activeCard) {
            const cardIconBox = activeCard.querySelector('.template-icon-box');
            if (cardIconBox) {
                iconContainer.innerHTML = cardIconBox.innerHTML;
                return;
            }
        }
        
        // Explicit checks based on the current preset ID if card isn't found/rendered yet
        if (currentPreset.startsWith('custom_')) {
            // Bookmark icon for custom templates
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bookmark"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>`;
            return;
        }
        
        if (currentPreset === 'lesefassung') {
            // Book-open icon
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-book-open"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>`;
            return;
        }
        
        if (currentPreset === 'zeitcodes') {
            // Clock icon
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-clock"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`;
            return;
        }
        
        if (currentPreset === 'sprecher_gruppiert') {
            // Users icon
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`;
            return;
        }
        
        if (currentPreset === 'fliesstext') {
            // File-text icon
            iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>`;
            return;
        }
        
        // Fallback for custom/sliders icon (Dialog standard or custom formatting sliders)
        iconContainer.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sliders-horizontal"><line x1="21" y1="4" x2="14" y2="4"/><line x1="10" y1="4" x2="3" y2="4"/><line x1="21" y1="12" x2="12" y2="12"/><line x1="8" y1="12" x2="3" y2="12"/><line x1="21" y1="20" x2="16" y2="20"/><line x1="12" y1="20" x2="3" y2="20"/><line x1="14" y1="2" x2="14" y2="6"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="16" y1="18" x2="16" y2="22"/></svg>`;
    }

    syncActiveTranscriptTemplateUI() {
        this.ensureTranscriptFormatState();
        const currentPreset = this.app.state.selectedTranscriptPreset || 'dialog_standard';
        
        // 1. Sync checkboxes/toggles in UI
        const f = this.app.state.transcriptFormat;
        const toggleSpeakers = document.getElementById('ts-toggle-speakers');
        const toggleTimestamps = document.getElementById('ts-toggle-timestamps');
        const toggleAvatars = document.getElementById('ts-toggle-avatars');
        const toggleBubbles = document.getElementById('ts-toggle-bubbles');
        const toggleAnonymize = document.getElementById('ts-toggle-anonymize');
        
        if (toggleSpeakers) {
            toggleSpeakers.checked = !!f.speakers;
        }
        if (toggleTimestamps) {
            toggleTimestamps.checked = !!f.timestamps;
        }
        if (toggleAvatars) {
            toggleAvatars.checked = !!f.avatars;
        }
        if (toggleBubbles) {
            toggleBubbles.checked = f.bubbles !== false;
        }
        if (toggleAnonymize) {
            toggleAnonymize.checked = !!f.anonymize;
        }
        
        // 2. Sync order segmented control
        const btnChrono = document.getElementById('ts-order-chronological');
        const btnSpeaker = document.getElementById('ts-order-speaker');
        if (btnChrono && btnSpeaker) {
            if (f.order === 'chronological') {
                btnChrono.classList.add('active');
                btnSpeaker.classList.remove('active');
            } else {
                btnChrono.classList.remove('active');
                btnSpeaker.classList.add('active');
            }
        }
        
        // 3. Highlight the correct preset / custom card
        document.querySelectorAll('#export-transcript-settings-panel [data-preset]').forEach(el => {
            const isCurrent = el.getAttribute('data-preset') === currentPreset;
            el.classList.toggle('active', isCurrent);
            const badge = el.querySelector('.badge-active-pill');
            if (badge) {
                badge.classList.toggle('hidden', !isCurrent);
            }
        });
        
        document.querySelectorAll('#export-transcript-settings-panel [data-custom-template]').forEach(el => {
            const isCurrent = currentPreset === `custom_${el.getAttribute('data-custom-template')}`;
            el.classList.toggle('active', isCurrent);
            const badge = el.querySelector('.badge-active-pill');
            if (badge) {
                badge.classList.toggle('hidden', !isCurrent);
            }
        });
        
        // 4. Update the active template name in the subheader trigger
        const activeTmplNameEl = document.getElementById('export-active-transcript-template-name');
        if (activeTmplNameEl) {
            if (currentPreset.startsWith('custom_')) {
                const tmplName = currentPreset.substring(7);
                activeTmplNameEl.textContent = tmplName;
                
                // Pre-fill name input
                const inputEl = document.getElementById('ts-template-name-input');
                if (inputEl) {
                    inputEl.value = tmplName;
                }
            } else if (currentPreset === 'custom') {
                activeTmplNameEl.textContent = 'Benutzerdefiniert';
            } else {
                const preset = this.transcriptPresets[currentPreset];
                activeTmplNameEl.textContent = preset ? preset.name : 'Dialog (Standard)';
                
                // Clear name input for presets
                const inputEl = document.getElementById('ts-template-name-input');
                if (inputEl) {
                    inputEl.value = '';
                }
            }
        }
        
        // 5. Update the active template icon in the subheader trigger
        this.updateActiveTranscriptTemplateIcon();
    }

    selectExportOption(option) {
        this.hideTemplateSelect();
        document.getElementById('export-transcript-settings-panel')?.classList.add('hidden');
        document.getElementById('export-template-editor-panel')?.classList.add('hidden');
        document.querySelectorAll('.sidebar-export-card').forEach(el => el.classList.remove('active'));
        const selectedCard = document.querySelector(`.sidebar-export-card[data-option="${option}"]`);
        if (selectedCard) selectedCard.classList.add('active');

        this.app.state.exportType = option;
        
        const subtitle = document.getElementById('export-preview-subtitle');
        const subheader = document.getElementById('export-subheader');
        const templatePanel = document.getElementById('export-template-panel');
        const transcriptTemplatePanel = document.getElementById('export-transcript-template-panel');
        const placeholderView = document.getElementById('export-placeholder-view');
        const resultContainer = document.getElementById('export-result-container');
        const headerActions = document.getElementById('export-header-actions');
        const footer = document.querySelector('.transcript-view-footer');
        const metaPanel = document.getElementById('export-preview-meta-panel');
        
        const formatContainer = document.getElementById('export-format-options-footer-container');
        const formatFooter = document.getElementById('export-format-options-footer');

        const separator = document.getElementById('export-footer-split-separator');
        const chevronBtn = document.getElementById('export-footer-chevron-btn');
        const chevronIcon = document.getElementById('export-footer-chevron-icon');

        if (option === 'summary' || option === 'transcript') {
            if (subtitle) subtitle.textContent = "Vorschau";
            if (subheader) subheader.classList.remove('hidden');
            
            if (option === 'summary') {
                if (templatePanel) templatePanel.classList.remove('hidden');
                if (transcriptTemplatePanel) transcriptTemplatePanel.classList.add('hidden');
            } else {
                if (templatePanel) templatePanel.classList.add('hidden');
                if (transcriptTemplatePanel) transcriptTemplatePanel.classList.remove('hidden');
            }
            
            if (formatContainer) formatContainer.classList.add('hidden'); // hidden by default
            if (separator) separator.classList.remove('hidden');
            if (chevronBtn) chevronBtn.classList.remove('hidden');
            if (chevronIcon) chevronIcon.classList.remove('rotate-180');
            
            if (formatFooter) {
                formatFooter.innerHTML = `
                    <button class="export-format-btn" data-format="docx" onclick="window.app.exportManager.setFormat('docx')">DOCX</button>
                    <button class="export-format-btn" data-format="pdf" onclick="window.app.exportManager.setFormat('pdf')">PDF</button>
                    <button class="export-format-btn" data-format="markdown" onclick="window.app.exportManager.setFormat('markdown')">Markdown</button>
                    <button class="export-format-btn" data-format="txt" onclick="window.app.exportManager.setFormat('txt')">TXT</button>
                `;
            }

            if (option === 'summary') {
                const currentFormat = this.app.state.exportFormat || 'docx';
                this.setFormat(currentFormat);
                
                if (this.app.state.summaryGenerated) {
                    if (placeholderView) placeholderView.classList.add('hidden');
                    if (resultContainer) resultContainer.classList.remove('hidden');
                    if (headerActions) headerActions.classList.remove('hidden');
                    if (metaPanel) {
                        metaPanel.classList.add('hidden');
                    }
                } else {
                    if (placeholderView) placeholderView.classList.remove('hidden');
                    if (resultContainer) resultContainer.classList.add('hidden');
                    if (headerActions) headerActions.classList.add('hidden');
                    if (metaPanel) metaPanel.classList.add('hidden');
                }
            } else {
                // Option: transcript
                if (placeholderView) placeholderView.classList.add('hidden');
                if (resultContainer) resultContainer.classList.remove('hidden');
                if (headerActions) headerActions.classList.remove('hidden');
                if (metaPanel) metaPanel.classList.add('hidden');
                
                this.syncActiveTranscriptTemplateUI();
                this.exportToVerlauf();
                const currentFormat = this.app.state.exportFormat || 'docx';
                this.setFormat(currentFormat);
            }
        } else {
            if (subheader) subheader.classList.add('hidden');
            if (templatePanel) templatePanel.classList.add('hidden');
            if (transcriptTemplatePanel) transcriptTemplatePanel.classList.add('hidden');
            if (placeholderView) placeholderView.classList.add('hidden');
            if (resultContainer) resultContainer.classList.remove('hidden');
            if (headerActions) headerActions.classList.remove('hidden');
            if (metaPanel) metaPanel.classList.add('hidden');

            if (option === 'srt') {
                if (subtitle) subtitle.textContent = "Vorschau";
                if (formatContainer) formatContainer.classList.add('hidden'); // hidden by default
                if (separator) separator.classList.remove('hidden');
                if (chevronBtn) chevronBtn.classList.remove('hidden');
                if (chevronIcon) chevronIcon.classList.remove('rotate-180');
                if (formatFooter) {
                    formatFooter.innerHTML = `
                        <button class="export-format-btn" data-format="srt" onclick="window.app.exportManager.setSrtFormat('srt')">SRT</button>
                        <button class="export-format-btn" data-format="vtt" onclick="window.app.exportManager.setSrtFormat('vtt')">VTT</button>
                    `;
                }
                const currentSrtFormat = this.app.state.srtFormat || 'srt';
                this.setSrtFormat(currentSrtFormat);
            } else {
                if (formatContainer) formatContainer.classList.add('hidden');
                if (separator) separator.classList.add('hidden');
                if (chevronBtn) chevronBtn.classList.add('hidden');
                if (option === 'json') {
                    if (subtitle) subtitle.textContent = "Vorschau";
                    this.exportToJSON();
                }
            }
        }

        if (footer) footer.classList.remove('hidden');
        this.updateFooterState();
        this.updateDownloadButtonText();
    }

    setFormat(format) {
        document.querySelectorAll('.export-format-btn').forEach(btn => btn.classList.remove('active'));
        const selectedBtn = document.querySelector(`.export-format-btn[data-format="${format}"]`);
        if (selectedBtn) selectedBtn.classList.add('active');

        this.app.state.exportFormat = format;

        if (this.app.state.exportData) {
            this.app.state.exportData.extension = (format === 'markdown') ? 'md' : format;
            if (format === 'txt') {
                this.app.state.exportData.type = 'text/plain;charset=utf-8';
            } else if (format === 'markdown') {
                this.app.state.exportData.type = 'text/markdown;charset=utf-8';
            } else if (format === 'pdf') {
                this.app.state.exportData.type = 'application/pdf';
            } else if (format === 'docx') {
                this.app.state.exportData.type = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            }
        }
        
        this.updateDownloadButtonText();
    }

    setSrtFormat(format) {
        document.querySelectorAll('.export-format-btn').forEach(btn => btn.classList.remove('active'));
        const selectedBtn = document.querySelector(`.export-format-btn[data-format="${format}"]`);
        if (selectedBtn) selectedBtn.classList.add('active');

        this.app.state.srtFormat = format;
        
        if (format === 'vtt') {
            this.exportToVTT();
        } else {
            this.exportToSRT();
        }
        
        this.updateDownloadButtonText();
    }

    updateDownloadButtonText() {
        const downloadBtn = document.getElementById('export-footer-download-btn');
        if (!downloadBtn) return;

        const option = this.app.state.exportType || 'summary';
        const format = this.app.state.exportFormat || 'docx';

        let text = "Herunterladen";
        if (option === 'summary' || option === 'transcript') {
            text = `Als ${format.toUpperCase()} herunterladen`;
        } else if (option === 'srt') {
            const srtFormat = this.app.state.srtFormat || 'srt';
            text = `Als ${srtFormat.toUpperCase()} herunterladen`;
        } else if (option === 'json') {
            text = "Als JSON herunterladen";
        }

        downloadBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
            ${text}
        `;
    }

    updateExportPreviewMetadata() {
        const titleDiv = document.getElementById('export-preview-title');
        const participantsDiv = document.getElementById('export-preview-participants');
        
        if (titleDiv) {
            const activeTitle = document.getElementById('current-transcript-title')?.textContent || 'Interview';
            titleDiv.textContent = activeTitle;
        }

        if (participantsDiv) {
            const speakers = new Set();
            if (this.app.state.currentTranscriptSegments) {
                this.app.state.currentTranscriptSegments.forEach(s => {
                    if (s.speaker) speakers.add(s.speaker);
                });
            }
            if (speakers.size > 0) {
                participantsDiv.textContent = "Teilnehmer: " + Array.from(speakers).join(', ');
            } else {
                participantsDiv.textContent = "";
            }
        }
    }

    updateFormatDropdown(option) {
        const formatSelect = document.getElementById('export-file-format');
        if (!formatSelect) return;

        formatSelect.innerHTML = '';

        if (option === 'summary') {
            formatSelect.innerHTML = `
                <option value="md">Markdown (.md)</option>
                <option value="txt">Text (.txt)</option>
            `;
        } else if (option === 'transcript') {
            formatSelect.innerHTML = `
                <option value="txt">Text (.txt)</option>
            `;
        } else if (option === 'srt') {
            formatSelect.innerHTML = `
                <option value="srt">SRT (.srt)</option>
                <option value="vtt">VTT (.vtt)</option>
            `;
        } else if (option === 'json') {
            formatSelect.innerHTML = `
                <option value="json">JSON (.json)</option>
            `;
        }
    }

    changeExportFormat(format) {
        if (this.app.state.exportType === 'srt') {
            if (format === 'vtt') {
                this.exportToVTT();
            } else {
                this.exportToSRT();
            }
        }
        
        if (this.app.state.exportType === 'summary' && this.app.state.exportData) {
            this.app.state.exportData.extension = format;
            if (format === 'txt') {
                this.app.state.exportData.type = 'text/plain;charset=utf-8';
            } else {
                this.app.state.exportData.type = 'text/markdown;charset=utf-8';
            }
        }
    }

    updateFooterState(statusText = null, disableDownload = null) {
        const footerStatus = document.getElementById('export-footer-status');
        const footerDownloadBtn = document.getElementById('export-footer-download-btn');
        const footerCopyBtn = document.getElementById('export-footer-copy-btn');
        if (!footerStatus || !footerDownloadBtn) return;

        const option = this.app.state.exportType || 'summary';

        if (statusText !== null) {
            footerStatus.textContent = statusText;
        } else {
            if (option === 'summary' && !this.app.state.summaryGenerated) {
                footerStatus.textContent = "Zusammenfassung noch nicht erstellt";
            } else {
                footerStatus.textContent = "Vorschau bereit zum Herunterladen";
            }
        }

        const isCurrentlyDisabled = disableDownload !== null ? disableDownload : (option === 'summary' && !this.app.state.summaryGenerated);
        
        footerDownloadBtn.disabled = isCurrentlyDisabled;
        if (footerCopyBtn) {
            footerCopyBtn.disabled = isCurrentlyDisabled;
        }
        const footerChevronBtn = document.getElementById('export-footer-chevron-btn');
        if (footerChevronBtn) {
            footerChevronBtn.disabled = isCurrentlyDisabled;
        }

        document.querySelectorAll('.export-format-btn').forEach(btn => {
            btn.disabled = isCurrentlyDisabled;
        });
    }

    triggerExportCopy() {
        const option = this.app.state.exportType || 'summary';
        const format = this.app.state.exportFormat || 'docx';
        
        let copyText = "";
        if (option === 'summary' || option === 'transcript') {
            if (format === 'markdown') {
                copyText = this.app.state.exportData ? this.app.state.exportData.content : "";
            } else {
                const previewContent = document.getElementById('export-preview-content');
                if (previewContent) {
                    copyText = previewContent.innerText || previewContent.textContent || "";
                }
            }
        } else {
            copyText = this.app.state.exportData ? this.app.state.exportData.content : "";
        }

        if (!copyText) return;
        
        navigator.clipboard.writeText(copyText)
            .then(() => {
                const copyBtn = document.getElementById('export-footer-copy-btn');
                if (copyBtn) {
                    const originalHtml = copyBtn.innerHTML;
                    copyBtn.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 6px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Kopiert!
                    `;
                    setTimeout(() => {
                        copyBtn.innerHTML = originalHtml;
                    }, 2000);
                }
            })
            .catch(err => {
                console.error("Fehler beim Kopieren:", err);
            });
    }

    exportToJSON() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) {
            console.warn("Keine Segmente für JSON Export vorhanden");
            return;
        }

        const jsonContent = JSON.stringify(this.app.state.currentTranscriptSegments, null, 2);

        this.app.state.exportData = {
            content: jsonContent,
            type: 'application/json;charset=utf-8',
            extension: 'json'
        };

        const previewContent = document.getElementById('export-preview-content');
        if (previewContent) {
            previewContent.innerHTML = '';
            const pre = document.createElement('pre');
            pre.className = 'export-pre-preview';
            pre.textContent = jsonContent;
            previewContent.appendChild(pre);
        }

        this.updateFooterState("Vorschau bereit zum Herunterladen", false);
    }

    exportToSRT() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) {
            console.warn("Keine Segmente für SRT Export vorhanden");
            return;
        }

        const anonymize = this.app.state.transcriptFormat?.anonymize || false;
        const subtitleBlocks = Utils.getSubtitleBlocks(this.app.state.currentTranscriptSegments, anonymize);
        let srtContent = "";

        subtitleBlocks.forEach((block, index) => {
            const startStr = Utils.formatSecondsToSRT(block.start);
            const endStr = Utils.formatSecondsToSRT(block.end);
            
            srtContent += `${index + 1}\n`;
            srtContent += `${startStr} --> ${endStr}\n`;
            srtContent += `${block.text}\n\n`;
        });

        this.app.state.exportData = {
            content: srtContent,
            type: 'text/plain;charset=utf-8',
            extension: 'srt'
        };

        const previewContent = document.getElementById('export-preview-content');
        if (previewContent) {
            previewContent.innerHTML = '';
            const pre = document.createElement('pre');
            pre.className = 'export-pre-preview';
            pre.textContent = srtContent;
            previewContent.appendChild(pre);
        }

        this.updateFooterState("Vorschau bereit zum Herunterladen", false);
    }

    exportToVTT() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) {
            console.warn("Keine Segmente für VTT Export vorhanden");
            return;
        }

        const anonymize = this.app.state.transcriptFormat?.anonymize || false;
        const subtitleBlocks = Utils.getSubtitleBlocks(this.app.state.currentTranscriptSegments, anonymize);
        let vttContent = "WEBVTT\n\n";

        subtitleBlocks.forEach((block, index) => {
            const startStr = Utils.formatSecondsToSRT(block.start).replace(',', '.');
            const endStr = Utils.formatSecondsToSRT(block.end).replace(',', '.');
            
            vttContent += `${index + 1}\n`;
            vttContent += `${startStr} --> ${endStr}\n`;
            vttContent += `${block.text}\n\n`;
        });

        this.app.state.exportData = {
            content: vttContent,
            type: 'text/vtt;charset=utf-8',
            extension: 'vtt'
        };

        const previewContent = document.getElementById('export-preview-content');
        if (previewContent) {
            previewContent.innerHTML = '';
            const pre = document.createElement('pre');
            pre.className = 'export-pre-preview';
            pre.textContent = vttContent;
            previewContent.appendChild(pre);
        }

        this.updateFooterState("Vorschau bereit zum Herunterladen", false);
    }

    exportToVerlauf() {
        this.ensureTranscriptFormatState();
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) {
            console.warn("Keine Segmente für Verlaufsprotokoll vorhanden");
            return;
        }

        const format = this.app.state.transcriptFormat || {
            speakers: true,
            timestamps: true,
            avatars: true,
            order: 'chronological',
            visibleSpeakers: {}
        };

        const filteredSegments = this.app.state.currentTranscriptSegments.filter(segment => {
            const speakerName = segment.speaker || 'Unbekannt';
            return format.visibleSpeakers[speakerName] !== false;
        });

        // Build anonymized speaker mapping if enabled
        let anonymizedSpeakerMap = new Map();
        if (format.anonymize) {
            let speakerIndex = 1;
            filteredSegments.forEach((segment) => {
                let speakerName = segment.speaker || 'Unbekannt';
                if (!anonymizedSpeakerMap.has(speakerName)) {
                    anonymizedSpeakerMap.set(speakerName, `Speaker ${speakerIndex}`);
                    speakerIndex++;
                }
            });
        }
        
        const getSpeakerDisplayName = (name) => {
            if (format.anonymize) {
                return anonymizedSpeakerMap.get(name) || name;
            }
            return name;
        };

        if (filteredSegments.length === 0) {
            let txtContent = "[Alle Sprecher ausgeblendet]";
            this.app.state.exportData = {
                content: txtContent,
                type: 'text/plain;charset=utf-8',
                extension: 'txt'
            };
            const settingsPanel = document.getElementById('export-transcript-settings-panel');
            const isSettingsVisible = settingsPanel && !settingsPanel.classList.contains('hidden');
            const previewContent = isSettingsVisible
                ? document.getElementById('transcript-settings-preview-content')
                : document.getElementById('export-preview-content');
            if (previewContent) {
                previewContent.innerHTML = '<div class="transcript-preview-empty" style="text-align: center; color: var(--text-faded-color); padding: 40px 0;">[Alle Sprecher ausgeblendet]</div>';
            }
            this.updateFooterState("Vorschau bereit zum Herunterladen", false);
            return;
        }

        let txtContent = "";
        
        let speakerMap = new Map();
        let colorIndexCounter = 1;
        
        filteredSegments.forEach((segment) => {
            let speakerName = segment.speaker;
            if (!speakerName) {
                speakerName = `Unbekannt ${colorIndexCounter}`;
                colorIndexCounter++;
            }
            if (!speakerMap.has(speakerName)) {
                speakerMap.set(speakerName, true);
            }
        });
        
        const timestamp = new Date().toLocaleString();
        txtContent += `VERLAUFSPROTOKOLL\n`;
        txtContent += `Erstellt am: ${timestamp}\n`;
        if (this.app.state.currentTranscriptSlug) {
            txtContent += `Transkription-ID: ${this.app.state.currentTranscriptSlug}\n`;
        }
        
        txtContent += `\nTEILNEHMER:\n`;
        speakerMap.forEach((_, name) => {
            txtContent += `- ${getSpeakerDisplayName(name)}\n`;
        });
        
        txtContent += `\n` + `=`.repeat(50) + `\n\n`;

        if (format.order === 'speaker') {
            // Group by speaker
            let speakerSegments = {};
            filteredSegments.forEach(segment => {
                const speakerName = segment.speaker || 'Unbekannt';
                if (!speakerSegments[speakerName]) {
                    speakerSegments[speakerName] = [];
                }
                speakerSegments[speakerName].push(segment);
            });
            
            Object.keys(speakerSegments).forEach(speakerName => {
                txtContent += `${getSpeakerDisplayName(speakerName)}:\n`;
                let speakerText = "";
                let segments = speakerSegments[speakerName];
                
                segments.forEach((segment) => {
                    let safeText = Utils.getSegmentTextWithRedactions(segment);
                    if (format.timestamps) {
                        speakerText += `[${Utils.formatSecondsToTime(segment.start)}] ${safeText.trim()}\n`;
                    } else {
                        speakerText += `${safeText.trim()}\n`;
                    }
                });
                
                txtContent += `${speakerText.trim()}\n\n`;
            });
        } else {
            // Chronological
            let currentSpeaker = null;
            let currentText = "";
            let blockStartTime = 0;
            
            filteredSegments.forEach((segment, index) => {
                let segSpeaker = segment.speaker || 'Unbekannt';
                let safeText = Utils.getSegmentTextWithRedactions(segment);

                if (index === 0 || segSpeaker !== currentSpeaker || (segment.start - filteredSegments[index-1].end) > 10) {
                    if (currentSpeaker) {
                        let header = "";
                        if (format.timestamps && format.speakers) {
                            header = `[${Utils.formatSecondsToTime(blockStartTime)}] ${getSpeakerDisplayName(currentSpeaker)}:\n`;
                        } else if (format.timestamps) {
                            header = `[${Utils.formatSecondsToTime(blockStartTime)}]:\n`;
                        } else if (format.speakers) {
                            header = `${getSpeakerDisplayName(currentSpeaker)}:\n`;
                        }
                        txtContent += `${header}${currentText.trim()}\n\n`;
                    }
                    currentSpeaker = segSpeaker;
                    currentText = safeText + " ";
                    blockStartTime = segment.start;
                } else {
                    currentText += safeText + " ";
                }
            });

            if (currentSpeaker) {
                let header = "";
                if (format.timestamps && format.speakers) {
                    header = `[${Utils.formatSecondsToTime(blockStartTime)}] ${getSpeakerDisplayName(currentSpeaker)}:\n`;
                } else if (format.timestamps) {
                    header = `[${Utils.formatSecondsToTime(blockStartTime)}]:\n`;
                } else if (format.speakers) {
                    header = `${getSpeakerDisplayName(currentSpeaker)}:\n`;
                }
                txtContent += `${header}${currentText.trim()}\n`;
            }
        }

        this.app.state.exportData = {
            content: txtContent,
            type: 'text/plain;charset=utf-8',
            extension: 'txt'
        };

        const settingsPanel = document.getElementById('export-transcript-settings-panel');
        const isSettingsVisible = settingsPanel && !settingsPanel.classList.contains('hidden');
        const previewContent = isSettingsVisible
            ? document.getElementById('transcript-settings-preview-content')
            : document.getElementById('export-preview-content');
        if (previewContent) {
            previewContent.innerHTML = '';
            
            const container = document.createElement('div');
            container.className = 'transcript-preview-html-container';
            container.style.display = 'flex';
            container.style.flexDirection = 'column';
            container.style.gap = '16px';
            
            const renderBlockHTML = (speaker, text, startTime, colorId) => {
                const blockDiv = document.createElement('div');
                blockDiv.className = 'transcript-preview-block';
                if (format.bubbles !== false) {
                    blockDiv.style.background = 'var(--background-secondary, #f8fafc)';
                    blockDiv.style.border = 'var(--border-stroke-thin, 1px solid #e2e8f0)';
                    blockDiv.style.borderRadius = '8px';
                    blockDiv.style.padding = '12px 16px';
                } else {
                    blockDiv.style.background = 'transparent';
                    blockDiv.style.border = 'none';
                    blockDiv.style.borderRadius = '0';
                    blockDiv.style.padding = '4px 0 12px 0';
                    if (format.speakers || format.timestamps) {
                        blockDiv.style.borderBottom = 'var(--border-stroke-thin, 1px solid rgba(226, 232, 240, 0.5))';
                    }
                }
                blockDiv.style.display = 'flex';
                blockDiv.style.flexDirection = 'column';
                blockDiv.style.gap = '8px';
                
                const headerDiv = document.createElement('div');
                headerDiv.className = 'transcript-preview-header';
                headerDiv.style.display = 'flex';
                headerDiv.style.alignItems = 'center';
                headerDiv.style.gap = '8px';
                
                if (format.avatars) {
                    const avatarSpan = document.createElement('span');
                    avatarSpan.className = `speaker-avatar speaker-color-${colorId}`;
                    avatarSpan.style.width = '24px';
                    avatarSpan.style.height = '24px';
                    avatarSpan.style.borderRadius = '50%';
                    avatarSpan.style.display = 'inline-flex';
                    avatarSpan.style.alignItems = 'center';
                    avatarSpan.style.justifyContent = 'center';
                    headerDiv.appendChild(avatarSpan);
                }
                
                if (format.speakers) {
                    const speakerSpan = document.createElement('span');
                    speakerSpan.className = 'speaker-label';
                    speakerSpan.style.fontWeight = '700';
                    speakerSpan.style.fontSize = '13px';
                    speakerSpan.style.color = 'var(--text-color)';
                    speakerSpan.textContent = speaker;
                    headerDiv.appendChild(speakerSpan);
                }
                
                if (format.timestamps && format.order !== 'speaker') {
                    const timeSpan = document.createElement('span');
                    timeSpan.className = 'speaker-time';
                    timeSpan.style.fontSize = '11px';
                    timeSpan.style.color = 'var(--text-faded-color, #64748b)';
                    timeSpan.textContent = `[${Utils.formatSecondsToTime(startTime)}]`;
                    headerDiv.appendChild(timeSpan);
                }
                
                if (headerDiv.children.length > 0) {
                    blockDiv.appendChild(headerDiv);
                }
                
                const bodyDiv = document.createElement('div');
                bodyDiv.className = 'transcript-preview-body';
                bodyDiv.style.fontSize = '13px';
                bodyDiv.style.lineHeight = '1.5';
                bodyDiv.style.color = 'var(--text-color)';
                bodyDiv.style.whiteSpace = 'pre-wrap';
                bodyDiv.textContent = text.trim();
                blockDiv.appendChild(bodyDiv);
                
                container.appendChild(blockDiv);
            };
            
            if (format.order === 'speaker') {
                // Group by speaker
                let speakerSegments = {};
                filteredSegments.forEach(segment => {
                    const speakerName = segment.speaker || 'Unbekannt';
                    if (!speakerSegments[speakerName]) {
                        speakerSegments[speakerName] = [];
                    }
                    speakerSegments[speakerName].push(segment);
                });
                
                Object.keys(speakerSegments).forEach(speakerName => {
                    let text = "";
                    let startTime = speakerSegments[speakerName][0].start;
                    let colorId = this.getSpeakerColorId(speakerName);
                    
                    speakerSegments[speakerName].forEach(seg => {
                        let safeText = Utils.getSegmentTextWithRedactions(seg);
                        if (format.timestamps) {
                            text += `[${Utils.formatSecondsToTime(seg.start)}] ${safeText.trim()}\n`;
                        } else {
                            text += `${safeText.trim()}\n`;
                        }
                    });
                    
                    renderBlockHTML(getSpeakerDisplayName(speakerName), text, startTime, colorId);
                });
            } else {
                // Chronological
                let currentSpeaker = null;
                let currentText = "";
                let blockStartTime = 0;
                let blockColorId = 1;
                
                filteredSegments.forEach((segment, index) => {
                    let segSpeaker = segment.speaker || 'Unbekannt';
                    let safeText = Utils.getSegmentTextWithRedactions(segment);
                    let colorId = this.getSpeakerColorId(segSpeaker);

                    if (index === 0 || segSpeaker !== currentSpeaker || (segment.start - filteredSegments[index-1].end) > 10) {
                        if (currentSpeaker) {
                            renderBlockHTML(getSpeakerDisplayName(currentSpeaker), currentText, blockStartTime, blockColorId);
                        }
                        currentSpeaker = segSpeaker;
                        currentText = safeText + " ";
                        blockStartTime = segment.start;
                        blockColorId = colorId;
                    } else {
                        currentText += safeText + " ";
                    }
                });
                if (currentSpeaker) {
                    renderBlockHTML(getSpeakerDisplayName(currentSpeaker), currentText, blockStartTime, blockColorId);
                }
            }
            
            previewContent.appendChild(container);
        }

        this.updateFooterState("Vorschau bereit zum Herunterladen", false);
    }

    exportToErgebnis() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) {
            console.warn("Keine Segmente vorhanden");
            return;
        }
        
        const previewContent = document.getElementById('export-preview-content');
        if (previewContent) {
            previewContent.innerHTML = '';
            const tmpl = document.getElementById('tmpl-export-check-data');
            if (tmpl) previewContent.appendChild(tmpl.content.cloneNode(true));
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        fetch('/req/transcription/summarize', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({ 
                check_only: true,
                transcription_slug: this.app.state.currentTranscriptSlug || null
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.summary) {
                this.renderErgebnisprotokoll(false, null, data.summary);
            } else {
                this.renderErgebnisprotokoll();
            }
        })
        .catch(err => {
            console.error("Fehler beim Prüfen:", err);
            this.renderErgebnisprotokoll();
        });
    }

    renderErgebnisprotokoll(loading = false, errorMsg = null, markdownContent = null) {
        const previewContent = document.getElementById('export-preview-content');
        if (!previewContent) return;

        const placeholderView = document.getElementById('export-placeholder-view');
        const resultContainer = document.getElementById('export-result-container');
        const headerActions = document.getElementById('export-header-actions');
        const footer = document.querySelector('.transcript-view-footer');
        const metaPanel = document.getElementById('export-preview-meta-panel');
        
        if (loading) {
            if (placeholderView) placeholderView.classList.add('hidden');
            if (resultContainer) resultContainer.classList.remove('hidden');
        if (headerActions) headerActions.classList.add('hidden');
            if (footer) footer.classList.remove('hidden');
            if (metaPanel) metaPanel.classList.add('hidden');

            previewContent.innerHTML = '';
            const tmpl = document.getElementById('tmpl-export-loading-summary');
            if (tmpl) {
                previewContent.appendChild(tmpl.content.cloneNode(true));
            }

            // Dynamically populate loading skeletons
            const skeletonList = previewContent.querySelector('.export-loading-skeleton-list');
            const templateSubtext = this.app.state.selectedTemplateSubtext || 'Zusammenfassung · Entscheidungen · Aufgaben';
            if (skeletonList && templateSubtext) {
                const headlines = templateSubtext.split('·').map(h => h.trim()).filter(Boolean);
                
                // Add initial skeleton lines at the top
                let skeletonsHtml = `
                    <div class="skeleton-item first-skeleton-item">
                        <div class="skeleton-line active w-full"></div>
                        <div class="skeleton-line active w-2-3"></div>
                    </div>
                `;
                
                if (headlines.length > 0) {
                    skeletonsHtml += headlines.map((headline, idx) => {
                        let lineWidthClass = 'w-full';
                        if (idx % 3 === 1) lineWidthClass = 'w-1-2';
                        else if (idx % 3 === 2) lineWidthClass = 'w-2-3';
                        
                        return `
                            <div class="skeleton-item">
                                <span class="skeleton-label">${headline}</span>
                                <div class="skeleton-line-group">
                                    <div class="skeleton-line active ${lineWidthClass}"></div>
                                    ${idx % 2 === 0 ? `<div class="skeleton-line active ${idx % 3 === 0 ? 'w-2-3' : 'w-1-2'}"></div>` : ''}
                                </div>
                            </div>
                        `;
                    }).join('');
                }
                skeletonList.innerHTML = skeletonsHtml;
            }
            
            this.updateFooterState("Ergebnisprotokoll wird generiert...", true);
            this.updateDownloadButtonText();
            return;
        }

        if (errorMsg) {
            if (placeholderView) placeholderView.classList.add('hidden');
            if (resultContainer) resultContainer.classList.remove('hidden');
        if (headerActions) headerActions.classList.add('hidden');
            if (footer) footer.classList.remove('hidden');
            if (metaPanel) metaPanel.classList.add('hidden');

            previewContent.innerHTML = '';
            const tmpl = document.getElementById('tmpl-export-error');
            if (tmpl) {
                const clone = tmpl.content.cloneNode(true);
                clone.querySelector('.error-msg').textContent = errorMsg;
                previewContent.appendChild(clone);
            }
            
            this.updateFooterState("Generierung fehlgeschlagen", true);
            this.updateDownloadButtonText();
            return;
        }
        
        if (markdownContent) {
            this.app.state.summaryGenerated = true;

            if (placeholderView) placeholderView.classList.add('hidden');
            if (resultContainer) resultContainer.classList.remove('hidden');
                if (headerActions) headerActions.classList.remove('hidden');
            if (footer) footer.classList.remove('hidden');
            if (metaPanel) {
                metaPanel.classList.add('hidden');
            }

            this.app.state.exportData = {
                content: markdownContent,
                type: 'text/markdown;charset=utf-8',
                extension: 'md'
            };

            const currentFormat = this.app.state.exportFormat || 'docx';
            this.setFormat(currentFormat);

            let htmlContent = markdownContent;
            if (window.md && typeof window.md.render === 'function') {
                htmlContent = window.md.render(markdownContent);
            }

            previewContent.innerHTML = '';
            const wrapper = document.createElement('div');
            wrapper.className = 'markdown-prose export-markdown-preview';
            if (window.md && typeof window.md.render === 'function') {
                wrapper.innerHTML = htmlContent;
            } else {
                const pre = document.createElement('pre');
                pre.className = 'export-pre-preview';
                pre.textContent = htmlContent;
                wrapper.appendChild(pre);
            }
            previewContent.appendChild(wrapper);
            
            this.updateFooterState("Zusammenfassung bereit zum Herunterladen", false);
            this.updateDownloadButtonText();
        } else {
            this.app.state.summaryGenerated = false;

            if (placeholderView) placeholderView.classList.remove('hidden');
            if (resultContainer) resultContainer.classList.add('hidden');
        if (headerActions) headerActions.classList.add('hidden');
            if (footer) footer.classList.remove('hidden');
            if (metaPanel) metaPanel.classList.add('hidden');

            previewContent.innerHTML = '';
            this.updateFooterState("Zusammenfassung noch nicht erstellt", true);
            this.updateDownloadButtonText();
        }
    }

    generateErgebnisprotokoll(force = false) {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) return;

        this.renderErgebnisprotokoll(true);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        const activeTmpl = this.templates.find(t => t.name === this.app.state.selectedTemplate);
        const sections = activeTmpl ? activeTmpl.structure.filter(b => b.type === 'section') : null;
        const structure = activeTmpl ? activeTmpl.structure : null;

        let bodyObj = {
            transcription_slug: this.app.state.currentTranscriptSlug,
            force_regenerate: force
        };

        if (sections) {
            bodyObj.sections = sections;
            bodyObj.structure = structure;
            bodyObj.preview = false;
        } else {
            let textPayload = "";
            let currentSpeaker = null;
            let currentText = "";
            
            this.app.state.currentTranscriptSegments.forEach((segment, index) => {
                let segSpeaker = segment.speaker || 'Unbekannt';
                let safeText = Utils.getSegmentTextWithRedactions(segment);

                if (index === 0 || segSpeaker !== currentSpeaker || (segment.start - this.app.state.currentTranscriptSegments[index-1].end) > 10) {
                    if (currentSpeaker) {
                        textPayload += `${currentSpeaker}: ${currentText.trim()}\n`;
                    }
                    currentSpeaker = segSpeaker;
                    currentText = safeText + " ";
                } else {
                    currentText += safeText + " ";
                }
            });
            
            if (currentSpeaker) {
                textPayload += `${currentSpeaker}: ${currentText.trim()}\n`;
            }
            bodyObj.transcript_text = textPayload;
        }

        const modelSelect = document.getElementById('ergebnis-model-select');
        const model = modelSelect ? modelSelect.value : null;
        if (model) bodyObj.model = model;

        fetch('/req/transcription/summarize', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify(bodyObj)
        })
        .then(res => {
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (data.success && data.summary) {
                this.renderErgebnisprotokoll(false, null, data.summary);
            } else {
                this.renderErgebnisprotokoll(false, data.message || "Unbekannter Serverfehler");
            }
        })
        .catch(err => {
            console.error("Generierung fehlgeschlagen:", err);
            this.renderErgebnisprotokoll(false, "Fehler bei der Kommunikation mit dem Server.");
        });
    }

    regenerateCurrentExport() {
        const option = this.app.state.exportType || 'summary';
        if (option === 'srt') {
            this.exportToSRT();
        } else if (option === 'transcript') {
            this.exportToVerlauf();
        } else if (option === 'summary') {
            this.generateErgebnisprotokoll(true);
        } else if (option === 'json') {
            this.exportToJSON();
        }
    }

    triggerExportDownload() {
        if (!this.app.state.exportData) return;
        
        const option = this.app.state.exportType || 'summary';
        const format = this.app.state.exportFormat || 'docx';
        const title = document.getElementById('current-transcript-title')?.textContent || 'Interview';
        const isMarkdown = (option === 'summary');
        
        const id = this.app.state.currentTranscriptSlug || 'export';
        const filename = `transkription-${id}.${this.app.state.exportData.extension}`;

        const downloadBlob = (blob) => {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        };

        if (format === 'docx') {
            this.generateDocxBlob(this.app.state.exportData.content, title, isMarkdown)
                .then(blob => {
                    downloadBlob(blob);
                })
                .catch(err => {
                    console.error("Fehler beim DOCX-Export:", err);
                    alert("Export fehlgeschlagen.");
                });
        } else if (format === 'pdf') {
            try {
                const blob = this.generatePdfBlob(this.app.state.exportData.content, title, isMarkdown);
                if (blob) {
                    downloadBlob(blob);
                } else {
                    throw new Error("Blob konnte nicht erstellt werden");
                }
            } catch (err) {
                console.error("Fehler beim PDF-Export:", err);
                alert("PDF Export fehlgeschlagen.");
            }
        } else {
            let downloadContent = this.app.state.exportData.content;
            
            if ((option === 'summary' || option === 'transcript') && format !== 'markdown' && format !== 'docx' && format !== 'pdf') {
                const previewContent = document.getElementById('export-preview-content');
                if (previewContent) {
                    downloadContent = previewContent.innerText || previewContent.textContent || "";
                }
            }
            
            const blob = new Blob([downloadContent], { type: this.app.state.exportData.type });
            downloadBlob(blob);
        }
    }

    showTemplateSelect() {
        const previewPanel = document.getElementById('export-preview-panel');
        const selectPanel = document.getElementById('export-template-select-panel');
        if (previewPanel && selectPanel) {
            previewPanel.classList.add('hidden');
            selectPanel.classList.remove('hidden');
        }
        // Ensure export tools sidebar is visible
        document.getElementById('export-tools-sidebar')?.classList.remove('hidden');

        // Reset search input
        const searchInput = document.getElementById('export-template-search-input');
        if (searchInput) {
            searchInput.value = '';
            searchInput.classList.add('hidden');
            this.filterTemplates('');
        }
        this.loadTemplates();
    }

    hideTemplateSelect() {
        const previewPanel = document.getElementById('export-preview-panel');
        const selectPanel = document.getElementById('export-template-select-panel');
        if (previewPanel && selectPanel) {
            selectPanel.classList.add('hidden');
            previewPanel.classList.remove('hidden');
        }
        // Show export tools sidebar
        document.getElementById('export-tools-sidebar')?.classList.remove('hidden');
    }

    useTemplate(templateName, templateSubtext) {
        this.app.state.selectedTemplate = templateName;
        this.app.state.selectedTemplateSubtext = templateSubtext;

        // Update active name in subheader
        const activeTmplNameEl = document.getElementById('export-active-template-name');
        if (activeTmplNameEl) {
            activeTmplNameEl.textContent = templateName;
        }

        // Update active template icon in subheader
        const activeCard = document.querySelector(`.template-select-card[data-template="${templateName}"]`);
        if (activeCard) {
            const cardIconBox = activeCard.querySelector('.template-icon-box');
            if (cardIconBox) {
                const iconContainer = document.getElementById('export-active-template-icon-container');
                if (iconContainer) {
                    iconContainer.innerHTML = cardIconBox.innerHTML;
                }
            }
        }

        // Update placeholder description
        const placeholderDescEl = document.getElementById('export-placeholder-template-desc');
        if (placeholderDescEl) {
            placeholderDescEl.textContent = `Wird nach deiner Vorlage „${templateName}“ erstellt.`;
        }

        // Update placeholder skeleton list headlines
        const skeletonList = document.querySelector('#export-placeholder-view .export-placeholder-skeleton-list');
        if (skeletonList && templateSubtext) {
            const headlines = templateSubtext.split('·').map(h => h.trim()).filter(Boolean);
            if (headlines.length > 0) {
                skeletonList.innerHTML = headlines.map((headline, idx) => {
                    let lineWidthClass = 'w-full';
                    if (idx % 3 === 1) lineWidthClass = 'w-1-2';
                    else if (idx % 3 === 2) lineWidthClass = 'w-2-3';
                    
                    return `
                        <div class="skeleton-item">
                            <span class="skeleton-label">${headline}</span>
                            <div class="skeleton-line-group">
                                <div class="skeleton-line ${lineWidthClass}"></div>
                                ${idx % 2 === 0 ? `<div class="skeleton-line ${idx % 3 === 0 ? 'w-2-3' : 'w-1-2'}"></div>` : ''}
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }

        // Update card active classes and badges in select template panel
        document.querySelectorAll('.template-select-card').forEach(card => {
            const cardTmplName = card.getAttribute('data-template');
            if (cardTmplName === templateName) {
                card.classList.add('active');
                
                // Ensure badge is present
                let badge = card.querySelector('.badge-active-pill');
                if (!badge) {
                    const titleRow = card.querySelector('.template-card-header');
                    if (titleRow) {
                        const newBadge = document.createElement('span');
                        newBadge.className = 'badge-active-pill';
                        newBadge.textContent = 'AKTIV';
                        titleRow.appendChild(newBadge);
                    }
                }
            } else {
                card.classList.remove('active');
                const badge = card.querySelector('.badge-active-pill');
                if (badge) badge.remove();
            }
        });

        this.hideTemplateSelect();
    }

    toggleSearchInput() {
        const searchInput = document.getElementById('export-template-search-input');
        if (searchInput) {
            if (searchInput.classList.contains('hidden')) {
                searchInput.classList.remove('hidden');
                searchInput.focus();
            } else {
                searchInput.classList.add('hidden');
                searchInput.value = '';
                this.filterTemplates('');
            }
        }
    }

    toggleFormatOptions() {
        const formatContainer = document.getElementById('export-format-options-footer-container');
        const chevronIcon = document.getElementById('export-footer-chevron-icon');
        if (!formatContainer) return;

        if (formatContainer.classList.contains('hidden')) {
            formatContainer.classList.remove('hidden');
            if (chevronIcon) {
                chevronIcon.classList.add('rotate-180');
            }
        } else {
            formatContainer.classList.add('hidden');
            if (chevronIcon) {
                chevronIcon.classList.remove('rotate-180');
            }
        }
    }

    showTranscriptSettings() {
        this.ensureTranscriptFormatState();
        
        document.getElementById('export-preview-panel')?.classList.add('hidden');
        document.getElementById('export-transcript-settings-panel')?.classList.remove('hidden');
        document.getElementById('export-tools-sidebar')?.classList.add('hidden');
        
        this.renderTranscriptSpeakersChips();
        this.renderTranscriptCustomTemplates();
        this.syncActiveTranscriptTemplateUI();
        this.exportToVerlauf();
    }

    hideTranscriptSettings() {
        document.getElementById('export-transcript-settings-panel')?.classList.add('hidden');
        document.getElementById('export-preview-panel')?.classList.remove('hidden');
        document.getElementById('export-tools-sidebar')?.classList.remove('hidden');
        
        this.exportToVerlauf();
    }

    toggleTranscriptAccordion() {
        const content = document.getElementById('transcript-accordion-content');
        const chevron = document.getElementById('transcript-accordion-chevron');
        if (!content) return;
        
        const isHidden = content.classList.contains('hidden');
        if (isHidden) {
            content.classList.remove('hidden');
            if (chevron) chevron.classList.add('rotate-180');
        } else {
            content.classList.add('hidden');
            if (chevron) chevron.classList.remove('rotate-180');
        }
    }

    renderTranscriptSpeakersChips() {
        this.ensureTranscriptFormatState();
        const container = document.getElementById('transcript-speakers-chips');
        if (!container) return;
        
        container.innerHTML = '';
        
        const speakers = new Map();
        let colorCounter = 1;
        
        if (this.app.state.currentTranscriptSegments) {
            this.app.state.currentTranscriptSegments.forEach(segment => {
                const name = segment.speaker || 'Unbekannt';
                if (!speakers.has(name)) {
                    speakers.set(name, this.getSpeakerColorId(name));
                }
            });
        }
        
        if (speakers.size === 0) {
            container.innerHTML = '<span class="transcript-item-desc">Keine Sprecher</span>';
            return;
        }
        
        speakers.forEach((colorId, name) => {
            const isVisible = this.app.state.transcriptFormat.visibleSpeakers[name] !== false;
            const chip = document.createElement('div');
            chip.className = `transcript-speaker-chip ${isVisible ? '' : 'disabled'}`;
            chip.innerHTML = `
                <span class="transcript-speaker-chip-dot speaker-color-${colorId}"></span>
                <span>${name}</span>
            `;
            chip.onclick = () => {
                this.app.state.transcriptFormat.visibleSpeakers[name] = !isVisible;
                this.updateTranscriptCustomFormat();
                this.renderTranscriptSpeakersChips();
            };
            container.appendChild(chip);
        });
    }

    updateTranscriptCustomFormat() {
        this.ensureTranscriptFormatState();
        const toggleSpeakers = document.getElementById('ts-toggle-speakers');
        const toggleTimestamps = document.getElementById('ts-toggle-timestamps');
        const toggleAvatars = document.getElementById('ts-toggle-avatars');
        const toggleBubbles = document.getElementById('ts-toggle-bubbles');
        const toggleAnonymize = document.getElementById('ts-toggle-anonymize');
        
        if (toggleSpeakers) this.app.state.transcriptFormat.speakers = toggleSpeakers.checked;
        if (toggleTimestamps) this.app.state.transcriptFormat.timestamps = toggleTimestamps.checked;
        if (toggleAvatars) this.app.state.transcriptFormat.avatars = toggleAvatars.checked;
        if (toggleBubbles) this.app.state.transcriptFormat.bubbles = toggleBubbles.checked;
        if (toggleAnonymize) this.app.state.transcriptFormat.anonymize = toggleAnonymize.checked;
        
        // Remove active preset highlight
        document.querySelectorAll('#export-transcript-settings-panel [data-preset]').forEach(el => {
            el.classList.remove('active');
            const badge = el.querySelector('.badge-active-pill');
            if (badge) {
                badge.classList.add('hidden');
            }
        });
        document.querySelectorAll('#export-transcript-settings-panel [data-custom-template]').forEach(el => {
            el.classList.remove('active');
            const badge = el.querySelector('.badge-active-pill');
            if (badge) {
                badge.classList.add('hidden');
            }
        });
        
        this.app.state.selectedTranscriptPreset = 'custom';
        
        const activeTmplNameEl = document.getElementById('export-active-transcript-template-name');
        if (activeTmplNameEl) {
            activeTmplNameEl.textContent = 'Benutzerdefiniert';
        }
        
        this.updateActiveTranscriptTemplateIcon();
        this.exportToVerlauf();
    }

    selectTranscriptPreset(presetId) {
        this.ensureTranscriptFormatState();
        this.app.state.editingCustomTemplateName = null;
        const preset = this.transcriptPresets[presetId];
        if (!preset) return;
        
        this.app.state.transcriptFormat.speakers = preset.speakers;
        this.app.state.transcriptFormat.timestamps = preset.timestamps;
        this.app.state.transcriptFormat.avatars = preset.avatars;
        this.app.state.transcriptFormat.bubbles = preset.bubbles !== undefined ? preset.bubbles : true;
        this.app.state.transcriptFormat.anonymize = preset.anonymize !== undefined ? preset.anonymize : false;
        this.app.state.transcriptFormat.order = preset.order;
        
        // Update checkboxes in UI
        const toggleSpeakers = document.getElementById('ts-toggle-speakers');
        const toggleTimestamps = document.getElementById('ts-toggle-timestamps');
        const toggleAvatars = document.getElementById('ts-toggle-avatars');
        const toggleBubbles = document.getElementById('ts-toggle-bubbles');
        const toggleAnonymize = document.getElementById('ts-toggle-anonymize');
        
        if (toggleSpeakers) toggleSpeakers.checked = preset.speakers;
        if (toggleTimestamps) toggleTimestamps.checked = preset.timestamps;
        if (toggleAvatars) toggleAvatars.checked = preset.avatars;
        if (toggleBubbles) toggleBubbles.checked = preset.bubbles !== undefined ? preset.bubbles : true;
        if (toggleAnonymize) toggleAnonymize.checked = preset.anonymize !== undefined ? preset.anonymize : false;
        
        // Clear name input when selecting a preset
        const inputEl = document.getElementById('ts-template-name-input');
        if (inputEl) {
            inputEl.value = '';
        }
        
        // Update segmented control active button
        const btnChrono = document.getElementById('ts-order-chronological');
        const btnSpeaker = document.getElementById('ts-order-speaker');
        if (btnChrono && btnSpeaker) {
            if (preset.order === 'chronological') {
                btnChrono.classList.add('active');
                btnSpeaker.classList.remove('active');
            } else {
                btnChrono.classList.remove('active');
                btnSpeaker.classList.add('active');
            }
        }
        
        // Highlight active preset
        document.querySelectorAll('#export-transcript-settings-panel [data-preset]').forEach(el => {
            const isCurrent = el.getAttribute('data-preset') === presetId;
            el.classList.toggle('active', isCurrent);
            const badge = el.querySelector('.badge-active-pill');
            if (badge) {
                badge.classList.toggle('hidden', !isCurrent);
            }
        });
        
        document.querySelectorAll('#export-transcript-settings-panel [data-custom-template]').forEach(el => {
            el.classList.remove('active');
            const badge = el.querySelector('.badge-active-pill');
            if (badge) {
                badge.classList.add('hidden');
            }
        });
        
        this.app.state.selectedTranscriptPreset = presetId;
        
        const activeTmplNameEl = document.getElementById('export-active-transcript-template-name');
        if (activeTmplNameEl) {
            activeTmplNameEl.textContent = preset.name;
        }
        
        this.updateActiveTranscriptTemplateIcon();
        this.exportToVerlauf();
    }

    setTranscriptOrder(order) {
        this.ensureTranscriptFormatState();
        this.app.state.transcriptFormat.order = order;
        
        const btnChrono = document.getElementById('ts-order-chronological');
        const btnSpeaker = document.getElementById('ts-order-speaker');
        if (btnChrono && btnSpeaker) {
            if (order === 'chronological') {
                btnChrono.classList.add('active');
                btnSpeaker.classList.remove('active');
            } else {
                btnChrono.classList.remove('active');
                btnSpeaker.classList.add('active');
            }
        }
        
        this.updateTranscriptCustomFormat();
    }

    saveCustomTranscriptTemplate() {
        this.ensureTranscriptFormatState();
        const inputEl = document.getElementById('ts-template-name-input');
        if (!inputEl) return;
        
        const baseName = inputEl.value.trim();
        if (!baseName) {
            if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
                window.openModal(window.ModalType.ERROR, "Bitte gib einen Vorlagennamen ein.", "Eingabe erforderlich");
            } else {
                alert("Bitte gib einen Vorlagennamen ein.");
            }
            return;
        }
        
        let originalName = this.app.state.editingCustomTemplateName;
        
        let finalName = baseName;
        let counter = 1;
        while (this.app.state.customTranscriptTemplates.some(t => t.name.toLowerCase() === finalName.toLowerCase() && t.name !== originalName)) {
            finalName = `${baseName} (${counter})`;
            counter++;
        }
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        let existingId = null;
        if (originalName) {
            const existing = this.app.state.customTranscriptTemplates.find(t => t.name === originalName);
            if (existing) {
                existingId = existing.id;
            }
        }

        const payload = {
            id: existingId,
            name: finalName,
            speakers: this.app.state.transcriptFormat.speakers,
            timestamps: this.app.state.transcriptFormat.timestamps,
            avatars: this.app.state.transcriptFormat.avatars,
            bubbles: this.app.state.transcriptFormat.bubbles,
            anonymize: this.app.state.transcriptFormat.anonymize,
            order: this.app.state.transcriptFormat.order
        };

        fetch('/req/transcription/formats', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success && data.format) {
                this.app.state.editingCustomTemplateName = finalName;
                this.loadCustomFormats();
                this.selectCustomTranscriptTemplate(finalName);
            } else {
                alert("Fehler beim Speichern der Vorlage: " + (data.error || "Unbekannter Fehler"));
            }
        })
        .catch(err => {
            console.error("Fehler beim Speichern des Formats:", err);
            alert("Verbindungsfehler beim Speichern.");
        });
    }

    renderTranscriptCustomTemplates() {
        this.ensureTranscriptFormatState();
        const container = document.getElementById('transcript-custom-templates-list');
        if (!container) return;
        
        container.innerHTML = '';
        
        const customSection = document.getElementById('ts-settings-custom-section');
        if (this.app.state.customTranscriptTemplates.length === 0) {
            if (customSection) customSection.classList.add('hidden');
            return;
        }
        
        if (customSection) customSection.classList.remove('hidden');
        
        this.app.state.customTranscriptTemplates.forEach(tmpl => {
            const isCurrent = this.app.state.selectedTranscriptPreset === `custom_${tmpl.name}`;
            const card = document.createElement('div');
            card.className = `template-select-card ${isCurrent ? 'active' : ''}`;
            card.setAttribute('data-custom-template', tmpl.name);
            
            // Build the subtitle details
            const details = [];
            if (tmpl.speakers) details.push('Namen');
            if (tmpl.timestamps) details.push('Zeitstempel');
            if (tmpl.avatars) details.push('Avatare');
            if (tmpl.bubbles !== false) details.push('Blasen');
            if (tmpl.anonymize) details.push('Anonymisiert');
            details.push(tmpl.order === 'chronological' ? 'chronologisch' : 'nach Sprecher');
            
            card.innerHTML = `
                <div class="template-card-header" onclick="window.app.exportManager.selectCustomTranscriptTemplate('${tmpl.name}')">
                    <div class="template-header-left-inner">
                        <div class="template-icon-box">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bookmark"><path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/></svg>
                        </div>
                        <h4 class="template-title">${tmpl.name}</h4>
                    </div>
                    <span class="badge-active-pill ${isCurrent ? '' : 'hidden'}">AKTIV</span>
                </div>
                <p class="template-desc" onclick="window.app.exportManager.selectCustomTranscriptTemplate('${tmpl.name}')">${details.join(' · ')}</p>
                <div class="template-actions">
                    <span class="template-action-link use" onclick="window.app.exportManager.selectCustomTranscriptTemplate('${tmpl.name}')">Verwenden</span>
                    <span class="template-action-link delete" onclick="event.stopPropagation(); window.app.exportManager.deleteCustomTranscriptTemplate('${tmpl.name}')">Löschen</span>
                </div>
            `;
            container.appendChild(card);
        });
    }

    selectCustomTranscriptTemplate(tmplName) {
        this.ensureTranscriptFormatState();
        const tmpl = this.app.state.customTranscriptTemplates.find(t => t.name === tmplName);
        if (!tmpl) return;
        
        this.app.state.editingCustomTemplateName = tmplName;
        
        this.app.state.transcriptFormat.speakers = tmpl.speakers;
        this.app.state.transcriptFormat.timestamps = tmpl.timestamps;
        this.app.state.transcriptFormat.avatars = tmpl.avatars;
        this.app.state.transcriptFormat.bubbles = tmpl.bubbles !== undefined ? tmpl.bubbles : true;
        this.app.state.transcriptFormat.anonymize = tmpl.anonymize !== undefined ? tmpl.anonymize : false;
        this.app.state.transcriptFormat.order = tmpl.order;
        
        // Update checkboxes in UI
        const toggleSpeakers = document.getElementById('ts-toggle-speakers');
        const toggleTimestamps = document.getElementById('ts-toggle-timestamps');
        const toggleAvatars = document.getElementById('ts-toggle-avatars');
        const toggleBubbles = document.getElementById('ts-toggle-bubbles');
        const toggleAnonymize = document.getElementById('ts-toggle-anonymize');
        
        if (toggleSpeakers) toggleSpeakers.checked = tmpl.speakers;
        if (toggleTimestamps) toggleTimestamps.checked = tmpl.timestamps;
        if (toggleAvatars) toggleAvatars.checked = tmpl.avatars;
        if (toggleBubbles) toggleBubbles.checked = tmpl.bubbles !== undefined ? tmpl.bubbles : true;
        if (toggleAnonymize) toggleAnonymize.checked = tmpl.anonymize !== undefined ? tmpl.anonymize : false;
        
        // Pre-fill name input for editing
        const inputEl = document.getElementById('ts-template-name-input');
        if (inputEl) {
            inputEl.value = tmpl.name;
        }
        
        // Update segmented control active button
        const btnChrono = document.getElementById('ts-order-chronological');
        const btnSpeaker = document.getElementById('ts-order-speaker');
        if (btnChrono && btnSpeaker) {
            if (tmpl.order === 'chronological') {
                btnChrono.classList.add('active');
                btnSpeaker.classList.remove('active');
            } else {
                btnChrono.classList.remove('active');
                btnSpeaker.classList.add('active');
            }
        }
        
        // Highlight active template
        document.querySelectorAll('#export-transcript-settings-panel [data-preset]').forEach(el => {
            el.classList.remove('active');
            const badge = el.querySelector('.badge-active-pill');
            if (badge) {
                badge.classList.add('hidden');
            }
        });
        
        document.querySelectorAll('#export-transcript-settings-panel [data-custom-template]').forEach(el => {
            const isCurrent = el.getAttribute('data-custom-template') === tmplName;
            el.classList.toggle('active', isCurrent);
            const badge = el.querySelector('.badge-active-pill');
            if (badge) {
                badge.classList.toggle('hidden', !isCurrent);
            }
        });
        
        this.app.state.selectedTranscriptPreset = `custom_${tmplName}`;
        
        const activeTmplNameEl = document.getElementById('export-active-transcript-template-name');
        if (activeTmplNameEl) {
            activeTmplNameEl.textContent = tmplName;
        }
        
        this.updateActiveTranscriptTemplateIcon();
        this.exportToVerlauf();
    }

    deleteCustomTranscriptTemplate(tmplName) {
        const tmpl = this.app.state.customTranscriptTemplates.find(t => t.name === tmplName);
        if (!tmpl) return;

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        fetch(`/req/transcription/formats/${tmpl.id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                this.loadCustomFormats();
                if (this.app.state.selectedTranscriptPreset === `custom_${tmplName}`) {
                    this.selectTranscriptPreset('dialog_standard');
                }
            } else {
                alert("Fehler beim Löschen der Vorlage: " + (data.error || "Unbekannter Fehler"));
            }
        })
        .catch(err => {
            console.error("Fehler beim Löschen des Formats:", err);
            alert("Verbindungsfehler beim Löschen.");
        });
    }

    filterTemplates(query) {
        const lowerQuery = query.toLowerCase().trim();
        document.querySelectorAll('.template-select-card').forEach(card => {
            if (card.classList.contains('new-template-card')) return; // Keep new template card visible
            const name = (card.getAttribute('data-template') || '').toLowerCase();
            const desc = (card.getAttribute('data-subtext') || '').toLowerCase();
            if (name.includes(lowerQuery) || desc.includes(lowerQuery)) {
                card.classList.remove('hidden');
            } else {
                card.classList.add('hidden');
            }
        });
    }

    loadPreviewCache() {
        try {
            const cached = localStorage.getItem('hawki_template_preview_cache');
            this.previewCache = cached ? JSON.parse(cached) : {};
        } catch (e) {
            this.previewCache = {};
        }
    }

    savePreviewCache() {
        try {
            localStorage.setItem('hawki_template_preview_cache', JSON.stringify(this.previewCache));
        } catch (e) {
            console.error(e);
        }
    }

    loadTemplates() {
        fetch('/req/transcription/templates')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.templates) {
                this.templates = data.templates;
                this.renderTemplatesList();
            }
        })
        .catch(err => {
            console.error("Fehler beim Laden der Vorlagen:", err);
        });
    }

    loadCustomFormats() {
        fetch('/req/transcription/formats')
        .then(res => res.json())
        .then(data => {
            if (data.success && data.formats) {
                this.app.state.customTranscriptTemplates = data.formats;
                this.renderTranscriptCustomTemplates();
                this.migrateLocalStorageFormats();
            }
        })
        .catch(err => {
            console.error("Fehler beim Laden der benutzerdefinierten Formate:", err);
            if (!Array.isArray(this.app.state.customTranscriptTemplates)) {
                try {
                    this.app.state.customTranscriptTemplates = JSON.parse(localStorage.getItem('customTranscriptTemplates') || '[]');
                } catch (e) {
                    this.app.state.customTranscriptTemplates = [];
                }
                this.renderTranscriptCustomTemplates();
            }
        });
    }

    migrateLocalStorageFormats() {
        let localFormats = [];
        try {
            localFormats = JSON.parse(localStorage.getItem('customTranscriptTemplates') || '[]');
        } catch (e) {
            return;
        }

        if (localFormats.length === 0) return;

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        const promises = localFormats.map(tmpl => {
            return fetch('/req/transcription/formats', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    name: tmpl.name,
                    speakers: tmpl.speakers || false,
                    timestamps: tmpl.timestamps || false,
                    avatars: tmpl.avatars || false,
                    bubbles: tmpl.bubbles !== false,
                    anonymize: tmpl.anonymize || false,
                    order: tmpl.order || 'chronological'
                })
            }).then(res => res.json());
        });

        Promise.all(promises)
        .then(() => {
            localStorage.removeItem('customTranscriptTemplates');
            fetch('/req/transcription/formats')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.formats) {
                    this.app.state.customTranscriptTemplates = data.formats;
                    this.renderTranscriptCustomTemplates();
                }
            });
        })
        .catch(err => {
            console.error("Fehler bei der Migration der lokalen Formate:", err);
        });
    }

    renderTemplatesList() {
        const userGrid = document.getElementById('user-templates-grid');
        const libGrid = document.getElementById('library-templates-grid');
        if (!userGrid || !libGrid) return;
        userGrid.innerHTML = '';
        libGrid.innerHTML = '';

        this.templates.forEach(tmpl => {
            const subtext = tmpl.structure
                .filter(b => b.type === 'section')
                .map(b => b.heading)
                .join(' · ');

            const isUserTemplate = tmpl.user_id !== null;
            
            const card = document.createElement('div');
            card.className = `template-select-card${this.app.state.selectedTemplate === tmpl.name ? ' active' : ''}`;
            card.setAttribute('data-template', tmpl.name);
            card.setAttribute('data-subtext', subtext);

            let badgeHtml = '';
            if (this.app.state.selectedTemplate === tmpl.name) {
                badgeHtml = '<span class="badge-active-pill">AKTIV</span>';
            }

            let actionLinksHtml = '';
            if (isUserTemplate) {
                actionLinksHtml = `
                    <span class="template-action-link use" onclick="window.app.exportManager.useTemplate('${tmpl.name}', '${subtext}')">Verwenden</span>
                    <span class="template-action-link edit" onclick="window.app.exportManager.openTemplateEditor(${tmpl.id})">Bearbeiten</span>
                    <span class="template-action-link delete" onclick="window.app.exportManager.deleteTemplate(${tmpl.id})">Löschen</span>
                `;
            } else {
                actionLinksHtml = `
                    <span class="template-action-link use" onclick="window.app.exportManager.useTemplate('${tmpl.name}', '${subtext}')">Verwenden</span>
                    <span class="template-action-link edit" onclick="window.app.exportManager.openTemplateEditor(${tmpl.id}, true)">Anpassen</span>
                `;
            }

            card.innerHTML = `
                <div class="template-card-header">
                    <div class="template-header-left-inner">
                        <div class="template-icon-box">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-user-icon lucide-file-user"><path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M16 22a4 4 0 0 0-8 0"/><circle cx="12" cy="15" r="3"/></svg>
                        </div>
                        <h4 class="template-title">${tmpl.name}</h4>
                    </div>
                    ${badgeHtml}
                </div>
                <p class="template-desc">${subtext}</p>
                <div class="template-actions">
                    ${actionLinksHtml}
                </div>
            `;

            if (tmpl.user_id !== null) {
                userGrid.appendChild(card);
            } else {
                libGrid.appendChild(card);
            }
        });

        // Append "Neue Vorlage" card to User grid
        const newCard = document.createElement('div');
        newCard.className = 'template-select-card new-template-card';
        newCard.onclick = () => this.openTemplateEditor(null);
        newCard.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="new-template-card-icon"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            <h4 class="new-template-card-title">Neue Vorlage</h4>
            <p class="new-template-card-desc">Leer beginnen</p>
        `;
        userGrid.appendChild(newCard);
    }

    async deleteTemplate(id) {
        if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
            const confirmed = await window.openModal(window.ModalType.WARNING, "Möchtest du diese Vorlage wirklich löschen?");
            if (!confirmed) return;
        } else if (!confirm("Möchtest du diese Vorlage wirklich löschen?")) {
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        fetch(`/req/transcription/templates/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrfToken
            }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                this.loadTemplates();
            } else {
                if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
                    window.openModal(window.ModalType.ERROR, "Fehler beim Löschen: " + (data.error || "Unbekannt"), "Fehler");
                } else {
                    alert("Fehler beim Löschen: " + (data.error || "Unbekannt"));
                }
            }
        })
        .catch(err => {
            console.error(err);
            if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
                window.openModal(window.ModalType.ERROR, "Verbindungsfehler.", "Fehler");
            } else {
                alert("Verbindungsfehler.");
            }
        });
    }

    openTemplateEditor(templateId = null, isRemix = false) {
        let template = null;
        if (templateId !== null) {
            template = this.templates.find(t => t.id === templateId);
        }

        if (template) {
            this.editorTemplate = {
                id: isRemix ? null : template.id,
                name: isRemix ? `${template.name} (Kopie)` : template.name,
                structure: JSON.parse(JSON.stringify(template.structure))
            };
        } else {
            this.editorTemplate = {
                id: null,
                name: 'Meine neue Vorlage',
                structure: [
                    { type: 'heading', level: 1, text: '{{titel}}' },
                    { type: 'text', text: 'Datum: {{datum}} · {{teilnehmer}}' },
                    { type: 'section', heading: 'Zusammenfassung', instruction: 'Fasse das Gespräch in 3-4 Sätzen zusammen' }
                ]
            };
        }

        document.getElementById('export-template-select-panel')?.classList.add('hidden');
        document.getElementById('export-template-editor-panel')?.classList.remove('hidden');
        document.getElementById('export-tools-sidebar')?.classList.add('hidden');

        const nameInput = document.getElementById('editor-template-name');
        if (nameInput) {
            nameInput.value = this.editorTemplate.name;
            const adjustWidth = () => {
                const tempSpan = document.createElement('span');
                tempSpan.className = 'measure-span-hidden';
                const computed = window.getComputedStyle(nameInput);
                tempSpan.style.fontSize = computed.fontSize;
                tempSpan.style.fontWeight = computed.fontWeight;
                tempSpan.style.fontFamily = computed.fontFamily;
                tempSpan.style.letterSpacing = computed.letterSpacing;
                tempSpan.style.textTransform = computed.textTransform;
                tempSpan.textContent = nameInput.value || nameInput.placeholder || '';
                document.body.appendChild(tempSpan);
                nameInput.style.width = (tempSpan.getBoundingClientRect().width + 12) + 'px';
                document.body.removeChild(tempSpan);
            };
            // Run initially once loaded or inside a requestAnimationFrame to ensure fonts are fully styled
            requestAnimationFrame(() => adjustWidth());
            nameInput.oninput = (e) => {
                this.editorTemplate.name = e.target.value;
                adjustWidth();
            };
            nameInput.onfocus = () => {
                setTimeout(adjustWidth, 50);
            };
            nameInput.onblur = () => {
                setTimeout(adjustWidth, 50);
            };
        }

        this.lastFocusedInput = null;
        this.renderEditorBlocks();
        this.renderEditorPreview();
    }

    renderEditorBlocks() {
        const list = document.getElementById('editor-blocks-list');
        if (!list) return;
        list.innerHTML = '';

        this.editorTemplate.structure.forEach((block, idx) => {
            const card = document.createElement('div');
            card.className = 'editor-block-card';
            card.className = 'editor-block-card';

            const controlsHtml = `
                <div class="block-controls">
                    <button class="btn-block-ctrl" onclick="window.app.exportManager.moveBlock(${idx}, -1)" title="Nach oben verschieben">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>
                    </button>
                    <button class="btn-block-ctrl" onclick="window.app.exportManager.moveBlock(${idx}, 1)" title="Nach unten verschieben">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                </div>
            `;

            if (block.type === 'heading' || block.type === 'text') {
                const isHeading = block.type === 'heading';
                card.innerHTML = `
                    ${controlsHtml}
                    <div class="block-card-type-header ${isHeading ? 'heading' : 'text'}">
                        <span class="block-type-dot blue"></span>
                        ${isHeading ? 'Überschrift' : 'Textabschnitt'}
                    </div>
                    <div class="block-card-body-row">
                        ${isHeading ? `
                            <select class="block-heading-level" onchange="window.app.exportManager.updateBlockLevel(${idx}, this.value)">
                                <option value="1" ${block.level === 1 ? 'selected' : ''}>H1 (#)</option>
                                <option value="2" ${block.level === 2 ? 'selected' : ''}>H2 (##)</option>
                                <option value="3" ${block.level === 3 ? 'selected' : ''}>H3 (###)</option>
                            </select>
                        ` : ''}
                        <input type="text" class="block-text-input" value="${block.text || ''}" onfocus="window.app.exportManager.setFocusedInput(this, ${idx}, 'text')" oninput="window.app.exportManager.updateBlockText(${idx}, this.value)" placeholder="${isHeading ? 'Überschriftstext' : 'Text eingeben'}">
                    </div>
                `;
            } else if (block.type === 'section') {
                card.innerHTML = `
                    ${controlsHtml}
                    <div class="block-card-type-header section">
                        <span class="block-type-dot purple"></span>
                        KI-Abschnitt (Generiert)
                    </div>
                    <div class="block-card-body-column">
                        <input type="text" class="block-section-heading" value="${block.heading || ''}" onfocus="window.app.exportManager.setFocusedInput(this, ${idx}, 'heading')" oninput="window.app.exportManager.updateSectionHeading(${idx}, this.value)" placeholder="Abschnittsname (z.B. Zusammenfassung)">
                        <textarea class="block-section-instruction" onfocus="window.app.exportManager.setFocusedInput(this, ${idx}, 'instruction')" oninput="window.app.exportManager.updateSectionInstruction(${idx}, this.value)" placeholder="Anweisung für die KI (z.B. Fasse das Gespräch zusammen)">${block.instruction || ''}</textarea>
                    </div>
                `;
            } else if (block.type === 'divider') {
                card.innerHTML = `
                    ${controlsHtml}
                    <div class="block-card-type-header divider">
                        <span class="block-type-dot gray"></span>
                        Trennlinie
                    </div>
                    <div class="block-card-body-row">
                        <hr class="block-divider-hr">
                    </div>
                `;
            }

            // Drag handle positioned inside controls at top right
            const handle = document.createElement('button');
            handle.type = 'button';
            handle.className = 'btn-block-ctrl drag-handle';
            handle.title = 'Ziehen zum Verschieben';
            handle.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-grip-horizontal"><circle cx="12" cy="9" r="1"/><circle cx="19" cy="9" r="1"/><circle cx="5" cy="9" r="1"/><circle cx="12" cy="15" r="1"/><circle cx="19" cy="15" r="1"/><circle cx="5" cy="15" r="1"/></svg>
            `;

            handle.addEventListener('mousedown', () => {
                card.setAttribute('draggable', 'true');
            });
            handle.addEventListener('mouseup', () => {
                card.setAttribute('draggable', 'false');
            });
            handle.addEventListener('mouseleave', () => {
                card.setAttribute('draggable', 'false');
            });

            const controlsContainer = card.querySelector('.block-controls');
            if (controlsContainer) {
                controlsContainer.appendChild(handle);
            }

            // Delete button positioned at bottom right
            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'btn-block-ctrl-delete-bottom';
            deleteBtn.title = 'Löschen';
            deleteBtn.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            `;
            deleteBtn.onclick = () => window.app.exportManager.deleteBlock(idx);
            card.appendChild(deleteBtn);

            // Drag & Drop events
            card.addEventListener('dragstart', (e) => {
                e.dataTransfer.setData('text/plain', idx);
                card.classList.add('drag-dragging');
            });

            card.addEventListener('dragend', () => {
                card.classList.remove('drag-dragging');
                this.renderEditorBlocks();
            });

            card.addEventListener('dragover', (e) => {
                e.preventDefault();
                const rect = card.getBoundingClientRect();
                const relativeY = e.clientY - rect.top;
                const isBottomHalf = relativeY > rect.height / 2;
                
                if (isBottomHalf) {
                    card.classList.remove('drag-over-top');
                    card.classList.add('drag-over-bottom');
                } else {
                    card.classList.add('drag-over-top');
                    card.classList.remove('drag-over-bottom');
                }
            });

            card.addEventListener('dragleave', () => {
                card.classList.remove('drag-over-top', 'drag-over-bottom');
            });

            card.addEventListener('drop', (e) => {
                e.preventDefault();
                card.classList.remove('drag-over-top', 'drag-over-bottom');
                
                const fromIdx = parseInt(e.dataTransfer.getData('text/plain'));
                if (isNaN(fromIdx)) return;
                
                const rect = card.getBoundingClientRect();
                const relativeY = e.clientY - rect.top;
                const isBottomHalf = relativeY > rect.height / 2;
                
                let toIdx = idx;
                if (isBottomHalf) {
                    toIdx = idx + 1;
                }
                
                if (fromIdx === toIdx || fromIdx === toIdx - 1) return;
                
                const blockObj = this.editorTemplate.structure.splice(fromIdx, 1)[0];
                let insertIdx = toIdx;
                if (fromIdx < toIdx) {
                    insertIdx = toIdx - 1;
                }
                
                this.editorTemplate.structure.splice(insertIdx, 0, blockObj);
                
                this.renderEditorBlocks();
                this.renderEditorPreview();
            });

            list.appendChild(card);
        });
    }

    setFocusedInput(el, idx, field) {
        this.lastFocusedInput = { el, idx, field };
    }

    updateBlockText(idx, val) {
        if (this.editorTemplate.structure[idx]) {
            this.editorTemplate.structure[idx].text = val;
            this.renderEditorPreview();
        }
    }

    updateBlockLevel(idx, val) {
        if (this.editorTemplate.structure[idx]) {
            this.editorTemplate.structure[idx].level = parseInt(val);
            this.renderEditorPreview();
        }
    }

    updateSectionHeading(idx, val) {
        if (this.editorTemplate.structure[idx]) {
            this.editorTemplate.structure[idx].heading = val;
            this.renderEditorPreview();
        }
    }

    updateSectionInstruction(idx, val) {
        if (this.editorTemplate.structure[idx]) {
            this.editorTemplate.structure[idx].instruction = val;
            this.renderEditorPreview();
        }
    }

    addNewSectionBlock(heading = 'Zusammenfassung', instruction = 'Fasse das Gespräch in 3-4 Sätzen zusammen') {
        this.editorTemplate.structure.push({
            type: 'section',
            heading: heading,
            instruction: instruction
        });
        this.renderEditorBlocks();
        this.renderEditorPreview();
    }

    addNewHeadingBlock() {
        this.editorTemplate.structure.push({
            type: 'heading',
            level: 2,
            text: 'Neue Überschrift'
        });
        this.renderEditorBlocks();
        this.renderEditorPreview();
    }

    addNewTextBlock() {
        this.editorTemplate.structure.push({
            type: 'text',
            text: 'Neuer Text'
        });
        this.renderEditorBlocks();
        this.renderEditorPreview();
    }

    addNewDividerBlock() {
        this.editorTemplate.structure.push({
            type: 'divider'
        });
        this.renderEditorBlocks();
        this.renderEditorPreview();
    }

    deleteBlock(idx) {
        this.editorTemplate.structure.splice(idx, 1);
        this.renderEditorBlocks();
        this.renderEditorPreview();
    }

    moveBlock(idx, direction) {
        const targetIdx = idx + direction;
        if (targetIdx < 0 || targetIdx >= this.editorTemplate.structure.length) return;
        const temp = this.editorTemplate.structure[idx];
        this.editorTemplate.structure[idx] = this.editorTemplate.structure[targetIdx];
        this.editorTemplate.structure[targetIdx] = temp;
        this.renderEditorBlocks();
        this.renderEditorPreview();
    }

    insertPlaceholder(placeholder) {
        if (this.lastFocusedInput && this.lastFocusedInput.el) {
            const el = this.lastFocusedInput.el;
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const text = el.value;
            const before = text.substring(0, start);
            const after = text.substring(end, text.length);
            el.value = before + placeholder + after;
            el.selectionStart = el.selectionEnd = start + placeholder.length;
            el.focus();
            
            if (this.lastFocusedInput.field === 'text') {
                this.updateBlockText(this.lastFocusedInput.idx, el.value);
            } else if (this.lastFocusedInput.field === 'heading') {
                this.updateSectionHeading(this.lastFocusedInput.idx, el.value);
            } else if (this.lastFocusedInput.field === 'instruction') {
                this.updateSectionInstruction(this.lastFocusedInput.idx, el.value);
            }
        } else {
            this.editorTemplate.structure.push({ type: 'text', text: placeholder });
            this.renderEditorBlocks();
            this.renderEditorPreview();
        }
    }

    closeTemplateEditor() {
        document.getElementById('export-template-editor-panel')?.classList.add('hidden');
        document.getElementById('export-template-select-panel')?.classList.remove('hidden');
        // Show export tools sidebar again
        document.getElementById('export-tools-sidebar')?.classList.remove('hidden');
    }

    saveTemplate() {
        const name = (this.editorTemplate.name || '').trim();
        if (!name) {
            if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
                window.openModal(window.ModalType.WARNING, "Bitte einen Vorlagennamen eingeben.", "Eingabe erforderlich");
            } else {
                alert("Bitte einen Vorlagennamen eingeben.");
            }
            return;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        fetch('/req/transcription/templates', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                id: this.editorTemplate.id,
                name: name,
                structure: this.editorTemplate.structure
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                this.app.state.selectedTemplate = data.template.name;
                const subtext = data.template.structure
                    .filter(b => b.type === 'section')
                    .map(b => b.heading)
                    .join(' · ');
                this.app.state.selectedTemplateSubtext = subtext;
                
                this.loadTemplates();
                this.closeTemplateEditor();
                
                const activeTmplNameEl = document.getElementById('export-active-template-name');
                if (activeTmplNameEl) {
                    activeTmplNameEl.textContent = data.template.name;
                }
                const placeholderDescEl = document.getElementById('export-placeholder-template-desc');
                if (placeholderDescEl) {
                    placeholderDescEl.textContent = `Wird nach deiner Vorlage „${data.template.name}“ erstellt.`;
                }
            } else {
                if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
                    window.openModal(window.ModalType.ERROR, "Fehler beim Speichern: " + (data.error || "Unbekannt"), "Fehler");
                } else {
                    alert("Fehler beim Speichern: " + (data.error || "Unbekannt"));
                }
            }
        })
        .catch(err => {
            console.error(err);
            if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
                window.openModal(window.ModalType.ERROR, "Verbindungsfehler beim Speichern.", "Fehler");
            } else {
                alert("Verbindungsfehler beim Speichern.");
            }
        });
    }

    getStringHash(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            hash = (hash << 5) - hash + str.charCodeAt(i);
            hash |= 0;
        }
        return hash.toString();
    }

    getPlaceholderValues() {
        const title = document.getElementById('current-transcript-title')?.textContent || 'Interview zu Innovation & Musiktechnologie';
        
        let date = new Date().toLocaleDateString('de-DE');
        const subtitleEl = document.querySelector('.transcript-subtitle');
        if (subtitleEl) {
            const dateMatch = subtitleEl.textContent.match(/\d{2}\.\d{2}\.\d{4}/);
            if (dateMatch) date = dateMatch[0];
        }

        const speakers = new Set();
        if (this.app.state.currentTranscriptSegments) {
            this.app.state.currentTranscriptSegments.forEach(s => {
                if (s.speaker) speakers.add(s.speaker);
            });
        }
        const participants = speakers.size > 0 ? Array.from(speakers).join(', ') : 'Sten, Soria, Nadia';

        const durationVal = this.app.state.currentTranscriptDuration || 2700;
        const durationMin = Math.round(durationVal / 60);
        const duration = `${durationMin} Min`;

        return {
            '{{titel}}': title,
            '{{title}}': title,
            '{{datum}}': date,
            '{{date}}': date,
            '{{teilnehmer}}': participants,
            '{{participants}}': participants,
            '{{dauer}}': duration,
            '{{duration}}': duration
        };
    }

    renderEditorPreview() {
        const renderContainer = document.getElementById('editor-preview-render');
        if (!renderContainer) return;
        
        const scrollTop = renderContainer.scrollTop;
        renderContainer.innerHTML = '';
        const placeholders = this.getPlaceholderValues();

        const replacePlaceholders = (str) => {
            let res = str;
            for (const [key, value] of Object.entries(placeholders)) {
                res = res.replaceAll(key, value);
            }
            return res;
        };

        const slug = this.app.state.currentTranscriptSlug || 'preview-default';
        const templateName = this.editorTemplate.name || 'default';

        if (!this.previewCache[slug]) this.previewCache[slug] = {};
        if (!this.previewCache[slug][templateName]) this.previewCache[slug][templateName] = {};

        const wrapper = document.createElement('div');
        wrapper.className = 'export-markdown-preview';

        let hasStaleSections = false;

        this.editorTemplate.structure.forEach((block, idx) => {
            if (block.type === 'heading') {
                const text = replacePlaceholders(block.text || '');
                const lvl = block.level || 1;
                const h = document.createElement(`h${lvl}`);
                h.className = `preview-heading-lvl-${lvl}`;
                h.textContent = text;
                wrapper.appendChild(h);
            } else if (block.type === 'text') {
                const text = replacePlaceholders(block.text || '');
                const contentDiv = document.createElement('div');
                contentDiv.className = 'preview-text-block';
                
                let renderedHtml = text;
                if (window.md && typeof window.md.render === 'function') {
                    renderedHtml = window.md.render(text);
                } else {
                    renderedHtml = text.replaceAll('\n', '<br>');
                }
                contentDiv.innerHTML = renderedHtml;
                wrapper.appendChild(contentDiv);
            } else if (block.type === 'divider') {
                const hr = document.createElement('hr');
                hr.className = 'preview-divider-hr';
                wrapper.appendChild(hr);
            } else if (block.type === 'section') {
                const headingText = replacePlaceholders(block.heading || 'Abschnitt');
                const instructionText = block.instruction || '';
                const currentHash = this.getStringHash(instructionText);

                const cached = this.previewCache[slug][templateName][block.heading];
                let status = 'empty';
                let output = '';

                if (cached) {
                    output = cached.output || '';
                    if (cached.instruction_hash === currentHash) {
                        status = 'fresh';
                    } else {
                        status = 'stale';
                        hasStaleSections = true;
                    }
                } else {
                    hasStaleSections = true;
                }

                if (block.generating) {
                    status = 'generating';
                }

                const sectionDiv = document.createElement('div');
                sectionDiv.className = `preview-section-container preview-status-${status}`;

                const header = document.createElement('div');
                header.className = 'preview-section-header-flex';

                const heading = document.createElement('h4');
                heading.className = 'preview-section-heading-h4';
                heading.innerHTML = `${headingText} <span class="refresh-sec-icon" onclick="window.app.exportManager.refreshSection('${block.heading}')"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg></span>`;
                header.appendChild(heading);

                if (status === 'stale') {
                    const badgeContainer = document.createElement('div');
                    badgeContainer.className = 'badge-container-flex';

                    const badge = document.createElement('span');
                    badge.className = 'badge-stale-instruction';
                    badge.textContent = 'Anweisung geändert';

                    const refreshLink = document.createElement('span');
                    refreshLink.className = 'refresh-link-inline';
                    refreshLink.textContent = 'Aktualisieren';
                    refreshLink.onclick = () => this.refreshSection(block.heading);

                    badgeContainer.appendChild(badge);
                    badgeContainer.appendChild(refreshLink);
                    header.appendChild(badgeContainer);
                }

                sectionDiv.appendChild(header);

                const contentBody = document.createElement('div');
                contentBody.className = `section-preview-body ${status === 'stale' ? 'stale' : ''} ${status === 'empty' ? 'empty' : ''}`.trim();
                
                if (status === 'empty') {
                    contentBody.innerHTML = `Noch kein KI-Inhalt generiert. Klicke oben auf „Vorschau testen“.`;
                } else if (status === 'generating') {
                    contentBody.innerHTML = `
                        <div class="skeleton-line active w-full"></div>
                        <div class="skeleton-line active" style="width: 66%"></div>
                    `;
                } else {
                    let renderedHtml = output;
                    if (window.md && typeof window.md.render === 'function') {
                        renderedHtml = window.md.render(output);
                    } else {
                        renderedHtml = output.replaceAll('\n', '<br>');
                    }
                    contentBody.innerHTML = renderedHtml;
                }

                sectionDiv.appendChild(contentBody);
                wrapper.appendChild(sectionDiv);
            }
        });

        renderContainer.appendChild(wrapper);
        renderContainer.scrollTop = scrollTop;

        const testPreviewBtn = document.getElementById('editor-btn-test-preview');
        if (testPreviewBtn) {
            if (hasStaleSections) {
                testPreviewBtn.disabled = false;
            } else {
                testPreviewBtn.disabled = true;
            }
        }
    }

    testPreview() {
        const slug = this.app.state.currentTranscriptSlug;
        if (!slug) {
            alert("Kein Transkript geladen.");
            return;
        }

        const templateName = this.editorTemplate.name || 'default';

        const staleSections = [];
        this.editorTemplate.structure.forEach(block => {
            if (block.type === 'section') {
                const currentHash = this.getStringHash(block.instruction || '');
                const cached = this.previewCache[slug]?.[templateName]?.[block.heading];
                
                if (!cached || cached.instruction_hash !== currentHash) {
                    staleSections.push(block.heading);
                    block.generating = true;
                }
            }
        });

        if (staleSections.length === 0) {
            return;
        }

        this.renderEditorPreview();

        const testBtn = document.getElementById('editor-btn-test-preview');
        let originalBtnHtml = '';
        if (testBtn) {
            originalBtnHtml = testBtn.innerHTML;
            testBtn.innerHTML = `<span class="loader-spinner"></span> Generiere...`;
            testBtn.disabled = true;
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const sectionsData = this.editorTemplate.structure.filter(b => b.type === 'section');

        const modelSelect = document.getElementById('ergebnis-model-select');
        const model = modelSelect ? modelSelect.value : null;

        fetch('/req/transcription/summarize', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                transcription_slug: slug,
                sections: sectionsData,
                stale_headings: staleSections,
                preview: true,
                ...(model && { model: model })
            })
        })
        .then(res => {
            if (!res.ok) throw new Error("HTTP-Fehler beim Generieren");
            return res.json();
        })
        .then(data => {
            this.editorTemplate.structure.forEach(block => {
                if (block.type === 'section') block.generating = false;
            });

            if (data.success && data.results) {
                if (!this.previewCache[slug]) this.previewCache[slug] = {};
                if (!this.previewCache[slug][templateName]) this.previewCache[slug][templateName] = {};

                for (const [heading, output] of Object.entries(data.results)) {
                    const block = this.editorTemplate.structure.find(b => b.type === 'section' && b.heading === heading);
                    const inst = block ? block.instruction : '';
                    this.previewCache[slug][templateName][heading] = {
                        instruction_hash: this.getStringHash(inst),
                        output: output
                    };
                }

                this.savePreviewCache();
            } else {
                alert("Generierung fehlgeschlagen: " + (data.error || "Fehler"));
            }

            if (testBtn) {
                testBtn.innerHTML = originalBtnHtml;
                testBtn.disabled = false;
            }
            this.renderEditorPreview();
        })
        .catch(err => {
            console.error(err);
            this.editorTemplate.structure.forEach(block => {
                if (block.type === 'section') block.generating = false;
            });
            if (testBtn) {
                testBtn.innerHTML = originalBtnHtml;
                testBtn.disabled = false;
            }
            alert("Verbindungsfehler beim Generieren der Vorschau.");
            this.renderEditorPreview();
        });
    }

    refreshSection(heading) {
        const slug = this.app.state.currentTranscriptSlug;
        if (!slug) return;

        const templateName = this.editorTemplate.name || 'default';

        const block = this.editorTemplate.structure.find(b => b.type === 'section' && b.heading === heading);
        if (!block) return;
        block.generating = true;
        this.renderEditorPreview();

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const sectionsData = this.editorTemplate.structure.filter(b => b.type === 'section');

        const modelSelect = document.getElementById('ergebnis-model-select');
        const model = modelSelect ? modelSelect.value : null;

        fetch('/req/transcription/summarize', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                transcription_slug: slug,
                sections: sectionsData,
                stale_headings: [heading],
                preview: true,
                ...(model && { model: model })
            })
        })
        .then(res => {
            if (!res.ok) throw new Error("HTTP-Fehler beim Generieren");
            return res.json();
        })
        .then(data => {
            block.generating = false;

            if (data.success && data.results && data.results[heading] !== undefined) {
                if (!this.previewCache[slug]) this.previewCache[slug] = {};
                if (!this.previewCache[slug][templateName]) this.previewCache[slug][templateName] = {};

                this.previewCache[slug][templateName][heading] = {
                    instruction_hash: this.getStringHash(block.instruction || ''),
                    output: data.results[heading]
                };

                this.savePreviewCache();
            } else {
                alert("Generierung fehlgeschlagen: " + (data.error || "Fehler"));
            }
            this.renderEditorPreview();
        })
        .catch(err => {
            console.error(err);
            block.generating = false;
            alert("Verbindungsfehler beim Generieren.");
            this.renderEditorPreview();
        });
    }

    parseInlineMarkdown(text) {
        if (!text) return [];
        const parts = text.split(/(\*\*.*?\*\*|\*.*?\*|__.*?__|`.*?`)/g);
        return parts.map(part => {
            if (part.startsWith('**') && part.endsWith('**')) {
                return { text: part.slice(2, -2), bold: true };
            } else if (part.startsWith('*') && part.endsWith('*')) {
                return { text: part.slice(1, -1), italic: true };
            } else if (part.startsWith('__') && part.endsWith('__')) {
                return { text: part.slice(2, -2), underline: true };
            } else if (part.startsWith('`') && part.endsWith('`')) {
                return { text: part.slice(1, -1), code: true };
            } else {
                return { text: part };
            }
        }).filter(p => p.text.length > 0);
    }

    generateDocxBlob(content, title, isMarkdown) {
        const docxLib = window.docx;
        if (!docxLib) {
            console.error("docx library is not loaded on window");
            return Promise.reject(new Error("docx library is not loaded"));
        }

        const lines = content.split('\n');
        const documentChildren = [];

        // Only prepend title if it's not a markdown document (summaries have their own heading blocks)
        const shouldPrependTitle = !isMarkdown && title;

        if (shouldPrependTitle) {
            documentChildren.push(
                new docxLib.Paragraph({
                    children: [
                        new docxLib.TextRun({
                            text: title,
                            bold: true,
                            size: 36,
                        }),
                    ],
                    spacing: { after: 300 },
                })
            );
        }

        lines.forEach(line => {
            let cleanLine = line.trim();
            if (cleanLine.length === 0) {
                documentChildren.push(
                    new docxLib.Paragraph({
                        children: [],
                        spacing: { after: 120 }
                    })
                );
                return;
            }

            let headingLevel = 0;
            let isListItem = false;
            let isNumberedItem = false;
            let listNumber = '';

            if (isMarkdown) {
                if (cleanLine.startsWith('# ')) {
                    headingLevel = 1;
                    cleanLine = cleanLine.substring(2);
                } else if (cleanLine.startsWith('## ')) {
                    headingLevel = 2;
                    cleanLine = cleanLine.substring(3);
                } else if (cleanLine.startsWith('### ')) {
                    headingLevel = 3;
                    cleanLine = cleanLine.substring(4);
                } else if (cleanLine.startsWith('- ') || cleanLine.startsWith('* ') || cleanLine.startsWith('• ')) {
                    isListItem = true;
                    cleanLine = cleanLine.substring(2);
                } else if (/^\d+\.\s/.test(cleanLine)) {
                    isNumberedItem = true;
                    const match = cleanLine.match(/^(\d+\.)\s/);
                    listNumber = match[1];
                    cleanLine = cleanLine.substring(match[0].length);
                }
            } else {
                if (/^(\[\d{1,2}:\d{2}(:\d{2})?\])?\s*[^:\n]+:$/.test(cleanLine)) {
                    headingLevel = 3;
                }
            }

            const runsData = isMarkdown ? this.parseInlineMarkdown(cleanLine) : [{ text: cleanLine }];
            const runs = runsData.map(part => {
                let size = 24;
                if (headingLevel === 1) size = 36;
                else if (headingLevel === 2) size = 28;
                else if (headingLevel === 3) size = 24;

                return new docxLib.TextRun({
                    text: part.text,
                    bold: part.bold || headingLevel > 0 || false,
                    italics: part.italic || false,
                    underline: part.underline ? (docxLib.UnderlineType ? { type: docxLib.UnderlineType.SINGLE } : {}) : undefined,
                    font: part.code ? 'Courier New' : undefined,
                    size: size,
                });
            });

            if (isListItem) {
                runs.unshift(new docxLib.TextRun({
                    text: "• ",
                    size: 24
                }));
            } else if (isNumberedItem) {
                runs.unshift(new docxLib.TextRun({
                    text: listNumber + " ",
                    bold: true,
                    size: 24
                }));
            }

            documentChildren.push(
                new docxLib.Paragraph({
                    children: runs.length > 0 ? runs : [],
                    spacing: { after: headingLevel > 0 ? 200 : 120 }
                })
            );
        });

        const doc = new docxLib.Document({
            sections: [
                {
                    properties: {
                        type: docxLib.SectionType.CONTINUOUS,
                    },
                    children: documentChildren,
                },
            ],
        });

        return docxLib.Packer.toBlob(doc);
    }

    generatePdfBlob(content, title, isMarkdown) {
        const jsPDFLib = window.jsPDF;
        if (!jsPDFLib) {
            console.error("jsPDF library is not loaded on window");
            return null;
        }

        const doc = new jsPDFLib();
        const maxPageHeight = 270;
        const margin = 20;
        const maxWidth = 210 - (margin * 2);
        const font = 'helvetica';
        
        const shouldPrependTitle = !isMarkdown && title;
        let yOffset = 20;
        
        if (shouldPrependTitle) {
            doc.setFont(font, 'bold');
            doc.setFontSize(16);
            doc.text(title, margin, yOffset);
            
            doc.setDrawColor(200, 200, 200);
            doc.line(margin, yOffset + 3, 210 - margin, yOffset + 3);
            
            yOffset += 12;
        }
        
        const lines = content.split('\n');
        
        lines.forEach(line => {
            let cleanLine = line.trim();
            if (cleanLine.length === 0) {
                yOffset += 6;
                return;
            }
            
            let fontSize = 11;
            let fontStyle = 'normal';
            let isHeading = false;
            
            if (isMarkdown) {
                if (cleanLine.startsWith('# ')) {
                    fontSize = 18;
                    fontStyle = 'bold';
                    cleanLine = cleanLine.substring(2);
                    isHeading = true;
                } else if (cleanLine.startsWith('## ')) {
                    fontSize = 14;
                    fontStyle = 'bold';
                    cleanLine = cleanLine.substring(3);
                    isHeading = true;
                } else if (cleanLine.startsWith('### ')) {
                    fontSize = 12;
                    fontStyle = 'bold';
                    cleanLine = cleanLine.substring(4);
                    isHeading = true;
                } else if (cleanLine.startsWith('- ') || cleanLine.startsWith('* ') || cleanLine.startsWith('• ')) {
                    cleanLine = '• ' + cleanLine.substring(2);
                }
                
                cleanLine = cleanLine.replace(/\*\*([^*]+)\*\*/g, '$1')
                                     .replace(/\*([^*]+)\*/g, '$1')
                                     .replace(/__([^_]+)__/g, '$1')
                                     .replace(/`([^`]+)`/g, '$1');
            } else {
                if (/^(\[\d{1,2}:\d{2}(:\d{2})?\])?\s*[^:\n]+:$/.test(cleanLine)) {
                    fontSize = 11;
                    fontStyle = 'bold';
                }
            }
            
            doc.setFont(font, fontStyle);
            doc.setFontSize(fontSize);
            
            const lineHeight = (doc.getLineHeight() / doc.internal.scaleFactor) || 6;
            
            const wrappedLines = doc.splitTextToSize(cleanLine, maxWidth);
            wrappedLines.forEach(wLine => {
                if (yOffset + lineHeight > maxPageHeight) {
                    doc.addPage();
                    yOffset = 20;
                }
                doc.text(wLine, margin, yOffset);
                yOffset += lineHeight;
            });
            
            yOffset += isHeading ? 4 : 2;
        });
        
        return doc.output('blob');
    }
}
