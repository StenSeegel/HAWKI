/**
 * TranslateApp - Central Orchestrator for HAWKI Translation.
 * Ties together UI, Language Services, Glossary, and Document Translation.
 */
import { DOM_IDS, getTranslations } from './Constants.js';
import { TextProcessor } from './TextProcessor.js';
import { LanguageService } from './LanguageService.js';
import { UIManager } from './UIManager.js';
import { GlossaryManager } from './GlossaryManager.js';
import { DocumentTranslator } from './DocumentTranslator.js';
import { SentenceProcessor } from './SentenceProcessor.js';

export class TranslateApp {
    constructor() {
        this.t = getTranslations();
        this.currentMode = 'translation'; // 'translation', 'writing', 'document'
        this.isLoading = false;

        // Domain State
        this.sourceSentences = [];
        this.targetSentences = [];
        this.lastProcessedSourceText = '';
        this.lastProcessedSourceLang = 'auto';
        this.lastProcessedTargetLang = 'en-gb';
        this.lastProcessedModel = null;
        this.lastProcessedFormality = null;
        this.lastProcessedGlossaryIds = '';
        this.lastProcessedStyle = null;
        this.lastProcessedTone = null;

        this.translationSourceSentences = [];
        this.translationTargetSentences = [];
        this.writingSourceSentences = [];
        this.writingTargetSentences = [];

        this.selectedModel = null;
        this.lastUserModelId = null;
        this.selectedStyle = 'default';
        this.selectedTone = 'default';
        this.selectedFormality = 'default';
        this.showChangesEnabled = false;

        // Buffers for mode switching
        this.lastTranslationSource = '';
        this.lastTranslationResult = '';
        this.lastWritingSource = '';
        this.lastWritingResult = '';
        this.lastWritingDiffSource = '';
        this.lastSourceText = '';

        // Initialize Modules
        this.textProcessor = new TextProcessor(this);
        this.languageService = new LanguageService();
        this.uiManager = new UIManager(this);
        this.glossaryManager = new GlossaryManager(this);
        this.documentTranslator = new DocumentTranslator(this);
        this.sentenceProcessor = new SentenceProcessor(this);

        this.init();
    }

    init() {
        // Document translation requires DeepL API Pro
        const models = this.getAvailableModels();
        const hasDeepl = models.some(m => m.id === 'deepl');
        if (!hasDeepl && this.uiManager.elements.documentModeBtn) {
            this.uiManager.elements.documentModeBtn.style.display = 'none';
        }

        this.loadSession();
        
        if (!this.selectedModel) {
            const models = this.getAvailableModels();
            const defaults = window.TranslationData?.defaults || {};
            const defaultId = (this.currentMode === 'writing' ? defaults.rephrase_model : defaults.translate_model) || null;
            
            this.selectedModel = models.find(m => m.id === defaultId) || models.find(m => m.is_default) || models[0] || null;
            this.lastUserModelId = this.selectedModel?.id;
        }

        if (!this.lastUserModelId) {
            this.lastUserModelId = this.selectedModel?.id;
        }

        this.uiManager.updateModelLabel();

        this.attachGlobalListeners();
        this.uiManager.updateStyleUI(this.getState());
        this.uiManager.updateStyleLabel(this.getState());
        
        // Initial mode setup
        this.switchMode(this.currentMode, null, true);
    }

    getState() {
        return {
            style: this.selectedStyle,
            tone: this.selectedTone,
            formality: this.selectedFormality
        };
    }

