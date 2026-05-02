import { Utils } from './Utils.js';

export class SegmentProcessor {
    constructor(app) {
        this.app = app;
    }

    formatTranscriptionWithSpeakers(segments, fullText, allowReorder = false) {
        if (!segments || segments.length === 0) {
            return `<div class="transcript-segment">
                        <div class="segment-header">
                            <div class="speaker-avatar" style="background: var(--speaker-1-gradient, linear-gradient(135deg, #6e8efb, #a777e3))"></div>
                            <div class="speaker-info">Person 1 <span class="speaker-sep">•</span> [00:00:00]</div>
                        </div>
                        <div class="transcript-text">${fullText}</div>
                    </div>`;
        }

        let formattedHTML = '';
        let speakerMap = new Map();
        let colorIndexCounter = 1;
        let currentBlock = null;
        let lastEndTime = 0;
        let isRight = false;
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
                    speakerMap.set(speakerName, (speakerMap.size % 5) + 1);
                }
                const colorId = speakerMap.get(speakerName);
                const indentationClass = isRight ? 'indented' : '';
                isRight = !isRight;

                currentBlock = {
                    speakerName,
                    colorId,
                    startTime: segment.start,
                    timestamp: Utils.formatSecondsToTime(segment.start),
                    text: segment.text.trim(),
                    indentationClass,
                    segmentIndices: [index]
                };
            } else {
                if (currentBlock) {
                    currentBlock.text += ' ' + segment.text.trim();
                    currentBlock.segmentIndices.push(index);
                }
            }
            lastEndTime = segment.end;
        });
        if (currentBlock) speakerBlocks.push(currentBlock);
        this.app.state.lastRenderedSpeakerBlocks = speakerBlocks;

        speakerBlocks.forEach((block, bIdx) => {
            let controls = '';
            if (allowReorder) {
                const firstSegIdx = block.segmentIndices[0];
                const lastSegIdx = block.segmentIndices[block.segmentIndices.length - 1];

                controls = '<div class="segment-reorder-controls">';
                if (bIdx > 0) {
                    controls += `<button class="reorder-btn move-up" 
                        onclick="window.moveSegment(${firstSegIdx}, 'up')" 
                        onmouseover="window.highlightSegment(${firstSegIdx}, true)" 
                        onmouseout="window.highlightSegment(${firstSegIdx}, false)" 
                        title="Ersten Satz nach oben verschieben">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"></polyline></svg>
                    </button>`;
                }
                if (bIdx < speakerBlocks.length - 1) {
                    controls += `<button class="reorder-btn move-down" 
                        onclick="window.moveSegment(${lastSegIdx}, 'down')" 
                        onmouseover="window.highlightSegment(${lastSegIdx}, true)" 
                        onmouseout="window.highlightSegment(${lastSegIdx}, false)" 
                        title="Letzten Satz nach unten verschieben">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </button>`;
                }
                controls += '</div>';
            }

            const copyBtn = allowReorder ? '' : `<button class="copy-block-btn" title="Abschnitt kopieren" onclick="window.copyBlockText(this)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                    </button>`;

            let blockHTML = '';
            block.segmentIndices.forEach(idx => {
                const seg = segments[idx];
                const trimmedText = seg.text.trim();
                let textContent;
                
                if (trimmedText === "[Dieser Sprecher hat noch keinen Text!]") {
                    textContent = `<span class="transcript-placeholder">${trimmedText}</span>`;
                } else {
                    if (seg.redactions && seg.redactions.length > 0) {
                        let lastIdx = 0;
                        let newText = '';
                        const sortedRedactions = [...seg.redactions].sort((a, b) => a.start - b.start);
                        
                        sortedRedactions.forEach(red => {
                            newText += trimmedText.substring(lastIdx, red.start);
                            newText += `<span class="redacted" title="Schwärzung">${trimmedText.substring(red.start, red.end)}</span>`;
                            lastIdx = red.end;
                        });
                        newText += trimmedText.substring(lastIdx);
                        textContent = newText;
                    } else {
                        textContent = trimmedText;
                    }
                }
                blockHTML += `<span class="transcript-seg-item" data-seg-id="${idx}">${textContent} </span>`;
            });

            formattedHTML += `<div class="transcript-segment ${block.indentationClass} ${allowReorder ? 'reorder-mode' : ''}" data-speaker="${block.speakerName}">
                <div class="segment-header">
                    <div class="speaker-avatar" style="background: var(--speaker-${block.colorId}-gradient, linear-gradient(135deg, #6e8efb, #a777e3))"></div>
                    <div class="speaker-info">
                        <span class="speaker-label">${block.speakerName}</span> 
                        <span class="speaker-sep">•</span> 
                        [${block.timestamp}]
                        <button class="speaker-quick-edit-btn" onclick="window.openSpeakerEditDropdown(event, ${bIdx})" title="Sprecher anpassen">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                            <span class="plus-minus">+/-</span>
                        </button>
                    </div>
                    ${controls}
                </div>
                <div class="transcript-text">${blockHTML}</div>
                <div class="segment-actions">
                    ${copyBtn}
                </div>
            </div>`;
        });

        return formattedHTML || `<div class="transcript-segment"><div class="transcript-text">${fullText}</div></div>`;
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
        const btns = document.querySelectorAll('#undo-reorder-btn, #undo-speaker-btn, #undo-redaction-btn');
        const hasHistory = this.app.state.transcriptUndoStack.length > 0;
        btns.forEach(btn => {
            btn.disabled = !hasHistory;
        });
    }

    moveSegment(segIdx, direction) {
        const segments = this.app.state.currentTranscriptSegments;
        if (!segments[segIdx]) return;

        this.pushToUndo();

        if (direction === 'up' && segIdx > 0) {
            segments[segIdx].speaker = segments[segIdx - 1].speaker;
        } else if (direction === 'down' && segIdx < segments.length - 1) {
            segments[segIdx].speaker = segments[segIdx + 1].speaker;
        }

        this.app.ui.renderTranscriptArea();

        if (this.app.state.currentTranscriptSlug) {
            this.saveCurrentSegmentsToServer();
        }
    }

    async saveCurrentSegmentsToServer() {
        if (!this.app.state.currentTranscriptSlug) return;
        try {
            await fetch(`/req/transcription/${this.app.state.currentTranscriptSlug}/segments`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({ segments: this.app.state.currentTranscriptSegments })
            });
        } catch (e) { console.warn("Failed to sync segments:", e); }
    }

    updateSegmentText(idx, newText) {
        if (!this.app.state.currentTranscriptSegments[idx]) return;
        const sanitized = newText.trim();
        if (sanitized === '' || sanitized === '[Dieser Sprecher hat noch keinen Text!]') {
            this.app.state.currentTranscriptSegments[idx].text = "[Dieser Sprecher hat noch keinen Text!]";
        } else {
            this.app.state.currentTranscriptSegments[idx].text = sanitized;
        }
        this.app.ui.updateSidebarSaveButtonState();
    }

    cleanupOrphanedPlaceholders() {
        if (!this.app.state.currentTranscriptSegments) return;
        const segments = this.app.state.currentTranscriptSegments;
        const placeholderText = "[Dieser Sprecher hat noch keinen Text!]";
        let i = 0;
        let indicesToRemove = [];
        while (i < segments.length) {
            let blockIndices = [];
            const speaker = segments[i].speaker;
            let j = i;
            while (j < segments.length && segments[j].speaker === speaker) {
                blockIndices.push(j);
                j++;
            }
            if (blockIndices.length > 1) {
                const hasRealText = blockIndices.some(idx => segments[idx].text !== placeholderText);
                if (hasRealText) {
                    blockIndices.forEach(idx => {
                        if (segments[idx].text === placeholderText) {
                            indicesToRemove.push(idx);
                        }
                    });
                }
            }
            i = j;
        }
        if (indicesToRemove.length > 0) {
            indicesToRemove.sort((a,b) => b-a).forEach(idx => {
                this.app.state.currentTranscriptSegments.splice(idx, 1);
            });
        }
    }

    openSpeakerEditDropdown(event, blockIndex) {
        if (!this.app.state.editModeActive) return;
        if (event) event.stopPropagation();

        if (!this.app.state.lastRenderedSpeakerBlocks || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        const menu = document.getElementById('custom-context-menu');
        if (!menu) return;

        let html = '<div class="speaker-menu-list">';

        html += `<div class="speaker-menu-item" onclick="window.showRenameSpeakerInline(event, ${blockIndex})">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
            Sprecher umbenennen
        </div>`;

        html += `<div class="speaker-menu-item" onclick="window.showReassignSubmenu(event, ${blockIndex})">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
            Zuweisen an...
        </div>`;

        html += `<div class="speaker-menu-item" onclick="window.insertSpeakerAt(event, ${blockIndex}, 'above')">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="18 15 12 9 6 15"></polyline></svg>
            Sprecher oben einfügen
        </div>`;

        html += `<div class="speaker-menu-item" onclick="window.insertSpeakerAt(event, ${blockIndex}, 'below')">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="6 9 12 15 18 9"></polyline></svg>
            Sprecher unten einfügen
        </div>`;

        html += '</div>';

        menu.innerHTML = html;

        if (menu.style.display !== 'block') {
            menu.style.display = 'block';
            if (event && event.currentTarget) {
                const rect = event.currentTarget.getBoundingClientRect();
                menu.style.left = (rect.left) + 'px';
                menu.style.top = (rect.bottom + window.scrollY + 5) + 'px';

                setTimeout(() => {
                    const menuRect = menu.getBoundingClientRect();
                    if (menuRect.right > window.innerWidth) {
                        menu.style.left = (window.innerWidth - menuRect.width - 20) + 'px';
                    }
                }, 0);
            }
        }

        menu.onclick = (e) => e.stopPropagation();

        const closeMenu = (e) => {
            if (!menu.contains(e.target)) {
                menu.style.display = 'none';
                document.removeEventListener('click', closeMenu);
            }
        };
        setTimeout(() => document.addEventListener('click', closeMenu), 0);
    }

    showReassignSubmenu(event, blockIndex) {
        if (event) event.stopPropagation();
        const menu = document.getElementById('custom-context-menu');
        if (!menu || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        const currentSpeaker = this.app.state.lastRenderedSpeakerBlocks[blockIndex].speakerName;
        const speakers = [...new Set(this.app.state.lastRenderedSpeakerBlocks.map(b => b.speakerName))]
            .filter(s => s && s !== currentSpeaker);

        let html = '<div class="speaker-menu-list">';
        html += '<div class="speaker-menu-header">Zuweisen an:</div>';

        speakers.forEach(speaker => {
            const escaped = speaker.replace(/'/g, "\\'");
            html += `<div class="speaker-menu-item" onclick="window.reassignSpeaker(${blockIndex}, '${escaped}')">
                ${speaker}
            </div>`;
        });

        html += '<div class="speaker-menu-divider"></div>';
        html += `<div class="speaker-menu-item new-speaker" onclick="window.showNewSpeakerInline(event, ${blockIndex})">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Neuer Sprecher
        </div>`;

        html += '<div class="speaker-menu-divider"></div>';
        html += `<div class="speaker-menu-item" onclick="window.openSpeakerEditDropdown(null, ${blockIndex})" style="color: var(--text-faded-color); font-size: 11px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
            Zurück
        </div>`;

        html += '</div>';
        menu.innerHTML = html;
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
        if (menu) menu.style.display = 'none';
    }

    showNewSpeakerInline(event, blockIndex) {
        if (event) event.stopPropagation();

        const target = event.currentTarget;
        if (!target) return;

        target.onclick = null;
        target.style.padding = '0';
        target.classList.remove('speaker-menu-item');
        target.style.background = 'transparent';
        target.style.cursor = 'default';

        let html = `
            <div class="speaker-menu-input-container" onclick="event.stopPropagation()">
                <input type="text" class="speaker-menu-input" id="inline-speaker-input" placeholder="Name..." autofocus onkeyup="if(event.key === 'Enter') window.confirmInlineSpeaker(${blockIndex}, this.value)">
                <button class="speaker-menu-confirm-btn" onclick="window.confirmInlineSpeaker(${blockIndex}, document.getElementById('inline-speaker-input').value)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </button>
            </div>
        `;

        target.innerHTML = html;

        setTimeout(() => {
            const input = document.getElementById('inline-speaker-input');
            if (input) input.focus();
        }, 50);
    }

    confirmInlineSpeaker(blockIndex, name) {
        if (name && name.trim()) {
            this.reassignSpeaker(blockIndex, name.trim());
        }
    }

    showRenameSpeakerInline(event, blockIndex) {
        if (event) event.stopPropagation();
        const target = event.currentTarget;
        if (!target || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        const currentName = this.app.state.lastRenderedSpeakerBlocks[blockIndex].speakerName;

        target.onclick = null;
        target.style.padding = '0';
        target.classList.remove('speaker-menu-item');
        target.style.background = 'transparent';
        target.style.cursor = 'default';

        target.innerHTML = `
            <div class="speaker-menu-input-container" onclick="event.stopPropagation()">
                <input type="text" class="speaker-menu-input" id="inline-rename-input" value="${currentName}" autofocus onkeyup="if(event.key === 'Enter') window.confirmRenameSpeaker(${blockIndex}, this.value)">
                <button class="speaker-menu-confirm-btn" onclick="window.confirmRenameSpeaker(${blockIndex}, document.getElementById('inline-rename-input').value)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </button>
            </div>
        `;

        setTimeout(() => {
            const input = document.getElementById('inline-rename-input');
            if (input) {
                input.focus();
                input.select();
            }
        }, 50);
    }

    confirmRenameSpeaker(blockIndex, newName) {
        const sanitized = newName.trim();
        if (!sanitized || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        const oldName = this.app.state.lastRenderedSpeakerBlocks[blockIndex].speakerName;
        if (sanitized === oldName) {
            const menu = document.getElementById('custom-context-menu');
            if (menu) menu.style.display = 'none';
            return;
        }

        this.pushToUndo();

        this.app.state.currentTranscriptSegments.forEach(seg => {
            if (seg.speaker === oldName) {
                seg.speaker = sanitized;
            }
        });

        this.app.ui.renderTranscriptArea();
        this.saveCurrentSegmentsToServer();

        const menu = document.getElementById('custom-context-menu');
        if (menu) menu.style.display = 'none';
    }

    insertSpeakerAt(event, blockIndex, position) {
        if (event) event.stopPropagation();
        const menu = document.getElementById('custom-context-menu');
        if (!menu || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        const currentSpeaker = this.app.state.lastRenderedSpeakerBlocks[blockIndex].speakerName;
        const speakers = [...new Set(this.app.state.currentTranscriptSegments.map(s => s.speaker || 'Person 1'))]
            .filter(s => s && s !== currentSpeaker);

        let html = '<div class="speaker-menu-list">';
        html += `<div class="speaker-menu-header">Sprecher ${position === 'above' ? 'davor' : 'danach'} einfügen:</div>`;

        speakers.forEach(speaker => {
            const escaped = speaker.replace(/'/g, "\\'");
            html += `<div class="speaker-menu-item" onclick="window.performSpeakerInsertion(${blockIndex}, '${position}', '${escaped}')">
                ${speaker}
            </div>`;
        });

        html += '<div class="speaker-menu-divider"></div>';
        html += `<div class="speaker-menu-item new-speaker" onclick="window.showNewSpeakerInlineForInsertion(event, ${blockIndex}, '${position}')">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Neuer Sprecher
        </div>`;

        html += '<div class="speaker-menu-divider"></div>';
        html += `<div class="speaker-menu-item" onclick="window.openSpeakerEditDropdown(null, ${blockIndex})" style="color: var(--text-faded-color); font-size: 11px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
            Zurück
        </div>`;

        html += '</div>';
        menu.innerHTML = html;
    }

    performSpeakerInsertion(blockIndex, position, speakerName) {
        if (!this.app.state.lastRenderedSpeakerBlocks || !this.app.state.lastRenderedSpeakerBlocks[blockIndex]) return;

        this.pushToUndo();

        const block = this.app.state.lastRenderedSpeakerBlocks[blockIndex];
        let targetIdx;
        let baseTime;

        if (position === 'above') {
            targetIdx = block.segmentIndices[0];
            baseTime = this.app.state.currentTranscriptSegments[targetIdx].start;
        } else {
            targetIdx = block.segmentIndices[block.segmentIndices.length - 1] + 1;
            baseTime = this.app.state.currentTranscriptSegments[targetIdx - 1].end;
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
        if (menu) menu.style.display = 'none';
    }

    showNewSpeakerInlineForInsertion(event, blockIndex, position) {
        if (event) event.stopPropagation();
        const target = event.currentTarget;
        if (!target) return;

        target.onclick = null;
        target.style.padding = '0';
        target.classList.remove('speaker-menu-item');
        target.style.background = 'transparent';
        target.style.cursor = 'default';

        let html = `
            <div class="speaker-menu-input-container" onclick="event.stopPropagation()">
                <input type="text" class="speaker-menu-input" id="inline-insert-input" placeholder="Name..." autofocus onkeyup="if(event.key === 'Enter') window.confirmInlineInsertion(${blockIndex}, '${position}', this.value)">
                <button class="speaker-menu-confirm-btn" onclick="window.confirmInlineInsertion(${blockIndex}, '${position}', document.getElementById('inline-insert-input').value)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                </button>
            </div>
        `;
        target.innerHTML = html;
        setTimeout(() => {
            const input = document.getElementById('inline-insert-input');
            if (input) input.focus();
        }, 50);
    }

    confirmInlineInsertion(blockIndex, position, name) {
        if (name && name.trim()) {
            this.performSpeakerInsertion(blockIndex, position, name.trim());
        }
    }

    renderRedactionList() {
        const listEl = document.getElementById('redaction-list');
        if (!listEl) return;

        const segments = this.app.state.currentTranscriptSegments || [];
        const entries = [];

        segments.forEach((seg, segIdx) => {
            if (!seg.redactions || seg.redactions.length === 0) return;
            const baseText = (seg.text || '').trim();
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
            if (accordion) accordion.style.display = 'none';
            if (listEl) listEl.innerHTML = '<p style="font-size: 13px; color: #aaa;">Keine Ausblendungen vorhanden.</p>';
            return;
        }

        if (accordion) accordion.style.display = 'block';
        if (countEl) countEl.textContent = entries.length;

        let html = '';
        entries.forEach(({ segIdx, redIdx, speaker, redactedText }) => {
            const displayText = redactedText.length > 60
                ? redactedText.substring(0, 57) + '...'
                : redactedText;

            html += `
            <div class="redaction-list-item" data-seg="${segIdx}" data-red="${redIdx}">
                <div class="redaction-list-content">
                    <span class="redaction-list-speaker">${speaker}</span>
                    <span class="redaction-list-text">&bdquo;${displayText}&ldquo;</span>
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
        if (!this.app.state.editModeActive || this.app.state.reorderModeActive) return;

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

        const startSegItem = resolveSegItem(range.startContainer);
        const endSegItem = resolveSegItem(range.endContainer);

        if (!startSegItem || !endSegItem || startSegItem !== endSegItem) {
            if (toolbar) toolbar.remove();
            return;
        }

        this.showSelectionToolbar(range);
    }

    showSelectionToolbar(range) {
        let toolbar = document.getElementById('selection-toolbar');
        if (!toolbar) {
            toolbar = document.createElement('div');
            toolbar.id = 'selection-toolbar';
            toolbar.innerHTML = `
                <button onmousedown="event.preventDefault()" onclick="window.redactSelectedText()" title="Text ausblenden (Schwärzen)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                    Ausblenden
                </button>
                <div class="selection-toolbar-divider"></div>
                <button onmousedown="event.preventDefault()" onclick="window.toggleSatzkorrektur()" title="Satzkorrektur (Satzverschiebung) aktivieren">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 3 21 3 21 8"></polyline><line x1="4" y1="20" x2="21" y2="3"></line><polyline points="21 16 21 21 16 21"></polyline><line x1="15" y1="15" x2="21" y2="21"></line><line x1="4" y1="4" x2="9" y2="9"></line></svg>
                    Satzkorrektur
                </button>
            `;
            document.body.appendChild(toolbar);
        }

        const rect = range.getBoundingClientRect();
        toolbar.style.top = `${rect.top + window.scrollY}px`;
        toolbar.style.left = `${rect.left + rect.width / 2}px`;
    }

    redactSelectedText() {
        const selection = window.getSelection();
        if (!selection || selection.isCollapsed) return;

        const range = selection.getRangeAt(0);

        const startEl = range.startContainer.nodeType === 3
            ? range.startContainer.parentElement
            : range.startContainer;
        const segItem = startEl ? startEl.closest('.transcript-seg-item') : null;
        if (!segItem) return;

        const endEl = range.endContainer.nodeType === 3
            ? range.endContainer.parentElement
            : range.endContainer;
        const endSegItem = endEl ? endEl.closest('.transcript-seg-item') : null;
        if (!endSegItem || endSegItem !== segItem) {
            selection.removeAllRanges();
            const toolbar = document.getElementById('selection-toolbar');
            if (toolbar) toolbar.remove();
            return;
        }

        const segId = parseInt(segItem.getAttribute('data-seg-id'));
        const segment = this.app.state.currentTranscriptSegments[segId];
        if (!segment) return;

        let startOffset = 0;
        try {
            const preRange = document.createRange();
            preRange.setStartBefore(segItem.firstChild);
            preRange.setEnd(range.startContainer, range.startOffset);
            startOffset = preRange.toString().length;
        } catch (e) {
            return;
        }

        const selectedText = selection.toString();
        const leadingSpaces = selectedText.length - selectedText.trimStart().length;
        const trailingSpaces = selectedText.length - selectedText.trimEnd().length;
        const trimmedStart = startOffset + leadingSpaces;
        const trimmedEnd = startOffset + selectedText.length - trailingSpaces;

        if (trimmedStart >= trimmedEnd) return;

        this.pushToUndo();

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

        this.app.ui.renderTranscriptArea();
        this.renderRedactionList();
        this.saveCurrentSegmentsToServer();

        selection.removeAllRanges();
        const toolbar = document.getElementById('selection-toolbar');
        if (toolbar) toolbar.remove();
    }
}
