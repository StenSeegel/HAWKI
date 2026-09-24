"""HAWKI Realtime Bridge.

Bridges browser WebRTC connections to the vLLM realtime speech-to-text
WebSocket (reached through the LiteLLM gateway). Exists because vLLM's
realtime endpoint is WebSocket-only: a browser WebSocket cannot carry an
Authorization header, so any direct connection would require exposing a
gateway API key to the client. With this bridge, the browser speaks WebRTC
(authenticated once via HAWKI's backend SDP relay, media needs no
credential) and the gateway key stays server-side.

Protocol towards the browser (data channel "oai-events"): OpenAI Realtime
API event names, matching what HAWKI's realtime_transcription.js already
handles for the OpenAI and Speaches providers:
  -> input_audio_buffer.committed              (item registered, drain anchor)
  -> conversation.item.input_audio_transcription.delta
  -> conversation.item.input_audio_transcription.completed
  -> conversation.item.input_audio_transcription.failed
  <- input_audio_buffer.commit                 (client asks to finalize; the
                                                session closes afterwards)
  <- input_audio_buffer.commit {keep_open: true}
                                               (client sends a message but keeps
                                                the mic open: the current item is
                                                finalized, the next one starts at
                                                once on a fresh upstream stream;
                                                audio in between is buffered, and
                                                every event of the new item comes
                                                after the old item's completed)
  <- session.update                            (ignored; session is server-managed)

Protocol towards vLLM (see vllm/entrypoints/speech_to_text/realtime/):
  -> session.update {model}                    (mandatory model validation)
  -> input_audio_buffer.append {audio}         (base64 PCM16 @ 16 kHz mono)
  -> input_audio_buffer.commit {final: false}  (starts decoding, sent early)
  -> input_audio_buffer.commit {final: true}   (ends the stream)
  <- transcription.delta / transcription.done / error

Per-request configuration comes from HAWKI's backend via headers
(X-Gateway-Base, X-Gateway-Key, X-Model), so the single source of truth for
provider credentials remains HAWKI's database. The bridge itself holds no
secrets; BRIDGE_API_KEY (optional) merely fences the signaling endpoint.
"""

import asyncio
import base64
import json
import logging
import os
import uuid

import aiohttp
from aiohttp import web
from aiortc import (
    RTCConfiguration,
    RTCIceServer,
    RTCPeerConnection,
    RTCSessionDescription,
)
from av.audio.resampler import AudioResampler

logging.basicConfig(
    level=os.environ.get("LOG_LEVEL", "INFO").upper(),
    format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
)
logger = logging.getLogger("realtime-bridge")

TARGET_RATE = 16000
APPEND_CHUNK_BYTES = TARGET_RATE * 2 // 10  # 100 ms of PCM16 mono
# Decoding upstream only starts on the first (non-final) commit; send it as
# soon as a little audio has accumulated so deltas stream while speaking.
START_COMMIT_AFTER_BYTES = TARGET_RATE * 2 * 3 // 10  # 300 ms
DONE_TIMEOUT_S = 15.0

BRIDGE_API_KEY = os.environ.get("BRIDGE_API_KEY", "")

# ---------------------------------------------------------------------------
# ICE / TURN
# ---------------------------------------------------------------------------
# Host candidates alone only work when the browser can reach the HAWKI host
# directly on an arbitrary high port. On the university network that is not the
# case: only 22/80/443 pass the firewall to the staging host (verified
# 2026-08-13 — everything else times out rather than being refused), so ICE
# stalls at "connecting" and no audio ever arrives. A TURN server on a
# permitted port relays the media instead.
#
#   TURN_URLS               comma-separated, e.g.
#                           "turns:turn.example.org:443?transport=tcp"
#   TURN_USERNAME/PASSWORD  static credentials (or ephemeral ones minted upstream)
#   STUN_URLS               optional; pointless on a host with only a private IP
#
# Note: there is deliberately no relay-only switch. aiortc 1.15.0's
# RTCConfiguration exposes only iceServers/bundlePolicy/alwaysNegotiateDataChannels
# — no iceTransportPolicy (aioice supports transport_policy, aiortc never passes
# it through). So the bridge always gathers host candidates too and reaches the
# relay by ICE fallback; that works, it just costs a few seconds of checking the
# unreachable direct pairs first.
TURN_URLS = [u.strip() for u in os.environ.get("TURN_URLS", "").split(",") if u.strip()]
TURN_USERNAME = os.environ.get("TURN_USERNAME", "")
TURN_PASSWORD = os.environ.get("TURN_PASSWORD", "")
STUN_URLS = [u.strip() for u in os.environ.get("STUN_URLS", "").split(",") if u.strip()]


