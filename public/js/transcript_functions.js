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
        document.getElementById('drop-text').style.display = 'none';
        document.getElementById('loading-spinner').style.display = 'block';
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
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('loading-spinner').style.display = 'none';
                document.getElementById('drop-text').style.display = 'block';
                document.body.classList.remove('cursor-wait');
                console.log('✅ Upload abgeschlossen:', JSON.stringify(data, null, 2));

                if (data.success && data.text) {
                    // Zeige die Transkription im DOM an
                    const outputDiv = document.getElementById('transcription-result');

                    // Alle Eingabeflächen ausblenden
                    document.getElementById('transcript-file-ui').style.display = 'none';

                    outputDiv.innerText = data.text;

                    document.getElementById('history-title').style.display = 'none';
                    document.querySelectorAll('.history-entry').forEach(e => e.style.display =
                        'none');

                    saveTranscriptToHistory(data.text);

                    // Blende den gesamten Container sichtbar ein
                    document.getElementById('drop-zone').style.display = 'none';
                    document.getElementById('selected-file-preview').style.display = 'none';
                    // Zeige Transkript und verstecke Drop-Zone + Dateinamen
                    document.getElementById('transcription-output').style.display = 'flex';
                    document.getElementById('transcript-file-ui').style.display = 'none';
                    document.getElementById('selected-file-preview').style.display = 'none';
                    // Zeige Kopier-Button

                    document.getElementById('copy-transcript-btn').onclick = function() {
                        const text = document.getElementById('transcription-result')
                            .innerText;
                        navigator.clipboard.writeText(text)
                            .then(() => alert(
                                'Transkription wurde in die Zwischenablage kopiert!'))
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
    document.getElementById('transcript-choice').style.display = 'none';
    document.getElementById('history-title').style.display = 'none';
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
    // Auswahl anzeigen
    document.getElementById('transcript-choice').style.display = 'block';
    document.getElementById('history-title').style.display = 'block';
    document.getElementById('history-title').style.display = 'block';
    document.querySelectorAll('.history-entry').forEach(e => e.style.display = 'block');

    // Alles andere ausblenden
    document.getElementById('transcript-file-ui').style.display = 'none';
    document.getElementById('transcript-live-ui').style.display = 'none';
    document.getElementById('file-transcription-options').style.display = 'none';
    document.getElementById('start-upload-wrapper').style.display = 'none';
    document.getElementById('speaker-recognition-wrapper').style.display = 'none';
    document.getElementById('back-button-wrapper').style.display = 'none';

    // Transkriptionsergebnis zurücksetzen
    const outputDiv = document.getElementById('transcription-output');
    const resultDiv = document.getElementById('transcription-result');
    const copyBtn = document.getElementById('copy-transcript-btn');

    if (outputDiv) outputDiv.style.display = 'none';
    if (resultDiv) resultDiv.innerText = '';
    if (copyBtn) copyBtn.style.display = 'none';

    // Drop-Zone sichtbar machen
    const dropZone = document.getElementById('drop-zone');
    if (dropZone) dropZone.style.display = 'flex'; // NICHT 'none' – wir wollen es wieder anzeigen!

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

    document.getElementById('transcription-result').innerText = entry.content;
    document.getElementById('transcription-output').style.display = 'flex';
    document.getElementById('copy-transcript-btn').style.display = 'block';
    document.getElementById('back-button-wrapper').style.display = 'block';

    // Alles andere ausblenden
    document.getElementById('transcript-choice').style.display = 'none';
    document.getElementById('transcript-file-ui').style.display = 'none';
    document.getElementById('transcript-live-ui').style.display = 'none';
    document.getElementById('file-transcription-options').style.display = 'none';
}
