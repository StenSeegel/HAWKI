// Transcript Functions - Globale Definitionen
console.log("🚀 transcript_functions.js geladen");

// Globale Variablen für File-Status und Save-Promise
window.selectedAudioFile = null;
window.activeSavePromise = null;
window.transcriptHistoryRenderSeq = 0;
window.currentTranscriptSegments = [];
window.currentTranscriptText = '';
window.currentTranscriptSlug = null;
window.reorderModeActive = false;
window.transcriptUndoStack = [];


// --- Globale UI Funktionen (Sofort verfügbar) ---

window.switchTranscriptView = function (view) {
    console.log("🔄 switchTranscriptView:", view);

    // 1. Define all possible panels
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
        'file-transcription-options'
    ];

    // 2. Hide everything first to ensure a clean state
    [...mainPanels, ...sidebarPanels].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // 3. Show specific panels based on view
    switch (view) {
        case 'choice':
            showIfExist('transcript-choice', 'flex');
            showIfExist('sidebar-history-content', 'block');

            // Clear search when returning to choice screen
            const searchInput = document.getElementById('history-search');
            if (searchInput) {
                searchInput.value = '';
                window.filterHistory();
            }
            break;
        case 'file':
            showIfExist('transcript-file-ui', 'block');
            showIfExist('file-transcription-options', 'block');
            showIfExist('drop-zone', 'flex');
            break;
        case 'live':
            showIfExist('transcript-live-ui', 'block');
            break;
        case 'view-transcript':
            showIfExist('transcript-history-ui', 'flex');
            showIfExist('sidebar-detail-content', 'block');
            hideIfExist('sidebar-history-content');
            break;
        case 'transcript-export-ui':
            showIfExist('transcript-export-ui', 'flex');
            showIfExist('sidebar-detail-content', 'block');
            hideIfExist('sidebar-history-content');
            break;
    }
};

function showIfExist(id, display = 'block') {
    const el = document.getElementById(id);
    if (el) el.style.display = display;
}

function hideIfExist(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

window.toggleSidebarMenu = function (mode) {
    const speakerBtn = document.getElementById('edit-speakers-btn');
    const sentenceBtn = document.getElementById('reorder-sentences-btn');
    const exportBtn = document.getElementById('export-options-btn');
    
    const speakerPanel = document.getElementById('speaker-rename-panel');
    const sentencePanel = document.getElementById('sentence-reorder-panel');
    const exportPanel = document.getElementById('export-options-panel');
    
    const historyUI = document.getElementById('transcript-history-ui');
    const exportUI = document.getElementById('transcript-export-ui');

    // Reset view-transcript by default when switching any mode
    // This ensures previews (like SRT) disappear when changing sidebar categories.
    window.switchTranscriptView('view-transcript');

    if (mode === 'speakers') {
        if (speakerBtn) speakerBtn.classList.add('active');
        if (sentenceBtn) sentenceBtn.classList.remove('active');
        if (exportBtn) exportBtn.classList.remove('active');
        
        if (speakerPanel) speakerPanel.style.display = 'block';
        if (sentencePanel) sentencePanel.style.display = 'none';
        if (exportPanel) exportPanel.style.display = 'none';
        
        if (historyUI) historyUI.style.display = 'flex';
        if (exportUI) exportUI.style.display = 'none';
        
        window.reorderModeActive = false;
        renderTranscriptArea();
    } else if (mode === 'sentences') {
        if (speakerBtn) speakerBtn.classList.remove('active');
        if (sentenceBtn) sentenceBtn.classList.add('active');
        if (exportBtn) exportBtn.classList.remove('active');
        
        if (speakerPanel) speakerPanel.style.display = 'none';
        if (sentencePanel) sentencePanel.style.display = 'block';
        if (exportPanel) exportPanel.style.display = 'none';
        
        if (historyUI) historyUI.style.display = 'flex';
        
        window.reorderModeActive = true;
        renderTranscriptArea();
    } else if (mode === 'export') {
        if (speakerBtn) speakerBtn.classList.remove('active');
        if (sentenceBtn) sentenceBtn.classList.remove('active');
        if (exportBtn) exportBtn.classList.add('active');
        
        if (speakerPanel) speakerPanel.style.display = 'none';
        if (sentencePanel) sentencePanel.style.display = 'none';
        if (exportPanel) exportPanel.style.display = 'block';
        
        if (historyUI) historyUI.style.display = 'flex';
        
        window.reorderModeActive = false;
        renderTranscriptArea();
    }
};

window.finishReorderMode = function () {
    window.toggleSidebarMenu('speakers');
};

function renderTranscriptArea() {
    const resDiv = document.getElementById('transcription-result');
    if (resDiv) {
        // Cleanup placeholders that are no longer alone in their block
        cleanupOrphanedPlaceholders();

        resDiv.innerHTML = formatTranscriptionWithSpeakers(
            window.currentTranscriptSegments,
            window.currentTranscriptText,
            window.reorderModeActive
        );
        if (!window.reorderModeActive) {
            populateSpeakerPanel(resDiv);
        }
        updateSidebarSaveButtonState();
    }
}

window.cleanupOrphanedPlaceholders = function() {
    if (!window.currentTranscriptSegments) return;
    const segments = window.currentTranscriptSegments;
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
        // If a block has multiple segments and at least one is real text, 
        // remove any placeholders in that specific block.
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
            window.currentTranscriptSegments.splice(idx, 1);
        });
    }
};

window.updateSidebarSaveButtonState = function() {
    const segments = window.currentTranscriptSegments || [];
    const hasPlaceholders = segments.some(s => s.text && s.text.includes("[Dieser Sprecher hat noch keinen Text!]"));
    
    // Target both possible IDs or classes for the save button
    const saveBtn = document.querySelector('.btn-sidebar-action:last-child'); // Find the save button in sidebar
    if (!saveBtn) return;

    if (hasPlaceholders) {
        saveBtn.classList.add('btn-sidebar-save-disabled');
        saveBtn.disabled = true;
    } else {
        saveBtn.classList.remove('btn-sidebar-save-disabled');
        saveBtn.disabled = false;
    }
};

window.updateSegmentText = function(idx, newText) {
    if (!window.currentTranscriptSegments[idx]) return;
    const sanitized = newText.trim();
    if (sanitized === '' || sanitized === '[Dieser Sprecher hat noch keinen Text!]') {
        window.currentTranscriptSegments[idx].text = "[Dieser Sprecher hat noch keinen Text!]";
    } else {
        window.currentTranscriptSegments[idx].text = sanitized;
    }
    // Update button state without full re-render to avoid losing focus if editing
    updateSidebarSaveButtonState();
};

window.showTranscriptMode = function (mode) {
    console.log("🛠 showTranscriptMode aufgerufen:", mode);
    window.switchTranscriptView(mode);
};

