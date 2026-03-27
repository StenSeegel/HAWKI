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
                <h4>{{ $translation["SelectOrManageGlossaries"] ?? "Glossare auswählen oder verwalten" }}</h4>
                <div style="display: flex; gap: 0.75rem; align-items: center;">
                    <button class="btn-primary" id="newGlossaryBtn" style="align-self: flex-start;">
                        + {{ $translation["NewGlossary"] ?? "Neues Glossar" }}
                    </button>
                    <button class="btn-secondary" id="importGlossaryBtn" style="align-self: flex-start;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                        {{ $translation["ImportGlossary"] ?? "Glossar importieren" }}
                    </button>
                </div>
                
                <p class="text-sm text-faded">
                    {{ $translation["GlossaryDescription"] ?? "Definieren Sie, wie Wörter oder Phrasen übersetzt werden sollen." }}
                </p>

                <div class="glossary-list-empty">
                    {{ $translation["NoGlossariesYet"] ?? "Noch keine Glossare erstellt." }}
                </div>
            </div>

            <!-- View 2: Create -->
            <div id="glossaryCreateView" class="glossary-view">
                <h4>{{ $translation["GiveYourGlossaryName"] ?? "Geben Sie Ihrem Glossar einen Namen:" }}</h4>
                <input type="text" class="text-input" placeholder="{{ $translation['GlossaryNamePlaceholder'] ?? 'z.B. Fachbegriffe' }}" id="newGlossaryName">
                
                <h4>{{ $translation["Description"] ?? "Beschreibung" }}</h4>
                <textarea class="text-input" style="min-height: 80px; resize: vertical; margin-bottom: 1rem;" placeholder="{{ $translation['GlossaryDescriptionPlaceholder'] ?? 'Optionale Beschreibung...' }}" id="newGlossaryDescription"></textarea>

                <h4>{{ $translation["GuideTranslation"] ?? "Legen Sie fest, wie bestimmte Wörter übersetzt werden:" }}</h4>
                
                <div id="termPairsContainer">
                    <div class="term-pair-row">
                        <div class="term-pair-inputs">
                            <select class="styleless-select border">
                                <option>EN</option>
                                <option>DE</option>
                                <option>UK</option>
                            </select>
                            <input type="text" class="term-input" placeholder="{{ $translation['SourceTerm'] ?? 'Ausgangsbegriff' }}">
                        </div>
                        <span style="color: var(--text-faded-color);">→</span>
                        <div class="term-pair-inputs">
                            <select class="styleless-select border">
                                <option>DE</option>
                                <option>EN</option>
                                <option>UK</option>
                            </select>
                                <input type="text" class="term-input" placeholder="{{ $translation['TargetTerm'] ?? 'Zielbegriff' }}">
                        </div>
                        <button class="delete-term-btn" title="{{ $translation['RemoveTermPair'] ?? 'Begriffspaar entfernen' }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                        </button>
                    </div>
                </div>

                 <button class="btn-xs-stroke" id="addTermPairBtn" style="align-self: flex-start; margin-top: 0.5rem;">
                    {{ $translation["AddTermPair"] ?? "Begriffspaar hinzufügen" }}
                </button>

                <div class="glossary-footer-actions">
                    <button class="btn-secondary" id="glossaryBackBtn">{{ $translation["Back"] ?? "Zurück" }}</button>
                    <button class="btn-primary" id="createGlossaryBtn">{{ $translation["CreateGlossary"] ?? "Glossar erstellen" }}</button>
                </div>
            </div>

            <!-- View 3: Import -->
            <div id="glossaryImportView" class="glossary-view">
                <h4>{{ $translation["ImportGlossaryTitle"] ?? "Glossar aus CSV importieren" }}</h4>
                
                <div class="form-group">
                    <label style="font-weight: 500; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">{{ $translation["GlossaryName"] ?? "Glossar-Name" }}</label>
                    <input type="text" class="text-input" placeholder="{{ $translation['GlossaryNamePlaceholder'] ?? 'z.B. Fachbegriffe' }}" id="importGlossaryName">
                </div>

                <div class="form-group">
                    <label style="font-weight: 500; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">{{ $translation["Description"] ?? "Beschreibung" }}</label>
                    <textarea class="text-input" style="min-height: 80px; resize: vertical;" placeholder="{{ $translation['GlossaryDescriptionPlaceholder'] ?? 'Optionale Beschreibung...' }}" id="importGlossaryDescription"></textarea>
                </div>

                <div style="display: flex; gap: 1rem;">
                    <div style="flex: 1;">
                        <label style="font-weight: 500; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">{{ $translation["SourceLanguage"] ?? "Ausgangssprache" }}</label>
                        <select class="styleless-select border" id="importSourceLang" style="width: 100%; height: 40px; border-radius: var(--border-radius-tight);">
                            <option value="DE">Deutsch (DE)</option>
                            <option value="EN">English (EN)</option>
                            <option value="UK">Ukrainian (UK)</option>
                            <option value="FR">Français (FR)</option>
                            <option value="ES">Español (ES)</option>
                            <option value="IT">Italiano (IT)</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label style="font-weight: 500; font-size: 0.9rem; display: block; margin-bottom: 0.5rem;">{{ $translation["TargetLanguage"] ?? "Zielsprache" }}</label>
                        <select class="styleless-select border" id="importTargetLang" style="width: 100%; height: 40px; border-radius: var(--border-radius-tight);">
                            <option value="EN">English (EN)</option>
                            <option value="DE">Deutsch (DE)</option>
                            <option value="UK">Ukrainian (UK)</option>
                            <option value="FR">Français (FR)</option>
                            <option value="ES">Español (ES)</option>
                            <option value="IT">Italiano (IT)</option>
                        </select>
                    </div>
                </div>

                <div class="doc-drop-zone" id="csvDropZone" style="padding: 40px 20px; min-height: 150px; cursor: pointer; border-style: dashed; border-width: 2px;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                    <p style="margin: 10px 0 0 0; font-weight: 500;">{{ $translation["ClickOrDragCSV"] ?? "Klicken oder CSV-Datei hierher ziehen" }}</p>
                    <p class="text-xs text-faded" id="csvFileNameDisplay">{{ $translation["FormatHint"] ?? "Format: Begriff1,Begriff2 (Source,Target)" }}</p>
                    <input type="file" id="csvFileInput" accept=".csv,.txt" style="display: none;">
                </div>

                <div class="glossary-footer-actions">
                    <button class="btn-secondary" id="importBackBtn">{{ $translation["Back"] ?? "Zurück" }}</button>
                    <button class="btn-primary" id="submitImportBtn">{{ $translation["Import"] ?? "Importieren" }}</button>
                </div>
            </div>

            <!-- View 4: Details -->
            <div id="glossaryDetailsView" class="glossary-view">
                <div class="glossary-details-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                    <div>
                        <h2 id="detailsGlossaryName" style="margin: 0; font-size: 1.5rem; font-weight: 600; color: var(--text-color);"></h2>
                        <div id="detailsTermsCount" style="font-size: 0.85rem; color: var(--text-faded-color); margin-top: 2px;"></div>
                    </div>
                    <span id="detailsGlossaryDomain" class="domain-badge"></span>
                </div>

                <div id="glossaryDetailsContent" class="glossary-details-cards">
                    <!-- Cards will be populated by JS -->
                </div>

                <div class="glossary-footer-actions" style="margin-top: 2rem; border-top: 1px solid var(--border-stroke-thin); padding-top: 1rem; display: flex; justify-content: flex-end; gap: 10px;">
                    <button class="btn-secondary" id="detailsCloseBtn">{{ $translation["Back"] ?? "zurück" }}</button>
                </div>
            </div>
        </div>
    </div>
</div>