def build_rtc_configuration() -> RTCConfiguration | None:
    """RTCConfiguration from env, or None to keep aiortc's host-only default."""
    servers: list[RTCIceServer] = []
    if STUN_URLS:
        servers.append(RTCIceServer(urls=STUN_URLS))
    if TURN_URLS:
        if not (TURN_USERNAME and TURN_PASSWORD):
            logger.warning(
                "TURN_URLS set but TURN_USERNAME/TURN_PASSWORD missing — "
                "the relay will be advertised and then rejected by the server"
            )
        servers.append(
            RTCIceServer(
                urls=TURN_URLS,
                username=TURN_USERNAME or None,
                credential=TURN_PASSWORD or None,
            )
        )
    if not servers:
        return None
    return RTCConfiguration(iceServers=servers)


RTC_CONFIGURATION = build_rtc_configuration()
if RTC_CONFIGURATION is None:
    logger.warning(
        "no STUN/TURN configured — relying on host ICE candidates only; this "
        "works on a developer machine but not through a firewalled network"
    )
else:
    logger.info("ICE servers configured: stun=%d turn=%d", len(STUN_URLS), len(TURN_URLS))


class BridgeSession:
    """One browser connection: RTCPeerConnection + upstream WebSocket."""

    def __init__(self, gateway_base: str, gateway_key: str, model: str):
        self.id = uuid.uuid4().hex[:12]
        self.gateway_base = gateway_base.rstrip("/")
        self.gateway_key = gateway_key
        self.model = model
        self.item_id = "item_" + self.id
        self.item_seq = 0
        self.log = logging.getLogger(f"session.{self.id}")

        self.pc = (
            RTCPeerConnection(configuration=RTC_CONFIGURATION)
            if RTC_CONFIGURATION is not None
            else RTCPeerConnection()
        )
        self.channel = None
        self.pending_client_events: list[dict] = []
        self.http: aiohttp.ClientSession | None = None
        self.upstream: aiohttp.ClientWebSocketResponse | None = None
        self.tasks: set[asyncio.Task] = set()
        self.audio_buffer = bytearray()
        self.bytes_sent = 0
        # Per item (segment): bytes appended and whether decoding started.
        self.segment_bytes = 0
        self.generation_started = False
        self.finalizing = False
        self.done_received = asyncio.Event()
        # Item rotation (keep_open commit): while the next upstream stream is
        # being opened, audio collects in `hold` instead of being dropped.
        self.rotating = False
        self.hold = bytearray()
        self.segment_lock = asyncio.Lock()
        self.closed = False

        self.pc.on("datachannel", self._on_datachannel)
        self.pc.on("track", self._on_track)
        self.pc.on("connectionstatechange", self._on_connection_state)

    # ---------------------------------------------------------------- WebRTC

    async def negotiate(self, offer_sdp: str) -> str:
        """Connect upstream first (fail fast), then answer the SDP offer."""
        await self._connect_upstream()

        await self.pc.setRemoteDescription(
            RTCSessionDescription(sdp=offer_sdp, type="offer")
        )
        answer = await self.pc.createAnswer()
        # setLocalDescription performs ICE gathering; the returned SDP
        # contains all host candidates.
        await self.pc.setLocalDescription(answer)
        self.log.info("negotiated (model=%s)", self.model)
        return self.pc.localDescription.sdp

    def _on_datachannel(self, channel):
        self.log.info("data channel opened: %s", channel.label)
        self.channel = channel
        for event in self.pending_client_events:
            self._channel_send(event)
        self.pending_client_events.clear()

        @channel.on("message")
        def on_message(message):
            try:
                data = json.loads(message)
            except (TypeError, ValueError):
                return
            # The client asks to finalize (user stopped recording) or, with
            # keep_open, to close the current item and continue with the next
            # one (a message was sent, the mic stays open). Any session.update
            # or other client events are intentionally ignored: the upstream
            # session is bridge-managed.
            if data.get("type") == "input_audio_buffer.commit":
                if data.get("keep_open"):
                    self._spawn(self._rotate())
                else:
                    self._spawn(self._finalize())

    def _on_track(self, track):
        if track.kind != "audio":
            return
        self.log.info("audio track received")
        self._spawn(self._pump_audio(track))

    def _on_connection_state(self):
        state = self.pc.connectionState
        self.log.info("connection state: %s", state)
        if state in ("failed", "closed"):
            self._spawn(self.close())

    # -------------------------------------------------------------- upstream

    async def _connect_upstream(self):
        """First upstream stream, opened during negotiation (fail fast)."""
        self.http = aiohttp.ClientSession()
        try:
            self.upstream, self.done_received = await self._open_upstream(self.item_id)
        except Exception:
            await self.http.close()
            self.http = None
            raise

    async def _open_upstream(self, item_id: str):
        """Open one vLLM realtime stream for one item; returns (ws, done)."""
        ws_base = self.gateway_base.replace("https://", "wss://", 1).replace(
            "http://", "ws://", 1
        )
        url = f"{ws_base}/v1/realtime?model={self.model}"
        ws = await self.http.ws_connect(
            url,
            headers={"Authorization": f"Bearer {self.gateway_key}"},
            heartbeat=20,
            max_msg_size=16 * 1024 * 1024,
        )
        # vLLM refuses audio until the model is validated via session.update
        # (note: `model` sits at the event's top level, unlike OpenAI).
        await ws.send_json({"type": "session.update", "model": self.model})
        done = asyncio.Event()
        self._spawn(self._read_upstream(ws, item_id, done))
        self.log.info("upstream connected: %s (%s)", url, item_id)
        return ws, done

    async def _read_upstream(self, ws, item_id: str, done: asyncio.Event):
        try:
            async for msg in ws:
                if msg.type != aiohttp.WSMsgType.TEXT:
                    continue
                event = json.loads(msg.data)
                etype = event.get("type")

                if etype == "transcription.delta":
                    delta = event.get("delta", "")
                    if delta:
                        self.log.debug("delta: %r", delta)
                        self._channel_send(
                            {
                                "type": "conversation.item.input_audio_transcription.delta",
                                "item_id": item_id,
                                "delta": delta,
                            }
                        )
                elif etype == "transcription.done":
                    self.log.info(
                        "transcription done (%d chars, %s)", len(event.get("text", "")), item_id
                    )
                    self._channel_send(
                        {
                            "type": "conversation.item.input_audio_transcription.completed",
                            "item_id": item_id,
                            "transcript": event.get("text", ""),
                        }
                    )
                    done.set()
                elif etype == "error":
                    self.log.error("upstream error: %s", event)
                    self._channel_send(
                        {
                            "type": "conversation.item.input_audio_transcription.failed",
                            "item_id": item_id,
                            "error": event.get("error"),
                        }
                    )
                    # The item is resolved for the client - don't make a
                    # finalize wait for a done that will not come.
                    done.set()
        except Exception as exc:
            if not self.closed:
                self.log.warning("upstream reader ended: %r", exc)
        finally:
            done.set()

    # ----------------------------------------------------------------- audio

    async def _pump_audio(self, track):
        import time as _time

        resampler = AudioResampler(format="s16", layout="mono", rate=TARGET_RATE)
        pump_start = None
        last_stat = 0.0
        try:
            while not self.finalizing:
                frame = await track.recv()
                if pump_start is None:
                    pump_start = _time.monotonic()
                    self.log.info("first audio frame received")
                for out in resampler.resample(frame):
                    self.audio_buffer.extend(
                        bytes(out.planes[0])[: out.samples * 2]
                    )
                while len(self.audio_buffer) >= APPEND_CHUNK_BYTES:
                    chunk = bytes(self.audio_buffer[:APPEND_CHUNK_BYTES])
                    del self.audio_buffer[:APPEND_CHUNK_BYTES]
                    await self._send_audio(chunk)
                # If the audio clock falls behind the wall clock, either the
                # client isn't sending in realtime or this pump is starved.
                elapsed = _time.monotonic() - pump_start
                if elapsed - last_stat >= 5.0:
                    last_stat = elapsed
                    audio_s = self.bytes_sent / (TARGET_RATE * 2)
                    self.log.info(
                        "audio clock: %.1fs sent / %.1fs wall (lag %.1fs)",
                        audio_s,
                        elapsed,
                        elapsed - audio_s,
                    )
        except Exception as exc:
            # MediaStreamError: the client closed the connection or stopped
            # the track without asking to finalize (e.g. tab closed).
            if not self.finalizing and not self.closed:
                self.log.info("audio track ended (%r), finalizing", exc)
                self._spawn(self._finalize())

    async def _send_audio(self, chunk: bytes):
        if self.rotating:
            self.hold.extend(chunk)
            return
        if self.upstream is None or self.upstream.closed:
            return
        await self._append(self.upstream, chunk)

    async def _append(self, ws, chunk: bytes):
        """Append audio to the current item's stream; starts decoding early."""
        await ws.send_json(
            {
                "type": "input_audio_buffer.append",
                "audio": base64.b64encode(chunk).decode(),
            }
        )
        self.bytes_sent += len(chunk)
        self.segment_bytes += len(chunk)
        if not self.generation_started and self.segment_bytes >= START_COMMIT_AFTER_BYTES:
            await self._start_generation(ws)

    async def _start_generation(self, ws):
        self.generation_started = True
        await ws.send_json({"type": "input_audio_buffer.commit", "final": False})
        # Anchor for the client's stop()-drain logic: it waits for this
        # item to complete before tearing the connection down.
        self._channel_send(
            {"type": "input_audio_buffer.committed", "item_id": self.item_id}
        )
        self.log.info("generation started (%s)", self.item_id)

    # -------------------------------------------------------------- lifecycle

    async def _seal(self, ws, tail: bytes):
        """Finish the current item: flush its audio, end its stream and wait
        for the final transcript (or report the item failed)."""
        item_id, done = self.item_id, self.done_received
        if ws is not None and not ws.closed:
            if tail:
                await ws.send_json(
                    {
                        "type": "input_audio_buffer.append",
                        "audio": base64.b64encode(tail).decode(),
                    }
                )
                self.bytes_sent += len(tail)
                self.segment_bytes += len(tail)
            if not self.generation_started:
                # Too little audio to have started generation: start it now,
                # otherwise the final commit has nothing to close.
                await self._start_generation(ws)
            await ws.send_json({"type": "input_audio_buffer.commit", "final": True})

        try:
            await asyncio.wait_for(done.wait(), DONE_TIMEOUT_S)
        except asyncio.TimeoutError:
            self.log.error("no transcription.done within %.0fs (%s)", DONE_TIMEOUT_S, item_id)
            self._channel_send(
                {
                    "type": "conversation.item.input_audio_transcription.failed",
                    "item_id": item_id,
                    "error": "timeout waiting for final transcription",
                }
            )

    async def _rotate(self):
        """keep_open commit: finalize the current item, continue with the next
        one. The WebRTC connection stays up; audio arriving meanwhile is held
        and handed to the next item, so nothing said after the send is lost."""
        async with self.segment_lock:
            if self.finalizing or self.closed:
                return
            self.rotating = True
            old_ws = self.upstream
            self.upstream = None
            tail = bytes(self.audio_buffer)
            self.audio_buffer.clear()

            self.item_seq += 1
            next_item = f"item_{self.id}_{self.item_seq}"
            self.log.info(
                "rotating %s -> %s (%.1fs in item)",
                self.item_id, next_item, (self.segment_bytes + len(tail)) / (TARGET_RATE * 2),
            )
            # Open the next stream while the current one finishes.
            opening = asyncio.ensure_future(self._open_upstream(next_item))
            try:
                await self._seal(old_ws, tail)
            finally:
                if old_ws is not None and not old_ws.closed:
                    try:
                        await old_ws.close()
                    except Exception:
                        pass

            try:
                new_ws, new_done = await opening
            except Exception as exc:
                self.log.error("next upstream failed: %r", exc)
                self._channel_send(
                    {
                        "type": "conversation.item.input_audio_transcription.failed",
                        "item_id": next_item,
                        "error": "upstream connection failed",
                    }
                )
                self.rotating = False
                self._spawn(self.close())
                return

            self.item_id, self.done_received = next_item, new_done
            self.segment_bytes = 0
            self.generation_started = False
            # Hand over the held audio before the pump may write again; the
            # pump keeps appending to `hold` until `rotating` is cleared, and
            # there is no await between the empty check and the switch.
            while self.hold:
                chunk = bytes(self.hold[:APPEND_CHUNK_BYTES])
                del self.hold[:APPEND_CHUNK_BYTES]
                await self._append(new_ws, chunk)
            self.upstream = new_ws
            self.rotating = False

    async def _finalize(self):
        if self.finalizing:
            return
        self.finalizing = True
        self.log.info("finalizing (%.1fs audio sent)", self.bytes_sent / (TARGET_RATE * 2))

        try:
            # A rotation in progress completes first (it owns the upstream).
            async with self.segment_lock:
                tail = bytes(self.audio_buffer) + bytes(self.hold)
                self.audio_buffer.clear()
                self.hold.clear()
                await self._seal(self.upstream, tail)
        finally:
            # Give the data channel a moment to flush the completed event
            # before the peer connection goes away.
            await asyncio.sleep(0.5)
            await self.close()

    async def close(self):
        if self.closed:
            return
        self.closed = True
        self.log.info("closing session")
        for task in list(self.tasks):
            if task is not asyncio.current_task():
                task.cancel()
        if self.upstream is not None and not self.upstream.closed:
            try:
                await self.upstream.close()
            except Exception:
                pass
        if self.http is not None:
            await self.http.close()
        try:
            await self.pc.close()
        except Exception:
            pass

    # ----------------------------------------------------------------- utils

    def _channel_send(self, event: dict):
        if self.channel is None:
            self.pending_client_events.append(event)
            return
        if self.channel.readyState != "open":
            return
        try:
            self.channel.send(json.dumps(event))
        except Exception as exc:
            self.log.warning("data channel send failed: %r", exc)

    def _spawn(self, coro):
        task = asyncio.ensure_future(coro)
        self.tasks.add(task)
        task.add_done_callback(self.tasks.discard)


