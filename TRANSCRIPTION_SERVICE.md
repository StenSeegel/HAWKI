# Whisper Transkriptions-Service

## Übersicht

Der Transkriptions-Service nutzt einen lokalen Ollama-Server mit dem Whisper-Modell zur Audio-Transkription. Die Konfiguration erfolgt über die Datenbank.

## Konfiguration

### 1. Datenbank-Einträge prüfen/erstellen

#### API Provider (Tabelle: `api_providers`)

```sql
-- Provider prüfen
SELECT * FROM api_providers WHERE unique_name = 'ollama-jlu';

-- Falls nicht vorhanden, Provider erstellen:
INSERT INTO api_providers (
    unique_name, 
    provider_name, 
    base_url, 
    is_active, 
    display_order,
    created_at,
    updated_at
) VALUES (
    'ollama-jlu',
    'Ollama JLU',
    'http://localhost:11434',  -- Passen Sie die URL an
    1,
    0,
    NOW(),
    NOW()
);
```

#### AI Model (Tabelle: `ai_models`)

```sql
-- Whisper-Modell prüfen
SELECT * FROM ai_models WHERE model_id = 'karanchopda333/whisper:latest';

-- Falls nicht vorhanden, Modell erstellen:
-- Zuerst die Provider-ID ermitteln:
SET @provider_id = (SELECT id FROM api_providers WHERE unique_name = 'ollama-jlu');

-- Dann das Modell erstellen:
INSERT INTO ai_models (
    system_id,
    model_id,
    label,
    provider_id,
    is_active,
    is_visible,
    display_order,
    created_at,
    updated_at
) VALUES (
    UUID(),
    'karanchopda333/whisper:latest',
    'Whisper Audio Transkription',
    @provider_id,
    1,
    1,
    0,
    NOW(),
    NOW()
);
```

### 2. Ollama-Server vorbereiten

Stellen Sie sicher, dass Ollama läuft und das Whisper-Modell verfügbar ist:

```bash
# Ollama-Server starten (falls nicht bereits gestartet)
ollama serve

# Whisper-Modell laden
ollama pull karanchopda333/whisper:latest

# Verfügbare Modelle prüfen
ollama list
```

### 3. Base URL konfigurieren

Die `base_url` in der `api_providers` Tabelle muss auf Ihren Ollama-Server zeigen:
- Lokal: `http://localhost:11434`
- Docker: `http://ollama:11434`
- Remote: `http://<server-ip>:11434`

## API-Endpunkte

### 1. Transkription durchführen

```http
POST /req/transcribe
Content-Type: multipart/form-data

audio: [Audio-Datei]
language: de (optional)
```

**Beispiel mit cURL:**

```bash
curl -X POST http://localhost:8000/req/transcribe \
  -F "audio=@/pfad/zur/audio.mp3" \
  -F "language=de"
```

**Antwort:**

```json
{
  "success": true,
  "text": "Transkribierter Text...",
  "segments": [],
  "language": "de",
  "model": "karanchopda333/whisper:latest",
  "provider": "Ollama JLU"
}
```

### 2. Konfiguration abrufen

```http
GET /req/transcription-config
```

**Antwort:**

```json
{
  "success": true,
  "data": {
    "provider": {
      "id": 1,
      "name": "Ollama JLU",
      "unique_name": "ollama-jlu",
      "base_url": "http://localhost:11434",
      "is_active": true
    },
    "model": {
      "id": 5,
      "model_id": "karanchopda333/whisper:latest",
      "label": "Whisper Audio Transkription",
      "is_active": true
    }
  }
}
```

### 3. Verbindung testen

```http
GET /req/transcription-test
```

**Antwort bei Erfolg:**

```json
{
  "success": true,
  "message": "Verbindung erfolgreich",
  "url": "http://localhost:11434",
  "models": [
    {
      "name": "karanchopda333/whisper:latest",
      "modified_at": "...",
      "size": ...
    }
  ]
}
```

### 4. Status abrufen

```http
GET /req/transcription-status/{jobId}
```

## Fehlerbehebung

### Fehler: "Provider 'ollama-jlu' nicht gefunden"

**Lösung:** Provider in der Datenbank erstellen (siehe SQL oben)

```bash
# Provider prüfen
php artisan tinker
>>> App\Models\ApiProvider::where('unique_name', 'ollama-jlu')->first()
```

### Fehler: "Whisper-Modell nicht gefunden"

**Lösung:** Modell in der Datenbank erstellen und sicherstellen, dass `provider_id` korrekt ist

```bash
php artisan tinker
>>> $provider = App\Models\ApiProvider::where('unique_name', 'ollama-jlu')->first()
>>> App\Models\AiModel::where('provider_id', $provider->id)->get()
```

### Fehler: "Ollama API nicht erreichbar"

**Lösungen:**

1. **Ollama läuft nicht:**
   ```bash
   ollama serve
   ```

2. **Falsche URL:** Prüfen Sie die `base_url` in der Datenbank
   ```sql
   UPDATE api_providers 
   SET base_url = 'http://localhost:11434' 
   WHERE unique_name = 'ollama-jlu';
   ```

3. **Firewall/Netzwerk:** Prüfen Sie, ob Port 11434 erreichbar ist
   ```bash
   curl http://localhost:11434/api/tags
   ```

4. **Docker:** Wenn Ollama in Docker läuft, verwenden Sie den Container-Namen
   ```sql
   UPDATE api_providers 
   SET base_url = 'http://ollama:11434' 
   WHERE unique_name = 'ollama-jlu';
   ```

### Fehler: "Whisper-Modell nicht geladen"

```bash
# Modell pullen
ollama pull karanchopda333/whisper:latest

# Prüfen
ollama list
```

## Logs überprüfen

```bash
# Laravel-Logs
tail -f storage/logs/laravel.log

# Nach Transkription filtern
tail -f storage/logs/laravel.log | grep -i transcri
```

## Cache leeren

Bei Konfigurationsänderungen Cache leeren:

```bash
php artisan cache:clear
php artisan config:clear
```

## Schnelltest

```bash
# 1. Konfiguration prüfen
curl http://localhost:8000/req/transcription-config

# 2. Verbindung testen
curl http://localhost:8000/req/transcription-test

# 3. Test-Transkription
curl -X POST http://localhost:8000/req/transcribe \
  -F "audio=@test_audio.mp3" \
  -F "language=de"
```

## Technische Details

### Unterstützte Audio-Formate
- MP3
- WAV
- M4A
- Weitere (abhängig von Whisper-Modell)

### Maximale Dateigröße
25 MB (konfigurierbar in `TranscriptionController.php`)

### Timeout
120 Sekunden für Transkription

### Ollama API-Integration
Der Service unterstützt verschiedene Ollama-API-Ansätze:
1. `/api/generate` Endpoint mit Images-Array
2. `/api/chat` Endpoint als Fallback

### Antwort-Normalisierung
Die Antwort von Ollama wird normalisiert und enthält:
- `text`: Transkribierter Text
- `segments`: Audio-Segmente (falls verfügbar)
- `language`: Erkannte Sprache
- `model`: Verwendetes Modell
- `provider`: Provider-Name
