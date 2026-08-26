import { Utils } from './Utils.js?v=1.0.6';

// Waveform rendering for the global player — same bar style as WaveformAudioPlayer.
// Peaks are resampled from this fixed-resolution buffer at draw time.
const WAVEFORM_PEAK_RESOLUTION = 400;

// decodeAudioData inflates the file into raw PCM — skip the real waveform
// above this size and keep the placeholder bars.
const WAVEFORM_DECODE_SIZE_LIMIT = 100 * 1024 * 1024;

// "job/slug:index" -> per-file peaks, so reopening a transcription doesn't re-download.
const waveformPeaksCache = new Map();

let sharedWaveformContext = null;

// Shortest selectable editor window in seconds.
const EDITOR_WINDOW_MIN_LENGTH = 0.2;

// The selection window always renders at this fraction of the track width,
// regardless of file length; pre/post sections share the rest proportionally.
const EDITOR_ZOOM_WIDTH = 0.1;

// Waveform peaks per second of audio. Time-based so the zoomed selection
// window still resolves real detail on long files.
const WAVEFORM_PEAKS_PER_SECOND = 20;

// Solid variants of the .player-segment speaker gradients (first gradient stop).
const SPEAKER_BAR_COLORS = {
    1: '#3b82f6', 2: '#a855f7', 3: '#f97316', 4: '#a3e635', 5: '#ec4899',
    6: '#fde047', 7: '#22d3ee', 8: '#6366f1', 9: '#14b8a6', 10: '#f43f5e'
};

export class CustomAudioPlayer {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container - DOM container to render into
     * @param {string} options.mode - 'global' | 'snippet' | 'editor'
     * @param {string} [options.slug] - Transcription slug
     * @param {Array} [options.audioSources] - Source files list
     * @param {Array} [options.segments] - Transcript segments list
     * @param {number} [options.start] - Start offset (for snippet/editor modes)
     * @param {number} [options.end] - End offset (for snippet/editor modes)
     * @param {number} [options.fileDuration] - Duration of the specific file (for editor mode)
     * @param {number} [options.maxWindowLength] - Longest selectable range in seconds (editor mode)
     * @param {string} [options.jobId] - Active Job ID (if known)
     * @param {number} [options.fileIndex] - File index in merged transcript
     * @param {Function} [options.onTimeUpdate] - Timeupdate callback
     * @param {Function} [options.onRangeChange] - Range update callback for Editor mode
     * @param {Function} [options.onSegmentClick] - Segment click callback for Global mode
     */
    constructor(options) {
        this.container = options.container;
        this.mode = options.mode; // 'global' | 'snippet' | 'editor'
        this.slug = options.slug || null;
        this.audioSources = options.audioSources || [];
        this.segments = options.segments || [];
        this.onTimeUpdate = options.onTimeUpdate || null;
        this.onRangeChange = options.onRangeChange || null;
        this.onSegmentClick = options.onSegmentClick || null;
        this.onPlay = options.onPlay || null;
        this.onPause = options.onPause || null;
        this.playRange = null; // { start, end }
        this.temporaryTime = null; // target seek time during loading
        this.directUrl = options.directUrl || null;
        this.speakerId = options.speakerId || null;

        // Snippet / Editor bounds
        this.start = options.start !== undefined ? options.start : 0;
        this.end = options.end !== undefined ? options.end : 0;
        this.fileDuration = options.fileDuration || 0;
        this.jobId = options.jobId || null;
        this.fileIndex = options.fileIndex !== undefined ? options.fileIndex : 0;

        // Editor bounds (linear scale over the whole file)
        if (this.mode === 'editor') {
            this.currentStart = this.start;
            this.currentEnd = this.end;
            this.minTime = 0;
            this.maxTime = this.fileDuration || (this.end + 10);
            // Longest allowed window (snippet-length setting); the initial
            // range is the fallback when the caller doesn't pass it.
            this.maxWindowLength = options.maxWindowLength
                || Math.max(EDITOR_WINDOW_MIN_LENGTH, this.end - this.start);
            this.frozenMetrics = null; // set while dragging so the zoom doesn't remap under the cursor
        }

        // Shared native audio element
        this.audio = null;
        this.isLoaded = false;
        this.isPlaying = false;
        this.currentSourceIndex = -1;

        // DOM elements
        this.playerEl = null;
        this.playBtn = null;
        this.timelineTrack = null;
        this.playhead = null;
        this.tooltip = null;
        this.progressLine = null;
        this.dotPlayhead = null;
        this.selectionRange = null;
        this.handleLeft = null;
        this.handleRight = null;
        this.startInput = null;
        this.endInput = null;

        // Drag state
        this.isDraggingLeft = false;
        this.isDraggingRight = false;
        this.isDraggingPlayhead = false;
        this.wasDragging = false;

        // Waveform state (global mode)
        this.waveformCanvas = null;
        this.waveformPeaks = null;
        this.waveformResizeObserver = null;
        
        this.init();
    }

