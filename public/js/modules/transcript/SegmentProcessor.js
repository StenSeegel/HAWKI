import { Utils } from './Utils.js';

export class SegmentProcessor {
    constructor(app) {
        this.app = app;
    }

    formatTranscriptionWithSpeakers(segments, fullText, isEditMode = false) {
        if (!segments || segments.length === 0) {
            return `<div class="transcript-segment">
                        <div class="segment-header">
                            <div class="speaker-avatar speaker-color-1">
                                <svg class="avatar-hover-play lucide lucide-play" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/></svg>
                                <svg class="avatar-hover-pause lucide lucide-pause" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"></rect><rect x="6" y="4" width="4" height="16" rx="1"></rect></svg>
                            </div>
                            <div class="speaker-info">Person 1 <span class="speaker-sep">•</span> [00:00:00]</div>
                        </div>
                        <div class="transcript-text">${Utils.escapeHTML(fullText)}</div>
                    </div>`;
        }

        let formattedHTML = '';
        if (!this.app.state.speakerColorMap) {
            this.app.state.speakerColorMap = new Map();
        }
        if (!this.app.state.hiddenSpeakers) {
            this.app.state.hiddenSpeakers = new Set();
        }
        let speakerMap = this.app.state.speakerColorMap;
        let colorIndexCounter = 1;
        let currentBlock = null;
        let lastEndTime = 0;
        let speakerBlocks = [];

        segments.forEach((segment, index) => {
            const pauseDuration = segment.start - lastEndTime;
            const segmentSpeaker = segment.speaker || null;

            let shouldChangeSpeaker = false;
            if (segmentSpeaker) {
                shouldChangeSpeaker = currentBlock && currentBlock.speakerName !== segmentSpeaker;
            } else {
                shouldChangeSpeaker = index > 0 && (
                    pauseDuration > 3.0 ||
                    (segment.start - (currentBlock ? currentBlock.startTime : 0)) > 45
                );
            }

            if (index === 0 || shouldChangeSpeaker) {
                if (currentBlock) speakerBlocks.push(currentBlock);

                let speakerName = segmentSpeaker;
                if (!speakerName) {
                    speakerName = `Unbekannt ${colorIndexCounter}`;
                    colorIndexCounter++;
                }

                if (!speakerMap.has(speakerName)) {
                    speakerMap.set(speakerName, {
                        colorId: (speakerMap.size % 10) + 1,
                        speakerIndex: speakerMap.size
                    });
                }
                const speakerInfo = speakerMap.get(speakerName);
                const colorId = speakerInfo.colorId;

                currentBlock = {
                    speakerName,
                    colorId,
                    startTime: segment.start,
                    timestamp: Utils.formatSecondsToTime(segment.start),
                    text: segment.text ? segment.text.trimLeft() : '',
                    segmentIndices: [index]
                };
            } else {
                if (currentBlock) {
                    const rawText = segment.text || '';
                    if (!currentBlock.text.endsWith(' ') && !rawText.startsWith(' ') && !/^[.,!?:;]/.test(rawText.trim())) {
                        currentBlock.text += ' ';
                    }
                    currentBlock.text += rawText;
                    currentBlock.segmentIndices.push(index);
                }
            }
            lastEndTime = segment.end;
        });
        if (currentBlock) speakerBlocks.push(currentBlock);
        this.app.state.lastRenderedSpeakerBlocks = speakerBlocks;

        speakerBlocks.forEach((block, bIdx) => {
            let controlsTop = '';
            let controlsBottom = '';

            const copyBtn = `<button class="copy-block-btn" title="Abschnitt kopieren" onclick="window.copyBlockText(this)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                    </button>`;

            let speakBtn = '';
            let audioPlayerPlaceholder = '';

            let blockHTML = '';
            block.segmentIndices.forEach(idx => {
                const seg = segments[idx];
                const rawText = seg.text || '';
                let textContent;
                
                if (rawText.trim() === "[Dieser Sprecher hat noch keinen Text!]") {
                    textContent = `<span class="transcript-placeholder">${Utils.escapeHTML(rawText.trim())}</span>`;
                } else {
                    if (seg.redactions && seg.redactions.length > 0) {
                        let lastIdx = 0;
                        let newText = '';
                        const sortedRedactions = [...seg.redactions].sort((a, b) => a.start - b.start);
                        
                        sortedRedactions.forEach((red, redIdx) => {
                            newText += Utils.escapeHTML(rawText.substring(lastIdx, red.start));
                            if (isEditMode) {
                                newText += `<span class="redacted clickable-redaction" title="Schwärzung" onclick="window.showRedactionContextMenu(event, ${idx}, ${redIdx})">${Utils.escapeHTML(rawText.substring(red.start, red.end))}</span>`;
                            } else {
                                newText += `<span class="redacted" title="Schwärzung">${Utils.escapeHTML(rawText.substring(red.start, red.end))}</span>`;
                            }
                            lastIdx = red.end;
                        });
                        newText += Utils.escapeHTML(rawText.substring(lastIdx));
                        textContent = newText;
                    } else {
                        textContent = Utils.escapeHTML(rawText);
                    }
                }

                let spaceHtml = '';
                const nextIdxIndex = block.segmentIndices.indexOf(idx) + 1;
                if (nextIdxIndex < block.segmentIndices.length) {
                    const nextSeg = segments[block.segmentIndices[nextIdxIndex]];
                    const nextText = nextSeg.text || '';
                    if (!rawText.endsWith(' ') && !nextText.startsWith(' ') && !/^[.,!?:;]/.test(nextText)) {
                        spaceHtml = ' ';
                    }
                }

                if (isEditMode) {
                    blockHTML += `<span class="transcript-seg-item" data-seg-id="${idx}" contenteditable="false" spellcheck="false" onclick="window.makeSegmentEditable(event, this)" onkeydown="if(event.key==='Enter'){event.preventDefault();this.blur();}" onblur="this.contentEditable='false'; window.updateSegmentText(${idx}, this.innerText)">${textContent}${spaceHtml}</span>`;
                } else {
                    blockHTML += `<span class="transcript-seg-item" data-seg-id="${idx}">${textContent}${spaceHtml}</span>`;
                }
            });

            const isHiddenClass = this.app.state.hiddenSpeakers.has(block.speakerName) ? 'speaker-hidden' : '';
            formattedHTML += `<div class="transcript-segment ${isHiddenClass} ${isEditMode ? 'reorder-mode' : ''}" data-speaker="${Utils.escapeHTML(block.speakerName)}" data-block-idx="${bIdx}">
                <div class="segment-header">
                    <div class="speaker-avatar speaker-color-${block.colorId}">
                        <svg class="avatar-hover-play lucide lucide-play" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/></svg>
                        <svg class="avatar-hover-pause lucide lucide-pause" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="14" y="4" width="4" height="16" rx="1"></rect><rect x="6" y="4" width="4" height="16" rx="1"></rect></svg>
                    </div>
                    <div class="speaker-info">
                        <span class="speaker-label" ${isEditMode ? `onclick="window.showRenameSpeakerInline(event, ${bIdx})" style="cursor: pointer;" title="Klicken zum Umbenennen"` : ''}>${Utils.escapeHTML(block.speakerName)}</span> 
                        <span class="speaker-sep">•</span> 
                        [${block.timestamp}]
                        ${isEditMode ? `
                        <div class="speaker-edit-container">
                            <button class="speaker-action-btn" onclick="window.showReassignSubmenu(event, ${bIdx})" title="Zuweisen an...">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-users"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            </button>
                            <button class="speaker-action-btn" onclick="window.insertSpeakerAt(event, ${bIdx}, 'above')" title="Sprecher davor einfügen">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 13 20 10 23 13"></polyline><polyline points="17 18 20 15 23 18"></polyline></svg>
                            </button>
                            <button class="speaker-action-btn" onclick="window.insertSpeakerAt(event, ${bIdx}, 'below')" title="Sprecher danach einfügen">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 10 20 13 23 10"></polyline><polyline points="17 15 20 18 23 15"></polyline></svg>
                            </button>
                            <button class="speaker-remove-btn" onclick="window.removeSpeaker(${bIdx})" title="Sprecherzuweisung entfernen">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-trash-2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                            </button>
                        </div>` : ''}
                    </div>
                </div>
                ${controlsTop}
                <div class="transcript-text">${blockHTML}</div>
                ${controlsBottom}
                <div class="segment-actions">
                    ${copyBtn}
                </div>
            </div>`;
        });

        return formattedHTML || `<div class="transcript-segment"><div class="transcript-text">${Utils.escapeHTML(fullText)}</div></div>`;
    }

