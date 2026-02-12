@extends('layouts.home')

@section('content')
<div class="main-panel-grid">
    <link rel="stylesheet" href="{{ asset('css/translate.css') }}">
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
    window.TranslationData = {
        Glossary: "{{ $translation['Glossary'] ?? 'Glossary' }}",
        NewGlossary: "{{ $translation['NewGlossary'] ?? 'New glossary' }}",
        Translate: "{{ $translation['Translate'] ?? 'Translate' }}",
        ImproveText: "{{ $translation['ImproveText'] ?? 'Text verbessern' }}",
        Err_EmptyInput: "{{ $translation['Err_EmptyInput'] ?? 'Bitte geben Sie Text ein' }}",
        Success_Translated: "{{ $translation['Success_Translated'] ?? 'Übersetzung erfolgreich!' }}",
        Success_Improved: "{{ $translation['Success_Improved'] ?? 'Text erfolgreich verbessert!' }}",
        Status_Error: "{{ $translation['Status_Error'] ?? 'Fehler beim Verarbeiten' }}",
        Err_CopyFailed: "{{ $translation['Err_CopyFailed'] ?? 'Kopieren fehlgeschlagen' }}"
    };
</script>
@vite('resources/js/translate.js')
@endsection
