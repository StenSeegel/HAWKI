import { Utils } from './Utils.js';
import { CustomAudioPlayer } from './CustomAudioPlayer.js?v=1.0.24';
import { WaveformAudioPlayer } from './WaveformAudioPlayer.js?v=1.0.1';

// Maximum speaker snippet length in seconds. New samples are created at this
// length and the editor lets users shorten them, but never exceed it.
const SPEAKER_SNIPPET_SECONDS = 5;

export class TranscriptUI {
    constructor(app) {
        this.app = app;
        this.currentAudioPlayer = null;
        this.currentAudioBtn = null;
        this.currentAudioPlaceholder = null;
        this.audioUpdateHandler = null;
        this.pollingJobs = new Set();
        this.sidebarPlayers = new Map();
        this.activeSnippetPlayer = null;
        this.uploadWaveformPlayers = new Map(); // "group:file" -> WaveformAudioPlayer
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

        // Jump playhead or play/pause when clicking on a speaker avatar
        document.addEventListener('click', async (e) => {
            const avatar = e.target.closest('.speaker-avatar');
            if (avatar && (avatar.closest('#transcription-result') || avatar.closest('#transcription-result-container-edit'))) {
                const segment = avatar.closest('.transcript-segment');
                if (segment && this.app.globalAudioPlayer) {
                    const blockIdx = parseInt(segment.dataset.blockIdx, 10);
                    if (!isNaN(blockIdx) && this.app.globalAudioPlayer.segments) {
                        const seg = this.app.globalAudioPlayer.segments[blockIdx];
                        if (seg) {
                            if (this.app.state.editModeActive) {
                                const isCurrentSegment = this.app.globalAudioPlayer.playRange && 
                                                         this.app.globalAudioPlayer.playRange.start === seg.start && 
                                                         this.app.globalAudioPlayer.playRange.end === seg.end;
                                
                                if (isCurrentSegment && this.app.globalAudioPlayer.isPlaying) {
                                    this.app.globalAudioPlayer.pause();
                                } else {
                                    await this.app.globalAudioPlayer.seek(seg.start);
                                    await this.app.globalAudioPlayer.play();
                                }
                            } else {
                                const currentGlobalTime = this.app.globalAudioPlayer.getGlobalTime();
                                const isCurrentSegment = currentGlobalTime >= seg.start && currentGlobalTime <= seg.end;
                                if (isCurrentSegment && this.app.globalAudioPlayer.isPlaying) {
                                    this.app.globalAudioPlayer.pause();
                                } else {
                                    await this.app.globalAudioPlayer.seek(seg.start);
                                    await this.app.globalAudioPlayer.play();
                                }
                            }
                        }
                    }
                }
            }
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
        if (el) {
            console.log('Showing element:', id);
            el.classList.remove('hidden');
        } else {
            console.warn('Element NOT found for showing:', id);
        }
    }

    hideIfExist(id) {
        const el = document.getElementById(id);
        if (el) {
            console.log('Hiding element:', id);
            el.classList.add('hidden');
        }
    }

    switchTranscriptView(viewId) {
        console.log('switchTranscriptView called with:', viewId);
        if (viewId !== 'live' && this.app.state.liveTranscriptMaximized) {
            this.app.state.liveTranscriptMaximized = false;
            document.body.classList.remove('live-transcript-maximized-active');
        }

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
            'transcript-settings-footer-container',
            'live-record-sidebar-options',
            'live-transcript-sidebar-options'
        ];

        [...mainPanels, ...sidebarPanels].forEach(id => this.hideIfExist(id));

        // Ensure the main chat content panel is visible (it contains our transcript module)
        const chatPanel = document.getElementById('chat');
        if (chatPanel) {
            chatPanel.style.display = 'flex';
        }

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
                        <div class="label"><strong>${window.translation?.TranscriptBack ?? 'Zurück'}</strong></div>
                    `;
                }
                
                this.renderMultiFileSelection();
                break;
            case 'live':
                this.showIfExist('transcript-live-ui');
                this.showIfExist('live-record-sidebar-options');
                
                // Initialize audio devices if not already done
                if (this.app.liveTranscriptionManager) {
                    this.app.liveTranscriptionManager.initializeLiveAudioDevices();
                }

                const liveNewBtn = document.getElementById('new-transcription-btn');
                if (liveNewBtn) {
                    liveNewBtn.innerHTML = `
                        <div class="icon"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg></div>
                        <div class="label"><strong>${window.translation?.TranscriptBack ?? 'Zurück'}</strong></div>
                    `;
                }
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

        if (this.app.globalAudioPlayer) {
            this.app.globalAudioPlayer.playRange = null;
        }

        const globalPlayer = document.getElementById('global-audio-player');
        if (globalPlayer) {
            globalPlayer.classList.toggle('hidden-export', tabId === 'export');
        }
        this.moveSharedTabElements(tabId);

        if (tabId === 'korrekturen') {
            this.app.state.editModeActive = true;
            this.renderTranscriptArea();
        } else if (tabId === 'vorschau') {
            this.app.state.editModeActive = false;
            document.getElementById('transcript-selection-toolbar')?.remove();
            document.getElementById('custom-context-menu')?.classList.add('hidden');
            this.renderTranscriptArea();
        } else if (tabId === 'export') {
            this.app.state.editModeActive = false;
            document.getElementById('transcript-selection-toolbar')?.remove();
            document.getElementById('custom-context-menu')?.classList.add('hidden');
            if (this.app.exportManager) {
                this.app.exportManager.selectExportOption(this.app.state.exportType || 'summary');
            }
        }
    }

    // The global audio player, the sidebar toggle button and the speaker sidebar are
    // single DOM nodes shared between the Vorschau and Korrekturen tabs — relocate
    // them into the active tab instead of duplicating markup (IDs must stay unique).
    moveSharedTabElements(tabId) {
        if (tabId !== 'vorschau' && tabId !== 'korrekturen') return;

        const tabContent = document.getElementById(`tab-${tabId}`);
        if (!tabContent) return;

        const globalPlayer = document.getElementById('global-audio-player');
        const targetHeader = tabContent.querySelector('.transcript-view-header');
        if (globalPlayer && targetHeader) {
            targetHeader.insertAdjacentElement('afterend', globalPlayer);
        }

        const sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
        const actionsContainer = tabContent.querySelector('.header-actions-container');
        if (sidebarToggleBtn && actionsContainer) {
            actionsContainer.appendChild(sidebarToggleBtn);
        }

        const editorSidebar = document.getElementById('editor-tools-sidebar');
        if (editorSidebar) {
            tabContent.appendChild(editorSidebar);
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
                this.app.state.currentTranscriptSegments,
                this.app.state.currentTranscriptMetadata
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
            btn.innerHTML = `<div class="loader-spinner"></div> ${window.translation?.TranscriptSaving ?? 'Speichern...'}`;
        } else if (btn.classList.contains('saving')) {
            btn.classList.remove('saving');
            btn.classList.add('success');
            btn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> ${window.translation?.TranscriptSaved ?? 'Gespeichert'}`;

            setTimeout(() => {
                btn.classList.remove('success');
                btn.innerHTML = window.translation?.TranscriptSaveChanges ?? 'Änderungen speichern';
            }, 2000);
        }
    }

    showTranscriptMode(mode) {
        console.log('showTranscriptMode called with:', mode);
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
                <div class="label"><strong>${window.translation?.TranscriptStartNew ?? 'Neue Transkription starten'}</strong></div>
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
        const maxFileSize = 500 * 1024 * 1024;
        const validTypes = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/m4a', 'audio/ogg', 'video/mp4'];
        const accepted = [];

        for (const file of files) {
            if (!validTypes.includes(file.type) && !file.name.match(/\.(mp3|wav|m4a|mp4|ogg)$/i)) {
                alert(window.translation?.TranscriptUnsupportedFileAlert ?? "Wir unterstützen .mp3, .wav, .m4a und .ogg.\n\nMaximal 500MB pro Datei.");
                continue;
            }
            if (file.size > maxFileSize) {
                alert(window.translation?.TranscriptUnsupportedFileAlert ?? "Wir unterstützen .mp3, .wav, .m4a und .ogg.\n\nMaximal 500MB pro Datei.");
                continue;
            }
            accepted.push(file);
        }

        if (accepted.length === 0) {
            document.getElementById('audio_file').value = '';
            return;
        }

        this.ensureGroupsInitialized();
        
        // Special case: if a single file has a customName, we might want to use it as the group name
        if (files.length === 1 && files[0].customName) {
            // Find first empty group or create a new one
            let foundGroup = false;
            for (let i = 0; i < this.app.state.selectedFileGroups.length; i++) {
                if (this.app.state.selectedFileGroups[i].files.length === 0) {
                    targetGroupIndex = i;
                    this.app.state.selectedFileGroups[i].name = files[0].customName;
                    foundGroup = true;
                    break;
                }
            }
            if (!foundGroup) {
                targetGroupIndex = this.app.state.selectedFileGroups.length;
                this.app.state.selectedFileGroups.push({ name: files[0].customName, files: [] });
            }
        }

        if (!this.app.state.selectedFileGroups[targetGroupIndex]) {
            this.app.state.selectedFileGroups[targetGroupIndex] = { name: `Transcript ${targetGroupIndex + 1}`, files: [] };
        }

        const targetGroup = this.app.state.selectedFileGroups[targetGroupIndex];
        const existing = targetGroup.files || [];
        const existingKeys = new Set(existing.map(f => `${f.name}_${f.size}_${f.lastModified}`));
        const newFiles = [];
        accepted.forEach((file) => {
            const key = `${file.name}_${file.size}_${file.lastModified}`;
            if (!existingKeys.has(key)) {
                file._id = Math.random().toString(36).substr(2, 9);
                file.analysisStatus = 'idle';
                
                // Extract file duration from metadata
                const audio = document.createElement('audio');
                audio.src = URL.createObjectURL(file);
                audio.addEventListener('loadedmetadata', () => {
                    file.duration = audio.duration;
                    URL.revokeObjectURL(audio.src);
                });

                existing.push(file);
                existingKeys.add(key);
                newFiles.push(file);
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
        
        // Start auto-analysis for newly added files
        newFiles.forEach(file => {
            this.autoAnalyzeFile(file);
        });
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

    async removeFileFromGroup(groupIndex, fileIndex) {
        const group = this.app.state.selectedFileGroups[groupIndex];
        if (!group) return;

        const file = group.files[fileIndex];

        // Once a file has a job_id it exists server-side: removing it locally
        // only would make getActiveJobs() resurrect it on the next visit, so
        // confirm and cancel/delete the job on the server first.
        if (file && file.job_id && !file.transcriptionResult) {
            const message = (window.translation?.TranscriptConfirmDeleteJob ?? '"{name}" wirklich löschen? Der Transkriptions-Auftrag wird abgebrochen und die hochgeladene Datei entfernt.').replace('{name}', this.escapeHtml(file.name));
            if (!(await this.confirmDialog(message, (window.translation?.TranscriptDeleteJobTitle ?? 'Auftrag löschen')))) {
                return;
            }
            const deleted = await this.deleteJobOnServer(file.job_id);
            if (!deleted) {
                this.errorDialog((window.translation?.TranscriptDeleteJobFailed ?? 'Der Auftrag konnte nicht gelöscht werden. Bitte versuche es erneut.'));
                return;
            }
        }

        group.files.splice(fileIndex, 1);
        this.syncDerivedSelectedFiles();
        this.cleanupEmptyGroups();
        this.renderMultiFileSelection();
    }

    // The confirm-modal renders messages via innerHTML — escape anything
    // user-controlled (filenames) that gets interpolated into them.
    escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    /**
     * In-app confirmation via the global confirm-modal (layouts/home includes
     * it); falls back to the native dialog outside that layout — same idiom as
     * HistoryManager/ExportManager.
     */
    async confirmDialog(message, header = null) {
        if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
            return await window.openModal(window.ModalType.WARNING, message, header);
        }
        return confirm(message);
    }

    errorDialog(message, header = null) {
        header = header ?? (window.translation?.TranscriptError ?? 'Fehler');
        if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
            window.openModal(window.ModalType.ERROR, message, header);
            return;
        }
        alert(message);
    }

    async deleteJobOnServer(jobId) {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const response = await fetch(`/req/transcription/async/job/${jobId}`, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            });
            const data = await response.json();
            return !!data.success;
        } catch (error) {
            console.error(`Fehler beim Löschen des Jobs ${jobId}:`, error);
            return false;
        }
    }

