// Transcript Functions - Globale Definitionen
console.log("🚀 transcript_functions.js geladen");

// Globale Variablen für File-Status und Save-Promise
window.selectedAudioFile = null;
window.activeSavePromise = null;

// --- Globale UI Funktionen (Sofort verfügbar) ---

window.showTranscriptMode = function (mode) {
    console.log("🛠 showTranscriptMode aufgerufen:", mode);

    const elementsToHide = [
        'transcript-choice', 'history-title', 'transcript-file-ui',
        'transcript-live-ui', 'file-transcription-options'
    ];
    elementsToHide.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'none');
    document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'flex');

    const backBtn = document.getElementById('back-button-wrapper');
    if (backBtn) backBtn.style.display = 'block';

    if (mode === 'file') {
        const fileUi = document.getElementById('transcript-file-ui');
        const fileOpts = document.getElementById('file-transcription-options');
        const historyTitle = document.getElementById('history-title');
        
        if (fileUi) fileUi.style.display = 'block';
        if (fileOpts) fileOpts.style.display = 'block';
        if (historyTitle) historyTitle.style.display = 'none';
        
    } else if (mode === 'live') {
        const liveUi = document.getElementById('transcript-live-ui');
        if (liveUi) liveUi.style.display = 'block';
    }
};

window.showTranscriptChoice = async function () {
    console.log("🛠 showTranscriptChoice aufgerufen");
    if (window.activeSavePromise) {
        console.log('⏳ Warte auf Abschluss des Speichervorgangs...');
        try { await window.activeSavePromise; } catch (err) { }
    }

    const elementsToShow = ['transcript-choice', 'history-title'];
    elementsToShow.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'block';
    });

    const elementsToHide = [
        'transcript-file-ui', 'transcript-live-ui', 'file-transcription-options',
        'back-button-wrapper', 'transcription-output'
    ];
    elementsToHide.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    
    // Ensure history is rendered and shown
    await renderHistory();
    document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'flex');

    const outputDivInline = document.getElementById('transcription-output-inline');
    if (outputDivInline) outputDivInline.style.display = 'none';

    window.removeSelectedFile();

    // Ensure history title and options are toggled correctly
    const fileOpts = document.getElementById('file-transcription-options');
    if (fileOpts) fileOpts.style.display = 'none';

    await renderHistory();
};