    attachGlobalListeners() {
        const { elements } = this.uiManager;

        if (elements.translationModeBtn) elements.translationModeBtn.addEventListener('click', () => this.switchMode('translation'));
        if (elements.writingModeBtn) elements.writingModeBtn.addEventListener('click', () => this.switchMode('writing'));
        if (elements.documentModeBtn) elements.documentModeBtn.addEventListener('click', () => this.switchMode('document'));

        if (elements.translateBtn) elements.translateBtn.addEventListener('click', () => this.translate());
        
        if (elements.sourceText) {
            elements.sourceText.addEventListener('input', () => {
                const val = elements.sourceText.value;
                this.uiManager.updateCharCount(val);
                this.scheduleLanguageDetection();
                this.updateButtonState();
                
                if (!val.trim()) {
                    if (elements.sourceLang && elements.sourceLang.value !== 'auto') {
                        elements.sourceLang.value = 'auto';
                        const changeEvent = new Event('change', { bubbles: true });
                        changeEvent.isProgrammatic = true;
                        elements.sourceLang.dispatchEvent(changeEvent);
                    }
                    this.uiManager.clearTarget();
                    this.lastSourceText = '';
                    this.lastTranslationResult = '';
                    this.lastWritingResult = '';
                    this.targetSentences = [];
                    this.saveSession();
                }
            });
        }

        if (elements.swapLanguagesBtn) {
            elements.swapLanguagesBtn.addEventListener('click', () => this.swapLanguages());
        }

        if (elements.showChangesToggle) {
            elements.showChangesToggle.addEventListener('change', (e) => {
                this.showChangesEnabled = e.target.checked;
                this.uiManager.toggleDiffView();
                this.saveSession();
            });
        }

        if (elements.deleteSourceBtn) {
            elements.deleteSourceBtn.addEventListener('click', () => {
                if (elements.sourceText) {
                    elements.sourceText.value = '';
                    elements.sourceText.dispatchEvent(new Event('input', { bubbles: true }));
                }
                
                // Reset language to auto
                if (elements.sourceLang && elements.sourceLang.value !== 'auto') {
                    elements.sourceLang.value = 'auto';
                    const changeEvent = new Event('change', { bubbles: true });
                    changeEvent.isProgrammatic = true;
                    elements.sourceLang.dispatchEvent(changeEvent);
                }
                
                this.uiManager.clearTarget();
                this.lastSourceText = '';
                this.lastTranslationResult = '';
                this.lastWritingResult = '';
                this.targetSentences = [];
                this.updateButtonState();
                this.saveSession();
            });
        }

        if (elements.sourceLang) {
            elements.sourceLang.addEventListener('change', (e) => {
                if (!e.isProgrammatic) {
                    this.userSetSourceLang = elements.sourceLang.value !== 'auto';
                }
                this.preventSameLanguage('source');
                this.updateButtonState();
            });
        }

        if (elements.targetLang) {
            elements.targetLang.addEventListener('change', () => {
                this.preventSameLanguage('target');
                this.updateButtonState();
            });
        }
    }

    switchMode(mode, initialText = null, force = false) {
        if (!force && mode === this.currentMode) return;

        // Save current state
        if (this.currentMode === 'translation') {
            this.lastTranslationSource = this.uiManager.elements.sourceText?.value || '';
            this.lastTranslationResult = this.uiManager.elements.translatedText?.value || '';
            this.translationSourceSentences = [...this.sourceSentences];
            this.translationTargetSentences = [...this.targetSentences];
        } else if (this.currentMode === 'writing') {
            this.lastWritingSource = this.uiManager.elements.sourceText?.value || '';
            this.lastWritingResult = this.uiManager.elements.translatedText?.value || '';
            this.lastWritingDiffSource = this.lastSourceText;
            this.writingSourceSentences = [...this.sourceSentences];
            this.writingTargetSentences = [...this.targetSentences];
        }

        if (initialText !== null) {
            if (mode === 'writing') {
                this.lastWritingSource = initialText;
                this.lastWritingResult = '';
                this.lastWritingDiffSource = '';
                this.writingSourceSentences = [];
                this.writingTargetSentences = [];
            } else if (mode === 'translation') {
                this.lastTranslationSource = initialText;
                this.lastTranslationResult = '';
                this.translationSourceSentences = [];
                this.translationTargetSentences = [];
            }
        }

        this.currentMode = mode;
        this.sentenceProcessor.hideWriteContextMenu();
        this.sentenceProcessor.clearCache();

        // Restore state
        if (mode === 'translation') {
            if (this.uiManager.elements.sourceText) this.uiManager.elements.sourceText.value = this.lastTranslationSource;
            if (this.uiManager.elements.translatedText) this.uiManager.elements.translatedText.value = this.lastTranslationResult;
            this.sourceSentences = [...this.translationSourceSentences];
            this.targetSentences = [...this.translationTargetSentences];
            this.lastSourceText = ''; // Translation mode doesn't rely on lastSourceText for diff by default unless enabled
        } else if (mode === 'writing') {
            if (this.uiManager.elements.sourceText) this.uiManager.elements.sourceText.value = this.lastWritingSource;
            if (this.uiManager.elements.translatedText) this.uiManager.elements.translatedText.value = this.lastWritingResult;
            this.sourceSentences = [...this.writingSourceSentences];
            this.targetSentences = [...this.writingTargetSentences];
            this.lastSourceText = this.lastWritingDiffSource;
        }

        this.uiManager.updateCharCount(this.uiManager.elements.sourceText?.value || '');
        this.uiManager.updateTargetCharCount(this.uiManager.elements.translatedText?.value || '');
        
        // Trigger language detection if we have new text
        if (initialText !== null && initialText.trim().length > 20) {
            this.scheduleLanguageDetection();
        }

        // Update Board Visibility
        if (this.uiManager.elements.translateBoard) this.uiManager.elements.translateBoard.style.display = (mode === 'document') ? 'none' : 'grid';
        if (this.uiManager.elements.documentBoard) this.uiManager.elements.documentBoard.style.display = (mode === 'document') ? 'grid' : 'none';

        this.uiManager.updateModeUI(mode);
        this.uiManager.updateOutputUI();
        this.saveSession();
    }

