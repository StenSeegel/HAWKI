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
<div class="group-header lang-group-header">
    <div class="language-selector-wrapper">
        <div class="custom-dropdown" id="sourceLangDropdown">
            <div class="dropdown-trigger">
                <span class="selected-text">{{ $translation["AutoDetect"] ?? "Automatisch" }}</span>
                <x-icon name="chevron-down" class="dropdown-arrow" />
            </div>
            <div class="dropdown-menu">
                <div class="dropdown-item selected" data-value="auto">{{ $translation["AutoDetect"] ?? "Automatisch" }}</div>
                <div class="dropdown-item" data-value="en-gb">English (UK)</div>
                <div class="dropdown-item" data-value="en-us">English (US)</div>
                <div class="dropdown-item" data-value="de">Deutsch</div>
                <div class="dropdown-item" data-value="uk">Українська</div>
                <div class="dropdown-item" data-value="fr">Français</div>
                <div class="dropdown-item" data-value="es">Español</div>
                <div class="dropdown-item" data-value="it">Italiano</div>
                <div class="dropdown-item" data-value="nl">Nederlands</div>
                <div class="dropdown-item" data-value="pl">Polski</div>
                <div class="dropdown-item" data-value="pt">Português</div>
                <div class="dropdown-item" data-value="ru">Русский</div>
                <div class="dropdown-item" data-value="zh">中文</div>
                <div class="dropdown-item" data-value="ja">日本語</div>
            </div>
            <select id="sourceLang" class="styleless-select" style="display: none;">
                <option value="auto" selected>{{ $translation["AutoDetect"] ?? "Automatisch" }}</option>
                <option value="en-gb">English (UK)</option>
                <option value="en-us">English (US)</option>
                <option value="de">Deutsch</option>
                <option value="uk">Українська</option>
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
    
    <button type="button" id="swapLanguagesBtn" class="btn-icon-only-sm tooltip-parent">
        <x-icon name="swap"/>
        <div class="tooltip">{{ $translation['SwapLanguages'] ?? 'Sprachen tauschen' }}</div>
    </button>

    <div class="language-selector-wrapper">
        <div class="custom-dropdown" id="targetLangDropdown">
            <div class="dropdown-trigger">
                <span class="selected-text">{{ $defaultTarget === 'en' ? 'English (UK)' : ($defaultTarget === 'de' ? 'Deutsch' : 'English (UK)') }}</span>
                <x-icon name="chevron-down" class="dropdown-arrow" />
            </div>
            <div class="dropdown-menu">
                <div class="dropdown-item {{ $defaultTarget === 'en' ? 'selected' : '' }}" data-value="en-gb">English (UK)</div>
                <div class="dropdown-item" data-value="en-us">English (US)</div>
                <div class="dropdown-item {{ $defaultTarget === 'de' ? 'selected' : '' }}" data-value="de">Deutsch</div>
                <div class="dropdown-item" data-value="uk">Українська</div>
                <div class="dropdown-item {{ $defaultTarget === 'fr' ? 'selected' : '' }}" data-value="fr">Français</div>
                <div class="dropdown-item {{ $defaultTarget === 'es' ? 'selected' : '' }}" data-value="es">Español</div>
                <div class="dropdown-item {{ $defaultTarget === 'it' ? 'selected' : '' }}" data-value="it">Italiano</div>
                <div class="dropdown-item {{ $defaultTarget === 'nl' ? 'selected' : '' }}" data-value="nl">Nederlands</div>
                <div class="dropdown-item {{ $defaultTarget === 'pl' ? 'selected' : '' }}" data-value="pl">Polski</div>
                <div class="dropdown-item {{ $defaultTarget === 'pt' ? 'selected' : '' }}" data-value="pt">Português</div>
                <div class="dropdown-item {{ $defaultTarget === 'ru' ? 'selected' : '' }}" data-value="ru">Русский</div>
                <div class="dropdown-item {{ $defaultTarget === 'zh' ? 'selected' : '' }}" data-value="zh">中文</div>
                <div class="dropdown-item {{ $defaultTarget === 'ja' ? 'selected' : '' }}" data-value="ja">日本語</div>
            </div>
            <select id="targetLang" class="styleless-select" style="display: none;">
                <option value="en-gb" {{ $defaultTarget === 'en' ? 'selected' : '' }}>English (UK)</option>
                <option value="en-us">English (US)</option>
                <option value="de" {{ $defaultTarget === 'de' ? 'selected' : '' }}>Deutsch</option>
                <option value="uk">Українська</option>
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
</div>
