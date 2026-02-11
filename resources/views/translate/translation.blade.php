@extends('layouts.home')

@section('content')
<div class="main-panel-grid">
    <div class="dy-sidebar expanded" id="translate-sidebar">
        <div class="dy-sidebar-wrapper">
            <div class="header">
                <button id="translationModeBtn" class="btn-md-stroke active">
                    <div class="icon">
                        <x-icon name="translate-icon"/>
                    </div>
                    <div class="label"><strong>{{ $translation["Translate"] ?? "Übersetzung" }}</strong></div>
                </button>
                 <button id="writingModeBtn" class="btn-md-stroke">
                    <div class="icon">
                        <x-icon name="edit"/>
                    </div>
                    <div class="label"><strong>{{ $translation["Revise"] ?? "Überarbeiten" }}</strong></div>
                </button>
            </div>
            <div class="dy-sidebar-content-panel">
                 <div class="dy-sidebar-scroll-panel">
                    <div class="sidebar-section-container">
                        
                        <div class="sidebar-section">
                            <h4 class="sidebar-group-title">{{ $translation["LanguageModel"] ?? "Sprachmodell" }}</h4>
                            <div class="sidebar-item model-selector-item" style="padding: 0;">
                                <div class="sidebar-item-icon" style="margin-left: 0.75rem;">
                                    <x-icon name="assistant-icon"/>
                                </div>
                                <select id="aiModel" class="styleless-select full-width-select" style="border: none; background: transparent; padding-left: 0.5rem;">
                                    <!-- Models loaded dynamically -->
                                </select>
                            </div>
                        </div>
                    {{--
                        <div class="sidebar-section" style="display: none;">
                            <h4 class="sidebar-group-title">{{ $translation["EditingTools"] ?? "Editing tools" }}</h4>
                            
                            <div class="sidebar-item disabled">
                                <div class="sidebar-item-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"></polyline><line x1="9" y1="20" x2="15" y2="20"></line><line x1="12" y1="4" x2="12" y2="20"></line></svg>
                                </div>
                                <span class="sidebar-item-label">{{ $translation["Formality"] ?? "Formality" }}</span>
                                <span class="badge-pro">Pro</span>
                            </div>
 
                            <div class="sidebar-item disabled">
                                <div class="sidebar-item-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                </div>
                                <span class="sidebar-item-label">{{ $translation["Clarify"] ?? "Clarify" }}</span>
                                <span class="badge-pro">Pro</span>
                            </div>

                            <div class="sidebar-item disabled">
                                <div class="sidebar-item-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                </div>
                                <span class="sidebar-item-label">{{ $translation["Rewrite"] ?? "Rewrite" }}</span>
                                <span class="badge-write-pro">Write Pro</span>
                            </div>
                        </div>
 --}}
                        <div class="sidebar-section">
                            <h4 class="sidebar-group-title">{{ $translation["Customizations"] ?? "Customizations" }}</h4>
                            
                            <div class="sidebar-item" id="glossary-btn" style="cursor: pointer;">
                                <div class="sidebar-item-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                                </div>
                                <span class="sidebar-item-label">{{ $translation["Glossaries"] ?? "Glossaries" }}</span>
                                <label class="toggle-switch">
                                    <input type="checkbox">
                                    <span class="slider round"></span>
                                </label>
                            </div>

                            <div class="sidebar-item disabled">
                                <div class="sidebar-item-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"></path></svg>
                                </div>
                                <span class="sidebar-item-label">{{ $translation["StyleRules"] ?? "Style rules" }}</span>
                                <span class="badge-pro">Pro</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dy-sidebar-expand-btn" onclick="togglePanelClass('translate-sidebar', 'expanded')">
                <x-icon name="chevron-right"/>
            </div>
        </div>
    </div>

    <div class="dy-main-panel">
        <div class="dy-main-content">
            <div class="scroll-container translate-module" id="translate">
                <div class="scroll-panel">
                    <div class="translate-container">

                    <div id="translateBoard" class="translate-board-3col">
                        <div class="board-panel-group">
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

                        <!-- Shared Footer -->
                        <div class="group-footer">
                            <button type="button" id="translateBtn" class="btn-md-stroke active">
                                <div class="label" style="text-align: center; flex: unset;">
                                    <span>{{ $translation["Translate"] ?? "Translate" }}</span>
                                </div>
                            </button>
                        </div>
                        
                        </div>

                    </div>

                    <div id="errorMessage" class="error-msg-container" style="display: none;"></div>
                    <div id="successMessage" class="success-msg-container" style="display: none;"></div>

                </div>
            </div>
        </div>
    </div>
    </div>
