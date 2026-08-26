# WebRTC Realtime Transcription — What Was Needed

## Problem

Clicking "Aufnahme starten" failed silently or with a 400 Bad Request from OpenAI. No transcription text appeared.

---

## Root Causes and Fixes

### 1. Wrong signaling approach — PHP proxy mangled the SDP body

**Problem:** The original code POSTed the SDP offer to a PHP backend route (`/req/transcription/realtime/signaling`), which then forwarded it to OpenAI. Laravel's HTTP client (Guzzle) re-encoded the raw SDP body, causing OpenAI to reject it with `"Failed to parse offer: failed to unmarshal SDP: EOF"`.

**Fix:** Switched to the **ephemeral token approach**:
1. Browser calls HAWKI backend (`POST /req/transcription/realtime/session`) to mint a short-lived key.
2. Browser POSTs its SDP offer **directly to OpenAI** (`https://api.openai.com/v1/realtime/calls`) using the ephemeral key — the PHP proxy never touches the SDP.

**Files changed:**
- `app/Http/Controllers/Transcription/RealtimeSignalingController.php` — added `createSession()` method
- `routes/web.php` — added `POST /req/transcription/realtime/session` route
- `public/js/modules/realtime_transcription.js` — rewrote `start()` to use ephemeral token flow

---

### 2. Wrong endpoint for the SDP exchange

**Problem:** Original code posted to `/v1/realtime` (session management endpoint), not the WebRTC signaling endpoint.

**Fix:** Use `https://api.openai.com/v1/realtime/calls` for the SDP POST.

---

### 3. Wrong session creation body — `session.model` not accepted

**Problem:** The backend was sending `{ "session": { "type": "transcription", "model": "gpt-4o-realtime-preview" } }`. OpenAI returned `"Unknown parameter: 'session.model'"` — the `RealtimeTranscriptionSessionCreateRequestGA` schema has no top-level `model` field.

**Fix:** Removed `model` from the session creation body. The transcription model is configured separately (see fix #4).

---

### 4. Transcription not enabled — wrong config path and wrong model

**Problem:** Even after the session connected and the data channel opened, no transcription events arrived. The session was not configured to produce transcripts.

Two sub-issues:
- Used `input_audio_transcription: { model: "gpt-4o-transcribe" }` — wrong field path and wrong model (`gpt-4o-transcribe` is for file/request-response workflows, not live streaming).
- The transcription config must be sent as a `session.update` event **on the data channel** after it opens, using the path `audio.input.transcription.model`.

**Fix:**
- Send `session.update` after `dataChannel.onopen` fires:
  ```json
  {
    "type": "session.update",
    "session": {
      "type": "transcription",
      "audio": {
        "input": {
          "transcription": {
            "model": "gpt-realtime-whisper"
          }
        }
      }
    }
  }
  ```
- `gpt-realtime-whisper` is the correct model for live streaming with delta events.
- Also updated the PHP `createSession()` body to include the same `audio.input.transcription` structure for the ephemeral key request.

---

### 5. Wrong event types in the data channel handler

**Problem:** The original handler listened for `response.audio_transcription.delta` — an event type that does not exist for transcription sessions.

**Fix:** Listen for the correct events:
- `conversation.item.input_audio_transcription.delta` — streaming partial text (fires word-by-word)
- `conversation.item.input_audio_transcription.completed` — final transcript per utterance
- `conversation.item.done` — kept as fallback (its `item.content[].transcript` is populated once transcription is configured)

---

### 6. Debug noise in the browser console

**Problem:** 12 `console.error('DEBUG: ...')` calls added during development showed as red errors in the browser console on every recording start.

**Fix:** Removed all debug logging. Only genuine errors (`catch` block) log to console.

---

## Final Architecture

```
Browser                          HAWKI Backend              OpenAI
──────                           ─────────────              ──────
POST /req/transcription/         createSession()
  realtime/session        ──────>  POST /v1/realtime/
                                     client_secrets   ──────> ephemeral key
                         <──────  { value: "ek-..." } <──────

RTCPeerConnection.createOffer()
POST offer.sdp                                         ──────> /v1/realtime/calls
  (Authorization: Bearer ek-...)                      <──────  SDP answer

setRemoteDescription(answer)
  → ICE completes, data channel opens

dataChannel.send(session.update)                      ──────> enable transcription
  { audio.input.transcription.model: gpt-realtime-whisper }

microphone audio streams via WebRTC                   ──────>
                                                      <──────  .delta events (word-by-word)
                                                      <──────  .completed events (per utterance)
```

---

## Key References

- `Realtime-API-with-WebRTC.md` — ephemeral token flow and unified interface
- `Realtime-transcription.md` — correct `session.update` schema and model names
- OpenAI spec: `RealtimeTranscriptionSessionCreateRequestGA` — no top-level `model` field
