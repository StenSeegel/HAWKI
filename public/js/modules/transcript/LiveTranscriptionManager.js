export class LiveTranscriptionManager {
    constructor(app) {
        this.app = app;
        this.pollingJobs = new Set();
        this.initializeState();
        this.checkMicrophonePermission();
    }

    initializeState() {
        this.app.state.liveTranscriptFontSize = 18;
        this.app.state.liveTranscriptContrastInverted = false;
        this.app.state.liveTranscriptMaximized = false;
        this.app.state.liveTranscriptMode = 'openai';
        this.app.state.liveInputDevices = [];
        this.app.state.liveSelectedDeviceId = '';
        this.app.state.liveMicrophonePermissionGranted = false;
        this.app.state.liveRecordingStatus = 'idle';
        this.app.state.liveRecordingError = '';
        this.app.state.liveMediaStream = null;
        this.app.state.liveRecorder = null;
        this.app.state.liveAudioChunks = [];
        this.app.state.liveRecordedFile = null;
        this.app.state.liveRecordedFileUrl = null;
        this.app.state.liveRecordingStartedAt = null;
        this.app.state.liveRecordingDurationSeconds = 0;
        this.app.state.liveRecordingTimer = null;
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

        const editTitleBtn = document.querySelector('.edit-title-btn');
        if (editTitleBtn) {
            editTitleBtn.addEventListener('click', () => this.editLiveRecordingTitle());
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

    editLiveRecordingTitle() {
        const titleContainer = document.querySelector('.transcript-title-editable');
        if (!titleContainer) return;

        const originalText = titleContainer.innerText.trim();
        const wrapper = document.createElement('div');
        wrapper.className = 'title-edit-wrapper';

        const confirmBtn = document.createElement('button');
        confirmBtn.className = 'btn-xs title-edit-confirm';
        const confirmTmpl = document.getElementById('tmpl-history-confirm-btn');
        if (confirmTmpl) confirmBtn.appendChild(confirmTmpl.content.cloneNode(true));
        else confirmBtn.innerHTML = '✔';

        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn-xs title-edit-cancel';
        const cancelTmpl = document.getElementById('tmpl-history-cancel-btn');
        if (cancelTmpl) cancelBtn.appendChild(cancelTmpl.content.cloneNode(true));
        else cancelBtn.innerHTML = '✖';

        const input = Object.assign(document.createElement('input'), {
            type: 'text',
            value: originalText === 'Aufnahme benennen' ? '' : originalText,
            className: 'title-edit-input',
            placeholder: 'Name der Aufnahme...',
            maxLength: 35,
            onclick: (e) => e.stopPropagation()
        });

        wrapper.appendChild(input);
        wrapper.appendChild(confirmBtn);
        wrapper.appendChild(cancelBtn);

        const originalContent = titleContainer.innerHTML;
        titleContainer.innerHTML = '';
        titleContainer.appendChild(wrapper);

        input.focus();
        input.select();

        const save = () => {
            const newTitle = input.value.trim() || 'Aufnahme benennen';
            titleContainer.innerHTML = newTitle;
            if (newTitle !== 'Aufnahme benennen') {
                this.customRecordingTitle = newTitle;
            }
            const btn = document.createElement('button');
            btn.className = 'edit-title-btn';
            btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
            btn.onclick = () => this.editLiveRecordingTitle();
            titleContainer.appendChild(btn);
        };

        confirmBtn.onclick = (e) => {
            e.stopPropagation();
            save();
        };

        cancelBtn.onclick = (e) => {
            e.stopPropagation();
            titleContainer.innerHTML = originalContent;
            const btn = titleContainer.querySelector('.edit-title-btn');
            if (btn) btn.onclick = () => this.editLiveRecordingTitle();
        };

        input.onkeydown = (e) => {
            if (e.key === 'Enter') confirmBtn.click();
            if (e.key === 'Escape') cancelBtn.click();
        };
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
                deviceSelect.innerHTML = '<option value="">Mikrofonzugriff nicht unterstützt</option>';
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
                deviceSelect.innerHTML = '<option value="">Mikrofone nicht verfügbar</option>';
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
            this.app.state.liveRecordingError = 'Mikrofonberechtigung verweigert: ' + error.message;
            this.app.state.liveRecordingStatus = 'idle';
            this.updateLiveRecordingUI();
        }
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

            if (this.currentLiveTab === 'live-transcript' && this.app.state.liveTranscriptMode === 'openai') {
                // Use OpenAI Realtime
                window.RealtimeTranscription.onTextUpdate = (text) => {
                    const previewText = document.getElementById('live-transcript-preview-text');
                    if (previewText) {
                        if (previewText.innerHTML.includes('Beispieltext')) {
                            previewText.innerHTML = '';
                        }
                        previewText.innerHTML += text;
                        // Scroll to bottom
                        const card = document.getElementById('live-transcript-preview-card');
                        if (card) card.scrollTop = card.scrollHeight;
                    }
                };

                await window.RealtimeTranscription.start();
                this.app.state.liveRecordingStatus = 'recording';
                this.app.state.liveRecordingStartedAt = Date.now();
                this.updateLiveRecordingUI();
                this.startRecordingTimer();
                return;
            }

            const constraints = {
                audio: {
                    deviceId: this.app.state.liveSelectedDeviceId
                        ? { exact: this.app.state.liveSelectedDeviceId }
                        : undefined
                }
            };

            this.app.state.liveMediaStream = await navigator.mediaDevices.getUserMedia(constraints);
            this.app.state.liveRecorder = new MediaRecorder(this.app.state.liveMediaStream);
            this.app.state.liveAudioChunks = [];
            this.app.state.liveRecordingStatus = 'recording';
            this.app.state.liveRecordingStartedAt = Date.now();

            this.app.state.liveRecorder.ondataavailable = (event) => {
                this.app.state.liveAudioChunks.push(event.data);
            };

            this.app.state.liveRecorder.start();
            this.updateLiveRecordingUI();
            this.startRecordingTimer();
        } catch (error) {
            console.error('Error starting recording:', error);
            this.app.state.liveRecordingError = error.message || 'Fehler beim Starten der Aufnahme';
            this.app.state.liveRecordingStatus = 'idle';
            this.updateLiveRecordingUI();
        }
    }

    async stopLiveRecording() {
        if (this.currentLiveTab === 'live-transcript' && this.app.state.liveTranscriptMode === 'openai') {
            window.RealtimeTranscription.stop();
            this.app.state.liveRecordingStatus = 'idle';
            clearInterval(this.app.state.liveRecordingTimer);
            this.updateLiveRecordingUI();
            return;
        }

        if (!this.app.state.liveRecorder || this.app.state.liveRecordingStatus !== 'recording') {
            return;
        }

        return new Promise((resolve, reject) => {
            try {
                this.app.state.liveRecorder.onstop = () => {
                    const audioBlob = new Blob(this.app.state.liveAudioChunks, { type: 'audio/wav' });
                    this.app.state.liveRecordedFile = new File(
                        [audioBlob],
                        'live-recording.wav',
                        { type: 'audio/wav' }
                    );
                    this.app.state.liveRecordedFileUrl = URL.createObjectURL(audioBlob);

                    this.app.state.liveMediaStream.getTracks().forEach(track => track.stop());
                    this.app.state.liveRecordingStatus = 'idle';
                    this.updateLiveRecordingUI();

                    // Automatically switch to file view and handle the recorded file
                    if (this.app.state.liveRecordedFile) {
                        const file = this.app.state.liveRecordedFile;
                        // Use custom title if set
                        if (this.customRecordingTitle) {
                            file.customName = this.customRecordingTitle;
                        }
                        
                        this.app.ui.switchTranscriptView('file');
                        setTimeout(() => {
                            this.app.ui.handleFileSelect([file]);
                        }, 300);
                    }
                    resolve();
                };

                this.app.state.liveRecorder.stop();
                clearInterval(this.app.state.liveRecordingTimer);
            } catch (error) {
                console.error('Error stopping recording:', error);
                this.app.state.liveRecordingError = 'Fehler beim Beenden der Aufnahme';
                reject(error);
            }
        });
    }

    startRecordingTimer() {
        this.app.state.liveRecordingTimer = setInterval(() => {
            if (this.app.state.liveRecordingStartedAt) {
                const elapsed = Math.floor((Date.now() - this.app.state.liveRecordingStartedAt) / 1000);
                const minutes = Math.floor(elapsed / 60).toString().padStart(2, '0');
                const seconds = (elapsed % 60).toString().padStart(2, '0');

                const timerElement = document.getElementById('live-record-timer');
                if (timerElement) {
                    timerElement.textContent = `${minutes}:${seconds}`;
                }
            }
        }, 1000);
    }

    updateLiveRecordingUI() {
        const status = this.app.state.liveRecordingStatus || 'idle';
        const startBtn = document.getElementById('live-record-start-btn');
        const pauseBtn = document.getElementById('live-record-pause-btn');
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
        const timer = document.getElementById('live-record-timer');
        const recordedFile = this.app.state.liveRecordedFile;
        const recordingError = this.app.state.liveRecordingError;
        const microphoneReady = this.app.state.liveMicrophonePermissionGranted;

        const elapsedSeconds = this.getLiveRecordingElapsedSeconds();
        if (timer) timer.textContent = this.formatLiveRecordingTime(elapsedSeconds);

        if (startBtn) {
            startBtn.disabled = status === 'stopping' || status === 'requesting';
            startBtn.style.opacity = status === 'stopping' || status === 'requesting' ? '0.7' : '';
            startBtn.innerHTML = status === 'recording'
                ? '<div style="width: 10px; height: 10px; background: white; border-radius: 2px;"></div>Aufnahme stoppen'
                : status === 'requesting'
                    ? '<div style="width: 10px; height: 10px; background: white; border-radius: 50%;"></div>Mikrofon freigeben'
                    : '<div style="width: 10px; height: 10px; background: white; border-radius: 50%;"></div>Aufnahme starten';
            
            if (status === 'recording') {
                startBtn.style.background = '#0f172a';
            } else {
                startBtn.style.background = '#dc2626';
            }
        }

        if (pauseBtn) {
            pauseBtn.disabled = true;
            pauseBtn.style.cursor = 'not-allowed';
            pauseBtn.style.opacity = '0.8';
        }

        if (card) {
            card.style.borderColor = status === 'recording' ? '#ef4444' : '#e2e8f0';
        }

        if (iconWrap) {
            iconWrap.style.background = status === 'recording' ? '#fee2e2' : '#eff6ff';
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

        let titleText = 'Starten Sie Ihre Aufnahme';
        let statusTextValue = 'Wählen Sie unten ein Mikrofon aus und drücken Sie Aufnahme starten.';

        if (status === 'recording') {
            titleText = 'Aufnahme läuft';
            statusTextValue = 'Das ausgewählte Mikrofon wird lokal im Browser aufgenommen.';
        } else if (status === 'requesting') {
            titleText = 'Mikrofonfreigabe';
            statusTextValue = 'Bitte erlaube den Mikrofonzugriff in der Browser-Abfrage.';
        } else if (status === 'stopping') {
            titleText = 'Aufnahme wird beendet';
            statusTextValue = 'Die Audiodatei wird vorbereitet.';
        } else if (recordingError) {
            titleText = 'Aufnahme nicht möglich';
            statusTextValue = recordingError;
        } else if (recordedFile) {
            titleText = 'Aufnahme bereit';
            statusTextValue = `${recordedFile.name} (${this.formatFileSize(recordedFile.size)})`;
        } else if (microphoneReady) {
            titleText = 'Mikrofon bereit';
            statusTextValue = 'Wählen Sie ein Eingabegerät aus und starten Sie die Aufnahme.';
        }

        if (title) title.textContent = titleText;
        if (titleSidebar) titleSidebar.textContent = titleText;
        if (text) {
            text.textContent = statusTextValue;
            text.style.color = recordingError ? '#dc2626' : '#94a3b8';
        }
        if (textSidebar) {
            textSidebar.textContent = statusTextValue;
            textSidebar.style.color = recordingError ? '#dc2626' : '#94a3b8';
        }
    }

    getLiveRecordingElapsedSeconds() {
        if (this.app.state.liveRecordingStatus !== 'recording' && this.app.state.liveRecordingDurationSeconds) {
            return this.app.state.liveRecordingDurationSeconds;
        }
        if (!this.app.state.liveRecordingStartedAt) return 0;
        return Math.max(0, Math.floor((Date.now() - this.app.state.liveRecordingStartedAt) / 1000));
    }

    formatLiveRecordingTime(totalSeconds) {
        const minutes = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
        const seconds = Math.floor(totalSeconds % 60).toString().padStart(2, '0');
        return `${minutes}:${seconds}`;
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

        const fontSize = this.app.state.liveTranscriptFontSize || 18;
        const isInverted = Boolean(this.app.state.liveTranscriptContrastInverted);
        const isMaximized = Boolean(this.app.state.liveTranscriptMaximized);

        if (sizeSlider) sizeSlider.value = fontSize;
        if (sizeValue) sizeValue.textContent = `${fontSize}px`;
        if (previewText) previewText.style.setProperty('--live-font-size', `${fontSize}px`);
        
        if (previewCard) {
            previewCard.classList.toggle('contrast-inverted', isInverted);
        }

        if (contrastToggle) {
            contrastToggle.checked = isInverted;
        }

        document.body.classList.toggle('live-transcript-maximized-active', isMaximized);
        if (maximizeBtn) {
            maximizeBtn.setAttribute('aria-pressed', isMaximized);
            maximizeBtn.title = isMaximized ? 'Textansicht minimieren' : 'Textansicht maximieren';
        }
    }
}