</div>

<style>
    .translate-container {
        width: 100%;
        max-width: 95rem;
        margin: 0 auto;
        padding: 2rem 2rem 5rem 2rem;
    }

    .translate-board-3col {
        display: grid;
        grid-template-columns: 1fr; /* Full width */
        gap: 1.5rem;
        align-items: stretch;
    }

    .board-panel-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0;
        background-color: var(--background-main);
        border: var(--border-stroke-thin);
        border-radius: var(--border-radius-normal);
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        overflow: hidden;
    }

    .group-header {
        grid-column: 1 / -1;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 1.5rem;
        padding: 0.75rem 1.5rem;
        border-bottom: var(--border-stroke-thin);
    }
    
    .language-selector-wrapper {
        position: relative;
    }

    .group-header .styleless-select {
        font-size: var(--font-sm);
        font-weight: 600;
        padding: 0.5rem 1rem;
        border-radius: var(--border-radius-tight);
    }
    
    .group-header .styleless-select:hover {
        background-color: rgba(0,0,0,0.05);
    }

    .group-footer {
        grid-column: 1 / -1;
        border-top: var(--border-stroke-thin);
        padding: 0;
        background-color: var(--background-main);
        display: flex;
    }

    .group-footer button {
        width: 100%;
        height: 100%;
        border: none;
        border-radius: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 1rem;
        cursor: pointer;
    }
    
    .group-footer button.active {
        background-color: var(--accent-color);
        color: white;
    }

    /* Reset inner panels */
    .board-panel-group .board-panel {
        border: none;
        border-radius: 0;
        box-shadow: none;
    }

    /* Vertical separator */
    .board-panel-group .board-panel:first-of-type {
        border-right: var(--border-stroke-thin);
    }

    @media (max-width: 1200px) {
        .translate-board-3col {
            grid-template-columns: 1fr; /* Stack Group and Sidebar */
        }
        .settings-panel {
            grid-column: span 1;
        }
    }

    @media (max-width: 900px) {
        .board-panel-group {
             grid-template-columns: 1fr;
        }
        .board-panel-group .board-panel:first-child {
            border-right: none;
            border-bottom: var(--border-stroke-thin);
        }
    }

    .board-panel {
        background-color: var(--background-main);
        border: var(--border-stroke-thin);
        border-radius: var(--border-radius-normal);
        display: flex;
        flex-direction: column;
        box-shadow: 0 4px 20px rgba(0,0,0,0.03);
        transition: border-color 0.2s;
        height: 100%; /* Ensure panels fill the grid cell */
    }

    .board-panel:focus-within {
        border-color: var(--accent-color);
    }

    .settings-panel {
        /* background-color: var(--background-secondary); Removed to match main panels */
    }

    .settings-content {
        padding: 0 !important;
    }

    .sidebar-section-container {
        display: flex;
        flex-direction: column;
        gap: 2rem;
        padding: 1.5rem;
    }

    .sidebar-section {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .sidebar-title {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        font-size: var(--font-xs);
        color: var(--text-color);
        margin: 0;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .sidebar-title svg {
        width: 1.1rem;
        height: 1.1rem;
        color: var(--accent-color);
    }

    .sidebar-content {
        padding-left: 0;
    }

    .setting-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .setting-label {
        font-size: var(--font-xxs);
        color: var(--text-faded-color);
        font-weight: 500;
    }

    .full-width-select {
        width: 100%;
    }

    .styleless-select.full-width-select {
        padding: 0.6rem 0.75rem;
        font-size: var(--font-xs);
        background-color: var(--background-main);
        border-radius: var(--border-radius-tight);
    }

    .styleless-select.border {
        border: var(--border-stroke-thin) !important;
    }

    .sidebar-info-text {
        font-size: var(--font-xs);
        color: var(--text-faded-color);
        line-height: 1.4;
        font-style: italic;
    }

    .translate-header {
        text-align: center;
        margin-bottom: 2.5rem;
    }

    .center-flex {
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .bottom-gap-2 {
        margin-bottom: 1.5rem;
    }

    .mode-selection.large {
        padding: 4px;
        border-radius: var(--border-radius-normal);
        background-color: var(--background-secondary);
        border: var(--border-stroke-thin);
        display: flex;
        flex-wrap: nowrap;
    }

    .mode-selection.large .selection-item {
        padding: 0.5rem 2rem;
        font-size: var(--font-sm);
        border-radius: calc(var(--border-radius-normal) - 2px);
    }

    .translate-header p {
        max-width: 800px;
        margin: 0.5rem auto 0;
    }

    .panel-header {
        padding: 0.75rem 1.25rem;
        border-bottom: var(--border-stroke-thin);
        display: flex;
        justify-content: space-between;
        align-items: center;
        background-color: var(--background-secondary);
        border-top-left-radius: var(--border-radius-normal);
        border-top-right-radius: var(--border-radius-normal);
    }

    .panel-content {
        flex: 1;
        display: flex;
        flex-direction: column;
    }

    .panel-content textarea {
        flex: 1;
        border: var(--border-stroke-thin) !important;
        border-radius: 0 !important;
        min-height: 450px;
        padding: 1.5rem;
        background-color: transparent !important;
        resize: none;
        font-size: var(--font-sm);
        line-height: 1.6;
    }

    .panel-footer {
        padding: 0.75rem 1.25rem;
        border-top: var(--border-stroke-thin);
        display: flex;
        justify-content: space-between;
        align-items: center;
        min-height: 4.5rem;
        border-bottom-left-radius: var(--border-radius-normal);
        border-bottom-right-radius: var(--border-radius-normal);
    }

    .styleless-select {
        border: none;
        background: transparent;
        color: var(--text-color);
        font-size: var(--font-xs);
        font-weight: 500;
        cursor: pointer;
        padding: 0.25rem 0.5rem;
        outline: none;
        border-radius: var(--border-radius-tight);
        transition: background-color 0.2s;
    }

    .styleless-select:hover {
        background-color: var(--highlight-color);
    }

    .selection-item {
        padding: 0.2rem 0.75rem;
        border: none;
        background: transparent;
        color: var(--text-color-gray);
        font-size: var(--font-xxs);
        font-weight: 600;
        cursor: pointer;
        border-radius: calc(var(--border-radius-tight) - 1px);
        transition: all 0.2s;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .selection-item.active {
        background-color: var(--accent-color);
        color: white;
    }

    .copy-overlay-btn {
        position: absolute;
        top: 0.75rem;
        right: 0.75rem;
        z-index: 10;
        opacity: 0;
        transition: opacity 0.2s, background-color 0.2s;
    }

    .panel-content:hover .copy-overlay-btn {
        opacity: 1;
    }

    .error-msg-container, .success-msg-container {
        margin-top: 1.5rem;
        padding: 1rem;
        border-radius: var(--border-radius-tight);
        font-size: var(--font-sm);
        border-left: 4px solid;
    }

    .error-msg-container {
        background-color: rgba(239, 68, 68, 0.05);
        color: #ef4444;
        border-color: #ef4444;
    }

    .success-msg-container {
        background-color: rgba(16, 185, 129, 0.05);
        color: #10b981;
        border-color: #10b981;
    }

    .btn-loading {
        opacity: 0.6;
        pointer-events: none;
    }

    /* Sidebar Redesign Styles */
    .icon-header {
        justify-content: flex-start;
        border-bottom: none;
        padding-bottom: 0;
    }
    .btn-icon-only-sm {
        background: transparent;
        border: none;
        cursor: pointer;
        padding: 4px;
        color: var(--text-color);
        border-radius: var(--border-radius-tight);
    }
    .btn-icon-only-sm:hover {
        background-color: var(--background-secondary);
    }

    .sidebar-group-title {
        font-size: var(--font-xxs);
        color: var(--text-faded-color);
        text-transform: normal;
        margin: 0 0 0.5rem 0.5rem;
        font-weight: 500;
    }

    .sidebar-item {
        display: flex;
        align-items: center;
        padding: 0.5rem 0.75rem;
        border-radius: var(--border-radius-tight);
        cursor: pointer;
        transition: background-color 0.2s;
        gap: 0.75rem;
    }
    .sidebar-item:hover {
        background-color: var(--background-secondary);
    }
    .sidebar-item.disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }
    .sidebar-item.model-selector-item:hover {
        background-color: var(--background-secondary);
    }

    .sidebar-item-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 1.25rem;
        height: 1.25rem;
        color: var(--text-color);
    }
    .sidebar-item-icon svg {
        width: 100%;
        height: 100%;
        stroke-width: 1.5;
    }

    .sidebar-item-label {
        font-size: var(--font-xs);
        font-weight: 500;
        color: var(--text-color);
    }

    .badge-pro, .badge-write-pro {
        font-size: 0.7rem;
        padding: 0.1rem 0.4rem;
        border-radius: 4px;
        font-weight: 600;
        margin-left: auto;
    }
    .badge-pro {
        color: #10b981;
        background-color: rgba(16, 185, 129, 0.1);
    }
    .badge-write-pro {
        color: #10b981;
        background-color: rgba(16, 185, 129, 0.1);
    }

    .toggle-switch {
        position: relative;
        display: inline-block;
        width: 34px;
        height: 20px;
        margin: auto 0 auto auto;
    }

    .toggle-switch input { 
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #e5e7eb;
        transition: .4s;
        border-radius: 34px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 14px;
        width: 14px;
        left: 3px;
        bottom: 3px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }

    input:checked + .slider {
        background-color: var(--accent-color);
    }

    input:checked + .slider:before {
        transform: translateX(14px);
    }

    /* Collapsed State Styles */
    .translate-board-3col.sidebar-collapsed {
        grid-template-columns: 1fr; /* Remains full width, sidebar logic is external */
    }

    .settings-panel.collapsed {
        width: 3.5rem;
        min-width: 3.5rem;
    }
    
    .settings-panel.collapsed .panel-content {
        display: none;
    }
    
    .settings-panel.collapsed .panel-header {
        justify-content: center;
        padding-left: 0;
        padding-right: 0;
    }

    /* Left Sidebar Button Active State */
    .btn-md-stroke.active {
        background-color: var(--background-secondary);
        border-color: var(--accent-color);
        color: var(--text-color);
    }
    
    .btn-md-stroke.active .icon {
        color: var(--accent-color);
    }
    /* Glossary Modal Styles */
    .glossary-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 1000;
        backdrop-filter: blur(2px);
    }

    .glossary-modal {
        background: var(--background-main);
        width: 600px;
        max-width: 90%;
        border-radius: var(--border-radius-normal);
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        animation: modalFadeIn 0.2s ease-out;
    }

    @keyframes modalFadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .glossary-modal-header {
        padding: 1rem 1.5rem;
        border-bottom: var(--border-stroke-thin);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .glossary-modal-header h3 {
        margin: 0;
        font-size: var(--font-lg);
        font-weight: 600;
        color: var(--text-color);
    }

    .glossary-close-btn {
        background: none;
        border: none;
        cursor: pointer;
        color: var(--text-faded-color);
        padding: 0.25rem;
        border-radius: 50%;
        transition: background 0.2s;
    }

    .glossary-close-btn:hover {
        background: var(--background-secondary);
        color: var(--text-color);
    }

    .glossary-modal-content {
        padding: 1.5rem;
    }

    .glossary-view {
        display: none;
        flex-direction: column;
        gap: 1.5rem;
    }

    .glossary-view.active {
        display: flex;
    }

    .glossary-view h4 {
        margin: 0;
        font-size: var(--font-md);
        font-weight: 600;
    }

    .glossary-list-empty {
        text-align: center;
        color: var(--text-faded-color);
        padding: 2rem;
        border: 1px dashed var(--border-stroke-thin);
        border-radius: var(--border-radius-normal);
    }
    
    .term-pair-row {
        display: flex;
        gap: 1rem;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .term-pair-inputs {
        display: flex;
        flex: 1 1 200px;
        gap: 0.5rem;
        align-items: center;
        min-width: 0;
    }

    .term-input {
        flex: 1;
        padding: 0.5rem;
        border: var(--border-stroke-thin);
        border-radius: var(--border-radius-tight);
        min-width: 0;
    }

    /* Modal Button Styles */
    .btn-primary {
        background-color: var(--accent-color);
        color: white;
        border: none;
        padding: 0.5rem 1rem;
        border-radius: var(--border-radius-tight);
        font-size: var(--font-sm);
        cursor: pointer;
        transition: opacity 0.2s;
        font-weight: 500;
    }
    .btn-primary:hover {
        opacity: 0.9;
    }

    .btn-secondary {
        background-color: transparent;
        color: var(--text-color);
        border: var(--border-stroke-thin);
        padding: 0.5rem 1rem;
        border-radius: var(--border-radius-tight);
        font-size: var(--font-sm);
        cursor: pointer;
        transition: background-color 0.2s;
        font-weight: 500;
    }
    .btn-secondary:hover {
        background-color: var(--background-secondary);
    }

    .btn-xs-stroke {
        background-color: transparent;
        color: var(--text-color);
        border: var(--border-stroke-thin);
        padding: 0.25rem 0.75rem;
        border-radius: var(--border-radius-tight);
        font-size: var(--font-xs);
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: all 0.2s;
        font-weight: 500;
    }
    .btn-xs-stroke:hover {
        border-color: var(--accent-color);
        color: var(--accent-color);
        background-color: var(--background-secondary);
    }

    .glossary-footer-actions {
        display: flex;
        justify-content: flex-end;
        gap: 1rem;
        margin-top: 1rem;
    }

    .delete-term-btn {
        background: transparent;
        border: none;
        color: var(--text-faded-color);
        cursor: pointer;
        padding: 0.5rem;
        border-radius: var(--border-radius-tight);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .delete-term-btn:hover {
        background-color: rgba(239, 68, 68, 0.1);
        color: var(--error-color);
    }
</style>

<!-- Glossary Modal HTML -->
<div id="glossaryModalOverlay" class="glossary-modal-overlay">
    <div class="glossary-modal">
        <div class="glossary-modal-header">
            <h3 id="glossaryModalTitle">{{ $translation["Glossary"] ?? "Glossary" }}</h3>
            <button class="glossary-close-btn" id="glossaryCloseBtn">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        
        <div class="glossary-modal-content">
            <!-- View 1: List -->
            <div id="glossaryListView" class="glossary-view active">
                <h4>{{ $translation["SelectOrManageGlossaries"] ?? "Select or manage glossaries" }}</h4>
                <button class="btn-primary" id="newGlossaryBtn" style="align-self: flex-start;">
                    + {{ $translation["NewGlossary"] ?? "New glossary" }}
                </button>
                
                <p class="text-sm text-faded">
                    {{ $translation["GlossaryDescription"] ?? "Define how words or phrases should be translated, and the translator will adapt your entries appropriately." }}
                </p>

                <div class="glossary-list-empty">
                    {{ $translation["NoGlossariesYet"] ?? "No glossaries created yet." }}
                </div>
            </div>

            <!-- View 2: Create -->
            <div id="glossaryCreateView" class="glossary-view">
                <h4>{{ $translation["GiveYourGlossaryName"] ?? "Give your glossary a name:" }}</h4>
                <input type="text" class="text-input" placeholder="e.g., Technical terms" id="newGlossaryName">
                
                <h4>{{ $translation["GuideTranslation"] ?? "Guide how to translate specific words:" }}</h4>
                
                <div id="termPairsContainer">
                    <div class="term-pair-row">
                        <div class="term-pair-inputs">
                            <select class="styleless-select border" style="width: 80px;">
                                <option>EN</option>
                                <option>DE</option>
                            </select>
                            <input type="text" class="term-input" placeholder="Source term">
                        </div>
                        <span style="color: var(--text-faded-color);">→</span>
                        <div class="term-pair-inputs">
                            <select class="styleless-select border" style="width: 80px;">
                                <option>DE</option>
                                <option>EN</option>
                            </select>
                                <input type="text" class="term-input" placeholder="Target term">
                        </div>
                        <button class="delete-term-btn" title="Remove term pair">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                        </button>
                    </div>
                </div>

                 <button class="btn-xs-stroke" id="addTermPairBtn" style="align-self: flex-start; margin-top: 0.5rem;">
                    {{ $translation["AddTermPair"] ?? "Add term pair" }}
                </button>

                <div class="glossary-footer-actions">
                    <button class="btn-secondary" id="glossaryBackBtn">{{ $translation["Back"] ?? "Back" }}</button>
                    <button class="btn-primary" id="createGlossaryBtn">{{ $translation["CreateGlossary"] ?? "Create glossary" }}</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const glossaryBtn = document.getElementById('glossary-btn');
        const modalOverlay = document.getElementById('glossaryModalOverlay');
        const closeBtn = document.getElementById('glossaryCloseBtn');
        const newGlossaryBtn = document.getElementById('newGlossaryBtn');
        const backBtn = document.getElementById('glossaryBackBtn');
        const listView = document.getElementById('glossaryListView');
        const createView = document.getElementById('glossaryCreateView');
        const modalTitle = document.getElementById('glossaryModalTitle'); // Dynamic title if needed

        const createGlossaryBtn = document.getElementById('createGlossaryBtn');
        const termPairsContainer = document.getElementById('termPairsContainer');
        const addTermPairBtn = document.getElementById('addTermPairBtn');

        // Toggle Modal
        if(glossaryBtn) {
            glossaryBtn.addEventListener('click', (e) => {
                 // Check if the click target is the toggle switch or its children
                 if (e.target.closest('.toggle-switch')) {
                    // Let the toggle switch function natively (toggle checkbox)
                    return;
                }

                // Otherwise, open the modal
                e.preventDefault(); 
                modalOverlay.style.display = 'flex';
            });
        }

        // Close Modal
        if(closeBtn) {
            closeBtn.addEventListener('click', () => {
                modalOverlay.style.display = 'none';
                resetView();
            });
        }
        
        // Close on overlay click
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) {
                modalOverlay.style.display = 'none';
                resetView();
            }
        });

        // Switch to Create View
        if(newGlossaryBtn) {
            newGlossaryBtn.addEventListener('click', () => {
                listView.classList.remove('active');
                createView.classList.add('active');
                modalTitle.textContent = '{{ $translation["NewGlossary"] ?? "New glossary" }}';
            });
        }

        // Back to List View
        if(backBtn) {
            backBtn.addEventListener('click', () => {
                createView.classList.remove('active');
                listView.classList.add('active');
                modalTitle.textContent = '{{ $translation["Glossary"] ?? "Glossary" }}';
            });
        }

        function resetView() {
            createView.classList.remove('active');
            listView.classList.add('active');
            modalTitle.textContent = '{{ $translation["Glossary"] ?? "Glossary" }}';
            // Optional: clear inputs
        }
        
        // Add Term Pair Logic
        if(addTermPairBtn && termPairsContainer) {
            
            // Function to attach delete listener to a specific button
            const attachDeleteListener = (btn) => {
                btn.addEventListener('click', function() {
                    const row = this.closest('.term-pair-row');
                    if(termPairsContainer.querySelectorAll('.term-pair-row').length > 1) {
                         row.remove();
                    } else {
                        // Optional: Clear inputs if it's the last row
                        row.querySelectorAll('input').forEach(input => input.value = '');
                    }
                });
            };

            // Attach to initial row
            termPairsContainer.querySelectorAll('.delete-term-btn').forEach(btn => attachDeleteListener(btn));

            addTermPairBtn.addEventListener('click', () => {
                const firstRow = termPairsContainer.querySelector('.term-pair-row');
                if(firstRow) {
                    const newRow = firstRow.cloneNode(true);
                    // Clear inputs in new row
                    newRow.querySelectorAll('input').forEach(input => input.value = '');
                    
                    // Re-attach listener to new button
                    const newDeleteBtn = newRow.querySelector('.delete-term-btn');
                    if(newDeleteBtn) attachDeleteListener(newDeleteBtn);

                    termPairsContainer.appendChild(newRow);
                }
            });
        }

        // Create Glossary Logic (Placeholder)
        if(createGlossaryBtn) {
            createGlossaryBtn.addEventListener('click', () => {
                const name = document.getElementById('newGlossaryName').value;
                const terms = [];
                termPairsContainer.querySelectorAll('.term-pair-row').forEach(row => {
                    const inputs = row.querySelectorAll('input');
                    const selects = row.querySelectorAll('select');
                    if(inputs[0].value && inputs[1].value) {
                         terms.push({
                            sourceLang: selects[0].value,
                            sourceTerm: inputs[0].value,
                            targetLang: selects[1].value,
                            targetTerm: inputs[1].value
                        });
                    }
                });

                console.log('Creating Glossary:', { name, terms });
                
                // Show success feedback (simulated)
                const originalText = createGlossaryBtn.textContent;
                createGlossaryBtn.textContent = 'Created!';
                createGlossaryBtn.style.backgroundColor = 'var(--success-color)';
                
                setTimeout(() => {
                    createGlossaryBtn.textContent = originalText;
                    createGlossaryBtn.style.backgroundColor = '';
                    
                    // Close modal / Switch view
                    resetView();
                    // document.getElementById('newGlossaryName').value = ''; 
                    // Reset inputs logic here if needed
                }, 1000);
            });
        }
    });