window.showTranscriptChoice = async function () {
    console.log("🛠 showTranscriptChoice aufgerufen");

    // Protection against hanging promises
    if (window.activeSavePromise) {
        console.log('⏳ Warte auf Abschluss des Speichervorgangs...');
        const timeoutPromise = new Promise(resolve => setTimeout(resolve, 3000));
        try {
            await Promise.race([window.activeSavePromise, timeoutPromise]);
        } catch (err) {
            console.warn("Save promise error during navigation:", err);
        }
        window.activeSavePromise = null; // Clear it anyway to allow navigation
    }

    window.switchTranscriptView('choice');

    // Ensure history is rendered and shown
    await renderHistory();
    window.removeSelectedFile();
};

window.removeSelectedFile = function () {
    window.selectedAudioFile = null;
    const fileInput = document.getElementById('audio_file');
    const filePreview = document.getElementById('selected-file-preview');

    // Sidebar elements
    const sidebarPill = document.getElementById('sidebar-file-pill');
    const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');
    if (fileInput) fileInput.value = '';
    if (filePreview) filePreview.style.display = 'none';

    if (sidebarPill) sidebarPill.style.display = 'none';
    if (sidebarPlaceholder) sidebarPlaceholder.style.display = 'block';
};

// --- Daten & Helfer Funktionen ---

function formatSecondsToTime(seconds) {
    const hrs = String(Math.floor(seconds / 3600)).padStart(2, '0');
    const mins = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
    const secs = String(Math.floor(seconds % 60)).padStart(2, '0');
    return `${hrs}:${mins}:${secs}`;
}

