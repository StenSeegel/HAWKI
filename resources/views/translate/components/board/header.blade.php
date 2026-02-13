<!-- Shared Header -->
<div class="group-header">
    <div class="language-selector-wrapper">
            <select id="sourceLang" class="styleless-select">
            <option value="auto">Auto Detect</option>
            <option value="en">English</option>
            <option value="de" selected>Deutsch</option>
            <option value="fr">Français</option>
            <option value="es">Español</option>
            <option value="it">Italiano</option>
            <option value="nl">Nederlands</option>
            <option value="pl">Polski</option>
            <option value="pt">Português</option>
            <option value="ru">Русский</option>
            <option value="zh">中文</option>
            <option value="ja">日本語</option>
        </select>
    </div>
    
    <button type="button" id="swapLanguagesBtn" class="btn-icon-only-sm tooltip-parent" style="position: relative;">
        <x-icon name="swap"/>
        <div class="tooltip" style="bottom: -30px; top: auto; white-space: nowrap;">{{ $translation['SwapLanguages'] ?? 'Sprachen tauschen' }}</div>
    </button>

    <div class="language-selector-wrapper">
            <select id="targetLang" class="styleless-select">
            <option value="en" selected>English</option>
            <option value="de">Deutsch</option>
            <option value="fr">Français</option>
            <option value="es">Español</option>
            <option value="it">Italiano</option>
            <option value="nl">Nederlands</option>
            <option value="pl">Polski</option>
            <option value="pt">Português</option>
            <option value="ru">Русский</option>
            <option value="zh">中文</option>
            <option value="ja">日本語</option>
        </select>
    </div>
</div>