window.removeSelectedFile = function () {
    window.selectedAudioFile = null;
    const filePill = document.getElementById('selected-file-pill');
    const dropZoneSidebar = document.getElementById('drop-zone-sidebar');
    const fileInput = document.getElementById('audio_file');
    const filePreview = document.getElementById('selected-file-preview');
    
    // Sidebar elements
    const sidebarPill = document.getElementById('sidebar-file-pill');
    const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');
    const dropText = document.getElementById('drop-text');

    if (filePill) filePill.style.display = 'none';
    if (dropZoneSidebar) dropZoneSidebar.style.display = 'block';
    if (fileInput) fileInput.value = '';
    if (filePreview) filePreview.style.display = 'none';
    if (dropText) dropText.style.display = 'block';
    
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

function formatTranscriptionWithSpeakers(segments, fullText) {
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
    let speakerMap = new Map(); // Maps speaker names to color indices (1-5)
    let colorIndexCounter = 1;
    let currentBlock = null;
    let lastEndTime = 0;

    segments.forEach((segment, index) => {
        const pauseDuration = segment.start - lastEndTime;
        const timestamp = formatSecondsToTime(segment.start);
        const text = segment.text.trim();
        
        // Use speaker from segment (diarized) or fallback to heuristic
        const segmentSpeaker = segment.speaker || null;
        
        let shouldChangeSpeaker = false;
        if (segmentSpeaker) {
            // Change if current block exists and has a different speaker
            shouldChangeSpeaker = currentBlock && currentBlock.speakerName !== segmentSpeaker;
        } else {
            // Heuristic fallback
            shouldChangeSpeaker = index > 0 && (
                pauseDuration > 2.0 ||
                (segment.start - (currentBlock ? currentBlock.startTime : 0)) > 45
            );
        }

        if (index === 0 || shouldChangeSpeaker) {
            if (currentBlock) {
                formattedHTML += `${currentBlock.text}</div></div>`;
            }

            let speakerName = segmentSpeaker || `Person ${colorIndexCounter}`;
            if (!speakerMap.has(speakerName)) {
                speakerMap.set(speakerName, (speakerMap.size % 5) + 1);
            }
            const colorId = speakerMap.get(speakerName);
            const isIndented = colorId % 2 === 0 ? 'indented' : '';

            currentBlock = {
                speakerName: speakerName,
                colorId: colorId,
                startTime: segment.start,
                text: text
            };

            formattedHTML += `<div class="transcript-segment ${isIndented}">
                <div class="segment-header">
                    <div class="speaker-avatar" style="background: var(--speaker-${colorId}-gradient, linear-gradient(135deg, #6e8efb, #a777e3))"></div>
                    <div class="speaker-info">${speakerName} <span class="speaker-sep">•</span> [${timestamp}]</div>
                </div>
                <div class="transcript-text">`;
        } else {
            currentBlock.text += ' ' + text;
        }

        lastEndTime = segment.end;

        if (index === segments.length - 1 && currentBlock) {
            formattedHTML += `${currentBlock.text}</div></div>`;
        }
    });

    return formattedHTML || `<div class="transcript-segment"><div class="transcript-text">${fullText}</div></div>`;
}

window.renderHistory = async function () {
    console.log("📊 renderHistory aufgerufen");
    if (!document.getElementById('transcript-sidebar')) {
        return;
    }
    const list = document.getElementById("chats-list");
    if (!list) return;
    list.querySelectorAll(".history-entry").forEach(e => e.remove());

    const historyTitle = document.getElementById('history-title');
    const shouldHide = historyTitle && historyTitle.style.display === 'none';

    let historyItems = [];

    // Server load
    try {
        const response = await fetch('/req/transcriptions', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (response.ok) {
            const data = await response.json();
            if (data.success && data.transcriptions) {
                historyItems = data.transcriptions.map(t => ({
                    id: t.slug,
                    slug: t.slug,
                    title: t.title,
                    fromServer: true
                }));
            }
        }
    } catch (error) {
        console.warn('Konnte Transkripte nicht vom Server laden:', error);
    }

    // LocalStorage fallback
    const localHistory = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
    localHistory.forEach(entry => {
        if (!historyItems.find(h => h.id === entry.id)) {
            historyItems.push({ ...entry, fromServer: false });
        }
    });

    const template = document.getElementById('selection-item-template');
    if (!template) {
        console.error('Selection item template not found');
        return;
    }

    historyItems.forEach(entry => {
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
    console.log(`📊 renderHistory abgeschlossen. ${historyItems.length} Items gerendert.`);
}

window.loadTranscript = async function (target, fromServer = null) {
    console.log("📖 loadTranscript aufgerufen");
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

    document.querySelectorAll('#chats-list .selection-item').forEach(item => item.classList.remove('active'));
    if (activeItem) activeItem.classList.add('active');

    if (fromServer) {
        try {
            const response = await fetch(`/req/transcription/${id}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.transcription) {
                    const trans = data.transcription;
                    content = formatTranscriptionWithSpeakers(trans.segments || [], trans.transcript_text);
                }
            }
        } catch (error) {
            console.warn('Fehler beim Laden vom Server:', error);
        }
    }

    if (!content) {
        const history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
        const entry = history.find(e => e.id === id);
        if (entry) {
            content = formatTranscriptionWithSpeakers(entry.segments || [], entry.content);
        }
    }

    if (!content) {
        alert('Transkription konnte nicht geladen werden');
        return;
    }

    const resDiv = document.getElementById('transcription-result');
    const resOut = document.getElementById('transcription-output');
    const backBtnWrap = document.getElementById('back-button-wrapper');
    const copyBtn = document.getElementById('copy-transcript-btn');

    if (resDiv) resDiv.innerHTML = content;
    if (resOut) resOut.style.display = 'flex';
    if (backBtnWrap) backBtnWrap.style.display = 'block';

    if (copyBtn) {
        copyBtn.onclick = function () {
            const text = resDiv.innerText;
            navigator.clipboard.writeText(text)
                .then(() => alert('Transkription wurde in die Zwischenablage kopiert!'))
                .catch(err => alert('Fehler beim Kopieren: ' + err));
        };
    }

    const elementsToHide = [
        'transcript-choice', 'transcript-file-ui', 'transcript-live-ui',
        'file-transcription-options', 'history-title'
    ];
    elementsToHide.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    // Don't hide history entries when loading a transcript, just let the result show
    // document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'none');
};

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
                let history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
                const entry = history.find(e => e.id === id);
                if (entry) {
                    entry.title = title;
                    localStorage.setItem("transcriptionHistory", JSON.stringify(history));
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
        let history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
        localStorage.setItem("transcriptionHistory", JSON.stringify(history.filter(e => e.id !== id)));
    }

    const wasActive = activeItem.classList.contains('active');
    activeItem.remove();
    if (wasActive) window.showTranscriptChoice();
};

function saveTranscriptToHistory(text, slug = null, serverTitle = null, segments = null) {
    const timestamp = new Date().toLocaleString();
    const id = slug || `transcript-${Date.now()}`;
    const title = serverTitle || `Transkription vom ${timestamp}`;
    const entry = { id, title, content: text, slug: slug, segments: segments };
    let history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
    history.unshift(entry);
    localStorage.setItem("transcriptionHistory", JSON.stringify(history));
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
        const response = await fetch(`/req/transcription/${slug}`, {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        });
        const result = await response.json();
        if (result.success && result.transcription) {
            const newTitle = result.transcription.title;
            if (newTitle && newTitle !== initialTitle) {
                let history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
                const entry = history.find(e => e.slug === slug);
                if (entry) {
                    entry.title = newTitle;
                    localStorage.setItem("transcriptionHistory", JSON.stringify(history));
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
    const dropZoneSidebar = document.getElementById('drop-zone-sidebar');
    const fileInput = document.getElementById('audio_file');

    if ((dropZone || dropZoneSidebar) && fileInput) {
        if (dropZone) dropZone.addEventListener('click', () => fileInput.click());
        if (dropZoneSidebar) dropZoneSidebar.addEventListener('click', () => fileInput.click());

        fileInput.addEventListener('change', function () {
                if (fileInput.files.length > 0) {
                    window.selectedAudioFile = fileInput.files[0];
                    const filePill = document.getElementById('selected-file-pill');
                    const dropZoneSidebar = document.getElementById('drop-zone-sidebar');
                    const fileNameSpan = document.getElementById('selected-file-name');
                    
                    // Sidebar elements
                    const sidebarPill = document.getElementById('sidebar-file-pill');
                    const sidebarFileName = document.getElementById('sidebar-file-name');
                    const sidebarPlaceholder = document.getElementById('sidebar-file-placeholder');

                    if (fileNameSpan) fileNameSpan.textContent = window.selectedAudioFile.name;
                    if (filePill) filePill.style.display = 'flex';
                    if (dropZoneSidebar) dropZoneSidebar.style.display = 'none';
                    
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
        const zones = [dropZone, dropZoneSidebar].filter(z => z);

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

            const dropText = document.getElementById('drop-text');
            const spinner = document.getElementById('loading-spinner');
            if (dropText) dropText.style.display = 'none';
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
                    if (dropText) dropText.style.display = 'block';
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

                        const historyTitle = document.getElementById('history-title');
                        if (historyTitle) historyTitle.style.display = 'none';
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

                        const copyBtnInline = document.getElementById('copy-transcript-btn-inline');
                        if (copyBtnInline) {
                            copyBtnInline.onclick = function () {
                                navigator.clipboard.writeText(outputDivInline.innerText)
                                    .then(() => alert('Kopiert!'))
                                    .catch(err => alert('Fehler: ' + err));
                            };
                        }
                    } else {
                        alert("Fehler: " + (data.message || "Keine Antwort."));
                    }
                })
                .catch(error => {
                    const info = document.getElementById('loading-info');
                    if (info) info.remove();
                    if (spinner) spinner.style.display = 'none';
                    if (dropText) dropText.style.display = 'block';
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

    if (typeof openBurgerMenu === 'function') {
        openBurgerMenu('quick-actions', element, true);
    }
};
