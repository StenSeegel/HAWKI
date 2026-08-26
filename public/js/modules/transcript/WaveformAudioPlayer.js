/**
 * Compact audio player for the upload screen: round play button, file name,
 * time display, and a bar-style waveform that doubles as the seek bar.
 * Plays a local File via an object URL — no backend involved.
 */

// Fixed-resolution peak buffer; bars are resampled from it at draw time so the
// waveform stays crisp at any container width without re-decoding the audio.
const PEAK_RESOLUTION = 200;

// decodeAudioData inflates the whole file into raw PCM in memory — skip the
// real waveform above this size and fall back to placeholder bars.
const DECODE_SIZE_LIMIT = 100 * 1024 * 1024;

let sharedAudioContext = null;

// All live instances — used to pause the others when one starts playing.
const activePlayers = new Set();

export class WaveformAudioPlayer {
    /**
     * @param {Object} options
     * @param {HTMLElement} options.container - DOM container to render into
     * @param {File} options.file - Local audio file to play
     * @param {string} [options.name] - Display name (defaults to file.name)
     */
    constructor(options) {
        this.container = options.container;
        this.file = options.file;
        this.name = options.name || options.file.name;
        this.url = URL.createObjectURL(options.file);

        this.audio = null;
        this.playerEl = null;
        this.playBtn = null;
        this.canvas = null;
        this.timeEl = null;

        this.peaks = null;
        this.duration = 0;
        this.isPlaying = false;
        this.isSeeking = false;
        this.resizeObserver = null;

        this.init();
    }

    init() {
        this.container.innerHTML = '';
        this.playerEl = document.createElement('div');
        this.playerEl.className = 'waveform-audio-player';
        this.playerEl.innerHTML = `
            <div class="waveform-player-body">
                <div class="waveform-player-header">
                    <span class="waveform-player-title">
                        <span class="waveform-player-name"></span>
                        <span class="waveform-player-size"></span>
                    </span>
                    <span class="waveform-player-time">00:00 / 00:00</span>
                </div>
                <div class="waveform-main-row">
                    <button type="button" class="waveform-play-btn" title="Abspielen / Pause">
                        <svg class="icon-play lucide lucide-play" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M5 5a2 2 0 0 1 3.008-1.728l11.997 6.998a2 2 0 0 1 .003 3.458l-12 7A2 2 0 0 1 5 19z"/>
                        </svg>
                        <svg class="icon-pause hidden lucide lucide-pause" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="14" y="4" width="4" height="16" rx="1"></rect>
                            <rect x="6" y="4" width="4" height="16" rx="1"></rect>
                        </svg>
                    </button>
                    <div class="waveform-canvas-wrap">
                        <canvas class="waveform-canvas"></canvas>
                    </div>
                </div>
            </div>
        `;
        this.container.appendChild(this.playerEl);

        const nameEl = this.playerEl.querySelector('.waveform-player-name');
        nameEl.textContent = this.name;
        nameEl.title = this.name;

        const sizeEl = this.playerEl.querySelector('.waveform-player-size');
        sizeEl.textContent = this.formatFileSize(this.file.size);

        this.playBtn = this.playerEl.querySelector('.waveform-play-btn');
        this.canvas = this.playerEl.querySelector('.waveform-canvas');
        this.timeEl = this.playerEl.querySelector('.waveform-player-time');

        this.audio = document.createElement('audio');
        this.audio.preload = 'metadata';
        this.audio.src = this.url;
        this.playerEl.appendChild(this.audio);

        this.bindEvents();
        this.draw();
        this.computePeaks();

        activePlayers.add(this);
    }

    bindEvents() {
        this.playBtn.addEventListener('click', () => this.toggle());

        this.audio.addEventListener('loadedmetadata', () => {
            // MediaRecorder blobs can report Infinity — the decoded buffer
            // duration (set in computePeaks) covers that case.
            if (Number.isFinite(this.audio.duration)) {
                this.duration = this.audio.duration;
            }
            this.updateTimeLabel();
            this.draw();
        });
        this.audio.addEventListener('timeupdate', () => {
            this.updateTimeLabel();
            this.draw();
        });
        this.audio.addEventListener('play', () => this.setPlayState(true));
        this.audio.addEventListener('pause', () => this.setPlayState(false));
        this.audio.addEventListener('ended', () => this.setPlayState(false));

        const wrap = this.playerEl.querySelector('.waveform-canvas-wrap');
        wrap.addEventListener('mousedown', (e) => {
            e.preventDefault();
            this.isSeeking = true;
            this.seekFromEvent(e, wrap);

            const onMouseMove = (moveEvent) => {
                if (this.isSeeking) this.seekFromEvent(moveEvent, wrap);
            };
            const onMouseUp = () => {
                this.isSeeking = false;
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            };
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });

        this.resizeObserver = new ResizeObserver(() => this.draw());
        this.resizeObserver.observe(wrap);
    }

