<!-- Spalte 1: Eingabetext -->
<div class="board-panel">
    <div class="panel-content">
        <button type="button" id="deleteSourceBtn" class="delete-overlay-btn tooltip-parent" style="display: none;">
            <x-icon name="x"/>
            <div class="tooltip">{{ $translation["DeleteSourceToolTip"] ?? "Quelltext löschen" }}</div>
        </button>
        <textarea id="sourceText" class="text-input" placeholder=" " maxlength="50000"></textarea>
        <div class="rich-placeholder" onclick="document.getElementById('sourceText').focus()">
            <div class="placeholder-title">{{ $translation["Translate_Placeholder_Title"] ?? "Type to translate." }}</div>
            <div class="placeholder-subtitle">{{ $translation["Translate_Placeholder_Subtitle"] ?? "Drag and drop to translate PDF, Word (.docx), and PowerPoint (.pptx) files with our document translator." }}</div>
        </div>
    </div>
    <div class="panel-footer">
        <div class="footer-info">
            <span id="charCount">0</span> / 50.000 {{ $translation["Characters"] ?? "Zeichen" }}
        </div>
        <button type="button" id="copyInputBtn" class="btn-xs reaction-button fast-access-btn tooltip-parent" onmousedown="reactionMouseDown(this);" onmouseup="reactionMouseUp(this)" style="border:none;">
            <x-icon name="copy"/>
            <div class="reaction">{{ $translation["CopiedToolTip"] ?? "Kopiert!" }}</div>
            <div class="tooltip">{{ $translation["CopyToolTip"] ?? "Kopieren" }}</div>
        </button>
    </div>
</div>