    init() {
        this.container.innerHTML = '';
        this.playerEl = document.createElement('div');
        this.playerEl.className = `custom-audio-player mode-${this.mode}`;

        // Create inner structure
        if (this.mode === 'global') {
            this.createGlobalPlayerHTML();
        } else if (this.mode === 'snippet') {
            this.createSnippetPlayerHTML();
        } else if (this.mode === 'editor') {
            this.createEditorPlayerHTML();
        }

        this.container.appendChild(this.playerEl);

        // Create or find global audio elements
        this.audio = document.createElement('audio');
        this.playerEl.appendChild(this.audio);

        this.audio.addEventListener('loadedmetadata', () => {
            if (!this.fileDuration && Number.isFinite(this.audio.duration) && this.audio.duration > 0) {
                this.fileDuration = this.audio.duration;
                if (this.mode === 'editor') {
                    this.maxTime = this.fileDuration;
                    // Pull the window back in if it slid past the real file end
                    // while the duration was still unknown.
                    if (this.currentEnd > this.fileDuration) {
                        this.slideWindowTo(this.currentStart);
                    }
                    this.updatePlayerVisuals();
                }
            }
        });

        this.bindEvents();
        this.updatePlayerVisuals();

        if (this.mode === 'global' || this.mode === 'editor') {
            this.waveformResizeObserver = new ResizeObserver(() => this.drawWaveform());
            this.waveformResizeObserver.observe(this.timelineTrack);
            this.computeWaveformPeaks();
        }

        // Without a known duration the slide clamp and the linear scale have
        // no real right bound — load metadata up front instead of on first play.
        if (this.mode === 'editor' && !this.fileDuration) {
            this.audio.preload = 'metadata';
            this.loadAudioForTime(this.start);
        }
    }

    createGlobalPlayerHTML() {
        this.playerEl.innerHTML = `
            <button type="button" class="player-play-btn" title="Abspielen / Pause">
                <svg class="icon-play lucide lucide-play-icon lucide-play" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/>
                </svg>
                <svg class="icon-pause hidden lucide lucide-pause" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="14" y="4" width="4" height="16" rx="1"></rect>
                    <rect x="6" y="4" width="4" height="16" rx="1"></rect>
                </svg>
            </button>
            <div class="player-timeline-wrapper">
                <div class="player-timeline-track">
                    <canvas class="player-waveform-canvas"></canvas>
                    <div class="player-playhead">
                        <div class="player-tooltip">00:00</div>
                    </div>
                </div>
                <span class="player-total-duration">00:00</span>
            </div>
        `;

        this.playBtn = this.playerEl.querySelector('.player-play-btn');
        this.timelineTrack = this.playerEl.querySelector('.player-timeline-track');
        this.playhead = this.playerEl.querySelector('.player-playhead');
        this.tooltip = this.playerEl.querySelector('.player-tooltip');
        this.waveformCanvas = this.playerEl.querySelector('.player-waveform-canvas');

        this.renderGlobalSegments();
    }

    createSnippetPlayerHTML() {
        this.playerEl.innerHTML = `
            <button type="button" class="player-play-btn" title="Abspielen / Pause">
                <svg class="icon-play lucide lucide-play-icon lucide-play" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/>
                </svg>
                <svg class="icon-pause hidden lucide lucide-pause" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="14" y="4" width="4" height="16" rx="1"></rect>
                    <rect x="6" y="4" width="4" height="16" rx="1"></rect>
                </svg>
            </button>
            <div class="player-timeline-wrapper">
                <div class="player-timeline-track">
                    <div class="player-progress-line" style="width: 0%;"></div>
                    <div class="player-dot-playhead" style="left: 0%;"></div>
                </div>
                <div class="player-time-labels">
                    <span class="label-start">${this.formatTime(this.start)}</span>
                    <span class="label-end">${this.formatTime(this.end)}</span>
                </div>
            </div>
        `;

        this.playBtn = this.playerEl.querySelector('.player-play-btn');
        this.timelineTrack = this.playerEl.querySelector('.player-timeline-track');
        this.progressLine = this.playerEl.querySelector('.player-progress-line');
        this.dotPlayhead = this.playerEl.querySelector('.player-dot-playhead');
    }

    createEditorPlayerHTML() {
        this.playerEl.innerHTML = `
            <button type="button" class="player-play-btn" title="Abspielen / Pause">
                <svg class="icon-play lucide lucide-play-icon lucide-play" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/>
                </svg>
                <svg class="icon-pause hidden lucide lucide-pause" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="14" y="4" width="4" height="16" rx="1"></rect>
                    <rect x="6" y="4" width="4" height="16" rx="1"></rect>
                </svg>
            </button>
            <div class="player-timeline-wrapper">
                <div class="player-timeline-track">
                    <canvas class="player-waveform-canvas"></canvas>
                    <div class="player-selection-range">
                        <div class="drag-handle handle-left"></div>
                        <div class="drag-handle handle-right"></div>
                    </div>
                    <div class="player-playhead-line" style="left: 0%;"></div>
                </div>
                <div class="player-editor-inputs">
                    <div class="editor-input-box">
                        <span>Start:</span>
                        <input type="text" class="input-start-time speaker-time-input speaker-start-input" data-speaker-id="${this.speakerId || ''}" value="${this.formatTime(this.currentStart)}">
                    </div>
                    <div class="editor-input-box">
                        <span>End:</span>
                        <input type="text" class="input-end-time speaker-time-input speaker-end-input" data-speaker-id="${this.speakerId || ''}" value="${this.formatTime(this.currentEnd)}">
                    </div>
                </div>
            </div>
        `;

        this.playBtn = this.playerEl.querySelector('.player-play-btn');
        this.timelineTrack = this.playerEl.querySelector('.player-timeline-track');
        this.selectionRange = this.playerEl.querySelector('.player-selection-range');
        this.handleLeft = this.playerEl.querySelector('.handle-left');
        this.handleRight = this.playerEl.querySelector('.handle-right');
        this.startInput = this.playerEl.querySelector('.input-start-time');
        this.endInput = this.playerEl.querySelector('.input-end-time');
        this.playheadLine = this.playerEl.querySelector('.player-playhead-line');
        this.waveformCanvas = this.playerEl.querySelector('.player-waveform-canvas');
    }