    seekFromEvent(e, wrap) {
        if (!this.duration) return;
        const rect = wrap.getBoundingClientRect();
        const percentage = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        this.audio.currentTime = percentage * this.duration;
        this.updateTimeLabel();
        this.draw();
    }

    async computePeaks() {
        // Peaks are cached on the file object so list re-renders don't re-decode.
        if (this.file._waveformPeaks) {
            this.peaks = this.file._waveformPeaks;
            if (!this.duration && this.file._waveformDuration) {
                this.duration = this.file._waveformDuration;
            }
            this.updateTimeLabel();
            this.draw();
            return;
        }

        if (this.file.size > DECODE_SIZE_LIMIT) {
            this.peaks = new Array(PEAK_RESOLUTION).fill(0.45);
            this.draw();
            return;
        }

        try {
            if (!sharedAudioContext) {
                sharedAudioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
            const arrayBuffer = await this.file.arrayBuffer();
            const audioBuffer = await sharedAudioContext.decodeAudioData(arrayBuffer);

            const channel = audioBuffer.getChannelData(0);
            const bucketSize = Math.max(1, Math.floor(channel.length / PEAK_RESOLUTION));
            const peaks = new Array(PEAK_RESOLUTION).fill(0);

            for (let i = 0; i < PEAK_RESOLUTION; i++) {
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

            const overallMax = Math.max(...peaks, 0.01);
            this.peaks = peaks.map(peak => peak / overallMax);

            this.file._waveformPeaks = this.peaks;
            this.file._waveformDuration = audioBuffer.duration;
            if (!this.duration) this.duration = audioBuffer.duration;

            this.updateTimeLabel();
            this.draw();
        } catch (error) {
            console.warn('Waveform decoding failed, using placeholder bars:', error);
            this.peaks = new Array(PEAK_RESOLUTION).fill(0.45);
            this.draw();
        }
    }

    draw() {
        const wrap = this.canvas.parentElement;
        if (!wrap) return;
        const width = wrap.clientWidth;
        const height = wrap.clientHeight;
        if (width === 0 || height === 0) return;

        const dpr = window.devicePixelRatio || 1;
        this.canvas.width = width * dpr;
        this.canvas.height = height * dpr;

        const ctx = this.canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);

        const styles = getComputedStyle(this.playerEl);
        const playedColor = styles.getPropertyValue('--waveform-played').trim() || '#2F2ABF';
        const unplayedColor = styles.getPropertyValue('--waveform-unplayed').trim() || '#cbd5e1';

        const peaks = this.peaks || new Array(PEAK_RESOLUTION).fill(0.1);
        const barWidth = 3;
        const gap = 3;
        const barCount = Math.max(1, Math.floor(width / (barWidth + gap)));
        const centerY = height / 2;
        const maxBarHeight = height - 2;
        const minBarHeight = 2;

        const progress = this.duration > 0 ? this.audio.currentTime / this.duration : 0;
        const progressX = progress * width;

        for (let i = 0; i < barCount; i++) {
            const peak = peaks[Math.floor((i / barCount) * peaks.length)] || 0;
            const barHeight = Math.max(minBarHeight, peak * maxBarHeight);
            const x = i * (barWidth + gap);

            ctx.fillStyle = (x + barWidth / 2) <= progressX ? playedColor : unplayedColor;
            ctx.beginPath();
            ctx.roundRect(x, centerY - barHeight / 2, barWidth, barHeight, barWidth / 2);
            ctx.fill();
        }

        // Thin playhead line, like the reference design.
        if (progress > 0) {
            ctx.fillStyle = playedColor;
            ctx.fillRect(progressX - 0.5, 0, 1, height);
        }
    }

    updateTimeLabel() {
        this.timeEl.textContent = `${this.formatTime(this.audio.currentTime)} / ${this.formatTime(this.duration)}`;
    }

    toggle() {
        if (this.isPlaying) {
            this.audio.pause();
        } else {
            // Only one file plays at a time across the whole list.
            activePlayers.forEach(player => {
                if (player !== this && player.isPlaying) player.audio.pause();
            });
            this.audio.play().catch(e => console.log('Audio playback failed', e));
        }
    }

    setPlayState(playing) {
        this.isPlaying = playing;
        this.playBtn.querySelector('.icon-play').classList.toggle('hidden', playing);
        this.playBtn.querySelector('.icon-pause').classList.toggle('hidden', !playing);
    }

    formatTime(sec) {
        if (!Number.isFinite(sec) || sec < 0) return '00:00';
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = Math.floor(sec % 60);
        const mm = String(m).padStart(2, '0');
        const ss = String(s).padStart(2, '0');
        return h > 0 ? `${String(h).padStart(2, '0')}:${mm}:${ss}` : `${mm}:${ss}`;
    }

    formatFileSize(bytes) {
        if (!Number.isFinite(bytes)) return '';
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    destroy() {
        activePlayers.delete(this);
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
            this.resizeObserver = null;
        }
        if (this.audio) {
            this.audio.pause();
            this.audio.remove();
            this.audio = null;
        }
        URL.revokeObjectURL(this.url);
        this.container.innerHTML = '';
    }
}
