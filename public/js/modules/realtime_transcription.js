/**
 * HAWKI Real-time Transcription Module
 * Uses the OpenAI WebRTC realtime protocol for zero-latency streaming.
 * Supports two providers, selected by `mode`:
 *  - 'onprem': relays the SDP offer through the HAWKI backend to the
 *    realtime-bridge sidecar, which feeds the audio to the on-prem
 *    vLLM/Voxtral server (word-level streaming while speaking). The bridge
 *    emits the same OpenAI-style events this module already handles.
 *  - 'openai': mints an ephemeral key and negotiates directly with OpenAI.
 */

// ICE servers come from the server (meta[name="ice-servers"], populated from
// config/realtime_bridge.php). Without a TURN relay the browser may have no
// candidate that can reach the HAWKI host at all: on the JLU VPN Chrome gathers
// host candidates only on interfaces with no route to the internal server and
// never on the VPN tunnel, so every pair fails and the session hangs in
// "connecting". TURN sidesteps that because the browser reaches the relay over
// ordinary TCP routing rather than via ICE interface enumeration.
function buildRtcConfiguration() {
    const meta = document.querySelector('meta[name="ice-servers"]');
    let iceServers = [];
    try {
        iceServers = JSON.parse(meta?.getAttribute('content') || '[]');
    } catch (e) {
        console.warn('ice-servers meta is not valid JSON; falling back to host candidates only', e);
    }
    if (!Array.isArray(iceServers) || iceServers.length === 0) {
        console.warn('No ICE servers configured — relying on host candidates only.');
        return {};
    }
    console.log('Using ICE servers (on-prem path):', iceServers.map(s => s.urls).flat().join(', '));
    return { iceServers };
}

class RealtimeTranscription {
    constructor() {
        this.peerConnection = null;
        this.dataChannel = null;
        this.mediaStream = null;
        this.isRecording = false;
        this.onTextUpdate = null;
        this.mode = 'onprem';
        this.chatProvider = null;
        // item_ids committed for transcription but not yet resolved — see
        // stop()'s drain wait below.
        this.pendingItemIds = new Set();
        this.onPendingItemsCleared = null;
        // item_id -> text already delivered via delta events, so the
        // completed event only appends what the deltas didn't cover.
        this.deliveredDeltaText = new Map();
    }

