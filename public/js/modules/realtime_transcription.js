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
        // A start() or stop() in flight - toggles arriving meanwhile are
        // ignored / joined instead of opening a second session or overwriting
        // the first stop's drain callback.
        this.starting = false;
        this.stopPromise = null;
        this.onTextUpdate = null;
        this.mode = 'onprem';
        this.chatProvider = null;
        // item_ids committed for transcription but not yet resolved — see
        // stop()'s drain wait below.
        this.pendingItemIds = new Set();
        this.onPendingItemsCleared = null;
        // segment(): resolves when the item that was open at the request has
        // its final transcript (the bridge sends every event of the next item
        // after that item's completed).
        this.onSegmentSealed = null;
        // Called when the connection drops while recording (not on stop()).
        this.onConnectionLost = null;
        // item_id -> text already delivered via delta events, so the
        // completed event only appends what the deltas didn't cover.
        this.deliveredDeltaText = new Map();
    }

    // deviceId: '' = the browser's default input. Callers that own a device
    // select pass its value; without one the transcript page's select is read.
    async start(mode = 'onprem', deviceId = undefined) {
        this.mode = mode;
        try {
            if (deviceId === undefined) {
                deviceId = document.getElementById('live-input-device-select')?.value || '';
            }
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

            // Only a connected peer connection carries audio. Reporting
            // "recording" right after the SDP exchange showed an active mic
            // while ICE/DTLS never completed, and the user waited on a session
            // that could not deliver anything.
            await this.waitForConnection();
            this.watchConnection();

            this.isRecording = true;

        } catch (error) {
            console.error('Failed to start real-time transcription:', error);
            await this.teardown();
            throw error;
        }
    }

    // A dropped connection (network, bridge restart, an older bridge closing
    // after a keep_open commit) must not leave a mic that looks open. Only
    // acts for owners that registered onConnectionLost - the transcript page
    // records locally from the same stream and handles its own state.
    watchConnection() {
        const pc = this.peerConnection;
        const dc = this.dataChannel;
        const lost = () => {
            if (pc !== this.peerConnection || !this.isRecording || this.stopPromise || !this.onConnectionLost) return;
            console.warn('Realtime connection lost, closing the voice input');
            const notify = this.onConnectionLost;
            this.onConnectionLost = null;
            this.teardown().then(() => notify());
        };
        pc.addEventListener('connectionstatechange', () => {
            if (pc.connectionState === 'failed' || pc.connectionState === 'closed') lost();
        });
        dc?.addEventListener('close', lost);
    }

    // Ends the current transcript segment but keeps recording: used when a
    // message is sent with the mic open. Resolves once the segment's final
    // text has been delivered through onTextUpdate.
    segment({ timeoutMs = 20000 } = {}) {
        const channel = this.dataChannel;
        if (!this.isRecording || !channel || channel.readyState !== 'open') return Promise.resolve();

        if (this.mode !== 'onprem') {
            // OpenAI's server VAD commits turns on its own; wait only for
            // turns already in flight.
            if (this.pendingItemIds.size === 0) return Promise.resolve();
            return new Promise(resolve => {
                let timer = null;
                const finish = () => {
                    clearTimeout(timer);
                    if (this.onPendingItemsCleared === finish) this.onPendingItemsCleared = null;
                    resolve();
                };
                timer = setTimeout(finish, 5000);
                this.onPendingItemsCleared = finish;
            });
        }

        return new Promise(resolve => {
            let timer = null;
            const finish = () => {
                clearTimeout(timer);
                if (this.onSegmentSealed === finish) this.onSegmentSealed = null;
                resolve();
            };
            timer = setTimeout(finish, timeoutMs);
            this.onSegmentSealed = finish;
            try {
                channel.send(JSON.stringify({ type: 'input_audio_buffer.commit', keep_open: true }));
            } catch (error) {
                console.error('Failed to send segment commit:', error);
                finish();
            }
        });
    }

    // Resolves once the peer connection is connected; rejects when it fails
    // or does not get there within timeoutMs.
    waitForConnection(timeoutMs = 15000) {
        const pc = this.peerConnection;
        if (pc.connectionState === 'connected') return Promise.resolve();

        return new Promise((resolve, reject) => {
            let done = false;
            const finish = (error) => {
                if (done) return;
                done = true;
                pc.removeEventListener('connectionstatechange', onChange);
                clearTimeout(timer);
                error ? reject(error) : resolve();
            };
            const onChange = () => {
                if (pc.connectionState === 'connected') finish();
                else if (pc.connectionState === 'failed' || pc.connectionState === 'closed') {
                    finish(new Error('Audio connection ' + pc.connectionState + '.'));
                }
            };
            pc.addEventListener('connectionstatechange', onChange);
            const timer = setTimeout(() => {
                finish(new Error('Audio connection not established within ' + Math.round(timeoutMs / 1000) + 's.'));
            }, timeoutMs);
        });
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
    stop(options = {}) {
        // A second stop() while the first drains must not replace its
        // callback (that left the first waiting for its full timeout) - join it.
        if (!this.stopPromise) {
            this.stopPromise = this.doStop(options).finally(() => { this.stopPromise = null; });
        }
        return this.stopPromise;
    }

    async doStop({ drainTimeoutMs = 20000 } = {}) {
        // On-prem: the bridge only finalizes (final upstream commit → full
        // transcript) when asked — tell it before waiting for the result. It
        // always answers a commit with completed or failed, so wait for that
        // rather than for a "committed" we may not have received yet.
        let committed = false;
        if (this.mode === 'onprem' && this.dataChannel && this.dataChannel.readyState === 'open') {
            try {
                this.dataChannel.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));
                committed = true;
            } catch (error) {
                console.error('Failed to send finalize commit:', error);
            }
        }

        if (this.mode === 'onprem' && (committed || this.pendingItemIds.size > 0)) {
            await new Promise(resolve => {
                const timeoutId = setTimeout(resolve, drainTimeoutMs);
                this.onPendingItemsCleared = () => {
                    clearTimeout(timeoutId);
                    resolve();
                };
            });
            this.onPendingItemsCleared = null;
        }

        await this.teardown();
    }

    // Mutes the microphone without ending the session (silence is sent).
    setMuted(muted) {
        this.mediaStream?.getAudioTracks().forEach(track => { track.enabled = !muted; });
    }

    // Releases the connection and the microphone without finalizing.
    async teardown() {
        // Nobody may keep waiting for results of a closed connection.
        const waiters = [this.onPendingItemsCleared, this.onSegmentSealed];
        this.onPendingItemsCleared = null;
        this.onSegmentSealed = null;
        waiters.forEach(fn => fn && fn());

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
            if (this.onSegmentSealed) this.onSegmentSealed();
            return;
        }

        if (data.type === 'conversation.item.input_audio_transcription.failed') {
            console.error('Transcription failed for item', data.item_id, data.error);
            if (data.item_id) this.deliveredDeltaText.delete(data.item_id);
            this.resolvePendingItem(data.item_id);
            if (this.onSegmentSealed) this.onSegmentSealed();
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
        // The bridge sends one completed/failed per commit; an item we never
        // saw "committed" for still ends the drain wait.
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

// ---------------------------------------------------------------- chat UI
// Behaviour in the chat (KI-772):
//  - mic click: spinner until the audio connection is up, then the mic is open
//  - sending (button or Enter) with the mic open sends the transcript so far
//    and keeps the mic open - the user can go on speaking while the model
//    answers, the words go into the next message
//  - voice chat: the answer to such a message is read aloud through its speak
//    button (toggle "Antworten vorlesen" in the mic dropdown, on by default);
//    while anything is read aloud the mic is muted, so the open mic does not
//    transcribe the answer into the next message
//  - the mic closes on its icon, on switching / starting / deleting a chat,
//    on leaving the page, and when the connection drops
//
// The voice input sits in the main chat input and in every thread input (the
// thread template includes the same input field), so nothing here may look
// elements up page-wide: from the second message on, the first match in the
// document is a hidden thread copy. Everything resolves from the clicked
// button; the elements of the running session are kept in `chatUi`.
let chatUi = null;
let voiceSend = false;          // a send with the mic open is in progress
let sendAfterStop = false;      // a send waits for a closing mic to finish
let bypassSendIntercept = false;

function voiceInputElements(btn) {
    const input = btn?.closest('.input');
    const outer = btn?.closest('.realtime-transcription-outer');
    if (!input || !outer) return null;
    return {
        inputField: input.querySelector('.input-field'),
        group: outer.querySelector('.realtime-transcription-group'),
        indicator: input.querySelector('.realtime-typing-indicator'),
        dropdown: outer.querySelector('.realtime-device-dropdown'),
        deviceSelect: outer.querySelector('.realtime-device-select'),
    };
}

function setVoiceInputState(ui, state) {
    if (!ui) return;
    ui.group?.classList.toggle('connecting', state === 'connecting');
    ui.group?.classList.toggle('active', state === 'active');
    ui.indicator?.classList.toggle('visible', state === 'active');
}

function appendTranscript(ui, text) {
    if (!ui?.inputField || !text) return;
    ui.inputField.value += text;
    if (typeof resizeInputField === 'function') {
        resizeInputField(ui.inputField);
    }
}

// Closes the mic on its icon: the words still being transcribed go into the
// field (not sent).
async function stopRealtimeTranscription() {
    const rt = window.RealtimeTranscription;
    if (!rt.isRecording) return;
    const ui = chatUi;
    rt.onConnectionLost = null;
    stopReadAloudWatch();
    // The spinner covers the finalize wait, so a stop never looks like a
    // dead button.
    setVoiceInputState(ui, 'connecting');
    await rt.stop();
    setVoiceInputState(ui, 'idle');
    if (chatUi === ui) chatUi = null;
}

// Closes the mic at once, without finishing the transcript: the chat it
// belongs to is going away.
function closeVoiceInputNow() {
    const rt = window.RealtimeTranscription;
    const ui = chatUi;
    if (!ui) return;
    chatUi = null;
    rt.onTextUpdate = null;
    rt.onConnectionLost = null;
    stopReadAloudWatch();
    awaitingVoiceAnswerSince = 0;
    // An answer read aloud for the chat that is going away stops with it.
    if (autoReadStarted && window.speechSynthesis?.speaking) window.speechSynthesis.cancel();
    autoReadStarted = false;
    setVoiceInputState(ui, 'idle');
    if (rt.isRecording || rt.stopPromise) rt.teardown();
}

// Only a send goes through the voice input. The same button aborts a running
// answer, and that must neither wait nor touch the open mic.
function sendButtonSends() {
    const status = typeof getSendBtnStat === 'function' ? getSendBtnStat() : undefined;
    return status !== 'stoppable' && status !== 'loading';
}

function clickSend(input) {
    bypassSendIntercept = true;
    try {
        input?.querySelector('#send-btn')?.click();
    } finally {
        bypassSendIntercept = false;
    }
}

// The send flows read the text synchronously on click but clear the field
// only after the message was accepted. Resolves once that happened (or the
// send was refused, or nothing was sent).
function waitForSendToClear(field, sent, timeoutMs = 30000) {
    return new Promise(resolve => {
        if (!field) return resolve();
        const started = Date.now();
        let sawBusy = false;
        const tick = () => {
            const status = typeof getSendBtnStat === 'function' ? getSendBtnStat() : undefined;
            if (status === 'loading' || status === 'stoppable') sawBusy = true;
            if (field.value === '' || field.value !== sent) return resolve();
            if (sawBusy && status === 'sendable') return resolve();   // refused, text kept
            if (Date.now() - started > timeoutMs) return resolve();
            setTimeout(tick, 100);
        };
        tick();
    });
}

// Send with the mic open: finish the current segment, send it, and route
// what is said from now on into the (then cleared) field.
async function sendWithOpenMic(input) {
    const rt = window.RealtimeTranscription;
    const ui = chatUi;
    if (voiceSend || !ui) return;
    voiceSend = true;
    let held = '';
    try {
        setVoiceInputState(ui, 'connecting');
        await rt.segment();
        // No await between here and the click: the next segment's text must
        // not reach the field the send is about to read.
        rt.onTextUpdate = (text) => { held += text; };
        const field = input?.querySelector('.input-field');
        const sent = field?.value ?? '';
        if (sent.trim() !== '') awaitingVoiceAnswerSince = Date.now();
        clickSend(input);
        await waitForSendToClear(field, sent);
    } finally {
        voiceSend = false;
        if (chatUi === ui) {
            rt.onTextUpdate = (text) => appendTranscript(ui, text);
            appendTranscript(ui, held);
            setVoiceInputState(ui, rt.isRecording ? 'active' : 'idle');
        }
    }
}

// Send while the mic is closing: wait for its last words, then send.
async function sendAfterMicClosed(input) {
    if (sendAfterStop) return;
    sendAfterStop = true;
    try {
        await window.RealtimeTranscription.stopPromise;
    } finally {
        sendAfterStop = false;
    }
    clickSend(input);
}

// Returns true when the voice input took over the send.
function routeSend(input) {
    const rt = window.RealtimeTranscription;
    if (!chatUi) return false;
    if (voiceSend || sendAfterStop) return true;               // one send at a time
    if (rt.stopPromise) { sendAfterMicClosed(input); return true; }
    if (rt.isRecording) { sendWithOpenMic(input); return true; }
    return false;                                              // still connecting
}

// Capture phase: runs before the button's own onclick and the textarea's
// onkeypress, which would send immediately.
document.addEventListener('click', function(e) {
    if (bypassSendIntercept) return;
    const btn = e.target.closest('#send-btn');
    if (!btn || !sendButtonSends()) return;
    if (routeSend(btn.closest('.input'))) {
        e.preventDefault();
        e.stopPropagation();
    }
}, true);

document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter' || e.shiftKey || !e.target.classList?.contains('input-field')) return;
    if (!sendButtonSends()) return;
    // A cancelled keydown suppresses the keypress that sends the message.
    if (routeSend(e.target.closest('.input'))) e.preventDefault();
}, true);

