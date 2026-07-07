/**
 * HAWKI Real-time Transcription Module
 * Uses OpenAI WebRTC protocol for zero-latency streaming.
 */

class RealtimeTranscription {
    constructor() {
        this.peerConnection = null;
        this.dataChannel = null;
        this.mediaStream = null;
        this.isRecording = false;
        this.onTextUpdate = null;
    }

    async start() {
        try {
            const deviceId = document.getElementById('live-input-device-select')?.value || '';
            const audioConstraints = deviceId ? { deviceId: { exact: deviceId } } : true;
            this.mediaStream = await navigator.mediaDevices.getUserMedia({ audio: audioConstraints });

            // Enumerate devices now that permission is granted
            if (typeof window.initializeLiveAudioDevices === 'function') {
                window.initializeLiveAudioDevices();
            }

            this.peerConnection = new RTCPeerConnection();

            this.dataChannel = this.peerConnection.createDataChannel('oai-events');
            this.setupDataChannelHandlers();

            this.mediaStream.getTracks().forEach(track => {
                this.peerConnection.addTrack(track, this.mediaStream);
            });

            const sessionRes = await fetch('/req/transcription/realtime/session', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            });

            if (!sessionRes.ok) {
                const sessionErr = await sessionRes.json();
                throw new Error(sessionErr.error || 'Failed to create realtime session.');
            }

            const sessionData = await sessionRes.json();
            const ephemeralKey = sessionData.value;

            const offer = await this.peerConnection.createOffer();
            await this.peerConnection.setLocalDescription(offer);

            const response = await fetch('https://api.openai.com/v1/realtime/calls', {
                method: 'POST',
                headers: {
                    'Authorization': `Bearer ${ephemeralKey}`,
                    'Content-Type': 'application/sdp',
                },
                body: offer.sdp,
            });

            if (!response.ok) {
                const errText = await response.text();
                throw new Error('OpenAI Realtime API Error: ' + errText);
            }

            const answerSdp = await response.text();
            await this.peerConnection.setRemoteDescription({
                type: 'answer',
                sdp: answerSdp,
            });

            this.isRecording = true;

        } catch (error) {
            console.error('Failed to start real-time transcription:', error);
            this.stop();
            throw error;
        }
    }

    stop() {
        if (this.dataChannel) {
            this.dataChannel.close();
            this.dataChannel = null;
        }
        if (this.peerConnection) {
            this.peerConnection.close();
            this.peerConnection = null;
        }
        if (this.mediaStream) {
            this.mediaStream.getTracks().forEach(track => track.stop());
            this.mediaStream = null;
        }
        this.isRecording = false;
    }

    setupDataChannelHandlers() {
        this.dataChannel.onmessage = (event) => {
            const data = JSON.parse(event.data);

            if (data.type === 'conversation.item.input_audio_transcription.delta') {
                if (this.onTextUpdate) this.onTextUpdate(data.delta ?? '');
                return;
            }

            if (data.type === 'conversation.item.input_audio_transcription.completed') {
                const text = data.transcript ?? '';
                if (text && this.onTextUpdate) this.onTextUpdate(text + ' ');
                return;
            }

            // Fallback: completed item may carry transcript in content[]
            if (data.type === 'conversation.item.done') {
                const contents = data.item?.content ?? [];
                for (const part of contents) {
                    const text = part.transcript ?? part.text ?? '';
                    if (text && this.onTextUpdate) this.onTextUpdate(text + ' ');
                }
            }
        };

        this.dataChannel.onopen = () => {
            this.sendSessionUpdate();
        };
    }

    // Enable streaming transcription. The `onopen` event can fire a tick before
    // readyState flips to 'open', so guard the send and retry briefly if needed.
    sendSessionUpdate(attempt = 0) {
        const channel = this.dataChannel;
        if (!channel) return;

        if (channel.readyState !== 'open') {
            if (attempt < 20) {
                setTimeout(() => this.sendSessionUpdate(attempt + 1), 50);
            } else {
                console.error('Data channel never reached "open" state; transcription not enabled.');
            }
            return;
        }

        try {
            channel.send(JSON.stringify({
                type: 'session.update',
                session: {
                    type: 'transcription',
                    audio: {
                        input: {
                            transcription: {
                                model: 'gpt-realtime-whisper',
                            },
                        },
                    },
                },
            }));
        } catch (error) {
            console.error('Failed to send session.update:', error);
        }
    }
}

window.RealtimeTranscription = new RealtimeTranscription();

function stopRealtimeTranscription() {
    if (!window.RealtimeTranscription.isRecording) return;
    window.RealtimeTranscription.stop();
    const group = document.getElementById('realtime-transcription-group');
    const indicator = document.getElementById('realtime-typing-indicator');
    if (group) { group.classList.remove('active'); group.classList.remove('connecting'); }
    if (indicator) indicator.classList.remove('visible');
}

document.addEventListener('click', function(e) {
    if (e.target.closest('#send-btn')) stopRealtimeTranscription();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey && e.target.classList.contains('input-field')) {
        stopRealtimeTranscription();
    }
});

window.toggleRealtimeTranscription = async function(_btn) {
    const inputField = document.querySelector('.input-field');
    const group = document.getElementById('realtime-transcription-group');
    const indicator = document.getElementById('realtime-typing-indicator');

    if (window.RealtimeTranscription.isRecording) {
        stopRealtimeTranscription();
    } else {
        // Close device dropdown before starting
        const dropdown = document.getElementById('realtime-device-dropdown');
        if (dropdown) dropdown.style.display = 'none';

        try {
            if (group) group.classList.add('connecting');

            window.RealtimeTranscription.onTextUpdate = (text) => {
                if (inputField) {
                    inputField.value += text;
                    if (typeof resizeInputField === 'function') {
                        resizeInputField(inputField);
                    }
                }
            };

            await window.RealtimeTranscription.start();

            if (group) {
                group.classList.remove('connecting');
                group.classList.add('active');
            }
            if (indicator) indicator.classList.add('visible');
        } catch (error) {
            if (group) {
                group.classList.remove('connecting');
                group.classList.remove('active');
            }
            if (indicator) indicator.classList.remove('visible');
            console.error('Realtime transcription error:', error);
            const isPermissionError = error?.name === 'NotAllowedError' || error?.name === 'PermissionDeniedError';
            alert(isPermissionError
                ? 'Microphone permission denied. Please allow microphone access and try again.'
                : 'Realtime transcription failed: ' + (error?.message ?? error));
        }
    }
};

window.toggleRealtimeDeviceDropdown = function() {
    const dropdown = document.getElementById('realtime-device-dropdown');
    if (!dropdown) return;

    const isVisible = dropdown.style.display !== 'none';
    dropdown.style.display = isVisible ? 'none' : 'block';

    // Populate devices when opening for the first time
    if (!isVisible && typeof window.initializeLiveAudioDevices === 'function') {
        window.initializeLiveAudioDevices();
    }
};

// Close device dropdown when clicking outside
document.addEventListener('click', function(e) {
    const outer = document.getElementById('realtime-transcription-outer');
    const dropdown = document.getElementById('realtime-device-dropdown');
    if (dropdown && outer && !outer.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});
