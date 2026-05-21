import { Utils } from './Utils.js';

export class TranscriptUI {
    constructor(app) {
        this.app = app;
        this.currentAudioPlayer = null;
        this.currentAudioBtn = null;
        this.currentAudioPlaceholder = null;
        this.audioUpdateHandler = null;
        this.pollingJobs = new Set();
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

        // Lock text selection to a single speaker segment
        document.addEventListener('mousedown', (e) => {
            const segment = e.target.closest('.transcript-segment');
            if (segment) {
                const container = e.target.closest('.transcript-content-area');
                if (container) container.classList.add('selection-locked');
                document.querySelectorAll('.transcript-segment.active-selection').forEach(el => el.classList.remove('active-selection'));
                segment.classList.add('active-selection');
            }
        });

        document.addEventListener('mouseup', () => {
            const containers = document.querySelectorAll('.transcript-content-area');
            containers.forEach(container => container.classList.remove('selection-locked'));
        });

        document.addEventListener('selectionchange', () => {
            const selection = window.getSelection();
            if (!selection || selection.isCollapsed) return;

            // Only enforce this if we are inside the transcription output
            const container = selection.anchorNode.closest ? selection.anchorNode.closest('.transcript-content-area') : selection.anchorNode.parentElement?.closest('.transcript-content-area');
            if (!container) return;

            const getSpeakerFromNode = (node) => {
                if (!node) return null;
                const el = node.nodeType === 3 ? node.parentElement : node;
                return el ? el.closest('.transcript-segment') : null;
            };

            const anchorSpeaker = getSpeakerFromNode(selection.anchorNode);
            const focusSpeaker = getSpeakerFromNode(selection.focusNode);

            if (anchorSpeaker && anchorSpeaker !== focusSpeaker) {
                const newRange = document.createRange();
                const position = selection.anchorNode.compareDocumentPosition(selection.focusNode);
                const isDraggingDown = position & Node.DOCUMENT_POSITION_FOLLOWING;

                if (isDraggingDown) {
                    newRange.setStart(selection.anchorNode, selection.anchorOffset);
                    const walker = document.createTreeWalker(anchorSpeaker.querySelector('.transcript-text'), NodeFilter.SHOW_TEXT, null, false);
                    let lastTextNode = null;
                    while(walker.nextNode()) lastTextNode = walker.currentNode;
                    if (lastTextNode) newRange.setEnd(lastTextNode, lastTextNode.length);
                } else {
                    const walker = document.createTreeWalker(anchorSpeaker.querySelector('.transcript-text'), NodeFilter.SHOW_TEXT, null, false);
                    const firstTextNode = walker.nextNode();
                    if (firstTextNode) newRange.setStart(firstTextNode, 0);
                    newRange.setEnd(selection.anchorNode, selection.anchorOffset);
                }
                
                selection.removeAllRanges();
                selection.addRange(newRange);
            }
        });
    }

    showIfExist(id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('hidden');
    }

    hideIfExist(id) {
        const el = document.getElementById(id);
        if (el) el.classList.add('hidden');
    }

    switchTranscriptView(viewId) {
        const mainPanels = [
            'transcript-choice',
            'transcript-file-ui',
            'transcript-live-ui',
            'transcript-history-ui',
            'transcription-output-inline'
        ];
        const sidebarPanels = [
            'sidebar-history-content',
            'file-transcription-options',
            'transcript-settings-footer-container'
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
                this.loadActiveJobs();
                break;
            case 'live':
                this.showIfExist('transcript-live-ui');
                break;
            case 'view-transcript':
                this.showIfExist('transcript-history-ui');
                this.showIfExist('sidebar-history-content');
                break;
        }

        if (viewId === 'view-transcript') {
            document.querySelectorAll('#chats-list .selection-item').forEach(item => item.classList.remove('hidden'));
        }
        
        const bBtn = document.getElementById('btn-back-to-mode');
        if (bBtn) bBtn.classList.toggle('hidden', viewId !== 'view-transcript');
    }