// ---------------------------------------------------------- voice chat
const READ_ALOUD_KEY = 'hawki.voiceReadAloud';
const VOICE_ANSWER_WINDOW_MS = 10 * 60 * 1000;
let readAloudFallback = true;       // when the browser storage is unavailable
let awaitingVoiceAnswerSince = 0;   // a message was sent with the mic open
let autoReadStarted = false;
let readAloudWatch = null;

function voiceReadAloudEnabled() {
    try {
        const stored = localStorage.getItem(READ_ALOUD_KEY);
        return stored === null ? readAloudFallback : stored !== 'false';
    } catch (e) {
        return readAloudFallback;
    }
}

function syncReadAloudToggles() {
    const on = voiceReadAloudEnabled();
    document.querySelectorAll('.realtime-read-aloud-toggle').forEach(box => { box.checked = on; });
}

window.setVoiceReadAloud = function(on) {
    readAloudFallback = !!on;
    try { localStorage.setItem(READ_ALOUD_KEY, on ? 'true' : 'false'); } catch (e) { /* per-page fallback */ }
    if (!on) awaitingVoiceAnswerSince = 0;
    syncReadAloudToggles();
};

// Speech synthesis plays outside the browser's echo cancellation, so an open
// mic would pick the answer up. Mute it while anything is read aloud - the
// auto-read answer or a speak button clicked by hand; stopping the read-aloud
// (its button) unmutes the mic.
function startReadAloudWatch() {
    stopReadAloudWatch();
    readAloudWatch = setInterval(() => {
        const rt = window.RealtimeTranscription;
        if (!chatUi || !rt.isRecording) return;
        const speaking = !!window.speechSynthesis?.speaking;
        rt.setMuted(speaking);
        if (!speaking) autoReadStarted = false;
    }, 200);
}

