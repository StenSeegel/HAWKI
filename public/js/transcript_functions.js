// Transcript Functions
// Hauptinitialisierung beim Laden der Seite
document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('audio_file');

    function formatSecondsToTime(seconds) {
        const hrs = String(Math.floor(seconds / 3600)).padStart(2, '0');
        const mins = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
        const secs = String(Math.floor(seconds % 60)).padStart(2, '0');
        return `${hrs}:${mins}:${secs}`;
    }

    /**
     * Formatiert Transkription mit Sprechern und Zeitstempeln
     * @param {Array} segments - Whisper API segments (mit text, start, end)
     * @param {string} fullText - Vollständiger Text als Fallback
     * @returns {string} Formatierter HTML-String
     */
    function formatTranscriptionWithSpeakers(segments, fullText) {
        if (!segments || segments.length === 0) {
            // Fallback: Wenn keine Segments, zeige nur den Text
            return `<div class="transcript-segment">
                        <div class="speaker-label">Sprecher 1 (00:00:00):</div>
                        <div class="transcript-text">${fullText}</div>
                    </div>`;
        }

        let formattedHTML = '';
        let currentSpeaker = 1;
        let currentBlock = null;
        let lastEndTime = 0;

        segments.forEach((segment, index) => {
            const pauseDuration = segment.start - lastEndTime;
            const timestamp = formatSecondsToTime(segment.start);
            const text = segment.text.trim();

            // Wechsel Sprecher bei Pause > 2 Sekunden oder alle 45 Sekunden
            const shouldChangeSpeaker = index > 0 && (
                pauseDuration > 2.0 || 
                (segment.start - currentBlock.startTime) > 45
            );

            if (index === 0 || shouldChangeSpeaker) {
                // Schließe vorherigen Block
                if (currentBlock) {
                    formattedHTML += `${currentBlock.text}</div></div>`;
                }

                // Neuer Sprecher
                if (shouldChangeSpeaker) {
                    currentSpeaker = (currentSpeaker % 5) + 1;
                }

                // Neuer Block
                currentBlock = {
                    speaker: currentSpeaker,
                    startTime: segment.start,
                    text: text
                };

                formattedHTML += `<div class="transcript-segment">
                    <div class="speaker-label">Sprecher ${currentSpeaker} (${timestamp}):</div>
                    <div class="transcript-text">`;
            } else {
                // Gleicher Sprecher, füge Text hinzu
                currentBlock.text += ' ' + text;
            }

            lastEndTime = segment.end;

            // Letztes Segment: Schließe Block
            if (index === segments.length - 1) {
                formattedHTML += `${currentBlock.text}</div></div>`;
            }
        });

        return formattedHTML;
    }

    // Klick öffnet Dateiauswahl
    dropZone.addEventListener('click', () => {
        console.log("Drop-Zone wurde geklickt");
        fileInput.click();
    });

    let selectedAudioFile = null;

    fileInput.addEventListener('change', function() {
        if (fileInput.files.length > 0) {
            selectedAudioFile = fileInput.files[0];


            const filePreview = document.getElementById('selected-file-preview');
            const fileNameSpan = document.getElementById('selected-file-name');

            document.getElementById('start-time').value = '00:00:00';

            fileNameSpan.textContent = selectedAudioFile.name;
            filePreview.style.display = 'flex';

            console.log("Datei vorgemerkt, aber noch nicht hochgeladen:", selectedAudioFile);
        }
        // Datei als temporäres Audio laden
        const audioElement = document.createElement('audio');
        audioElement.src = URL.createObjectURL(selectedAudioFile);
        audioElement.addEventListener('loadedmetadata', () => {
            const duration = audioElement.duration; // Sekunden als float
            const formattedDuration = formatSecondsToTime(duration);
            document.getElementById('end-time').value = formattedDuration;
        });
    });

    // Drag-and-Drop Verhalten
    dropZone.addEventListener('dragenter', (e) => {
        e.preventDefault();
        dropZone.classList.add('hover');
    });

    dropZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        dropZone.classList.remove('hover');
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('hover');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('hover');

        const files = e.dataTransfer.files;
        if (files.length > 0) {
            selectedAudioFile = files[0];

            const filePreview = document.getElementById('selected-file-preview');
            const fileNameSpan = document.getElementById('selected-file-name');
            document.getElementById('start-time').value = '00:00:00';

            fileNameSpan.textContent = selectedAudioFile.name;
            filePreview.style.display = 'flex';

            console.log("Datei durch Drag-and-Drop vorgemerkt:", selectedAudioFile);
        }
        // Datei als temporäres Audio laden
        const audioElement = document.createElement('audio');
        audioElement.src = URL.createObjectURL(selectedAudioFile);
        audioElement.addEventListener('loadedmetadata', () => {
            const duration = audioElement.duration; // Sekunden als float
            const formattedDuration = formatSecondsToTime(duration);
            document.getElementById('end-time').value = formattedDuration;
        });
    });
    document.getElementById('start-upload-btn').addEventListener('click', function() {
        if (!selectedAudioFile) {
            alert("Bitte wähle zuerst eine Datei aus.");
            return;
        }
        
        // Zeige Lade-Indikator mit Info-Text
        const dropText = document.getElementById('drop-text');
        const spinner = document.getElementById('loading-spinner');
        
        dropText.style.display = 'none';
        spinner.style.display = 'block';
        
        // Füge Info-Text für lange Verarbeitung hinzu
        const loadingInfo = document.createElement('p');
        loadingInfo.id = 'loading-info';
        loadingInfo.style.cssText = 'margin-top: 15px; color: #666; font-size: 14px; text-align: center;';
        loadingInfo.innerHTML = 'Transkription läuft...<br><small>Dies kann bei langen Audiodateien mehrere Minuten dauern.</small>';
        spinner.parentElement.appendChild(loadingInfo);
        
        document.body.classList.add('cursor-wait');

        const formData = new FormData();
        formData.append('audio', selectedAudioFile);
        formData.append('language', 'de'); // Standard-Sprache Deutsch

        /* Zukünftige Optionen:
        formData.append('file_path', document.getElementById('file-path').value);
        formData.append('start_time', document.getElementById('start-time').value);
        formData.append('end_time', document.getElementById('end-time').value);
        formData.append('language', document.getElementById('language-select').value);
        formData.append('api', document.getElementById('api-select').value);
        formData.append('speaker_count', document.getElementById('speaker-count').value); */

        fetch('/req/transcribe', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')
                        .getAttribute('content'),
                },
                body: formData,
                // Kein Timeout im Fetch - lasse PHP-Timeout entscheiden
            })
            .then(response => {
                // Prüfe ob Response JSON ist
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Server hat keine JSON-Antwort zurückgegeben. Möglicherweise ist ein Timeout aufgetreten. Bitte versuchen Sie es mit einer kürzeren Audiodatei.');
                }
                return response.json();
            })
            .then(data => {
                document.getElementById('loading-spinner').style.display = 'none';
                document.getElementById('drop-text').style.display = 'block';
                
                // Entferne Lade-Info falls vorhanden
                const loadingInfo = document.getElementById('loading-info');
                if (loadingInfo) loadingInfo.remove();
                
                document.body.classList.remove('cursor-wait');
                console.log('✅ Upload abgeschlossen:', JSON.stringify(data, null, 2));

                if (data.success && data.text) {
                    // Zeige die Transkription INLINE im Upload-Bereich an
                    const outputDivInline = document.getElementById('transcription-result-inline');
                    const outputContainerInline = document.getElementById('transcription-output-inline');

                    if (!outputDivInline || !outputContainerInline) {
                        console.error('❌ Transkriptions-Elemente nicht gefunden. Bitte Seite neu laden (Strg+F5).');
                        alert('Fehler: Transkriptions-Anzeige nicht gefunden. Bitte laden Sie die Seite neu (Strg+F5 / Cmd+Shift+R).');
                        return;
                    }

                    // Formatiere Text mit Sprechern und Zeitstempeln
                    const formattedHTML = formatTranscriptionWithSpeakers(data.segments || [], data.text);
                    outputDivInline.innerHTML = formattedHTML;

                    // Verstecke Drop-Zone und Dateivorschau
                    document.getElementById('drop-zone').style.display = 'none';
                    document.getElementById('selected-file-preview').style.display = 'none';

                    // Zeige Transkript unterhalb des Trennstrichs
                    outputContainerInline.style.display = 'flex';

                    // History aktualisieren
                    document.getElementById('history-title').style.display = 'none';
                    document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'none');
                    
                    saveTranscriptToHistory(formattedHTML);

                    // Kopier-Button für Inline-Version
                    document.getElementById('copy-transcript-btn-inline').onclick = function() {
                        const text = document.getElementById('transcription-result-inline').innerText;
                        navigator.clipboard.writeText(text)
                            .then(() => alert('Transkription wurde in die Zwischenablage kopiert!'))
                            .catch(err => alert('Fehler beim Kopieren: ' + err));
                    };
                } else {
                    // Fehlerfall: API-Response aber kein Erfolg
                    const errorMessage = data.message || "Keine Transkription erhalten.";
                    console.error('❌ Transkription fehlgeschlagen:', errorMessage);
                    alert("Fehler bei der Transkription: " + errorMessage);
                }
            })
            .catch(error => {
                // Entferne Lade-Info falls vorhanden
                const loadingInfo = document.getElementById('loading-info');
                if (loadingInfo) loadingInfo.remove();
                
                document.getElementById('loading-spinner').style.display = 'none';
                document.getElementById('drop-text').style.display = 'block';
                document.body.classList.remove('cursor-wait');

                console.error('❌ Upload fehlgeschlagen:', error);
                alert("Fehler beim Hochladen: " + error.message);
            });
    });


    function renderHistory() {
        const list = document.getElementById("chats-list");
        if (!list) return;
        //start - upload
        // Vorherige Einträge entfernen
        list.querySelectorAll(".history-entry").forEach(e => e.remove());

        const history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
        const contextMenu = document.getElementById("custom-context-menu");

        history.forEach(entry => {
            const wrapper = document.createElement("div");
            wrapper.className = "history-entry selection-item";
            wrapper.style.cursor = "pointer";
            wrapper.textContent = entry.title;

            // Sichtbarkeit steuern
            wrapper.classList.add("history-entry");

            // Links-Klick: Transkript laden
            wrapper.onclick = () => loadTranscript(entry.id);

            // Rechtsklick: Kontextmenü anzeigen
            wrapper.oncontextmenu = (e) => {
                e.preventDefault();

                contextMenu.innerHTML = "";

                const renameOption = document.createElement("div");
                renameOption.textContent = "Umbenennen";
                renameOption.className = "context-menu-item";
                renameOption.onclick = () => {
                    const newTitle = prompt("Neuer Titel:", entry.title);
                    if (newTitle) {
                        entry.title = newTitle;
                        localStorage.setItem("transcriptionHistory", JSON.stringify(
                            history));
                        renderHistory(); // wichtig!
                    }
                    contextMenu.style.display = "none";
                };

                const deleteOption = document.createElement("div");
                deleteOption.textContent = "Löschen";
                deleteOption.className = "context-menu-item";
                deleteOption.onclick = () => {
                    if (confirm("Diesen Eintrag wirklich löschen?")) {
                        const updated = history.filter(e => e.id !== entry.id);
                        localStorage.setItem("transcriptionHistory", JSON.stringify(
                            updated));
                        renderHistory(); // wichtig!
                    }
                    contextMenu.style.display = "none";
                };

                contextMenu.appendChild(renameOption);
                contextMenu.appendChild(deleteOption);

                contextMenu.style.top = `${e.pageY}px`;
                contextMenu.style.left = `${e.pageX}px`;
                contextMenu.style.display = "block";
            };

            list.appendChild(wrapper);
        });
    }

    // Optional: ESC schließt Menü
    document.addEventListener("keydown", function(e) {
        if (e.key === "Escape") {
            const contextMenu = document.getElementById("custom-context-menu");
            if (contextMenu) {
                contextMenu.style.display = "none";
            }
        }
    });
    renderHistory(true);
});


