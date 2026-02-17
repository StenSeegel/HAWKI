# OpenAI Whisper Transkriptions-Service

## Übersicht

Der Transkriptions-Service nutzt die **OpenAI Whisper API** zur professionellen Audio-Transkription. Die Konfiguration erfolgt über die **Datenbank** (`api_providers` und `ai_models` Tabellen) mit Fallback auf Config/Env.

## ✨ Features

- ✅ **Professionelle Transkription** mit OpenAI's Whisper-Modellen
- ✅ **Mehrsprachige Unterstützung** (automatische Erkennung oder manuelle Angabe)
- ✅ **Detaillierte Metadaten** (Segmente, Timestamps, Wörter)
- ✅ **Verbose JSON Format** mit zusätzlichen Informationen
- ✅ **Zuverlässige Cloud-API** ohne lokale Hardware-Anforderungen
- ✅ **Datenbankbasierte Konfiguration** für Flexibilität

## 🔧 Konfiguration

### Option 1: Datenbank-Konfiguration (Empfohlen)

Die bevorzugte Methode ist die Konfiguration über die Datenbank. Dies ermöglicht einfache Verwaltung über die Admin-Oberfläche.

#### 1. OpenAI Provider erstellen

```sql
-- API Provider erstellen
INSERT INTO api_providers (
    unique_name, 
    provider_name, 
    base_url,
    api_key,
    is_active, 
    display_order,
    created_at,
    updated_at
) VALUES (
    'openai',
    'OpenAI',
    'https://api.openai.com/v1',
    'sk-proj-XXXXXXXXXXXXXXXXXXXXXXXXX',  -- Ihr OpenAI API Key hier
    1,
    0,
    NOW(),
    NOW()
);
```

#### 2. Whisper-Modell hinzufügen

```sql
-- Zuerst die Provider-ID ermitteln
SET @provider_id = (SELECT id FROM api_providers WHERE unique_name = 'openai');

-- Dann das Whisper-Modell erstellen
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
    'gpt-4o-transcribe',
    'OpenAI Whisper (High Quality)',
    @provider_id,
    1,
    1,
    0,
    NOW(),
    NOW()
);
```

#### 3. Weitere Modelle (optional)

```sql
-- Günstigeres Mini-Modell
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
    'gpt-4o-mini-transcribe',
    'OpenAI Whisper Mini (Cost-Effective)',
    @provider_id,
    1,
    1,
    1,
    NOW(),
    NOW()
);
```

#### 4. Konfiguration prüfen

```bash
php artisan tinker
>>> App\Models\ApiProvider::where('unique_name', 'openai')->first()
>>> App\Models\AiModel::where('model_id', 'like', '%transcribe%')->get()
```

### Option 2: Fallback über Config/Env

Falls kein OpenAI-Provider in der Datenbank existiert, nutzt der Service automatisch die Config/Env-Einstellungen:

**In `config/services.php`:**
```php
'openai' => [
    'api_key' => env('OPENAI_API_KEY'),
],
```

**In `.env`:**
```env
OPENAI_API_KEY=sk-proj-XXXXXXXXXXXXXXXXXXXXXXXXX
OPENAI_BASE_URL="https://api.openai.com/v1"
OPENAI_WHISPER_MODEL="gpt-4o-transcribe"
```

### 2. Verfügbare Modelle

OpenAI bietet verschiedene Whisper-Modelle an:

| Modell | Beschreibung | Preis |
|--------|-------------|-------|
| `gpt-4o-transcribe` | Höchste Qualität, neueste Technologie | ~$0.006/Min |
| `gpt-4o-mini-transcribe` | Schneller, kosteneffizient | ~$0.003/Min |
| `whisper-1` | Original Whisper-Modell | ~$0.006/Min |

## 🚀 Schnellstart

### Für Production/Staging (mit Datenbank)

1. **OpenAI API Key erhalten:**
   - Registrieren Sie sich bei https://platform.openai.com/
   - Navigieren Sie zu https://platform.openai.com/api-keys
   - Erstellen Sie einen neuen API Key