function stopReadAloudWatch() {
    clearInterval(readAloudWatch);
    readAloudWatch = null;
    window.RealtimeTranscription.setMuted(false);
}

// The first answer completed after a voice send is read aloud, as long as the
// mic is still open and the toggle is on.
document.addEventListener('hawki:ai-answer-done', (event) => {
    const since = awaitingVoiceAnswerSince;
    if (!since) return;
    awaitingVoiceAnswerSince = 0;
    if (Date.now() - since > VOICE_ANSWER_WINDOW_MS || !chatUi || !voiceReadAloudEnabled()) return;
    const speakBtn = event.detail?.messageElement?.querySelector('#speak-btn');
    if (!speakBtn || typeof messageReadAloud !== 'function') return;
    autoReadStarted = true;
    messageReadAloud(speakBtn);
});

window.toggleRealtimeTranscription = async function(btn) {
    const rt = window.RealtimeTranscription;

    // Nothing to toggle while a session is being set up, drained or used
    // for a send.
    if (rt.starting || rt.stopPromise || voiceSend || sendAfterStop) return;

    if (rt.isRecording) {
        stopRealtimeTranscription();
        return;
    }

    const ui = voiceInputElements(btn);
    if (!ui) return;
    if (ui.dropdown) ui.dropdown.style.display = 'none';

    rt.starting = true;
    try {
        chatUi = ui;
        setVoiceInputState(ui, 'connecting');
        rt.onTextUpdate = (text) => appendTranscript(ui, text);
        rt.onConnectionLost = () => {
            if (chatUi === ui) chatUi = null;
            stopReadAloudWatch();
            setVoiceInputState(ui, 'idle');
        };

        // Chat voice input routes through the admin-configured provider
        // (transcription setting `chat_realtime_provider`, default: the
        // on-prem realtime bridge).
        const provider = await rt.fetchChatProvider();
        await rt.start(provider, ui.deviceSelect?.value || '');

        if (chatUi !== ui) {
            // The chat was switched or left while connecting.
            await rt.teardown();
            return;
        }
        setVoiceInputState(ui, 'active');
        startReadAloudWatch();
    } catch (error) {
        setVoiceInputState(ui, 'idle');
        if (chatUi === ui) chatUi = null;
        rt.onConnectionLost = null;
        console.error('Realtime transcription error:', error);
        const isPermissionError = error?.name === 'NotAllowedError' || error?.name === 'PermissionDeniedError';
        alert(isPermissionError
            ? 'Microphone permission denied. Please allow microphone access and try again.'
            : 'Realtime transcription failed: ' + (error?.message ?? error));
    } finally {
        rt.starting = false;
    }
};