// Globale Funktionen außerhalb von DOMContentLoaded
function showTranscriptMode(mode) {
    // Fade out den "Neue Transcription" Button
    const newTranscriptBtn = document.getElementById('new-transcription-btn');
    if (newTranscriptBtn) {
        newTranscriptBtn.classList.add('fade-out');
        // Nach der Animation komplett ausblenden
        setTimeout(() => {
            newTranscriptBtn.classList.add('hidden');
        }, 300); // Entspricht --transition-fast
    }

    document.getElementById('transcript-choice').style.display = 'none';
    document.getElementById('history-title').style.display = 'none';
    document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'none');
    document.getElementById('transcript-file-ui').style.display = 'none';
    document.getElementById('transcript-live-ui').style.display = 'none';
    document.getElementById('file-transcription-options').style.display = 'none';

    // Pfeil anzeigen
    document.getElementById('back-button-wrapper').style.display = 'block';

    document.getElementById('start-upload-wrapper').style.display = (mode === 'file') ? 'block' : 'none';

    document.getElementById('speaker-recognition-wrapper').style.display = (mode === 'file') ? 'block' : 'none';

    if (mode === 'file') {
        document.getElementById('transcript-file-ui').style.display = 'block';
        document.getElementById('file-transcription-options').style.display = 'block';
    } else if (mode === 'live') {
        document.getElementById('transcript-live-ui').style.display = 'block';
    }
}

