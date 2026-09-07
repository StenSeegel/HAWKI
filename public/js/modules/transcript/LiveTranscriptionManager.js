import { WaveformAudioPlayer } from './WaveformAudioPlayer.js';

// Subtitle-style wrapping limit for the live transcript rolling window (established convention).
const LIVE_TRANSCRIPT_CHARS_PER_LINE = 42;

export class LiveTranscriptionManager {
    constructor(app) {
        this.app = app;
        this.pollingJobs = new Set();
        this.liveRecordingPlayers = new Map(); // recording id -> WaveformAudioPlayer
        this.currentLiveTab = 'record'; // matches the blade's default active tab
        this.initializeState();
        this.checkMicrophonePermission();
    }

    initializeState() {
        this.app.state.liveTranscriptFontSize = 32;
        this.app.state.liveTranscriptContrastInverted = false;
        this.app.state.liveTranscriptMaximized = false;
        this.app.state.liveTranscriptMode = 'onprem';
        this.app.state.liveInputDevices = [];
        this.app.state.liveSelectedDeviceId = '';
        this.app.state.liveMicrophonePermissionGranted = false;
        this.app.state.liveRecordingStatus = 'idle';
        this.app.state.liveRecordingError = '';
        this.app.state.liveMediaStream = null;
        this.app.state.liveRecorder = null;
        this.app.state.liveAudioChunks = [];
        this.app.state.liveRecordedFiles = []; // { id, file, url } — appended to, never overwritten, by stopLiveRecording
        this.app.state.liveRecordingStartedAt = null;

        // Rolling 3-line transcript window state
        this.liveTranscriptLines = [];
        this.liveTranscriptCurrentLine = '';
    }

    async checkMicrophonePermission() {
        if (navigator.permissions && navigator.permissions.query) {
            try {
                const result = await navigator.permissions.query({ name: 'microphone' });
                this.app.state.liveMicrophonePermissionGranted = (result.state === 'granted');
                
                result.onchange = () => {
                    this.app.state.liveMicrophonePermissionGranted = (result.state === 'granted');
                    if (this.app.state.liveMicrophonePermissionGranted) {
                        this.initializeLiveAudioDevices();
                    }
                };
                
                if (this.app.state.liveMicrophonePermissionGranted) {
                    this.initializeLiveAudioDevices();
                }
            } catch (e) {
                console.warn('Microphone permission query failed:', e);
            }
        }
    }

    registerGlobalBindings(window) {
        window.setLiveTab = this.setLiveTab.bind(this);
        window.toggleLiveRecording = this.toggleLiveRecording.bind(this);
        window.initializeLiveAudioDevices = this.initializeLiveAudioDevices.bind(this);
        window.requestLiveMicrophonePermission = this.requestLiveMicrophonePermission.bind(this);
        window.toggleLiveTranscriptMaximize = this.toggleMaximize.bind(this);
        window.toggleLiveContrast = this.toggleContrast.bind(this);
        window.updateLiveFontSize = this.updateFontSize.bind(this);
    }