    async translate() {
        const fullText = this.uiManager.elements.sourceText?.value.trim();
        if (!fullText) {
            this.uiManager.showError(this.t.Err_EmptyInput || "Please enter text");
            return;
        }

        if (this.isLoading) return;
        this.isLoading = true;

        try {
            const sourceSentences = this.textProcessor.splitIntoSentences(fullText);

            // Determine if we can do a partial skeleton (incremental update)
            // We only do this if global settings haven't changed and we have a previous board state
            const settingsChanged = this.lastProcessedModel !== this.selectedModel?.id ||
                                    this.lastProcessedStyle !== this.selectedStyle ||
                                    this.lastProcessedTone !== this.selectedTone ||
                                    this.lastProcessedFormality !== this.selectedFormality;

            let changedIndices = null;
            if (!settingsChanged && this.sourceSentences && this.sourceSentences.length > 0) {
                changedIndices = [];
                const maxSentences = Math.max(sourceSentences.length, this.sourceSentences.length);
                for (let i = 0; i < maxSentences; i++) {
                    if (sourceSentences[i] !== this.sourceSentences[i]) {
                        changedIndices.push(i);
                    }
                }
                
                // Sensitivity logic:
                // Only revert to full skeleton if MANY sentences changed relative to the total.
                // For small texts (< 10 sentences), we are more lenient.
                const threshold = sourceSentences.length < 10 ? 1.0 : 0.8; 
                if (changedIndices.length > sourceSentences.length * threshold || Math.abs(sourceSentences.length - this.sourceSentences.length) > 5) {
                    changedIndices = null;
                }
            }

            this.uiManager.showSkeleton(true, changedIndices);

            const activeGlossaryIds = Array.from(document.querySelectorAll('#sidebarGlossaryList input:checked')).map(cb => cb.value);

            let sourceLang = this.uiManager.elements.sourceLang?.value || 'auto';
            let targetLang = this.uiManager.elements.targetLang?.value || 'en-gb';

            // Handle Auto-Detection and UI Update
            if (this.currentMode === 'writing') {
                if (sourceLang === 'auto') {
                    const detected = await this.languageService.detectLanguage(fullText.substring(0, 500)) || 'de';
                    sourceLang = detected;
                    if (this.uiManager.elements.sourceLang) {
                        this.uiManager.elements.sourceLang.value = sourceLang;
                        this.uiManager.elements.sourceLang.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                targetLang = sourceLang;
            } else if (sourceLang === 'auto') {
                const detected = await this.languageService.detectLanguage(fullText.substring(0, 500));
                if (detected) {
                    sourceLang = detected;
                    if (this.uiManager.elements.sourceLang) {
                        this.uiManager.elements.sourceLang.value = sourceLang;
                        const event = new Event('change', { bubbles: true });
                        event.isProgrammatic = true; 
                        this.uiManager.elements.sourceLang.dispatchEvent(event);
                    }
                }
            }

            // Final safety check for identical languages in translation mode
            if (this.currentMode !== 'writing' && sourceLang !== 'auto' && sourceLang === targetLang) {
                targetLang = this.languageService.getAlternativeTargetLang(sourceLang, 'de');
                if (this.uiManager.elements.targetLang) {
                    this.uiManager.elements.targetLang.value = targetLang;
                    this.uiManager.elements.targetLang.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            const data = {
                text: sourceSentences,
                source_lang: sourceLang === 'auto' ? null : sourceLang,
                target_lang: targetLang,
                model: this.selectedModel?.id,
                glossary_id: activeGlossaryIds,
                formality: this.selectedFormality !== 'default' ? this.selectedFormality : null,
                style: this.selectedStyle !== 'default' ? this.selectedStyle : null,
                tone: this.selectedTone !== 'default' ? this.selectedTone : null
            };

            let result;
            if (this.currentMode === 'writing') {
                data.type = 'improvement';
                result = await this.languageService.improve(data);
            } else {
                result = await this.languageService.process(data);
            }
            
            const rawText = result.data.text;
            const results = Array.isArray(rawText) ? rawText : [rawText];
            
            // Restore original trailing whitespace from source sentences if missing in result
            this.targetSentences = results.map((s, i) => {
                const sourceS = sourceSentences[i] || '';
                const match = sourceS.match(/\s+$/);
                const trailing = match ? match[0] : '';
                return (trailing && !s.endsWith(trailing)) ? s.trimEnd() + trailing : s;
            });

            this.sourceSentences = sourceSentences;
            this.lastSourceText = fullText;
            
            // Sync with mode-specific buffers to ensure switchMode captures the latest processed state
            if (this.currentMode === 'writing') {
                this.writingSourceSentences = [...this.sourceSentences];
                this.writingTargetSentences = [...this.targetSentences];
                this.lastWritingSource = fullText;
                this.lastWritingResult = this.targetSentences.join('');
            } else if (this.currentMode === 'translation') {
                this.translationSourceSentences = [...this.sourceSentences];
                this.translationTargetSentences = [...this.targetSentences];
                this.lastTranslationSource = fullText;
                this.lastTranslationResult = this.targetSentences.join('');
            }

            this.lastProcessedSourceText = fullText;
            this.lastProcessedSourceLang = sourceLang;
            this.lastProcessedTargetLang = targetLang;
            this.lastProcessedGlossaryIds = activeGlossaryIds.join(',');
            this.lastProcessedModel = this.selectedModel?.id;
            this.lastProcessedStyle = this.selectedStyle;
            this.lastProcessedTone = this.selectedTone;
            this.lastProcessedFormality = this.selectedFormality;

            this.uiManager.updateOutputUI();
            this.updateButtonState();
            this.saveSession();
        } catch (error) {
            this.uiManager.showError(error.message);
        } finally {
            this.isLoading = false;
            this.uiManager.showSkeleton(false);
        }
    }

    scheduleLanguageDetection() {
        if (this._langDetectTimeout) clearTimeout(this._langDetectTimeout);
        this._langDetectTimeout = setTimeout(async () => {
            const text = this.uiManager.elements.sourceText?.value.trim();
            if (text && text.length > 20 && !this.userSetSourceLang) {
                const lang = await this.languageService.detectLanguage(text);
                if (lang) {
                    const normalized = this.languageService.normalizeLanguageCode(lang);
                    if (this.uiManager.elements.sourceLang && this.uiManager.elements.sourceLang.value !== normalized) {
                        this.uiManager.elements.sourceLang.value = normalized;
                        const event = new Event('change', { bubbles: true });
                        event.isProgrammatic = true;
                        this.uiManager.elements.sourceLang.dispatchEvent(event);
                    }
                }
            }
        }, 800);
    }

    preventSameLanguage(side) {
        if (this.currentMode === 'writing') return;
        const s = this.uiManager.elements.sourceLang?.value;
        const t = this.uiManager.elements.targetLang?.value;
        if (s !== 'auto' && s === t) {
            if (side === 'source') {
                this.uiManager.elements.targetLang.value = this.languageService.getAlternativeTargetLang(s, 'de');
                this.uiManager.elements.targetLang.dispatchEvent(new Event('change', { bubbles: true }));
            } else {
                this.uiManager.elements.sourceLang.value = 'auto';
                this.uiManager.elements.sourceLang.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }

    swapLanguages() {
        const s = this.uiManager.elements.sourceLang;
        const t = this.uiManager.elements.targetLang;
        if (!s || !t) return;

        const sVal = s.value;
        const tVal = t.value;
        s.value = tVal;
        t.value = (sVal === 'auto') ? this.languageService.getAlternativeTargetLang(tVal, 'de') : sVal;

        [s, t].forEach(el => el.dispatchEvent(new Event('change', { bubbles: true })));
        
        const sText = this.uiManager.elements.sourceText;
        const tText = this.uiManager.elements.translatedText;
        if (sText && tText) {
            const temp = sText.value;
            sText.value = tText.value;
            tText.value = temp;
            this.uiManager.updateCharCount(sText.value);
            if (sText.value.trim()) this.translate();
        }
    }

    selectStyle(style) {
        this.selectedStyle = style;
        this.selectedTone = 'default';
        this.selectedFormality = 'default';
        this.uiManager.updateStyleUI(this.getState());
        this.uiManager.updateStyleLabel(this.getState());
        this.uiManager.closeStyleSubview();
        this.updateButtonState();
        this.saveSession();
    }

    selectTone(tone) {
        this.selectedTone = tone;
        this.selectedStyle = 'default';
        this.selectedFormality = 'default';
        this.uiManager.updateStyleUI(this.getState());
        this.uiManager.updateStyleLabel(this.getState());
        this.uiManager.closeStyleSubview();
        this.updateButtonState();
        this.saveSession();
    }

    selectFormality(formality) {
        this.selectedFormality = formality;
        this.selectedStyle = 'default';
        this.selectedTone = 'default';
        this.uiManager.updateStyleUI(this.getState());
        this.uiManager.updateStyleLabel(this.getState());
        this.uiManager.closeStyleSubview();
        this.updateButtonState();
        this.saveSession();
    }

    resetStyleSelections() {
        this.selectedStyle = 'default';
        this.selectedTone = 'default';
        this.selectedFormality = 'default';
        this.uiManager.updateStyleUI(this.getState());
        this.uiManager.updateStyleLabel(this.getState());
        this.updateButtonState();
        this.saveSession();
    }

    saveSession() {
        const session = {
            mode: this.currentMode,
            sourceText: this.uiManager.elements.sourceText?.value,
            targetText: this.uiManager.elements.translatedText?.value,
            sourceLang: this.uiManager.elements.sourceLang?.value,
            targetLang: this.uiManager.elements.targetLang?.value,
            docSourceLang: this.uiManager.elements.docSourceLang?.value,
            docTargetLang: this.uiManager.elements.docTargetLang?.value,
            style: this.selectedStyle,
            tone: this.selectedTone,
            formality: this.selectedFormality,
            showChanges: this.showChangesEnabled,
            selectedModelId: this.selectedModel?.id,
            lastUserModelId: this.lastUserModelId,
            glossaryIds: Array.from(document.querySelectorAll('#sidebarGlossaryList input:checked')).map(cb => cb.value),
            lastSourceText: this.lastSourceText,
            lastTranslationSource: this.lastTranslationSource,
            lastTranslationResult: this.lastTranslationResult,
            lastWritingSource: this.lastWritingSource,
            lastWritingResult: this.lastWritingResult,
            lastWritingDiffSource: this.lastWritingDiffSource,
            translationSourceSentences: this.translationSourceSentences,
            translationTargetSentences: this.translationTargetSentences,
            writingSourceSentences: this.writingSourceSentences,
            writingTargetSentences: this.writingTargetSentences
        };
        sessionStorage.setItem('hawki_text_session', JSON.stringify(session));
    }

    loadSession() {
        const raw = sessionStorage.getItem('hawki_text_session');
        if (!raw) return;
        try {
            const s = JSON.parse(raw);
            this.currentMode = s.mode || 'translation';
            
            // Populate history variables so switchMode restores them correctly
            this.lastTranslationSource = (s.mode === 'translation' || !s.mode) ? (s.sourceText || '') : '';
            this.lastTranslationResult = (s.mode === 'translation' || !s.mode) ? (s.targetText || '') : '';
            this.lastWritingResult = (s.mode === 'writing') ? (s.targetText || '') : (s.lastWritingResult || '');
            
            this.lastSourceText = s.lastSourceText || '';
            this.lastTranslationSource = s.lastTranslationSource || this.lastTranslationSource;
            this.lastTranslationResult = s.lastTranslationResult || this.lastTranslationResult;
            this.lastWritingSource = s.lastWritingSource || this.lastWritingSource;
            this.lastWritingResult = s.lastWritingResult || this.lastWritingResult;
            this.lastWritingDiffSource = s.lastWritingDiffSource || '';

            this.translationSourceSentences = s.translationSourceSentences || [];
            this.translationTargetSentences = s.translationTargetSentences || [];
            this.writingSourceSentences = s.writingSourceSentences || [];
            this.writingTargetSentences = s.writingTargetSentences || [];

            if (this.uiManager.elements.sourceText) {
                const val = s.sourceText || '';
                this.uiManager.elements.sourceText.value = val;
                this.uiManager.updateCharCount(val);
            }
            if (this.uiManager.elements.translatedText) {
                const val = s.targetText || '';
                this.uiManager.elements.translatedText.value = val;
            }
            
            if (s.sourceText) this.sourceSentences = this.textProcessor.splitIntoSentences(s.sourceText);
            if (s.targetText) this.targetSentences = this.textProcessor.splitIntoSentences(s.targetText);
            
            if (this.uiManager.elements.sourceLang) {
                this.uiManager.elements.sourceLang.value = s.sourceLang || 'auto';
                const event = new Event('change', { bubbles: true });
                event.isProgrammatic = true;
                this.uiManager.elements.sourceLang.dispatchEvent(event);
            }
            if (this.uiManager.elements.targetLang) {
                this.uiManager.elements.targetLang.value = s.targetLang || 'en-gb';
                const event = new Event('change', { bubbles: true });
                event.isProgrammatic = true;
                this.uiManager.elements.targetLang.dispatchEvent(event);
            }

            if (this.uiManager.elements.sourceLang && this.uiManager.elements.sourceLang.value === 'auto' && this.uiManager.elements.sourceText?.value.length > 20) {
                this.scheduleLanguageDetection();
            }

            if (this.uiManager.elements.docSourceLang) {
                this.uiManager.elements.docSourceLang.value = s.docSourceLang || 'auto';
                this.uiManager.elements.docSourceLang.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (this.uiManager.elements.docTargetLang) {
                this.uiManager.elements.docTargetLang.value = s.docTargetLang || 'en-gb';
                this.uiManager.elements.docTargetLang.dispatchEvent(new Event('change', { bubbles: true }));
            }
            
            this.selectedStyle = s.style || 'default';
            this.selectedTone = s.tone || 'default';
            this.selectedFormality = s.formality || 'default';
            this.showChangesEnabled = !!s.showChanges;
            if (this.uiManager.elements.showChangesToggle) this.uiManager.elements.showChangesToggle.checked = this.showChangesEnabled;

            // Update baseline for button locking
            this.lastProcessedSourceText = this.uiManager.elements.sourceText?.value.trim() || '';
            this.lastProcessedSourceLang = this.uiManager.elements.sourceLang?.value || 'auto';
            this.lastProcessedTargetLang = this.getCurrentTargetLang();
            this.lastProcessedGlossaryIds = Array.from(this.glossaryManager?.state.activeGlossaries || []).join(',');
            this.lastProcessedModel = this.selectedModel?.id;
            this.lastProcessedStyle = this.selectedStyle;
            this.lastProcessedTone = this.selectedTone;
            this.lastProcessedFormality = this.selectedFormality;

            if (s.lastUserModelId) {
                this.lastUserModelId = s.lastUserModelId;
            }

            if (s.selectedModelId) {
                const models = this.getAvailableModels();
                this.selectedModel = models.find(m => m.id === s.selectedModelId) || null;
                if (!this.lastUserModelId) this.lastUserModelId = this.selectedModel?.id;
                this.lastProcessedModel = this.selectedModel?.id;
            }
            
            this.updateButtonState();
        } catch (e) {
            console.error('Failed to load session', e);
        }
    }

    restoreSession() {
        const raw = sessionStorage.getItem('hawki_text_session');
        if (!raw || !this.glossaryManager) return;

        try {
            const session = JSON.parse(raw);
            const glossaryIds = Array.isArray(session.glossaryIds) ? session.glossaryIds.map(String) : [];
            this.glossaryManager.state.activeGlossaries = new Set(glossaryIds);
            this.glossaryManager.renderSidebarGlossaryList();
        } catch (e) {
            console.error('Failed to restore glossary session', e);
        }
    }

    getCurrentTargetLang() {
        if (this.currentMode === 'writing') {
            const sourceLang = this.uiManager.elements.sourceLang?.value;
            return sourceLang === 'auto' ? null : sourceLang;
        }
        return this.uiManager.elements.targetLang?.value || 'en-gb';
    }

    getAvailableModels() {
        return window.TranslationData?.models || [];
    }

    hasChanges() {
        if (this.isLoading) return false;
        
        const currentText = this.uiManager.elements.sourceText?.value.trim() || '';
        const currentSourceLang = this.uiManager.elements.sourceLang?.value || 'auto';
        const currentTargetLang = this.getCurrentTargetLang();

        const textChanged = currentText !== this.lastProcessedSourceText;
        const sourceLangChanged = currentSourceLang !== this.lastProcessedSourceLang;
        const targetLangChanged = currentTargetLang !== this.lastProcessedTargetLang;
        const modelTargetChanged = (this.selectedModel?.id || null) !== (this.lastProcessedModel || null);
        const styleChanged = (this.selectedStyle || 'default') !== (this.lastProcessedStyle || 'default') ||
                              (this.selectedTone || 'default') !== (this.lastProcessedTone || 'default') ||
                              (this.selectedFormality || 'default') !== (this.lastProcessedFormality || 'default');
        
        const activeGlossaryIds = Array.from(document.querySelectorAll('#sidebarGlossaryList input:checked')).map(cb => cb.value).join(',');
        const glossaryChanged = activeGlossaryIds !== this.lastProcessedGlossaryIds;

        return textChanged || sourceLangChanged || targetLangChanged || modelTargetChanged || styleChanged || glossaryChanged;
    }

    updateButtonState() {
        this.uiManager.updateButtonState(this.hasChanges());
    }

    selectModel(modelId, isProgrammatic = false) {
        const models = this.getAvailableModels();
        this.selectedModel = models.find(m => m.id === modelId) || null;
        if (!isProgrammatic) {
            this.lastUserModelId = modelId;
        }
        this.uiManager.updateModelLabel();
        this.updateButtonState();
        this.saveSession();
    }

    isSentenceChanged(index) {
        return this.sourceSentences[index] !== undefined && 
               this.targetSentences[index] !== undefined && 
               this.sourceSentences[index] !== this.targetSentences[index];
    }

    undoSentenceImprovement(index) {
        const original = this.sourceSentences[index];
        if (original !== undefined) {
            this.targetSentences[index] = original;
            this.uiManager.updateOutputUI();
            this.updateButtonState();
            this.saveSession();
        }
    }

    async applySpecificRephrase(index, text, mode, tokenIndex) {
        this.sentenceProcessor.hideWriteContextMenu();

        if (mode === 'word') {
            const originalSentence = this.targetSentences[index] || '';
            const tokens = this.textProcessor.getSentenceTokens(originalSentence);
            if (tokens[tokenIndex] !== undefined) {
                tokens[tokenIndex] = text.replace(/\[\[TARGET\]\]/g, '');
                let newSentence = tokens.join('');
                
                // Exakt das Leerzeichen des aktuell erzeugten Satzes auslesen
                const localMatch = newSentence.match(/[\s\r\n]+$/);
                const localTrailing = localMatch ? localMatch[0] : '';
                
                this.targetSentences[index] = newSentence;
                this.uiManager.updateOutputUI();
                
                try {
                    this.uiManager.maskSentences([index], tokenIndex);
                    
                    const rawResult = await this.languageService.improve({
                        text: newSentence,
                        context: originalSentence,
                        type: 'correction',
                        target_lang: this.getCurrentTargetLang(),
                        model: this.selectedModel?.id
                    });
                    
                    if (rawResult.success && rawResult.data && rawResult.data.text) {
                        let correctedText = Array.isArray(rawResult.data.text) ? rawResult.data.text[0] : rawResult.data.text;
                        // Forcierte Zuweisung: LLM Trimmen + 100% korrekte Anfügung der Ursprungs-Leerzeichen
                        this.targetSentences[index] = correctedText.replace(/[\s\r\n]+$/, '') + localTrailing;
                        this.uiManager.updateOutputUI();
                    }
                } catch (error) {
                    console.error('[Correction Service] Automatic correction failed:', error);
                } finally {
                    this.uiManager.clearPartialSkeletons();
                }
            }
        } else {
            const sourceS = this.sourceSentences[index] || '';
            const match = sourceS.match(/[\s\r\n]+$/);
            const trailing = match ? match[0] : '';
            this.targetSentences[index] = text.replace(/[\s\r\n]+$/, '') + trailing;
            this.uiManager.updateOutputUI();
        }
        
        this.updateButtonState();
        this.saveSession();
    }

    highlightSourceSentence(index) {
        if (!this.sourceSentences || this.sourceSentences.length <= index || index < 0) return;
        
        const sourceBoard = this.uiManager.elements.sourceBoard;
        const sourceText = this.uiManager.elements.sourceText;
        if (!sourceBoard || !sourceText) return;
        
        const currentText = sourceText.value.trim();
        const lastText = (this.lastProcessedSourceText || '').trim();
        
        // If the source text hasn't been edited, it's safe to show the interactive board again
        if (currentText === lastText && sourceBoard.style.display === 'none') {
            sourceBoard.style.display = 'block';
            sourceText.style.display = 'none';
        }
        
        // Remove existing active states
        sourceBoard.querySelectorAll('.sentence-item.active-context').forEach(el => el.classList.remove('active-context'));
        
        const targetElement = sourceBoard.querySelector(`.sentence-item[data-index="${index}"]`);
        if (targetElement) {
            targetElement.classList.add('active-context');
        }
    }

    clearSourceHighlight() {
        const sourceBoard = this.uiManager.elements.sourceBoard;
        if (sourceBoard) {
            sourceBoard.querySelectorAll('.sentence-item.active-context').forEach(el => el.classList.remove('active-context'));
        }
    }

    hoverSourceSentence(index) {
        if (!this.sourceSentences || this.sourceSentences.length <= index || index < 0) return;
        
        const sourceBoard = this.uiManager.elements.sourceBoard;
        const sourceText = this.uiManager.elements.sourceText;
        if (!sourceBoard || !sourceText) return;
        
        const currentText = sourceText.value.trim();
        const lastText = (this.lastProcessedSourceText || '').trim();
        
        if (currentText === lastText && sourceBoard.style.display === 'none' && document.activeElement !== sourceText) {
            sourceBoard.style.display = 'block';
            sourceText.style.display = 'none';
        }
        
        sourceBoard.querySelectorAll('.sentence-item.hover-context').forEach(el => el.classList.remove('hover-context'));
        
        const targetElement = sourceBoard.querySelector(`.sentence-item[data-index="${index}"]`);
        if (targetElement) {
            targetElement.classList.add('hover-context');
        }
    }

    clearSourceHover() {
        const sourceBoard = this.uiManager.elements.sourceBoard;
        if (sourceBoard) {
            sourceBoard.querySelectorAll('.sentence-item.hover-context').forEach(el => el.classList.remove('hover-context'));
        }
    }

}
