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
                <div class="dropdown-item" data-value="en-gb">{{ $translation['LangEnGb'] ?? 'English (UK)' }}</div>
                <div class="dropdown-item" data-value="en-us">{{ $translation['LangEnUs'] ?? 'English (US)' }}</div>
                <div class="dropdown-item" data-value="de">{{ $translation['LangDe'] ?? 'Deutsch' }}</div>
                <div class="dropdown-item" data-value="uk">{{ $translation['LangUk'] ?? 'Українська' }}</div>
                <div class="dropdown-item" data-value="fr">{{ $translation['LangFr'] ?? 'Français' }}</div>
                <div class="dropdown-item" data-value="es">{{ $translation['LangEs'] ?? 'Español' }}</div>
                <div class="dropdown-item" data-value="it">{{ $translation['LangIt'] ?? 'Italiano' }}</div>
                <div class="dropdown-item" data-value="nl">{{ $translation['LangNl'] ?? 'Nederlands' }}</div>
                <div class="dropdown-item" data-value="pl">{{ $translation['LangPl'] ?? 'Polski' }}</div>
                <div class="dropdown-item" data-value="pt">{{ $translation['LangPt'] ?? 'Português' }}</div>
                <div class="dropdown-item" data-value="ru">{{ $translation['LangRu'] ?? 'Русский' }}</div>
                <div class="dropdown-item" data-value="zh">{{ $translation['LangZh'] ?? '中文' }}</div>
                <div class="dropdown-item" data-value="ja">{{ $translation['LangJa'] ?? '日本語' }}</div>
            </div>
            <select id="sourceLang" class="styleless-select" style="display: none;">
                <option value="auto" selected>{{ $translation["AutoDetect"] ?? "Automatisch" }}</option>
                <option value="en-gb">{{ $translation['LangEnGb'] ?? 'English (UK)' }}</option>
                <option value="en-us">{{ $translation['LangEnUs'] ?? 'English (US)' }}</option>
                <option value="de">{{ $translation['LangDe'] ?? 'Deutsch' }}</option>
                <option value="uk">{{ $translation['LangUk'] ?? 'Українська' }}</option>
                <option value="fr">{{ $translation['LangFr'] ?? 'Français' }}</option>
                <option value="es">{{ $translation['LangEs'] ?? 'Español' }}</option>
                <option value="it">{{ $translation['LangIt'] ?? 'Italiano' }}</option>
                <option value="nl">{{ $translation['LangNl'] ?? 'Nederlands' }}</option>
                <option value="pl">{{ $translation['LangPl'] ?? 'Polski' }}</option>
                <option value="pt">{{ $translation['LangPt'] ?? 'Português' }}</option>
                <option value="ru">{{ $translation['LangRu'] ?? 'Русский' }}</option>
                <option value="zh">{{ $translation['LangZh'] ?? '中文' }}</option>
                <option value="ja">{{ $translation['LangJa'] ?? '日本語' }}</option>
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
                <span class="selected-text">{{ $defaultTarget === 'en' ? ($translation['LangEnGb'] ?? 'English (UK)') : ($defaultTarget === 'de' ? ($translation['LangDe'] ?? 'Deutsch') : ($translation['LangEnGb'] ?? 'English (UK)')) }}</span>
                <x-icon name="chevron-down" class="dropdown-arrow" />
            </div>
            <div class="dropdown-menu">
                <div class="dropdown-item {{ $defaultTarget === 'en' ? 'selected' : '' }}" data-value="en-gb">{{ $translation['LangEnGb'] ?? 'English (UK)' }}</div>
                <div class="dropdown-item" data-value="en-us">{{ $translation['LangEnUs'] ?? 'English (US)' }}</div>
                <div class="dropdown-item {{ $defaultTarget === 'de' ? 'selected' : '' }}" data-value="de">{{ $translation['LangDe'] ?? 'Deutsch' }}</div>
                <div class="dropdown-item" data-value="uk">{{ $translation['LangUk'] ?? 'Українська' }}</div>
                <div class="dropdown-item {{ $defaultTarget === 'fr' ? 'selected' : '' }}" data-value="fr">{{ $translation['LangFr'] ?? 'Français' }}</div>
                <div class="dropdown-item {{ $defaultTarget === 'es' ? 'selected' : '' }}" data-value="es">{{ $translation['LangEs'] ?? 'Español' }}</div>
                <div class="dropdown-item {{ $defaultTarget === 'it' ? 'selected' : '' }}" data-value="it">{{ $translation['LangIt'] ?? 'Italiano' }}</div>
                <div class="dropdown-item {{ $defaultTarget === 'nl' ? 'selected' : '' }}" data-value="nl">{{ $translation['LangNl'] ?? 'Nederlands' }}</div>
                <div class="dropdown-item {{ $defaultTarget === 'pl' ? 'selected' : '' }}" data-value="pl">{{ $translation['LangPl'] ?? 'Polski' }}</div>
                <div class="dropdown-item {{ $defaultTarget === 'pt' ? 'selected' : '' }}" data-value="pt">{{ $translation['LangPt'] ?? 'Português' }}</div>
                <div class="dropdown-item {{ $defaultTarget === 'ru' ? 'selected' : '' }}" data-value="ru">{{ $translation['LangRu'] ?? 'Русский' }}</div>
                <div class="dropdown-item {{ $defaultTarget === 'zh' ? 'selected' : '' }}" data-value="zh">{{ $translation['LangZh'] ?? '中文' }}</div>
                <div class="dropdown-item {{ $defaultTarget === 'ja' ? 'selected' : '' }}" data-value="ja">{{ $translation['LangJa'] ?? '日本語' }}</div>
            </div>
            <select id="targetLang" class="styleless-select" style="display: none;">
                <option value="en-gb" {{ $defaultTarget === 'en' ? 'selected' : '' }}>{{ $translation['LangEnGb'] ?? 'English (UK)' }}</option>
                <option value="en-us">{{ $translation['LangEnUs'] ?? 'English (US)' }}</option>
                <option value="de" {{ $defaultTarget === 'de' ? 'selected' : '' }}>{{ $translation['LangDe'] ?? 'Deutsch' }}</option>
                <option value="uk">{{ $translation['LangUk'] ?? 'Українська' }}</option>
                <option value="fr" {{ $defaultTarget === 'fr' ? 'selected' : '' }}>{{ $translation['LangFr'] ?? 'Français' }}</option>
                <option value="es" {{ $defaultTarget === 'es' ? 'selected' : '' }}>{{ $translation['LangEs'] ?? 'Español' }}</option>
                <option value="it" {{ $defaultTarget === 'it' ? 'selected' : '' }}>{{ $translation['LangIt'] ?? 'Italiano' }}</option>
                <option value="nl" {{ $defaultTarget === 'nl' ? 'selected' : '' }}>{{ $translation['LangNl'] ?? 'Nederlands' }}</option>
                <option value="pl" {{ $defaultTarget === 'pl' ? 'selected' : '' }}>{{ $translation['LangPl'] ?? 'Polski' }}</option>
                <option value="pt" {{ $defaultTarget === 'pt' ? 'selected' : '' }}>{{ $translation['LangPt'] ?? 'Português' }}</option>
                <option value="ru" {{ $defaultTarget === 'ru' ? 'selected' : '' }}>{{ $translation['LangRu'] ?? 'Русский' }}</option>
                <option value="zh" {{ $defaultTarget === 'zh' ? 'selected' : '' }}>{{ $translation['LangZh'] ?? '中文' }}</option>
                <option value="ja" {{ $defaultTarget === 'ja' ? 'selected' : '' }}>{{ $translation['LangJa'] ?? '日本語' }}</option>
            </select>
        </div>
    </div>
</div>