function formatTranscriptionWithSpeakers(segments, fullText, allowReorder = false) {
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
                timestamp: formatSecondsToTime(segment.start),
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
    window.lastRenderedSpeakerBlocks = speakerBlocks;

    speakerBlocks.forEach((block, bIdx) => {
        let controls = '';
        if (allowReorder) {
            const firstSegIdx = block.segmentIndices[0];
            const lastSegIdx = block.segmentIndices[block.segmentIndices.length - 1];

            controls = '<div class="segment-reorder-controls">';
            if (bIdx > 0) {
                controls += `<button class="reorder-btn move-up" 
                    onclick="moveSegment(${firstSegIdx}, 'up')" 
                    onmouseover="highlightSegment(${firstSegIdx}, true)" 
                    onmouseout="highlightSegment(${firstSegIdx}, false)" 
                    title="Ersten Satz nach oben verschieben">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"></polyline></svg>
                </button>`;
            }
            if (bIdx < speakerBlocks.length - 1) {
                controls += `<button class="reorder-btn move-down" 
                    onclick="moveSegment(${lastSegIdx}, 'down')" 
                    onmouseover="highlightSegment(${lastSegIdx}, true)" 
                    onmouseout="highlightSegment(${lastSegIdx}, false)" 
                    title="Letzten Satz nach unten verschieben">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </button>`;
            }
            controls += '</div>';
        }

        const copyBtn = allowReorder ? '' : `<button class="copy-block-btn" title="Abschnitt kopieren" onclick="copyBlockText(this)">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                </button>`;

        // Construct block text wrapping each segment in a span for highlighting
        let blockHTML = '';
        block.segmentIndices.forEach(idx => {
            const seg = segments[idx];
            const trimmedText = seg.text.trim();
            let textContent;
            
            if (trimmedText === "[Dieser Sprecher hat noch keinen Text!]") {
                textContent = `<span class="transcript-placeholder">${trimmedText}</span>`;
            } else {
                textContent = trimmedText;
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
                    <button class="speaker-quick-edit-btn" onclick="openSpeakerEditDropdown(event, ${bIdx})" title="Sprecher anpassen">
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

window.highlightSegment = function (segIdx, active) {
    const span = document.querySelector(`.transcript-seg-item[data-seg-id="${segIdx}"]`);
    if (span) {
        if (active) {
            span.classList.add('highlight-blue');
        } else {
            span.classList.remove('highlight-blue');
        }
    }
};

function pushToUndo() {
    if (!window.currentTranscriptSegments) return;
    // Deep copy segments to decouple from the current state
    const snapshot = JSON.parse(JSON.stringify(window.currentTranscriptSegments));
    window.transcriptUndoStack.push(snapshot);
    if (window.transcriptUndoStack.length > 10) {
        window.transcriptUndoStack.shift();
    }
    updateUndoButtonState();
}

window.undoLastMove = function () {
    if (window.transcriptUndoStack.length === 0) return;

    console.log("⏪ Undo triggered");
    const lastState = window.transcriptUndoStack.pop();
    window.currentTranscriptSegments = lastState;

    renderTranscriptArea();
    saveCurrentSegmentsToServer();
    updateUndoButtonState();
};

function updateUndoButtonState() {
    const btns = document.querySelectorAll('#undo-reorder-btn, #undo-speaker-btn');
    const hasHistory = window.transcriptUndoStack.length > 0;
    btns.forEach(btn => {
        btn.disabled = !hasHistory;
    });
}

window.moveSegment = function (segIdx, direction) {
    console.log(`📦 moveSegment index ${segIdx} direction ${direction}`);
    const segments = window.currentTranscriptSegments;
    if (!segments[segIdx]) return;

    // Capture state before move
    pushToUndo();

    if (direction === 'up' && segIdx > 0) {
        // Move current segment to the speaker of the previous segment
        segments[segIdx].speaker = segments[segIdx - 1].speaker;
    } else if (direction === 'down' && segIdx < segments.length - 1) {
        // Move current segment to the speaker of the next segment
        segments[segIdx].speaker = segments[segIdx + 1].speaker;
    }

    // Re-render
    renderTranscriptArea();

    // Auto-save changes locally (and we should probably save to server eventually)
    if (window.currentTranscriptSlug) {
        saveCurrentSegmentsToServer();
    }
};

async function saveCurrentSegmentsToServer() {
    if (!window.currentTranscriptSlug) return;
    try {
        await fetch(`/req/transcription/${window.currentTranscriptSlug}/segments`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ segments: window.currentTranscriptSegments })
        });
    } catch (e) { console.warn("Failed to sync segments:", e); }
}

function normalizeSegments(rawSegments) {
    if (Array.isArray(rawSegments)) return rawSegments;
    if (typeof rawSegments === 'string') {
        try {
            const parsed = JSON.parse(rawSegments);
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }
    return [];
}

function normalizeTranscriptText(rawText) {
    return typeof rawText === 'string' ? rawText : '';
}

window.copyBlockText = function (btn) {
    const segment = btn.closest('.transcript-segment');
    const text = segment ? segment.querySelector('.transcript-text')?.innerText : '';
    if (text) {
        navigator.clipboard.writeText(text).then(() => {
            btn.classList.add('copied');
            setTimeout(() => btn.classList.remove('copied'), 1500);
        });
    }
};

function getLocalTranscriptionHistory() {
    try {
        return JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
    } catch (e) {
        return [];
    }
}

function setLocalTranscriptionHistory(history) {
    localStorage.setItem("transcriptionHistory", JSON.stringify(history));
}

function isLocalOnlyTranscription(entry) {
    return !entry || !entry.slug;
}

function parseHistoryEntryDate(entry) {
    const raw = entry?.updated_at
        || entry?.created_at
        || entry?.updated_at_local
        || entry?.created_at_local
        || null;
    if (!raw) return null;
    const d = new Date(raw);
    return Number.isNaN(d.getTime()) ? null : d;
}

function getHistoryGroupKey(entryDate) {
    if (!entryDate) return 'older';

    const now = new Date();
    const todayStart = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterdayStart = new Date(todayStart);
    yesterdayStart.setDate(todayStart.getDate() - 1);
    const sevenDaysAgoStart = new Date(todayStart);
    sevenDaysAgoStart.setDate(todayStart.getDate() - 7);

    if (entryDate >= todayStart) return 'today';
    if (entryDate >= yesterdayStart) return 'yesterday';
    if (entryDate >= sevenDaysAgoStart) return 'last7';
    return 'older';
}

function getHistoryGroupLabel(groupKey) {
    if (groupKey === 'today') return 'Heute';
    if (groupKey === 'yesterday') return 'Gestern';
    if (groupKey === 'last7') return 'Letzte 7 Tage';
    return 'Vor längerer Zeit';
}

async function fetchJsonWithRetry(url, options = {}, retries = 1, retryDelayMs = 250) {
    let lastError = null;

    for (let attempt = 0; attempt <= retries; attempt++) {
        try {
            const response = await fetch(url, options);
            const contentType = response.headers.get('content-type') || '';

            if (!response.ok) {
                const statusError = new Error(`HTTP ${response.status}`);
                statusError.status = response.status;
                throw statusError;
            }

            if (!contentType.includes('application/json')) {
                throw new Error(`Unexpected content type: ${contentType || 'unknown'}`);
            }

            return await response.json();
        } catch (error) {
            lastError = error;
            if (attempt < retries) {
                await new Promise(resolve => setTimeout(resolve, retryDelayMs));
            }
        }
    }

    throw lastError;
}

window.filterHistory = function () {
    const query = (document.getElementById('history-search')?.value || '').trim().toLowerCase();
    const list = document.getElementById('chats-list');
    if (!list) return;

    const children = Array.from(list.children);
    let currentCategory = null;
    let categoryHasVisibleEntries = false;

    const flushCategoryVisibility = () => {
        if (currentCategory) {
            currentCategory.style.display = categoryHasVisibleEntries ? 'block' : 'none';
        }
    };

    children.forEach((node) => {
        if (node.classList.contains('history-category')) {
            flushCategoryVisibility();
            currentCategory = node;
            categoryHasVisibleEntries = false;
            return;
        }

        if (node.classList.contains('history-entry')) {
            const label = node.querySelector('.label');
            const title = (label?.textContent || '').toLowerCase();
            const isVisible = query === '' || title.includes(query);
            node.style.display = isVisible ? 'flex' : 'none';
            if (isVisible) categoryHasVisibleEntries = true;
        }
    });

    flushCategoryVisibility();
};

window.renderHistory = async function () {
    console.log("📊 renderHistory aufgerufen");
    if (!document.getElementById('transcript-sidebar')) {
        return;
    }
    const list = document.getElementById("chats-list");
    if (!list) return;

    const renderSeq = ++window.transcriptHistoryRenderSeq;
    const previousHtml = list.innerHTML;

    let historyItems = [];
    let serverHistoryLoaded = false;
    let serverRequestFailed = false;

    // Server load
    try {
        const data = await fetchJsonWithRetry('/req/transcriptions', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (data.success && data.transcriptions) {
            serverHistoryLoaded = true;
            historyItems = data.transcriptions.map(t => ({
                id: t.slug,
                slug: t.slug,
                title: t.title,
                fromServer: true,
                created_at: t.created_at,
                updated_at: t.updated_at
            }));
        }
    } catch (error) {
        serverRequestFailed = true;
        console.warn('Konnte Transkripte nicht vom Server laden:', error);
    }

    // LocalStorage handling:
    // - If server list is available, only keep local-only (unsynced) entries.
    // - If server is unavailable, use full local fallback.
    const localHistory = getLocalTranscriptionHistory();
    if (serverHistoryLoaded) {
        const localOnlyEntries = localHistory.filter(isLocalOnlyTranscription);
        localOnlyEntries.forEach(entry => {
            if (!historyItems.find(h => h.id === entry.id)) {
                historyItems.push({ ...entry, fromServer: false });
            }
        });
        // Remove stale mirrored server entries from local storage
        setLocalTranscriptionHistory(localOnlyEntries);
    } else {
        localHistory.forEach(entry => {
            if (!historyItems.find(h => h.id === entry.id)) {
                historyItems.push({ ...entry, fromServer: false });
            }
        });
    }

    const template = document.getElementById('selection-item-template');
    if (!template) {
        console.error('Selection item template not found');
        return;
    }

    historyItems.sort((a, b) => {
        const aDate = parseHistoryEntryDate(a);
        const bDate = parseHistoryEntryDate(b);
        return (bDate?.getTime() || 0) - (aDate?.getTime() || 0);
    });

    // If this render call is stale, ignore it.
    if (renderSeq !== window.transcriptHistoryRenderSeq) {
        return;
    }

    // Prevent transient backend/network hiccups from blanking an already shown list.
    if (historyItems.length === 0 && serverRequestFailed && previousHtml.trim() !== '') {
        console.warn('History refresh failed, keeping previous sidebar list.');
        return;
    }

    list.innerHTML = '';
    let currentGroup = null;

    historyItems.forEach(entry => {
        const entryDate = parseHistoryEntryDate(entry);
        const groupKey = getHistoryGroupKey(entryDate);
        if (groupKey !== currentGroup) {
            currentGroup = groupKey;
            const category = document.createElement('div');
            category.className = 'history-category';
            category.textContent = getHistoryGroupLabel(groupKey);
            list.appendChild(category);
        }

        const clone = template.content.cloneNode(true);
        const wrapper = clone.querySelector(".selection-item");
        const label = clone.querySelector(".label");

        wrapper.classList.add("history-entry");
        // Ensure consistent visibility - history should be visible if we are in transcript mode
        wrapper.style.display = 'flex';

        wrapper.setAttribute('slug', entry.slug || entry.id);
        wrapper.setAttribute('data-id', entry.id);
        wrapper.setAttribute('data-server', entry.fromServer);
        label.textContent = entry.title;

        wrapper.onclick = (e) => {
            if (e.target.closest('.burger-btn')) return;
            loadTranscript(wrapper);
        };

        wrapper.oncontextmenu = (e) => e.preventDefault();
        list.appendChild(clone);
    });
    if (typeof window.filterHistory === 'function') {
        window.filterHistory();
    }
    console.log(`📊 renderHistory abgeschlossen. ${historyItems.length} Items gerendert.`);
}

window.loadTranscript = async function (target, fromServer = null) {
    console.log("📖 loadTranscript aufgerufen");
    try {
        let rawSegments = [];
        let rawText = '';
        let content = null;
        let id = null;
        let activeItem = null;

        if (target instanceof HTMLElement) {
            activeItem = target.closest('.selection-item');
            if (activeItem) {
                id = activeItem.getAttribute('slug');
                fromServer = activeItem.getAttribute('data-server') === 'true';
            }
        } else {
            id = target;
            activeItem = document.querySelector(`.selection-item[slug="${id}"]`);
        }

        if (!id) {
            throw new Error('Missing transcription id/slug');
        }

        document.querySelectorAll('#chats-list .selection-item').forEach(item => item.classList.remove('active'));
        if (activeItem) activeItem.classList.add('active');

        let allowLocalFallback = !fromServer;
        if (fromServer) {
            try {
                const data = await fetchJsonWithRetry(`/req/transcription/${id}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }, 1);
                if (!data.success || !data.transcription) {
                    throw new Error('Server returned no transcription payload');
                }
                const trans = data.transcription;
                rawSegments = normalizeSegments(trans.segments);
                rawText = normalizeTranscriptText(trans.transcript_text);
                content = formatTranscriptionWithSpeakers(rawSegments, rawText);
            } catch (error) {
                if (error?.status === 404 || error?.status === 410) {
                    // Transcript was removed on server; ensure stale local mirror is gone too.
                    const history = getLocalTranscriptionHistory();
                    setLocalTranscriptionHistory(history.filter(e => e.id !== id && e.slug !== id));
                    allowLocalFallback = false;
                } else {
                    allowLocalFallback = true;
                }
                console.warn('Fehler beim Laden vom Server:', error);
            }
        }

        if (!content && allowLocalFallback) {
            const history = getLocalTranscriptionHistory();
            const entry = history.find(e => e.id === id || e.slug === id);
            if (entry) {
                rawSegments = normalizeSegments(entry.segments);
                rawText = normalizeTranscriptText(entry.content);
                content = formatTranscriptionWithSpeakers(rawSegments, rawText);
            }
        }

        if (!content) {
            alert('Transkription konnte nicht geladen werden');
            return;
        }

        window.currentTranscriptSegments = rawSegments;
        window.currentTranscriptText = rawText;
        window.currentTranscriptSlug = id;
        window.reorderModeActive = false;
        window.transcriptUndoStack = []; // Reset undo stack on load
        updateUndoButtonState();

        const resDiv = document.getElementById('transcription-result');
        if (resDiv) resDiv.innerHTML = content;

        window.switchTranscriptView('view-transcript');

        // Reset sidebar UI to speaker view
        window.toggleSidebarMenu('speakers');

        // Populate speaker rename list
        populateSpeakerPanel(resDiv);

        // Wire up download button
        const downloadBtn = document.getElementById('download-transcript-btn');
        if (downloadBtn) {
            downloadBtn.onclick = () => {
                const text = resDiv ? resDiv.innerText : '';
                const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = `transkription-${id || 'export'}.txt`;
                a.click();
                URL.revokeObjectURL(url);
            };
        }
    } catch (error) {
        console.error('Unerwarteter Fehler beim Laden der Transkription:', error);
        alert('Beim Laden ist ein unerwarteter Fehler aufgetreten.');
    }
};

function populateSpeakerPanel(transcriptContainer) {
    const list = document.getElementById('speaker-rename-list');
    if (!list || !transcriptContainer) return;
    list.innerHTML = '';

    // Collect unique speakers from rendered segments
    const segments = transcriptContainer.querySelectorAll('.transcript-segment[data-speaker]');
    const seenSpeakers = new Set();
    segments.forEach(seg => seenSpeakers.add(seg.getAttribute('data-speaker')));

    if (seenSpeakers.size === 0) {
        list.innerHTML = '<p style="font-size:13px;color:#aaa;">Keine Sprecher erkannt.</p>';
        return;
    }

    seenSpeakers.forEach(speaker => {
        const row = document.createElement('div');
        row.className = 'speaker-rename-row';
        row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:10px;';

        const input = document.createElement('input');
        input.type = 'text';
        input.value = speaker;
        input.dataset.original = speaker;
        input.className = 'speaker-rename-input';
        input.style.cssText = 'flex:1;border:1px solid #dde1e7;border-radius:8px;padding:6px 10px;font-size:13px;background:var(--card-bg,#fff);color:inherit;';

        const applyBtn = document.createElement('button');
        applyBtn.textContent = '✓';
        applyBtn.style.display = 'none'; // User requested to remove the checkmark button
        applyBtn.style.cssText = 'display:none;';

        applyBtn.onclick = () => {
            const oldName = input.dataset.original;
            const newName = input.value.trim();
            if (!newName || newName === oldName) return;

            // 0. Capture state before rename for Undo
            pushToUndo();

            // 1. Update matching segments in the data array
            if (window.currentTranscriptSegments) {
                window.currentTranscriptSegments.forEach(seg => {
                    const currentSpeaker = seg.speaker || (seg.speaker === null ? `Unbekannt ${seg.colorId || ''}` : '');
                    if (seg.speaker === oldName || (seg.speaker === null && oldName.startsWith('Unbekannt '))) {
                        seg.speaker = newName;
                    }
                });
            }

            // 2. Update all matching segments in the DOM
            transcriptContainer.querySelectorAll(`.transcript-segment[data-speaker="${oldName}"]`).forEach(seg => {
                seg.setAttribute('data-speaker', newName);
                const label = seg.querySelector('.speaker-label');
                if (label) label.textContent = newName;
            });

            // 3. Update other inputs referencing the same old name
            list.querySelectorAll('input[data-original="' + oldName + '"]').forEach(inp => {
                inp.dataset.original = newName;
            });

            input.dataset.original = newName;
            applyBtn.style.background = '#22c55e';
            setTimeout(() => applyBtn.style.background = 'var(--color-primary,#5B8CEE)', 1200);

            // 4. Save changes to server
            saveCurrentSegmentsToServer();
        };

        input.addEventListener('keydown', e => { if (e.key === 'Enter') applyBtn.click(); });
        input.addEventListener('blur', () => {
            const oldName = input.dataset.original;
            const newName = input.value.trim();
            if (newName && newName !== oldName) {
                applyBtn.click();
            }
        });

        row.appendChild(input);
        row.appendChild(applyBtn);
        list.appendChild(row);
    });


    const footer = document.createElement('div');
    footer.style.marginTop = '20px';
    footer.style.display = 'flex';
    footer.style.gap = '8px';

    const undoBtn = document.createElement('button');
    undoBtn.id = 'undo-speaker-btn'; // Unique ID for this instance if needed, but we can use class too
    undoBtn.className = 'btn-sidebar-secondary';
    undoBtn.style.cssText = 'width: 42px; height: 42px; padding: 0;';
    undoBtn.title = 'Rückgängig';
    undoBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>';
    undoBtn.onclick = () => window.undoLastMove();

    const finishBtn = document.createElement('button');
    finishBtn.className = 'btn-sidebar-action';
    finishBtn.textContent = 'Änderungen speichern';
    finishBtn.style.flex = '1';
    finishBtn.onclick = async () => {
        await saveCurrentSegmentsToServer();
        window.showTranscriptChoice();
    };

    footer.appendChild(undoBtn);
    footer.appendChild(finishBtn);
    list.appendChild(footer);

    // Ensure all undo buttons are updated
    updateUndoButtonState();
}


window.editTranscriptionTitle = async function () {
    const burgerMenu = document.getElementById('quick-actions');
    let slugOrId = burgerMenu ? burgerMenu.getAttribute('data-room-slug') : null;
    let activeItem = null;
    let label = null;

    if (slugOrId) {
        activeItem = document.querySelector(`.selection-item[slug="${slugOrId}"]`);
        if (activeItem) label = activeItem.querySelector('.label');
    }

    if (!label) {
        activeItem = document.querySelector('.selection-item.active');
        if (activeItem) {
            label = activeItem.querySelector('.label');
            slugOrId = activeItem.getAttribute('slug');
        }
    }

    if (!activeItem || !label) return;

    const originalText = label.textContent;
    const isFromServer = activeItem.getAttribute('data-server') === 'true';
    const id = activeItem.getAttribute('data-id');

    const wrapper = document.createElement('div');
    wrapper.className = 'title-edit-wrapper';

    const input = Object.assign(document.createElement('input'), {
        value: originalText,
        className: 'title-edit-input',
        maxLength: 35,
        onkeydown: (e) => {
            if (e.key === 'Enter') confirmBtn.click();
            if (e.key === 'Escape') cancelBtn.click();
        }
    });

    const confirmBtn = document.createElement('button');
    confirmBtn.className = 'btn-xs title-edit-confirm';
    confirmBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    confirmBtn.onclick = async (e) => {
        e.stopPropagation();
        document.removeEventListener('click', outsideClickHandler);
        const title = input.value.trim() || originalText;

        if (title !== originalText) {
            if (isFromServer) {
                try {
                    await fetch(`/req/transcription/${slugOrId}/title`, {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        },
                        body: JSON.stringify({ title })
                    });
                    label.textContent = title;
                } catch (err) { console.error(err); }
            } else {
                let history = getLocalTranscriptionHistory();
                const entry = history.find(e => e.id === id);
                if (entry) {
                    entry.title = title;
                    setLocalTranscriptionHistory(history);
                }
                label.textContent = title;
            }
        }
        wrapper.replaceWith(label);
    };

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn-xs title-edit-cancel';
    cancelBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
    cancelBtn.onclick = (e) => {
        e.stopPropagation();
        wrapper.replaceWith(label);
        document.removeEventListener('click', outsideClickHandler);
    };

    const outsideClickHandler = (e) => {
        if (!wrapper.contains(e.target)) cancelBtn.click();
    };

    wrapper.appendChild(input);
    wrapper.appendChild(confirmBtn);
    wrapper.appendChild(cancelBtn);
    label.replaceWith(wrapper);
    input.focus();
    input.select();
    if (typeof closeBurgerMenus === 'function') closeBurgerMenus();
    setTimeout(() => document.addEventListener('click', outsideClickHandler), 0);
};

window.requestDeleteTranscription = async function () {
    const burgerMenu = document.getElementById('quick-actions');
    let slugOrId = burgerMenu ? burgerMenu.getAttribute('data-room-slug') : null;
    let activeItem = null;

    if (slugOrId) activeItem = document.querySelector(`.selection-item[slug="${slugOrId}"]`);
    if (!activeItem) {
        activeItem = document.querySelector('.selection-item.active');
        if (activeItem) slugOrId = activeItem.getAttribute('slug');
    }
    if (!activeItem) return;

    if (typeof openModal === 'function' && typeof ModalType !== 'undefined') {
        const confirmed = await openModal(ModalType.WARNING, translation.DeleteChat || "Diesen Eintrag wirklich löschen?");
        if (!confirmed) return;
    } else if (!confirm("Diesen Eintrag wirklich löschen?")) {
        return;
    }

    const isFromServer = activeItem.getAttribute('data-server') === 'true';
    const id = activeItem.getAttribute('data-id');

    if (isFromServer) {
        await fetch(`/req/transcription/${slugOrId}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
    } else {
        let history = getLocalTranscriptionHistory();
        setLocalTranscriptionHistory(history.filter(e => e.id !== id));
    }

    const wasActive = activeItem.classList.contains('active');
    activeItem.remove();
    if (wasActive) window.showTranscriptChoice();
};

function saveTranscriptToHistory(text, slug = null, serverTitle = null, segments = null) {
    const timestamp = new Date().toLocaleString();
    const id = slug || `transcript-${Date.now()}`;
    const title = serverTitle || `Transkription vom ${timestamp}`;
    const nowIso = new Date().toISOString();
    const entry = { id, title, content: text, slug: slug, segments: segments, created_at_local: nowIso, updated_at_local: nowIso };
    let history = getLocalTranscriptionHistory();
    history.unshift(entry);
    setLocalTranscriptionHistory(history);
}

async function saveTranscriptionToDatabase(transcriptionData, audioFile) {
    const response = await fetch('/req/transcription/save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        },
        body: JSON.stringify({
            transcript_text: transcriptionData.text,
            segments: transcriptionData.segments || null,
            words: transcriptionData.words || null,
            language: transcriptionData.language || 'de',
            duration: transcriptionData.duration || null,
            model_used: transcriptionData.model || 'gpt-4o-transcribe',
            provider: 'openai',
            original_filename: audioFile ? audioFile.name : null,
            file_size: audioFile ? audioFile.size : null,
            metadata: { timestamp: new Date().toISOString() }
        })
    });
    const result = await response.json();
    if (!result.success) throw new Error(result.error);
    return result.transcription;
}

function pollForTitleUpdate(slug, initialTitle, maxAttempts = 5, interval = 2000) {
    let attempts = 0;
    const checkTitle = async () => {
        attempts++;
        if (await updateTranscriptionTitle(slug, initialTitle)) return;
        if (attempts < maxAttempts) setTimeout(checkTitle, interval);
    };
    setTimeout(checkTitle, interval);
}

async function updateTranscriptionTitle(slug, initialTitle) {
    try {
        const result = await fetchJsonWithRetry(`/req/transcription/${slug}`, {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        }, 1);
        if (result.success && result.transcription) {
            const newTitle = result.transcription.title;
            if (newTitle && newTitle !== initialTitle) {
                let history = getLocalTranscriptionHistory();
                const entry = history.find(e => e.slug === slug);
                if (entry) {
                    entry.title = newTitle;
                    setLocalTranscriptionHistory(history);
                    renderHistory(); // Refresh UI
                }
                return true;
            }
        }
    } catch (err) { }
    return false;
}

// --- Hauptinitialisierung ---

document.addEventListener('DOMContentLoaded', function () {
    console.log("📍 DOMContentLoaded in transcript_functions.js");
    if (!document.getElementById('transcript-sidebar')) {
        console.log("⏹ Kein Transkriptions-Sidebar gefunden, stoppe Initialisierung.");
        return;
    }

    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('audio_file');

    if (dropZone && fileInput) {
        if (dropZone) dropZone.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', function () {
            if (fileInput.files.length > 0) {
                window.selectedAudioFile = fileInput.files[0];
                const fileNameSpan = document.getElementById('selected-file-name');

                // Sidebar elements
                const sidebarPill = document.getElementById('sidebar-file-pill');
                const sidebarFileName = document.getElementById('sidebar-file-name');
                const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');

                if (fileNameSpan) fileNameSpan.textContent = window.selectedAudioFile.name;

                if (sidebarFileName) sidebarFileName.textContent = window.selectedAudioFile.name;
                if (sidebarPill) sidebarPill.style.display = 'flex';
                if (sidebarPlaceholder) sidebarPlaceholder.style.display = 'none';

                const audioElement = document.createElement('audio');
                audioElement.src = URL.createObjectURL(window.selectedAudioFile);
                audioElement.addEventListener('loadedmetadata', () => {
                    const duration = audioElement.duration;
                    const formattedDuration = formatSecondsToTime(duration);
                    const endTime = document.getElementById('end-time');
                    if (endTime) endTime.value = formattedDuration;
                });
            }
        });

        const dragEvents = ['dragenter', 'dragover', 'dragleave', 'drop'];
        const zones = [dropZone];

        dragEvents.forEach(evt => {
            zones.forEach(zone => {
                zone.addEventListener(evt, (e) => {
                    e.preventDefault();
                    if (evt === 'dragenter' || evt === 'dragover') zone.classList.add('hover');
                    else zone.classList.remove('hover');

                    if (evt === 'drop') {
                        const files = e.dataTransfer.files;
                        if (files.length > 0) {
                            fileInput.files = files;
                            fileInput.dispatchEvent(new Event('change'));
                        }
                    }
                });
            });
        });
    }

    const startUploadBtn = document.getElementById('start-upload-btn');
    if (startUploadBtn) {
        startUploadBtn.addEventListener('click', function () {
            if (!window.selectedAudioFile) {
                alert("Bitte wähle zuerst eine Datei aus.");
                return;
            }

            const dropZoneContent = document.getElementById('drop-zone-content');
            const spinner = document.getElementById('loading-spinner');
            if (dropZoneContent) dropZoneContent.style.display = 'none';
            if (spinner) spinner.style.display = 'block';

            const loadingInfo = document.createElement('p');
            loadingInfo.id = 'loading-info';
            loadingInfo.style.cssText = 'margin-top: 15px; color: #666; font-size: 14px; text-align: center;';
            loadingInfo.innerHTML = 'Transkription läuft...<br><small>Dies kann bei langen Audiodateien mehrere Minuten dauern.</small>';
            if (spinner) spinner.parentElement.appendChild(loadingInfo);

            document.body.classList.add('cursor-wait');

            const formData = new FormData();
            formData.append('audio', window.selectedAudioFile);
            formData.append('language', 'de');

            fetch('/req/transcribe', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
                body: formData,
            })
                .then(response => {
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        throw new Error('Server-Timeout oder Fehler. Bitte kürzere Datei versuchen.');
                    }
                    return response.json();
                })
                .then(data => {
                    if (spinner) spinner.style.display = 'none';
                    const dropZoneContent = document.getElementById('drop-zone-content');
                    if (dropZoneContent) dropZoneContent.style.display = 'flex';
                    const info = document.getElementById('loading-info');
                    if (info) info.remove();
                    document.body.classList.remove('cursor-wait');

                    if (data.success && data.text) {
                        const outputDivInline = document.getElementById('transcription-result-inline');
                        const outputContainerInline = document.getElementById('transcription-output-inline');

                        if (!outputDivInline || !outputContainerInline) {
                            alert('Fehler: Anzeige-Elemente fehlen.');
                            return;
                        }

                        const formattedHTML = formatTranscriptionWithSpeakers(data.segments || [], data.text);
                        outputDivInline.innerHTML = formattedHTML;

                        if (dropZone) dropZone.style.display = 'none';
                        document.getElementById('selected-file-preview').style.display = 'none';
                        outputContainerInline.style.display = 'flex';

                        document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'none');

                        window.activeSavePromise = saveTranscriptionToDatabase(data, window.selectedAudioFile)
                            .then(savedTranscription => {
                                saveTranscriptToHistory(data.text, savedTranscription.slug, savedTranscription.title, data.segments);
                                pollForTitleUpdate(savedTranscription.slug, savedTranscription.title);
                                return savedTranscription;
                            })
                            .catch(err => {
                                console.warn('DB-Save failed:', err);
                                saveTranscriptToHistory(data.text, null, null, data.segments);
                            })
                            .finally(() => {
                                window.activeSavePromise = null;
                            });

                    } else {
                        alert("Fehler: " + (data.message || "Keine Antwort."));
                    }
                })
                .catch(error => {
                    const info = document.getElementById('loading-info');
                    if (info) info.remove();
                    if (spinner) spinner.style.display = 'none';
                    const dropZoneContent = document.getElementById('drop-zone-content');
                    if (dropZoneContent) dropZoneContent.style.display = 'flex';
                    document.body.classList.remove('cursor-wait');
                    alert("Upload-Fehler: " + error.message);
                });
        });
    }

    renderHistory();
});

window.handleBurgerMenuClick = function (event, element) {
    if (event) event.stopPropagation();

    // Get the slug from the parent selection-item
    const selectionItem = element.closest('.selection-item');
    const slug = selectionItem ? selectionItem.getAttribute('slug') : null;

    // Store the slug in the burger menu for later use
    const burgerMenu = document.getElementById('quick-actions');
    if (burgerMenu && slug) {
        burgerMenu.setAttribute('data-room-slug', slug);
    }

    // Handle module specific visibility
    if (typeof activeModule !== 'undefined' && burgerMenu) {
        if (activeModule === 'transcript') {
            // No special logic needed as Blade template handles transcript buttons
        } else if (activeModule === 'groupchat' && typeof rooms !== 'undefined') {
            const room = rooms.find(r => r.slug === slug);
            if (room && room.isRemoved) return;

            const leaveBtn = burgerMenu.querySelector('#burger-leave-btn');
            const declineBtn = burgerMenu.querySelector('#burger-decline-btn');
            const infoBtn = burgerMenu.querySelector('#burger-info-btn');
            const markReadBtn = burgerMenu.querySelector('#burger-mark-read-btn');

            if (room && room.isNewRoom) {
                if (leaveBtn) leaveBtn.style.display = 'none';
                if (declineBtn) declineBtn.style.display = 'block';
                if (infoBtn) infoBtn.style.display = 'none';
                if (markReadBtn) {
                    markReadBtn.style.display = 'none';
                    markReadBtn.disabled = true;
                }
            } else {
                if (leaveBtn) leaveBtn.style.display = 'block';
                if (declineBtn) declineBtn.style.display = 'none';
                if (infoBtn) infoBtn.style.display = 'block';

                if (markReadBtn) {
                    if (room && room.hasUnreadMessages) {
                        markReadBtn.style.display = 'block';
                        markReadBtn.disabled = false;
                    } else {
                        markReadBtn.style.display = 'none';
                        markReadBtn.disabled = true;
                    }
                }
            }
        }
    }

};

window.openSpeakerEditDropdown = function (event, blockIndex) {
    if (event) event.stopPropagation();

    if (!window.lastRenderedSpeakerBlocks || !window.lastRenderedSpeakerBlocks[blockIndex]) return;

    const menu = document.getElementById('custom-context-menu');
    if (!menu) return;

    // Initial Menu Structure
    let html = '<div class="speaker-menu-list">';

    html += `<div class="speaker-menu-item" onclick="showReassignSubmenu(event, ${blockIndex})">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>
        Sprecher neu zuweisen
    </div>`;

    html += `<div class="speaker-menu-item" onclick="insertSpeakerAt(event, ${blockIndex}, 'above')">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="18 15 12 9 6 15"></polyline></svg>
        Neuen Sprecher oben einfügen
    </div>`;

    html += `<div class="speaker-menu-item" onclick="insertSpeakerAt(event, ${blockIndex}, 'below')">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="6 9 12 15 18 9"></polyline></svg>
        Neuen Sprecher unten einfügen
    </div>`;

    html += '</div>';

    menu.innerHTML = html;

    // Position menu only if it was hidden (initial open)
    if (menu.style.display !== 'block') {
        menu.style.display = 'block';
        if (event && event.currentTarget) {
            const rect = event.currentTarget.getBoundingClientRect();
            menu.style.left = (rect.left) + 'px';
            menu.style.top = (rect.bottom + window.scrollY + 5) + 'px';

            // Ensure menu stays within viewport
            setTimeout(() => {
                const menuRect = menu.getBoundingClientRect();
                if (menuRect.right > window.innerWidth) {
                    menu.style.left = (window.innerWidth - menuRect.width - 20) + 'px';
                }
            }, 0);
        }
    }

    // Stop propagation inside the menu to avoid closing it when clicking items
    menu.onclick = (e) => e.stopPropagation();

    // Close on outside click
    const closeMenu = (e) => {
        if (!menu.contains(e.target)) {
            menu.style.display = 'none';
            document.removeEventListener('click', closeMenu);
        }
    };
    setTimeout(() => document.addEventListener('click', closeMenu), 0);
};

window.showReassignSubmenu = function (event, blockIndex) {
    if (event) event.stopPropagation();
    const menu = document.getElementById('custom-context-menu');
    if (!menu || !window.lastRenderedSpeakerBlocks[blockIndex]) return;

    const currentSpeaker = window.lastRenderedSpeakerBlocks[blockIndex].speakerName;
    const speakers = [...new Set(window.currentTranscriptSegments.map(s => s.speaker || 'Person 1'))]
        .filter(s => s && s !== currentSpeaker);

    let html = '<div class="speaker-menu-list">';
    html += '<div class="speaker-menu-header">Zuweisen an:</div>';

    speakers.forEach(speaker => {
        const escaped = speaker.replace(/'/g, "\\'");
        html += `<div class="speaker-menu-item" onclick="reassignSpeaker(${blockIndex}, '${escaped}')">
            ${speaker}
        </div>`;
    });

    html += '<div class="speaker-menu-divider"></div>';
    html += `<div class="speaker-menu-item new-speaker" onclick="showNewSpeakerInline(event, ${blockIndex})">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Neuer Sprecher
    </div>`;

    html += '<div class="speaker-menu-divider"></div>';
    html += `<div class="speaker-menu-item" onclick="openSpeakerEditDropdown(null, ${blockIndex})" style="color: var(--text-faded-color); font-size: 11px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
        Zurück
    </div>`;

    html += '</div>';
    menu.innerHTML = html;
};

window.reassignSpeaker = function (blockIndex, newSpeaker) {
    if (!window.lastRenderedSpeakerBlocks || !window.lastRenderedSpeakerBlocks[blockIndex]) return;

    pushToUndo(); // For undo

    const block = window.lastRenderedSpeakerBlocks[blockIndex];
    block.segmentIndices.forEach(idx => {
        window.currentTranscriptSegments[idx].speaker = newSpeaker;
    });

    renderTranscriptArea();
    const menu = document.getElementById('custom-context-menu');
    if (menu) menu.style.display = 'none';
};

window.showNewSpeakerInline = function (event, blockIndex) {
    if (event) event.stopPropagation();

    const target = event.currentTarget;
    if (!target) return;

    // Pre-extract target to use in timeout if needed
    target.onclick = null;
    target.style.padding = '0';
    target.classList.remove('speaker-menu-item');
    target.style.background = 'transparent';
    target.style.cursor = 'default';

    let html = `
        <div class="speaker-menu-input-container" onclick="event.stopPropagation()">
            <input type="text" class="speaker-menu-input" id="inline-speaker-input" placeholder="Name..." autofocus onkeyup="if(event.key === 'Enter') confirmInlineSpeaker(${blockIndex}, this.value)">
            <button class="speaker-menu-confirm-btn" onclick="confirmInlineSpeaker(${blockIndex}, document.getElementById('inline-speaker-input').value)">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </button>
        </div>
    `;

    target.innerHTML = html;

    // Ensure focus
    setTimeout(() => {
        const input = document.getElementById('inline-speaker-input');
        if (input) input.focus();
    }, 50);
};

window.confirmInlineSpeaker = function (blockIndex, name) {
    if (name && name.trim()) {
        window.reassignSpeaker(blockIndex, name.trim());
    }
};

window.insertSpeakerAt = function (event, blockIndex, position) {
    if (event) event.stopPropagation();
    const menu = document.getElementById('custom-context-menu');
    if (!menu || !window.lastRenderedSpeakerBlocks[blockIndex]) return;

    const currentSpeaker = window.lastRenderedSpeakerBlocks[blockIndex].speakerName;
    // Use all unique speakers, filtered by the current speaker
    const speakers = [...new Set(window.currentTranscriptSegments.map(s => s.speaker || 'Person 1'))]
        .filter(s => s && s !== currentSpeaker);

    let html = '<div class="speaker-menu-list">';
    html += `<div class="speaker-menu-header">Sprecher ${position === 'above' ? 'davor' : 'danach'} einfügen:</div>`;

    speakers.forEach(speaker => {
        const escaped = speaker.replace(/'/g, "\\'");
        html += `<div class="speaker-menu-item" onclick="performSpeakerInsertion(${blockIndex}, '${position}', '${escaped}')">
            ${speaker}
        </div>`;
    });

    html += '<div class="speaker-menu-divider"></div>';
    html += `<div class="speaker-menu-item new-speaker" onclick="showNewSpeakerInlineForInsertion(event, ${blockIndex}, '${position}')">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Neuer Sprecher
    </div>`;

    html += '<div class="speaker-menu-divider"></div>';
    html += `<div class="speaker-menu-item" onclick="openSpeakerEditDropdown(null, ${blockIndex})" style="color: var(--text-faded-color); font-size: 11px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
        Zurück
    </div>`;

    html += '</div>';
    menu.innerHTML = html;
};

window.performSpeakerInsertion = function (blockIndex, position, speakerName) {
    if (!window.lastRenderedSpeakerBlocks || !window.lastRenderedSpeakerBlocks[blockIndex]) return;

    pushToUndo();

    const block = window.lastRenderedSpeakerBlocks[blockIndex];
    let targetIdx;
    let baseTime;

    if (position === 'above') {
        targetIdx = block.segmentIndices[0];
        baseTime = window.currentTranscriptSegments[targetIdx].start;
    } else {
        targetIdx = block.segmentIndices[block.segmentIndices.length - 1] + 1;
        // Use end of last segment in block, or current start if it's the very end
        baseTime = window.currentTranscriptSegments[targetIdx - 1].end;
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

    window.currentTranscriptSegments.splice(targetIdx, 0, newSeg);

    renderTranscriptArea();
    const menu = document.getElementById('custom-context-menu');
    if (menu) menu.style.display = 'none';
};

window.showNewSpeakerInlineForInsertion = function (event, blockIndex, position) {
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
            <input type="text" class="speaker-menu-input" id="inline-insert-input" placeholder="Name..." autofocus onkeyup="if(event.key === 'Enter') confirmInlineInsertion(${blockIndex}, '${position}', this.value)">
            <button class="speaker-menu-confirm-btn" onclick="confirmInlineInsertion(${blockIndex}, '${position}', document.getElementById('inline-insert-input').value)">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
            </button>
        </div>
    `;
    target.innerHTML = html;
    setTimeout(() => {
        const input = document.getElementById('inline-insert-input');
        if (input) input.focus();
    }, 50);
};

window.confirmInlineInsertion = function (blockIndex, position, name) {
    if (name && name.trim()) {
        window.performSpeakerInsertion(blockIndex, position, name.trim());
    }
};

window.selectExportOption = function (option) {
    console.log("💾 Selecting export option:", option);
    
    // 1. Remove 'active' class from all export cards in sidebar
    const cards = document.querySelectorAll('.sidebar-export-card');
    cards.forEach(card => card.classList.remove('active'));
    
    // 2. Add 'active' class to the selected card
    const selectedCard = document.querySelector(`.sidebar-export-card[data-option="${option}"]`);
    if (selectedCard) {
        selectedCard.classList.add('active');
    }
    
    // 3. Logic for what happens when an option is selected can be added here
    // For now, it just updates the UI state.
};

/**
 * Konvertiert Sekunden in das SRT-Zeitstempel-Format (HH:MM:SS,mmm)
 */
function formatSecondsToSRT(seconds) {
    const date = new Date(0);
    date.setSeconds(seconds);
    const hours = date.getUTCHours().toString().padStart(2, '0');
    const minutes = date.getUTCMinutes().toString().padStart(2, '0');
    const secs = date.getUTCSeconds().toString().padStart(2, '0');
    const ms = Math.floor((seconds % 1) * 1000).toString().padStart(3, '0');
    return `${hours}:${minutes}:${secs},${ms}`;
}

/**
 * Exportiert das aktuelle Transkript als SRT-Datei (Vorschau-Modus)
 */
window.exportToSRT = function () {
    if (!window.currentTranscriptSegments || window.currentTranscriptSegments.length === 0) {
        alert("Keine Transkriptionsdaten zum Exportieren vorhanden.");
        return;
    }

    console.log("🎬 Generiere SRT-Vorschau...");
    let srtContent = "";

    window.currentTranscriptSegments.forEach((segment, index) => {
        const start = formatSecondsToSRT(segment.start);
        const end = formatSecondsToSRT(segment.end);
        const speaker = segment.speaker || "Unbekannt";
        const text = segment.text || "";

        srtContent += `${index + 1}\n`;
        srtContent += `${start} --> ${end}\n`;
        srtContent += `${speaker}: ${text.trim()}\n\n`;
    });

    // Vorschau anzeigen
    window.currentExportData = srtContent;
    window.currentExportType = 'srt';
    
    const previewContent = document.getElementById('export-preview-content');
    if (previewContent) {
        previewContent.textContent = srtContent;
    }
    
    const subtitle = document.getElementById('export-preview-subtitle');
    if (subtitle) {
        subtitle.textContent = "SRT-Untertitel Datei Vorschau";
    }

    // Zur Export-UI wechseln
    window.switchTranscriptView('transcript-export-ui');
};

/**
 * Löst den Download der aktuell in der Vorschau angezeigten Datei aus
 */
window.triggerExportDownload = function () {
    if (!window.currentExportData) {
        alert("Es gibt keine Daten zum Herunterladen.");
        return;
    }

    const type = window.currentExportType || 'txt';
    const blob = new Blob([window.currentExportData], { type: `text/${type};charset=utf-8` });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const filename = `transkription-${window.currentTranscriptSlug || 'export'}.${type}`;
    
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
    
    console.log(`✅ Datei heruntergeladen: ${filename}`);
};
