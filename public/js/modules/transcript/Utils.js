export class Utils {
    static escapeHTML(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    static formatSecondsToTime(seconds) {
        const hrs = String(Math.floor(seconds / 3600)).padStart(2, '0');
        const mins = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
        const secs = String(Math.floor(seconds % 60)).padStart(2, '0');
        return `${hrs}:${mins}:${secs}`;
    }

    static formatSecondsToSRT(seconds) {
        const date = new Date(0);
        date.setSeconds(seconds);
        const hours = date.getUTCHours().toString().padStart(2, '0');
        const minutes = date.getUTCMinutes().toString().padStart(2, '0');
        const secs = date.getUTCSeconds().toString().padStart(2, '0');
        const ms = Math.floor((seconds % 1) * 1000).toString().padStart(3, '0');
        return `${hours}:${minutes}:${secs},${ms}`;
    }

    static async fetchJsonWithRetry(url, options = {}, retries = 1, retryDelayMs = 250) {
        let lastError = null;
        options.headers = options.headers || {};
        if (!options.headers['Accept']) {
            options.headers['Accept'] = 'application/json';
        }

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

    static parseHistoryEntryDate(entry) {
        const raw = entry?.updated_at
            || entry?.created_at
            || entry?.updated_at_local
            || entry?.created_at_local
            || null;
        if (!raw) return null;
        const d = new Date(raw);
        return Number.isNaN(d.getTime()) ? null : d;
    }

    static getHistoryGroupKey(entryDate) {
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

    static getHistoryGroupLabel(groupKey) {
        if (groupKey === 'today') return 'Heute';
        if (groupKey === 'yesterday') return 'Gestern';
        if (groupKey === 'last7') return 'Letzte 7 Tage';
        return 'Vor längerer Zeit';
    }

    static normalizeSegments(rawSegments) {
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

    static normalizeTranscriptText(rawText) {
        return typeof rawText === 'string' ? rawText : '';
    }

    static getSegmentTextWithRedactions(segment, replacement = "[AUSGEBLENDET]") {
        if (!segment.redactions || segment.redactions.length === 0) {
            return (segment.text || "").trim();
        }
        const text = (segment.text || "").trim();
        let lastIdx = 0;
        let result = '';
        const sortedRedactions = [...segment.redactions].sort((a, b) => a.start - b.start);
        
        sortedRedactions.forEach(red => {
            result += text.substring(lastIdx, red.start);
            result += replacement;
            lastIdx = red.end;
        });
        result += text.substring(lastIdx);
        return result;
    }

    static getSubtitleBlocks(segments, anonymize = false) {
        const rawBlocks = [];
        
        let anonymizedSpeakerMap = new Map();
        if (anonymize) {
            let speakerIndex = 1;
            segments.forEach((segment) => {
                let speakerName = segment.speaker || 'Unbekannt';
                if (!anonymizedSpeakerMap.has(speakerName)) {
                    anonymizedSpeakerMap.set(speakerName, `Speaker ${speakerIndex}`);
                    speakerIndex++;
                }
            });
        }
        
        segments.forEach(segment => {
            let displaySpeaker = segment.speaker;
            if (displaySpeaker && displaySpeaker.startsWith('Unbekannt')) {
                displaySpeaker = null;
            }
            if (displaySpeaker && anonymize) {
                displaySpeaker = anonymizedSpeakerMap.get(displaySpeaker) || displaySpeaker;
            }

            const safeText = Utils.getSegmentTextWithRedactions(segment);
            const textLine = displaySpeaker ? `[${displaySpeaker}]: ${safeText.trim()}` : safeText.trim();
            if (!textLine) return;

            const lines = [];
            const words = textLine.split(/\s+/);
            let currentLine = "";

            for (const word of words) {
                if (!word) continue;
                if (currentLine.length === 0) {
                    currentLine = word;
                } else if (currentLine.length + 1 + word.length <= 42) {
                    currentLine += " " + word;
                } else {
                    lines.push(currentLine);
                    currentLine = word;
                }
            }
            if (currentLine) {
                lines.push(currentLine);
            }

            const segmentBlocks = [];
            for (let i = 0; i < lines.length; i += 2) {
                segmentBlocks.push(lines.slice(i, i + 2).join('\n'));
            }

            if (segmentBlocks.length === 0) return;

            const totalLength = segmentBlocks.reduce((sum, b) => sum + b.replace('\n', '').length, 0);
            const segmentDur = segment.end - segment.start;

            let accumulatedLength = 0;
            segmentBlocks.forEach(blockText => {
                const cleanText = blockText.replace('\n', '');
                const L_j = cleanText.length;
                
                const portionStart = segment.start + (totalLength > 0 ? segmentDur * (accumulatedLength / totalLength) : 0);
                const portionEnd = segment.start + (totalLength > 0 ? segmentDur * ((accumulatedLength + L_j) / totalLength) : segmentDur);
                
                const minDur = Math.max(1.0, L_j / 17);
                
                rawBlocks.push({
                    text: blockText,
                    desiredStart: portionStart,
                    desiredEnd: portionEnd,
                    minDur: minDur
                });

                accumulatedLength += L_j;
            });
        });

        const finalBlocks = [];
        let lastEnd = -0.5;

        rawBlocks.forEach(block => {
            const startLimit = lastEnd + 0.5;
            let start = Math.max(startLimit, block.desiredStart);
            
            let desiredDur = block.desiredEnd - start;
            let duration = Math.max(block.minDur, Math.min(7.0, desiredDur));
            let end = start + duration;

            finalBlocks.push({
                start: start,
                end: end,
                text: block.text
            });
            
            lastEnd = end;
        });

        return finalBlocks;
    }
}

