<!-- Shared Header -->
@php
    // Determine default target language based on user locale
    // If user locale matches a language, default target to the "other" main language
    $defaultTarget = match($userLocale ?? 'en') {
        'de' => 'en',
        'en' => 'de',
        default => 'en',
    };
@endphp
<div class="group-header">
    <div class="language-selector-wrapper">
            <select id="sourceLang" class="styleless-select">
            <option value="auto" selected>{{ $translation["AutoDetect"] ?? "Automatisch" }}</option>
            <option value="en">English</option>
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
    
    <button type="button" id="swapLanguagesBtn" class="btn-icon-only-sm tooltip-parent" style="position: relative;">
        <x-icon name="swap"/>
        <div class="tooltip" style="bottom: -30px; top: auto; white-space: nowrap;">{{ $translation['SwapLanguages'] ?? 'Sprachen tauschen' }}</div>
    </button>

    <div class="language-selector-wrapper">
            <select id="targetLang" class="styleless-select">
            <option value="en" {{ $defaultTarget === 'en' ? 'selected' : '' }}>English</option>
            <option value="de" {{ $defaultTarget === 'de' ? 'selected' : '' }}>Deutsch</option>
            <option value="fr" {{ $defaultTarget === 'fr' ? 'selected' : '' }}>Français</option>
            <option value="es" {{ $defaultTarget === 'es' ? 'selected' : '' }}>Español</option>
            <option value="it" {{ $defaultTarget === 'it' ? 'selected' : '' }}>Italiano</option>
            <option value="nl" {{ $defaultTarget === 'nl' ? 'selected' : '' }}>Nederlands</option>
            <option value="pl" {{ $defaultTarget === 'pl' ? 'selected' : '' }}>Polski</option>
            <option value="pt" {{ $defaultTarget === 'pt' ? 'selected' : '' }}>Português</option>
            <option value="ru" {{ $defaultTarget === 'ru' ? 'selected' : '' }}>Русский</option>
            <option value="zh" {{ $defaultTarget === 'zh' ? 'selected' : '' }}>中文</option>
            <option value="ja" {{ $defaultTarget === 'ja' ? 'selected' : '' }}>日本語</option>
        </select>
    </div>
</div>