    switchTab(tabId) {
        document.querySelectorAll('.transcript-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
        
        const activeTabBtn = document.querySelector(`.transcript-tab[data-tab="${tabId}"]`);
        if (activeTabBtn) activeTabBtn.classList.add('active');
        
        const activeContent = document.getElementById(`tab-${tabId}`);
        if (activeContent) activeContent.classList.remove('hidden');

        if (tabId === 'korrekturen') {
            this.app.state.editModeActive = true;
            this.renderTranscriptArea();
        } else if (tabId === 'vorschau') {
            this.app.state.editModeActive = false;
            document.getElementById('selection-toolbar')?.remove();
            document.getElementById('custom-context-menu')?.classList.add('hidden');
            this.renderTranscriptArea();
        } else if (tabId === 'export') {
            this.app.state.editModeActive = false;
            document.getElementById('selection-toolbar')?.remove();
            document.getElementById('custom-context-menu')?.classList.add('hidden');
            if (this.app.exportManager) {
                this.app.exportManager.selectExportOption(this.app.state.exportType || 'ergebnis');
            }
        }
    }

    toggleRedactionAccordion() {
        const accordion = document.getElementById('redaction-accordion');
        const content = document.getElementById('redaction-accordion-content');
        if (!accordion || !content) return;

        const isVisible = !content.classList.contains('hidden');
        content.classList.toggle('hidden', isVisible);
        accordion.classList.toggle('expanded', !isVisible);
    }

    saveReorderHistory() {
        if (this.app.state.currentTranscriptSlug) {
            // Re-sync the transcript text just to be safe
            this.app.state.currentTranscriptText = this.app.processor.buildTranscriptTextFromSegments();
            this.app.history.saveTranscriptToHistory(
                this.app.state.currentTranscriptText, 
                this.app.state.currentTranscriptSlug, 
                null, 
                this.app.state.currentTranscriptSegments
            );
            this.app.history.renderHistory();
        }
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
        document.querySelectorAll('.sidebar-menu-content').forEach(m => m.classList.add('hidden'));
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

        const sidebarPill = document.getElementById('sidebar-file-pill');
        const sidebarFileName = document.getElementById('sidebar-file-name');
        const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');
        
        if (sidebarFileName) sidebarFileName.textContent = file.name;
        if (sidebarPill) sidebarPill.classList.remove('hidden');
        if (sidebarPlaceholder) sidebarPlaceholder.classList.add('hidden');

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
        
        const sidebarPill = document.getElementById('sidebar-file-pill');
        const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');
        
        if (sidebarPill) sidebarPill.classList.add('hidden');
        if (sidebarPlaceholder) sidebarPlaceholder.classList.remove('hidden');
        
        const endTime = document.getElementById('end-time');
        if (endTime) endTime.value = '';
    }

    highlightSegment(segIdx, highlight) {
        const selection = window.getSelection();

        // If user is actively dragging handles, do not interfere
        if (this.app.selectionHandles && this.app.selectionHandles.isDragging) return;

        if (!highlight) {
            // Only clear the selection if we created it via auto-highlight
            if (this.app.state.autoHighlightedSegIdx === segIdx) {
                selection.removeAllRanges();
                this.app.state.autoHighlightedSegIdx = null;
            }
            return;
        }

        // Do not override user's manual selection
        if (selection && !selection.isCollapsed && this.app.state.autoHighlightedSegIdx == null) {
            return;
        }

        const segEls = document.querySelectorAll(`.transcript-seg-item[data-seg-id="${segIdx}"]`);
        if (segEls.length > 0) {
            const range = document.createRange();
            range.setStartBefore(segEls[0].firstChild || segEls[0]);
            
            const lastEl = segEls[segEls.length - 1];
            range.setEndAfter(lastEl.lastChild || lastEl);
            
            selection.removeAllRanges();
            selection.addRange(range);
            this.app.state.autoHighlightedSegIdx = segIdx;
        }
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

    toggleAudioPlayer(btn, bIdx) {
        const playerPlaceholder = document.getElementById(`audio-player-${bIdx}`);
        if (!playerPlaceholder) return;
        
        const block = this.app.state.lastRenderedSpeakerBlocks[bIdx];
        if (!block || block.segmentIndices.length === 0) return;
        
        const firstIdx = block.segmentIndices[0];
        const lastIdx = block.segmentIndices[block.segmentIndices.length - 1];
        const start = this.app.state.currentTranscriptSegments[firstIdx].start;
        const end = this.app.state.currentTranscriptSegments[lastIdx].end;

        const playIcon = `<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor">
                            <path d="M8.25 3.75L4.5 6.75H1.5V11.25H4.5L8.25 14.25V3.75Z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M14.3018 3.69727C15.7078 5.10372 16.4977 7.01103 16.4977 8.99977C16.4977 10.9885 15.7078 12.8958 14.3018 14.3023M11.6543 6.34477C12.3573 7.04799 12.7522 8.00165 12.7522 8.99602C12.7522 9.99038 12.3573 10.944 11.6543 11.6473" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>`;
        const stopIcon = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><rect x="9" y="9" width="6" height="6"></rect></svg>`;

        // If clicking the same button that is currently playing
        if (this.currentAudioBtn === btn) {
            this.stopCurrentAudio();
            return;
        }

        // Stop any currently playing audio
        this.stopCurrentAudio();

        playerPlaceholder.classList.remove('hidden');
        
        if (this.app.state.selectedAudioFile) {
            let audio = playerPlaceholder.querySelector('audio');
            if (!audio) {
                if (!this.app.state.audioFileUrl) {
                    this.app.state.audioFileUrl = URL.createObjectURL(this.app.state.selectedAudioFile);
                }
                playerPlaceholder.innerHTML = `<audio controls style="width: 100%; height: 40px; margin-top: 5px;">
                    <source src="${this.app.state.audioFileUrl}" type="${this.app.state.selectedAudioFile.type}">
                    Dein Browser unterstützt das Audio-Element nicht.
                </audio>`;
                audio = playerPlaceholder.querySelector('audio');
            }
            
            this.currentAudioPlayer = audio;
            this.currentAudioBtn = btn;
            this.currentAudioPlaceholder = playerPlaceholder;
            btn.innerHTML = stopIcon;
            
            audio.currentTime = start;
            audio.play().catch(e => console.error("Audio playback failed", e));
            
            this.audioUpdateHandler = () => {
                if (audio.currentTime >= end) {
                    this.stopCurrentAudio();
                }
            };
            audio.addEventListener('timeupdate', this.audioUpdateHandler);
            
            audio.addEventListener('pause', () => {
                if (this.currentAudioPlayer === audio && this.currentAudioBtn) {
                    this.currentAudioBtn.innerHTML = playIcon;
                }
            }, { once: true });
            
        } else {
            playerPlaceholder.innerHTML = `<div style="padding: 10px; background: #f5f5f5; border-radius: 4px; font-size: 12px; color: #666; margin-top: 5px; display: flex; align-items: center; justify-content: center;">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px;"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg>
                Audio-Wiedergabe nicht verfügbar (Datei nicht gefunden)
            </div>`;
            this.currentAudioBtn = btn;
            this.currentAudioPlaceholder = playerPlaceholder;
            btn.innerHTML = stopIcon;
        }
    }

    stopCurrentAudio() {
        const playIcon = `<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor">
                            <path d="M8.25 3.75L4.5 6.75H1.5V11.25H4.5L8.25 14.25V3.75Z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M14.3018 3.69727C15.7078 5.10372 16.4977 7.01103 16.4977 8.99977C16.4977 10.9885 15.7078 12.8958 14.3018 14.3023M11.6543 6.34477C12.3573 7.04799 12.7522 8.00165 12.7522 8.99602C12.7522 9.99038 12.3573 10.944 11.6543 11.6473" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>`;
        
        if (this.currentAudioPlayer) {
            this.currentAudioPlayer.pause();
            if (this.audioUpdateHandler) {
                this.currentAudioPlayer.removeEventListener('timeupdate', this.audioUpdateHandler);
                this.audioUpdateHandler = null;
            }
            this.currentAudioPlayer = null;
        }
        
        if (this.currentAudioPlaceholder) {
            this.currentAudioPlaceholder.classList.add('hidden');
            this.currentAudioPlaceholder = null;
        }
        
        if (this.currentAudioBtn) {
            this.currentAudioBtn.innerHTML = playIcon;
            this.currentAudioBtn = null;
        }
    }


    openTranscriptSettings() {
        this.app.service.loadTranscriptConfig();
        const modal = document.getElementById('transcript-settings-modal');
        if (modal) {
            modal.classList.remove('hidden');
        }
    }

    closeTranscriptSettings() {
        const modal = document.getElementById('transcript-settings-modal');
        if (modal) {
            modal.classList.add('hidden');
        }
    }

    renderTranscriptArea() {
        // Render into appropriate container depending on mode
        const resDivVorschau = document.getElementById('transcription-result');
        const resDivEdit = document.getElementById('transcription-result-container-edit');

        if (resDivVorschau || resDivEdit) {
            // Cleanup placeholders that are no longer alone in their block
            if (this.app.processor && typeof this.app.processor.cleanupOrphanedPlaceholders === 'function') {
                this.app.processor.cleanupOrphanedPlaceholders();
            }

            if (resDivVorschau) {
                resDivVorschau.innerHTML = this.app.processor.formatTranscriptionWithSpeakers(
                    this.app.state.currentTranscriptSegments,
                    this.app.state.currentTranscriptText,
                    false
                );
            }

            if (resDivEdit) {
                resDivEdit.innerHTML = this.app.processor.formatTranscriptionWithSpeakers(
                    this.app.state.currentTranscriptSegments,
                    this.app.state.currentTranscriptText,
                    true
                );
            }
            
            if (this.app.processor && typeof this.app.processor.populateSpeakerPanel === 'function') {
                if (this.app.state.editModeActive && resDivEdit) {
                    this.app.processor.populateSpeakerPanel(resDivEdit);
                } else if (resDivVorschau) {
                    this.app.processor.populateSpeakerPanel(resDivVorschau);
                }
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
        if (dropZoneContent) dropZoneContent.classList.add('hidden');
        
        if (spinner) {
            spinner.classList.remove('hidden');
            let infoEl = spinner.querySelector('.extra-loading-info');
            if (!infoEl) {
                const extraInfo = document.createElement('p');
                extraInfo.className = 'extra-loading-info loading-extra-info';
                spinner.appendChild(extraInfo);
                infoEl = extraInfo;
            }
            infoEl.innerHTML = '<small>Vorbereitung für Upload...</small>';
        }

        document.body.classList.add('cursor-wait');

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        try {
            // 1. Create upload session
            if (spinner) spinner.querySelector('.extra-loading-info').innerHTML = '<small>Fordere Upload-URL an...</small>';
            const sessionResponse = await fetch('/req/transcription/async/session', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ filename: this.app.state.selectedAudioFile.name })
            });

            const sessionData = await sessionResponse.json();
            if (!sessionData.success) {
                throw new Error('Konnte keine Upload-Session erstellen.');
            }

            const { job_id, upload_url } = sessionData.session;

            // 2. Upload file directly to S3
            if (spinner) spinner.querySelector('.extra-loading-info').innerHTML = '<small>Lade Datei hoch...</small>';
            const uploadResponse = await fetch(upload_url, {
                method: 'PUT',
                body: this.app.state.selectedAudioFile
            });

            if (!uploadResponse.ok) {
                throw new Error('Fehler beim Datei-Upload zu S3.');
            }

            // 3. Dispatch Job
            if (spinner) spinner.querySelector('.extra-loading-info').innerHTML = '<small>Datei wird verarbeitet...</small>';
            const dispatchResponse = await fetch(`/req/transcription/async/dispatch/${job_id}`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            });

            const dispatchData = await dispatchResponse.json();
            if (!dispatchData.success) {
                throw new Error('Konnte Verarbeitungs-Job nicht starten.');
            }

            // 4. Poll Status
            let isCompleted = false;
            let resultData = null;

            while (!isCompleted) {
                await new Promise(r => setTimeout(r, 2000));
                const statusResponse = await fetch(`/req/transcription/async/status/${job_id}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const statusData = await statusResponse.json();

                if (statusData.status === 'failed') {
                    throw new Error('Fehler bei der Transkription: ' + (statusData.error || 'Unbekannt'));
                } else if (statusData.status === 'completed') {
                    isCompleted = true;
                    resultData = statusData.result;
                } else if (statusData.status === 'transcribing') {
                    if (spinner) spinner.querySelector('.extra-loading-info').innerHTML = '<small>Audio wird transkribiert...</small>';
                } else if (statusData.status === 'preprocessed') {
                    if (spinner) spinner.querySelector('.extra-loading-info').innerHTML = '<small>Vorbereitung abgeschlossen, starte Transkription...</small>';
                }
            }

            if (spinner) spinner.classList.add('hidden');
            if (dropZoneContent) dropZoneContent.classList.remove('hidden');
            document.body.classList.remove('cursor-wait');

            if (resultData && resultData.success) {
                this.handleJobCompleted(resultData);
            } else {
                console.error("Fehler: " + (resultData?.message || "Keine Antwort."));
                alert("Fehler: " + (resultData?.message || "Keine Antwort."));
            }

        } catch (error) {
            if (spinner) spinner.classList.add('hidden');
            if (dropZoneContent) dropZoneContent.classList.remove('hidden');
            document.body.classList.remove('cursor-wait');
            console.error("Upload-Fehler: " + error.message);
            alert("Upload-Fehler: " + error.message);
        }
    }