    async removeGroup(groupIndex) {
        if (!Array.isArray(this.app.state.selectedFileGroups)) return;
        const group = this.app.state.selectedFileGroups[groupIndex];
        if (!group) return;

        // Server-side jobs in this group must be cancelled/deleted too,
        // otherwise getActiveJobs() resurrects them on the next visit.
        const jobFiles = (group.files || []).filter(f => f.job_id && !f.transcriptionResult);
        if (jobFiles.length > 0) {
            const names = jobFiles.map(f => `"${this.escapeHtml(f.name)}"`).join(', ');
            const message = (window.translation?.TranscriptConfirmDeleteGroup ?? 'Transcript mit {names} wirklich löschen? Laufende Transkriptions-Aufträge werden abgebrochen und die hochgeladenen Dateien entfernt.').replace('{names}', names);
            if (!(await this.confirmDialog(message, (window.translation?.TranscriptDeleteTranscriptGroup ?? 'Transcript löschen')))) {
                return;
            }
            const results = await Promise.all(jobFiles.map(f => this.deleteJobOnServer(f.job_id)));
            if (results.some(ok => !ok)) {
                this.errorDialog((window.translation?.TranscriptDeleteGroupFailed ?? 'Mindestens ein Auftrag konnte nicht gelöscht werden. Bitte versuche es erneut.'));
                return;
            }
        }

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

    /**
     * Auto-generated speaker labels arrive from the backend in German ("Stimme 1",
     * or "Sprecher 1" for transcripts analysed before the rename). Render those in
     * the active language; keep names a user actually typed untouched.
     */
    localizeAutoSpeakerLabel(label, idx) {
        const name = String(label || '').trim();
        if (name === '') return '';
        const auto = name.match(/^(?:Stimme|Voice|Sprecher(?:in)?|Speaker)\s*(\d+)?$/i);
        if (!auto) return name;
        const num = auto[1] || (idx + 1);
        return (window.translation?.TranscriptSpeakerN ?? 'Stimme {n}').replace('{n}', num);
    }

    getUnidentifiedSpeakersCount(file) {
        if (!file || !file.speakers) return 0;
        
        let unidentifiedCount = 0;
        file.speakers.forEach(sp => {
            // Check mapping first, then label, then id
            let mappedName = '';
            if (file.speakerMapping && file.speakerMapping[sp.id] !== undefined) {
                mappedName = file.speakerMapping[sp.id];
            } else {
                mappedName = sp.label || sp.id || '';
            }
            
            const name = String(mappedName).trim();
            const isDefaultName = /^(Stimme|Voice|Sprecher|Speaker|Sprecherin)\s*(\s+\d+)?$/i.test(name);
            
            if (name === '' || isDefaultName) {
                unidentifiedCount++;
            }
        });
        
        return unidentifiedCount;
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
                    reject(new Error((window.translation?.TranscriptS3UploadFailed ?? 'Fehler beim Datei-Upload zu S3. Status: {status}').replace('{status}', xhr.status)));
                }
            });

            xhr.addEventListener('error', () => {
                reject(new Error((window.translation?.TranscriptS3UploadNetworkError ?? 'Fehler beim Datei-Upload zu S3 (Netzwerkfehler).')));
            });

            xhr.addEventListener('abort', () => {
                reject(new Error((window.translation?.TranscriptUploadAborted ?? 'Upload wurde abgebrochen.')));
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
        multiTitle.textContent = `${window.translation?.TranscriptFileListTitle ?? 'Dateiliste'} (${files.length})`;
        totalSizeEl.textContent = (window.translation?.TranscriptTotalFileSize ?? 'Dateigröße: {size} MB gesamt').replace('{size}', totalSizeMb.toFixed(1));

        this.uploadWaveformPlayers.forEach(player => player.destroy());
        this.uploadWaveformPlayers.clear();

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
                        <button type="button" class="multi-upload-icon-btn" data-group-add="${groupIndex}" aria-label="${window.translation?.TranscriptAddTranscript ?? 'Neues Transcript hinzufügen'}" title="${window.translation?.TranscriptAddTranscript ?? 'Neues Transcript hinzufügen'}">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
                            </svg>
                        </button>
                        <button type="button" class="multi-upload-icon-btn" data-group-remove="${groupIndex}" aria-label="${window.translation?.TranscriptDeleteTranscriptGroup ?? 'Transcript löschen'}" title="${window.translation?.TranscriptDeleteTranscriptGroup ?? 'Transcript löschen'}">
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
                row.dataset.fileId = file._id || '';
                const hasResult = isCompleted || !!file.transcriptionResult;
                // Restore in-flight/ready progress from the file itself so rebuilding this
                // row (triggered by unrelated list changes) doesn't snap the bar back to 0%.
                const progressPercent = hasResult ? 100 : (typeof file._progressPercent === 'number' ? file._progressPercent : 0);
                const progressState = hasResult ? 'success' : (file._progressState || 'ready');
                const statusLabel = hasResult
                    ? (window.translation?.TranscriptDone ?? 'Fertig')
                    : (file._progressText || (window.translation?.TranscriptReady ?? 'Bereit'));
                row.innerHTML = `
                    <div class="multi-upload-row">
                        <div class="multi-upload-file-main">
                            <span class="multi-upload-drag-handle" draggable="${hasResult ? 'false' : 'true'}" data-drag-handle="${groupIndex}:${fileIndex}" aria-label="${window.translation?.TranscriptMoveFile ?? 'Datei verschieben'}" title="${window.translation?.TranscriptMoveFile ?? 'Datei verschieben'}" ${hasResult ? 'style="display: none;"' : ''}>
                                <span></span><span></span><span></span>
                                <span></span><span></span><span></span>
                            </span>
                            <div class="multi-upload-name-container">
                                <div class="multi-upload-player-slot" data-player-slot="${groupIndex}:${fileIndex}"></div>
                                <div class="multi-upload-progress-container">
                                    <div class="multi-upload-progress-track">
                                        <div class="multi-upload-progress-bar is-${progressState}" style="width: ${progressPercent}%;"></div>
                                    </div>
                                    <div class="multi-upload-status-wrap">
                                        <span class="multi-upload-status is-${progressState}">${statusLabel}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="multi-upload-meta">
                            <div class="multi-upload-file-actions">
                                ${!hasResult && file.analysisStatus === 'ready' ? `
                                <button type="button" class="multi-upload-icon-btn open-speaker-btn ${file.speakersSaved ? 'is-saved' : ''}" data-speaker-mapping="${groupIndex}:${fileIndex}" title="${window.translation?.TranscriptAdjustSpeakers ?? 'Sprecher anpassen'}">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users-icon lucide-users">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                        <path d="M16 3.128a4 4 0 0 1 0 7.744"/>
                                        <path d="M22 21v-2a4 4 0 0 0-3-3.87"/>
                                        <circle cx="9" cy="7" r="4"/>
                                    </svg>
                                    ${this.getUnidentifiedSpeakersCount(file) > 0 ? `
                                    <span class="speaker-badge">${this.getUnidentifiedSpeakersCount(file)}</span>
                                    ` : ''}
                                </button>
                                ` : ''}
                                <button type="button" class="multi-upload-icon-btn" data-file-remove="${groupIndex}:${fileIndex}" aria-label="${window.translation?.TranscriptRemoveFile ?? 'Datei entfernen'}" title="${window.translation?.TranscriptRemoveFile ?? 'Datei entfernen'}" ${hasResult ? 'style="display: none;"' : ''}>
                                    <svg viewBox="0 0 24 24" fill="none">
                                        <path d="M7 7l10 10M17 7L7 17" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
                                    </svg>
                                </button>
                            </div>
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
        multiList.querySelectorAll('[data-speaker-mapping]').forEach((btn) => {
            btn.addEventListener('click', (e) => {
                const speakerMappingBtn = e.target.closest('.open-speaker-btn');
                if (speakerMappingBtn) {
                    const [groupIndex, fileIndex] = speakerMappingBtn.dataset.speakerMapping.split(':').map(Number);
                    const file = this.app.state.selectedFileGroups[groupIndex].files[fileIndex];
                    file.groupIndex = groupIndex;
                    file.fileIndex = fileIndex;
                    this.openSpeakerMappingModal(file);
                }
            });
        });

        multiList.querySelectorAll('[data-player-slot]').forEach((slot) => {
            const [groupIndex, fileIndex] = slot.dataset.playerSlot.split(':').map(Number);
            const file = groups[groupIndex]?.files?.[fileIndex];
            if (!(file instanceof File)) return;
            this.uploadWaveformPlayers.set(
                `${groupIndex}:${fileIndex}`,
                new WaveformAudioPlayer({ container: slot, file })
            );
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

        const speakerLabel = block.querySelector('.speaker-label')?.textContent || (window.translation?.TranscriptSpeaker ?? 'Sprecher');
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

        let playStart = start;
        let playEnd = end;

        const metadata = this.app.state.currentTranscriptMetadata;
        let sourceFiles = metadata && metadata.source_files;

        let targetJobId = null;
        let fileIndex = 0;

        if (sourceFiles && Array.isArray(sourceFiles) && sourceFiles.length > 0) {
            // Find the source file covering this start time
            let matchedSrcFile = null;
            for (let i = 0; i < sourceFiles.length; i++) {
                const sf = sourceFiles[i];
                if (start >= sf.start_time && start < sf.end_time) {
                    matchedSrcFile = sf;
                    fileIndex = i;
                    break;
                }
            }
            if (!matchedSrcFile) {
                matchedSrcFile = sourceFiles[sourceFiles.length - 1];
                fileIndex = sourceFiles.length - 1;
            }

            if (matchedSrcFile) {
                targetJobId = matchedSrcFile.job_id || null;
                playStart = Math.max(0, start - matchedSrcFile.start_time);
                playEnd = Math.max(0, end - matchedSrcFile.start_time);
            }
        }

        // Initialize Custom Audio Player in snippet mode (Modus 2)
        this.currentAudioBtn = btn;
        this.currentAudioPlaceholder = playerPlaceholder;
        btn.innerHTML = stopIcon;

        this.activeSnippetPlayer = new CustomAudioPlayer({
            container: playerPlaceholder,
            mode: 'snippet',
            slug: this.app.state.currentTranscriptSlug,
            jobId: targetJobId,
            fileIndex: fileIndex,
            start: playStart,
            end: playEnd
        });

        this.activeSnippetPlayer.play();
    }

    stopCurrentAudio() {
        const playIcon = `<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor">
                            <path d="M8.25 3.75L4.5 6.75H1.5V11.25H4.5L8.25 14.25V3.75Z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M14.3018 3.69727C15.7078 5.10372 16.4977 7.01103 16.4977 8.99977C16.4977 10.9885 15.7078 12.8958 14.3018 14.3023M11.6543 6.34477C12.3573 7.04799 12.7522 8.00165 12.7522 8.99602C12.7522 9.99038 12.3573 10.944 11.6543 11.6473" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>`;

        if (this.activeSnippetPlayer) {
            this.activeSnippetPlayer.destroy();
            this.activeSnippetPlayer = null;
        }

        if (this.app.globalAudioPlayer) {
            this.app.globalAudioPlayer.pause();
        }

        if (this.sidebarPlayers) {
            this.sidebarPlayers.forEach(p => p.destroy());
            this.sidebarPlayers.clear();
        }

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


    initGlobalAudioPlayer() {
        const placeholder = document.getElementById('global-audio-player');
        if (!placeholder) return;

        // Move the shared elements (player, sidebar toggle, speaker sidebar) into the active tab
        const activeTabId = this.app.state.editModeActive ? 'korrekturen' : 'vorschau';
        this.moveSharedTabElements(activeTabId);

        const metadata = this.app.state.currentTranscriptMetadata;
        const slug = this.app.state.currentTranscriptSlug;
        const segments = this.app.state.currentTranscriptSegments;

        if (!segments || segments.length === 0) {
            placeholder.innerHTML = window.translation?.TranscriptNoTranscriptLoaded ?? 'Keine Transkription geladen';
            return;
        }

        // Build source files
        let audioSources = metadata?.source_files;
        if (!audioSources) {
            const filename = this.app.state.selectedAudioFile?.name || 'Audio.mp3';
            const size = this.app.state.selectedAudioFile?.size || 0;
            const duration = this.app.state.currentTranscriptSegments.length > 0
                ? this.app.state.currentTranscriptSegments[this.app.state.currentTranscriptSegments.length - 1].end
                : 300;

            audioSources = [{
                name: filename,
                size: size,
                duration: duration,
                start_time: 0,
                end_time: duration,
                job_id: metadata?.job_id || null
            }];
        }

        // Build segments list for timeline
        const timelineSegments = [];
        const speakerBlocks = this.app.state.lastRenderedSpeakerBlocks || [];
        speakerBlocks.forEach((block, idx) => {
            const start = block.startTime;
            const end = segments[block.segmentIndices[block.segmentIndices.length - 1]].end;
            timelineSegments.push({
                start: start,
                end: end,
                speaker: block.speakerName,
                colorId: block.colorId,
                text: block.text
            });
        });

        if (this.app.globalAudioPlayer) {
            if (this.app.globalAudioPlayer.slug === slug) {
                this.app.globalAudioPlayer.segments = timelineSegments;
                this.app.globalAudioPlayer.renderGlobalSegments();
                return;
            }
            this.app.globalAudioPlayer.destroy();
            this.app.globalAudioPlayer = null;
        }

        let lastActiveBlockIdx = -1;

        this.app.globalAudioPlayer = new CustomAudioPlayer({
            container: placeholder,
            mode: 'global',
            slug: slug,
            audioSources: audioSources,
            segments: timelineSegments,
            onPlay: () => {
                const previewResult = document.getElementById('transcription-result');
                if (previewResult) {
                    previewResult.classList.add('audio-is-playing');
                }
                const editResult = document.getElementById('transcription-result-container-edit');
                if (editResult) {
                    editResult.classList.add('audio-is-playing');
                }
            },
            onPause: () => {
                const previewResult = document.getElementById('transcription-result');
                if (previewResult) {
                    previewResult.classList.remove('audio-is-playing');
                }
                const editResult = document.getElementById('transcription-result-container-edit');
                if (editResult) {
                    editResult.classList.remove('audio-is-playing');
                }
            },
            onTimeUpdate: (globalTime) => {
                // Highlight active segment block in transcript
                const activeBlock = timelineSegments.find(s => globalTime >= s.start && globalTime < s.end);
                const blockIdx = activeBlock ? timelineSegments.indexOf(activeBlock) : -1;

                if (blockIdx !== lastActiveBlockIdx) {
                    lastActiveBlockIdx = blockIdx;

                    document.querySelectorAll('.transcript-segment').forEach(el => {
                        el.classList.remove('active-playing-segment');
                        el.classList.remove('active-selection');
                        el.classList.remove('speaker-highlighted');
                    });
                    
                    document.querySelectorAll('.player-segment').forEach(el => {
                        el.classList.remove('is-highlighted');
                    });

                    if (activeBlock) {
                        const previewSegEl = document.querySelector(`#transcription-result .transcript-segment[data-block-idx="${blockIdx}"]`);
                        if (previewSegEl) {
                            previewSegEl.classList.add('active-playing-segment');
                            previewSegEl.classList.add('active-selection');
                            previewSegEl.classList.add('speaker-highlighted');
                            if (!window.app?.state?.editModeActive) {
                                previewSegEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }
                        
                        const editSegEl = document.querySelector(`#transcription-result-container-edit .transcript-segment[data-block-idx="${blockIdx}"]`);
                        if (editSegEl) {
                            editSegEl.classList.add('active-playing-segment');
                            editSegEl.classList.add('active-selection');
                            editSegEl.classList.add('speaker-highlighted');
                            if (window.app?.state?.editModeActive) {
                                editSegEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }
                        
                        const playerSegEl = placeholder.querySelectorAll('.player-segment')[blockIdx];
                        if (playerSegEl) {
                            playerSegEl.classList.add('is-highlighted');
                        }
                    }
                }
            },
            onSegmentClick: (seg) => {
                const blockIdx = timelineSegments.indexOf(seg);
                const containerId = window.app?.state?.editModeActive ? 'transcription-result-container-edit' : 'transcription-result';
                const selector = `#${containerId} .transcript-segment[data-block-idx="${blockIdx}"]`;
                const transcriptSegEl = document.querySelector(selector);
                if (transcriptSegEl) {
                    transcriptSegEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
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

    async openSpeakerMappingModal(file) {
        if (!file || !file.speakers) return;

        const modal = document.getElementById('speaker-mapping-modal');
        const modalContent = document.getElementById('speaker-mapping-modal-content');
        if (!modal || !modalContent) return;

        modal.style.display = 'flex';

        // Speaker audio_urls are presigned for a limited time at analysis —
        // for restored jobs (or a speaker check left open long enough) they
        // are expired and every snippet play would 403.
        await this.refreshSpeakerAudioUrls(file);
        
        // Store indices for precise badge updates
        modal.dataset.groupIndex = file.groupIndex;
        modal.dataset.fileIndex = file.fileIndex;

        // Initialize file.speakerMapping if not present
        if (!file.speakerMapping) {
            file.speakerMapping = {};
            file.speakers.forEach((sp, idx) => {
                file.speakerMapping[sp.id] = this.localizeAutoSpeakerLabel(sp.label, idx);
            });
        }

        // Sort speakers chronologically
        if (file.speakers) {
            file.speakers.sort((a, b) => a.start - b.start);
        }

        // Format seconds to mm:ss
        const formatTime = (seconds) => {
            if (isNaN(seconds) || seconds === null || seconds === undefined) return '';
            const m = Math.floor(seconds / 60);
            const s = Math.floor(seconds % 60);
            return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
        };

        // Parse mm:ss to seconds
        const parseTime = (timeStr) => {
            if (!timeStr) return 0;
            const parts = timeStr.toString().split(':');
            if (parts.length === 2) {
                return parseInt(parts[0]) * 60 + parseFloat(parts[1].replace(',', '.'));
            }
            return parseFloat(timeStr.toString().replace(',', '.'));
        };

        // Ensure all samples have persistent labels
        file.speakers.forEach(sp => {
            if (!sp.samples) sp.samples = [];
            sp.samples.forEach((samp, sIdx) => {
                if (!samp.label) {
                    samp.label = (window.translation?.TranscriptSampleN ?? 'Beispiel {n}').replace('{n}', sIdx + 1);
                }
            });
        });

        modalContent.innerHTML = `
            <div class="speaker-mapping-header" style="margin-bottom: 24px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;">
                <div>
                    <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #1e293b;">${window.translation?.TranscriptAdjustSpeakers ?? 'Sprecher anpassen'}</h3>
                    <p style="margin: 4px 0 0; color: #64748b; font-size: 14px;">${file.name}</p>
                </div>
                <button type="button" class="btn-lg-stroke retry-speaker-analysis-btn" style="flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px;" title="${window.translation?.TranscriptRerunSpeakerAnalysis ?? 'Sprecheranalyse erneut ausführen'}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-refresh-cw"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
                    <span class="retry-speaker-analysis-label">${window.translation?.TranscriptRepeatAnalysis ?? 'Analyse wiederholen'}</span>
                </button>
            </div>
            <div class="speaker-mapping-list">
                ${file.speakers.map((sp, idx) => {
                    file.speakerMapping[sp.id] = this.localizeAutoSpeakerLabel(file.speakerMapping[sp.id] ?? sp.label, idx);
                    return `
                    <div class="speaker-mapping-card" data-speaker-id="${sp.id}">
                        <div class="speaker-card-header" style="align-items: center;">
                            ${(() => {
                                const speakerName = file.speakerMapping[sp.id] || (window.translation?.TranscriptSpeakerN ?? 'Stimme {n}').replace('{n}', idx + 1);
                                const colorInfo = (this.app.state.speakerColorMap && this.app.state.speakerColorMap.get(speakerName));
                                const colorId = colorInfo ? colorInfo.colorId : (idx % 10) + 1;
                                return `<div class="speaker-avatar speaker-color-${colorId}" title="${window.translation?.TranscriptChangeColor ?? 'Farbe ändern'}"></div>`;
                            })()}
                            <div class="speaker-input-wrapper">
                                <span class="speaker-input-label">${(window.translation?.TranscriptSpeakerN ?? 'Stimme {n}').replace('{n}', idx + 1)}</span>
                                <input type="text" class="speaker-mapping-input" 
                                    data-speaker-id="${sp.id}" 
                                    value="${file.speakerMapping[sp.id] || ''}" 
                                    placeholder="${window.translation?.TranscriptEnterNamePlaceholder ?? 'Name eingeben...'}">
                            </div>
                            <div class="speaker-delete-container">
                                <button type="button" class="remove-speaker-btn" data-speaker-id="${sp.id}" title="${window.translation?.TranscriptRemoveSpeaker ?? 'Sprecher entfernen'}">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash-2"><path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                                <div class="confirm-btns-group speaker-confirm-group" style="display: none;">
                                    <button type="button" class="btn-cancel cancel-speaker-remove" style="color: #94a3b8;" title="${window.translation?.TranscriptCancel ?? 'Abbrechen'}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                    </button>
                                    <button type="button" class="btn-confirm confirm-speaker-remove" style="color: #ef4444;" data-speaker-id="${sp.id}" title="${window.translation?.TranscriptConfirm ?? 'Bestätigen'}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check"><path d="M20 6 9 17l-5-5"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="snippet-chip-row" data-speaker-id="${sp.id}">
                            ${(sp.samples || []).map((samp, sIdx) => `
                                <button type="button" class="snippet-chip" data-speaker-id="${sp.id}" data-sample-idx="${sIdx}">
                                    <div class="playing-animation">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-volume-2">
                                            <path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/>
                                            <path class="arc-1" d="M16 9a5 5 0 0 1 0 6"/>
                                            <path class="arc-2" d="M19.364 18.364a9 9 0 0 0 0-12.728"/>
                                        </svg>
                                    </div>
                                    <span class="chip-label">${samp.label || (window.translation?.TranscriptSampleN ?? 'Beispiel {n}').replace('{n}', sIdx + 1)}</span>
                                    <span class="chip-edit-trigger" title="${window.translation?.TranscriptEdit ?? 'Bearbeiten'}" data-speaker-id="${sp.id}" data-sample-idx="${sIdx}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pencil"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                    </span>
                                </button>
                            `).join('')}
                            <button type="button" class="snippet-chip add-snippet-chip" data-speaker-id="${sp.id}" title="${window.translation?.TranscriptAddSnippet ?? 'Snippet hinzufügen'}">+</button>
                        </div>
                        
                        <div class="snippet-editor-panel" style="display: none;">
                            <div class="editor-panel-content">
                                <div class="speaker-player-container" data-speaker-id="${sp.id}"></div>
                                <div class="delete-action-wrapper">
                                    <button type="button" class="delete-snippet-btn" data-speaker-id="${sp.id}" title="${window.translation?.TranscriptDeleteSnippet ?? 'Snippet löschen'}">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash-2"><path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                    <div class="confirm-btns-group snippet-confirm-group" style="display: none;">
                                        <button type="button" class="btn-cancel cancel-snippet-delete" style="color: #94a3b8;" title="${window.translation?.TranscriptCancel ?? 'Abbrechen'}">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                        </button>
                                        <button type="button" class="btn-confirm confirm-snippet-delete" style="color: #ef4444;" data-speaker-id="${sp.id}" title="${window.translation?.TranscriptConfirm ?? 'Bestätigen'}">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check"><path d="M20 6 9 17l-5-5"/></svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                }).join('')}
            </div>
            
            <button type="button" class="add-speaker-card-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-plus"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                ${window.translation?.TranscriptAddSpeaker ?? 'Sprecher hinzufügen'}
            </button>
        `;

        // Interaction logic for chips and editor
        const initPlayer = (spId, start, end) => {
            const sp = file.speakers.find(s => s.id === spId);
            const container = modalContent.querySelector(`.speaker-player-container[data-speaker-id="${spId}"]`);
            if (!sp || !container) return;

            // Cleanup old player
            if (this.sidebarPlayers.has(spId)) {
                this.sidebarPlayers.get(spId).destroy();
            }

            const directUrl = sp.audio_url.split('#')[0];
            const player = new CustomAudioPlayer({
                container: container,
                mode: 'editor',
                directUrl: directUrl,
                start: start,
                end: end,
                speakerId: spId,
                fileDuration: file.duration || 0,
                maxWindowLength: SPEAKER_SNIPPET_SECONDS,
                onPlay: () => {
                    const activeChip = modalContent.querySelector(`.snippet-chip.active[data-speaker-id="${spId}"]`);
                    if (activeChip) activeChip.classList.add('is-playing');
                },
                onPause: () => {
                    const activeChip = modalContent.querySelector(`.snippet-chip.active[data-speaker-id="${spId}"]`);
                    if (activeChip) activeChip.classList.remove('is-playing');
                },
                onRangeChange: (newStart, newEnd) => {
                    const activeChip = modalContent.querySelector(`.snippet-chip.active[data-speaker-id="${spId}"]`);
                    if (activeChip) {
                        const sIdx = parseInt(activeChip.dataset.sampleIdx);
                        if (sp.samples && sp.samples[sIdx]) {
                            sp.samples[sIdx].start = newStart;
                            sp.samples[sIdx].end = newEnd;
                        }
                    }
                }
            });
            this.sidebarPlayers.set(spId, player);
        };

        const toggleEditor = (spId, sampleIdx = null) => {
            // Stop any background preview when opening/toggling editor
            if (this.currentPreviewAudio) {
                this.currentPreviewAudio.pause();
                this.currentPreviewAudio = null;
                if (this.currentPlayingChip) {
                    this.currentPlayingChip.classList.remove('is-playing');
                }
                this.currentPlayingChip = null;
            }

            const card = modalContent.querySelector(`.speaker-mapping-card[data-speaker-id="${spId}"]`);
            const panel = card.querySelector('.snippet-editor-panel');
            const chips = card.querySelectorAll('.snippet-chip:not(.add-snippet-chip)');
            const sp = file.speakers.find(s => s.id === spId);

            // The editor panel is reused for every snippet of this speaker —
            // reset a pending delete confirmation on every open/close/switch,
            // otherwise the confirm buttons leak into the next snippet shown
            // (e.g. right after confirming a deletion).
            const deleteWrapper = panel.querySelector('.delete-action-wrapper');
            if (deleteWrapper) {
                const trashBtn = deleteWrapper.querySelector('.delete-snippet-btn');
                const confirmGroup = deleteWrapper.querySelector('.confirm-btns-group');
                if (trashBtn) trashBtn.style.display = 'flex';
                if (confirmGroup) confirmGroup.style.display = 'none';
            }

            if (sampleIdx === null) {
                // Close
                panel.style.display = 'none';
                chips.forEach(c => c.classList.remove('active'));
                if (this.sidebarPlayers.has(spId)) {
                    this.sidebarPlayers.get(spId).destroy();
                    this.sidebarPlayers.delete(spId);
                }
                return;
            }

            const isAlreadyActive = chips[sampleIdx].classList.contains('active');
            chips.forEach(c => c.classList.remove('active'));

            if (isAlreadyActive) {
                panel.style.display = 'none';
                if (this.sidebarPlayers.has(spId)) {
                    this.sidebarPlayers.get(spId).destroy();
                    this.sidebarPlayers.delete(spId);
                }
            } else {
                chips[sampleIdx].classList.add('active');
                panel.style.display = 'block';
                const sample = sp.samples[sampleIdx];
                initPlayer(spId, sample.start, sample.end);
            }
        };

        modalContent.onclick = (e) => {
            const retryBtn = e.target.closest('.retry-speaker-analysis-btn');
            if (retryBtn) {
                this.retrySpeakerAnalysis(file, retryBtn);
                return;
            }

            const avatar = e.target.closest('.speaker-avatar');
            if (avatar) {
                const card = avatar.closest('.speaker-mapping-card');
                const speakerId = card.dataset.speakerId;
                const input = card.querySelector('.speaker-mapping-input');
                const speakerName = input.value.trim() || (window.translation?.TranscriptSpeakerN ?? 'Stimme {n}').replace('{n}', Array.from(modalContent.querySelectorAll('.speaker-mapping-card')).indexOf(card) + 1);
                
                const gIdx = parseInt(modal.dataset.groupIndex);
                const fIdx = parseInt(modal.dataset.fileIndex);
                
                this.showAvatarPicker(avatar, speakerId, speakerName, gIdx, fIdx, saveCurrentInputs);
                return;
            }

            const editTrigger = e.target.closest('.chip-edit-trigger');
            if (editTrigger) {
                const spId = editTrigger.dataset.speakerId;
                const sIdx = parseInt(editTrigger.dataset.sampleIdx);
                toggleEditor(spId, sIdx);
                return;
            }

            const chip = e.target.closest('.snippet-chip:not(.add-snippet-chip)');
            if (chip) {
                const spId = chip.dataset.speakerId;
                const sIdx = parseInt(chip.dataset.sampleIdx);
                
                // Close any open editor panel when playing a chip
                modalContent.querySelectorAll('.snippet-editor-panel').forEach(p => p.style.display = 'none');
                modalContent.querySelectorAll('.snippet-chip.active').forEach(c => c.classList.remove('active'));
                if (this.sidebarPlayers) {
                    this.sidebarPlayers.forEach(p => p.destroy());
                    this.sidebarPlayers.clear();
                }

                this.playSnippet(spId, sIdx, file, chip);
                return;
            }

            const addBtn = e.target.closest('.add-snippet-chip');
            if (addBtn) {
                const spId = addBtn.dataset.speakerId;
                const sp = file.speakers.find(s => s.id === spId);
                if (sp) {
                    const maxLabel = sp.samples.reduce((max, s) => {
                        const num = parseInt(((s.label || '').match(/(\d+)\s*$/) || [])[1]);
                        return Math.max(max, isNaN(num) ? 0 : num);
                    }, 0);
                    const label = (window.translation?.TranscriptSampleN ?? 'Beispiel {n}').replace('{n}', maxLabel + 1);
                    const lastEnd = sp.samples.length > 0 ? sp.samples[sp.samples.length - 1].end : 0;
                    const start = Math.min(file.duration || 1000, lastEnd + 2);
                    const end = Math.min(file.duration || 1000, start + SPEAKER_SNIPPET_SECONDS);
                    sp.samples.push({ start, end, label });
                    
                    // Re-render chips for this card
                    const row = addBtn.parentElement;
                    const newSIdx = sp.samples.length - 1;
                    const newChip = document.createElement('button');
                    newChip.type = 'button';
                    newChip.className = 'snippet-chip';
                    newChip.dataset.speakerId = spId;
                    newChip.dataset.sampleIdx = newSIdx;
                    newChip.innerHTML = `
                        <div class="playing-animation">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-volume-2">
                                <path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/>
                                <path class="arc-1" d="M16 9a5 5 0 0 1 0 6"/>
                                <path class="arc-2" d="M19.364 18.364a9 9 0 0 0 0-12.728"/>
                            </svg>
                        </div>
                        <span class="chip-label">${label}</span>
                        <span class="chip-edit-trigger" title="${window.translation?.TranscriptEdit ?? 'Bearbeiten'}" data-speaker-id="${spId}" data-sample-idx="${newSIdx}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pencil"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                        </span>
                    `;
                    row.insertBefore(newChip, addBtn);
                    
                    // Open editor for new chip
                    toggleEditor(spId, newSIdx);
                }
                return;
            }

            const deleteBtn = e.target.closest('.delete-snippet-btn');
            if (deleteBtn) {
                const wrapper = deleteBtn.closest('.delete-action-wrapper');
                const group = wrapper.querySelector('.confirm-btns-group');
                deleteBtn.style.display = 'none';
                group.style.display = 'flex';
                return;
            }

            const cancelSnippetDelete = e.target.closest('.cancel-snippet-delete');
            if (cancelSnippetDelete) {
                const wrapper = cancelSnippetDelete.closest('.delete-action-wrapper');
                const deleteBtn = wrapper.querySelector('.delete-snippet-btn');
                const group = wrapper.querySelector('.confirm-btns-group');
                group.style.display = 'none';
                deleteBtn.style.display = 'flex';
                return;
            }

            const confirmSnippetDelete = e.target.closest('.confirm-snippet-delete');
            if (confirmSnippetDelete) {
                const spId = confirmSnippetDelete.dataset.speakerId;
                const sp = file.speakers.find(s => s.id === spId);
                const activeChip = modalContent.querySelector(`.snippet-chip.active[data-speaker-id="${spId}"]`);
                if (activeChip && sp) {
                    const sIdx = parseInt(activeChip.dataset.sampleIdx);
                    sp.samples.splice(sIdx, 1);
                    
                    // Close editor after deletion
                    toggleEditor(spId, null);
                    
                    // Re-render all chips for this speaker
                    const row = activeChip.parentElement;
                    const addChip = row.querySelector('.add-snippet-chip');
                    row.querySelectorAll('.snippet-chip:not(.add-snippet-chip)').forEach(c => c.remove());
                    sp.samples.forEach((samp, idx) => {
                        const nc = document.createElement('button');
                        nc.type = 'button';
                        nc.className = 'snippet-chip';
                        nc.dataset.speakerId = spId;
                        nc.dataset.sampleIdx = idx;
                        nc.innerHTML = `
                            <div class="playing-animation">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-volume-2">
                                    <path d="M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z"/>
                                    <path class="arc-1" d="M16 9a5 5 0 0 1 0 6"/>
                                    <path class="arc-2" d="M19.364 18.364a9 9 0 0 0 0-12.728"/>
                                </svg>
                            </div>
                            <span class="chip-label">${samp.label || (window.translation?.TranscriptSampleN ?? 'Beispiel {n}').replace('{n}', idx + 1)}</span>
                            <span class="chip-edit-trigger" title="${window.translation?.TranscriptEdit ?? 'Bearbeiten'}" data-speaker-id="${spId}" data-sample-idx="${idx}">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pencil"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                            </span>
                        `;
                        row.insertBefore(nc, addChip);
                    });
                }
                return;
            }

            const addSpBtn = e.target.closest('.add-speaker-card-btn');
            if (addSpBtn) {
                saveCurrentInputs();
                const newId = 'manual_' + Date.now();
                const nextNum = file.speakers.length + 1;
                const manualLabel = (window.translation?.TranscriptSpeakerN ?? 'Stimme {n}').replace('{n}', nextNum);
                file.speakers.push({
                    id: newId,
                    label: manualLabel,
                    audio_url: file.speakers[0]?.audio_url || '',
                    samples: []
                });
                file.speakerMapping[newId] = manualLabel;
                this.openSpeakerMappingModal(file);
                return;
            }

            const removeSpBtn = e.target.closest('.remove-speaker-btn');
            if (removeSpBtn) {
                const wrapper = removeSpBtn.closest('.speaker-delete-container');
                const group = wrapper.querySelector('.confirm-btns-group');
                removeSpBtn.style.setProperty('display', 'none', 'important');
                group.style.setProperty('display', 'flex', 'important');
                return;
            }

            const cancelSpeakerRemove = e.target.closest('.cancel-speaker-remove');
            if (cancelSpeakerRemove) {
                const wrapper = cancelSpeakerRemove.closest('.speaker-delete-container');
                const removeSpBtn = wrapper.querySelector('.remove-speaker-btn');
                const group = wrapper.querySelector('.confirm-btns-group');
                group.style.setProperty('display', 'none', 'important');
                removeSpBtn.style.setProperty('display', 'flex', 'important');
                return;
            }

            const confirmSpeakerRemove = e.target.closest('.confirm-speaker-remove');
            if (confirmSpeakerRemove) {
                const spId = confirmSpeakerRemove.dataset.speakerId;
                const spIdx = file.speakers.findIndex(s => s.id === spId);
                if (spIdx !== -1) {
                    file.speakers.splice(spIdx, 1);
                    delete file.speakerMapping[spId];
                    this.openSpeakerMappingModal(file);
                }
                return;
            }

            // Close menus on click outside
            if (!e.target.closest('.speaker-options-wrapper')) {
                modalContent.querySelectorAll('.speaker-options-menu.show').forEach(m => m.classList.remove('show'));
            }
        };

        // Stop any snippet preview as soon as the user clicks anywhere that isn't the
        // chip currently playing (including outside the modal entirely). Runs on capture
        // so it settles before a click on a different chip starts its own preview.
        if (this.speakerModalOutsideClickHandler) {
            document.removeEventListener('click', this.speakerModalOutsideClickHandler, true);
        }
        this.speakerModalOutsideClickHandler = (e) => {
            if (!this.currentPreviewAudio) return;
            if (this.currentPlayingChip && this.currentPlayingChip.contains(e.target)) return;
            this.currentPreviewAudio.pause();
            this.currentPreviewAudio = null;
            if (this.currentPlayingChip) {
                this.currentPlayingChip.classList.remove('is-playing');
                this.currentPlayingChip = null;
            }
        };
        document.addEventListener('click', this.speakerModalOutsideClickHandler, true);

        // Function to save current inputs
        const saveCurrentInputs = () => {
            // Save auto speaker names
            const nameInputs = modalContent.querySelectorAll('.speaker-mapping-input');
            nameInputs.forEach(input => {
                file.speakerMapping[input.dataset.speakerId] = input.value.trim();
            });
        };

        const saveBtns = modal.querySelectorAll('.save-speaker-mapping-btn-modal');
        saveBtns.forEach(btn => {
            btn.onclick = () => {
                saveCurrentInputs();

                // Set speaker checked/saved flag
                file.speakersSaved = true;

                // Destroy all sidebar players on save
                if (this.sidebarPlayers) {
                    this.sidebarPlayers.forEach(p => p.destroy());
                    this.sidebarPlayers.clear();
                }

                const originalText = btn.textContent;
                btn.textContent = (window.translation?.TranscriptSavedExclaim ?? 'Gespeichert!');
                btn.style.backgroundColor = '#22c55e';

                // Update the main UI immediately to show updated badges
                this.renderMultiFileSelection();
                
                // Explicitly update the badge on the specific button as well
                const speakerBtns = document.querySelectorAll('.open-speaker-btn');
                speakerBtns.forEach(btn => {
                    if (btn.dataset.speakerMapping === `${modal.dataset.groupIndex}:${modal.dataset.fileIndex}`) {
                        const count = this.getUnidentifiedSpeakersCount(file);
                        const badge = btn.querySelector('.speaker-badge');
                        if (count > 0) {
                            if (badge) {
                                badge.textContent = count;
                            } else {
                                const newBadge = document.createElement('span');
                                newBadge.className = 'speaker-badge';
                                newBadge.textContent = count;
                                btn.appendChild(newBadge);
                            }
                        } else if (badge) {
                            badge.remove();
                        }
                        
                        if (file.speakersSaved) {
                            btn.classList.add('is-saved');
                        }
                    }
                });

                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.backgroundColor = '';
                    this.closeSpeakerMappingModal();
                }, 600);
            };
        });
    }

    closeSpeakerMappingModal() {
        const modal = document.getElementById('speaker-mapping-modal');
        if (modal) {
            modal.style.display = 'none';
        }
        // Destroy all sidebar players on close
        if (this.sidebarPlayers) {
            this.sidebarPlayers.forEach(p => p.destroy());
            this.sidebarPlayers.clear();
        }
        // Stop any snippet chip preview still playing
        if (this.currentPreviewAudio) {
            this.currentPreviewAudio.pause();
            this.currentPreviewAudio = null;
        }
        if (this.currentPlayingChip) {
            this.currentPlayingChip.classList.remove('is-playing');
            this.currentPlayingChip = null;
        }
        if (this.speakerModalOutsideClickHandler) {
            document.removeEventListener('click', this.speakerModalOutsideClickHandler, true);
            this.speakerModalOutsideClickHandler = null;
        }
    }

    async retrySpeakerAnalysis(file, btn) {
        if (!file.job_id || file._retryingAnalysis) return;
        file._retryingAnalysis = true;

        const label = btn.querySelector('.retry-speaker-analysis-label');
        const originalLabel = label ? label.textContent : '';
        btn.disabled = true;
        if (label) label.textContent = 'Analysiere...';

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const durationParam = Number.isFinite(file.duration) ? `?duration=${encodeURIComponent(file.duration)}` : '';
            const analyzeResponse = await fetch(`/req/transcription/async/analyze/${file.job_id}${durationParam}`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            });
            const analyzeData = await analyzeResponse.json();
            if (!analyzeData.success) {
                throw new Error((window.translation?.TranscriptRestartAnalysisFailed ?? 'Konnte Analyse nicht neu starten.'));
            }

            let done = false;
            while (!done) {
                await new Promise(r => setTimeout(r, 2000));
                const statusResponse = await fetch(`/req/transcription/async/status/${file.job_id}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const statusData = await statusResponse.json();

                if (statusData.status === 'failed') {
                    throw new Error(statusData.error || (window.translation?.TranscriptAnalysisFailed ?? 'Analyse fehlgeschlagen.'));
                } else if (statusData.status === 'analyzed_speakers') {
                    done = true;
                    file.speakers = statusData.manifest?.speakers || [];
                    file.speakerMapping = {};
                    file.speakers.forEach((sp, idx) => {
                        file.speakerMapping[sp.id] = this.localizeAutoSpeakerLabel(sp.label, idx);
                    });
                    file.speakersSaved = false;
                } else if (label) {
                    label.textContent = (window.translation?.TranscriptAnalyzingSpeakers ?? 'Analysiere Sprecher...');
                }
            }

            // Re-render the modal with the fresh speaker list
            this.openSpeakerMappingModal(file);
            this.renderMultiFileSelection();
        } catch (error) {
            console.error('Fehler beim Wiederholen der Sprecheranalyse:', error);
            alert((window.translation?.TranscriptSpeakerAnalysisRetryFailed ?? 'Die Sprecheranalyse konnte nicht wiederholt werden: ') + error.message);
        } finally {
            file._retryingAnalysis = false;
            if (btn && btn.isConnected) {
                btn.disabled = false;
                if (label) label.textContent = originalLabel;
            }
        }
    }

    showAvatarPicker(avatarElement, speakerId, speakerName, groupIndex, fileIndex, saveInputsCallback) {
        // Remove existing pickers
        document.querySelectorAll('.avatar-picker-popover').forEach(p => p.remove());

        const picker = document.createElement('div');
        picker.className = 'avatar-picker-popover';
        picker.style.zIndex = "3000";
        
        const speakerMap = this.app.state.speakerColorMap || new Map();
        if (!this.app.state.speakerColorMap) this.app.state.speakerColorMap = speakerMap;
        
        // Extract current color ID from the element itself to ensure consistency
        const colorMatch = avatarElement.className.match(/speaker-color-(\d+)/);
        const currentColorId = colorMatch ? parseInt(colorMatch[1]) : 1;

        for (let i = 1; i <= 10; i++) {
            const option = document.createElement('div');
            option.className = `avatar-picker-option speaker-color-${i} ${i === currentColorId ? 'selected' : ''}`;
            option.onclick = (e) => {
                e.stopPropagation();
                if (!speakerMap.has(speakerName)) {
                    speakerMap.set(speakerName, { colorId: i, speakerIndex: speakerMap.size });
                } else {
                    speakerMap.get(speakerName).colorId = i;
                }
                
                // Save current names before re-rendering so the color matches the name correctly
                if (saveInputsCallback) saveInputsCallback();

                picker.remove();
                if (groupIndex != null && fileIndex != null) {
                    // Speaker mapping modal context: re-render the modal
                    const file = this.app.state.selectedFileGroups[groupIndex].files[fileIndex];
                    file.groupIndex = groupIndex;
                    file.fileIndex = fileIndex;
                    this.openSpeakerMappingModal(file);
                    // Also trigger a transcript re-render to reflect the change immediately
                    if (this.app.ui.renderTranscriptArea) this.app.ui.renderTranscriptArea();
                } else {
                    // Sidebar context: re-render transcript + speaker panel and persist the colors
                    this.renderTranscriptArea();
                    if (this.app.processor && this.app.processor.saveCurrentSegmentsToServer) {
                        this.app.processor.saveCurrentSegmentsToServer();
                    }
                }
            };
            picker.appendChild(option);
        }

        // Position picker near the avatar
        const rect = avatarElement.getBoundingClientRect();
        picker.style.position = 'fixed';
        picker.style.top = `${rect.bottom + 8}px`;
        picker.style.left = `${rect.left}px`;

        document.body.appendChild(picker);

        // Keep the picker inside the viewport (e.g. when opened from the right-side sidebar)
        const pickerRect = picker.getBoundingClientRect();
        if (pickerRect.right > window.innerWidth - 8) {
            picker.style.left = `${Math.max(8, window.innerWidth - 8 - pickerRect.width)}px`;
        }

        // Close on click outside
        const closePicker = (e) => {
            if (!picker.contains(e.target) && e.target !== avatarElement) {
                picker.remove();
                document.removeEventListener('click', closePicker);
            }
        };
        // Small delay to ensure the opening click doesn't immediately close it
        setTimeout(() => document.addEventListener('click', closePicker), 10);
    }

    playSnippet(spId, sampleIdx, file, chipElement) {
        const sp = file.speakers.find(s => s.id === spId);
        if (!sp || !sp.samples[sampleIdx]) return;

        // Check if we have an active editor player for this speaker
        const player = this.sidebarPlayers.get(spId);
        const activeChip = chipElement.classList.contains('active');
        
        if (player && activeChip) {
            // Link directly to the editor player
            if (player.isPlaying) {
                player.pause();
            } else {
                player.play();
            }
            return;
        }

        const sample = sp.samples[sampleIdx];
        const audioUrl = sp.audio_url.split('#')[0];

        // Toggle logic: if already playing this chip, stop it
        if (this.currentPreviewAudio && this.currentPlayingChip === chipElement) {
            this.currentPreviewAudio.pause();
            this.currentPreviewAudio = null;
            this.currentPlayingChip.classList.remove('is-playing');
            this.currentPlayingChip = null;
            return;
        }

        // Stop previous preview
        if (this.currentPreviewAudio) {
            this.currentPreviewAudio.pause();
            if (this.currentPlayingChip) {
                this.currentPlayingChip.classList.remove('is-playing');
            }
        }

        const audio = new Audio(audioUrl);
        audio.currentTime = sample.start;
        
        audio.ontimeupdate = () => {
            if (audio.currentTime >= sample.end) {
                audio.pause();
                chipElement.classList.remove('is-playing');
                if (this.currentPreviewAudio === audio) {
                    this.currentPreviewAudio = null;
                    this.currentPlayingChip = null;
                }
            }
        };

        audio.onended = () => {
            chipElement.classList.remove('is-playing');
        };

        this.currentPreviewAudio = audio;
        this.currentPlayingChip = chipElement;
        chipElement.classList.add('is-playing');
        audio.play();
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

            if (resDivVorschau || resDivEdit) {
                this.initGlobalAudioPlayer();
            }
        }
    }

    updateFileProgressByFile(file, progress, statusText, state = 'ready') {
        if (!file || !file._id) return;

        let activeState = 'ready';
        if (state === true || state === 'processing') {
            activeState = 'processing';
        } else if (state === 'error') {
            activeState = 'error';
        } else if (state === 'success') {
            activeState = 'success';
        }

        // Persisted on the file so a full renderMultiFileSelection() rebuild (triggered by
        // unrelated actions like adding/removing another file) can restore this file's
        // in-flight progress instead of resetting the bar back to 0%.
        file._progressPercent = progress;
        file._progressState = activeState;
        file._progressText = statusText;

        const item = document.querySelector(`[data-file-id="${file._id}"]`);
        if (!item) return;

        const progressBar = item.querySelector('.multi-upload-progress-bar');
        const statusEl = item.querySelector('.multi-upload-status');

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

    /**
     * Backend jobs like speaker analysis and audio preprocessing report no
     * sub-progress — they just sit in one status until they flip. Rather than
     * pinning the bar at a fixed percentage for however long that takes, ease
     * it asymptotically toward a soft cap so it keeps visibly moving until the
     * real status change arrives. Returns a stop function.
     */
    startProgressCreep(file, from, to, message) {
        let current = from;
        const intervalId = setInterval(() => {
            current += (to - current) * 0.06;
            this.updateFileProgressByFile(file, Math.round(current * 10) / 10, message, 'processing');
        }, 500);
        return () => clearInterval(intervalId);
    }

    async autoAnalyzeFile(file) {
        if (file.analysisStatus !== 'idle') return;
        file.analysisStatus = 'processing';

        let stopCreep = null;
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const language = document.getElementById('language-select')?.value || 'auto';
            const speakerCount = document.getElementById('speaker-count')?.value || 'auto';

            this.updateFileProgressByFile(file, 2, (window.translation?.TranscriptCreatingSession ?? 'Session erstellen...'), 'processing');

            const sessionResponse = await fetch('/req/transcription/async/session', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ 
                    filename: file.name,
                    language: language,
                    speaker_count: speakerCount
                })
            });

            const sessionData = await sessionResponse.json();
            if (!sessionData.success) {
                throw new Error((window.translation?.TranscriptUploadSessionFailed ?? 'Konnte keine Upload-Session erstellen.'));
            }

            const { job_id, upload_url } = sessionData.session;
            file.job_id = job_id;

            // xhr.upload progress reaches 100% when the last byte is handed to the
            // network — S3 has not confirmed the upload yet at that point, so keep
            // showing the upload label until the PUT actually resolves. Only then
            // does the analyze request (and any backend activity) start.
            await this.uploadFileWithProgress(upload_url, file, (percent) => {
                const mappedProgress = Math.round(2 + (percent * 0.48));
                this.updateFileProgressByFile(file, mappedProgress, (window.translation?.TranscriptUploadingFile ?? 'Dateiupload...'), 'processing');
            });

            this.updateFileProgressByFile(file, 50, (window.translation?.TranscriptAnalyzingAudio ?? 'Analysiere Audio...'), 'processing');

            const durationParam = Number.isFinite(file.duration) ? `?duration=${encodeURIComponent(file.duration)}` : '';
            const analyzeResponse = await fetch(`/req/transcription/async/analyze/${job_id}${durationParam}`, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                }
            });

            const analyzeData = await analyzeResponse.json();
            if (!analyzeData.success) {
                throw new Error((window.translation?.TranscriptAnalysisJobStartFailed ?? 'Konnte Analyse-Job nicht starten: ') + JSON.stringify(analyzeData));
            }

            let analysisCompleted = false;
            while (!analysisCompleted) {
                await new Promise(r => setTimeout(r, 2000));
                const statusResponse = await fetch(`/req/transcription/async/status/${job_id}`, {
                    headers: { 'Accept': 'application/json' }
                });
                const statusData = await statusResponse.json();

                if (statusData.status === 'failed') {
                    throw new Error((window.translation?.TranscriptAnalysisError ?? 'Fehler bei der Analyse: ') + (statusData.error || (window.translation?.TranscriptUnknown ?? 'Unbekannt')));
                } else if (statusData.status === 'analyzed_speakers') {
                    analysisCompleted = true;
                    if (stopCreep) { stopCreep(); stopCreep = null; }
                    file.speakers = statusData.manifest?.speakers || [];
                    this.updateFileProgressByFile(file, 100, (window.translation?.TranscriptReadyForTranscription ?? 'Bereit für Transkription'), 'ready');
                } else if (statusData.status === 'analyzing_speakers') {
                    if (!stopCreep) {
                        stopCreep = this.startProgressCreep(file, 50, 98, (window.translation?.TranscriptAnalyzingSpeakers ?? 'Analysiere Sprecher...'));
                    }
                } else if (statusData.status === 'analyzing_speakers_queued') {
                    if (stopCreep) { stopCreep(); stopCreep = null; }
                    this.updateFileProgressByFile(file, 50, (window.translation?.TranscriptWaitingForAnalysis ?? 'Warte auf Analyse...'), 'processing');
                }
            }

            file.analysisStatus = 'ready';
            this.renderMultiFileSelection();
        } catch (error) {
            if (stopCreep) stopCreep();
            console.error(`Fehler bei Analyse von ${file.name}:`, error);
            this.updateFileProgressByFile(file, 100, (window.translation?.TranscriptFailed ?? 'Fehlgeschlagen'), 'error');
            file.analysisStatus = 'error';
        }
    }

    async startTranscription() {
        const groups = this.app.state.selectedFileGroups || [];
        const hasFiles = groups.some(g => g.files && g.files.length > 0);
        if (!hasFiles) {
            alert(window.translation?.TranscriptAddFileFirst ?? 'Bitte füge zuerst mindestens eine Datei hinzu.');
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
                <span>${window.translation?.TranscriptInProgress ?? 'Transkription läuft...'}</span>
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

            const filePromises = files.map(async (file, fileIndex) => {
                let stopPreprocessCreep = null;
                try {
                    // Check if we already have the successful result from a previous run
                    if (file.transcriptionResult) {
                        fileResults[fileIndex] = file.transcriptionResult;
                        this.updateFileProgressByFile(file, 100, (window.translation?.TranscriptReadyFromCache ?? 'Bereit (aus Cache)'), 'success');
                        return;
                    }

                    // A restored job whose transcription is already running
                    // server-side (resumeTranscriptionPolling is attached) must
                    // not be dispatched a second time — wait for that poll.
                    if (file.job_id && this.pollingJobs.has(file.job_id)) {
                        this.updateFileProgressByFile(file, file._progressPercent || 40, (window.translation?.TranscriptInProgress ?? 'Transkription läuft...'), 'processing');
                        while (this.pollingJobs.has(file.job_id)) {
                            await new Promise(r => setTimeout(r, 1000));
                        }
                        if (file.transcriptionResult) {
                            fileResults[fileIndex] = file.transcriptionResult;
                            return;
                        }
                        throw new Error((window.translation?.TranscriptFileProcessingFailed ?? 'Datei konnte nicht verarbeitet werden.'));
                    }

                    // Wait for analysis to finish if still processing
                    if (file.analysisStatus === 'processing') {
                        this.updateFileProgressByFile(file, 50, (window.translation?.TranscriptWaitingForAnalysis ?? 'Warte auf Analyse...'), 'processing');
                        while (file.analysisStatus === 'processing') {
                            await new Promise(r => setTimeout(r, 1000));
                        }
                    }

                    if (file.analysisStatus === 'error') {
                        throw new Error((window.translation?.TranscriptFileProcessingFailed ?? 'Datei konnte nicht verarbeitet werden.'));
                    }
                    
                    const job_id = file.job_id;
                    const speakers = file.speakers || [];
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                    const speakerCount = document.getElementById('speaker-count')?.value || 'auto';

                    // 5. Read Speaker Mapping and Snippets from file directly
                    let speakerMapping = null;
                    let speakerSnippets = null;
                    let finalSpeakerCount = speakerCount;
                    
                    if (file.speakerMapping) {
                        speakerMapping = file.speakerMapping;
                    }
                    if (file.speakers && file.speakerMapping) {
                        speakerSnippets = file.speakers.map(sp => ({
                            id: sp.id,
                            name: file.speakerMapping[sp.id] || sp.label || sp.id,
                            start: sp.start,
                            end: sp.end
                        }));
                    }
                    
                    if (file.manualSpeakers && file.manualSpeakers.length > 0) {
                        if (!speakerSnippets) speakerSnippets = [];
                        file.manualSpeakers.forEach((ms, idx) => {
                            if (ms.name && ms.start && ms.end) {
                                // Parse mm:ss to seconds
                                const parseTime = (timeStr) => {
                                    const parts = timeStr.split(':');
                                    if (parts.length === 2) {
                                        return parseInt(parts[0]) * 60 + parseFloat(parts[1].replace(',', '.'));
                                    }
                                    return parseFloat(timeStr.replace(',', '.'));
                                };
                                const startSec = parseTime(ms.start);
                                const endSec = parseTime(ms.end);
                                
                                const id = 'MANUAL_' + idx;
                                speakerSnippets.push({
                                    id: id,
                                    name: ms.name,
                                    start: startSec,
                                    end: endSec
                                });
                                
                                if (!speakerMapping) speakerMapping = {};
                                speakerMapping[id] = ms.name;
                            }
                        });
                    }

                    // 6. Dispatch Transcription Job
                    this.updateFileProgressByFile(file, 5, (window.translation?.TranscriptPreprocessing ?? 'Vorverarbeitung'), 'processing');

                    const llmCorrectionToggle = document.getElementById('llm-correction-toggle');
                    const dispatchBody = {
                        speaker_mapping: speakerMapping,
                        speaker_snippets: speakerSnippets,
                        speaker_count: finalSpeakerCount,
                        llm_correction: llmCorrectionToggle ? (llmCorrectionToggle.checked ? 1 : 0) : 0
                    };

                    const dispatchResponse = await fetch(`/req/transcription/async/dispatch/${job_id}`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken
                        },
                        body: JSON.stringify(dispatchBody)
                    });

                    const dispatchData = await dispatchResponse.json();
                    if (!dispatchData.success) {
                        throw new Error((window.translation?.TranscriptProcessingJobStartFailed ?? 'Konnte Verarbeitungs-Job nicht starten.'));
                    }

                    // 7. Poll Status for Transcription
                    let isCompleted = false;
                    let resultData = null;

                    while (!isCompleted) {
                        await new Promise(r => setTimeout(r, 2000));
                        const statusResponse = await fetch(`/req/transcription/async/status/${job_id}`, {
                            headers: { 'Accept': 'application/json' }
                        });
                        const statusData = await statusResponse.json();

                        if (statusData.status === 'failed') {
                            if (stopPreprocessCreep) { stopPreprocessCreep(); stopPreprocessCreep = null; }
                            throw new Error((window.translation?.TranscriptTranscriptionError ?? 'Fehler bei der Transkription: ') + (statusData.error || (window.translation?.TranscriptUnknown ?? 'Unbekannt')));
                        } else if (statusData.status === 'completed') {
                            if (stopPreprocessCreep) { stopPreprocessCreep(); stopPreprocessCreep = null; }
                            isCompleted = true;
                            resultData = statusData.result;
                            file.duration = (resultData && resultData.duration) || file.duration;
                            this.updateFileProgress(100, (window.translation?.TranscriptTranscriptionComplete ?? 'Transcription abgeschlossen'), 'success', groupIndex, fileIndex);
                        } else if (statusData.status === 'transcribing' || statusData.status === 'optimizing') {
                            if (stopPreprocessCreep) { stopPreprocessCreep(); stopPreprocessCreep = null; }
                            let percent = 40;
                            let msg = (window.translation?.TranscriptPreparing ?? 'Vorbereitung');
                            if (statusData.manifest && statusData.manifest.progress) {
                                const current = statusData.manifest.progress.current_chunk || 0;
                                const total = statusData.manifest.progress.total_chunks || 1;
                                const phase = statusData.manifest.progress.phase || 'transcribing';

                                const chunkBasePercent = 40 + ((current - 1) / total * 50);
                                const chunkStepPercent = 50 / total;

                                if (phase === 'optimizing') {
                                    percent = 95;
                                    msg = (window.translation?.TranscriptSpeakerAssignment ?? 'Sprecherzuordnung');
                                } else if (phase === 'diarizing') {
                                    if (total === 1 && current === 0) {
                                        percent = 90;
                                        msg = (window.translation?.TranscriptSpeakerAssignment ?? 'Sprecherzuordnung');
                                    } else {
                                        percent = Math.round(chunkBasePercent + (chunkStepPercent * 0.9));
                                        msg = (window.translation?.TranscriptSpeakerAssignment ?? 'Sprecherzuordnung');
                                    }
                                } else {
                                    percent = Math.round(chunkBasePercent + (chunkStepPercent * 0.4));
                                    msg = (window.translation?.TranscriptTranscribing ?? 'Transkription');
                                }
                            }
                            this.updateFileProgressByFile(file, percent, msg, 'processing');
                        } else if (statusData.status === 'preprocessed') {
                            if (stopPreprocessCreep) { stopPreprocessCreep(); stopPreprocessCreep = null; }
                            this.updateFileProgressByFile(file, 35, (window.translation?.TranscriptPreprocessing ?? 'Vorverarbeitung'), 'processing');
                        } else if (statusData.status === 'preprocessing') {
                            if (!stopPreprocessCreep) {
                                stopPreprocessCreep = this.startProgressCreep(file, 8, 33, (window.translation?.TranscriptPreprocessing ?? 'Vorverarbeitung'));
                            }
                        }
                    }

                    if (resultData && resultData.success) {
                        fileResults[fileIndex] = resultData;
                        file.transcriptionResult = resultData; // Cache successful result on file object
                    } else {
                        throw new Error(resultData?.message || (window.translation?.TranscriptNoServerResponse ?? 'Keine Antwort vom Server.'));
                    }

                } catch (error) {
                    if (stopPreprocessCreep) stopPreprocessCreep();
                    console.error(`Fehler bei Datei ${file.name}:`, error);
                    this.updateFileProgressByFile(file, 100, (window.translation?.TranscriptFailed ?? 'Fehlgeschlagen'), 'error');
                    allFilesSuccessful = false;
                }
            });

            await Promise.all(filePromises);

            // Merge and save all files in the group if all were successful
            const allPopulated = files.every((_, idx) => fileResults[idx] !== undefined);
            if (allFilesSuccessful && allPopulated) {
                let combinedSegments = [];
                let combinedWords = [];
                let accumulatedDuration = 0;
                let combinedTextParts = [];
                let combinedFileSize = 0;
                let originalFilenames = [];
                let firstModelUsed = null;
                let firstProvider = null;
                let firstLanguage = null;
                const sourceFiles = [];

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

                    sourceFiles.push({
                        name: file.name,
                        size: file.size,
                        duration: chunkDuration,
                        start_time: accumulatedDuration,
                        end_time: accumulatedDuration + chunkDuration,
                        job_id: file.job_id || null
                    });

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
                    provider: firstProvider,
                    metadata: {
                        timestamp: new Date().toISOString(),
                        source_files: sourceFiles
                    }
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
            if (!data.success || !Array.isArray(data.jobs)) return;

            let restoredAny = false;
            for (const job of data.jobs) {
                if (this.findFileByJobId(job.id)) continue; // still tracked in memory
                this.restoreJobIntoQueue(job);
                restoredAny = true;
            }
            if (restoredAny) {
                this.renderMultiFileSelection();
            }
        } catch (error) {
            console.error('Fehler beim Laden aktiver Jobs:', error);
        }
    }

    findFileByJobId(jobId) {
        for (const group of this.app.state.selectedFileGroups || []) {
            for (const file of group.files || []) {
                if (file.job_id === jobId) return file;
            }
        }
        return null;
    }

    /**
     * Rebuilds a queue entry (multi-file-panel row) for a job that is still
     * running server-side but whose in-memory state was lost — the user left
     * the upload screen or reloaded the page. The original File object is gone
     * (and not needed: the audio already sits on S3), so a plain object with
     * the properties the queue flows actually use stands in for it; the
     * waveform player slot skips non-File entries on its own.
     */
    restoreJobIntoQueue(job) {
        const baseName = job.filename || (window.translation?.TranscriptJobTitle ?? 'Transkription {id}').replace('{id}', job.id.substring(0, 8));
        const file = {
            _id: Math.random().toString(36).substr(2, 9),
            name: baseName,
            size: 0,
            job_id: job.id,
            isRestored: true,
            analysisStatus: 'ready',
        };

        this.app.state.selectedFileGroups = this.app.state.selectedFileGroups || [];
        // Grouping does not survive a reload — each restored job becomes its
        // own group (one transcript per job). Reuse an empty placeholder group
        // if one exists.
        let group = this.app.state.selectedFileGroups.find(
            g => (g.files || []).length === 0 && (!g.processedTranscripts || g.processedTranscripts.length === 0)
        );
        if (!group) {
            group = { name: '', files: [] };
            this.app.state.selectedFileGroups.push(group);
        }
        if (!group.name || /^Transcript \d+$/.test(group.name)) {
            group.name = baseName.replace(/\.[^.]+$/, '');
        }
        group.files.push(file);

        switch (job.status) {
            case 'analyzing_speakers_queued':
            case 'analyzing_speakers':
                file.analysisStatus = 'processing';
                file._progressPercent = 50;
                file._progressState = 'processing';
                file._progressText = (window.translation?.TranscriptAnalyzingSpeakers ?? 'Analysiere Sprecher...');
                this.resumeSpeakerAnalysis(file);
                break;
            case 'analyzed_speakers':
                file._progressPercent = 100;
                file._progressState = 'ready';
                file._progressText = (window.translation?.TranscriptReadyForTranscription ?? 'Bereit für Transkription');
                this.hydrateRestoredSpeakers(file);
                break;
            default: // preprocessing, preprocessed, transcribing, optimizing, completed
                file._progressPercent = 40;
                file._progressState = 'processing';
                file._progressText = (window.translation?.TranscriptInProgress ?? 'Transkription läuft...');
                this.resumeTranscriptionPolling(file);
                break;
        }
    }

    /**
     * Resume the phase-1 wait of autoAnalyzeFile() for a restored job: poll
     * until the speaker preview is ready, then load the speakers so the
     * speaker-assignment modal works as for a freshly uploaded file.
     */
    async resumeSpeakerAnalysis(file) {
        if (this.pollingJobs.has(file.job_id)) return;
        this.pollingJobs.add(file.job_id);
        let stopCreep = null;
        try {
            let analysisCompleted = false;
            while (!analysisCompleted) {
                await new Promise(r => setTimeout(r, 2000));
                const statusResponse = await fetch(`/req/transcription/async/status/${file.job_id}`, {
                    headers: { 'Accept': 'application/json' }
                });
                if (!statusResponse.ok) continue;
                const statusData = await statusResponse.json();

                if (statusData.status === 'failed') {
                    throw new Error((window.translation?.TranscriptAnalysisError ?? 'Fehler bei der Analyse: ') + (statusData.error || (window.translation?.TranscriptUnknown ?? 'Unbekannt')));
                } else if (statusData.status === 'analyzed_speakers') {
                    analysisCompleted = true;
                    if (stopCreep) { stopCreep(); stopCreep = null; }
                    file.speakers = statusData.manifest?.speakers || [];
                    this.updateFileProgressByFile(file, 100, (window.translation?.TranscriptReadyForTranscription ?? 'Bereit für Transkription'), 'ready');
                } else if (statusData.status === 'analyzing_speakers') {
                    if (!stopCreep) {
                        stopCreep = this.startProgressCreep(file, 50, 98, (window.translation?.TranscriptAnalyzingSpeakers ?? 'Analysiere Sprecher...'));
                    }
                } else if (statusData.status === 'analyzing_speakers_queued') {
                    if (stopCreep) { stopCreep(); stopCreep = null; }
                    this.updateFileProgressByFile(file, 50, (window.translation?.TranscriptWaitingForAnalysis ?? 'Warte auf Analyse...'), 'processing');
                }
            }
            file.analysisStatus = 'ready';
            this.renderMultiFileSelection();
        } catch (error) {
            if (stopCreep) stopCreep();
            console.error(`Fehler bei Analyse von ${file.name}:`, error);
            this.updateFileProgressByFile(file, 100, (window.translation?.TranscriptFailed ?? 'Fehlgeschlagen'), 'error');
            file.analysisStatus = 'error';
        } finally {
            this.pollingJobs.delete(file.job_id);
        }
    }

    /**
     * A restored job waiting at the speaker-confirmation step: fetch the
     * speaker preview once (the status endpoint includes the manifest for
     * 'analyzed_speakers'), so the badge count and the assignment modal work.
     * No polling — nothing changes server-side until the user acts.
     */
    async hydrateRestoredSpeakers(file) {
        try {
            const statusResponse = await fetch(`/req/transcription/async/status/${file.job_id}`, {
                headers: { 'Accept': 'application/json' }
            });
            const statusData = await statusResponse.json();
            file.speakers = statusData.manifest?.speakers || [];
            // The manifest still carries the presigned URLs from analysis
            // time — usually long expired for a restored job.
            await this.refreshSpeakerAudioUrls(file);
            this.renderMultiFileSelection();
        } catch (error) {
            console.error(`Fehler beim Laden der Sprecher für ${file.name}:`, error);
        }
    }

    /**
     * Replaces the speakers' presigned audio URLs with a freshly signed one
     * when the stored URL is expired or about to expire. All snippets play
     * ranges of the same original file, so one URL serves every speaker.
     */
    async refreshSpeakerAudioUrls(file) {
        if (!file || !file.job_id || !Array.isArray(file.speakers) || file.speakers.length === 0) return;

        const current = file.speakers.find(sp => sp.audio_url)?.audio_url;
        if (current && !this.presignedUrlExpiresSoon(current)) return;

        try {
            const response = await fetch(`/req/transcription/audio?job_id=${encodeURIComponent(file.job_id)}`, {
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json();
            if (data.success && data.url) {
                file.speakers.forEach(sp => {
                    if (sp.audio_url) sp.audio_url = data.url;
                });
            }
        } catch (error) {
            console.error(`Konnte Audio-URL für ${file.name} nicht erneuern:`, error);
        }
    }

    presignedUrlExpiresSoon(url, marginSeconds = 300) {
        try {
            const params = new URL(url, window.location.origin).searchParams;
            const amzDate = params.get('X-Amz-Date'); // 20260710T120200Z
            const expires = parseInt(params.get('X-Amz-Expires') || '0', 10);
            if (!amzDate || !expires) return false;
            const signedAt = Date.parse(amzDate.replace(
                /^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/,
                '$1-$2-$3T$4:$5:$6Z'
            ));
            if (Number.isNaN(signedAt)) return false;
            return Date.now() > signedAt + (expires - marginSeconds) * 1000;
        } catch {
            return false;
        }
    }

    /**
     * Resume the phase-2 wait of startTranscription() for a restored job whose
     * transcription is already running — or finished while the user was away:
     * poll to completion, then save the result. Saving links the job to the
     * created Transcription, which drops it from the active-jobs listing.
     */
    async resumeTranscriptionPolling(file) {
        if (this.pollingJobs.has(file.job_id)) return;
        this.pollingJobs.add(file.job_id);
        try {
            let resultData = null;
            while (resultData === null) {
                const statusResponse = await fetch(`/req/transcription/async/status/${file.job_id}`, {
                    headers: { 'Accept': 'application/json' }
                });
                if (statusResponse.ok) {
                    const statusData = await statusResponse.json();
                    if (statusData.status === 'failed') {
                        throw new Error((window.translation?.TranscriptTranscriptionError ?? 'Fehler bei der Transkription: ') + (statusData.error || (window.translation?.TranscriptUnknown ?? 'Unbekannt')));
                    } else if (statusData.status === 'completed') {
                        if (!statusData.result || !statusData.result.success) {
                            throw new Error((window.translation?.TranscriptNoServerResponse ?? 'Keine Antwort vom Server.'));
                        }
                        resultData = statusData.result;
                        break;
                    } else if (statusData.status === 'transcribing' || statusData.status === 'optimizing') {
                        let percent = 40;
                        let msg = (window.translation?.TranscriptPreparing ?? 'Vorbereitung');
                        if (statusData.manifest && statusData.manifest.progress) {
                            const current = statusData.manifest.progress.current_chunk || 0;
                            const total = statusData.manifest.progress.total_chunks || 1;
                            const phase = statusData.manifest.progress.phase || 'transcribing';

                            const chunkBasePercent = 40 + ((current - 1) / total * 50);
                            const chunkStepPercent = 50 / total;

                            if (phase === 'optimizing') {
                                percent = 95;
                                msg = (window.translation?.TranscriptSpeakerAssignment ?? 'Sprecherzuordnung');
                            } else if (phase === 'diarizing') {
                                if (total === 1 && current === 0) {
                                    percent = 90;
                                    msg = (window.translation?.TranscriptSpeakerAssignment ?? 'Sprecherzuordnung');
                                } else {
                                    percent = Math.round(chunkBasePercent + (chunkStepPercent * 0.9));
                                    msg = (window.translation?.TranscriptSpeakerAssignment ?? 'Sprecherzuordnung');
                                }
                            } else {
                                percent = Math.round(chunkBasePercent + (chunkStepPercent * 0.4));
                                msg = (window.translation?.TranscriptTranscribing ?? 'Transkription');
                            }
                        }
                        this.updateFileProgressByFile(file, percent, msg, 'processing');
                    } else if (statusData.status === 'preprocessed') {
                        this.updateFileProgressByFile(file, 35, (window.translation?.TranscriptPreprocessing ?? 'Vorverarbeitung'), 'processing');
                    } else if (statusData.status === 'preprocessing') {
                        this.updateFileProgressByFile(file, 20, (window.translation?.TranscriptPreprocessing ?? 'Vorverarbeitung'), 'processing');
                    }
                }
                await new Promise(r => setTimeout(r, 3000));
            }

            let duration = resultData.duration;
            if (!duration && resultData.segments && resultData.segments.length > 0) {
                duration = resultData.segments[resultData.segments.length - 1].end;
            }
            duration = duration || 0;
            file.duration = file.duration || duration;
            file.transcriptionResult = resultData;

            resultData.metadata = resultData.metadata || { timestamp: new Date().toISOString() };
            resultData.metadata.job_id = file.job_id;
            if (!resultData.metadata.source_files) {
                resultData.metadata.source_files = [{
                    name: file.name,
                    size: 0,
                    duration: duration,
                    start_time: 0,
                    end_time: duration,
                    job_id: file.job_id
                }];
            }

            this.updateFileProgressByFile(file, 100, (window.translation?.TranscriptTranscriptionComplete ?? 'Transcription abgeschlossen'), 'success');
            const groupIndex = (this.app.state.selectedFileGroups || []).findIndex(
                g => (g.files || []).includes(file)
            );
            if (groupIndex >= 0) {
                await this.saveProcessedFile(resultData, file, groupIndex, 0);
                this.renderMultiFileSelection();
            }
        } catch (error) {
            console.error(`Fehler bei Datei ${file.name}:`, error);
            this.updateFileProgressByFile(file, 100, (window.translation?.TranscriptFailed ?? 'Fehlgeschlagen'), 'error');
        } finally {
            this.pollingJobs.delete(file.job_id);
        }
    }

    async saveProcessedFile(resultData, file, groupIndex, fileIndex) {
        const group = this.app.state.selectedFileGroups[groupIndex];
        const customTitle = group ? group.name : null;

        return this.app.service.saveTranscriptionToDatabase(resultData, file, customTitle)
            .then(savedTranscription => {
                this.app.history.saveTranscriptToHistory(
                    resultData.text,
                    savedTranscription.slug,
                    savedTranscription.title,
                    resultData.segments,
                    savedTranscription.metadata
                );
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
                this.app.history.saveTranscriptToHistory(resultData.text, null, null, resultData.segments, resultData.metadata);
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
                <span>${(window.translation?.TranscriptOpenItem ?? '{title} öffnen').replace('{title}', displayTitle)}</span>
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
                this.app.history.saveTranscriptToHistory(
                    resultData.text,
                    savedTranscription.slug,
                    savedTranscription.title,
                    resultData.segments,
                    savedTranscription.metadata
                );
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
                this.app.history.saveTranscriptToHistory(resultData.text, null, null, resultData.segments, resultData.metadata);
            })
            .finally(() => {
                this.app.state.activeSavePromise = null;
            });
    }
}
