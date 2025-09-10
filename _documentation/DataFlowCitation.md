## 🔍 **Aktueller DataFlow mit CitationService**:

### **1. Google Provider** - Non-Streaming & Streaming:
```php
// GoogleProvider.php formatResponse() & formatStreamChunk()
if (!empty($rawGroundingMetadata)) {
    $formattedCitations = $this->citationService->formatCitations('google', $rawGroundingMetadata, $content);
    $groundingMetadata = $formattedCitations;
}
```

### **2. Anthropic Provider**:
```php
// AnthropicProvider.php
$result = $this->citationService->formatCitations('anthropic', $providerData, $messageText);
```

### **3. OpenAI Responses Provider**:
```php
// OpenAIResponsesProvider.php  
$formattedCitations = $this->citationService->formatCitations('openai_responses', $citationData, $messageText);
```

## 🎯 **Kompletter Citation DataFlow**:

```
AI Provider API Response (with citation metadata)
           ↓
Provider.formatStreamChunk() / formatResponse()
           ↓
CitationService.formatCitations(provider, rawData, content)
           ↓
Provider-Specific Formatter (GoogleCitationFormatter, AnthropicCitationFormatter, OpenAIResponsesCitationFormatter)
           ↓
Standardisierte HAWKI Citation Format v1
           ↓
Frontend (als Teil von content.groundingMetadata -> JS)
```

## 📊 **CitationService Architektur**:

1. **Unified Interface**: Einheitliche `formatCitations(provider, providerData, messageText)` Methode für alle Provider
2. **Provider-Specific Formatters**: 
   - `GoogleCitationFormatter` - Verarbeitet Google Gemini's grounding metadata
   - `AnthropicCitationFormatter` - Verarbeitet Anthropic's citation format  
   - `OpenAIResponsesCitationFormatter` - Verarbeitet OpenAI Responses API citations
3. **Automatic Registration**: Default-Formatter werden automatisch im Service registriert
4. **Interface-Based**: Alle Formatter implementieren `CitationFormatterInterface`
5. **HAWKI v1 Format**: Alle Formatter konvertieren in einheitliches HAWKI Citation Format v1
6. **Processing Modes**: Unterstützt `segments` (Google-style) und `inline` (Anthropic-style) Processing
7. **Null-Safe**: Gibt `null` zurück wenn keine Citations vorhanden (`hasCitations()` check)

## 💡 **Warum das wichtig ist**:

- **Multi-Provider Citations**: Google Gemini, Anthropic Claude und OpenAI Responses unterstützen alle Citations
- **Google Search Integration**: Google Gemini kann Web-Suchergebnisse einbeziehen
- **Citation Formatting**: Rohe Provider-Metadaten werden in einheitliches HAWKI Format v1 konvertiert  
- **Provider Abstraction**: Jeder Provider kann eigene Citation-Formate haben, aber Output ist standardisiert
- **Processing Flexibility**: Unterstützt sowohl segment-basierte (Google) als auch inline-basierte (Anthropic) Citation-Verarbeitung
- **Frontend Consistency**: Einheitliche Citation-Darstellung unabhängig vom Provider
- **OpenAI Responses Integration**: Unterstützt komplexe annotation-basierte Citations mit start/end indices

**Der CitationService ist ein zentraler und aktiver Teil des DataFlows für alle Provider mit Citation-Funktionalität!** 🎯

## 📋 **HAWKI Citation Format v1**:

Das standardisierte Citation Format, das alle Provider-Formatter produzieren:

```json
{
  "format": "hawki_v1",
  "processing_mode": "segments|inline",
  "citations": [
    {
      "id": 1,
      "title": "Source Title",
      "url": "https://example.com",
      "snippet": "Relevant text snippet (optional)"
    }
  ],
  "text_processing": {
    "mode": "segments|inline",
    "text_segments": [
      {
        "text": "Text that needs citations",
        "citationIds": [1, 2]
      }
    ],
    "inline_markers": true  // für inline processing
  },
  "searchMetadata": {
    "query": "Original search query",
    "renderedContent": "Provider-specific rendered content",
    "queries": ["Multiple", "search", "queries"]  // für OpenAI Responses
  },
  "textSegments": []  // Legacy support für Backwards Compatibility
}
```

