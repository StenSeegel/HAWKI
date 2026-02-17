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
                <button class="btn-primary" id="newGlossaryBtn" style="align-self: flex-start;">
                    + {{ $translation["NewGlossary"] ?? "Neues Glossar" }}
                </button>
                
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
                
                <h4>{{ $translation["GuideTranslation"] ?? "Legen Sie fest, wie bestimmte Wörter übersetzt werden:" }}</h4>
                
                <div id="termPairsContainer">
                    <div class="term-pair-row">
                        <div class="term-pair-inputs">
                            <select class="styleless-select border" style="width: 80px;">
                                <option>EN</option>
                                <option>DE</option>
                            </select>
                            <input type="text" class="term-input" placeholder="{{ $translation['SourceTerm'] ?? 'Ausgangsbegriff' }}">
                        </div>
                        <span style="color: var(--text-faded-color);">→</span>
                        <div class="term-pair-inputs">
                            <select class="styleless-select border" style="width: 80px;">
                                <option>DE</option>
                                <option>EN</option>
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
        </div>
    </div>
</div>
