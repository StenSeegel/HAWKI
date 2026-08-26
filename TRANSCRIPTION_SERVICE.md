# Transkriptions-Service

HAWKI transkribiert hochgeladene Audio-/Videodateien asynchron mit
Sprechererkennung (Diarization) und bietet zusätzlich Live-Transkription
(Wort-für-Wort während des Sprechens). Dieses Dokument ist der Einstieg;
Details stehen als Kommentare an den genannten Klassen.

## Architektur

| Baustein | Aufgabe |
|---|---|
| **Batch-STT** (Whisper-Klasse-Modell) | Transkription der Audio-Chunks, OpenAI-kompatible `POST /v1/audio/transcriptions` |
| **Diarization** (pyannote) | Sprechersegmente + Zuordnung bekannter Sprecher; Speaches-eigene Endpunkte `POST /v1/audio/diarization` und `/v1/audio/speech/timestamps` |
| **audio-ingest** (Worker-Container) | Chunking/Vorverarbeitung (ffmpeg), angebunden über Redis-Queue und S3 |
| **S3** | Originaldatei, Chunks + Manifest; Browser-Upload per presigned PUT über den nginx-`/s3`-Proxy |
| **realtime-bridge** (Sidecar) | Browser-WebRTC → Realtime-WebSocket des Gateways; kein API-Key erreicht den Browser |

## Workflow (zwei Phasen)

Jede Datei durchläuft denselben asynchronen, queue-basierten Ablauf —
es gibt keinen separaten Pfad für kurze Dateien:

1. **Upload & Vorverarbeitung**: Browser lädt direkt nach S3;
   `audio-ingest` erzeugt Chunks + Manifest.
2. **Phase 1 — Sprecheranalyse** (`AnalyzeSpeakersJob`): Diarization über
   die ganze Datei, pro erkanntem Sprecher wird ein Audio-Schnipsel zur
   Bestätigung/Benennung im UI angeboten.
3. **Phase 2 — Transkription** (`ProcessTranscriptionJob`):
   Chunk-Transkription, finale Diarization mit den bestätigten
   Sprecher-Referenzen (`known_speaker_names/references`), Zeit-Overlap-
   Mapping der Segmente, optional LLM-Korrektur.

Einstiegspunkte: `app/Services/Transcription/` (Jobs, Provider, Settings)
und `config/transcription.php` (Timeouts, Queues, Limits).

## Provider

`TranscriptionFactory` wählt anhand der Einstellung `provider`:

- **`custom_speaches`** — der vollständige Workflow oben (Chunking,
  Diarization, Sprecher-Mapping). Implementierung:
  `CustomSpeachesProvider`. Batch-STT und Diarization können auf
  verschiedenen Servern liegen.
- **OpenAI-kompatibler Provider** (`api_providers.unique_name`) — einfacher
  Einzelrequest-Pfad ohne Diarization-Pipeline
  (`OpenAiTranscriptionProvider`).

## Konfiguration

Admin-UI: **Extensions → Transcription Service**. Persistenz in der Tabelle
`transcription_settings` (private Werte verschlüsselt).

**Batch-Transkription** — zwei Modi:

- **API-Provider-Referenz** (empfohlen): `batch_api_provider` verweist auf
  einen Eintrag in `api_providers`; Base-URL und Key werden von dort
  aufgelöst (keine Doppel-Pflege von Credentials), das Modell wird aus den
  aktiven `ai_models` des Providers gewählt und validiert.
- **Manuell**: `base_url` / `api_key` / `model` als freie Werte — für
  Server, deren Modelle nicht in `ai_models` stehen (z. B. Speaches-Worker;
  mehrere Worker per Komma-Liste in `base_url`).

**Diarization**: `diarization_base_url` / `diarization_api_key` /
`diarization_model` — leer = gleicher Server/Key wie Batch. Standardmodell:
`pyannote/speaker-diarization-community-1`.

**Realtime**: `chat_realtime_provider` (`onprem` | `openai`);
im On-Prem-Fall liefern `onprem_api_provider` (Referenz auf
`api_providers`) und `onprem_realtime_model` die Gateway-Zugangsdaten für
die realtime-bridge (`_docker/realtime-bridge/`).

Hinweis: Die vom virtuellen Gateway-Key erlaubten Modelle müssen die
verwendeten STT-Modelle einschließen, sonst antwortet das Gateway mit 403.

## Betrieb & Fehlersuche

- Job-Status/Fortschritt: Tabelle `transcription_jobs`
  (`manifest_data.progress`), Queue `transcription`.
- Diarization-Timeouts sind dynamisch:
  `clamp(2 × Dauer + 120 s, 600 s, 3600 s)` — lange Dateien belegen die
  GPU entsprechend lange.
- S3-Zugriff hinter Proxys: `NO_PROXY` muss die S3-/STT-Hosts abdecken
  (Symptom sonst: 503/Timeouts bei jedem S3- oder Diarization-Call).
- Tests: `php artisan test --filter="Transcription|Speaches"`.