2. **In Datenbank eintragen:**
   ```sql
   -- Provider erstellen
   INSERT INTO api_providers (unique_name, provider_name, base_url, api_key, is_active, display_order, created_at, updated_at) 
   VALUES ('openai', 'OpenAI', 'https://api.openai.com/v1', 'sk-proj-XXXXXX', 1, 0, NOW(), NOW());
   
   -- Modell erstellen
   SET @provider_id = (SELECT id FROM api_providers WHERE unique_name = 'openai');
   INSERT INTO ai_models (system_id, model_id, label, provider_id, is_active, is_visible, display_order, created_at, updated_at) 
   VALUES (UUID(), 'gpt-4o-transcribe', 'OpenAI Whisper', @provider_id, 1, 1, 0, NOW(), NOW());
   ```

3. **Testen:**
   ```bash
   curl http://localhost:8000/req/transcription-test
   curl -X POST http://localhost:8000/req/transcribe -F "audio=@test.mp3"
   ```

### Für lokale Entwicklung (mit Config/Env)

1. **API Key in .env:**
   ```bash
   echo 'OPENAI_API_KEY=sk-proj-XXXXXX' >> .env
   php artisan config:clear
   ```

2. **Testen:**
   ```bash
   curl http://localhost:8000/req/transcription-config
   ```

Der Service nutzt automatisch die Datenbank-Konfiguration, falls vorhanden, sonst Config/Env.

## 📡 API-Endpunkte

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
  "segments": [
    {
      "id": 0,
      "start": 0.0,
      "end": 5.2,
      "text": "Hallo und willkommen...",
      "tokens": [...],
      "temperature": 0.0,
      "avg_logprob": -0.3,
      "compression_ratio": 1.2,
      "no_speech_prob": 0.01
    }
  ],
  "words": [
    {
      "word": "Hallo",
      "start": 0.0,
      "end": 0.5
    }
  ],
  "language": "de",
  "duration": 125.5,
  "model": "gpt-4o-transcribe",
  "provider": "OpenAI",
  "usage": {
    "seconds": 125.5,
    "type": "duration"
  }
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
      "id": 5,
      "name": "OpenAI",
      "unique_name": "openai",
      "base_url": "https://api.openai.com/v1",
      "is_active": true,
      "source": "database"
    },
    "model": {
      "id": 12,
      "model_id": "gpt-4o-transcribe",
      "label": "OpenAI Whisper (High Quality)",
      "is_active": true,
      "source": "database"
    }
  }
}
```

> **Hinweis:** `source` zeigt an, ob die Konfiguration aus der `database` oder aus `config/env` kommt.

### 3. Verbindung testen

```http
GET /req/transcription-test
```

**Antwort bei Erfolg:**

```json
{
  "success": true,
  "message": "Verbindung erfolgreich",
  "url": "https://api.openai.com/v1",
  "current_model": "gpt-4o-transcribe",
  "available_transcription_models": [
    "gpt-4o-transcribe",
    "gpt-4o-mini-transcribe",
    "whisper-1"
  ]
}
```

### 4. Status abrufen

```http
GET /req/transcription-status/{jobId}
```

## 🎯 Verwendung

### Unterstützte Audio-Formate

- MP3
- WAV
- M4A
- Maximale Dateigröße: 25 MB

### Sprachen angeben (optional)

Sie können die Sprache explizit angeben oder automatische Erkennung nutzen:

```bash
# Deutsch
curl -X POST http://localhost:8000/req/transcribe \
  -F "audio=@audio.mp3" \
  -F "language=de"

# Englisch
curl -X POST http://localhost:8000/req/transcribe \
  -F "audio=@audio.mp3" \
  -F "language=en"

# Automatische Erkennung (language weglassen)
curl -X POST http://localhost:8000/req/transcribe \
  -F "audio=@audio.mp3"
```

Unterstützte Sprach-Codes: `de`, `en`, `fr`, `es`, `it`, `pt`, `nl`, `pl`, `ru`, `ja`, `zh`, und viele mehr.

## ⚙️ Erweiterte Konfiguration

### Base URL ändern

Für Azure OpenAI oder andere kompatible Endpoints:

```env
OPENAI_BASE_URL="https://your-azure-openai.openai.azure.com/v1"
OPENAI_API_KEY="your-azure-key"
```

### Modell wechseln

```env
# Für schnellere, günstigere Transkription
OPENAI_WHISPER_MODEL="gpt-4o-mini-transcribe"