    async start(mode = 'onprem') {
        this.mode = mode;
        try {
            const deviceId = document.getElementById('live-input-device-select')?.value || '';
            const audioConstraints = deviceId ? { deviceId: { exact: deviceId } } : true;
            this.mediaStream = await navigator.mediaDevices.getUserMedia({ audio: audioConstraints });

            // Enumerate devices now that permission is granted
            if (typeof window.initializeLiveAudioDevices === 'function') {
                window.initializeLiveAudioDevices();
            }

            // Our TURN relay applies to the on-prem path only. The OpenAI
            // realtime endpoint terminates media on OpenAI's own servers and
            // supplies its own ICE infrastructure; handing it our relay makes
            // Chrome try to reach OpenAI through coturn on the HAWKI host,
            // which has no route to it, and the session fails.
            this.peerConnection = new RTCPeerConnection(
                this.mode === 'openai' ? {} : buildRtcConfiguration()
            );

            this.dataChannel = this.peerConnection.createDataChannel('oai-events');
            this.setupDataChannelHandlers();

            this.mediaStream.getTracks().forEach(track => {
                this.peerConnection.addTrack(track, this.mediaStream);
            });

            const offer = await this.peerConnection.createOffer();
            await this.peerConnection.setLocalDescription(offer);

            // Wait for ICE gathering before signalling. `offer.sdp` is the
            // pre-gathering SDP and carries NO a=candidate lines: gathering only
            // starts at setLocalDescription and completes asynchronously. We do
            // not trickle (there is no candidate channel back to the bridge), and
            // the bridge is non-trickle too — it gathers fully before answering.
            // Sending offer.sdp therefore left the bridge with zero remote
            // candidates, so ICE never completed and the session hung in
            // "connecting" with no error. Send localDescription.sdp instead.
            await this.waitForIceGathering();

            const gatheredSdp = this.peerConnection.localDescription.sdp;

            const answerSdp = this.mode === 'openai'
                ? await this.negotiateOpenAi(gatheredSdp)
                : await this.negotiateOnPrem(gatheredSdp);

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

    // Resolves once ICE gathering is complete, so the SDP we signal contains
    // every host candidate. Bounded: if gathering stalls we proceed with
    // whatever was gathered rather than hanging the UI forever.
    waitForIceGathering(timeoutMs = 5000) {
        const pc = this.peerConnection;
        if (pc.iceGatheringState === 'complete') return Promise.resolve();

        return new Promise(resolve => {
            let done = false;
            const finish = () => {
                if (done) return;
                done = true;
                pc.removeEventListener('icegatheringstatechange', onChange);
                clearTimeout(timer);
                resolve();
            };
            const onChange = () => {
                if (pc.iceGatheringState === 'complete') finish();
            };
            pc.addEventListener('icegatheringstatechange', onChange);
            const timer = setTimeout(() => {
                console.warn('ICE gathering did not complete within', timeoutMs, 'ms; signalling partial candidates');
                finish();
            }, timeoutMs);
        });
    }

    async negotiateOpenAi(offerSdp) {
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

        const response = await fetch('https://api.openai.com/v1/realtime/calls', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${ephemeralKey}`,
                'Content-Type': 'application/sdp',
            },
            body: offerSdp,
        });

        if (!response.ok) {
            const errText = await response.text();
            throw new Error('OpenAI Realtime API Error: ' + errText);
        }

        return response.text();
    }

    // Which provider the chat voice input should use — admin-toggleable via
    // the `chat_realtime_provider` transcription setting. Cached for the page
    // lifetime; falls back to 'onprem' so a config hiccup never silently
    // routes audio to OpenAI.
    async fetchChatProvider() {
        if (this.chatProvider) return this.chatProvider;

        try {
            const response = await fetch('/req/transcription/realtime/config');
            const data = await response.json();
            this.chatProvider = data.provider === 'openai' ? 'openai' : 'onprem';
        } catch (error) {
            console.error('Failed to fetch realtime provider config, defaulting to onprem:', error);
            this.chatProvider = 'onprem';
        }

        return this.chatProvider;
    }

    // vLLM's realtime endpoint is WebSocket-only, which a browser can't
    // reach without exposing a gateway API key. The realtime-bridge sidecar
    // terminates the WebRTC connection server-side instead; the backend
    // relays the SDP offer/answer, keeping all credentials server-side.
    async negotiateOnPrem(offerSdp) {
        const response = await fetch('/req/transcription/realtime/onprem/signaling', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({ sdp: offerSdp }),
        });

        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Realtime bridge error.');
        }

        return data.sdp;
    }

    // Closing the peer connection immediately can drop a transcription
    // that's still being generated server-side: the bridge can't deliver the
    // final transcript once the data channel it was going to send over is
    // already closed. So stop() first asks the bridge to finalize, then
    // waits (with a timeout safety net, in case a result never arrives) for
    // any item committed but not yet resolved, THEN tears the connection
    // down.
    async stop({ drainTimeoutMs = 20000 } = {}) {
        // On-prem: the bridge only finalizes (final upstream commit → full
        // transcript) when asked — tell it before waiting for the result.
        if (this.mode === 'onprem' && this.dataChannel && this.dataChannel.readyState === 'open') {
            try {
                this.dataChannel.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));
            } catch (error) {
                console.error('Failed to send finalize commit:', error);
            }
        }

        if (this.mode === 'onprem' && this.pendingItemIds.size > 0) {
            await new Promise(resolve => {
                const timeoutId = setTimeout(resolve, drainTimeoutMs);
                this.onPendingItemsCleared = () => {
                    clearTimeout(timeoutId);
                    resolve();
                };
            });
            this.onPendingItemsCleared = null;
        }

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
        this.pendingItemIds.clear();
        this.deliveredDeltaText.clear();
    }

    setupDataChannelHandlers() {
        this.dataChannel.onmessage = (event) => {
            this.handleServerEvent(JSON.parse(event.data));
        };

        this.dataChannel.onopen = () => {
            this.sendSessionUpdate();
        };
    }

    handleServerEvent(data) {
        // Marks an item as awaiting transcription (sent by the bridge once
        // upstream generation starts) — see stop()'s drain wait, which needs
        // this to know when it's safe to close the connection without
        // dropping an in-flight result.
        if (data.type === 'input_audio_buffer.committed') {
            if (data.item_id) this.pendingItemIds.add(data.item_id);
            return;
        }

        if (data.type === 'conversation.item.input_audio_transcription.delta') {
            const delta = data.delta ?? '';
            if (data.item_id) {
                const prev = this.deliveredDeltaText.get(data.item_id) ?? '';
                this.deliveredDeltaText.set(data.item_id, prev + delta);
            }
            if (delta && this.onTextUpdate) this.onTextUpdate(delta);
            return;
        }

        if (data.type === 'conversation.item.input_audio_transcription.completed') {
            const text = data.transcript ?? '';
            const delivered = data.item_id ? (this.deliveredDeltaText.get(data.item_id) ?? '') : '';
            if (data.item_id) this.deliveredDeltaText.delete(data.item_id);
            // Deltas already streamed (some of) this transcript — only append
            // the missing tail. If the final transcript diverges from the
            // streamed text entirely, don't re-append it, that would duplicate.
            const remainder = text.startsWith(delivered) ? text.slice(delivered.length) : (delivered ? '' : text);
            if ((remainder || delivered) && this.onTextUpdate) this.onTextUpdate(remainder + ' ');
            this.resolvePendingItem(data.item_id);
            return;
        }

        if (data.type === 'conversation.item.input_audio_transcription.failed') {
            console.error('Transcription failed for item', data.item_id, data.error);
            if (data.item_id) this.deliveredDeltaText.delete(data.item_id);
            this.resolvePendingItem(data.item_id);
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
    }

    resolvePendingItem(itemId) {
        if (itemId) this.pendingItemIds.delete(itemId);
        if (this.pendingItemIds.size === 0 && this.onPendingItemsCleared) {
            this.onPendingItemsCleared();
        }
    }

    // Enable streaming transcription. The `onopen` event can fire a tick before
    // readyState flips to 'open', so guard the send and retry briefly if needed.
    sendSessionUpdate(attempt = 0) {
        // The realtime bridge manages the upstream vLLM session itself
        // (model validation, commit cadence) and ignores client
        // session.update events — nothing to configure from here.
        if (this.mode === 'onprem') return;

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

        const session = {
            type: 'transcription',
            audio: {
                input: {
                    transcription: {
                        model: 'gpt-realtime-whisper',
                    },
                },
            },
        };

        try {
            channel.send(JSON.stringify({ type: 'session.update', session }));
        } catch (error) {
            console.error('Failed to send session.update:', error);
        }
    }
}

window.RealtimeTranscription = new RealtimeTranscription();

async function stopRealtimeTranscription() {
    if (!window.RealtimeTranscription.isRecording) return;
    await window.RealtimeTranscription.stop();
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

            // Chat voice input routes through the admin-configured provider
            // (transcription setting `chat_realtime_provider`, default: the
            // on-prem realtime bridge).
            const provider = await window.RealtimeTranscription.fetchChatProvider();
            await window.RealtimeTranscription.start(provider);

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