function showTranscriptChoice() {
    // Fade in den "Neue Transcription" Button
    const newTranscriptBtn = document.getElementById('new-transcription-btn');
    if (newTranscriptBtn) {
        newTranscriptBtn.classList.remove('hidden');
        // Kurze Verzögerung für smooth fade-in
        setTimeout(() => {
            newTranscriptBtn.classList.remove('fade-out');
        }, 10);
    }

    // Auswahl anzeigen
    document.getElementById('transcript-choice').style.display = 'block';
    document.getElementById('history-title').style.display = 'block';
    document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'block');

    // Alles andere ausblenden
    document.getElementById('transcript-file-ui').style.display = 'none';
    document.getElementById('transcript-live-ui').style.display = 'none';
    document.getElementById('file-transcription-options').style.display = 'none';
    document.getElementById('start-upload-wrapper').style.display = 'none';
    document.getElementById('speaker-recognition-wrapper').style.display = 'none';
    document.getElementById('back-button-wrapper').style.display = 'none';

    // Beide Transkriptionsergebnisse zurücksetzen
    const outputDiv = document.getElementById('transcription-output');
    const outputDivInline = document.getElementById('transcription-output-inline');
    const resultDiv = document.getElementById('transcription-result');
    const resultDivInline = document.getElementById('transcription-result-inline');

    if (outputDiv) outputDiv.style.display = 'none';
    if (outputDivInline) outputDivInline.style.display = 'none';
    if (resultDiv) resultDiv.innerText = '';
    if (resultDivInline) resultDivInline.innerText = '';

    // Drop-Zone sichtbar machen
    const dropZone = document.getElementById('drop-zone');
    if (dropZone) dropZone.style.display = 'flex';

    // Datei-Vorschau und Name zurücksetzen
    const filePreview = document.getElementById('selected-file-preview');
    if (filePreview) filePreview.style.display = 'none';
    const fileNameSpan = document.getElementById('selected-file-name');
    if (fileNameSpan) fileNameSpan.textContent = 'Keine Datei ausgewählt';

    // Zustand zurücksetzen
    selectedAudioFile = null;
}

