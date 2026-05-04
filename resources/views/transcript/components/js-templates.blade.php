{{-- Transcript UI JavaScript Templates --}}

{{-- Export Manager: Check Data --}}
<template id="tmpl-export-check-data">
    <div class="export-state-container export-state-check">
        <div class="loading-spinner export-check-spinner"></div>
        <p class="export-state-text">Prüfe Daten...</p>
    </div>
</template>

{{-- Export Manager: Loading Summary --}}
<template id="tmpl-export-loading-summary">
    <div class="export-state-container export-state-loading">
        <div class="loader-spinner export-loading-spinner"></div>
        <p class="export-state-text">Ergebnisprotokoll wird generiert (KI)...</p>
        <p class="export-state-subtext">Dies kann je nach Länge des Transkripts einen Moment dauern.</p>
    </div>
</template>

{{-- Export Manager: Error --}}
<template id="tmpl-export-error">
    <div class="export-state-container export-state-error">
        <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        <p class="export-state-title">Generierung fehlgeschlagen</p>
        <p class="export-state-subtext error-msg"></p>
        <button class="btn-sidebar-action" onclick="window.generateErgebnisprotokoll()" style="margin-top: 15px;">Erneut versuchen</button>
    </div>
</template>

{{-- Export Manager: Generate Prompt --}}
<template id="tmpl-export-generate-prompt">
    <div class="export-state-container export-state-generate">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="export-generate-icon">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
        </svg>
        <h3 class="export-state-title">Ergebnisprotokoll generieren</h3>
        <p class="export-state-subtext">Erstelle eine KI-gestützte Zusammenfassung des aktuellen Transkripts. Dieser Vorgang dauert etwa 10-20 Sekunden.</p>
        <button onclick="window.generateErgebnisprotokoll()" class="btn-primary-blue">Jetzt generieren</button>
    </div>
</template>

{{-- History Manager: Confirm Title Edit Button --}}
<template id="tmpl-history-confirm-btn">
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
</template>

{{-- History Manager: Cancel Title Edit Button --}}
<template id="tmpl-history-cancel-btn">
    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
</template>

{{-- Transcript UI: Loading Save Button --}}
<template id="tmpl-ui-btn-saving">
    <div class="loader-spinner"></div> Speichern...
</template>

{{-- Transcript UI: Saved Button --}}
<template id="tmpl-ui-btn-saved">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> Gespeichert
</template>

{{-- Transcript UI: Copy Success Button --}}
<template id="tmpl-ui-btn-copy-success">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
</template>

{{-- Segment Processor: Context Menu Edit --}}
<template id="tmpl-context-edit">
    <div class="transcript-context-menu-item" id="context-edit">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>
        Text bearbeiten
    </div>
</template>

{{-- Segment Processor: Context Menu Redact --}}
<template id="tmpl-context-redact">
    <div class="transcript-context-menu-item" id="context-redact">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
        [Ausblenden]
    </div>
</template>

{{-- Segment Processor: Context Menu Unredact --}}
<template id="tmpl-context-unredact">
    <div class="transcript-context-menu-item" id="context-unredact">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
        [Einblenden]
    </div>
</template>

{{-- Segment Processor: Sentence Reorder Toolbar --}}
<template id="tmpl-sentence-reorder">
    <button class="reorder-btn move-up" title="Satz nach oben verschieben">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="19" x2="12" y2="5"></line><polyline points="5 12 12 5 19 12"></polyline></svg>
    </button>
    <button class="reorder-btn move-down" title="Satz nach unten verschieben">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><polyline points="19 12 12 19 5 12"></polyline></svg>
    </button>
    <div class="reorder-divider"></div>
    <button class="reorder-btn close-toolbar" title="Schließen">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
    </button>
</template>
