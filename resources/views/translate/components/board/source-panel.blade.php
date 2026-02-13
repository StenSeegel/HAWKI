<!-- Spalte 1: Eingabetext -->
<div class="board-panel">
    <div class="panel-content">
        <textarea id="sourceText" class="text-input" placeholder="{{ $translation["Translate_Placeholder"] ?? "Text zum Überarbeiten eingeben..." }}" maxlength="50000"></textarea>
    </div>
    <div class="panel-footer">
        <div class="footer-info">
            <span id="charCount">0</span> / 50.000 {{ $translation["Characters"] ?? "Zeichen" }}
        </div>
        <button type="button" id="copyInputBtn" class="btn-xs reaction-button fast-access-btn tooltip-parent" onmousedown="reactionMouseDown(this);" onmouseup="reactionMouseUp(this)" style="border:none;">
            <x-icon name="copy"/>
            <div class="reaction">Kopiert!</div>
            <div class="tooltip">Kopieren</div>
        </button>
    </div>
</div>