function saveTranscriptToHistory(text) {
    const timestamp = new Date().toLocaleString();
    const id = `transcript-${Date.now()}`;
    const entry = {
        id,
        title: `Transkription vom ${timestamp}`,
        content: text
    };

    let history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
    history.unshift(entry);
    localStorage.setItem("transcriptionHistory", JSON.stringify(history));

    // renderHistory(false);
}

function loadTranscript(id) {
    const history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
    const entry = history.find(e => e.id === id);
    if (!entry) return;

    // Verwende die separate History-Ausgabe (nicht die Inline-Version)
    document.getElementById('transcription-result').innerHTML = entry.content;
    document.getElementById('transcription-output').style.display = 'flex';
    document.getElementById('back-button-wrapper').style.display = 'block';

    // Kopier-Button für History-Version
    document.getElementById('copy-transcript-btn').onclick = function() {
        const text = document.getElementById('transcription-result').innerText;
        navigator.clipboard.writeText(text)
            .then(() => alert('Transkription wurde in die Zwischenablage kopiert!'))
            .catch(err => alert('Fehler beim Kopieren: ' + err));
    };

    // Alles andere ausblenden
    document.getElementById('transcript-choice').style.display = 'none';
    document.getElementById('transcript-file-ui').style.display = 'none';
    document.getElementById('transcript-live-ui').style.display = 'none';
    document.getElementById('file-transcription-options').style.display = 'none';
    document.getElementById('history-title').style.display = 'none';
    document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'none');
}
