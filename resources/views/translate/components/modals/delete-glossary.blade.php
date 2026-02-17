<!-- Delete Confirmation Modal -->
<div id="deleteGlossaryModalOverlay" class="glossary-modal-overlay" style="display: none;">
    <div class="glossary-modal" style="max-width: 450px;">
        <div class="glossary-modal-header">
            <h3>{{ $translation["DeleteGlossary"] ?? "Glossar löschen" }}</h3>
            <button class="glossary-close-btn" id="deleteGlossaryCloseBtn">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        
        <div class="glossary-modal-content">
            <p style="margin: 0; color: var(--text-color);">
                {{ $translation["DeleteGlossaryConfirm"] ?? "Möchten Sie dieses Glossar wirklich unwiderruflich löschen?" }}
            </p>
            
            <div class="glossary-footer-actions" style="margin-top: 1.5rem;">
                <button class="btn-secondary" id="deleteCancelBtn">{{ $translation["Abort"] ?? "Abbrechen" }}</button>
                <button class="btn-danger" id="deleteConfirmBtn">{{ $translation["Delete"] ?? "Löschen" }}</button>
            </div>
        </div>
    </div>
</div>
