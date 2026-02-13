<!-- Spalte 2: Ergebnistext -->
<div class="board-panel">
    <div class="panel-content relative">
        <textarea id="translatedText" class="text-input" placeholder="{{ $translation["Translate_OutputPlaceholder"] ?? "Der überarbeitete Text erscheint hier..." }}" readonly></textarea>
    </div>
    <div class="panel-footer">
        <div class="footer-info">
            <span id="targetCharCount">0</span> {{ $translation["Characters"] ?? "Zeichen" }}
        </div>
        <button type="button" id="copyOutputBtn" class="btn-xs reaction-button fast-access-btn tooltip-parent" onmousedown="reactionMouseDown(this);" onmouseup="reactionMouseUp(this)" style="border:none;">
            <x-icon name="copy"/>
            <div class="reaction">Kopiert!</div>
            <div class="tooltip">Kopieren</div>
        </button>
    </div>
</div>