# Für höchste Qualität (Standard)
OPENAI_WHISPER_MODEL="gpt-4o-transcribe"
```

## 🔍 Fehlerbehebung

### Fehler: "OpenAI API Key ist nicht konfiguriert"

**Ursache:** Weder Datenbank noch Config/Env haben einen API Key.

**Lösung:**

**Option A - Datenbank (Empfohlen):**
```sql
-- API Key in Datenbank setzen
UPDATE api_providers 
SET api_key = 'sk-proj-XXXXXXXXXXXXXXXXX' 
WHERE unique_name = 'openai';

-- Prüfen
SELECT unique_name, provider_name, base_url, is_active 
FROM api_providers 
WHERE unique_name = 'openai';
```

**Option B - Config/Env:**
```bash
# .env bearbeiten
echo 'OPENAI_API_KEY=sk-proj-XXXXXXXXX' >> .env

# Cache löschen
php artisan config:clear
```

### Fehler: "Provider 'openai' nicht gefunden"

**Lösung:** Provider in der Datenbank erstellen (siehe Konfiguration oben)

```bash
# Prüfen
php artisan tinker
>>> App\Models\ApiProvider::where('unique_name', 'openai')->first()
# Sollte den OpenAI-Provider zurückgeben, sonst: SQL ausführen
```

### Fehler: "Kein Whisper-Modell in DB gefunden"

Der Service funktioniert trotzdem mit dem Fallback-Modell, aber zur optimalen Konfiguration:

```sql
-- Whisper-Modelle prüfen
SELECT m.model_id, m.label, p.provider_name 
FROM ai_models m
JOIN api_providers p ON m.provider_id = p.id
WHERE m.model_id LIKE '%transcribe%' OR m.model_id LIKE '%whisper%';

-- Falls leer: Modell hinzufügen (siehe Konfiguration oben)
```

### Fehler: "OpenAI API nicht erreichbar"

**Lösung:** Prüfen Sie Ihre Internetverbindung und API Key:

```bash
# Verbindung testen
curl http://localhost:8000/req/transcription-test
```

### Fehler: "Incorrect API key provided"

**Lösung:** Überprüfen Sie Ihren API Key:
1. Key von https://platform.openai.com/api-keys kopieren
2. In `.env` einfügen
3. `php artisan config:clear` ausführen
4. Anwendung neu starten

### Rate Limits

OpenAI hat API Rate Limits. Bei häufigen Requests:

```json
{
  "error": {
    "message": "Rate limit exceeded",
    "type": "rate_limit_exceeded"
  }
}
```

**Lösung:** 
- Upgrade Ihres OpenAI Plans
- Implementierung von Request-Queuing
- Verwendung von `gpt-4o-mini-transcribe` für höhere Limits

## 💡 Best Practices

1. **API Key Sicherheit:**
   - Niemals API Keys in Git committen
   - `.env` in `.gitignore` belassen
   - Regelmäßig Keys rotieren

2. **Kostenoptimierung:**
   - Verwenden Sie `gpt-4o-mini-transcribe` für unkritische Transkriptionen
   - Audio-Dateien vor Upload komprimieren
   - Batch-Processing für viele Dateien

3. **Qualität:**
   - Hochwertige Audio-Aufnahmen verwenden
   - Hintergrundgeräusche minimieren
   - Sprache explizit angeben für bessere Ergebnisse

## 📊 Kosten

Beispielrechnung für `gpt-4o-transcribe` (~$0.006/Minute):

- 1 Minute Audio: $0.006
- 10 Minuten Audio: $0.06
- 1 Stunde Audio: $0.36
- 100 Stunden Audio: $36.00

## 🔗 Weitere Ressourcen

- [OpenAI Whisper API Dokumentation](https://platform.openai.com/docs/guides/speech-to-text)
- [OpenAI Pricing](https://openai.com/pricing)
- [Unterstützte Sprachen](https://platform.openai.com/docs/guides/speech-to-text/supported-languages)
- [API Keys verwalten](https://platform.openai.com/api-keys)

## 🔄 Migration von Ollama

Falls Sie vorher Ollama verwendet haben:

1. ✅ Kein Ollama-Server mehr nötig
2. ✅ Keine Datenbank-Konfiguration (api_providers/ai_models) erforderlich
3. ✅ Einfach `OPENAI_API_KEY` setzen und starten
4. ❌ Alte Ollama-Configs werden nicht mehr verwendet

Der Service ist jetzt **deutlich einfacher** und **zuverlässiger**!
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
