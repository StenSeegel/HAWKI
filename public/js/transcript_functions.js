// Transcript Functions
// Globale Variablen für File-Status und Save-Promise
let selectedAudioFile = null;
let activeSavePromise = null;

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
                    
                    // ✨ NEU: Automatisch in Datenbank speichern für Titel-Generierung
                    activeSavePromise = saveTranscriptionToDatabase(data, selectedAudioFile)
                        .then(savedTranscription => {
                            console.log('✅ Transkription in Datenbank gespeichert:', savedTranscription);
                            // Speichere lokale History mit Server-Slug
                            saveTranscriptToHistory(formattedHTML, savedTranscription.slug, savedTranscription.title);
                            
                            // 🔄 Prüfe alle 2 Sekunden ob Titel generiert wurde (max 10 Sekunden)
                            pollForTitleUpdate(savedTranscription.slug, savedTranscription.title);
                            
                            return savedTranscription;
                        })
                        .catch(err => {
                            console.warn('⚠️  Konnte nicht in DB speichern:', err);
                            // Fallback: Nur lokal speichern
                            saveTranscriptToHistory(formattedHTML);
                            throw err;
                        })
                        .finally(() => {
                            activeSavePromise = null; // Clear after completion
                        });

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


    async function renderHistory() {
        const list = document.getElementById("chats-list");
        if (!list) return;
        //start - upload
        // Vorherige Einträge entfernen
        list.querySelectorAll(".history-entry").forEach(e => e.remove());

        let history = [];

        // Zuerst vom Server laden (gespeicherte Transkriptionen)
        try {
            const response = await fetch('/req/transcriptions', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                }
            });
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.transcriptions) {
                    history = data.transcriptions.map(t => ({
                        id: t.slug,
                        slug: t.slug,
                        title: t.title,
                        content: '', // Wird später nachgeladen
                        fromServer: true
                    }));
                }
            }
        } catch (error) {
            console.warn('Konnte Transkripte nicht vom Server laden:', error);
        }

        // LocalStorage als Fallback (für nicht-gespeicherte Transkripte)
        const localHistory = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
        localHistory.forEach(entry => {
            // Nur hinzufügen, wenn nicht bereits vom Server geladen
            if (!history.find(h => h.id === entry.id)) {
                history.push({...entry, fromServer: false});
            }
        });

        const contextMenu = document.getElementById("custom-context-menu");

        history.forEach(entry => {
            const wrapper = document.createElement("div");
            wrapper.className = "history-entry selection-item";
            wrapper.style.cursor = "pointer";
            wrapper.textContent = entry.title;

            // Sichtbarkeit steuern
            wrapper.classList.add("history-entry");

            // Links-Klick: Transkript laden
            wrapper.onclick = () => loadTranscript(entry.id, entry.fromServer);

            // Rechtsklick: Kontextmenü anzeigen
            wrapper.oncontextmenu = (e) => {
                e.preventDefault();

                contextMenu.innerHTML = "";

                const renameOption = document.createElement("div");
                renameOption.textContent = "Umbenennen";
                renameOption.className = "context-menu-item";
                renameOption.onclick = async () => {
                    const newTitle = prompt("Neuer Titel:", entry.title);
                    if (newTitle) {
                        if (entry.fromServer) {
                            // Mit Server synchronisieren
                            try {
                                const response = await fetch(`/req/transcription/${entry.slug}/title`, {
                                    method: 'PATCH',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                        'X-Requested-With': 'XMLHttpRequest',
                                    },
                                    body: JSON.stringify({ title: newTitle })
                                });
                                if (response.ok) {
                                    console.log('✅ Titel auf Server aktualisiert');
                                    await renderHistory();
                                } else {
                                    alert('Fehler beim Umbenennen auf dem Server');
                                }
                            } catch (error) {
                                console.error('Fehler beim Umbenennen:', error);
                                alert('Fehler beim Umbenennen: ' + error.message);
                            }
                        } else {
                            // Nur localStorage
                            entry.title = newTitle;
                            const localHistory = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
                            const localEntry = localHistory.find(e => e.id === entry.id);
                            if (localEntry) {
                                localEntry.title = newTitle;
                                localStorage.setItem("transcriptionHistory", JSON.stringify(localHistory));
                            }
                            await renderHistory();
                        }
                    }
                    contextMenu.style.display = "none";
                };

                const deleteOption = document.createElement("div");
                deleteOption.textContent = "Löschen";
                deleteOption.className = "context-menu-item";
                deleteOption.onclick = async () => {
                    if (confirm("Diesen Eintrag wirklich löschen?")) {
                        if (entry.fromServer) {
                            // Mit Server synchronisieren
                            try {
                                const response = await fetch(`/req/transcription/${entry.slug}`, {
                                    method: 'DELETE',
                                    headers: {
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                        'X-Requested-With': 'XMLHttpRequest',
                                    }
                                });
                                if (response.ok) {
                                    console.log('✅ Transkription vom Server gelöscht');
                                    await renderHistory();
                                } else {
                                    alert('Fehler beim Löschen auf dem Server');
                                }
                            } catch (error) {
                                console.error('Fehler beim Löschen:', error);
                                alert('Fehler beim Löschen: ' + error.message);
                            }
                        } else {
                            // Nur localStorage
                            const localHistory = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
                            const updated = localHistory.filter(e => e.id !== entry.id);
                            localStorage.setItem("transcriptionHistory", JSON.stringify(updated));
                            await renderHistory();
                        }
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
    renderHistory(); // Initial load
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

// Als globale Funktion verfügbar machen für onclick-Handler in Blade-Templates
window.showTranscriptChoice = async function() {
    // Falls noch ein Speichervorgang läuft, warte darauf
    if (activeSavePromise) {
        console.log('⏳ Warte auf Abschluss des Speichervorgangs...');
        try {
            await activeSavePromise;
            console.log('✅ Speichervorgang abgeschlossen!');
        } catch (err) {
            console.warn('⚠️  Speichervorgang fehlgeschlagen, fahre trotzdem fort:', err);
        }
    }
    
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
    
    // History vom Server neu laden (zeigt neue Transkripte ohne Page-Reload)
    await renderHistory();
}

function saveTranscriptToHistory(text, slug = null, serverTitle = null) {
    const timestamp = new Date().toLocaleString();
    const id = slug || `transcript-${Date.now()}`;
    const title = serverTitle || `Transkription vom ${timestamp}`;
    
    const entry = {
        id,
        title,
        content: text,
        slug: slug // Optional: Für späteres Laden von Server
    };

    let history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
    history.unshift(entry);
    localStorage.setItem("transcriptionHistory", JSON.stringify(history));

    // renderHistory(false);
}

/**
 * Speichert Transkription in der Datenbank für automatische Titel-Generierung
 * @param {Object} transcriptionData - Die Transkriptionsdaten von /req/transcribe
 * @param {File} audioFile - Die hochgeladene Audio-Datei
 * @returns {Promise} Promise mit gespeicherter Transkription
 */
async function saveTranscriptionToDatabase(transcriptionData, audioFile) {
    const saveData = {
        transcript_text: transcriptionData.text,
        segments: transcriptionData.segments || null,
        words: transcriptionData.words || null,
        language: transcriptionData.language || 'de',
        duration: transcriptionData.duration || null,
        model_used: transcriptionData.model || 'gpt-4o-transcribe',
        provider: 'openai',
        original_filename: audioFile ? audioFile.name : null,
        file_size: audioFile ? audioFile.size : null,
        metadata: {
            timestamp: new Date().toISOString()
        }
    };

    const response = await fetch('/req/transcription/save', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        },
        body: JSON.stringify(saveData)
    });

    if (!response.ok) {
        throw new Error(`Server error: ${response.status}`);
    }

    const result = await response.json();
    
    if (!result.success) {
        throw new Error(result.error || 'Failed to save transcription');
    }

    return result.transcription;
}

/**
 * Prüft wiederholt ob der Titel generiert wurde (Polling)
 * @param {string} slug - Der Slug der Transkription
 * @param {string} initialTitle - Der initiale Titel (Fallback)
 * @param {number} maxAttempts - Maximale Anzahl Versuche (default: 5)
 * @param {number} interval - Intervall in ms zwischen Versuchen (default: 2000)
 */
function pollForTitleUpdate(slug, initialTitle, maxAttempts = 5, interval = 2000) {
    let attempts = 0;
    
    const checkTitle = async () => {
        attempts++;
        
        const titleChanged = await updateTranscriptionTitle(slug, initialTitle);
        
        if (titleChanged) {
            console.log(`✅ Titel wurde nach ${attempts * interval / 1000} Sekunden aktualisiert`);
            return; // Stop polling
        }
        
        if (attempts < maxAttempts) {
            // Weiter prüfen
            setTimeout(checkTitle, interval);
        } else {
            console.log(`⏱️ Titel-Generierung dauert länger als erwartet (>${maxAttempts * interval / 1000}s)`);
        }
    };
    
    // Erste Prüfung nach 2 Sekunden
    setTimeout(checkTitle, interval);
}

/**
 * Lädt den aktualisierten Titel vom Server und aktualisiert localStorage
 * @param {string} slug - Der Slug der Transkription
 * @param {string} initialTitle - Der initiale Titel zum Vergleich
 * @returns {Promise<boolean>} true wenn Titel sich geändert hat, sonst false
 */
async function updateTranscriptionTitle(slug, initialTitle) {
    try {
        const response = await fetch(`/req/transcription/${slug}`, {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            }
        });
        
        if (!response.ok) return false;
        
        const result = await response.json();
        
        if (result.success && result.transcription) {
            const newTitle = result.transcription.title;
            
            // Prüfe ob Titel sich vom initialen unterscheidet
            const hasChanged = newTitle && newTitle !== initialTitle;
            
            if (hasChanged) {
                // Update localStorage
                let history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
                const entry = history.find(e => e.slug === slug);
                
                if (entry) {
                    entry.title = newTitle;
                    localStorage.setItem("transcriptionHistory", JSON.stringify(history));
                    console.log(`✅ Titel aktualisiert: "${newTitle}"`);
                    
                    // Optional: UI aktualisieren falls sichtbar
                    // renderHistory();
                }
                
                return true; // Titel wurde geändert
            }
        }
        
        return false; // Titel noch nicht generiert
    } catch (err) {
        console.warn('⚠️  Konnte Titel nicht aktualisieren:', err);
        return false;
    }
}

async function loadTranscript(id, fromServer = false) {
    let content = null;
    
    // Zuerst vom Server versuchen, falls es eine gespeicherte Transkription ist
    if (fromServer) {
        try {
            const response = await fetch(`/req/transcription/${id}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                }
            });
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.transcription) {
                    content = data.transcription.transcript_text;
                    console.log('✅ Transkript vom Server geladen');
                }
            }
        } catch (error) {
            console.warn('Fehler beim Laden vom Server, versuche localStorage:', error);
        }
    }
    
    // Falls nicht vom Server geladen: localStorage Fallback
    if (!content) {
        const history = JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
        const entry = history.find(e => e.id === id);
        if (entry) {
            content = entry.content;
            console.log('✅ Transkript aus localStorage geladen');
        }
    }
    
    if (!content) {
        alert('Transkription konnte nicht geladen werden');
        return;
    }

    // Verwende die separate History-Ausgabe (nicht die Inline-Version)
    document.getElementById('transcription-result').innerHTML = content;
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