    registerEventListeners() {
        console.log('Registering LiveTranscriptionManager event listeners...');
        const liveRecordStartBtn = document.getElementById('live-record-start-btn');
        console.log('live-record-start-btn:', liveRecordStartBtn);
        if (liveRecordStartBtn) {
            liveRecordStartBtn.addEventListener('click', () => {
                console.log('live-record-start-btn clicked');
                this.toggleLiveRecording();
            });
        }

        const liveRecordUploadBtn = document.getElementById('live-record-upload-btn');
        if (liveRecordUploadBtn) {
            liveRecordUploadBtn.addEventListener('click', () => this.uploadLiveRecording());
        }

        // Two-step delete confirm per recording — same arm/cancel/confirm interaction
        // as the speaker delete buttons, delegated since cards are generated dynamically.
        const liveRecordPlayerList = document.getElementById('live-record-player-list');
        if (liveRecordPlayerList) {
            liveRecordPlayerList.addEventListener('click', (e) => {
                const downloadBtn = e.target.closest('.btn-record-download');
                if (downloadBtn) {
                    const wrapper = downloadBtn.closest('.live-record-player-item');
                    const id = wrapper?.dataset.recordingId;
                    if (id) this.downloadLiveRecording(id);
                    return;
                }

                const deleteBtn = e.target.closest('.btn-record-delete');
                if (deleteBtn) {
                    const wrapper = deleteBtn.closest('.live-record-delete-wrapper');
                    const group = wrapper.querySelector('.confirm-btns-group');
                    deleteBtn.style.setProperty('display', 'none', 'important');
                    if (group) group.style.setProperty('display', 'flex', 'important');
                    return;
                }

                const cancelBtn = e.target.closest('.live-record-delete-wrapper .btn-cancel');
                if (cancelBtn) {
                    const wrapper = cancelBtn.closest('.live-record-delete-wrapper');
                    const btn = wrapper.querySelector('.btn-record-delete');
                    const group = wrapper.querySelector('.confirm-btns-group');
                    if (group) group.style.setProperty('display', 'none', 'important');
                    if (btn) btn.style.setProperty('display', 'flex', 'important');
                    return;
                }

                const confirmBtn = e.target.closest('.live-record-delete-wrapper .btn-confirm');
                if (confirmBtn) {
                    const wrapper = confirmBtn.closest('.live-record-player-item');
                    const id = wrapper?.dataset.recordingId;
                    if (id) this.discardLiveRecording(id);
                }
            });
        }

        const maximizeBtn = document.getElementById('live-transcript-maximize-toggle');
        console.log('maximizeBtn:', maximizeBtn);
        if (maximizeBtn) {
            maximizeBtn.addEventListener('click', () => {
                console.log('maximizeBtn clicked');
                this.toggleMaximize();
            });
        }

        const contrastToggle = document.getElementById('live-contrast-toggle');
        console.log('contrastToggle:', contrastToggle);
        if (contrastToggle) {
            contrastToggle.addEventListener('change', (e) => {
                console.log('contrastToggle changed:', e.target.checked);
                this.toggleContrast(e.target.checked);
            });
        }

        const liveFontSizeSlider = document.getElementById('live-font-size-slider');
        if (liveFontSizeSlider) {
            liveFontSizeSlider.addEventListener('input', (e) => {
                this.app.state.liveTranscriptFontSize = e.target.value;
                this.applyAppearance();
            });
        }

        const modeSelect = document.getElementById('live-transcript-mode-select');
        if (modeSelect) {
            modeSelect.addEventListener('change', (e) => {
                this.app.state.liveTranscriptMode = e.target.value;
            });
        }

        document.addEventListener('fullscreenchange', () => {
            if (!document.fullscreenElement) {
                this.app.state.liveTranscriptMaximized = false;
                document.body.classList.remove('live-transcript-maximized-active');
            }
        });

        // Handle device selection for both main UI and sidebar
        const deviceSelects = [
            document.getElementById('live-input-device-select'),
            document.getElementById('live-input-device-select-sidebar')
        ];

        deviceSelects.forEach((select, idx) => {
            console.log(`deviceSelect[${idx}]:`, select);
            if (select) {
                select.addEventListener('change', (event) => {
                    const deviceId = event.target.value;
                    console.log('Device selected:', deviceId);
                    this.app.state.liveSelectedDeviceId = deviceId;
                    
                    // Keep selectors in sync
                    deviceSelects.forEach(s => {
                        if (s && s !== select) {
                            s.value = deviceId;
                        }
                    });
                });
            }
        });
    }

    setLiveTab(tabId = 'record') {
        const normalizedTabId = tabId === 'live-transcript' ? 'live-transcript' : 'record';

        if (normalizedTabId !== 'live-transcript' && this.app.state.liveTranscriptMaximized) {
            this.app.state.liveTranscriptMaximized = false;
            document.body.classList.remove('live-transcript-maximized-active');
        }

        document.querySelectorAll('#live-record-tabs .transcript-tab[data-live-tab]')
            .forEach(tab => tab.classList.toggle('active', tab.dataset.liveTab === normalizedTabId));

        const recordPanel = document.getElementById('live-record-panel');
        const transcriptPanel = document.getElementById('live-transcript-panel');
        if (recordPanel) recordPanel.classList.toggle('hidden', normalizedTabId !== 'record');
        if (transcriptPanel) transcriptPanel.classList.toggle('hidden', normalizedTabId !== 'live-transcript');

        const recordSidebar = document.getElementById('live-record-sidebar-options');
        const transcriptSidebar = document.getElementById('live-transcript-sidebar-options');
        if (recordSidebar) recordSidebar.classList.toggle('hidden', normalizedTabId !== 'record');
        if (transcriptSidebar) transcriptSidebar.classList.toggle('hidden', normalizedTabId !== 'live-transcript');

        this.currentLiveTab = normalizedTabId;

        this.applyAppearance();
        this.renderLiveRecordingList();
    }