    renderGlobalSegments() {
        if (this.mode !== 'global' || !this.playerEl) return;

        // Update total duration display
        const totalDurationEl = this.playerEl.querySelector('.player-total-duration');
        if (totalDurationEl) {
            totalDurationEl.textContent = this.formatTime(this.getTotalDuration());
        }

        // Speaker info lives in the waveform bar colors now.
        this.drawWaveform();
    }

    // Downloads and decodes the audio source(s) into waveform peaks. Global
    // mode assembles per-file peaks onto the merged timeline; editor mode uses
    // its single file directly. Any failure (CORS, oversize, codec) just
    // leaves the placeholder bars in place.
    async computeWaveformPeaks() {
        if (this.mode === 'editor') {
            let result = null;
            if (this.directUrl) {
                result = await this.decodePeaksFromUrl(this.directUrl, this.directUrl);
            } else if (this.jobId || this.slug) {
                result = await this.getSourcePeaks({ job_id: this.jobId }, this.fileIndex);
            }
            if (!result) return;

            // The decoded buffer knows the exact file length — adopt it when
            // the player was created without one, so the slide clamp and the
            // linear scale get their real right bound.
            if (!this.fileDuration && result.duration) {
                this.fileDuration = result.duration;
                this.maxTime = result.duration;
                if (this.currentEnd > this.fileDuration) {
                    this.slideWindowTo(this.currentStart);
                }
            }

            const overallMax = Math.max(...result.peaks, 0.01);
            this.waveformPeaks = result.peaks.map(peak => peak / overallMax);
            this.drawWaveform();
            return;
        }

        const totalDuration = this.getTotalDuration();
        if (totalDuration <= 0 || this.audioSources.length === 0) return;

        const sourceResults = await Promise.all(
            this.audioSources.map((src, idx) => this.getSourcePeaks(src, idx))
        );
        if (sourceResults.every(result => !result)) return;

        const peaks = new Array(WAVEFORM_PEAK_RESOLUTION).fill(0);
        for (let i = 0; i < WAVEFORM_PEAK_RESOLUTION; i++) {
            const time = ((i + 0.5) / WAVEFORM_PEAK_RESOLUTION) * totalDuration;
            const srcIdx = this.audioSources.findIndex(s => time >= s.start_time && time < s.end_time);
            if (srcIdx === -1) continue;

            const src = this.audioSources[srcIdx];
            const filePeaks = sourceResults[srcIdx] ? sourceResults[srcIdx].peaks : null;
            const srcDuration = src.end_time - src.start_time;
            if (!filePeaks || srcDuration <= 0) continue;

            const frac = (time - src.start_time) / srcDuration;
            peaks[i] = filePeaks[Math.min(filePeaks.length - 1, Math.floor(frac * filePeaks.length))] || 0;
        }

        const overallMax = Math.max(...peaks, 0.01);
        this.waveformPeaks = peaks.map(peak => peak / overallMax);
        this.drawWaveform();
    }

    async getSourcePeaks(src, index) {
        const cacheKey = `${src.job_id || this.slug || 'direct'}:${index}`;
        if (waveformPeaksCache.has(cacheKey)) {
            return waveformPeaksCache.get(cacheKey);
        }

        try {
            const queryParams = new URLSearchParams();
            if (src.job_id) {
                queryParams.set('job_id', src.job_id);
            } else if (this.slug) {
                queryParams.set('slug', this.slug);
                queryParams.set('index', String(index));
            }

            const res = await fetch(`/req/transcription/audio?${queryParams.toString()}`);
            const data = await res.json();
            if (!data.success || !data.url) return null;

            return await this.decodePeaksFromUrl(data.url, cacheKey);
        } catch (e) {
            console.warn('Waveform decoding failed for source', index, e);
            return null;
        }
    }

    async decodePeaksFromUrl(url, cacheKey) {
        if (waveformPeaksCache.has(cacheKey)) {
            return waveformPeaksCache.get(cacheKey);
        }

        try {
            const audioRes = await fetch(url);
            if (!audioRes.ok) return null;
            const size = Number(audioRes.headers.get('content-length'));
            if (size && size > WAVEFORM_DECODE_SIZE_LIMIT) return null;
            const arrayBuffer = await audioRes.arrayBuffer();

            // Low sample rate context: peaks don't need fidelity, and hour-long
            // recordings would otherwise decode to hundreds of MB of PCM.
            let audioBuffer;
            try {
                const offlineCtx = new OfflineAudioContext(1, 1, 8000);
                audioBuffer = await offlineCtx.decodeAudioData(arrayBuffer.slice(0));
            } catch (e) {
                if (!sharedWaveformContext) {
                    sharedWaveformContext = new (window.AudioContext || window.webkitAudioContext)();
                }
                audioBuffer = await sharedWaveformContext.decodeAudioData(arrayBuffer);
            }

            const channel = audioBuffer.getChannelData(0);
            // Time-based resolution so the zoomed editor selection still has
            // real detail on long files; clamped for very short/long ones.
            const resolution = Math.max(200, Math.min(100000,
                Math.ceil((audioBuffer.duration || 10) * WAVEFORM_PEAKS_PER_SECOND)));
            const bucketSize = Math.max(1, Math.floor(channel.length / resolution));
            const peaks = new Array(resolution).fill(0);

            for (let i = 0; i < resolution; i++) {
                const start = i * bucketSize;
                const end = Math.min(start + bucketSize, channel.length);
                let max = 0;
                // Sample within the bucket — full scan is unnecessary for a preview.
                const step = Math.max(1, Math.floor((end - start) / 64));
                for (let j = start; j < end; j += step) {
                    const value = Math.abs(channel[j]);
                    if (value > max) max = value;
                }
                peaks[i] = max;
            }

            const result = { peaks, duration: audioBuffer.duration };
            waveformPeaksCache.set(cacheKey, result);
            return result;
        } catch (e) {
            console.warn('Waveform decoding failed:', e);
            return null;
        }
    }