</script>

<script>
    class TranslateApp {
        constructor() {
            this.sourceText = document.getElementById('sourceText');
            this.translatedText = document.getElementById('translatedText');
            this.sourceLang = document.getElementById('sourceLang');
            this.targetLang = document.getElementById('targetLang');
            this.translateBtn = document.getElementById('translateBtn');
            this.translationModeBtn = document.getElementById('translationModeBtn');
            this.writingModeBtn = document.getElementById('writingModeBtn');
            this.writingStyle = document.getElementById('writingStyle');
            this.writingStyleWrapper = document.getElementById('writingStyleWrapper');
            this.toolsInfoText = document.getElementById('toolsInfoText');
            this.aiModel = document.getElementById('aiModel');
            this.aiModel = document.getElementById('aiModel');
            this.copyInputBtn = document.getElementById('copyInputBtn');
            this.copyOutputBtn = document.getElementById('copyOutputBtn');
            this.swapLanguagesBtn = document.getElementById('swapLanguagesBtn');
            this.charCount = document.getElementById('charCount');
            this.targetCharCount = document.getElementById('targetCharCount');
            this.errorMessage = document.getElementById('errorMessage');
            this.successMessage = document.getElementById('successMessage');

            this.isLoading = false;
            this.currentMode = 'translation';
            this.availableModels = [];

            this.init();
        }

        async init() {
            this.setupEventListeners();
            await this.loadAvailableModels();
        }

        async loadAvailableModels() {
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const response = await fetch('/req/ai/models', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    }
                });

                if (!response.ok) throw new Error('Failed to load models');
                const data = await response.json();
                
                if (data.success && data.data.models) {
                    this.availableModels = data.data.models;
                    this.populateModelDropdown();
                }
            } catch (error) {
                console.error('Failed to load AI models:', error);
            }
        }

        populateModelDropdown() {
            this.aiModel.innerHTML = '';
            if (this.availableModels.length === 0) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'Standard Modell';
                this.aiModel.appendChild(option);
                return;
            }
            
            this.availableModels.forEach((model, index) => {
                const option = document.createElement('option');
                option.value = model.id;
                option.textContent = model.label;
                if (index === 0) option.selected = true;
                this.aiModel.appendChild(option);
            });
        }

        setupEventListeners() {
            this.translateBtn.addEventListener('click', () => this.translate());
            this.translationModeBtn.addEventListener('click', () => this.switchMode('translation'));
            this.writingModeBtn.addEventListener('click', () => this.switchMode('writing'));
            if(this.copyInputBtn) this.copyInputBtn.addEventListener('click', () => this.copyText(this.sourceText, this.copyInputBtn));
            if(this.copyOutputBtn) this.copyOutputBtn.addEventListener('click', () => this.copyText(this.translatedText, this.copyOutputBtn));
            if(this.swapLanguagesBtn) this.swapLanguagesBtn.addEventListener('click', () => this.swapLanguages());
            this.sourceText.addEventListener('input', () => this.updateCharCount());

            /* const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
            if (sidebarToggleBtn) {
                sidebarToggleBtn.addEventListener('click', () => this.toggleSidebar());
            } */

            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    this.translate();
                }
            });
        }

        switchMode(mode) {
            this.currentMode = mode;
            this.translatedText.value = '';
            this.targetCharCount.textContent = '0';
            this.hideMessages();
            
            const btnLabel = this.translateBtn.querySelector('.label span');

            if (mode === 'translation') {
                this.translationModeBtn.classList.add('active');
                this.writingModeBtn.classList.remove('active');
                if (btnLabel) btnLabel.textContent = '{{ $translation["Translate"] ?? "Translate" }}';
                this.sourceLang.style.display = 'block';
                this.targetLang.style.display = 'block';
                if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'none';
                if (this.toolsInfoText) this.toolsInfoText.style.display = 'block';
            } else {
                this.writingModeBtn.classList.add('active');
                this.translationModeBtn.classList.remove('active');
                if (btnLabel) btnLabel.textContent = '{{ $translation["ImproveText"] ?? "Text verbessern" }}';
                this.sourceLang.style.display = 'none';
                this.targetLang.style.display = 'none';
                if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'block';
                if (this.toolsInfoText) this.toolsInfoText.style.display = 'none';
            }
        }

        updateCharCount() {
            const count = this.sourceText.value.length;
            this.charCount.textContent = count.toLocaleString();
        }

        toggleSidebar() {
           // Sidebar toggle logic is handled by global function togglePanelClass
        }

        async translate() {
            if (!this.sourceText.value.trim()) {
                this.showError('{{ $translation["Err_EmptyInput"] ?? "Bitte geben Sie Text ein" }}');
                return;
            }

            if (this.isLoading) return;

            this.isLoading = true;
            this.translateBtn.classList.add('btn-loading');
            this.hideMessages();

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                let endpoint, requestData, successMessage;
                
                if (this.currentMode === 'translation') {
                    endpoint = '/req/deepl/translate';
                    requestData = {
                        text: this.sourceText.value,
                        source_lang: this.sourceLang.value === 'auto' ? null : this.sourceLang.value,
                        target_lang: this.targetLang.value
                    };
                    successMessage = '{{ $translation["Success_Translated"] ?? "Übersetzung erfolgreich!" }}';
                } else {
                    endpoint = '/req/ai/write';
                    requestData = {
                        text: this.sourceText.value,
                        target_lang: null,
                        model: this.aiModel.value,
                        style: this.writingStyle.value
                    };
                    successMessage = '{{ $translation["Success_Improved"] ?? "Text erfolgreich verbessert!" }}';
                }

                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(requestData)
                });

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.error || data.message || 'Request failed');
                }

                this.translatedText.value = data.data.text;
                this.targetCharCount.textContent = data.data.text.length.toLocaleString();

                if (this.currentMode === 'translation' && data.data.detected_source_language && this.sourceLang.value === 'auto') {
                    this.sourceLang.value = data.data.detected_source_language.toLowerCase();
                }

                this.showSuccess(successMessage);
            } catch (error) {
                this.showError(error.message || '{{ $translation["Status_Error"] ?? "Fehler beim Verarbeiten" }}');
            } finally {
                this.isLoading = false;
                this.translateBtn.classList.remove('btn-loading');
            }
        }

        async swapLanguages() {
             const sourceVal = this.sourceLang.value;
             const targetVal = this.targetLang.value;
             
             // Swap languages
             this.sourceLang.value = targetVal;
             // If source was auto, default to English or keep target if valid (simple fallback)
             this.targetLang.value = (sourceVal === 'auto') ? 'en' : sourceVal;

             // Swap text content
             const sourceTextVal = this.sourceText.value;
             const targetTextVal = this.translatedText.value;
             
             this.sourceText.value = targetTextVal;
             // Clear translated text to trigger fresh translation logic effectively or set it?
             // Usually set it, then translate.
             this.translatedText.value = sourceTextVal;
             
             this.updateCharCount();
             
             // Trigger translation
             if (this.sourceText.value.trim()) {
                 this.translate();
             }
        }

        async copyText(element, btn) {
            if (!element.value) return;
            try {
                await navigator.clipboard.writeText(element.value);
                
                // Show reaction
                const reaction = btn.querySelector('.reaction');
                if (reaction) {
                    reaction.style.opacity = '1';
                    reaction.style.visibility = 'visible';
                    setTimeout(() => {
                        reaction.style.opacity = '0';
                        reaction.style.visibility = 'hidden';
                    }, 1500);
                } else {
                     // Fallback if structure changes
                     const originalContent = btn.innerHTML;
                     btn.style.color = 'var(--success-color)';
                     setTimeout(() => btn.style.color = '', 1000);
                }
                
                // Optional global message
                // this.showSuccess('{{ $translation["Success_Copied"] ?? "Kopiert!" }}'); 
            } catch (error) {
                this.showError('{{ $translation["Err_CopyFailed"] ?? "Kopieren fehlgeschlagen" }}');
            }
        }

        showError(message) {
            this.errorMessage.textContent = message;
            this.errorMessage.style.display = 'block';
            setTimeout(() => this.errorMessage.style.display = 'none', 5000);
        }

        showSuccess(message) {
            this.successMessage.textContent = message;
            this.successMessage.style.display = 'block';
            setTimeout(() => this.successMessage.style.display = 'none', 3000);
        }

        hideMessages() {
            this.errorMessage.style.display = 'none';
            this.successMessage.style.display = 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', () => new TranslateApp());
</script>
@endsection
