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
        if (exportActions) exportActions.style.display = 'block';

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

        this.app.ui.switchTranscriptView('transcript-export-ui');
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
            previewContent.innerHTML = `<pre style="white-space: pre-wrap; font-size: 13px; font-family: monospace; color: var(--text-color, #1a202c);">${srtContent}</pre>`;
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
            previewContent.innerHTML = `<pre style="white-space: pre-wrap; font-size: 13px; font-family: monospace; color: var(--text-color, #1a202c);">${txtContent}</pre>`;
        }
    }

    exportToErgebnis() {
        if (!this.app.state.currentTranscriptSegments || this.app.state.currentTranscriptSegments.length === 0) {
            console.warn("Keine Segmente vorhanden");
            return;
        }
        
        const previewContent = document.getElementById('export-preview-content');
        if (previewContent) {
            previewContent.innerHTML = `
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px; color: #64748B;">
                    <div class="loading-spinner" style="width: 40px; height: 40px; border: 3px solid #F1F5F9; border-top-color: #2F2ABF; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 20px;"></div>
                    <p style="font-weight: 500; margin-bottom: 8px;">Prüfe Daten...</p>
                </div>
            `;
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
            previewContent.innerHTML = `
                <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding: 40px 0; color: #64748b; gap: 15px;">
                    <div class="loader-spinner" style="width: 30px; height: 30px; border: 3px solid #f3f4f6; border-top: 3px solid var(--color-primary); border-radius: 50%; animation: spin 1s linear infinite;"></div>
                    <p style="font-size: 14px;">Ergebnisprotokoll wird generiert (KI)...</p>
                    <p style="font-size: 12px; opacity: 0.7; max-width: 300px; text-align: center;">Dies kann je nach Länge des Transkripts einen Moment dauern.</p>
                </div>
            `;
            const dBtn = document.getElementById('btn-download-export');
            if (dBtn) dBtn.disabled = true;
            return;
        }

        if (errorMsg) {
            previewContent.innerHTML = `
                <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; padding: 40px 0; color: #ef4444; gap: 10px;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                    <p style="font-size: 14px; font-weight: 500;">Generierung fehlgeschlagen</p>
                    <p style="font-size: 12px; color: #64748b;">${errorMsg}</p>
                    <button class="btn-sidebar-action" onclick="window.generateErgebnisprotokoll()" style="margin-top: 15px;">Erneut versuchen</button>
                </div>
            `;
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

            const htmlContent = window.marked && typeof window.marked.parse === 'function' ? window.marked.parse(markdownContent) : `<pre style="white-space: pre-wrap; font-size: 13px;">${markdownContent}</pre>`;
            previewContent.innerHTML = `<div class="markdown-prose" style="font-size: 14px; color: var(--text-color, #1a202c); line-height: 1.6;">${htmlContent}</div>`;
            
            const dBtn = document.getElementById('btn-download-export');
            if (dBtn) dBtn.disabled = false;
        } else {
            previewContent.innerHTML = `
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 60px; background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); width: 100%; max-width: 600px; margin: 0 auto; text-align: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width: 48px; height: 48px; color: #0EA5E9; margin-bottom: 16px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
                    </svg>
                    <h3 style="margin-bottom: 8px; color: #0F172A; font-size: 18px;">Ergebnisprotokoll generieren</h3>
                    <p style="color: #64748B; margin-bottom: 24px; font-size: 14px; width: 100%;">Erstelle eine KI-gestützte Zusammenfassung des aktuellen Transkripts. Dieser Vorgang dauert etwa 10-20 Sekunden.</p>
                    <button onclick="window.generateErgebnisprotokoll()" class="btn-primary-blue" style="padding: 10px 24px; font-weight: 500; font-size: 14px;">Jetzt generieren</button>
                </div>
            `;
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