    async initializeLiveAudioDevices() {
        console.log('initializeLiveAudioDevices started');
        const deviceSelect = document.getElementById('live-input-device-select');
        const deviceSelectSidebar = document.getElementById('live-input-device-select-sidebar');
        
        console.log('deviceSelect:', deviceSelect, 'deviceSelectSidebar:', deviceSelectSidebar);

        if (!deviceSelect && !deviceSelectSidebar) {
            console.log('No device select found, exiting initializeLiveAudioDevices');
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
            console.log('navigator.mediaDevices.enumerateDevices not supported');
            if (deviceSelect) {
                deviceSelect.innerHTML = `<option value="">${window.translation?.TranscriptMicrophoneAccessUnsupported ?? 'Mikrofonzugriff nicht unterstützt'}</option>`;
                deviceSelect.disabled = true;
            }
            return;
        }

        try {
            console.log('Enumerating devices...');
            const devices = await navigator.mediaDevices.enumerateDevices();
            console.log('Devices found:', devices.length);
            const audioInputs = devices.filter(device => device.kind === 'audioinput');
            console.log('Audio inputs found:', audioInputs.length);
            this.app.state.liveInputDevices = audioInputs;

            if (!this.app.state.liveSelectedDeviceId && audioInputs.length > 0) {
                this.app.state.liveSelectedDeviceId = audioInputs[0].deviceId;
                console.log('Auto-selected device:', this.app.state.liveSelectedDeviceId);
            }

            this.renderLiveAudioDeviceOptions();
            console.log('initializeLiveAudioDevices finished');
        } catch (error) {
            console.error('Mikrofone konnten nicht geladen werden:', error);
            if (deviceSelect) {
                deviceSelect.innerHTML = `<option value="">${window.translation?.TranscriptMicrophonesUnavailable ?? 'Mikrofone nicht verfügbar'}</option>`;
                deviceSelect.disabled = true;
            }
        }
    }

    renderLiveAudioDeviceOptions() {
        console.log('renderLiveAudioDeviceOptions called');
        const selects = [
            document.getElementById('live-input-device-select'),
            document.getElementById('live-input-device-select-sidebar')
        ].filter(el => el !== null);

        if (selects.length === 0) {
            console.log('No selects to render options into');
            return;
        }

        const devices = this.app.state.liveInputDevices || [];
        console.log('Rendering options for devices:', devices.length);
        const status = this.app.state.liveRecordingStatus || 'idle';
        const isDisabled = ['recording', 'stopping', 'requesting'].includes(status);

        let optionsHtml = '';
        if (devices.length === 0) {
            optionsHtml = '<option value="">Standardmikrofon</option>';
        } else {
            optionsHtml = devices.map((device, index) => {
                const label = device.label || `Mikrofon ${index + 1}`;
                const selected = device.deviceId === this.app.state.liveSelectedDeviceId ? ' selected' : '';
                return `<option value="${this.escapeHTML(device.deviceId)}"${selected}>${this.escapeHTML(label)}</option>`;
            }).join('');
        }

        selects.forEach(select => {
            select.innerHTML = optionsHtml;
            select.disabled = isDisabled;
        });
        console.log('renderLiveAudioDeviceOptions finished');
    }

    escapeHTML(str) {
        if (!str) return '';
        const p = document.createElement('p');
        p.textContent = str;
        return p.innerHTML;
    }

    async requestLiveMicrophonePermission() {
        console.log('requestLiveMicrophonePermission started');
        this.app.state.liveRecordingStatus = 'requesting';
        this.updateLiveRecordingUI();
        
        try {
            console.log('Calling getUserMedia...');
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            console.log('getUserMedia success');
            this.app.state.liveMicrophonePermissionGranted = true;
            this.app.state.liveRecordingStatus = 'idle';
            stream.getTracks().forEach(track => {
                console.log('Stopping track:', track.label);
                track.stop();
            });
            console.log('Tracks stopped, initializing devices...');
            await this.initializeLiveAudioDevices();
            console.log('Devices initialized, updating UI...');
            this.updateLiveRecordingUI();
            console.log('requestLiveMicrophonePermission finished');
        } catch (error) {
            console.error('Microphone permission denied or error:', error);
            this.app.state.liveMicrophonePermissionGranted = false;
            this.app.state.liveRecordingError = (window.translation?.TranscriptMicrophonePermissionDenied ?? 'Mikrofonberechtigung verweigert: ') + error.message;
            this.app.state.liveRecordingStatus = 'idle';
            this.updateLiveRecordingUI();
        }
    }

    // Clears the rolling transcript window, ready for a fresh recording session.
    resetLiveTranscript() {
        this.liveTranscriptLines = [];
        this.liveTranscriptCurrentLine = '';
        this.renderLiveTranscriptWindow();
    }