    drawWaveform() {
        if ((this.mode !== 'global' && this.mode !== 'editor') || !this.waveformCanvas || !this.timelineTrack) return;

        const width = this.timelineTrack.clientWidth;
        const height = this.timelineTrack.clientHeight;
        if (width === 0 || height === 0) return;

        const dpr = window.devicePixelRatio || 1;
        this.waveformCanvas.width = width * dpr;
        this.waveformCanvas.height = height * dpr;

        const ctx = this.waveformCanvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);

        const styles = getComputedStyle(this.playerEl);
        const playedColor = styles.getPropertyValue('--waveform-played').trim() || '#2F2ABF';
        const unplayedColor = styles.getPropertyValue('--waveform-unplayed').trim() || '#cbd5e1';

        const totalDuration = this.getTotalDuration();
        const progress = totalDuration > 0 ? this.getGlobalTime() / totalDuration : 0;
        const progressX = Math.max(0, Math.min(1, progress)) * width;

        const peaks = this.waveformPeaks || new Array(WAVEFORM_PEAK_RESOLUTION).fill(0.35);
        const barWidth = 3;
        const gap = 3;
        const barCount = Math.max(1, Math.floor(width / (barWidth + gap)));
        const centerY = height / 2;
        const maxBarHeight = height - 2;
        const minBarHeight = 2;

        if (this.mode === 'editor') {
            // Selection membership is shown by color; the DOM playhead line
            // and drag handles stay on top of the canvas. Each bar covers a
            // time range on the zoomed scale (milliseconds inside the
            // selection, possibly minutes in the compressed sections), so the
            // peak is the max over that range rather than a single sample.
            const editorDuration = this.getEditorDuration();
            for (let i = 0; i < barCount; i++) {
                const x = i * (barWidth + gap);
                const time = this.percentageToTime(((x + barWidth / 2) / width) * 100);

                let peak;
                if (this.waveformPeaks && editorDuration > 0) {
                    const t0 = this.percentageToTime((x / width) * 100);
                    const t1 = this.percentageToTime(((x + barWidth + gap) / width) * 100);
                    const i0 = Math.max(0, Math.min(peaks.length - 1, Math.floor((t0 / editorDuration) * peaks.length)));
                    const i1 = Math.max(i0, Math.min(peaks.length - 1, Math.ceil((t1 / editorDuration) * peaks.length) - 1));
                    peak = 0;
                    for (let j = i0; j <= i1; j++) {
                        if (peaks[j] > peak) peak = peaks[j];
                    }
                } else {
                    peak = peaks[Math.floor((i / barCount) * peaks.length)] || 0;
                }

                const barHeight = Math.max(minBarHeight, peak * maxBarHeight);

                ctx.fillStyle = (time >= this.currentStart && time <= this.currentEnd)
                    ? playedColor
                    : unplayedColor;
                ctx.beginPath();
                ctx.roundRect(x, centerY - barHeight / 2, barWidth, barHeight, barWidth / 2);
                ctx.fill();
            }
            return;
        }

        for (let i = 0; i < barCount; i++) {
            const peak = peaks[Math.floor((i / barCount) * peaks.length)] || 0;
            const barHeight = Math.max(minBarHeight, peak * maxBarHeight);
            const x = i * (barWidth + gap);
            const played = (x + barWidth / 2) <= progressX;

            // Bars carry the speaker color of the segment they fall into;
            // playback progress is shown by dimming the unplayed part.
            const barTime = totalDuration > 0 ? ((x + barWidth / 2) / width) * totalDuration : 0;
            const segment = this.segments.find(s => barTime >= s.start && barTime < s.end);

            if (segment) {
                ctx.fillStyle = SPEAKER_BAR_COLORS[segment.colorId] || playedColor;
                ctx.globalAlpha = played ? 1 : 0.35;
            } else {
                ctx.fillStyle = played ? playedColor : unplayedColor;
                ctx.globalAlpha = 1;
            }

            ctx.beginPath();
            ctx.roundRect(x, centerY - barHeight / 2, barWidth, barHeight, barWidth / 2);
            ctx.fill();
        }
        ctx.globalAlpha = 1;

