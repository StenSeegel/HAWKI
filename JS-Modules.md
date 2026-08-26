# HAWKI JS Modularisierungs-Strategie

Dieses Dokument beschreibt die Architektur und Strategie, die bei der Refaktorierung monolithischer JavaScript-Dateien (z. B. `translate.js`) in ein modulares ES-Module-System angewandt wurde. 
Es dient als Leitfaden und Blaupause, um weitere große Legacy-Dateien im HAWKI-Projekt nach dem gleichen Schema umzubauen.

## Philosophie & Ziele
- **Separation of Concerns (SoC)**: Trennung von API-Kommunikation, UI-Rendering und Geschäftslogik.
- **Wartbarkeit**: Vermeidung von 1000+ Zeilen Dateien ("Spaghetti-Code").
- **Testbarkeit**: Isolierte Module (wie Services und Text-Prozessoren) lassen sich einfacher über Unit-Tests abdecken, da sie keine harten DOM-Abhängigkeiten haben.
- **Namensräume & Scope**: Vermeidung von globalen Variablen (`window.xxx`) durch strikte ES6-Exporte.

---

## Ziel-Architektur (Blueprint)

Ein erfolgreich refaktorisiertes Modul sollte sich in etwa in folgende Schichten aufteilen:

### 1. Orchestrator (z.B. `TranslateApp.js`)
Der "Controller" oder das Gehirn der Anwendung. 
- **Zweck**: Verbindet alle isolierten Module miteinander und verwaltet den globalen Zustand (`state`) der Anwendung.
- **Aufgaben**: 
  - Instanziiert die Unter-Klassen (UI-Manager, Services, Prozessoren).
  - Reagiert auf High-Level-Events und orchestriert die Workflows (z.B. `switchMode()`, `translate()`).
  - Dient als zentrale Kommunikations-Schnittstelle (`this.app`), die bei Bedarf an Untermodule übergeben wird (Dependency Injection).

### 2. UI-Management (z.B. `UIManager.js`)
- **Zweck**: Die einzige Datei, die tiefe Eingriffe ins DOM vornehmen sollte.
- **Aufgaben**:
  - `document.getElementById`, `querySelector` und DOM-Referenzen bündeln.
  - Event-Listener für UI-Buttons registrieren.
  - Klassen hinzufügen/entfernen, Sichtbarkeiten toggeln (Loading-Skeleton, Error-Messages).
- **Regel**: Der UI-Manager trifft **keine** Entscheidungen über Business-Logik. Er nimmt Befehle vom Orchestrator entgegen (`showSkeleton()`, `updateOutputUI()`).

### 3. API & Services (z.B. `LanguageService.js`)
- **Zweck**: Abkapselung jeglicher Server-Kommunikation.
- **Aufgaben**:
  - `fetch()` Calls, Error-Handling für Network-Requests, Abfangen von Backend-Errors (z.B. 422, 500).
  - Formatierung von Payloads.
- **Regel**: Services dürfen **niemals** auf das DOM zugreifen. Sie empfangen Parameter und geben Promises/Datenstrukturen zurück.

### 4. Domain & Business Logic (z.B. `SentenceProcessor.js`, `TextProcessor.js`)
- **Zweck**: Ausgelagerte Logik für spezifische Themenbereiche, um den Orchestrator schlank zu halten.
- **Aufgaben**:
  - `TextProcessor`: String-Manipulation, Tokenisierung, Regex-Aufspaltung.
  - `SentenceProcessor`: Verwaltung der interaktiven Element-Logik (z.B. Board-Rendern, Context-Menus, Differenz-Berechnung).
  - `GlossaryManager` / `DocumentTranslator`: Verwaltung von ganz dedizierten Feature-Workflows.

### 5. Config & Constants (z.B. `Constants.js`)
- **Zweck**: Zentrale Sammelstelle für Hardcoded-Werte.
- **Aufgaben**:
  - Mapping von IDs (`DOM_IDS`).
  - Sammeln von Übersetzungen aus Laravel (z.B. über ein in `window` injiziertes Objekt).
  - Statische Arrays und Typen.

### 6. Utilities (z.B. `Utils.js`, `Debug.js`)
- **Zweck**: Wiederverwendbare, reine Funktionen (Pure Functions).
- **Aufgaben**:
  - `escapeHtml`, `debounce`, `formatDate`.
  - Konsolen-Logging über einen kontrollierten Debugger kapseln.

---

## Schritt-für-Schritt Refactoring-Guide

Wenn eine monolithische `legacy.js` umgebaut werden soll, empfiehlt sich dieses schrittweise Vorgehen, um die Applikation funktionsfähig zu halten:

### Schritt 1: Constants & Utils extrahieren
1. Erstelle `Constants.js` und schiebe alle Konfigurations-Objekte, Magic Strings und DOM-IDs dorthin.
2. Finde alle Helper-Funktionen (die kein externes `this` benötigen) und verschiebe sie in `Utils.js`.

### Schritt 2: Service Layer extrahieren
1. Suche alle `fetch(url)` oder `axios`-Aufrufe.
2. Ziehe diese in eine neue Klasse `YourFeatureService.js`.
3. Gib aus den Funktionen saubere Daten via `return await response.json()` zurück, sodass die Logik im Monolithen nun `await service.fetchData()` aufruft.

### Schritt 3: UI-Manager erstellen
1. Erstelle `UIManager.js`.
2. Lagere die DOM-Referenzierung (`document.getElementById`) in eine `initElements()` Funktion aus.
3. Lagere reine DOM-Manipulationen (z.B. `hideLoadingSpinner()`) in den Manager aus.

### Schritt 4: Domain Processors auslagern
1. Gruppiere verbleibende Funktionalitäten thematisch. 
2. Alles, was mit einem bestimmten Sub-Feature zu tun hat (z.B. Drag & Drop Upload, oder Canvas-Rendering), in eigene `Manager`-Klassen extrahieren.
3. Übergebe dem Manager bei Instanziierung ggf. den Zugriff auf die Haupt-App: `new DragDropManager(this)`.

### Schritt 5: Orchestrator bauen & Entrypoint anpassen
1. Der Restbestand der alten monolithischen Datei wird zum Orchestrator (`YourFeatureApp.js`).
2. Passe die Blade-Templates an, damit sie das Script als Module laden: `<script type="module" src="..."></script>`.
3. Entferne alte `window.onload` oder `document.addEventListener('DOMContentLoaded')` Logiken und baue am Ende des Orchestrators die saubere Initialisierung:
   ```javascript
   document.addEventListener('DOMContentLoaded', () => {
       window.app = new YourFeatureApp();
   });
   ```

---

## Best Practices & Stolpersteine

- **Scope von `this`**: Bei Callbacks und Event-Listenern zwingend Arrow-Functions `(e) => this.method(e)` verwenden, da sonst der Kontext von `this` verloren geht.
- **Zirkuläre Abhängigkeiten**: Vermeide, dass sich Module gegenseitig importieren. Die Kommunikation sollte idealerweise "Top-Down" erfolgen (Orchestrator -> Modul) oder via Callbacks / Event-Buses.
- **DOM-Abhängigkeiten minimieren**: Je weniger ein Modul vom HTML-Layout weiß, desto besser übersteht es spätere UI/UX-Anpassungen. HTML-Strukturen (z.B. Template-Strings) sollten möglichst zentralisiert erzeugt werden.
- **Fehlerbehandlung**: Try-Catch Blöcke sollten im Orchestrator oder im Service-Layer abgefangen und via `UIManager.showError()` benutzerfreundlich visualisiert werden.