    pushToUndo() {
        if (!this.app.state.currentTranscriptSegments) return;
        const snapshot = JSON.parse(JSON.stringify(this.app.state.currentTranscriptSegments));
        this.app.state.transcriptUndoStack.push(snapshot);
        if (this.app.state.transcriptUndoStack.length > 10) {
            this.app.state.transcriptUndoStack.shift();
        }
        this.updateUndoButtonState();
    }

    undoLastMove() {
        if (this.app.state.transcriptUndoStack.length === 0) return;

        console.log("⏪ Undo triggered");
        const lastState = this.app.state.transcriptUndoStack.pop();
        this.app.state.currentTranscriptSegments = lastState;

        this.app.ui.renderTranscriptArea();
        this.saveCurrentSegmentsToServer();
        this.updateUndoButtonState();
    }

    updateUndoButtonState() {
        const btns = document.querySelectorAll('#undo-global-btn, #undo-reorder-btn, #undo-speaker-btn, #undo-redaction-btn');
        const hasHistory = this.app.state.transcriptUndoStack.length > 0;
        btns.forEach(btn => {
            btn.disabled = !hasHistory;
        });
    }

    moveSegment(segIdx, direction) {
        const segments = this.app.state.currentTranscriptSegments;
        if (!segments[segIdx]) return;

        this.pushToUndo();

        // Explicitly set speakers for all segments before moving
        // This ensures pause-based implicit blocks don't silently ignore the move
        const blocks = this.app.state.lastRenderedSpeakerBlocks;
        if (blocks) {
            blocks.forEach(block => {
                block.segmentIndices.forEach(idx => {
                    if (!segments[idx].speaker) {
                        segments[idx].speaker = block.speakerName;
                    }
                });
            });
        }

        let targetSpeaker = null;
        const currentSpeaker = segments[segIdx].speaker;
        
        if (direction === 'up') {
            for (let i = segIdx - 1; i >= 0; i--) {
                if (segments[i].speaker && segments[i].speaker !== currentSpeaker) {
                    targetSpeaker = segments[i].speaker;
                    break;
                }
            }
        } else if (direction === 'down') {
            const bounds = window.getSelectionBounds ? window.getSelectionBounds() : null;
            const endIdx = bounds ? bounds.end.segId : segIdx;
            for (let i = endIdx + 1; i < segments.length; i++) {
                if (segments[i].speaker && segments[i].speaker !== currentSpeaker) {
                    targetSpeaker = segments[i].speaker;
                    break;
                }
            }
        }
        
        if (!targetSpeaker) return;

        const bounds = window.getSelectionBounds ? window.getSelectionBounds() : null;

        if (bounds) {
            // We process END split first so it doesn't mess up start segment index!
            const endSeg = segments[bounds.end.segId];
            if (bounds.end.offset < endSeg.text.length && bounds.end.offset > 0) {
                // Split end segment
                const firstPart = endSeg.text.substring(0, bounds.end.offset);
                const secondPart = endSeg.text.substring(bounds.end.offset);
                
                const duration = endSeg.end - endSeg.start;
                const splitTime = endSeg.start + duration * (bounds.end.offset / endSeg.text.length);
                
                endSeg.text = firstPart;
                const oldEnd = endSeg.end;
                endSeg.end = splitTime;
                
                const newSeg = { ...endSeg, start: splitTime, end: oldEnd, text: secondPart };
                // Keep the original speaker for the remainder part
                newSeg.speaker = endSeg.speaker;
                segments.splice(bounds.end.segId + 1, 0, newSeg);
            }

            const startSeg = segments[bounds.start.segId];
            let actualStartIdx = bounds.start.segId;
            let actualEndIdx = bounds.end.segId;

            if (bounds.start.offset > 0 && bounds.start.offset < startSeg.text.length) {
                // Split start segment
                const firstPart = startSeg.text.substring(0, bounds.start.offset);
                const secondPart = startSeg.text.substring(bounds.start.offset);
                
                const duration = startSeg.end - startSeg.start;
                const splitTime = startSeg.start + duration * (bounds.start.offset / startSeg.text.length);
                
                startSeg.text = firstPart;
                const oldEnd = startSeg.end;
                startSeg.end = splitTime;
                
                const newSeg = { ...startSeg, start: splitTime, end: oldEnd, text: secondPart };
                segments.splice(bounds.start.segId + 1, 0, newSeg);
                
                actualStartIdx = bounds.start.segId + 1;
                if (bounds.start.segId === bounds.end.segId) {
                    actualEndIdx = actualStartIdx;
                } else {
                    actualEndIdx++; // shifted by 1
                }
            }

            const oldSpeakersMap = new Map();
            for (let i = actualStartIdx; i <= actualEndIdx; i++) {
                if (!oldSpeakersMap.has(segments[i].speaker)) {
                    oldSpeakersMap.set(segments[i].speaker, {
                        start: segments[i].start,
                        end: segments[i].end,
                        startIdx: i,
                        endIdx: i
                    });
                } else {
                    oldSpeakersMap.get(segments[i].speaker).endIdx = i;
                    oldSpeakersMap.get(segments[i].speaker).end = segments[i].end;
                }
                segments[i].speaker = targetSpeaker;
            }


        // Clear native selection so UI doesn't look weird after moving
            window.getSelection().removeAllRanges();
            
        } else {
            // Normal move if no text selected
            let startIdx = segIdx;
            let endIdx = segIdx;

            const selectedSegments = window.getSelectedSegmentIds ? window.getSelectedSegmentIds() : [];
            if (selectedSegments.length > 0) {
                startIdx = Math.min(...selectedSegments);
                endIdx = Math.max(...selectedSegments);
            }

            const oldSpeakersMap = new Map();
            for (let i = startIdx; i <= endIdx; i++) {
                if (!oldSpeakersMap.has(segments[i].speaker)) {
                    oldSpeakersMap.set(segments[i].speaker, {
                        start: segments[i].start,
                        end: segments[i].end,
                        startIdx: i,
                        endIdx: i
                    });
                } else {
                    oldSpeakersMap.get(segments[i].speaker).endIdx = i;
                    oldSpeakersMap.get(segments[i].speaker).end = segments[i].end;
                }
                segments[i].speaker = targetSpeaker;
            }

        }

        this.app.ui.renderTranscriptArea();

        if (this.app.state.currentTranscriptSlug) {
            this.saveCurrentSegmentsToServer();
        }
    }

