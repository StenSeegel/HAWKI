import { Utils } from './Utils.js';

export class ExportManager {
    constructor(app) {
        this.app = app;
    }

    selectExportOption(option) {
        document.querySelectorAll('.sidebar-export-card').forEach(el => el.classList.remove('active'));
        const selectedCard = document.querySelector(`.sidebar-export-card[data-option="${option}"]`);
        if (selectedCard) selectedCard.classList.add('active');

        this.app.state.exportType = option;
        
        const exportActions = document.getElementById('export-actions');
        if (exportActions) exportActions.classList.remove('hidden');

        const subtitle = document.getElementById('export-preview-subtitle');

        if (option === 'srt') {
            if (subtitle) subtitle.textContent = "Untertitel (SRT) Vorschau";
            this.exportToSRT();
        } else if (option === 'verlauf') {
            if (subtitle) subtitle.textContent = "Verlaufsprotokoll (Tabellarisch) Vorschau";
            this.exportToVerlauf();
        } else if (option === 'ergebnis') {
            if (subtitle) subtitle.textContent = "Ergebnisprotokoll Vorschau";
            this.exportToErgebnis();
        }
    }

    exportToSRT() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) {
            console.warn("Keine Segmente für SRT Export vorhanden");
            return;
        }

        let srtContent = "";
        let index = 1;

        this.app.state.currentTranscriptSegments.forEach(segment => {
            const startStr = Utils.formatSecondsToSRT(segment.start);
            const endStr = Utils.formatSecondsToSRT(segment.end);
            
            let displaySpeaker = segment.speaker;
            if (displaySpeaker && displaySpeaker.startsWith('Unbekannt')) displaySpeaker = null;

            const safeText = Utils.getSegmentTextWithRedactions(segment);
            const textLine = displaySpeaker ? `[${displaySpeaker}]: ${safeText.trim()}` : safeText.trim();

            srtContent += `${index}\n`;
            srtContent += `${startStr} --> ${endStr}\n`;
            srtContent += `${textLine}\n\n`;
            index++;
        });

        this.app.state.exportData = {
            content: srtContent,
            type: 'text/plain;charset=utf-8',
            extension: 'srt'
        };

        const previewContent = document.getElementById('export-preview-content');
        if (previewContent) {
            previewContent.innerHTML = '';
            const pre = document.createElement('pre');
            pre.className = 'export-pre-preview';
            pre.textContent = srtContent;
            previewContent.appendChild(pre);
        }
    }

    exportToVerlauf() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) {
            console.warn("Keine Segmente für Verlaufsprotokoll vorhanden");
            return;
        }

        let txtContent = "";
        
        let speakerMap = new Map();
        let colorIndexCounter = 1;
        
        this.app.state.currentTranscriptSegments.forEach((segment) => {
            let speakerName = segment.speaker;
            if (!speakerName) {
                speakerName = `Unbekannt ${colorIndexCounter}`;
                colorIndexCounter++;
            }
            if (!speakerMap.has(speakerName)) {
                speakerMap.set(speakerName, true);
            }
        });
        
        const timestamp = new Date().toLocaleString();
        txtContent += `VERLAUFSPROTOKOLL\n`;
        txtContent += `Erstellt am: ${timestamp}\n`;
        if (this.app.state.currentTranscriptSlug) {
            txtContent += `Transkription-ID: ${this.app.state.currentTranscriptSlug}\n`;
        }
        
        txtContent += `\nTEILNEHMER:\n`;
        speakerMap.forEach((_, name) => {
            txtContent += `- ${name}\n`;
        });
        
        txtContent += `\n` + `=`.repeat(50) + `\n\n`;

        let currentSpeaker = null;
        let currentText = "";
        let blockStartTime = 0;
        
        this.app.state.currentTranscriptSegments.forEach((segment, index) => {
            let segSpeaker = segment.speaker || 'Unbekannt';
            let safeText = Utils.getSegmentTextWithRedactions(segment);

            if (index === 0 || segSpeaker !== currentSpeaker || (segment.start - this.app.state.currentTranscriptSegments[index-1].end) > 10) {
                if (currentSpeaker) {
                    txtContent += `[${Utils.formatSecondsToTime(blockStartTime)}] ${currentSpeaker}:\n${currentText.trim()}\n\n`;
                }
                currentSpeaker = segSpeaker;
                currentText = safeText + " ";
                blockStartTime = segment.start;
            } else {
                currentText += safeText + " ";
            }
        });

        if (currentSpeaker) {
            txtContent += `[${Utils.formatSecondsToTime(blockStartTime)}] ${currentSpeaker}:\n${currentText.trim()}\n`;
        }

        this.app.state.exportData = {
            content: txtContent,
            type: 'text/plain;charset=utf-8',
            extension: 'txt'
        };

        const previewContent = document.getElementById('export-preview-content');
        if (previewContent) {
            previewContent.innerHTML = '';
            const pre = document.createElement('pre');
            pre.className = 'export-pre-preview';
            pre.textContent = txtContent;
            previewContent.appendChild(pre);
        }
    }

    exportToErgebnis() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) {
            console.warn("Keine Segmente vorhanden");
            return;
        }
        
        const previewContent = document.getElementById('export-preview-content');
        if (previewContent) {
            previewContent.innerHTML = '';
            const tmpl = document.getElementById('tmpl-export-check-data');
            if (tmpl) previewContent.appendChild(tmpl.content.cloneNode(true));
        }

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        fetch('/req/transcription/summarize', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({ 
                check_only: true,
                transcription_slug: this.app.state.currentTranscriptSlug || null
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.summary) {
                this.renderErgebnisprotokoll(false, null, data.summary);
            } else {
                this.renderErgebnisprotokoll();
            }
        })
        .catch(err => {
            console.error("Fehler beim Prüfen:", err);
            this.renderErgebnisprotokoll();
        });
    }

    renderErgebnisprotokoll(loading = false, errorMsg = null, markdownContent = null) {
        const previewContent = document.getElementById('export-preview-content');
        if (!previewContent) return;
        
        if (loading) {
            previewContent.innerHTML = '';
            const tmpl = document.getElementById('tmpl-export-loading-summary');
            if (tmpl) previewContent.appendChild(tmpl.content.cloneNode(true));
            const dBtn = document.getElementById('btn-download-export');
            if (dBtn) dBtn.disabled = true;
            return;
        }

        if (errorMsg) {
            previewContent.innerHTML = '';
            const tmpl = document.getElementById('tmpl-export-error');
            if (tmpl) {
                const clone = tmpl.content.cloneNode(true);
                clone.querySelector('.error-msg').textContent = errorMsg;
                previewContent.appendChild(clone);
            }
            const dBtn = document.getElementById('btn-download-export');
            if (dBtn) dBtn.disabled = true;
            return;
        }
        
        if (markdownContent) {
            this.app.state.exportData = {
                content: markdownContent,
                type: 'text/markdown;charset=utf-8',
                extension: 'md'
            };

            let htmlContent = markdownContent;
            if (window.md && typeof window.md.render === 'function') {
                htmlContent = window.md.render(markdownContent);
            }

            previewContent.innerHTML = '';
            const wrapper = document.createElement('div');
            wrapper.className = 'markdown-prose export-markdown-preview';
            if (window.md && typeof window.md.render === 'function') {
                wrapper.innerHTML = htmlContent;
            } else {
                const pre = document.createElement('pre');
                pre.className = 'export-pre-preview';
                pre.textContent = htmlContent;
                wrapper.appendChild(pre);
            }
            previewContent.appendChild(wrapper);
            
            const dBtn = document.getElementById('btn-download-export');
            if (dBtn) dBtn.disabled = false;
        } else {
            previewContent.innerHTML = '';
            const tmpl = document.getElementById('tmpl-export-generate-prompt');
            if (tmpl) previewContent.appendChild(tmpl.content.cloneNode(true));
            const dBtn = document.getElementById('btn-download-export');
            if (dBtn) dBtn.disabled = true;
        }
    }

    generateErgebnisprotokoll() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) return;

        this.renderErgebnisprotokoll(true);

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        let textPayload = "";
        let currentSpeaker = null;
        let currentText = "";
        
        this.app.state.currentTranscriptSegments.forEach((segment, index) => {
            let segSpeaker = segment.speaker || 'Unbekannt';
            let safeText = Utils.getSegmentTextWithRedactions(segment);

            if (index === 0 || segSpeaker !== currentSpeaker || (segment.start - this.app.state.currentTranscriptSegments[index-1].end) > 10) {
                if (currentSpeaker) {
                    textPayload += `${currentSpeaker}: ${currentText.trim()}\n`;
                }
                currentSpeaker = segSpeaker;
                currentText = safeText + " ";
            } else {
                currentText += safeText + " ";
            }
        });
        
        if (currentSpeaker) {
            textPayload += `${currentSpeaker}: ${currentText.trim()}\n`;
        }

        const modelSelect = document.getElementById('ergebnis-model-select');
        const model = modelSelect ? modelSelect.value : null;

        fetch('/req/transcription/summarize', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                transcript_text: textPayload,
                ...(model && { model: model }),
                transcription_slug: this.app.state.currentTranscriptSlug
            })
        })
        .then(res => {
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.json();
        })
        .then(data => {
            if (data.success && data.summary) {
                this.renderErgebnisprotokoll(false, null, data.summary);
            } else {
                this.renderErgebnisprotokoll(false, data.message || "Unbekannter Serverfehler");
            }
        })
        .catch(err => {
            console.error("Generierung fehlgeschlagen:", err);
            this.renderErgebnisprotokoll(false, "Fehler bei der Kommunikation mit dem Server.");
        });
    }

    triggerExportDownload() {
        if (!this.app.state.exportData) return;
        
        const blob = new Blob([this.app.state.exportData.content], { type: this.app.state.exportData.type });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        const id = this.app.state.currentTranscriptSlug || 'export';
        a.download = `transkription-${id}.${this.app.state.exportData.extension}`;
        a.click();
        URL.revokeObjectURL(url);
    }
}