    // Wraps incoming text at the established char-per-line limit and keeps only
    // the current line plus the two lines before it (older text is discarded,
    // not just hidden, so the buffer never grows unbounded).
    appendLiveTranscriptText(text) {
        if (!text) return;

        this.liveTranscriptCurrentLine += text;

        while (this.liveTranscriptCurrentLine.length > LIVE_TRANSCRIPT_CHARS_PER_LINE) {
            let breakIndex = this.liveTranscriptCurrentLine.lastIndexOf(' ', LIVE_TRANSCRIPT_CHARS_PER_LINE);
            if (breakIndex <= 0) {
                breakIndex = LIVE_TRANSCRIPT_CHARS_PER_LINE;
            }
            this.liveTranscriptLines.push(this.liveTranscriptCurrentLine.slice(0, breakIndex).trimEnd());
            this.liveTranscriptCurrentLine = this.liveTranscriptCurrentLine.slice(breakIndex).trimStart();
        }

        if (this.liveTranscriptLines.length > 2) {
            this.liveTranscriptLines = this.liveTranscriptLines.slice(-2);
        }

        this.renderLiveTranscriptWindow();
    }

    renderLiveTranscriptWindow() {
        const olderEl = document.getElementById('live-transcript-line-older');
        const prevEl = document.getElementById('live-transcript-line-prev');
        const currentEl = document.getElementById('live-transcript-line-current');

        const lines = this.liveTranscriptLines;
        const prev = lines[lines.length - 1] || '';
        const older = lines[lines.length - 2] || '';

        if (olderEl) olderEl.textContent = older;
        if (prevEl) prevEl.textContent = prev;
        if (currentEl) currentEl.textContent = this.liveTranscriptCurrentLine;
    }

    async toggleLiveRecording() {
        if (this.app.state.liveRecordingStatus === 'idle') {
            await this.startLiveRecording();
        } else if (this.app.state.liveRecordingStatus === 'recording') {
            await this.stopLiveRecording();
        }
    }

    async startLiveRecording() {
        try {
            if (!this.app.state.liveMicrophonePermissionGranted) {
                console.log('Permission not granted in state, checking navigator.permissions...');
                if (navigator.permissions && navigator.permissions.query) {
                    const result = await navigator.permissions.query({ name: 'microphone' });
                    if (result.state === 'granted') {
                        this.app.state.liveMicrophonePermissionGranted = true;
                    }
                }
            }

            if (!this.app.state.liveMicrophonePermissionGranted) {
                console.log('Permission still not granted, requesting...');
                await this.requestLiveMicrophonePermission();
                if (!this.app.state.liveMicrophonePermissionGranted) {
                    return;
                }
            }

            const liveTranscriptMode = this.app.state.liveTranscriptMode;
            if (this.currentLiveTab === 'live-transcript' && ['openai', 'onprem'].includes(liveTranscriptMode)) {
                // Use the Realtime WebRTC provider (the on-prem realtime
                // bridge or OpenAI) for the live transcript text...
                this.resetLiveTranscript();
                window.RealtimeTranscription.onTextUpdate = (text) => {
                    this.appendLiveTranscriptText(text);
                };

                await window.RealtimeTranscription.start(liveTranscriptMode);

                // ...and also record that same mic stream locally, so the
                // finished audio lands in the recordings list below — same
                // button, same outcome as the classic record mode.
                this.attachLocalRecorder(window.RealtimeTranscription.mediaStream);

                this.app.state.liveRecordingStatus = 'recording';
                this.app.state.liveRecordingStartedAt = Date.now();
                this.updateLiveRecordingUI();
                return;
            }

            const constraints = {
                audio: {
                    deviceId: this.app.state.liveSelectedDeviceId
                        ? { exact: this.app.state.liveSelectedDeviceId }
                        : undefined
                }
            };

            const stream = await navigator.mediaDevices.getUserMedia(constraints);
            this.attachLocalRecorder(stream);
            this.app.state.liveRecordingStatus = 'recording';
            this.app.state.liveRecordingStartedAt = Date.now();
            this.updateLiveRecordingUI();
        } catch (error) {
            console.error('Error starting recording:', error);
            this.app.state.liveRecordingError = error.message || (window.translation?.TranscriptStartRecordingFailed ?? 'Fehler beim Starten der Aufnahme');
            this.app.state.liveRecordingStatus = 'idle';
            this.updateLiveRecordingUI();
        }
    }

    // Starts a MediaRecorder against the given stream — shared by both
    // recording paths (classic record mode's own getUserMedia stream, and
    // live-transcript mode's RealtimeTranscription.mediaStream).
    attachLocalRecorder(stream) {
        this.app.state.liveMediaStream = stream;
        this.app.state.liveRecorder = new MediaRecorder(stream);
        this.app.state.liveAudioChunks = [];
        this.app.state.liveRecorder.ondataavailable = (event) => {
            this.app.state.liveAudioChunks.push(event.data);
        };
        this.app.state.liveRecorder.start();
    }

