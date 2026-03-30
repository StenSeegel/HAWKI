<!-- Spalte 2: Ergebnistext -->
<div class="board-panel">
    <div class="panel-content relative">
        <div id="outputSkeleton" class="skeleton-screen">
            <div class="skeleton-line"></div>
            <div class="skeleton-line"></div>
            <div class="skeleton-line"></div>
            <div class="skeleton-line"></div>
            <div class="skeleton-line"></div>
            <div class="skeleton-line"></div>
        </div>
        <textarea id="translatedText" class="text-input" readonly></textarea>
        <div id="diffView" class="diff-view" style="display: none;"></div>
    </div>
    <div class="panel-footer">
        <div class="footer-info">
            <span id="targetCharCount">0</span> {{ $translation["Characters"] ?? "Zeichen" }}
        </div>
        <div class="footer-actions" style="display: flex; gap: 0.5rem;">
            <button type="button" id="improveTargetBtn" class="btn-xs reaction-button fast-access-btn tooltip-parent" style="border:none; display:none;">
                <x-icon name="edit"/>
                <div class="tooltip">{{ $translation["ImproveTargetText"] ?? "Text überarbeiten" }}</div>
            </button>
            <button type="button" id="translateTargetBtn" class="btn-xs reaction-button fast-access-btn tooltip-parent" style="border:none; display:none;">
                <x-icon name="translate-icon"/>
                <div class="tooltip">{{ $translation["TranslateTargetText"] ?? "Text übersetzen" }}</div>
            </button>
            <button type="button" id="copyOutputBtn" class="btn-xs reaction-button fast-access-btn tooltip-parent" onmousedown="reactionMouseDown(this);" onmouseup="reactionMouseUp(this)" style="border:none;">
                <x-icon name="copy"/>
                <div class="reaction">{{ $translation["CopiedToolTip"] ?? "Kopiert!" }}</div>
                <div class="tooltip">{{ $translation["CopyToolTip"] ?? "Kopieren" }}</div>
            </button>
        </div>
    </div>
</div>
