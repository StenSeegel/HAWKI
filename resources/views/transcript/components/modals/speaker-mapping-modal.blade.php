
<div class="modal" id="speaker-mapping-modal">
    <div class="modal-panel" style="max-width: 800px; width: 95%; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="modal-content-wrapper" style="flex: 1; overflow-y: auto; direction: ltr;">
            <div class="modal-content" style="padding: 20px;">
                <div id="speaker-mapping-modal-content">
                    <!-- Content injected by JS -->
                </div>
            </div>
        </div>
        <div class="modal-footer" style="padding: 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 10px; background: var(--background-main);">
             <button type="button" class="btn-lg-stroke" onclick="window.app.ui.closeSpeakerMappingModal()">{{ $translation["TranscriptClose"] ?? 'Schließen' }}</button>
             <button type="button" class="btn-lg-fill save-speaker-mapping-btn-modal" style="background-color: var(--button-color); color: var(--invert-Text-color);">{{ $translation["TranscriptSave"] ?? 'Speichern' }}</button>
        </div>
    </div>
</div>