### **Processing Modes**:
- **`segments`**: Google-style, text wird in Segmente mit spezifischen Citation-IDs aufgeteilt
- **`inline`**: Anthropic/OpenAI-style, Citations werden als `[1]`, `[2]` Marker im Text erkannt

## ✅ **Frontend Citation DataFlow** 🎯

## 🔍 **Kompletter Frontend Citation DataFlow**:

### **1. Content Parsing** (`deconstContent` - Zeile 392):
```javascript
function deconstContent(inputContent){
    let messageText = '';
    let groundingMetadata = '';
    
    if(isValidJson(inputContent)){
        const json = JSON.parse(inputContent);
        
        if(json.hasOwnProperty('groundingMetadata')){
            groundingMetadata = json.groundingMetadata; // ← Hier wird es extrahiert!
        }
        if(json.hasOwnProperty('text')){
            messageText = json.text;
        }
    }
    
    return { messageText, groundingMetadata }
}
```

### **2. Message Processing** (`formatMessage` - Zeile 58):
```javascript
function formatMessage(rawContent, groundingMetadata = '') {
    // Process citations and preserve HTML elements in one step
    let contentToProcess = formatCitations(rawContent, groundingMetadata); // ← Citation Processing
    
    // Apply markdown rendering
    const markdownProcessed = md.render(processedContent);
    
    // Restore preserved HTML elements
    let finalContent = restoreCitations(finalContent);
    return finalContent;
}
```

### **3. Citation Formatting** (`formatCitations` - Zeile 310+):
```javascript
function formatCitations(content, groundingMetadata = '') {
    // Return early if no citation metadata
    if (!groundingMetadata || typeof groundingMetadata !== 'object') {
        return content;
    }
    // [Citation processing logic]
}
```

### **4. Search Metadata Rendering** (`addSearchRenderedContent` - Zeile 273):
```javascript
function addSearchRenderedContent(messageElement, groundingMetadata){
    if (groundingMetadata && typeof groundingMetadata === 'object' &&
        groundingMetadata.searchMetadata &&
        groundingMetadata.searchMetadata.renderedContent) {
                
        const render = groundingMetadata.searchMetadata.renderedContent;
        // Extract the HTML and add it to the message
        const parser = new DOMParser();
        const doc = parser.parseFromString(render, 'text/html');
        const divElement = doc.querySelector('.container');
        
        // Create google-search span and append to message
        let googleSpan = document.createElement('span');
        googleSpan.classList.add('google-search');
        googleSpan.innerHTML = divElement.outerHTML; 
        messageContent.appendChild(googleSpan);
    }
}
```

## 🎯 **Kompletter Citation DataFlow**:

```
Backend: CitationService.formatCitations()
           ↓
JSON: {"text": "...", "groundingMetadata": {...}}
           ↓
Frontend: deconstContent() → extrahiert groundingMetadata
           ↓
formatMessage(messageText, groundingMetadata)
           ↓
formatCitations() → Verarbeitet Citations im Text
           ↓
addSearchRenderedContent() → Fügt Google Search UI hinzu
           ↓
DOM: Citation-Links + Google Search Chips im Message
```

## 📊 **Was passiert konkret**:

1. **Text Citations**: `formatCitations()` ersetzt Citation-Marker im Text durch klickbare Links
2. **Search Metadata**: `addSearchRenderedContent()` fügt Google Search-Chips unterhalb der Nachricht hinzu
3. **HTML Preservation**: Citations werden während Markdown-Processing geschützt
4. **UI Integration**: Search-Results werden als `.google-search` span in `.message-content` eingefügt

## 💡 **Frontend Citation Features**:

- ✅ **Inline Citations**: Links im Text für Quellenverweise
- ✅ **Search Chips**: Interaktive Google Search-Ergebnisse
- ✅ **External Links**: `target="_blank"` für alle Citation-Links  
- ✅ **Markdown Safe**: Citations überleben Markdown-Processing
- ✅ **Dynamic Updates**: Funktioniert mit Streaming und statischen Messages

**Das Frontend hat ein vollständiges Citation-System das sowohl Inline-Citations als auch Google Search-UI unterstützt!** 🚀