# ------------------------------------------------------------------ HTTP API


async def handle_realtime(request: web.Request) -> web.Response:
    if BRIDGE_API_KEY:
        auth = request.headers.get("Authorization", "")
        if auth != f"Bearer {BRIDGE_API_KEY}":
            return web.Response(status=401, text="unauthorized")

    gateway_base = request.headers.get("X-Gateway-Base", "")
    gateway_key = request.headers.get("X-Gateway-Key", "")
    model = request.headers.get("X-Model", "")
    if not gateway_base or not model:
        return web.Response(status=400, text="X-Gateway-Base and X-Model are required")

    offer_sdp = await request.text()
    if not offer_sdp.startswith("v="):
        return web.Response(status=400, text="body must be an SDP offer")

    session = BridgeSession(gateway_base, gateway_key, model)
    try:
        answer_sdp = await session.negotiate(offer_sdp)
    except Exception as exc:
        logger.error("negotiation failed: %r", exc)
        await session.close()
        return web.Response(status=502, text=f"upstream connection failed: {exc}")

    return web.Response(content_type="application/sdp", text=answer_sdp)


async def handle_health(_request: web.Request) -> web.Response:
    return web.Response(text="ok")


def main():
    app = web.Application()
    app.router.add_post("/realtime", handle_realtime)
    app.router.add_get("/health", handle_health)
    port = int(os.environ.get("PORT", "8089"))
    logger.info("HAWKI realtime bridge listening on :%d", port)
    web.run_app(app, host=os.environ.get("HOST", "0.0.0.0"), port=port, print=None)


if __name__ == "__main__":
    main()
