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