// Switching, starting or deleting a chat (conversation or room) always
// empties the chat log - one choke point for "the chat changed". The first
// send of a new chat empties it too (initNewConv), but that is the same chat.
function installChatChangeHook() {
    const original = window.clearChatlog;
    if (typeof original !== 'function' || original.__voiceInputHook) return;
    const hooked = function(...args) {
        if (chatUi && !voiceSend && !sendAfterStop) closeVoiceInputNow();
        return original.apply(this, args);
    };
    hooked.__voiceInputHook = true;
    window.clearChatlog = hooked;
}
function initVoiceInput() {
    installChatChangeHook();
    syncReadAloudToggles();
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initVoiceInput);
} else {
    initVoiceInput();
}
window.addEventListener('pagehide', closeVoiceInputNow);

window.toggleRealtimeDeviceDropdown = function(btn) {
    const dropdown = btn?.closest('.realtime-transcription-outer')?.querySelector('.realtime-device-dropdown');
    if (!dropdown) return;

    const isVisible = dropdown.style.display !== 'none';
    dropdown.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) syncReadAloudToggles();

    // Populate devices when opening
    if (!isVisible && typeof window.initializeLiveAudioDevices === 'function') {
        window.initializeLiveAudioDevices();
    }
};

// Close open device dropdowns when clicking outside their component
document.addEventListener('click', function(e) {
    document.querySelectorAll('.realtime-device-dropdown').forEach(dropdown => {
        const outer = dropdown.closest('.realtime-transcription-outer');
        if (dropdown.style.display !== 'none' && outer && !outer.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
});
