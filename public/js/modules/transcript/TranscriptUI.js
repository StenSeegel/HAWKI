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
        const addGroupBtn = document.getElementById('add-group-btn');

        if (dropArea) {
            const globalDropArea = document.getElementById('transcript-file-ui') || dropArea;

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                globalDropArea.addEventListener(eventName, preventDefaults, false);
            });

            ['dragenter', 'dragover'].forEach(eventName => {
                globalDropArea.addEventListener(eventName, () => {
                    if (!this.app.state.isProcessing) {
                        dropArea.classList.add('highlight');
                    }
                }, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                globalDropArea.addEventListener(eventName, () => dropArea.classList.remove('highlight'), false);
            });

            globalDropArea.addEventListener('drop', (e) => {
                if (this.app.state.isProcessing) return;
                const dt = e.dataTransfer;
                if (dt.files && dt.files.length > 0) {
                    if (fileInput) fileInput.files = dt.files;
                    this.handleFileSelect(Array.from(dt.files));
                }
            }, false);

            dropArea.addEventListener('click', () => {
                if (fileInput) fileInput.click();
            });

            if (fileInput) {
                fileInput.addEventListener('change', (e) => {
                    if (e.target.files && e.target.files.length > 0) {
                        const targetGroupIndex = Number(fileInput.dataset.targetGroupIndex ?? '0');
                        this.handleFileSelect(Array.from(e.target.files), Number.isNaN(targetGroupIndex) ? 0 : targetGroupIndex);
                        delete fileInput.dataset.targetGroupIndex;
                        fileInput.value = '';
                    }
                });
            }
        }

        if (addGroupBtn) {
            addGroupBtn.addEventListener('click', () => this.addGroup());
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
                    while (walker.nextNode()) lastTextNode = walker.currentNode;
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
                if (!this.app.state.isProcessing) this.showIfExist('drop-zone');
                this.loadActiveJobs();
                
                const newBtn = document.getElementById('new-transcription-btn');
                if (newBtn) {
                    newBtn.innerHTML = `
                        <div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg></div>
                        <div class="label"><strong>Zurück</strong></div>
                    `;
                }
                
                this.renderMultiFileSelection();
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
        
        const newBtn = document.getElementById('new-transcription-btn');
        if (newBtn) {
            newBtn.innerHTML = `
                <div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg></div>
                <div class="label"><strong>Neue Transkription starten</strong></div>
            `;
        }
        
        if (!this.app.state.isProcessing) {
            this.resetUploadSelectionState();
        }
        document.querySelectorAll('#chats-list .selection-item').forEach(item => item.classList.remove('active'));
        const search = document.getElementById('history-search');
        if (search) search.value = '';
    }

    handleFileSelect(files, targetGroupIndex = 0) {
        if (this.app.state.isProcessing) return; // Disable drop interactions while processing
        if (!Array.isArray(files) || files.length === 0) return;
        const maxFileSize = 100 * 1024 * 1024;
        const validTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/m4a', 'video/mp4'];
        const accepted = [];

        for (const file of files) {
            if (!validTypes.includes(file.type) && !file.name.match(/\.(mp3|wav|m4a|mp4)$/i)) {
                alert(`Nicht unterstützt: ${file.name}`);
                continue;
            }
            if (file.size > maxFileSize) {
                alert(`Zu groß: ${file.name}`);
                continue;
            }
            accepted.push(file);
        }

        if (accepted.length === 0) {
            document.getElementById('audio_file').value = '';
            return;
        }

        this.ensureGroupsInitialized();
        if (!this.app.state.selectedFileGroups[targetGroupIndex]) {
            this.app.state.selectedFileGroups[targetGroupIndex] = { name: `Transcript ${targetGroupIndex + 1}`, files: [] };
        }

        const targetGroup = this.app.state.selectedFileGroups[targetGroupIndex];
        const existing = targetGroup.files || [];
        const existingKeys = new Set(existing.map(f => `${f.name}_${f.size}_${f.lastModified}`));
        accepted.forEach((file) => {
            const key = `${file.name}_${file.size}_${file.lastModified}`;
            if (!existingKeys.has(key)) {
                existing.push(file);
                existingKeys.add(key);
            }
        });
        targetGroup.files = existing;
        this.syncDerivedSelectedFiles();

        const sidebarPill = document.getElementById('sidebar-file-pill');
        const sidebarFileName = document.getElementById('sidebar-file-name');
        const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');

        if (sidebarFileName && this.app.state.selectedAudioFile) sidebarFileName.textContent = this.app.state.selectedAudioFile.name;
        if (sidebarPill) sidebarPill.classList.remove('hidden');
        if (sidebarPlaceholder) sidebarPlaceholder.classList.add('hidden');

        const audioElement = document.createElement('audio');
        audioElement.src = URL.createObjectURL(this.app.state.selectedAudioFile);
        audioElement.addEventListener('loadedmetadata', () => {
            const duration = audioElement.duration;
            const formattedDuration = Utils.formatSecondsToTime(duration);
            const endTime = document.getElementById('end-time');
            if (endTime) endTime.value = formattedDuration;
        });

        this.renderMultiFileSelection();
    }

    removeSelectedFile() {
        this.resetUploadSelectionState();
        this.renderMultiFileSelection();
    }

    resetUploadSelectionState() {
        this.app.state.selectedAudioFile = null;
        this.app.state.selectedAudioFiles = [];
        this.app.state.selectedFileGroups = [];

        const fileInput = document.getElementById('audio_file');
        if (fileInput) fileInput.value = '';

        const sidebarPill = document.getElementById('sidebar-file-pill');
        const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');
        if (sidebarPill) sidebarPill.classList.add('hidden');
        if (sidebarPlaceholder) sidebarPlaceholder.classList.remove('hidden');

        const endTime = document.getElementById('end-time');
        if (endTime) endTime.value = '';
    }

    removeFileFromSelection(index) {
        const files = this.app.state.selectedAudioFiles || [];
        files.splice(index, 1);
        this.app.state.selectedAudioFiles = files;
        this.app.state.selectedAudioFile = files[0] || null;

        const sidebarFileName = document.getElementById('sidebar-file-name');
        const sidebarPill = document.getElementById('sidebar-file-pill');
        const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');
        if (this.app.state.selectedAudioFile) {
            if (sidebarFileName) sidebarFileName.textContent = this.app.state.selectedAudioFile.name;
            if (sidebarPill) sidebarPill.classList.remove('hidden');
            if (sidebarPlaceholder) sidebarPlaceholder.classList.add('hidden');
        } else {
            if (sidebarPill) sidebarPill.classList.add('hidden');
            if (sidebarPlaceholder) sidebarPlaceholder.classList.remove('hidden');
        }

        this.renderMultiFileSelection();
    }

    addGroup() {
        this.ensureGroupsInitialized();
        const nextIndex = this.app.state.selectedFileGroups.length + 1;
        this.app.state.selectedFileGroups.push({
            name: `Transcript ${nextIndex}`,
            files: []
        });
        this.renderMultiFileSelection();
    }

    addFilesToGroup(groupIndex) {
        const fileInput = document.getElementById('audio_file');
        if (!fileInput) return;
        fileInput.dataset.targetGroupIndex = String(groupIndex);
        fileInput.click();
    }

    removeFileFromGroup(groupIndex, fileIndex) {
        const group = this.app.state.selectedFileGroups[groupIndex];
        if (!group) return;
        group.files.splice(fileIndex, 1);
        this.syncDerivedSelectedFiles();
        this.cleanupEmptyGroups();
        this.renderMultiFileSelection();
    }

    removeGroup(groupIndex) {
        if (!Array.isArray(this.app.state.selectedFileGroups)) return;
        this.app.state.selectedFileGroups.splice(groupIndex, 1);
        this.renumberGroups();
        this.syncDerivedSelectedFiles();
        this.renderMultiFileSelection();
    }

    ensureGroupsInitialized() {
        if (!Array.isArray(this.app.state.selectedFileGroups)) {
            this.app.state.selectedFileGroups = [];
        }
        if (this.app.state.selectedFileGroups.length === 0) {
            this.app.state.selectedFileGroups.push({ name: 'Transcript 1', files: [] });
        }
    }

    cleanupEmptyGroups() {
        const groups = this.app.state.selectedFileGroups || [];
        this.app.state.selectedFileGroups = groups.filter(group => Array.isArray(group.files) && group.files.length > 0);
        this.renumberGroups();
    }

    renumberGroups() {
        (this.app.state.selectedFileGroups || []).forEach((group, idx) => {
            if (!group.name || /^(Gruppe|Transkript|Transcript) \d+$/i.test(group.name.trim())) {
                group.name = `Transcript ${idx + 1}`;
            }
        });
    }

    syncDerivedSelectedFiles() {
        const groups = this.app.state.selectedFileGroups || [];
        const flat = groups.flatMap((group) => group.files || []);
        this.app.state.selectedAudioFiles = flat;
        this.app.state.selectedAudioFile = flat[0] || null;
    }

    getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        if (['mp4', 'mkv', 'avi', 'mov', 'webm'].includes(ext)) {
            return `
                <svg viewBox="0 0 24 24" fill="none" class="multi-upload-file-type-icon is-video">
                    <path d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14v-4z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M3 6a2 2 0 012-2h8a2 2 0 012 2v12a2 2 0 01-2 2H5a2 2 0 01-2-2V6z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            `;
        } else if (['m4a', 'aac', 'ogg'].includes(ext)) {
            return `
                <svg viewBox="0 0 24 24" fill="none" class="multi-upload-file-type-icon is-mic">
                    <path d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M19 10v1a7 7 0 01-14 0v-1M12 18v4M8 22h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            `;
        } else {
            return `
                <svg viewBox="0 0 24 24" fill="none" class="multi-upload-file-type-icon is-audio">
                    <path d="M9 18V5l12-2v13M9 9l12-2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="6" cy="18" r="3" stroke="currentColor" stroke-width="1.8"/>
                    <circle cx="18" cy="16" r="3" stroke="currentColor" stroke-width="1.8"/>
                </svg>
            `;
        }
    }

    updateFileProgress(progress, statusText, state = 'ready', groupIndex = 0, fileIndex = 0) {
        const item = document.querySelector(`[data-file-row="${groupIndex}:${fileIndex}"]`) || document.querySelector('.multi-upload-item');
        if (!item) return;

        const progressBar = item.querySelector('.multi-upload-progress-bar');
        const statusEl = item.querySelector('.multi-upload-status');

        let activeState = 'ready';
        if (state === true || state === 'processing') {
            activeState = 'processing';
        } else if (state === 'error') {
            activeState = 'error';
        } else if (state === 'success') {
            activeState = 'success';
        }

        if (progressBar) {
            progressBar.style.width = `${progress}%`;
            progressBar.classList.remove('is-processing', 'is-ready', 'is-error', 'is-success');
            progressBar.classList.add(`is-${activeState}`);
        }

        if (statusEl) {
            statusEl.textContent = statusText;
            statusEl.classList.remove('is-processing', 'is-ready', 'is-error', 'is-success');
            statusEl.classList.add(`is-${activeState}`);
        }

        if (activeState === 'success') {
            const actions = item.querySelector('.multi-upload-file-actions');
            if (actions) actions.style.display = 'none';
        }
    }

    uploadFileWithProgress(url, file, onProgress) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', url);

            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    const percentComplete = (event.loaded / event.total) * 100;
                    onProgress(percentComplete);
                }
            });

            xhr.addEventListener('load', () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve();
                } else {
                    reject(new Error(`Fehler beim Datei-Upload zu S3. Status: ${xhr.status}`));
                }
            });

            xhr.addEventListener('error', () => {
                reject(new Error('Fehler beim Datei-Upload zu S3 (Netzwerkfehler).'));
            });

            xhr.addEventListener('abort', () => {
                reject(new Error('Upload wurde abgebrochen.'));
            });

            xhr.send(file);
        });
    }

    renderMultiFileSelection() {
        const dropZone = document.getElementById('drop-zone');
        const multiPanel = document.getElementById('multi-file-panel');
        const multiList = document.getElementById('multi-file-list');
        const startWrap = document.getElementById('upload-start-center-wrap');
        const multiTitle = document.getElementById('multi-file-title');
        const totalSizeEl = document.getElementById('multi-file-total-size');
        if (!dropZone || !multiPanel || !multiList || !multiTitle || !totalSizeEl || !startWrap) return;

        const groups = this.app.state.selectedFileGroups || [];
        const files = groups.flatMap((group) => group.files || []);
        const hasFiles = files.length > 0;

        const section = document.querySelector('.transcript-section');
        if (section) {
            section.classList.toggle('has-queue', hasFiles);
        }

        dropZone.classList.remove('hidden');
        multiPanel.classList.toggle('hidden', !hasFiles);
        startWrap.classList.toggle('hidden', !hasFiles);

        const totalSizeMb = files.reduce((acc, file) => acc + file.size, 0) / (1024 * 1024);
        multiTitle.textContent = `Dateiliste (${files.length})`;
        totalSizeEl.textContent = `Dateigröße: ${totalSizeMb.toFixed(1)} MB gesamt`;

        multiList.innerHTML = '';
        groups.forEach((group, groupIndex) => {
            const isCompleted = group.processedTranscripts && group.processedTranscripts.length > 0;
            const groupWrap = document.createElement('div');
            groupWrap.className = 'multi-upload-group-block';
            groupWrap.innerHTML = `
                <div class="multi-upload-group-header">
                    <div class="multi-upload-group-title-wrap">
                        <span class="multi-upload-folder-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h4l1.6 1.8H18A2.5 2.5 0 0 1 20.5 9.3v7.2A2.5 2.5 0 0 1 18 19H6a2.5 2.5 0 0 1-2.5-2.5V7.5Z" stroke="currentColor" stroke-width="1.7"/>
                            </svg>
                        </span>
                        <span class="multi-upload-group-name-editable" contenteditable="true" data-group-name-index="${groupIndex}">${group.name}</span>
                    </div>
                    <div class="multi-upload-group-links" id="group-links-${groupIndex}"></div>
                    <div class="multi-upload-group-actions" ${isCompleted ? 'style="display: none;"' : ''}>
                        <button type="button" class="multi-upload-icon-btn" data-group-add="${groupIndex}" aria-label="Neues Transcript hinzufügen" title="Neues Transcript hinzufügen">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
                            </svg>
                        </button>
                        <button type="button" class="multi-upload-icon-btn" data-group-remove="${groupIndex}" aria-label="Transcript löschen" title="Transcript löschen">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M4.5 7.5h15M9.5 4.8h5M9 10.5v6.5M15 10.5v6.5M7.5 7.5l.7 10.2a2 2 0 0 0 2 1.8h3.6a2 2 0 0 0 2-1.8l.7-10.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
            `;

            const rowsHost = document.createElement('div');
            (group.files || []).forEach((file, fileIndex) => {
                const row = document.createElement('div');
                row.className = 'multi-upload-item';
                row.dataset.fileRow = `${groupIndex}:${fileIndex}`;
                const fileSizeMb = (file.size / (1024 * 1024)).toFixed(1);
                row.innerHTML = `
                    <div class="multi-upload-row">
                        <div class="multi-upload-file-main">
                            <span class="multi-upload-drag-handle" draggable="${isCompleted ? 'false' : 'true'}" data-drag-handle="${groupIndex}:${fileIndex}" aria-label="Datei verschieben" title="Datei verschieben" ${isCompleted ? 'style="display: none;"' : ''}>
                                <span></span><span></span><span></span>
                                <span></span><span></span><span></span>
                            </span>
                            ${this.getFileIcon(file.name)}
                            <div class="multi-upload-name-container">
                                <span class="multi-upload-name" title="${file.name}">${file.name}</span>
                                <div class="multi-upload-progress-container">
                                    <div class="multi-upload-progress-bar ${isCompleted ? 'is-success' : 'is-ready'}" style="width: ${isCompleted ? '100%' : '0%'};"></div>
                                </div>
                            </div>
                        </div>
                        <div class="multi-upload-meta">
                            <div class="multi-upload-status-wrap">
                                <span class="multi-upload-status ${isCompleted ? 'is-success' : 'is-ready'}">${isCompleted ? 'Fertig' : 'Bereit'}</span>
                                <span class="multi-upload-size">${fileSizeMb} MB</span>
                            </div>
                            <button type="button" class="multi-upload-icon-btn" data-file-remove="${groupIndex}:${fileIndex}" aria-label="Datei entfernen" title="Datei entfernen" ${isCompleted ? 'style="display: none;"' : ''}>
                                <svg viewBox="0 0 24 24" fill="none">
                                    <path d="M7 7l10 10M17 7L7 17" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                `;
                rowsHost.appendChild(row);
            });

            groupWrap.appendChild(rowsHost);
            rowsHost.className = 'multi-upload-group-files';
            rowsHost.dataset.groupDrop = String(groupIndex);
            multiList.appendChild(groupWrap);

            if (group.processedTranscripts && group.processedTranscripts.length > 0) {
                this.renderGroupTranscriptLinks(groupIndex);
            }
        });

        multiList.querySelectorAll('[data-group-add]').forEach((btn) => {
            btn.addEventListener('click', () => this.addGroup());
        });
        multiList.querySelectorAll('[data-group-remove]').forEach((btn) => {
            btn.addEventListener('click', () => this.removeGroup(Number(btn.dataset.groupRemove)));
        });
        multiList.querySelectorAll('[data-group-name-index]').forEach((nameEl) => {
            nameEl.addEventListener('input', () => {
                const index = Number(nameEl.dataset.groupNameIndex);
                if (Number.isNaN(index) || !this.app.state.selectedFileGroups[index]) return;
                const value = (nameEl.textContent || '').trim();
                this.app.state.selectedFileGroups[index].name = value !== '' ? value : `Transcript ${index + 1}`;
            });
            nameEl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    nameEl.blur();
                }
            });
            nameEl.addEventListener('blur', async () => {
                const index = Number(nameEl.dataset.groupNameIndex);
                if (Number.isNaN(index) || !this.app.state.selectedFileGroups[index]) return;
                let value = (nameEl.textContent || '').trim();
                if (!value) {
                    value = `Transcript ${index + 1}`;
                    nameEl.textContent = value;
                }
                this.app.state.selectedFileGroups[index].name = value;
                await this.syncGroupTranscriptionsTitle(index, value);
            });
        });
        multiList.querySelectorAll('[data-file-remove]').forEach((btn) => {
            const [groupIndex, fileIndex] = (btn.dataset.fileRemove || '0:0').split(':').map(Number);
            btn.addEventListener('click', () => this.removeFileFromGroup(groupIndex, fileIndex));
        });

        this.bindFileDragAndDrop(multiList);
    }

    bindFileDragAndDrop(multiList) {
        multiList.querySelectorAll('[data-drag-handle]').forEach((handle) => {
            handle.addEventListener('dragstart', (event) => {
                if (this.app.state.isProcessing) {
                    event.preventDefault();
                    return;
                }
                const [fromGroup, fromIndex] = (handle.dataset.dragHandle || '0:0').split(':').map(Number);
                const fromGroupObj = this.app.state.selectedFileGroups[fromGroup];
                if (fromGroupObj && fromGroupObj.processedTranscripts && fromGroupObj.processedTranscripts.length > 0) {
                    event.preventDefault();
                    return;
                }
                this.draggedFileRef = { fromGroup, fromIndex };
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', `${fromGroup}:${fromIndex}`);
                const row = handle.closest('.multi-upload-item');
                row?.classList.add('is-dragging');
            });

            handle.addEventListener('dragend', () => {
                this.draggedFileRef = null;
                multiList.querySelectorAll('.multi-upload-item').forEach((row) => row.classList.remove('is-dragging', 'drop-hover'));
                multiList.querySelectorAll('.multi-upload-group-files').forEach((group) => group.classList.remove('drop-hover-group'));
            });
        });

        multiList.querySelectorAll('[data-file-row]').forEach((row) => {
            row.addEventListener('dragover', (event) => {
                if (this.app.state.isProcessing) return;
                const [toGroup, toIndex] = (row.dataset.fileRow || '0:0').split(':').map(Number);
                const toGroupObj = this.app.state.selectedFileGroups[toGroup];
                if (toGroupObj && toGroupObj.processedTranscripts && toGroupObj.processedTranscripts.length > 0) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                row.classList.add('drop-hover');
            });
            row.addEventListener('dragleave', () => row.classList.remove('drop-hover'));
            row.addEventListener('drop', (event) => {
                if (this.app.state.isProcessing) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }
                const [toGroup, toIndex] = (row.dataset.fileRow || '0:0').split(':').map(Number);
                const toGroupObj = this.app.state.selectedFileGroups[toGroup];
                if (toGroupObj && toGroupObj.processedTranscripts && toGroupObj.processedTranscripts.length > 0) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                row.classList.remove('drop-hover');
                if (!this.draggedFileRef) {
                    // External file drop
                    if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0) {
                        this.handleFileSelect(Array.from(event.dataTransfer.files), toGroup);
                    }
                    return;
                }
                this.moveFile(this.draggedFileRef.fromGroup, this.draggedFileRef.fromIndex, toGroup, toIndex);
            });
        });

        multiList.querySelectorAll('[data-group-drop]').forEach((groupHost) => {
            groupHost.addEventListener('dragover', (event) => {
                if (this.app.state.isProcessing) return;
                const toGroup = Number(groupHost.dataset.groupDrop || '0');
                const toGroupObj = this.app.state.selectedFileGroups[toGroup];
                if (toGroupObj && toGroupObj.processedTranscripts && toGroupObj.processedTranscripts.length > 0) {
                    return;
                }
                event.preventDefault();
                groupHost.classList.add('drop-hover-group');
            });
            groupHost.addEventListener('dragleave', () => groupHost.classList.remove('drop-hover-group'));
            groupHost.addEventListener('drop', (event) => {
                if (this.app.state.isProcessing) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }
                const toGroup = Number(groupHost.dataset.groupDrop || '0');
                const toGroupObj = this.app.state.selectedFileGroups[toGroup];
                if (toGroupObj && toGroupObj.processedTranscripts && toGroupObj.processedTranscripts.length > 0) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                groupHost.classList.remove('drop-hover-group');
                if (!this.draggedFileRef) {
                    // External file drop
                    if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length > 0) {
                        this.handleFileSelect(Array.from(event.dataTransfer.files), toGroup);
                    }
                    return;
                }
                this.moveFile(this.draggedFileRef.fromGroup, this.draggedFileRef.fromIndex, toGroup, null);
            });
        });
    }

    moveFile(fromGroupIndex, fromFileIndex, toGroupIndex, toFileIndex = null) {
        const groups = this.app.state.selectedFileGroups || [];
        const fromGroup = groups[fromGroupIndex];
        const toGroup = groups[toGroupIndex];
        if (!fromGroup || !toGroup) return;
        if (fromGroup.processedTranscripts && fromGroup.processedTranscripts.length > 0) return;
        if (toGroup.processedTranscripts && toGroup.processedTranscripts.length > 0) return;
        if (!Array.isArray(fromGroup.files) || !Array.isArray(toGroup.files)) return;
        if (fromFileIndex < 0 || fromFileIndex >= fromGroup.files.length) return;

        const [movedFile] = fromGroup.files.splice(fromFileIndex, 1);
        if (!movedFile) return;

        let insertIndex = toFileIndex;
        if (insertIndex === null || Number.isNaN(insertIndex)) {
            insertIndex = toGroup.files.length;
        }
        if (insertIndex < 0) insertIndex = 0;
        if (insertIndex > toGroup.files.length) insertIndex = toGroup.files.length;

        toGroup.files.splice(insertIndex, 0, movedFile);
        this.syncDerivedSelectedFiles();
        this.renderMultiFileSelection();
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
        const groups = this.app.state.selectedFileGroups || [];
        const hasFiles = groups.some(g => g.files && g.files.length > 0);
        if (!hasFiles) {
            alert("Bitte füge zuerst mindestens eine Datei hinzu.");
            return;
        }

        this.app.state.isProcessing = true;

        const dropZoneContainer = document.querySelector('.drop-zone-container');
        const startBtn = document.getElementById('start-upload-btn');

        if (dropZoneContainer) dropZoneContainer.classList.add('hidden');

        if (startBtn) {
            startBtn.disabled = true;
            startBtn.style.opacity = '0.7';
            startBtn.style.cursor = 'not-allowed';
            startBtn.innerHTML = `
                <span class="start-btn-spinner" aria-hidden="true"></span>
                <span>Transkription läuft...</span>
            `;
        }

        document.body.classList.add('cursor-wait');

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        // Loop sequentially through every group
        for (let groupIndex = 0; groupIndex < groups.length; groupIndex++) {
            const group = groups[groupIndex];
            group.processedTranscripts = group.processedTranscripts || [];
            const files = group.files || [];

            if (files.length === 0) {
                continue;
            }

            // If the whole group has already been successfully processed, skip it
            if (group.processedTranscripts.length > 0) {
                continue;
            }

            const fileResults = [];
            let allFilesSuccessful = true;

            for (let fileIndex = 0; fileIndex < files.length; fileIndex++) {
                const file = files[fileIndex];

                try {
                    // 1. Create upload session
                    this.updateFileProgress(5, 'Wird verarbeitet...', 'processing', groupIndex, fileIndex);

                    const sessionResponse = await fetch('/req/transcription/async/session', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken
                        },
                        body: JSON.stringify({ filename: file.name })
                    });

                    const sessionData = await sessionResponse.json();
                    if (!sessionData.success) {
                        throw new Error('Konnte keine Upload-Session erstellen.');
                    }

                    const { job_id, upload_url } = sessionData.session;

                    // 2. Upload file directly to S3 with progress tracking
                    
                    await this.uploadFileWithProgress(upload_url, file, (percent) => {
                        const mappedProgress = Math.round(5 + (percent * 0.55)); // Maps 0-100% upload to 5%-60% progress
                        this.updateFileProgress(mappedProgress, 'Wird verarbeitet...', 'processing', groupIndex, fileIndex);
                    });

                    // 3. Dispatch Job
                    this.updateFileProgress(70, 'Wird verarbeitet...', 'processing', groupIndex, fileIndex);

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
                            this.updateFileProgress(100, 'Fertig', 'success', groupIndex, fileIndex);
                        } else if (statusData.status === 'transcribing') {
                            this.updateFileProgress(90, 'Wird verarbeitet...', 'processing', groupIndex, fileIndex);
                        } else if (statusData.status === 'preprocessed') {
                            this.updateFileProgress(80, 'Wird verarbeitet...', 'processing', groupIndex, fileIndex);
                        }
                    }

                    if (resultData && resultData.success) {
                        fileResults[fileIndex] = resultData;
                    } else {
                        throw new Error(resultData?.message || "Keine Antwort vom Server.");
                    }

                } catch (error) {
                    console.error(`Fehler bei Datei ${file.name}:`, error);
                    this.updateFileProgress(100, 'Fehlgeschlagen', 'error', groupIndex, fileIndex);
                    allFilesSuccessful = false;
                    break; // Abort processing for this group if any chunk fails
                }
            }

            // Merge and save all files in the group if all were successful
            if (allFilesSuccessful && fileResults.length === files.length) {
                let combinedSegments = [];
                let combinedWords = [];
                let accumulatedDuration = 0;
                let combinedTextParts = [];
                let combinedFileSize = 0;
                let originalFilenames = [];
                let firstModelUsed = null;
                let firstProvider = null;
                let firstLanguage = null;

                for (let fileIndex = 0; fileIndex < files.length; fileIndex++) {
                    const resultData = fileResults[fileIndex];
                    const file = files[fileIndex];

                    let chunkDuration = resultData.duration;
                    if (!chunkDuration && resultData.segments && resultData.segments.length > 0) {
                        chunkDuration = resultData.segments[resultData.segments.length - 1].end;
                    }
                    if (!chunkDuration) {
                        chunkDuration = 0;
                    }

                    // Shift segment times
                    const shiftedSegments = (resultData.segments || []).map(seg => ({
                        ...seg,
                        start: seg.start + accumulatedDuration,
                        end: seg.end + accumulatedDuration
                    }));
                    combinedSegments = combinedSegments.concat(shiftedSegments);

                    // Shift word times (if present)
                    if (resultData.words && Array.isArray(resultData.words)) {
                        const shiftedWords = resultData.words.map(w => ({
                            ...w,
                            start: w.start + accumulatedDuration,
                            end: w.end + accumulatedDuration
                        }));
                        combinedWords = combinedWords.concat(shiftedWords);
                    }

                    combinedTextParts.push(resultData.text || '');
                    combinedFileSize += file.size || 0;
                    originalFilenames.push(file.name);

                    if (fileIndex === 0) {
                        firstModelUsed = resultData.model_used || resultData.model;
                        firstProvider = resultData.provider;
                        firstLanguage = resultData.language;
                    }

                    accumulatedDuration += chunkDuration;
                }

                // Construct merged result data
                const mergedResultData = {
                    success: true,
                    text: combinedTextParts.filter(t => t.trim().length > 0).join(' '),
                    segments: combinedSegments,
                    words: combinedWords.length > 0 ? combinedWords : null,
                    duration: Math.round(accumulatedDuration),
                    language: firstLanguage || 'de',
                    model_used: firstModelUsed,
                    provider: firstProvider
                };

                const combinedFileMock = {
                    name: originalFilenames.join(', '),
                    size: combinedFileSize
                };

                try {
                    await this.saveProcessedFile(mergedResultData, combinedFileMock, groupIndex, files.length - 1);
                } catch (saveError) {
                    console.error('Error saving merged transcription:', saveError);
                }
            }
        }

        this.app.state.isProcessing = false;

        // Processing finished (all files checked)
        if (dropZoneContainer) dropZoneContainer.classList.remove('hidden');
        document.body.classList.remove('cursor-wait');

        // Restore button
        if (startBtn) {
            startBtn.disabled = false;
            startBtn.style.opacity = '';
            startBtn.style.cursor = '';
            startBtn.innerHTML = `
                <span class="upload-start-play-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M8.6 6.9L17 12L8.6 17.1V6.9Z" fill="currentColor"></path>
                    </svg>
                </span>
                <span>Transkription starten</span>
            `;
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

    async saveProcessedFile(resultData, file, groupIndex, fileIndex) {
        const group = this.app.state.selectedFileGroups[groupIndex];
        const customTitle = group ? group.name : null;

        return this.app.service.saveTranscriptionToDatabase(resultData, file, customTitle)
            .then(savedTranscription => {
                this.app.history.saveTranscriptToHistory(resultData.text, savedTranscription.slug, savedTranscription.title, resultData.segments);
                this.app.service.pollForTitleUpdate(savedTranscription.slug, savedTranscription.title);
                this.app.history.renderHistory();

                const group = this.app.state.selectedFileGroups[groupIndex];
                if (group) {
                    group.processedTranscripts = [
                        {
                            slug: savedTranscription.slug,
                            title: savedTranscription.title || file.name
                        }
                    ];
                    this.renderGroupTranscriptLinks(groupIndex);
                }

                return savedTranscription;
            })
            .catch(err => {
                console.warn('DB-Save failed:', err);
                this.app.history.saveTranscriptToHistory(resultData.text, null, null, resultData.segments);
            });
    }

    renderGroupTranscriptLinks(groupIndex) {
        const container = document.getElementById(`group-links-${groupIndex}`);
        if (!container) return;

        const group = this.app.state.selectedFileGroups[groupIndex];
        if (!group || !group.processedTranscripts || group.processedTranscripts.length === 0) return;

        container.innerHTML = '';

        // Hide group actions since processing is complete
        const header = container.closest('.multi-upload-group-header');
        if (header) {
            const actions = header.querySelector('.multi-upload-group-actions');
            if (actions) {
                actions.style.display = 'none';
            }
        }

        group.processedTranscripts.forEach((transcript) => {
            const link = document.createElement('button');
            link.type = 'button';
            link.className = 'group-transcript-open-btn';
            link.dataset.slug = transcript.slug;

            const displayTitle = transcript.title.length > 25 ? transcript.title.substring(0, 22) + '...' : transcript.title;

            link.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" class="link-icon">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <polyline points="10 9 9 9 8 9" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>${displayTitle} öffnen</span>
            `;

            link.addEventListener('click', () => {
                this.app.history.loadTranscript(transcript.slug, true);
            });

            container.appendChild(link);
        });
    }

    async syncGroupTranscriptionsTitle(groupIndex, newTitle) {
        const group = this.app.state.selectedFileGroups[groupIndex];
        if (!group || !group.processedTranscripts || group.processedTranscripts.length === 0) return;

        for (const transcript of group.processedTranscripts) {
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const response = await fetch(`/req/transcription/${transcript.slug}/title`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ title: newTitle })
                });
                const result = await response.json();
                if (result.success) {
                    transcript.title = newTitle;

                    let history = this.app.history.getLocalTranscriptionHistory();
                    const entry = history.find(e => e.slug === transcript.slug);
                    if (entry) {
                        entry.title = newTitle;
                        this.app.history.setLocalTranscriptionHistory(history);
                    }

                    if (this.app.state.currentTranscriptSlug === transcript.slug) {
                        const titleDiv = document.getElementById('current-transcript-title');
                        if (titleDiv) {
                            titleDiv.textContent = newTitle;
                        }
                    }
                }
            } catch (err) {
                console.error('Failed to sync transcription title on rename:', err);
            }
        }

        this.app.history.renderHistory();
        this.renderGroupTranscriptLinks(groupIndex);
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
        
        const group = this.app.state.selectedFileGroups[0];
        const customTitle = group ? group.name : null;

        this.app.state.activeSavePromise = this.app.service.saveTranscriptionToDatabase(
            resultData,
            this.app.state.selectedAudioFile || null,
            customTitle
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
