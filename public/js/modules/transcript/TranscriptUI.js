import { Utils } from './Utils.js';

export class TranscriptUI {
    constructor(app) {
        this.app = app;
    }

    initEventListeners() {
        const dropArea = document.getElementById('drop-zone');
        const fileInput = document.getElementById('audio_file');
        const transcribeBtn = document.getElementById('start-upload-btn');
        const historySearch = document.getElementById('history-search');

        if (dropArea) {
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, () => dropArea.classList.add('highlight'), false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, () => dropArea.classList.remove('highlight'), false);
            });

            dropArea.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                if (dt.files && dt.files.length > 0) {
                    fileInput.files = dt.files;
                    this.handleFileSelect(dt.files[0]);
                }
            }, false);

            dropArea.addEventListener('click', () => {
                if (fileInput) fileInput.click();
            });

            if (fileInput) {
                fileInput.addEventListener('change', (e) => {
                    if (e.target.files && e.target.files.length > 0) {
                        this.handleFileSelect(e.target.files[0]);
                    }
                });
            }
        }

        if (historySearch) {
            historySearch.addEventListener('input', () => this.app.history.filterHistory());
        }

        if (transcribeBtn) {
            transcribeBtn.addEventListener('click', () => this.startTranscription());
        }

        const settingsMenuTrigger = document.getElementById('settings-menu-trigger');
        if (settingsMenuTrigger) {
            settingsMenuTrigger.addEventListener('click', (e) => {
                e.preventDefault();
                this.openTranscriptSettings();
            });
        }

        const downloadBtn = document.getElementById('download-transcript-btn');
        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => {
                // If exportData is available, trigger export download
                if (this.app.state.exportData) {
                    this.app.exportManager.triggerExportDownload();
                } else {
                    // Fallback to old behavior: just download the displayed text
                    const resDiv = document.getElementById('transcription-result');
                    const text = resDiv ? resDiv.innerText : '';
                    if (!text) return;
                    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    const id = this.app.state.currentTranscriptSlug || 'export';
                    a.download = `transkription-${id}.txt`;
                    a.click();
                    URL.revokeObjectURL(url);
                }
            });
        }
        
        document.addEventListener('mouseup', this.app.processor.handleTextSelection.bind(this.app.processor));
    }

    showIfExist(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'block';
    }

    hideIfExist(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    switchTranscriptView(viewId) {
        const mainPanels = [
            'transcript-choice',
            'transcript-file-ui',
            'transcript-live-ui',
            'transcript-history-ui',
            'transcript-export-ui',
            'transcription-output-inline'
        ];
        const sidebarPanels = [
            'sidebar-history-content',
            'sidebar-detail-content',
            'file-transcription-options',
            'transcript-settings-footer-container',
            'edit-mode-panel'
        ];

        [...mainPanels, ...sidebarPanels].forEach(id => this.hideIfExist(id));

        switch (viewId) {
            case 'choice':
                this.showIfExist('transcript-choice');
                this.showIfExist('sidebar-history-content');
                const searchInput = document.getElementById('history-search');
                if (searchInput) {
                    searchInput.value = '';
                    this.app.history.filterHistory();
                }
                break;
            case 'file':
                this.showIfExist('transcript-file-ui');
                this.showIfExist('file-transcription-options');
                this.showIfExist('transcript-settings-footer-container');
                this.showIfExist('drop-zone');
                break;
            case 'live':
                this.showIfExist('transcript-live-ui');
                break;
            case 'view-transcript':
                this.showIfExist('transcript-history-ui');
                this.showIfExist('sidebar-detail-content');
                this.hideIfExist('sidebar-history-content');
                break;
            case 'transcript-export-ui':
                this.showIfExist('transcript-export-ui');
                this.showIfExist('sidebar-detail-content');
                this.hideIfExist('sidebar-history-content');
                break;
        }

        if (viewId === 'view-transcript') {
            document.querySelectorAll('#chats-list .selection-item').forEach(item => item.style.display = 'flex');
        }
        
        const bBtn = document.getElementById('btn-back-to-mode');
        if (bBtn) bBtn.style.display = (viewId === 'view-transcript') ? 'inline-flex' : 'none';
        
        const qBtn = document.getElementById('quick-actions');
        if (qBtn) qBtn.style.display = (viewId === 'view-transcript') ? 'inline-flex' : 'none';
    }

    toggleSidebarMenu(menuId) {
        const editBtn = document.getElementById('edit-mode-btn');
        const exportBtn = document.getElementById('export-options-btn');
        const sentenceBtn = document.getElementById('reorder-sentences-btn');
        
        const editPanel = document.getElementById('edit-mode-panel');
        const exportPanel = document.getElementById('export-options-panel');
        const sentencePanel = document.getElementById('sentence-reorder-panel');
        
        const historyUI = document.getElementById('transcript-history-ui');

        this.switchTranscriptView('view-transcript');

        [editBtn, exportBtn, sentenceBtn].forEach(btn => {
            if (btn) btn.classList.remove('active');
        });
        [editPanel, exportPanel, sentencePanel].forEach(panel => {
            if (panel) panel.style.display = 'none';
        });

        if (menuId === 'edit') {
            if (editBtn) editBtn.classList.add('active');
            if (editPanel) editPanel.style.display = 'block';
            if (historyUI) historyUI.style.display = 'flex';
            this.app.state.editModeActive = true;
            this.app.state.reorderModeActive = false;
            this.renderTranscriptArea();
        } else if (menuId === 'sentences') {
            if (sentenceBtn) sentenceBtn.classList.add('active');
            if (sentencePanel) sentencePanel.style.display = 'block';
            if (historyUI) historyUI.style.display = 'flex';
            this.app.state.editModeActive = true;
            this.app.state.reorderModeActive = true;
            this.renderTranscriptArea();
        } else if (menuId === 'export') {
            if (exportBtn) exportBtn.classList.add('active');
            if (exportPanel) exportPanel.style.display = 'block';
            if (historyUI) historyUI.style.display = 'flex';
            this.app.state.editModeActive = false;
            this.app.state.reorderModeActive = false;
            this.renderTranscriptArea();
        }
    }

    toggleRedactionAccordion() {
        const accordion = document.getElementById('redaction-accordion');
        const content = document.getElementById('redaction-accordion-content');
        if (!accordion || !content) return;

        const isVisible = content.style.display !== 'none';
        content.style.display = isVisible ? 'none' : 'block';
        accordion.classList.toggle('expanded', !isVisible);
    }

    toggleSatzkorrektur() {
        // Toggle reorder mode
        if (this.app.state.reorderModeActive) {
            this.toggleSidebarMenu('edit'); // Go back to edit view
        } else {
            this.toggleSidebarMenu('sentences');
        }
        
        // Remove toolbar after action
        const toolbar = document.getElementById('selection-toolbar');
        if (toolbar) toolbar.remove();
        
        // Clear selection
        window.getSelection().removeAllRanges();
    }

    finishReorderMode() {
        this.toggleSidebarMenu('edit');
    }

    updateSidebarSaveButtonState() {
        const btn = document.getElementById('sidebar-save-btn');
        if (!btn) return;

        if (this.app.state.activeSavePromise) {
            btn.classList.add('saving');
            btn.classList.remove('success');
            btn.innerHTML = `<div class="loader-spinner"></div> Speichern...`;
        } else if (btn.classList.contains('saving')) {
            btn.classList.remove('saving');
            btn.classList.add('success');
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Gespeichert`;
            
            setTimeout(() => {
                btn.classList.remove('success');
                btn.innerHTML = `Änderungen speichern`;
            }, 2000);
        }
    }

    showTranscriptMode(mode) {
        this.switchTranscriptView(mode);
        document.querySelectorAll('.sidebar-menu-content').forEach(m => m.style.display = 'none');
        document.querySelectorAll('.sidebar-bottom-item').forEach(btn => btn.classList.remove('active'));
    }

    showTranscriptChoice() {
        this.switchTranscriptView('choice');
        document.querySelectorAll('#chats-list .selection-item').forEach(item => item.classList.remove('active'));
        const search = document.getElementById('history-search');
        if (search) search.value = '';
    }

    handleFileSelect(file) {
        if (!file) return;

        const maxFileSize = 100 * 1024 * 1024;
        const validTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/m4a', 'video/mp4'];
        
        if (!validTypes.includes(file.type) && !file.name.match(/\.(mp3|wav|m4a|mp4)$/i)) {
            alert('Bitte wählen Sie eine unterstützte Audio- oder Videodatei (MP3, WAV, M4A, MP4).');
            document.getElementById('audio_file').value = '';
            return;
        }

        if (file.size > maxFileSize) {
            alert('Die Datei ist zu groß. Bitte wählen Sie eine Datei unter 100 MB.');
            document.getElementById('audio_file').value = '';
            return;
        }

        this.app.state.selectedAudioFile = file;

        const fileNameSpan = document.getElementById('selected-file-name');
        const sidebarPill = document.getElementById('sidebar-file-pill');
        const sidebarFileName = document.getElementById('sidebar-file-name');
        const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');
        const filePreview = document.getElementById('selected-file-preview');
        
        if (fileNameSpan) fileNameSpan.textContent = file.name;
        if (sidebarFileName) sidebarFileName.textContent = file.name;
        if (sidebarPill) sidebarPill.style.display = 'flex';
        if (sidebarPlaceholder) sidebarPlaceholder.style.display = 'none';
        if (filePreview) filePreview.style.display = 'block';

        const audioElement = document.createElement('audio');
        audioElement.src = URL.createObjectURL(file);
        audioElement.addEventListener('loadedmetadata', () => {
            const duration = audioElement.duration;
            const formattedDuration = Utils.formatSecondsToTime(duration);
            const endTime = document.getElementById('end-time');
            if (endTime) endTime.value = formattedDuration;
        });
    }

    removeSelectedFile() {
        this.app.state.selectedAudioFile = null;
        const fileInput = document.getElementById('audio_file');
        if (fileInput) fileInput.value = '';
        
        const filePreview = document.getElementById('selected-file-preview');
        const sidebarPill = document.getElementById('sidebar-file-pill');
        const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');
        
        if (filePreview) filePreview.style.display = 'none';
        if (sidebarPill) sidebarPill.style.display = 'none';
        if (sidebarPlaceholder) sidebarPlaceholder.style.display = 'inline-block';
        
        const endTime = document.getElementById('end-time');
        if (endTime) endTime.value = '';
    }

    highlightSegment(segIdx, highlight) {
        const segEls = document.querySelectorAll(`.transcript-seg-item[data-seg-id="${segIdx}"]`);
        segEls.forEach(el => {
            if (highlight) {
                el.style.backgroundColor = 'rgba(91, 140, 238, 0.15)';
                el.style.outline = '1px dashed var(--color-primary)';
                el.style.borderRadius = '2px';
            } else {
                el.style.backgroundColor = '';
                el.style.outline = '';
            }
        });
    }

    copyBlockText(btn) {
        const block = btn.closest('.transcript-segment');
        if (!block) return;
        
        const speakerLabel = block.querySelector('.speaker-label')?.textContent || 'Sprecher';
        const textElements = block.querySelectorAll('.transcript-seg-item');
        let text = '';
        
        textElements.forEach(el => {
            const content = el.cloneNode(true);
            const redactedEls = content.querySelectorAll('.redacted');
            redactedEls.forEach(r => {
                r.textContent = '[AUSGEBLENDET]';
            });
            text += content.textContent;
        });

        const formattedText = `${speakerLabel}: ${text.trim()}`;

        navigator.clipboard.writeText(formattedText).then(() => {
            const originalIcon = btn.innerHTML;
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>`;
            setTimeout(() => {
                btn.innerHTML = originalIcon;
            }, 2000);
        }).catch(err => {
            console.error('Failed to copy text: ', err);
        });
    }

    openTranscriptSettings() {
        this.app.service.loadTranscriptConfig();
        const modal = document.getElementById('transcript-settings-modal');
        if (modal) {
            modal.style.display = 'flex';
        }
    }

    closeTranscriptSettings() {
        const modal = document.getElementById('transcript-settings-modal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    renderTranscriptArea() {
        const resDiv = document.getElementById('transcription-result');
        if (resDiv) {
            // Cleanup placeholders that are no longer alone in their block
            if (this.app.processor && typeof this.app.processor.cleanupOrphanedPlaceholders === 'function') {
                this.app.processor.cleanupOrphanedPlaceholders();
            }
            const html = this.app.processor.formatTranscriptionWithSpeakers(
                this.app.state.currentTranscriptSegments,
                this.app.state.currentTranscriptText,
                this.app.state.reorderModeActive
            );
            resDiv.innerHTML = html;
            
            if (!this.app.state.reorderModeActive && this.app.processor && typeof this.app.processor.populateSpeakerPanel === 'function') {
                this.app.processor.populateSpeakerPanel(resDiv);
            }
        }
    }

    async startTranscription() {
        if (!this.app.state.selectedAudioFile) {
            console.error("Bitte wähle zuerst eine Datei aus.");
            return;
        }

        const dropZoneContent = document.getElementById('drop-zone-content');
        const spinner = document.getElementById('loading-spinner');
        if (dropZoneContent) dropZoneContent.style.display = 'none';
        
        if (spinner) {
            spinner.style.display = 'block';
            if (!spinner.querySelector('.extra-loading-info')) {
                const extraInfo = document.createElement('p');
                extraInfo.className = 'extra-loading-info';
                extraInfo.style.cssText = 'color: #666; font-size: 14px; margin-top: 10px;';
                extraInfo.innerHTML = '<small>Dies kann bei langen Audiodateien mehrere Minuten dauern.</small>';
                spinner.appendChild(extraInfo);
            }
        }

        document.body.classList.add('cursor-wait');

        const formData = new FormData();
        formData.append('audio', this.app.state.selectedAudioFile);
        formData.append('language', 'de');

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        if (csrfToken) {
            formData.append('_token', csrfToken);
        }

        try {
            const response = await fetch('/req/transcribe', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json'
                },
                body: formData
            });

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Server-Timeout oder Fehler. Bitte kürzere Datei versuchen.');
            }

            const data = await response.json();

            if (spinner) spinner.style.display = 'none';
            if (dropZoneContent) dropZoneContent.style.display = 'flex';
            document.body.classList.remove('cursor-wait');

            if (data.success && data.text) {
                const outputDivInline = document.getElementById('transcription-result-inline');
                const outputContainerInline = document.getElementById('transcription-output-inline');

                if (!outputDivInline || !outputContainerInline) {
                    console.error('Fehler: Anzeige-Elemente fehlen.');
                    return;
                }

                const formattedHTML = this.app.processor.formatTranscriptionWithSpeakers(data.segments || [], data.text);
                outputDivInline.innerHTML = formattedHTML;

                const dropZone = document.getElementById('drop-zone');
                if (dropZone) dropZone.style.display = 'none';
                
                const preview = document.getElementById('selected-file-preview');
                if (preview) preview.style.display = 'none';
                
                outputContainerInline.style.display = 'flex';

                document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'none');

                this.app.state.currentTranscriptSegments = data.segments || [];
                this.app.state.currentTranscriptText = data.text || '';
                
                this.app.state.activeSavePromise = this.app.service.saveTranscriptionToDatabase(
                    data,
                    this.app.state.selectedAudioFile
                )
                    .then(savedTranscription => {
                        this.app.history.saveTranscriptToHistory(data.text, savedTranscription.slug, savedTranscription.title, data.segments);
                        this.app.state.currentTranscriptSlug = savedTranscription.slug;
                        this.app.service.pollForTitleUpdate(savedTranscription.slug, savedTranscription.title);
                        this.app.history.renderHistory();
                        
                        this.app.history.loadTranscript(savedTranscription.slug, true);
                        
                        return savedTranscription;
                    })
                    .catch(err => {
                        console.warn('DB-Save failed:', err);
                        this.app.history.saveTranscriptToHistory(data.text, null, null, data.segments);
                    })
                    .finally(() => {
                        this.app.state.activeSavePromise = null;
                        this.updateSidebarSaveButtonState();
                    });

            } else {
                console.error("Fehler: " + (data.message || "Keine Antwort."));
                alert("Fehler: " + (data.message || "Keine Antwort."));
            }

        } catch (error) {
            if (spinner) spinner.style.display = 'none';
            if (dropZoneContent) dropZoneContent.style.display = 'flex';
            document.body.classList.remove('cursor-wait');
            console.error("Upload-Fehler: " + error.message);
            alert("Upload-Fehler: " + error.message);
        }
    }
}
