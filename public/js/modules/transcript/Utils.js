export class Utils {
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
}