    // "username-yyyymmdd-hhmmss.wav", timestamped to when the recording started.
    buildLiveRecordingFilename() {
        const username = (typeof userInfo !== 'undefined' && userInfo?.username) || 'user';
        const pad = (n) => String(n).padStart(2, '0');
        const date = new Date(this.app.state.liveRecordingStartedAt || Date.now());
        const datePart = `${date.getFullYear()}${pad(date.getMonth() + 1)}${pad(date.getDate())}`;
        const timePart = `${pad(date.getHours())}${pad(date.getMinutes())}${pad(date.getSeconds())}`;
        return `${username}-${datePart}-${timePart}.wav`;
    }

    // Wraps the captured chunks into a real, valid .wav File and appends it
    // to the shared recordings list — used by both the classic and openai
    // recording paths. MediaRecorder never actually encodes to WAV (Chrome
    // records webm/opus, etc.) — labeling that raw blob as "audio/wav" would
    // produce a file with no RIFF header at all, which fails validation the
    // moment anything (backend, ffmpeg, a native player) actually checks the
    // container instead of trusting the extension. Decoding via Web Audio
    // and re-encoding as PCM16 guarantees the bytes really are WAV.
    async finalizeLiveRecordingChunks() {
        const recordedBlob = new Blob(this.app.state.liveAudioChunks, {
            type: this.app.state.liveRecorder.mimeType || 'audio/webm'
        });

        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        let file;
        try {
            const arrayBuffer = await recordedBlob.arrayBuffer();
            const audioBuffer = await audioCtx.decodeAudioData(arrayBuffer);
            const wavBuffer = this.encodeWav(audioBuffer);
            file = new File([wavBuffer], this.buildLiveRecordingFilename(), { type: 'audio/wav' });
        } finally {
            audioCtx.close();
        }

        const id = `rec_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
        this.app.state.liveRecordedFiles.push({ id, file });
    }

    // PCM16 RIFF/WAVE encoder — turns a decoded AudioBuffer into real WAV bytes.
    encodeWav(audioBuffer) {
        const numChannels = audioBuffer.numberOfChannels;
        const sampleRate = audioBuffer.sampleRate;
        const numFrames = audioBuffer.length;
        const blockAlign = numChannels * 2; // 16-bit samples
        const dataSize = numFrames * blockAlign;

        const buffer = new ArrayBuffer(44 + dataSize);
        const view = new DataView(buffer);
        const writeStr = (offset, str) => {
            for (let i = 0; i < str.length; i++) view.setUint8(offset + i, str.charCodeAt(i));
        };

        writeStr(0, 'RIFF');
        view.setUint32(4, 36 + dataSize, true);
        writeStr(8, 'WAVE');
        writeStr(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true); // PCM
        view.setUint16(22, numChannels, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, sampleRate * blockAlign, true);
        view.setUint16(32, blockAlign, true);
        view.setUint16(34, 16, true);
        writeStr(36, 'data');
        view.setUint32(40, dataSize, true);

        const channelData = [];
        for (let ch = 0; ch < numChannels; ch++) {
            channelData.push(audioBuffer.getChannelData(ch));
        }

        let offset = 44;
        for (let i = 0; i < numFrames; i++) {
            for (let ch = 0; ch < numChannels; ch++) {
                const sample = Math.max(-1, Math.min(1, channelData[ch][i]));
                view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7fff, true);
                offset += 2;
            }
        }

        return buffer;
    }

    async stopLiveRecording() {
        const isRealtimeMode = this.currentLiveTab === 'live-transcript'
            && ['openai', 'onprem'].includes(this.app.state.liveTranscriptMode);

        if (!this.app.state.liveRecorder || this.app.state.liveRecordingStatus !== 'recording') {
            if (isRealtimeMode) {
                this.app.state.liveRecordingStatus = 'stopping';
                this.updateLiveRecordingUI();
                await window.RealtimeTranscription.stop();
            }
            this.app.state.liveRecordingStatus = 'idle';
            this.updateLiveRecordingUI();
            return;
        }

        // Decoding/re-encoding the audio (see finalizeLiveRecordingChunks) takes
        // a moment, so surface the existing "stopping" state instead of jumping
        // straight to idle.
        this.app.state.liveRecordingStatus = 'stopping';
        this.updateLiveRecordingUI();

        return new Promise((resolve, reject) => {
            try {
                this.app.state.liveRecorder.onstop = async () => {
                    try {
                        await this.finalizeLiveRecordingChunks();
                    } catch (error) {
                        console.error('Error finalizing recording:', error);
                        this.app.state.liveRecordingError = (window.translation?.TranscriptRecordingProcessFailed ?? 'Die Aufnahme konnte nicht verarbeitet werden.');
                    }

                    if (isRealtimeMode) {
                        // Also tears down the peer connection and stops the shared stream's
                        // tracks — after draining any in-flight transcription (still
                        // shows the existing "stopping" UI state while it waits).
                        await window.RealtimeTranscription.stop();
                    } else {
                        this.app.state.liveMediaStream.getTracks().forEach(track => track.stop());
                    }

                    this.app.state.liveRecordingStatus = 'idle';
                    this.updateLiveRecordingUI();
                    resolve();
                };

                this.app.state.liveRecorder.stop();
            } catch (error) {
                console.error('Error stopping recording:', error);
                this.app.state.liveRecordingError = (window.translation?.TranscriptStopRecordingFailed ?? 'Fehler beim Beenden der Aufnahme');
                this.app.state.liveRecordingStatus = 'idle';
                this.updateLiveRecordingUI();
                reject(error);
            }
        });
    }

    // Hands ALL recorded files off to the file-transcription flow as a single
    // transcript container — triggered by the upload button, never automatically
    // on stop, so multiple takes accumulate until the user is ready.
    uploadLiveRecording() {
        const entries = this.app.state.liveRecordedFiles || [];
        if (!entries.length) return;

        const files = entries.map(entry => entry.file);
        this.app.ui.switchTranscriptView('file');
        setTimeout(() => {
            this.app.ui.handleFileSelect(files);
        }, 300);

        // WaveformAudioPlayer owns its own object URL and revokes it on destroy(),
        // which renderLiveRecordingList() below triggers as it tears down these cards.
        this.app.state.liveRecordedFiles = [];
        this.updateLiveRecordingUI();
    }

    // Saves a single recording to disk via a throwaway <a download> click.
    downloadLiveRecording(id) {
        const entries = this.app.state.liveRecordedFiles || [];
        const entry = entries.find(e => e.id === id);
        if (!entry) return;

        const url = URL.createObjectURL(entry.file);
        const link = document.createElement('a');
        link.href = url;
        link.download = entry.file.name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    // Discards a single recording without uploading it — armed via that
    // recording's trash button, using the same two-step confirm interaction
    // as the speaker delete buttons.
    discardLiveRecording(id) {
        const entries = this.app.state.liveRecordedFiles || [];
        const idx = entries.findIndex(entry => entry.id === id);
        if (idx === -1) return;

        entries.splice(idx, 1);
        this.updateLiveRecordingUI();
    }

    // Renders one playback card per recorded file — each a WaveformAudioPlayer
    // (same component used on the upload screen), driven directly off the
    // local recording File. Diffs against the existing cards/players so an
    // unrelated state update (e.g. starting a new take) doesn't tear down and
    // restart playback of an unaffected recording.
    renderLiveRecordingList() {
        const listContainer = document.getElementById('live-record-player-list');
        if (!listContainer) return;

        const entries = this.app.state.liveRecordedFiles || [];
        // Stays visible regardless of liveRecordingStatus or which tab is
        // active, so starting another take doesn't hide (and tear down the
        // players of) earlier recordings — both the classic and the
        // live-transcript recording paths feed this same list.
        const shouldShow = entries.length > 0;

        listContainer.classList.toggle('hidden', !shouldShow);

        if (!shouldShow) {
            this.liveRecordingPlayers.forEach(player => player.destroy());
            this.liveRecordingPlayers.clear();
            listContainer.innerHTML = '';
            return;
        }

        const currentIds = new Set(entries.map(entry => entry.id));

        this.liveRecordingPlayers.forEach((player, id) => {
            if (currentIds.has(id)) return;
            player.destroy();
            this.liveRecordingPlayers.delete(id);
            const card = listContainer.querySelector(`[data-recording-id="${id}"]`);
            if (card) card.remove();
        });

        entries.forEach(entry => {
            if (this.liveRecordingPlayers.has(entry.id)) return;
            const card = this.buildLiveRecordingCard(entry);
            listContainer.appendChild(card);
            this.initLiveRecordingCardPlayer(entry);
        });
    }

    buildLiveRecordingCard(entry) {
        const card = document.createElement('div');
        card.className = 'live-record-player-item';
        card.dataset.recordingId = entry.id;
        card.innerHTML = `
            <div class="live-record-player-slot" id="live-record-player-slot-${entry.id}"></div>
            <div class="live-record-item-actions">
                <button type="button" class="btn-record-download" title="${window.translation?.TranscriptDownloadRecording ?? 'Aufnahme herunterladen'}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-download"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </button>
                <div class="live-record-delete-wrapper">
                    <button type="button" class="btn-record-delete" title="${window.translation?.TranscriptDeleteRecording ?? 'Aufnahme löschen'}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-trash-2"><path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    </button>
                    <div class="confirm-btns-group" style="display: none;">
                        <button type="button" class="btn-confirm" style="color: #ef4444;" title="${window.translation?.TranscriptConfirm ?? 'Bestätigen'}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-check"><path d="M20 6 9 17l-5-5"/></svg>
                        </button>
                        <button type="button" class="btn-cancel" style="color: #94a3b8;" title="${window.translation?.TranscriptCancel ?? 'Abbrechen'}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-x"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                    </div>
                </div>
            </div>
        `;
        return card;
    }

    initLiveRecordingCardPlayer(entry) {
        const slot = document.getElementById(`live-record-player-slot-${entry.id}`);
        if (!slot) return;

        const player = new WaveformAudioPlayer({
            container: slot,
            file: entry.file
        });

        this.liveRecordingPlayers.set(entry.id, player);
    }

    updateLiveRecordingUI() {
        const status = this.app.state.liveRecordingStatus || 'idle';
        const startBtn = document.getElementById('live-record-start-btn');
        const uploadBtn = document.getElementById('live-record-upload-btn');
        const card = document.getElementById('live-record-card');
        const iconWrap = document.getElementById('live-record-icon-wrap');
        const badge = document.getElementById('live-record-badge');
        const badgeSidebar = document.getElementById('live-record-badge-sidebar');
        const deviceSelect = document.getElementById('live-input-device-select');
        const deviceSelectSidebar = document.getElementById('live-input-device-select-sidebar');
        const title = document.getElementById('live-record-status-title');
        const titleSidebar = document.getElementById('live-record-status-title-sidebar');
        const text = document.getElementById('live-record-status-text');
        const textSidebar = document.getElementById('live-record-status-text-sidebar');
        const recordedFiles = this.app.state.liveRecordedFiles || [];
        const recordingError = this.app.state.liveRecordingError;
        const microphoneReady = this.app.state.liveMicrophonePermissionGranted;

        if (startBtn) {
            startBtn.disabled = status === 'stopping' || status === 'requesting';
            startBtn.style.opacity = status === 'stopping' || status === 'requesting' ? '0.7' : '';
            startBtn.innerHTML = status === 'recording'
                ? `<div style="width: 10px; height: 10px; background: white; border-radius: 2px;"></div>${window.translation?.TranscriptStopRecording ?? 'Aufnahme stoppen'}`
                : status === 'requesting'
                    ? `<div style="width: 10px; height: 10px; background: white; border-radius: 50%;"></div>${window.translation?.TranscriptGrantMicrophone ?? 'Mikrofon freigeben'}`
                    : `<div style="width: 10px; height: 10px; background: white; border-radius: 50%;"></div>${window.translation?.TranscriptStartRecording ?? 'Aufnahme starten'}`;
            
            startBtn.classList.toggle('is-recording', status === 'recording');
        }

        if (uploadBtn) {
            uploadBtn.classList.toggle('hidden', status !== 'idle' || recordedFiles.length === 0);
        }

        this.renderLiveRecordingList();

        if (card) {
            card.classList.toggle('is-recording', status === 'recording');
        }

        if (iconWrap) {
            iconWrap.classList.toggle('recording-pulse', status === 'recording');
        }

        if (badge) {
            badge.classList.toggle('hidden', status !== 'recording');
        }
        if (badgeSidebar) {
            badgeSidebar.classList.toggle('hidden', status !== 'recording');
        }

        const isControlsDisabled = status === 'recording' || status === 'stopping' || status === 'requesting';
        if (deviceSelect) deviceSelect.disabled = isControlsDisabled;
        if (deviceSelectSidebar) deviceSelectSidebar.disabled = isControlsDisabled;

        let titleText = (window.translation?.TranscriptStartYourRecording ?? 'Starten Sie Ihre Aufnahme');
        let statusTextValue = (window.translation?.TranscriptSelectMicrophoneBelow ?? 'Wählen Sie unten ein Mikrofon aus und drücken Sie Aufnahme starten.');

        if (status === 'recording') {
            titleText = (window.translation?.TranscriptRecordingRunning ?? 'Aufnahme läuft');
            statusTextValue = (window.translation?.TranscriptRecordingRunningHint ?? 'Das ausgewählte Mikrofon wird lokal im Browser aufgenommen.');
        } else if (status === 'requesting') {
            titleText = (window.translation?.TranscriptMicrophonePermission ?? 'Mikrofonfreigabe');
            statusTextValue = (window.translation?.TranscriptMicrophonePermissionHint ?? 'Bitte erlaube den Mikrofonzugriff in der Browser-Abfrage.');
        } else if (status === 'stopping') {
            titleText = (window.translation?.TranscriptRecordingStopping ?? 'Aufnahme wird beendet');
            statusTextValue = (window.translation?.TranscriptRecordingStoppingHint ?? 'Die Audiodatei wird vorbereitet.');
        } else if (recordingError) {
            titleText = (window.translation?.TranscriptRecordingNotPossible ?? 'Aufnahme nicht möglich');
            statusTextValue = recordingError;
        } else if (recordedFiles.length > 0) {
            titleText = (window.translation?.TranscriptRecordingReady ?? 'Aufnahme bereit');
            statusTextValue = recordedFiles.length === 1
                ? `${recordedFiles[0].file.name} (${this.formatFileSize(recordedFiles[0].file.size)})`
                : (window.translation?.TranscriptRecordingsReadyToUpload ?? '{count} Aufnahmen bereit zum Hochladen').replace('{count}', recordedFiles.length);
        } else if (microphoneReady) {
            titleText = (window.translation?.TranscriptMicrophoneReady ?? 'Mikrofon bereit');
            statusTextValue = (window.translation?.TranscriptSelectInputDeviceHint ?? 'Wählen Sie ein Eingabegerät aus und starten Sie die Aufnahme.');
        }

        if (title) title.textContent = titleText;
        if (titleSidebar) titleSidebar.textContent = titleText;
        if (text) {
            text.textContent = statusTextValue;
            text.classList.toggle('has-error', Boolean(recordingError));
        }
        if (textSidebar) {
            textSidebar.textContent = statusTextValue;
            textSidebar.classList.toggle('has-error', Boolean(recordingError));
        }
    }

    formatFileSize(bytes) {
        if (!bytes) return '0 KB';
        const megabytes = bytes / (1024 * 1024);
        if (megabytes >= 1) return `${megabytes.toFixed(1)} MB`;
        return `${Math.ceil(bytes / 1024)} KB`;
    }

    toggleMaximize() {
        const panel = document.getElementById('live-transcript-panel');
        this.app.state.liveTranscriptMaximized = !this.app.state.liveTranscriptMaximized;
        
        if (this.app.state.liveTranscriptMaximized) {
            document.body.classList.add('live-transcript-maximized-active');
            if (panel && panel.requestFullscreen) {
                panel.requestFullscreen().catch(err => {
                    console.error(`Error attempting to enable full-screen mode: ${err.message}`);
                });
            }
        } else {
            document.body.classList.remove('live-transcript-maximized-active');
            if (document.fullscreenElement) {
                document.exitFullscreen();
            }
        }
    }

    toggleContrast(inverted) {
        this.app.state.liveTranscriptContrastInverted = inverted;
        const previewCard = document.getElementById('live-transcript-preview-card');
        if (previewCard) {
            previewCard.classList.toggle('contrast-inverted', inverted);
        }
    }

    updateFontSize(size) {
        this.app.state.liveTranscriptFontSize = size;
        this.applyAppearance();
    }

    applyAppearance() {
        const previewText = document.getElementById('live-transcript-preview-text');
        const previewCard = document.getElementById('live-transcript-preview-card');
        const sizeValue = document.getElementById('live-font-size-value');
        const sizeSlider = document.getElementById('live-font-size-slider');
        const contrastToggle = document.getElementById('live-contrast-toggle');
        const maximizeBtn = document.getElementById('live-transcript-maximize-toggle');

        const fontSize = this.app.state.liveTranscriptFontSize || 32;
        const isInverted = Boolean(this.app.state.liveTranscriptContrastInverted);
        const isMaximized = Boolean(this.app.state.liveTranscriptMaximized);

        if (sizeSlider) sizeSlider.value = fontSize;
        if (sizeValue) sizeValue.textContent = `${fontSize}px`;
        // Unitless: consumed by a cqw-based calc() so the text scales with the
        // card's actual width, keeping the same font-to-canvas ratio whether
        // the panel is inline-sized or fullscreen.
        if (previewText) previewText.style.setProperty('--live-font-size', fontSize);
        
        if (previewCard) {
            previewCard.classList.toggle('contrast-inverted', isInverted);
        }

        if (contrastToggle) {
            contrastToggle.checked = isInverted;
        }

        document.body.classList.toggle('live-transcript-maximized-active', isMaximized);
        if (maximizeBtn) {
            maximizeBtn.setAttribute('aria-pressed', isMaximized);
            maximizeBtn.title = isMaximized ? (window.translation?.TranscriptMinimizeTextView ?? 'Textansicht minimieren') : (window.translation?.TranscriptMaximizeTextView ?? 'Textansicht maximieren');
        }
    }
}