        // Thin playhead line, matching the other waveform players.
        if (progressX > 0) {
            ctx.fillStyle = playedColor;
            ctx.fillRect(progressX - 0.5, 0, 1, height);
        }
    }

    getTotalDuration() {
        if (this.mode === 'global') {
            if (this.audioSources.length > 0) {
                return this.audioSources[this.audioSources.length - 1].end_time;
            }
            if (this.segments.length > 0) {
                return this.segments[this.segments.length - 1].end;
            }
            return 0;
        } else if (this.mode === 'editor') {
            return this.maxTime - this.minTime;
        } else {
            return this.end - this.start;
        }
    }

    bindEvents() {
        // Play / Pause toggle
        this.playBtn.addEventListener('click', () => this.toggle());

        // Audio element listeners
        this.audio.addEventListener('timeupdate', () => {
            if (this.temporaryTime !== null && this.temporaryTime !== undefined) {
                const relTime = this.toRelativeTime(this.temporaryTime);
                if (Math.abs(this.audio.currentTime - relTime) < 0.5) {
                    this.temporaryTime = null;
                }
            }
            this.handleTimeUpdate();
        });
        this.audio.addEventListener('play', () => this.setPlayState(true));
        this.audio.addEventListener('pause', () => this.setPlayState(false));
        this.audio.addEventListener('ended', () => this.handleAudioEnded());

        // Drag-seeking on timeline track
        this.timelineTrack.addEventListener('mousedown', (e) => {
            if (e.target.classList.contains('drag-handle')) return;
            e.preventDefault();

            this.isDraggingPlayhead = true;
            const startX = e.clientX;
            let didDrag = false;

            // Editor: a drag that starts inside the selection window moves
            // the window as a whole (no resize) instead of scrubbing.
            const downTime = this.clientXToTime(e.clientX);
            const isWindowDrag = this.mode === 'editor'
                && downTime >= this.currentStart && downTime <= this.currentEnd;
            const windowStartAtDown = this.currentStart;

            if (isWindowDrag) {
                // Same freeze as handle drags: keep the zoom scale stable
                // under the cursor while the window moves.
                this.frozenMetrics = this.getZoomMetrics();
            }

            // Global / snippet: seeking starts right on mousedown.
            // Editor: a plain click toggles play/pause instead, so dragging
            // only kicks in once the pointer actually moves.
            if (this.mode !== 'editor') {
                this.seek(this.clientXToTime(e.clientX));
            }

            const onMouseMove = (moveEvent) => {
                if (!this.isDraggingPlayhead) return;
                if (this.mode === 'editor' && !didDrag && Math.abs(moveEvent.clientX - startX) < 3) return;
                didDrag = true;

                if (isWindowDrag) {
                    const delta = this.clientXToTime(moveEvent.clientX) - downTime;
                    this.slideWindowTo(windowStartAtDown + delta);
                } else {
                    this.seek(this.clientXToTime(moveEvent.clientX));
                }
            };

            const onMouseUp = (upEvent) => {
                this.isDraggingPlayhead = false;
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);

                if (this.mode !== 'editor') return;

                if (isWindowDrag) {
                    // Unfreeze and re-zoom onto the moved window.
                    this.frozenMetrics = null;
                    if (didDrag) this.updatePlayerVisuals();
                }

                // Click without drag on the editor waveform: play from the
                // clicked position, or pause if already playing. The play
                // button remains the way to audition the selected window.
                if (!didDrag) {
                    if (this.isPlaying) {
                        this.pause();
                    } else {
                        this.seek(this.clientXToTime(upEvent.clientX)).then(() => this.play());
                    }
                }
            };

            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });

        // Speaker info on hover — replaces the title attribute the segment blocks had.
        if (this.mode === 'global') {
            this.timelineTrack.addEventListener('mousemove', (e) => {
                const totalDuration = this.getTotalDuration();
                if (totalDuration <= 0) return;
                const rect = this.timelineTrack.getBoundingClientRect();
                const time = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width)) * totalDuration;
                const seg = this.segments.find(s => time >= s.start && time < s.end);
                this.timelineTrack.title = seg
                    ? `${seg.speaker || 'Sprecher'}: ${this.formatTime(seg.start)} - ${this.formatTime(seg.end)}`
                    : '';
            });
        }

        // Editor mode specific handles & inputs.
        // Handles resize the window up to the maximum snippet length; at the
        // maximum, continued dragging slides the whole window (see moveEdge).
        if (this.mode === 'editor') {
            // Cursor affordance: grab over the movable window, pointer elsewhere.
            this.timelineTrack.addEventListener('mousemove', (e) => {
                if (this.isDraggingPlayhead || this.isDraggingLeft || this.isDraggingRight) return;
                if (e.target.classList.contains('drag-handle')) return;
                const time = this.clientXToTime(e.clientX);
                this.timelineTrack.style.cursor =
                    (time >= this.currentStart && time <= this.currentEnd) ? 'grab' : 'pointer';
            });

            const startHandleDrag = () => {
                // Freeze the zoom scale so the track doesn't remap under the
                // cursor while the edge moves; released on mouseup.
                this.frozenMetrics = this.getZoomMetrics();
                document.addEventListener('mousemove', this.dragHandler);
                document.addEventListener('mouseup', this.dragEndHandler);
            };

            this.handleLeft.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this.isDraggingLeft = true;
                startHandleDrag();
            });

            this.handleRight.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this.isDraggingRight = true;
                startHandleDrag();
            });

            this.startInput.addEventListener('change', () => {
                const newStart = this.parseTime(this.startInput.value);
                if (!isNaN(newStart)) {
                    this.moveEdge('start', newStart);
                } else {
                    this.startInput.value = this.formatTime(this.currentStart);
                }
            });

            this.endInput.addEventListener('change', () => {
                const newEnd = this.parseTime(this.endInput.value);
                if (!isNaN(newEnd)) {
                    this.moveEdge('end', newEnd);
                } else {
                    this.endInput.value = this.formatTime(this.currentEnd);
                }
            });

            // Handlers
            this.dragHandler = (e) => this.handleDrag(e);
            this.dragEndHandler = () => {
                this.isDraggingLeft = false;
                this.isDraggingRight = false;
                // Unfreeze and re-zoom onto the new selection window.
                this.frozenMetrics = null;
                this.updatePlayerVisuals();
                document.removeEventListener('mousemove', this.dragHandler);
                document.removeEventListener('mouseup', this.dragEndHandler);
            };
        }
    }

    clientXToTime(clientX) {
        const rect = this.timelineTrack.getBoundingClientRect();
        const percentage = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));

        if (this.mode === 'global') {
            return percentage * this.getTotalDuration();
        }
        if (this.mode === 'snippet') {
            return this.start + percentage * (this.end - this.start);
        }
        return this.percentageToTime(percentage * 100);
    }

    handleDrag(e) {
        if (!this.isDraggingLeft && !this.isDraggingRight) return;
        this.moveEdge(this.isDraggingLeft ? 'start' : 'end', this.clientXToTime(e.clientX));
    }

    // Moving an edge resizes the window between the minimum and the maximum
    // snippet length; pulling further once the maximum is reached pushes the
    // whole window along (sliding-window behavior).
    moveEdge(edge, targetTime) {
        const maxLength = this.maxWindowLength;
        const fileLimit = this.getEditorDuration();

        let newStart = this.currentStart;
        let newEnd = this.currentEnd;

        if (edge === 'start') {
            newStart = Math.max(0, Math.min(targetTime, newEnd - EDITOR_WINDOW_MIN_LENGTH));
            if (newEnd - newStart > maxLength) {
                newEnd = Math.min(fileLimit, newStart + maxLength);
            }
        } else {
            newEnd = Math.min(fileLimit, Math.max(targetTime, newStart + EDITOR_WINDOW_MIN_LENGTH));
            if (newEnd - newStart > maxLength) {
                newStart = Math.max(0, newEnd - maxLength);
            }
        }

        this.applyWindow(newStart, newEnd);
    }

    // Moves the window so it starts at newStart without changing its length,
    // clamped to the file bounds. Used by programmatic re-clamps (duration
    // arriving late); interactive edits go through moveEdge.
    slideWindowTo(newStart) {
        const duration = this.currentEnd - this.currentStart;
        // Same duration source as the visual scale, so the window can reach
        // exactly as far as the track shows — no artificial right boundary
        // while the real file length is still loading.
        const fileLimit = this.getEditorDuration();
        const clampedStart = Math.max(0, Math.min(Math.max(0, fileLimit - duration), newStart));
        this.applyWindow(clampedStart, clampedStart + duration);
    }

    applyWindow(newStart, newEnd) {
        this.currentStart = Math.round(newStart * 100) / 100;
        this.currentEnd = Math.round(newEnd * 100) / 100;

        if (this.currentEnd > this.maxTime) {
            this.maxTime = Math.min(this.getEditorDuration(), this.currentEnd);
        }

        this.startInput.value = this.formatTime(this.currentStart);
        this.endInput.value = this.formatTime(this.currentEnd);

        this.updatePlayerVisuals();
        if (this.onRangeChange) {
            this.onRangeChange(this.currentStart, this.currentEnd);
        }
    }

    updatePlayerVisuals() {
        const currentGlobalTime = this.getGlobalTime();

        if (this.mode === 'editor') {
            const leftPct = this.timeToPercentage(this.currentStart);
            const rightPct = this.timeToPercentage(this.currentEnd);
            const playheadPct = this.timeToPercentage(currentGlobalTime);

            this.selectionRange.style.left = `${leftPct}%`;
            this.selectionRange.style.width = `${rightPct - leftPct}%`;
            this.playheadLine.style.left = `${Math.max(0, Math.min(100, playheadPct))}%`;
            this.drawWaveform();

        } else if (this.mode === 'snippet') {
            const duration = this.end - this.start;
            const played = currentGlobalTime - this.start;
            const percentage = duration > 0 ? Math.max(0, Math.min(100, (played / duration) * 100)) : 0;

            this.progressLine.style.width = `${percentage}%`;
            this.dotPlayhead.style.left = `${percentage}%`;

        } else if (this.mode === 'global') {
            const totalDuration = this.getTotalDuration();
            const percentage = totalDuration > 0 ? (currentGlobalTime / totalDuration) * 100 : 0;

            this.playhead.style.left = `${Math.max(0, Math.min(100, percentage))}%`;
            this.tooltip.textContent = this.formatTime(currentGlobalTime);
            this.drawWaveform();
        }
    }

    async loadAudioForTime(globalTime) {
        if (this.directUrl) {
            if (!this.isLoaded) {
                this.audio.src = this.directUrl;
                this.audio.load();
                this.isLoaded = true;
            }
            return;
        }

        if (this.mode === 'global') {
            // Find which audio source covers this time
            let targetSrcIdx = 0;
            for (let i = 0; i < this.audioSources.length; i++) {
                const src = this.audioSources[i];
                if (globalTime >= src.start_time && globalTime < src.end_time) {
                    targetSrcIdx = i;
                    break;
                }
            }

            if (targetSrcIdx !== this.currentSourceIndex) {
                this.isLoaded = false;
                this.currentSourceIndex = targetSrcIdx;
                const src = this.audioSources[targetSrcIdx];

                // Fetch presigned URL from backend
                const queryParams = new URLSearchParams();
                if (src.job_id) {
                    queryParams.set('job_id', src.job_id);
                } else if (this.slug) {
                    queryParams.set('slug', this.slug);
                    queryParams.set('index', String(targetSrcIdx));
                }

                try {
                    const res = await fetch(`/req/transcription/audio?${queryParams.toString()}`);
                    const data = await res.json();
                    if (data.success && data.url) {
                        this.audio.src = data.url;
                        this.audio.load();
                        this.isLoaded = true;
                    }
                } catch (e) {
                    console.error("Failed to load global audio source index:", targetSrcIdx, e);
                }
            }
        } else {
            // Snippet / Editor modes use single file
            if (!this.isLoaded) {
                const queryParams = new URLSearchParams();
                if (this.jobId) {
                    queryParams.set('job_id', this.jobId);
                } else if (this.slug) {
                    queryParams.set('slug', this.slug);
                    queryParams.set('index', String(this.fileIndex));
                }

                try {
                    const res = await fetch(`/req/transcription/audio?${queryParams.toString()}`);
                    const data = await res.json();
                    if (data.success && data.url) {
                        this.audio.src = data.url;
                        this.audio.load();
                        this.isLoaded = true;
                    }
                } catch (e) {
                    console.error("Failed to load audio source for snippet:", e);
                }
            }
        }
    }

    async play() {
        const globalTime = this.getGlobalTime();

        if (window.app?.state?.editModeActive && this.segments && this.segments.length > 0) {
            const seg = this.segments.find(s => globalTime >= s.start && globalTime <= s.end) || 
                        this.segments.find(s => globalTime >= s.start - 0.1 && globalTime <= s.end + 0.1);
            if (seg) {
                this.playRange = { start: seg.start, end: seg.end };
            } else {
                this.playRange = null;
            }
        } else if (!window.app?.state?.editModeActive) {
            this.playRange = null;
        }

        await this.loadAudioForTime(globalTime);

        if (this.isLoaded) {
            const relativeTime = this.toRelativeTime(globalTime);
            
            // Sync current time of native element
            if (Math.abs(this.audio.currentTime - relativeTime) > 0.5) {
                this.audio.currentTime = relativeTime;
            }

            this.audio.play().catch(e => console.log('Audio playback failed', e));
        }
    }

    pause() {
        this.audio.pause();
    }

    toggle() {
        if (this.isPlaying) {
            this.pause();
        } else if (this.mode === 'editor') {
            // The play button always auditions the selected range from its
            // start — scrub-seeking elsewhere still resumes in place because
            // it calls play() directly.
            this.playFromSelection();
        } else {
            this.play();
        }
    }

    async playFromSelection() {
        await this.seek(this.currentStart);
        await this.play();
    }

    async seek(globalTime) {
        const wasPlaying = this.isPlaying;
        if (wasPlaying) {
            this.pause();
        }

        this.temporaryTime = globalTime;
        this.lastGlobalTime = globalTime; // Reset last time to prevent accidental auto-pause
        this.updatePlayerVisuals();

        if (window.app?.state?.editModeActive && this.segments && this.segments.length > 0) {
            const seg = this.segments.find(s => globalTime >= s.start && globalTime <= s.end) || 
                        this.segments.find(s => globalTime >= s.start - 0.1 && globalTime <= s.end + 0.1);
            if (seg) {
                this.playRange = { start: seg.start, end: seg.end };
            } else {
                this.playRange = null;
            }
        } else if (!window.app?.state?.editModeActive) {
            this.playRange = null;
        }

        // Bound validation
        if (this.mode === 'snippet') {
            globalTime = Math.max(this.start, Math.min(this.end, globalTime));
        } else if (this.mode === 'editor') {
            globalTime = Math.max(this.minTime, Math.min(this.maxTime, globalTime));
        } else if (this.mode === 'global') {
            const total = this.getTotalDuration();
            globalTime = Math.max(0, Math.min(total, globalTime));
        }

        await this.loadAudioForTime(globalTime);

        if (this.isLoaded) {
            const relTime = this.toRelativeTime(globalTime);
            this.audio.currentTime = relTime;
            this.updatePlayerVisuals();
        }

        if (wasPlaying) {
            this.play();
        }
    }

    setPlayState(playing) {
        this.isPlaying = playing;
        if (playing) {
            this.playBtn.querySelector('.icon-play').classList.add('hidden');
            this.playBtn.querySelector('.icon-pause').classList.remove('hidden');
            if (this.onPlay) {
                this.onPlay();
            }
        } else {
            this.playBtn.querySelector('.icon-play').classList.remove('hidden');
            this.playBtn.querySelector('.icon-pause').classList.add('hidden');
            if (this.onPause) {
                this.onPause();
            }
        }
    }

    handleTimeUpdate() {
        const globalTime = this.getGlobalTime();
        const lastTime = this.lastGlobalTime !== undefined ? this.lastGlobalTime : globalTime;
        this.lastGlobalTime = globalTime;

        // Enforce range boundaries (No looping, only pause when crossing the end)
        if (this.mode === 'snippet' && globalTime >= this.end && lastTime < this.end) {
            this.pause();
            return;
        }

        if (this.mode === 'editor' && globalTime >= this.currentEnd && lastTime < this.currentEnd) {
            this.pause();
            return;
        }

        // Global Mode transitions to next file
        if (this.mode === 'global') {
            const currentSrc = this.audioSources[this.currentSourceIndex];
            if (currentSrc && globalTime >= currentSrc.end_time) {
                // Seek to next file
                this.seek(currentSrc.end_time);
                return;
            }
        }

        this.updatePlayerVisuals();
        if (this.onTimeUpdate) {
            this.onTimeUpdate(globalTime);
        }
    }

    handleAudioEnded() {
        if (this.playRange) {
            this.setPlayState(false);
            const relativeStart = this.toRelativeTime(this.playRange.start);
            this.audio.currentTime = relativeStart;
            this.updatePlayerVisuals();
            return;
        }

        if (this.mode === 'global' && this.currentSourceIndex < this.audioSources.length - 1) {
            // Play next file
            const nextSrc = this.audioSources[this.currentSourceIndex + 1];
            this.seek(nextSrc.start_time);
            this.play();
        } else {
            this.setPlayState(false);
        }
    }

    getGlobalTime() {
        if (this.temporaryTime !== null && this.temporaryTime !== undefined) {
            return this.temporaryTime;
        }

        if (!this.audio || !this.isLoaded) {
            return this.mode === 'global' ? 0 : (this.mode === 'editor' ? this.currentStart : this.start);
        }

        const nativeTime = this.audio.currentTime;
        if (this.mode === 'global') {
            const currentSrc = this.audioSources[this.currentSourceIndex];
            if (currentSrc) {
                return currentSrc.start_time + nativeTime;
            }
            return nativeTime;
        }

        // Snippet / Editor mode is single file
        const fileStart = 0; // Relative to current loaded file
        return nativeTime;
    }

    toRelativeTime(globalTime) {
        if (this.mode === 'global') {
            const currentSrc = this.audioSources[this.currentSourceIndex];
            if (currentSrc) {
                return Math.max(0, globalTime - currentSrc.start_time);
            }
        }
        return globalTime;
    }

    formatTime(sec) {
        if (isNaN(sec) || sec === null) return '00:00';
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = Math.floor(sec % 60);
        const mm = String(m).padStart(2, '0');
        const ss = String(s).padStart(2, '0');
        if (h > 0) {
            return `${String(h).padStart(2, '0')}:${mm}:${ss}`;
        }
        return `${mm}:${ss}`;
    }

    // --- Editor Scale (selection-anchored zoom) ---
    // The selection window always occupies EDITOR_ZOOM_WIDTH of the track;
    // the sections before and after it share the rest proportionally to
    // their durations. Precise trimming happens inside the zoomed window.
    getEditorDuration() {
        return this.fileDuration
            || (this.audioSources.length > 0 ? this.audioSources[this.audioSources.length - 1].end_time : this.currentEnd + 60);
    }

    getZoomMetrics() {
        // Frozen during handle drags so the scale stays stable under the cursor.
        if (this.frozenMetrics) return this.frozenMetrics;

        const D = this.getEditorDuration();
        const zoomStart = Math.max(0, Math.min(this.currentStart, D));
        const zoomEnd = Math.max(zoomStart, Math.min(this.currentEnd, D));

        const durPre = zoomStart;
        const durZoom = Math.max(0.001, zoomEnd - zoomStart);
        const durPost = Math.max(0, D - zoomEnd);

        let widthZoom = EDITOR_ZOOM_WIDTH;
        let widthPre = 0;
        let widthPost = 0;

        if (durPre + durPost > 0) {
            const remaining = 1 - widthZoom;
            widthPre = remaining * (durPre / (durPre + durPost));
            widthPost = remaining * (durPost / (durPre + durPost));
        } else {
            widthZoom = 1; // selection covers the whole file
        }

        return {
            D, zoomStart, zoomEnd,
            durPre, durZoom, durPost,
            pctPreEnd: widthPre * 100,
            pctZoomEnd: (widthPre + widthZoom) * 100
        };
    }

    timeToPercentage(t) {
        const m = this.getZoomMetrics();
        if (m.D <= 0) return 0;
        if (t <= m.zoomStart) {
            return m.durPre > 0 ? (t / m.durPre) * m.pctPreEnd : 0;
        }
        if (t <= m.zoomEnd) {
            return m.pctPreEnd + ((t - m.zoomStart) / m.durZoom) * (m.pctZoomEnd - m.pctPreEnd);
        }
        const postProgress = m.durPost > 0 ? (t - m.zoomEnd) / m.durPost : 0;
        return m.pctZoomEnd + postProgress * (100 - m.pctZoomEnd);
    }

    percentageToTime(p) {
        const m = this.getZoomMetrics();
        if (p <= m.pctPreEnd) {
            return m.pctPreEnd > 0 ? (p / m.pctPreEnd) * m.durPre : 0;
        }
        if (p <= m.pctZoomEnd) {
            return m.zoomStart + ((p - m.pctPreEnd) / (m.pctZoomEnd - m.pctPreEnd)) * m.durZoom;
        }
        const postProgress = m.pctZoomEnd < 100 ? (p - m.pctZoomEnd) / (100 - m.pctZoomEnd) : 0;
        return m.zoomEnd + postProgress * m.durPost;
    }

    parseTime(timeStr) {
        const parts = timeStr.toString().split(':');
        if (parts.length === 3) {
            return parseInt(parts[0]) * 3600 + parseInt(parts[1]) * 60 + parseFloat(parts[2].replace(',', '.'));
        }
        if (parts.length === 2) {
            return parseInt(parts[0]) * 60 + parseFloat(parts[1].replace(',', '.'));
        }
        return parseFloat(timeStr.toString().replace(',', '.'));
    }

    async updateRange(start, end) {
        this.start = start;
        this.end = end;
        this.currentStart = start;
        this.currentEnd = end;
        
        if (this.startInput) this.startInput.value = this.formatTime(this.currentStart);
        if (this.endInput) this.endInput.value = this.formatTime(this.currentEnd);
        
        this.updatePlayerVisuals();
        await this.seek(this.currentStart);
        this.play();
    }

    destroy() {
        if (this.waveformResizeObserver) {
            this.waveformResizeObserver.disconnect();
            this.waveformResizeObserver = null;
        }
        if (this.audio) {
            this.audio.pause();
            this.audio.remove();
        }
        this.container.innerHTML = '';
    }
}
