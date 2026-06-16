import { Utils } from './Utils.js?v=1.0.6';

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

        // Editor calculations (pre-roll & post-roll +-5s)
        if (this.mode === 'editor') {
            this.preRoll = 5;
            this.postRoll = 5;
            this.minTime = Math.max(0, this.start - this.preRoll);
            this.maxTime = this.fileDuration ? Math.min(this.fileDuration, this.end + this.postRoll) : (this.end + this.postRoll);
            this.currentStart = this.start;
            this.currentEnd = this.end;
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
            if (!this.fileDuration && this.audio.duration) {
                this.fileDuration = this.audio.duration;
                if (this.mode === 'editor') {
                    this.maxTime = Math.min(this.fileDuration, this.currentEnd + this.postRoll);
                    this.updatePlayerVisuals();
                }
            }
        });

        this.bindEvents();
        this.updatePlayerVisuals();
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
                    <div class="player-segments-container"></div>
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
    }

    renderGlobalSegments() {
        const segmentsContainer = this.playerEl.querySelector('.player-segments-container');
        if (!segmentsContainer) return;

        segmentsContainer.innerHTML = '';

        const totalDuration = this.getTotalDuration();

        // Update total duration display
        const totalDurationEl = this.playerEl.querySelector('.player-total-duration');
        if (totalDurationEl) {
            totalDurationEl.textContent = this.formatTime(totalDuration);
        }

        if (totalDuration <= 0) return;

        // Render segment blocks
        this.segments.forEach((seg) => {
            const left = (seg.start / totalDuration) * 100;
            const width = ((seg.end - seg.start) / totalDuration) * 100;

            const segEl = document.createElement('div');
            segEl.className = `player-segment speaker-color-${seg.colorId || 1}`;
            segEl.style.left = `${left}%`;
            segEl.style.width = `${width}%`;
            segEl.title = `${seg.speaker || 'Sprecher'}: ${this.formatTime(seg.start)} - ${this.formatTime(seg.end)}`;
            
            // Bubbles up to timelineTrack for precise coordinate seeking

            segmentsContainer.appendChild(segEl);
        });
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

            const updateSeek = (clientX) => {
                const rect = this.timelineTrack.getBoundingClientRect();
                const clickX = clientX - rect.left;
                const percentage = Math.max(0, Math.min(1, clickX / rect.width));
                
                if (this.mode === 'global') {
                    const totalDuration = this.getTotalDuration();
                    this.seek(percentage * totalDuration);
                } else if (this.mode === 'snippet') {
                    const duration = this.end - this.start;
                    this.seek(this.start + (percentage * duration));
                } else if (this.mode === 'editor') {
                    const range = this.maxTime - this.minTime;
                    this.seek(this.minTime + (percentage * range));
                }
            };

            updateSeek(e.clientX);

            const onMouseMove = (moveEvent) => {
                if (!this.isDraggingPlayhead) return;
                updateSeek(moveEvent.clientX);
            };

            const onMouseUp = () => {
                this.isDraggingPlayhead = false;
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            };

            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });

        // Editor mode specific handles & inputs
        if (this.mode === 'editor') {
            this.handleLeft.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this.isDraggingLeft = true;
                document.addEventListener('mousemove', this.dragHandler);
                document.addEventListener('mouseup', this.dragEndHandler);
            });

            this.handleRight.addEventListener('mousedown', (e) => {
                e.preventDefault();
                this.isDraggingRight = true;
                document.addEventListener('mousemove', this.dragHandler);
                document.addEventListener('mouseup', this.dragEndHandler);
            });

            this.startInput.addEventListener('change', () => {
                const newStart = this.parseTime(this.startInput.value);
                if (!isNaN(newStart) && newStart >= this.minTime && newStart < this.currentEnd - 0.2) {
                    this.currentStart = newStart;
                    this.updatePlayerVisuals();
                    if (this.onRangeChange) this.onRangeChange(this.currentStart, this.currentEnd);
                } else {
                    this.startInput.value = this.formatTime(this.currentStart);
                }
            });

            this.endInput.addEventListener('change', () => {
                const newEnd = this.parseTime(this.endInput.value);
                const fileLimit = this.fileDuration || this.maxTime;
                if (!isNaN(newEnd) && newEnd > this.currentStart + 0.2 && newEnd <= fileLimit) {
                    this.currentEnd = newEnd;
                    // Adjust maxTime if manually input past maxTime
                    if (this.currentEnd + this.postRoll > this.maxTime) {
                        this.maxTime = Math.min(fileLimit, this.currentEnd + this.postRoll);
                    }
                    this.updatePlayerVisuals();
                    if (this.onRangeChange) this.onRangeChange(this.currentStart, this.currentEnd);
                } else {
                    this.endInput.value = this.formatTime(this.currentEnd);
                }
            });

            // Handlers
            this.dragHandler = (e) => this.handleDrag(e);
            this.dragEndHandler = () => {
                this.isDraggingLeft = false;
                this.isDraggingRight = false;
                document.removeEventListener('mousemove', this.dragHandler);
                document.removeEventListener('mouseup', this.dragEndHandler);
            };
        }
    }

    handleDrag(e) {
        if (!this.isDraggingLeft && !this.isDraggingRight) return;

        const rect = this.timelineTrack.getBoundingClientRect();
        const mouseX = e.clientX - rect.left;
        const percentage = Math.max(0, Math.min(1, mouseX / rect.width));
        const totalDuration = this.maxTime - this.minTime;
        const targetTime = this.minTime + (percentage * totalDuration);

        if (this.isDraggingLeft) {
            if (targetTime >= this.minTime && targetTime < this.currentEnd - 0.2) {
                this.currentStart = Math.round(targetTime * 100) / 100;
                this.startInput.value = this.formatTime(this.currentStart);
            }
        } else if (this.isDraggingRight) {
            const fileLimit = this.fileDuration || this.maxTime;
            if (targetTime > this.currentStart + 0.2 && targetTime <= fileLimit) {
                this.currentEnd = Math.round(targetTime * 100) / 100;
                this.endInput.value = this.formatTime(this.currentEnd);
            }
        }

        this.updatePlayerVisuals();
        if (this.onRangeChange) {
            this.onRangeChange(this.currentStart, this.currentEnd);
        }
    }

    updatePlayerVisuals() {
        const totalDuration = this.getTotalDuration();

        if (this.mode === 'editor') {
            const range = this.maxTime - this.minTime;
            if (range <= 0) return;

            const leftPct = ((this.currentStart - this.minTime) / range) * 100;
            const widthPct = ((this.currentEnd - this.currentStart) / range) * 100;

            this.selectionRange.style.left = `${leftPct}%`;
            this.selectionRange.style.width = `${widthPct}%`;

            const currentGlobalTime = this.getGlobalTime();
            const playheadPct = ((currentGlobalTime - this.minTime) / range) * 100;
            this.playheadLine.style.left = `${Math.max(0, Math.min(100, playheadPct))}%`;

        } else if (this.mode === 'snippet') {
            const currentGlobalTime = this.getGlobalTime();
            const duration = this.end - this.start;
            const played = currentGlobalTime - this.start;
            const percentage = duration > 0 ? Math.max(0, Math.min(100, (played / duration) * 100)) : 0;

            this.progressLine.style.width = `${percentage}%`;
            this.dotPlayhead.style.left = `${percentage}%`;

        } else if (this.mode === 'global') {
            const currentGlobalTime = this.getGlobalTime();
            const percentage = totalDuration > 0 ? (currentGlobalTime / totalDuration) * 100 : 0;

            this.playhead.style.left = `${Math.max(0, Math.min(100, percentage))}%`;
            this.tooltip.textContent = this.formatTime(currentGlobalTime);
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
        } else {
            this.play();
        }
    }

    async seek(globalTime) {
        const wasPlaying = this.isPlaying;
        if (wasPlaying) {
            this.pause();
        }

        this.temporaryTime = globalTime;
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

        if (this.playRange && globalTime >= this.playRange.end) {
            this.pause();
            this.setPlayState(false);
            const relativeStart = this.toRelativeTime(this.playRange.start);
            this.audio.currentTime = relativeStart;
            this.updatePlayerVisuals();
            return;
        }

        // Enforce range boundaries
        if (this.mode === 'snippet' && globalTime >= this.end) {
            this.pause();
            this.seek(this.start);
            return;
        }

        if (this.mode === 'editor' && globalTime >= this.currentEnd) {
            this.pause();
            this.seek(this.currentStart);
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
            if (this.mode === 'snippet') {
                this.seek(this.start);
            } else if (this.mode === 'editor') {
                this.seek(this.currentStart);
            } else {
                this.seek(0);
            }
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

    parseTime(timeStr) {
        const parts = timeStr.split(':');
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
        if (this.mode === 'editor') {
            this.minTime = Math.max(0, this.start - (this.preRoll || 5));
            this.maxTime = this.fileDuration ? Math.min(this.fileDuration, this.end + (this.postRoll || 5)) : (this.end + (this.postRoll || 5));
            if (this.startInput) this.startInput.value = this.formatTime(this.currentStart);
            if (this.endInput) this.endInput.value = this.formatTime(this.currentEnd);
        }
        this.updatePlayerVisuals();
        await this.seek(this.currentStart);
        this.play();
    }

    destroy() {
        if (this.audio) {
            this.audio.pause();
            this.audio.remove();
        }
        this.container.innerHTML = '';
    }
}
