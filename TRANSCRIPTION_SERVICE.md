# HAWKI Transkriptions-Service

## Übersicht

Der Transkriptions-Service in HAWKI nutzt die **Audio Transcriptions API** (kompatibel zum OpenAI Whisper-Standard) zur professionellen Audio-Transkription. Die Konfiguration erfolgt **ausschließlich über die Datenbank** (Tabellen `api_providers` und `ai_models`), wodurch beliebige LLM-Proxies, lokale Server oder die offizielle OpenAI API flexibel und ohne Code-Änderungen angebunden werden können.

## ✨ Features

- ✅ **Kompatibilität:** Unterstützt die offizielle OpenAI API sowie kompatible LLM-Proxies über die Adapter `OpenAi` und `Responses`.
- ✅ **Mehrsprachige Unterstützung:** Automatische Erkennung oder explizite Sprachvorgabe.
- ✅ **Dynamisches Format-Handling:** Automatische Wahl zwischen `verbose_json` (für maximale Metadaten, falls vom Modell unterstützt) und `json` (als robuster Standard für Drittanbieter-Modelle).
- ✅ **Datenbankbasierte Konfiguration:** Keine Fallbacks auf statische `.env`-Dateien. Alles ist übersichtlich per Admin-Dashboard verwaltbar.
- ✅ **Globale Präferenzen:** Die in den "Advanced Settings" ausgewählten Provider und Modelle werden als globale App-Einstellungen in der Datenbank für die gesamte HAWKI-Instanz gespeichert.

## 🔧 Konfiguration

Die Konfiguration erfolgt zwingend über die HAWKI-Datenbank. Es gibt **keinen** automatischen Fallback auf `.env`-Variablen mehr, um unbeabsichtigte API-Aufrufe an Drittsysteme zu verhindern. Zudem wurde der pauschale Fallback auf Modelle mit dem Namen "whisper" entfernt, um sicherzustellen, dass nur die explizit in den Einstellungen konfigurierten Modelle genutzt werden.

### 1. Provider konfigurieren

Ein Provider muss in der Tabelle `api_providers` existieren und als Adapter entweder `OpenAi` oder `Responses` (bzw. `ResponsesApi`) eingetragen haben.

**Beispiel für die offizielle OpenAI API:**
```sql
INSERT INTO api_providers (
    unique_name, provider_name, base_url, api_key, adapter, is_active, display_order, created_at, updated_at
) VALUES (
    'openai', 'OpenAI', 'https://api.openai.com/v1', 'sk-proj-XXXXXXXXXXXXXXXX', 'OpenAi', 1, 0, NOW(), NOW()
);
```

**Beispiel für einen Custom LLM-Proxy (z.B. JLU):**
```sql
INSERT INTO api_providers (
    unique_name, provider_name, base_url, api_key, adapter, is_active, display_order, created_at, updated_at
) VALUES (
    'jlu_proxy', 'JLU LLM Proxy', 'https://api.hrz.uni-giessen.de/v1', 'DEIN_PROXY_KEY', 'Responses', 1, 1, NOW(), NOW()
);
```

### 2. Transkriptions-Modell hinzufügen

Dem Provider muss mindestens ein Transkriptions-Modell zugeordnet werden. Üblicherweise enthalten die Namen der Modelle die Begriffe `whisper` oder `transcribe`.

**Beispiel:**
```sql
SET @provider_id = (SELECT id FROM api_providers WHERE unique_name = 'jlu_proxy');

INSERT INTO ai_models (
    system_id, model_id, label, provider_id, is_active, is_visible, display_order, created_at, updated_at
) VALUES (
    UUID(), 'jlu/whisper-1', 'JLU Whisper', @provider_id, 1, 1, 0, NOW(), NOW()
);
```

## 🚀 Nutzung

Ein **Administrator** kann im HAWKI-Interface über die Schaltfläche **"Einstellungen"** in der Transkriptions-Ansicht den bevorzugten Provider und das gewünschte Modell für das gesamte System auswählen. Diese Auswahl wird in der globalen `app_settings` Tabelle gespeichert (Keys: `hawki_transcription_provider` und `hawki_transcription_model`).

Sollte in den globalen Einstellungen kein gültiges Modell konfiguriert sein, wird der Service die Anfrage mit einer `RuntimeException` ablehnen. Es gibt keinen stillen Fallback mehr.

## 📡 API-Kommunikation & Endpunkte

Das HAWKI-Backend kommuniziert mit dem Provider über den Endpoint `[BASE_URL]/audio/transcriptions`.

**Beispielhafter Request-Payload:**
```json
{
  "model": "jlu/whisper-1",
  "language": "de",
  "response_format": "json"
}
```

*Hinweis zum `response_format`:* Für den `OpenAi`-Adapter wird standardmäßig `verbose_json` angefordert, um Timestamps und Segmente abzurufen. Für den `Responses`-Adapter (häufig bei Proxies eingesetzt) wird `json` verwendet, da viele Drittanbieter-Modelle `verbose_json` nicht oder nur unzureichend unterstützen (z.B. führt dies andernfalls zu 400 Bad Request Fehlern).

## 🔍 Fehlerbehebung

### Fehler: "Kein gültiger Transkriptions-Provider gefunden"
**Ursache:** Es wurde kein Provider ausgewählt und es gibt in der Datenbank keinen aktiven Provider mit dem Adapter `OpenAi` oder `Responses`.
**Lösung:** Legen Sie im Admin-Backend einen entsprechenden Provider an.

### Fehler: "API Key ist nicht konfiguriert"
**Ursache:** Der gefundene Provider in der Datenbank hat keinen API Key hinterlegt.
**Lösung:** Tragen Sie den API Key in die `api_providers` Tabelle ein.

### Fehler: "401 Key Model Access Denied"
**Ursache:** Das in HAWKI ausgewählte (oder automatisch zugewiesene) Modell ist für den verwendeten API Key auf Seiten des LLM-Proxies nicht freigeschaltet (z.B. Request auf `jlu/whisper` statt `jlu/whisper-1`).
**Lösung:** Wählen Sie im HAWKI UI unter "Advanced Settings" das exakte Modell aus, für das der Key freigeschaltet ist, oder bereinigen Sie die `ai_models` Tabelle.

### Fehler: "response_format 'verbose_json' is not compatible"
**Ursache:** Ein Proxy-Server oder lokales Modell unterstützt das von OpenAI spezifizierte `verbose_json` Format nicht.
**Lösung:** Stellen Sie sicher, dass für diesen Provider in der `api_providers` Tabelle als Adapter `Responses` eingetragen ist, damit HAWKI als Fallback automatisch auf das einfache `json` Format umschaltet.