    async loadActiveJobs() {
        try {
            const response = await fetch('/req/transcriptions/jobs/active', {
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json();
            if (data.success && data.jobs) {
                this.renderActiveJobs(data.jobs);
            }
        } catch (error) {
            console.error('Fehler beim Laden aktiver Jobs:', error);
        }
    }

    renderActiveJobs(jobs) {
        const container = document.getElementById('active-jobs-container');
        const list = document.getElementById('active-jobs-list');
        
        if (!container || !list) return;

        if (jobs.length === 0) {
            container.classList.add('hidden');
            return;
        }

        container.classList.remove('hidden');
        
        // Remove completed jobs that are no longer active
        const currentActiveJobIds = jobs.map(j => j.id);
        
        // Add new jobs to UI if not already there
        jobs.forEach(job => {
            let jobEl = document.getElementById(`active-job-${job.id}`);
            
            let statusText = 'Wird verarbeitet...';
            if (job.status === 'preprocessing') statusText = 'Vorbereitung (Audio Konvertierung)...';
            if (job.status === 'transcribing') statusText = 'Audio wird transkribiert...';

            if (!jobEl) {
                jobEl = document.createElement('div');
                jobEl.id = `active-job-${job.id}`;
                jobEl.className = 'active-job-item';
                jobEl.style.cssText = 'padding: 12px; background: var(--chat-msg-bg, #f8fafc); border: 1px solid var(--border-color, #e2e8f0); border-radius: 6px; display: flex; align-items: center; justify-content: space-between;';
                
                jobEl.innerHTML = `
                    <div style="display: flex; flex-direction: column; gap: 4px;">
                        <strong style="font-size: 0.9rem; color: var(--text-color, #333);">Job: ${job.id.substring(0, 8)}...</strong>
                        <span class="job-status-text" style="font-size: 0.8rem; color: var(--text-muted, #64748b);">${statusText}</span>
                    </div>
                    <div class="loader-spinner" style="width: 16px; height: 16px; border-width: 2px;"></div>
                `;
                list.appendChild(jobEl);
            } else {
                const statusEl = jobEl.querySelector('.job-status-text');
                if (statusEl) statusEl.textContent = statusText;
            }

            // Start polling if not already polling
            if (!this.pollingJobs.has(job.id)) {
                this.pollActiveJob(job.id);
            }
        });
        
        // Clean up UI for jobs that are completed
        Array.from(list.children).forEach(child => {
            const id = child.id.replace('active-job-', '');
            if (!currentActiveJobIds.includes(id) && !this.pollingJobs.has(id)) {
                child.remove();
            }
        });
        
        if (list.children.length === 0) {
            container.classList.add('hidden');
        }
    }

    async pollActiveJob(jobId) {
        if (this.pollingJobs.has(jobId)) return;
        this.pollingJobs.add(jobId);
        
        let isCompleted = false;
        let resultData = null;
        let errorMsg = null;

        try {
            while (!isCompleted) {
                await new Promise(r => setTimeout(r, 3000));
                
                const statusResponse = await fetch(`/req/transcription/async/status/${jobId}`, {
                    headers: { 'Accept': 'application/json' }
                });
                
                if (!statusResponse.ok) {
                    continue; // Might be temporary network issue
                }
                
                const statusData = await statusResponse.json();

                const jobEl = document.getElementById(`active-job-${jobId}`);
                const statusTextEl = jobEl ? jobEl.querySelector('.job-status-text') : null;

                if (statusData.status === 'failed') {
                    isCompleted = true;
                    errorMsg = statusData.error || 'Unbekannter Fehler';
                    if (statusTextEl) {
                        statusTextEl.textContent = 'Fehlgeschlagen: ' + errorMsg;
                        statusTextEl.style.color = '#ef4444';
                    }
                    if (jobEl) {
                        const spinner = jobEl.querySelector('.loader-spinner');
                        if (spinner) spinner.remove();
                    }
                } else if (statusData.status === 'completed') {
                    isCompleted = true;
                    resultData = statusData.result;
                } else if (statusData.status === 'transcribing') {
                    if (statusTextEl) statusTextEl.textContent = 'Audio wird transkribiert...';
                } else if (statusData.status === 'preprocessed') {
                    if (statusTextEl) statusTextEl.textContent = 'Vorbereitung abgeschlossen, starte Transkription...';
                }
            }

            this.pollingJobs.delete(jobId);
            
            const jobEl = document.getElementById(`active-job-${jobId}`);
            if (jobEl && isCompleted && resultData && resultData.success) {
                jobEl.remove();
                this.loadActiveJobs(); // refresh list to hide container if empty
            }

            if (resultData && resultData.success) {
                this.handleJobCompleted(resultData);
            }

        } catch (error) {
            console.error('Polling error:', error);
            this.pollingJobs.delete(jobId);
        }
    }

    handleJobCompleted(resultData) {
        const outputDivInline = document.getElementById('transcription-result-inline');
        const outputContainerInline = document.getElementById('transcription-output-inline');

        if (!outputDivInline || !outputContainerInline) {
            console.error('Fehler: Anzeige-Elemente fehlen.');
            return;
        }

        const formattedHTML = this.app.processor.formatTranscriptionWithSpeakers(resultData.segments || [], resultData.text);
        outputDivInline.innerHTML = formattedHTML;

        const dropZone = document.getElementById('drop-zone');
        if (dropZone) dropZone.classList.add('hidden');
        
        outputContainerInline.classList.remove('hidden');

        document.querySelectorAll('.history-entry').forEach(e => e.classList.add('hidden'));

        this.app.state.currentTranscriptSegments = resultData.segments || [];
        this.app.state.currentTranscriptText = resultData.text || '';
        
        // Ensure switch to file view to show the result if we are somewhere else
        this.showTranscriptMode('file');
        
        this.app.state.activeSavePromise = this.app.service.saveTranscriptionToDatabase(
            resultData,
            this.app.state.selectedAudioFile || null
        )
            .then(savedTranscription => {
                this.app.history.saveTranscriptToHistory(resultData.text, savedTranscription.slug, savedTranscription.title, resultData.segments);
                this.app.state.currentTranscriptSlug = savedTranscription.slug;
                
                const inlineTitle = document.getElementById('current-transcript-title-inline');
                if (inlineTitle && savedTranscription.title) {
                    inlineTitle.textContent = savedTranscription.title;
                    inlineTitle.classList.remove('hidden');
                }

                this.app.service.pollForTitleUpdate(savedTranscription.slug, savedTranscription.title);
                this.app.history.renderHistory();
                
                this.app.history.loadTranscript(savedTranscription.slug, true);
                
                return savedTranscription;
            })
            .catch(err => {
                console.warn('DB-Save failed:', err);
                this.app.history.saveTranscriptToHistory(resultData.text, null, null, resultData.segments);
            })
            .finally(() => {
                this.app.state.activeSavePromise = null;
                this.updateSidebarSaveButtonState();
            });
    }
}