    async optimizeSpeakersWithAI() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) return;

        const btn = document.getElementById('optimize-speakers-btn');
        if (btn) {
            btn.disabled = true;
            btn.classList.add('loading');
            btn.setAttribute('title', 'KI-Optimierung läuft...');
        }

        try {
            const res = await fetch('/req/transcription/optimize-speakers', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({ segments: this.app.state.currentTranscriptSegments })
            });

            const data = await res.json();
            if (data.success && data.segments) {
                // Save state to undo history before applying corrections
                this.pushToUndo();

                // Update state
                this.app.state.currentTranscriptSegments = data.segments;

                // Re-render and save changes to server
                this.app.ui.renderTranscriptArea();
                this.saveCurrentSegmentsToServer();

                if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
                    await window.openModal(window.ModalType.INFO, "Sprecherzuordnung erfolgreich per KI optimiert!", "Erfolg");
                } else {
                    alert("Sprecherzuordnung erfolgreich per KI optimiert!");
                }
            } else {
                throw new Error(data.error || "Unbekannter Fehler bei der Sprecher-Optimierung.");
            }
        } catch (e) {
            console.error("AI Speaker Optimization failed:", e);
            if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
                await window.openModal(window.ModalType.ERROR, "Fehler bei der Sprecher-Optimierung: " + e.message, "Fehler");
            } else {
                alert("Fehler bei der Sprecher-Optimierung: " + e.message);
            }
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.setAttribute('title', 'Sprecher per KI optimieren');
            }
        }
    }

    async saveCurrentSegmentsToServer() {
        if (!this.app.state.currentTranscriptSlug) return;
        
        // Ensure transcript text is synchronized in the frontend state whenever segments are saved
        this.app.state.currentTranscriptText = this.buildTranscriptTextFromSegments();

        this.app.state.activeSavePromise = (async () => {
            try {
                await fetch(`/req/transcription/${this.app.state.currentTranscriptSlug}/segments`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    },
                    body: JSON.stringify({
                        segments: this.app.state.currentTranscriptSegments,
                        speaker_color_map: Object.fromEntries(this.app.state.speakerColorMap || new Map())
                    })
                });
            } catch (e) {
                console.warn("Failed to sync segments:", e);
            } finally {
                this.app.state.activeSavePromise = null;
                if (this.app.ui && this.app.ui.updateSidebarSaveButtonState) {
                    this.app.ui.updateSidebarSaveButtonState();
                }
            }
        })();
        
        if (this.app.ui && this.app.ui.updateSidebarSaveButtonState) {
            this.app.ui.updateSidebarSaveButtonState();
        }
        
        return this.app.state.activeSavePromise;
    }

    updateSegmentText(idx, newText) {
        if (!this.app.state.currentTranscriptSegments[idx]) return;
        
        // Remove linebreaks but preserve spaces
        const sanitized = newText.replace(/[\r\n]+/g, '');
        const seg = this.app.state.currentTranscriptSegments[idx];

        // If the text hasn't actually changed, do nothing
        if (seg.text === sanitized) return;

        // If text was cleared completely, mark it as placeholder
        if (sanitized.trim() === '') {
            seg.text = "[Dieser Sprecher hat noch keinen Text!]";
        } else {
            seg.text = sanitized;
        }

        // Clear redactions since indices might be invalid now
        if (seg.redactions && seg.redactions.length > 0) {
            seg.redactions = [];
        }

        this.saveCurrentSegmentsToServer();
        this.app.ui.updateSidebarSaveButtonState();
    }

    cleanupOrphanedPlaceholders() {
        if (!this.app.state.currentTranscriptSegments) return;
        const segments = this.app.state.currentTranscriptSegments;
        const placeholderText = "[Dieser Sprecher hat noch keinen Text!]";
        
        let indicesToRemove = [];
        let currentSpeaker = null;
        let currentGroup = [];
        let currentGroupStartIndex = 0;

        const processGroup = (group, startIndex) => {
            const hasRealText = group.some(seg => seg.text && seg.text !== placeholderText && seg.text.trim() !== '');
            group.forEach((seg, i) => {
                if (seg.text === placeholderText) {
                    // If group has real text, remove all placeholders in this group
                    if (hasRealText) {
                        indicesToRemove.push(startIndex + i);
                    } else {
                        // If group is ONLY placeholders, keep the first one, remove the rest
                        if (i > 0) {
                            indicesToRemove.push(startIndex + i);
                        }
                    }
                }
            });
        };

        segments.forEach((seg, idx) => {
            if (seg.speaker !== currentSpeaker) {
                if (currentGroup.length > 0) {
                    processGroup(currentGroup, currentGroupStartIndex);
                }
                currentSpeaker = seg.speaker;
                currentGroup = [seg];
                currentGroupStartIndex = idx;
            } else {
                currentGroup.push(seg);
            }
        });
        
        if (currentGroup.length > 0) {
            processGroup(currentGroup, currentGroupStartIndex);
        }

        if (indicesToRemove.length > 0) {
            indicesToRemove.sort((a,b) => b-a).forEach(idx => {
                this.app.state.currentTranscriptSegments.splice(idx, 1);
            });
        }
    }


    showReassignSubmenu(event, blockIndex) {
        if (event) event.stopPropagation();
        const menu = document.getElementById('custom-context-menu');
        if (!menu || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        // Toggle behavior: if already open for this speaker block, close it
        if (!menu.classList.contains('hidden') && 
            menu.dataset.blockIndex === String(blockIndex) && 
            menu.dataset.menuType === 'reassign') {
            menu.classList.add('hidden');
            menu.style.display = '';
            return;
        }
        menu.dataset.blockIndex = blockIndex;
        menu.dataset.menuType = 'reassign';

        const currentSpeaker = this.app.state.lastRenderedSpeakerBlocks[blockIndex].speakerName;
        const speakers = [...new Set(this.app.state.lastRenderedSpeakerBlocks.map(b => b.speakerName))]
            .filter(s => s && s !== currentSpeaker);

        menu.innerHTML = '';
        const list = document.createElement('div');
        list.className = 'speaker-menu-list';

        const header = document.createElement('div');
        header.className = 'speaker-menu-header';
        header.textContent = 'Zuweisen an:';
        list.appendChild(header);

        speakers.forEach(speaker => {
            const item = document.createElement('div');
            item.className = 'speaker-menu-item';
            item.textContent = speaker;
            item.onclick = () => window.reassignSpeaker(blockIndex, speaker);
            list.appendChild(item);
        });

        const divider1 = document.createElement('div');
        divider1.className = 'speaker-menu-divider';
        list.appendChild(divider1);

        const newSpeakerItem = document.createElement('div');
        newSpeakerItem.className = 'speaker-menu-item new-speaker';
        newSpeakerItem.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
        newSpeakerItem.appendChild(document.createTextNode(' Neuer Sprecher'));
        newSpeakerItem.onclick = (e) => window.showNewSpeakerInline(e, blockIndex);
        list.appendChild(newSpeakerItem);

        menu.appendChild(list);

        menu.classList.remove('hidden');
        menu.style.display = ''; // Ensure no inline style is hiding it
        if (event && event.currentTarget) {
            if (menu.parentNode !== document.body) {
                document.body.appendChild(menu);
            }
            const rect = event.currentTarget.getBoundingClientRect();
            
            // Center the dropdown horizontally relative to the icon
            const menuWidth = 200; // Expected min-width
            let leftPos = rect.left + window.scrollX - (menuWidth / 2) + (rect.width / 2);
            let arrowPos = menuWidth / 2;
            
            // Adjust if it goes off-screen
            if (leftPos + menuWidth > window.innerWidth) {
                const shift = (leftPos + menuWidth) - window.innerWidth + 20;
                leftPos -= shift;
                arrowPos += shift;
            } else if (leftPos < 20) {
                const shift = 20 - leftPos;
                leftPos += shift;
                arrowPos -= shift;
            }
            
            menu.style.left = leftPos + 'px';
            menu.style.top = (rect.bottom + window.scrollY + 8) + 'px';
            menu.style.setProperty('--arrow-pos', arrowPos + 'px');

            setTimeout(() => {
                const menuRect = menu.getBoundingClientRect();
                if (menuRect.width !== menuWidth) {
                    // Re-adjust if actual width is different
                    let newLeft = rect.left + window.scrollX - (menuRect.width / 2) + (rect.width / 2);
                    let newArrowPos = menuRect.width / 2;
                    
                    if (newLeft + menuRect.width > window.innerWidth) {
                        const shift = (newLeft + menuRect.width) - window.innerWidth + 20;
                        newLeft -= shift;
                        newArrowPos += shift;
                    } else if (newLeft < 20) {
                        const shift = 20 - newLeft;
                        newLeft += shift;
                        newArrowPos -= shift;
                    }
                    
                    menu.style.left = newLeft + 'px';
                    menu.style.setProperty('--arrow-pos', newArrowPos + 'px');
                }
            }, 0);
        }

        menu.onclick = (e) => e.stopPropagation();

        const closeMenu = (e) => {
            if (e && e.type === 'click' && menu.contains(e.target)) return;
            menu.classList.add('hidden');
            document.removeEventListener('click', closeMenu);
            window.removeEventListener('scroll', closeMenu, true);
        };
        setTimeout(() => {
            document.addEventListener('click', closeMenu);
            window.addEventListener('scroll', closeMenu, true);
        }, 0);
    }


    reassignSpeaker(blockIndex, newSpeaker) {
        if (!this.app.state.lastRenderedSpeakerBlocks || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        this.pushToUndo();

        const block = this.app.state.lastRenderedSpeakerBlocks[blockIndex];

        block.segmentIndices.forEach(idx => {
            this.app.state.currentTranscriptSegments[idx].speaker = newSpeaker;
        });

        this.app.ui.renderTranscriptArea();
        this.saveCurrentSegmentsToServer();
        const menu = document.getElementById('custom-context-menu');
        if (menu) menu.classList.add('hidden');
    }

    removeSpeaker(blockIndex) {
        if (!this.app.state.lastRenderedSpeakerBlocks || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        // Prevent removing if it's the only block
        if (this.app.state.lastRenderedSpeakerBlocks.length <= 1) {
            console.warn("Cannot remove the only speaker block.");
            return;
        }

        this.pushToUndo();

        // Ensure all segments have explicit speakers set before we modify
        // This prevents implicit pause-based assignments from moving segments
        const blocks = this.app.state.lastRenderedSpeakerBlocks;
        blocks.forEach(b => {
            b.segmentIndices.forEach(idx => {
                if (!this.app.state.currentTranscriptSegments[idx].speaker) {
                    this.app.state.currentTranscriptSegments[idx].speaker = b.speakerName;
                }
            });
        });

        const block = this.app.state.lastRenderedSpeakerBlocks[blockIndex];

        // Determine target speaker (prefer previous block, fallback to next)
        let targetSpeaker = null;
        if (blockIndex > 0) {
            targetSpeaker = this.app.state.lastRenderedSpeakerBlocks[blockIndex - 1].speakerName;
        } else {
            targetSpeaker = this.app.state.lastRenderedSpeakerBlocks[blockIndex + 1].speakerName;
        }

        const indicesToRemove = [];

        block.segmentIndices.forEach(idx => {
            const seg = this.app.state.currentTranscriptSegments[idx];
            if (seg.text === "[Dieser Sprecher hat noch keinen Text!]") {
                indicesToRemove.push(idx);
            } else {
                seg.speaker = targetSpeaker;
            }
        });

        // Remove placeholders in reverse order to maintain indices
        indicesToRemove.sort((a, b) => b - a).forEach(idx => {
            this.app.state.currentTranscriptSegments.splice(idx, 1);
        });

        this.app.ui.renderTranscriptArea();
        this.saveCurrentSegmentsToServer();
        
        const menu = document.getElementById('custom-context-menu');
        if (menu) menu.classList.add('hidden');
    }

    showNewSpeakerInline(event, blockIndex) {
        if (event) event.stopPropagation();

        // Close any previously opened inline rename inputs
        document.querySelectorAll('.inline-rename-cancel').forEach(btn => btn.click());

        const target = event.currentTarget;
        if (!target) return;

        const currentName = target.innerHTML;

        const restoreOriginal = () => {
            target.innerHTML = currentName;
            target.classList.remove('target-reset-styles');
            target.onclick = (e) => window.showNewSpeakerInline(e, blockIndex);
            document.removeEventListener('click', outsideClickListener);
        };

        const outsideClickListener = (e) => {
            if (!container.contains(e.target)) {
                restoreOriginal();
            }
        };

        target.onclick = null;
        target.classList.add('target-reset-styles');

        target.innerHTML = '';
        const container = document.createElement('div');
        container.className = 'speaker-menu-input-container';
        container.onclick = (e) => e.stopPropagation();

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'speaker-menu-input';
        input.id = 'inline-speaker-input';
        input.placeholder = 'Name...';
        input.onkeyup = (e) => { 
            if(e.key === 'Enter') {
                window.confirmInlineSpeaker(blockIndex, input.value, restoreOriginal);
            } else if (e.key === 'Escape') {
                restoreOriginal();
            }
        };

        const btn = document.createElement('button');
        btn.className = 'speaker-menu-confirm-btn';
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        btn.onclick = (e) => {
            e.stopPropagation();
            window.confirmInlineSpeaker(blockIndex, input.value, restoreOriginal);
        };

        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'inline-rename-cancel';
        cancelBtn.style.display = 'none';
        cancelBtn.onclick = (e) => {
            e.stopPropagation();
            restoreOriginal();
        };

        container.appendChild(input);
        container.appendChild(btn);
        container.appendChild(cancelBtn);
        target.appendChild(container);

        setTimeout(() => {
            document.addEventListener('click', outsideClickListener);
            input.focus();
        }, 50);
    }

    confirmInlineSpeaker(blockIndex, name, restoreOriginal) {
        if (name && name.trim()) {
            this.reassignSpeaker(blockIndex, name.trim());
        } else if (restoreOriginal) {
            restoreOriginal();
        }
    }

    showRenameSpeakerInline(event, blockIndex) {
        if (event) event.stopPropagation();

        // Close any custom context menu if open, as stopPropagation prevents the document listener
        const menu = document.getElementById('custom-context-menu');
        if (menu) {
            menu.classList.add('hidden');
            menu.style.display = '';
            menu.innerHTML = '';
            menu.dataset.menuType = '';
            menu.dataset.blockIndex = '';
        }

        // Close any previously opened inline rename inputs
        document.querySelectorAll('.inline-rename-cancel').forEach(btn => btn.click());

        const target = event.currentTarget;
        if (!target || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        const currentName = this.app.state.lastRenderedSpeakerBlocks[blockIndex].speakerName;

        const restoreOriginal = () => {
            target.innerHTML = Utils.escapeHTML(currentName);
            target.classList.remove('target-reset-styles');
            target.onclick = (e) => this.showRenameSpeakerInline(e, blockIndex);
            document.removeEventListener('click', outsideClickListener);
        };

        const outsideClickListener = (e) => {
            if (!container.contains(e.target)) {
                restoreOriginal();
            }
        };

        target.onclick = null;
        target.classList.add('target-reset-styles');

        target.innerHTML = '';
        const container = document.createElement('div');
        container.className = 'speaker-menu-input-container';
        container.onclick = (e) => e.stopPropagation();

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'speaker-menu-input';
        input.value = currentName;
        input.onkeyup = (e) => { 
            if(e.key === 'Enter') {
                window.confirmRenameSpeaker(blockIndex, input.value, restoreOriginal);
            } else if (e.key === 'Escape') {
                restoreOriginal();
            }
        };

        const btn = document.createElement('button');
        btn.className = 'speaker-menu-confirm-btn';
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        btn.onclick = (e) => {
            e.stopPropagation();
            window.confirmRenameSpeaker(blockIndex, input.value, restoreOriginal);
        };

        // Hidden cancel button for programmatic closing when opening another input
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'inline-rename-cancel';
        cancelBtn.style.display = 'none';
        cancelBtn.onclick = (e) => {
            e.stopPropagation();
            restoreOriginal();
        };

        container.appendChild(input);
        container.appendChild(btn);
        container.appendChild(cancelBtn);
        target.appendChild(container);

        setTimeout(() => {
            document.addEventListener('click', outsideClickListener);
            input.focus();
            input.select();
        }, 50);
    }

    confirmRenameSpeaker(blockIndex, newName, restoreOriginal) {
        const sanitized = newName.trim();
        if (!sanitized || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) {
            if (restoreOriginal) restoreOriginal();
            return;
        }

        const oldName = this.app.state.lastRenderedSpeakerBlocks[blockIndex].speakerName;
        if (sanitized === oldName) {
            if (restoreOriginal) restoreOriginal();
            return;
        }

        if (restoreOriginal) {
            restoreOriginal();
        }

        this.pushToUndo();

        this.app.state.currentTranscriptSegments.forEach(seg => {
            if (seg.speaker === oldName) {
                seg.speaker = sanitized;
            }
        });

        this.app.ui.renderTranscriptArea();
        this.saveCurrentSegmentsToServer();
    }

    insertSpeakerAt(event, blockIndex, position) {
        if (event) event.stopPropagation();
        const menu = document.getElementById('custom-context-menu');
        if (!menu || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        // Toggle behavior: if already open for this insertion, close it
        if (!menu.classList.contains('hidden') && 
            menu.dataset.blockIndex === String(blockIndex) && 
            menu.dataset.menuType === 'insert-' + position) {
            menu.classList.add('hidden');
            menu.style.display = '';
            return;
        }
        menu.dataset.blockIndex = blockIndex;
        menu.dataset.menuType = 'insert-' + position;

        const currentSpeaker = this.app.state.lastRenderedSpeakerBlocks[blockIndex].speakerName;
        const speakers = [...new Set(this.app.state.currentTranscriptSegments.map(s => s.speaker || 'Person 1'))]
            .filter(s => s && s !== currentSpeaker);

        menu.innerHTML = '';
        const list = document.createElement('div');
        list.className = 'speaker-menu-list';

        const header = document.createElement('div');
        header.className = 'speaker-menu-header';
        header.textContent = `Sprecher ${position === 'above' ? 'davor' : 'danach'} einfügen:`;
        list.appendChild(header);

        speakers.forEach(speaker => {
            const item = document.createElement('div');
            item.className = 'speaker-menu-item';
            item.textContent = speaker;
            item.onclick = () => window.performSpeakerInsertion(blockIndex, position, speaker);
            list.appendChild(item);
        });

        const divider1 = document.createElement('div');
        divider1.className = 'speaker-menu-divider';
        list.appendChild(divider1);

        const newSpeakerItem = document.createElement('div');
        newSpeakerItem.className = 'speaker-menu-item new-speaker';
        newSpeakerItem.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>';
        newSpeakerItem.appendChild(document.createTextNode(' Neuer Sprecher'));
        newSpeakerItem.onclick = (e) => window.showNewSpeakerInlineForInsertion(e, blockIndex, position);
        list.appendChild(newSpeakerItem);

        menu.appendChild(list);

        menu.classList.remove('hidden');
        menu.style.display = ''; // Ensure no inline style is hiding it
        if (event && event.currentTarget) {
            if (menu.parentNode !== document.body) {
                document.body.appendChild(menu);
            }
            const rect = event.currentTarget.getBoundingClientRect();
            
            // Center the dropdown horizontally relative to the icon
            const menuWidth = 200; // Expected min-width
            let leftPos = rect.left + window.scrollX - (menuWidth / 2) + (rect.width / 2);
            let arrowPos = menuWidth / 2;
            
            // Adjust if it goes off-screen
            if (leftPos + menuWidth > window.innerWidth) {
                const shift = (leftPos + menuWidth) - window.innerWidth + 20;
                leftPos -= shift;
                arrowPos += shift;
            } else if (leftPos < 20) {
                const shift = 20 - leftPos;
                leftPos += shift;
                arrowPos -= shift;
            }
            
            menu.style.left = leftPos + 'px';
            menu.style.top = (rect.bottom + window.scrollY + 8) + 'px';
            menu.style.setProperty('--arrow-pos', arrowPos + 'px');

            setTimeout(() => {
                const menuRect = menu.getBoundingClientRect();
                if (menuRect.width !== menuWidth) {
                    // Re-adjust if actual width is different
                    let newLeft = rect.left + window.scrollX - (menuRect.width / 2) + (rect.width / 2);
                    let newArrowPos = menuRect.width / 2;
                    
                    if (newLeft + menuRect.width > window.innerWidth) {
                        const shift = (newLeft + menuRect.width) - window.innerWidth + 20;
                        newLeft -= shift;
                        newArrowPos += shift;
                    } else if (newLeft < 20) {
                        const shift = 20 - newLeft;
                        newLeft += shift;
                        newArrowPos -= shift;
                    }
                    
                    menu.style.left = newLeft + 'px';
                    menu.style.setProperty('--arrow-pos', newArrowPos + 'px');
                }
            }, 0);
        }

        menu.onclick = (e) => e.stopPropagation();

        const closeMenu = (e) => {
            if (e && e.type === 'click' && menu.contains(e.target)) return;
            menu.classList.add('hidden');
            document.removeEventListener('click', closeMenu);
            window.removeEventListener('scroll', closeMenu, true);
        };
        setTimeout(() => {
            document.addEventListener('click', closeMenu);
            window.addEventListener('scroll', closeMenu, true);
        }, 0);
    }

    performSpeakerInsertion(blockIndex, position, speakerName) {
        if (!this.app.state.lastRenderedSpeakerBlocks || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        this.pushToUndo();

        // Explicitly set speakers for all segments before structure change
        const blocks = this.app.state.lastRenderedSpeakerBlocks;
        blocks.forEach(b => {
            b.segmentIndices.forEach(idx => {
                if (!this.app.state.currentTranscriptSegments[idx].speaker) {
                    this.app.state.currentTranscriptSegments[idx].speaker = b.speakerName;
                }
            });
        });

        const block = this.app.state.lastRenderedSpeakerBlocks[blockIndex];
        let targetIdx;
        let baseTime;

        if (position === 'above') {
            targetIdx = block.segmentIndices[0];
            baseTime = this.app.state.currentTranscriptSegments[targetIdx].start;
        } else {
            targetIdx = block.segmentIndices[block.segmentIndices.length - 1] + 1;
            // Handle edge case where targetIdx is out of bounds
            if (targetIdx <= this.app.state.currentTranscriptSegments.length) {
                baseTime = this.app.state.currentTranscriptSegments[targetIdx - 1].end;
            } else {
                baseTime = this.app.state.currentTranscriptSegments[targetIdx - 2].end;
            }
        }

        const newSeg = {
            speaker: speakerName,
            text: "[Dieser Sprecher hat noch keinen Text!]",
            start: baseTime,
            end: baseTime + 1.0,
            avg_logprob: 0,
            no_speech_prob: 0,
            compression_ratio: 0
        };

        this.app.state.currentTranscriptSegments.splice(targetIdx, 0, newSeg);

        this.app.ui.renderTranscriptArea();
        this.saveCurrentSegmentsToServer();
        const menu = document.getElementById('custom-context-menu');
        if (menu) menu.classList.add('hidden');
    }

    showNewSpeakerInlineForInsertion(event, blockIndex, position) {
        if (event) event.stopPropagation();

        document.querySelectorAll('.inline-rename-cancel').forEach(btn => btn.click());

        const target = event.currentTarget;
        if (!target) return;

        const currentName = target.innerHTML;

        const restoreOriginal = () => {
            target.innerHTML = currentName;
            target.classList.remove('target-reset-styles');
            target.onclick = (e) => window.showNewSpeakerInlineForInsertion(e, blockIndex, position);
            document.removeEventListener('click', outsideClickListener);
        };

        const outsideClickListener = (e) => {
            if (!container.contains(e.target)) {
                restoreOriginal();
            }
        };

        target.onclick = null;
        target.classList.add('target-reset-styles');

        target.innerHTML = '';
        const container = document.createElement('div');
        container.className = 'speaker-menu-input-container';
        container.onclick = (e) => e.stopPropagation();

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'speaker-menu-input';
        input.id = 'inline-insert-input';
        input.placeholder = 'Name...';
        input.onkeyup = (e) => { 
            if(e.key === 'Enter') {
                window.confirmInlineInsertion(blockIndex, position, input.value, restoreOriginal);
            } else if (e.key === 'Escape') {
                restoreOriginal();
            }
        };

        const btn = document.createElement('button');
        btn.className = 'speaker-menu-confirm-btn';
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        btn.onclick = (e) => {
            e.stopPropagation();
            window.confirmInlineInsertion(blockIndex, position, input.value, restoreOriginal);
        };

        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'inline-rename-cancel';
        cancelBtn.style.display = 'none';
        cancelBtn.onclick = (e) => {
            e.stopPropagation();
            restoreOriginal();
        };

        container.appendChild(input);
        container.appendChild(btn);
        container.appendChild(cancelBtn);
        target.appendChild(container);
        
        setTimeout(() => {
            document.addEventListener('click', outsideClickListener);
            const inputEl = document.getElementById('inline-insert-input');
            if (inputEl) inputEl.focus();
        }, 50);
    }

    confirmInlineInsertion(blockIndex, position, name, restoreOriginal) {
        if (name && name.trim()) {
            this.performSpeakerInsertion(blockIndex, position, name.trim());
        } else if (restoreOriginal) {
            restoreOriginal();
        }
    }

    buildTranscriptTextFromSegments() {
        if (!this.app.state.currentTranscriptSegments) return '';

        let blocks = [];
        let currentBlock = null;
        let lastEndTime = 0;

        this.app.state.currentTranscriptSegments.forEach((segment) => {
            if (!segment || typeof segment.text !== 'string') return;
            
            const rawText = segment.text;
            if (rawText.trim() === '' || rawText.trim() === '[Dieser Sprecher hat noch keinen Text!]') return;

            const isDifferentSpeaker = currentBlock && segment.speaker !== currentBlock.speaker;
            const isPause = currentBlock && (segment.start - lastEndTime > 2.0);

            if (!currentBlock || isDifferentSpeaker || isPause) {
                currentBlock = {
                    speaker: (segment.speaker || '').trim(),
                    text: rawText.trimLeft()
                };
                blocks.push(currentBlock);
            } else {
                if (!currentBlock.text.endsWith(' ') && !rawText.startsWith(' ') && !/^[.,!?:;]/.test(rawText.trim())) {
                    currentBlock.text += ' ';
                }
                currentBlock.text += rawText;
            }
            lastEndTime = segment.end;
        });

        let lines = [];
        blocks.forEach(block => {
            const cleanText = block.text.trim();
            if (block.speaker !== '') {
                lines.push(`${block.speaker}: ${cleanText}`);
            } else {
                lines.push(cleanText);
            }
        });

        return lines.join('\n\n');
    }

    renderRedactionList() {
        const listEl = document.getElementById('redaction-list');
        if (!listEl) return;

        const segments = this.app.state.currentTranscriptSegments || [];
        const entries = [];

        segments.forEach((seg, segIdx) => {
            if (!seg.redactions || seg.redactions.length === 0) return;
            const baseText = seg.text || '';
            seg.redactions.forEach((red, redIdx) => {
                entries.push({
                    segIdx,
                    redIdx,
                    speaker: seg.speaker || 'Unbekannt',
                    redactedText: baseText.substring(red.start, red.end),
                });
            });
        });

        const accordion = document.getElementById('redaction-accordion');
        const countEl = document.getElementById('redaction-count');

        if (entries.length === 0) {
            if (accordion) accordion.classList.add('hidden');
            if (listEl) listEl.innerHTML = '<p class="empty-redactions-text">Keine Ausblendungen vorhanden.</p>';
            return;
        }

        if (accordion) accordion.classList.remove('hidden');
        if (countEl) countEl.textContent = entries.length;

        let html = '';
        entries.forEach(({ segIdx, redIdx, speaker, redactedText }) => {
            const displayText = redactedText.length > 60
                ? redactedText.substring(0, 57) + '...'
                : redactedText;

            html += `
            <div class="redaction-list-item" data-seg="${segIdx}" data-red="${redIdx}">
                <div class="redaction-list-content">
                    <span class="redaction-list-speaker">${Utils.escapeHTML(speaker)}</span>
                    <span class="redaction-list-text">&bdquo;${Utils.escapeHTML(displayText)}&ldquo;</span>
                </div>
                <button class="redaction-remove-btn"
                    onclick="window.removeRedaction(${segIdx}, ${redIdx})"
                    title="Ausblendung entfernen">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                </button>
            </div>`;
        });

        listEl.innerHTML = html;
    }

    removeRedaction(segIdx, redIdx) {
        const segment = this.app.state.currentTranscriptSegments[segIdx];
        if (!segment || !segment.redactions) return;

        this.pushToUndo();
        segment.redactions.splice(redIdx, 1);

        this.app.ui.renderTranscriptArea();
        this.renderRedactionList();
        this.saveCurrentSegmentsToServer();
    }

    clearAllRedactions() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) return;

        const hasAny = this.app.state.currentTranscriptSegments.some(s => s.redactions && s.redactions.length > 0);
        if (!hasAny) return;

        this.pushToUndo();
        this.app.state.currentTranscriptSegments.forEach(seg => {
            seg.redactions = [];
        });

        this.app.ui.renderTranscriptArea();
        this.renderRedactionList();
        this.saveCurrentSegmentsToServer();
    }

    handleTextSelection(e) {
        if (!this.app.state.editModeActive) return;

        if (e && e.target && e.target.closest('#selection-toolbar')) {
            return;
        }

        const selection = window.getSelection();
        const toolbar = document.getElementById('selection-toolbar');

        if (!selection || selection.isCollapsed || selection.toString().trim() === '') {
            if (toolbar) toolbar.remove();
            return;
        }

        const range = selection.getRangeAt(0);

        const resolveSegItem = (node) => {
            const el = node.nodeType === 3 ? node.parentElement : node;
            return el ? el.closest('.transcript-seg-item') : null;
        };

        const startTextContainer = range.startContainer.parentElement ? range.startContainer.parentElement.closest('.transcript-text') : null;
        const endTextContainer = range.endContainer.parentElement ? range.endContainer.parentElement.closest('.transcript-text') : null;

        if (!startTextContainer || !endTextContainer || startTextContainer !== endTextContainer) {
            if (toolbar) toolbar.remove();
            return;
        }

        const startSegItem = resolveSegItem(range.startContainer) || startTextContainer.querySelector('.transcript-seg-item');
        if (!startSegItem) {
            if (toolbar) toolbar.remove();
            return;
        }

        const segId = startSegItem.getAttribute('data-seg-id');
        this.showSelectionToolbar(range, segId);
    }

    showSelectionToolbar(range, segId) {
        let toolbar = document.getElementById('selection-toolbar');
        if (!toolbar) {
            toolbar = document.createElement('div');
            toolbar.id = 'selection-toolbar';
            document.body.appendChild(toolbar);
        }

        toolbar.innerHTML = `
            <button class="context-menu-btn" onmousedown="event.preventDefault(); window.redactSelectedText();" title="Text ausblenden (Schwärzen)">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                Ausblenden
            </button>
            <div class="context-menu-divider"></div>
            <button class="context-menu-btn" onmousedown="event.preventDefault(); window.moveSegment(${segId}, 'up'); document.getElementById('selection-toolbar')?.remove(); window.getSelection().removeAllRanges();" title="Nach oben schieben">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="8.5" cy="7" r="4"></circle>
                    <polyline points="17 13 20 10 23 13"></polyline>
                    <polyline points="17 18 20 15 23 18"></polyline>
                </svg>
                Nach oben schieben
            </button>
            <button class="context-menu-btn" onmousedown="event.preventDefault(); window.moveSegment(${segId}, 'down'); document.getElementById('selection-toolbar')?.remove(); window.getSelection().removeAllRanges();" title="Nach unten schieben">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="8.5" cy="7" r="4"></circle>
                    <polyline points="17 10 20 13 23 10"></polyline>
                    <polyline points="17 15 20 18 23 15"></polyline>
                </svg>
                Nach unten schieben
            </button>
        `;

        const rect = range.getBoundingClientRect();
        toolbar.style.top = `${rect.top + window.scrollY}px`;
        toolbar.style.left = `${rect.left + rect.width / 2}px`;
    }

    redactSelectedText() {
        const bounds = window.getSelectionBounds ? window.getSelectionBounds() : null;
        if (!bounds) {
            const selection = window.getSelection();
            if (selection) selection.removeAllRanges();
            const toolbar = document.getElementById('selection-toolbar');
            if (toolbar) toolbar.remove();
            return;
        }

        this.pushToUndo();

        for (let i = bounds.start.segId; i <= bounds.end.segId; i++) {
            const segment = this.app.state.currentTranscriptSegments[i];
            if (!segment) continue;

            let trimmedStart = (i === bounds.start.segId) ? bounds.start.offset : 0;
            let trimmedEnd = (i === bounds.end.segId) ? bounds.end.offset : segment.text.length;

            // Trim leading/trailing spaces for the redaction range
            while (trimmedStart < trimmedEnd && /^\s$/.test(segment.text[trimmedStart])) trimmedStart++;
            while (trimmedEnd > trimmedStart && /^\s$/.test(segment.text[trimmedEnd - 1])) trimmedEnd--;

            if (trimmedStart >= trimmedEnd) continue;

            if (!segment.redactions) segment.redactions = [];
            segment.redactions.push({ start: trimmedStart, end: trimmedEnd });

            segment.redactions.sort((a, b) => a.start - b.start);
            const merged = [];
            for (const red of segment.redactions) {
                if (merged.length > 0 && red.start <= merged[merged.length - 1].end) {
                    merged[merged.length - 1].end = Math.max(merged[merged.length - 1].end, red.end);
                } else {
                    merged.push({ ...red });
                }
            }
            segment.redactions = merged;
        }

        this.app.ui.renderTranscriptArea();
        this.renderRedactionList();
        this.saveCurrentSegmentsToServer();

        const selection = window.getSelection();
        if (selection) selection.removeAllRanges();
        const toolbar = document.getElementById('selection-toolbar');
        if (toolbar) toolbar.remove();
    }

    populateSpeakerPanel() {
        const container = document.getElementById('sidebar-speaker-list');
        const countSpan = document.getElementById('sidebar-speaker-count');
        if (!container) return;

        const uniqueSpeakers = new Map();
        
        if (this.app.state.lastRenderedSpeakerBlocks) {
            this.app.state.lastRenderedSpeakerBlocks.forEach(block => {
                if (!uniqueSpeakers.has(block.speakerName)) {
                    uniqueSpeakers.set(block.speakerName, block.colorId);
                }
            });
        }

        if (countSpan) {
            countSpan.textContent = uniqueSpeakers.size;
        }

        container.innerHTML = '';
        
        uniqueSpeakers.forEach((colorId, speakerName) => {
            const speakerItem = document.createElement('div');
            speakerItem.className = 'export-option-card-compact sidebar-speaker-card';
            
            speakerItem.onclick = (e) => {
                // While a speaker is soloed (preview mode), clicking a card switches the solo
                if (!this.app.state.editModeActive
                    && document.querySelector('#sidebar-speaker-list .speaker-avatar.solo-active')) {
                    this.toggleSoloSpeaker(speakerName);
                    return;
                }

                document.querySelectorAll('.sidebar-speaker-card').forEach(c => {
                    if (c !== speakerItem) c.classList.remove('active');
                });
                
                const isActive = speakerItem.classList.toggle('active');
                
                let firstMatch = null;
                
                document.querySelectorAll('.transcript-segment').forEach(block => {
                    if (block.dataset.speaker === speakerName) {
                        if (isActive) {
                            block.classList.add('speaker-highlighted');
                            if (!firstMatch) firstMatch = block;
                        } else {
                            block.classList.remove('speaker-highlighted');
                        }
                    } else {
                        block.classList.remove('speaker-highlighted');
                    }
                });
                
                if (isActive && firstMatch) {
                    const rect = firstMatch.getBoundingClientRect();
                    // Check if element is out of the viewport
                    if (rect.top < 0 || rect.bottom > window.innerHeight) {
                        firstMatch.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            };
            
            const avatar = document.createElement('div');
            avatar.className = `speaker-avatar speaker-color-${colorId}`;
            avatar.style.flexShrink = '0';

            if (this.app.state.editModeActive) {
                avatar.style.cursor = 'pointer';
                avatar.title = 'Farbe ändern';
                avatar.innerHTML = '<svg class="avatar-hover-edit" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>';
                avatar.onclick = (e) => {
                    e.stopPropagation();
                    this.app.ui.showAvatarPicker(avatar, null, speakerName, null, null, null);
                };
            } else {
                // Preview mode: avatar acts as a solo toggle for this speaker
                const allNames = Array.from(uniqueSpeakers.keys());
                const hidden = this.app.state.hiddenSpeakers;
                const isSoloed = allNames.length > 1 && !hidden.has(speakerName)
                    && allNames.every(n => n === speakerName || hidden.has(n));
                avatar.style.cursor = 'pointer';
                avatar.title = isSoloed ? 'Alle Sprecher anzeigen' : 'Nur diesen Sprecher anzeigen';
                avatar.innerHTML = '<svg class="avatar-hover-solo" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/></svg>';
                if (isSoloed) {
                    avatar.classList.add('solo-active');
                    speakerItem.classList.add('active');
                }
                avatar.onclick = (e) => {
                    e.stopPropagation();
                    this.toggleSoloSpeaker(speakerName);
                };
            }
            
            const info = document.createElement('div');
            info.className = 'card-details';
            info.style.flex = '1';
            
            const nameHeader = document.createElement('h4');
            nameHeader.className = 'speaker-label';
            nameHeader.textContent = speakerName;
            
            info.appendChild(nameHeader);
            
            let editBtn = null;
            if (this.app.state.editModeActive) {
                editBtn = document.createElement('button');
                editBtn.className = 'btn-sidebar-action btn-speaker-edit';
                editBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>';
                editBtn.style.background = 'transparent';
                editBtn.style.border = 'none';
                editBtn.style.cursor = 'pointer';
                editBtn.title = 'Sprecher umbenennen';
                editBtn.onclick = (e) => {
                    e.stopPropagation();
                    this.showRenameSpeakerFromSidebar(e, speakerName, nameHeader);
                };
            }

            const filterBtn = document.createElement('button');
            filterBtn.className = 'btn-sidebar-action filter-btn';
            
            // Initial state check
            let isHidden = this.app.state.hiddenSpeakers.has(speakerName);

            const eyeIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
            const eyeOffIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>';

            filterBtn.innerHTML = isHidden ? eyeOffIcon : eyeIcon;
            if (isHidden) filterBtn.classList.add('speaker-off');
            
            filterBtn.style.background = 'transparent';
            filterBtn.style.border = 'none';
            filterBtn.style.cursor = 'pointer';
            filterBtn.title = isHidden ? 'Sprecher einblenden' : 'Sprecher ausblenden';
            filterBtn.onclick = (e) => {
                e.stopPropagation();
                
                const isCurrentlyHidden = filterBtn.classList.contains('speaker-off');
                
                if (isCurrentlyHidden) {
                    // Turn ON this speaker
                    this.app.state.hiddenSpeakers.delete(speakerName);
                    filterBtn.classList.remove('speaker-off');
                    filterBtn.innerHTML = eyeIcon;
                    filterBtn.title = 'Sprecher ausblenden';
                    
                    document.querySelectorAll('.transcript-segment').forEach(block => {
                        const blockSpeaker = block.getAttribute('data-speaker');
                        if (blockSpeaker === Utils.escapeHTML(speakerName)) {
                            block.classList.remove('speaker-hidden');
                        }
                    });
                } else {
                    // Turn OFF this speaker
                    this.app.state.hiddenSpeakers.add(speakerName);
                    filterBtn.classList.add('speaker-off');
                    filterBtn.innerHTML = eyeOffIcon;
                    filterBtn.title = 'Sprecher einblenden';
                    
                    document.querySelectorAll('.transcript-segment').forEach(block => {
                        const blockSpeaker = block.getAttribute('data-speaker');
                        if (blockSpeaker === Utils.escapeHTML(speakerName)) {
                            block.classList.add('speaker-hidden');
                        }
                    });
                }
                this.updateDynamicIndentation();
            };
            
            const actionContainer = document.createElement('div');
            actionContainer.className = 'sidebar-actions-wrapper';
            actionContainer.style.display = 'flex';
            actionContainer.style.gap = '4px';
            actionContainer.style.flexShrink = '0';
            
            actionContainer.appendChild(filterBtn);
            if (editBtn) actionContainer.appendChild(editBtn);
            
            speakerItem.appendChild(avatar);
            speakerItem.appendChild(info);
            speakerItem.appendChild(actionContainer);
            
            container.appendChild(speakerItem);
        });

        this.updateDynamicIndentation();
    }

    // Show only the given speaker; clicking again restores all speakers.
    toggleSoloSpeaker(speakerName) {
        const hidden = this.app.state.hiddenSpeakers;
        const allNames = new Set();
        (this.app.state.lastRenderedSpeakerBlocks || []).forEach(b => allNames.add(b.speakerName));

        const isSoloed = allNames.size > 1 && !hidden.has(speakerName)
            && Array.from(allNames).every(n => n === speakerName || hidden.has(n));

        hidden.clear();
        if (!isSoloed) {
            allNames.forEach(n => { if (n !== speakerName) hidden.add(n); });
        }

        document.querySelectorAll('.transcript-segment').forEach(block => {
            block.classList.toggle('speaker-hidden', hidden.has(block.getAttribute('data-speaker')));
        });

        // Rebuild the panel so eye icons and the solo star reflect the new state
        this.populateSpeakerPanel();
    }

    updateDynamicIndentation() {
        const visibleSpeakers = new Map();
        let nextIndex = 0;
        
        document.querySelectorAll('.transcript-segment:not(.speaker-hidden)').forEach(block => {
            const speakerName = block.getAttribute('data-speaker');
            if (!visibleSpeakers.has(speakerName)) {
                visibleSpeakers.set(speakerName, nextIndex);
                nextIndex++;
            }
            
            const indentRem = visibleSpeakers.get(speakerName) * 0.5;
            block.style.marginLeft = `${indentRem}rem`;
        });
    }

    showRenameSpeakerFromSidebar(event, currentName, targetElement = null) {
        if (event) event.stopPropagation();

        const menu = document.getElementById('custom-context-menu');
        if (menu) {
            menu.classList.add('hidden');
            menu.style.display = '';
            menu.innerHTML = '';
            menu.dataset.menuType = '';
            menu.dataset.blockIndex = '';
        }

        document.querySelectorAll('.inline-rename-cancel').forEach(btn => btn.click());

        const target = targetElement || event.currentTarget;
        if (!target) return;

        let actionButtons = [];
        if (targetElement && targetElement.parentElement && targetElement.parentElement.parentElement) {
            actionButtons = targetElement.parentElement.parentElement.querySelectorAll('.btn-sidebar-action');
            actionButtons.forEach(btn => btn.style.display = 'none');
        }

        const restoreOriginal = () => {
            target.innerHTML = Utils.escapeHTML(currentName);
            target.classList.remove('target-reset-styles');
            if (!targetElement) {
                target.onclick = (e) => this.showRenameSpeakerFromSidebar(e, currentName);
            } else {
                actionButtons.forEach(btn => btn.style.display = '');
            }
            document.removeEventListener('click', outsideClickListener);
        };

        const outsideClickListener = (e) => {
            if (!container.contains(e.target)) {
                restoreOriginal();
            }
        };

        target.onclick = null;
        target.classList.add('target-reset-styles');

        target.innerHTML = '';
        const container = document.createElement('div');
        container.className = 'speaker-menu-input-container';
        container.onclick = (e) => e.stopPropagation();

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'speaker-menu-input';
        input.value = currentName;
        input.onkeyup = (e) => { 
            if(e.key === 'Enter') {
                this.confirmRenameSpeakerGlobal(currentName, input.value, restoreOriginal);
            } else if (e.key === 'Escape') {
                restoreOriginal();
            }
        };

        const btn = document.createElement('button');
        btn.className = 'speaker-menu-confirm-btn';
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        btn.onclick = (e) => {
            e.stopPropagation();
            this.confirmRenameSpeakerGlobal(currentName, input.value, restoreOriginal);
        };

        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'inline-rename-cancel';
        cancelBtn.style.display = 'none';
        cancelBtn.onclick = (e) => {
            e.stopPropagation();
            restoreOriginal();
        };

        container.appendChild(input);
        container.appendChild(btn);
        container.appendChild(cancelBtn);
        target.appendChild(container);

        setTimeout(() => {
            document.addEventListener('click', outsideClickListener);
            input.focus();
            input.select();
        }, 50);
    }

    confirmRenameSpeakerGlobal(oldName, newName, restoreOriginal) {
        const sanitized = newName.trim();
        if (!sanitized || sanitized === oldName) {
            if (restoreOriginal) restoreOriginal();
            return;
        }

        if (restoreOriginal) {
            restoreOriginal();
        }

        this.pushToUndo();

        // Preserve color and indentation for the renamed speaker
        if (this.app.state.speakerColorMap && this.app.state.speakerColorMap.has(oldName)) {
            const speakerInfo = this.app.state.speakerColorMap.get(oldName);
            // It's safer to keep the old name mapped to the same color in case some cached segments still reference it momentarily,
            // but for a true rename, setting the new name to the old info is sufficient.
            this.app.state.speakerColorMap.set(sanitized, speakerInfo);
        }

        this.app.state.currentTranscriptSegments.forEach(seg => {
            if (seg.speaker === oldName) {
                seg.speaker = sanitized;
            }
        });

        this.app.ui.renderTranscriptArea();
        this.saveCurrentSegmentsToServer();
    }
}

window.showRedactionContextMenu = function(event, segIdx, redIdx) {
    if (!window.app || !window.app.state.editModeActive) return;
    if (event) event.stopPropagation();

    // Remove selection if any, so the selection toolbar doesn't conflict
    const selection = window.getSelection();
    if (selection) selection.removeAllRanges();

    let toolbar = document.getElementById('selection-toolbar');
    if (!toolbar) {
        toolbar = document.createElement('div');
        toolbar.id = 'selection-toolbar';
        document.body.appendChild(toolbar);
    }

    toolbar.innerHTML = `
        <button class="context-menu-btn" onmousedown="event.preventDefault(); window.removeRedaction(${segIdx}, ${redIdx}); document.getElementById('selection-toolbar')?.remove();" title="Einblenden">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            Einblenden
        </button>
    `;

    if (event && event.currentTarget) {
        const rect = event.currentTarget.getBoundingClientRect();
        toolbar.style.top = `${rect.top + window.scrollY}px`;
        toolbar.style.left = `${rect.left + rect.width / 2}px`;
    }

    const closeToolbar = (e) => {
        if (!toolbar.contains(e.target) && e.target !== event.currentTarget) {
            toolbar.remove();
            document.removeEventListener('mousedown', closeToolbar);
        }
    };
    setTimeout(() => document.addEventListener('mousedown', closeToolbar), 0);
};

window.makeSegmentEditable = function(event, el) {
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed || selection.toString().trim().length === 0) {
        el.contentEditable = 'true';
        el.focus();
        
        if (event && event.clientX) {
            if (document.caretPositionFromPoint) {
                const pos = document.caretPositionFromPoint(event.clientX, event.clientY);
                if (pos && pos.offsetNode) {
                    selection.collapse(pos.offsetNode, pos.offset);
                }
            } else if (document.caretRangeFromPoint) {
                const range = document.caretRangeFromPoint(event.clientX, event.clientY);
                if (range) {
                    selection.removeAllRanges();
                    selection.addRange(range);
                }
            }
        }
    }
};

// Attach a bulletproof helper for selection to window
window.getSelectedSegmentIds = function() {
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed) return [];
    
    const range = selection.getRangeAt(0);
    const selectedIds = [];
    
    document.querySelectorAll('.transcript-seg-item').forEach(item => {
        const itemRange = document.createRange();
        itemRange.selectNodeContents(item);
        
        // Check intersection: userRange.start < itemRange.end AND userRange.end > itemRange.start
        const startsBeforeEnd = range.compareBoundaryPoints(Range.START_TO_END, itemRange) === -1;
        const endsAfterStart = range.compareBoundaryPoints(Range.END_TO_START, itemRange) === 1;
        
        if (startsBeforeEnd && endsAfterStart) {
            const sId = parseInt(item.getAttribute('data-seg-id'), 10);
            if (!isNaN(sId)) selectedIds.push(sId);
        }
    });
    
    return selectedIds;
};

window.getSelectionBounds = function() {
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed) return null;

    const range = selection.getRangeAt(0);

    const getOffsetInSegment = (container, offset) => {
        const segItem = (container.nodeType === 3 ? container.parentElement : container).closest('.transcript-seg-item');
        if (!segItem) return null;
        const segId = parseInt(segItem.getAttribute('data-seg-id'), 10);
        
        let charOffset = 0;
        try {
            const preRange = document.createRange();
            preRange.setStartBefore(segItem.firstChild);
            preRange.setEnd(container, offset);
            charOffset = preRange.toString().length;
        } catch (e) {
            return null;
        }
        return { segId, offset: charOffset };
    };

    const startBound = getOffsetInSegment(range.startContainer, range.startOffset);
    const endBound = getOffsetInSegment(range.endContainer, range.endOffset);

    if (!startBound || !endBound) return null;

    if (startBound.segId > endBound.segId || (startBound.segId === endBound.segId && startBound.offset > endBound.offset)) {
        return { start: endBound, end: startBound };
    }
    return { start: startBound, end: endBound };
};
