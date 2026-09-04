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
import { TextCreateApp } from './TextCreateApp.js';

export class TranslateApp {
    constructor() {
        this.t = getTranslations();
        this.currentMode = 'translation'; // 'translation', 'rephrase', 'document'
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
        this.translationBaselineTargetSentences = [];
        this.rephraseSourceSentences = [];
        this.rephraseTargetSentences = [];
        this.rephraseBaselineTargetSentences = [];
        this.baselineTargetSentences = [];

        this.selectedModel = null;
        this.lastUserModelId = null;
        this.selectedStyle = 'default';
        this.selectedTone = 'default';
        this.selectedFormality = 'default';
        this.showChangesEnabled = false;
        this.liveTranslationEnabled = false;

        // Buffers for mode switching
        this.lastTranslationSource = '';
        this.lastTranslationResult = '';
        this.lastTranslationSourceLang = 'auto';
        this.lastTranslationTargetLang = 'en-gb';
        this.lastRephraseSource = '';
        this.lastRephraseResult = '';
        this.lastRephraseSourceLang = 'auto';
        this.lastRephraseDiffSource = '';
        this.lastSourceText = '';

        // Initialize Modules
        this.textProcessor = new TextProcessor(this);
        this.languageService = new LanguageService();
        this.uiManager = new UIManager(this);
        this.glossaryManager = new GlossaryManager(this);
        this.documentTranslator = new DocumentTranslator(this);
        this.sentenceProcessor = new SentenceProcessor(this);
        this.textCreateApp = new TextCreateApp(this);

        this.init();
    }

    init() {
        // Document translation requires DeepL API Pro
        const models = this.getAvailableModels();
        const hasDeepl = models.some(m => m.id === 'deepl');
        if (!hasDeepl && this.uiManager.elements.documentModeBtn) {
            this.uiManager.elements.documentModeBtn.style.display = 'none';
        }

        this.textCreateApp.init();
        this.loadSession();
        
        if (!this.selectedModel) {
            const models = this.getAvailableModels();
            const defaults = window.TranslationData?.defaults || {};
            const defaultId = (this.currentMode === 'rephrase' ? defaults.rephrase_model : defaults.translate_model) || null;
            
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

    getSentenceMapping(sentences, baselines) {
        if (!baselines || baselines.length === 0) return sentences.map((_, i) => i);
        
        const n = sentences.length;
        const m = baselines.length;
        
        // dp[i][j] stores the max similarity score of aligning first i target sentences with first j baseline sentences
        const dp = Array(n + 1).fill(null).map(() => Array(m + 1).fill(0));
        // choice[i][j]: 1 = diagonal (match), 2 = up (gap in baseline, i.e., inserted target), 3 = left (gap in target, e.g. deleted target)
        const choice = Array(n + 1).fill(null).map(() => Array(m + 1).fill(0));
        
        for (let i = 1; i <= n; i++) {
            for (let j = 1; j <= m; j++) {
                const s = sentences[i - 1].trim();
                const b = baselines[j - 1].trim();
                const sim = (!s && !b) ? 1.0 : ((!s || !b) ? 0 : this._similarity(s, b));
                
                let maxVal = dp[i-1][j]; // Option 2: gap in baseline (skip target sentence i)
                let dir = 2; 
                
                if (dp[i][j-1] > maxVal) { // Option 3: gap in target (skip baseline sentence j)
                    maxVal = dp[i][j-1];
                    dir = 3; 
                }
                
                // Option 1: match sentence i with baseline j (only if sim > 0.2 to prevent weak loose matching)
                const matchScore = sim > 0.2 ? dp[i-1][j-1] + sim : -1;
                
                if (matchScore >= maxVal && matchScore > dp[i-1][j-1]) {
                    maxVal = matchScore;
                    dir = 1;
                }
                
                dp[i][j] = maxVal;
                choice[i][j] = dir;
            }
        }
        
        const mapping = new Array(n).fill(-1);
        let i = n, j = m;
        while (i > 0 && j > 0) {
            if (choice[i][j] === 1) {
                const s = sentences[i - 1].trim();
                const b = baselines[j - 1].trim();
                const sim = (!s && !b) ? 1.0 : ((!s || !b) ? 0 : this._similarity(s, b));
                
                if (sim > 0.2) {
                    mapping[i - 1] = j - 1;
                }
                i--; j--;
            } else if (choice[i][j] === 2) {
                i--;
            } else {
                j--;
            }
        }
        
        // Handle exact 1:1 length fallback if DP gave unstructured maps
        if (n === m) {
            for (let k = 0; k < n; k++) {
                if (mapping[k] === -1) mapping[k] = k;
            }
        }
        
        return mapping;
    }

    _similarity(s1, s2) {
        if (s1 === s2) return 1.0;
        const w1 = s1.toLowerCase().split(/\s+/);
        const w2 = s2.toLowerCase().split(/\s+/);
        const s1Set = new Set(w1);
        let intersect = 0;
        for (const w of w2) {
            if (s1Set.has(w)) intersect++;
        }
        const union = w1.length + w2.length - intersect;
        return union === 0 ? 0 : intersect / union;
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
        if (elements.rephraseModeBtn) elements.rephraseModeBtn.addEventListener('click', () => this.switchMode('rephrase'));
        if (elements.documentModeBtn) elements.documentModeBtn.addEventListener('click', () => this.switchMode('document'));
        if (elements.translateBtn) elements.translateBtn.addEventListener('click', () => this.translate());
        
        if (elements.sourceText) {
            let fullSelectionWipe = false;

            elements.sourceText.addEventListener('keydown', (e) => {
                if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                    e.preventDefault();
                    if (elements.translateBtn && !elements.translateBtn.disabled) {
                        this.translate();
                    }
                }
            });

            elements.sourceText.addEventListener('beforeinput', (e) => {
                const src = elements.sourceText;
                if (src.selectionStart === 0 && src.selectionEnd === src.value.length && src.value.length > 0) {
                    fullSelectionWipe = true;
                } else {
                    fullSelectionWipe = false;
                }
            });

            elements.sourceText.addEventListener('input', () => {
                const val = elements.sourceText.value;
                this.uiManager.updateCharCount(val);
                this.scheduleLanguageDetection();
                this.scheduleLiveTranslation(val);
                this.updateButtonState();
                
                if (!val.trim() || fullSelectionWipe) {
                    if (elements.sourceLang && elements.sourceLang.value !== 'auto') {
                        elements.sourceLang.value = 'auto';
                        const changeEvent = new Event('change', { bubbles: true });
                        changeEvent.isProgrammatic = true;
                        elements.sourceLang.dispatchEvent(changeEvent);
                    }
                    this.uiManager.clearTarget();
                    this.lastSourceText = '';
                    this.lastProcessedSourceText = '';
                    this.lastTranslationResult = '';
                    this.lastRephraseResult = '';
                    this.targetSentences = [];
                    this.baselineTargetSentences = [];
                    this.sourceSentences = [];
                    fullSelectionWipe = false;
                    
                    if (!val.trim()) {
                        this.saveSession();
                        return;
                    }
                }
                
                fullSelectionWipe = false;
                this.saveSession();
            });
        }

        if (elements.translatedText) {
            elements.translatedText.addEventListener('input', () => {
                const val = elements.translatedText.value;
                this.uiManager.updateTargetCharCount(val);
                
                // If text is fully deleted over there...
                if (!val.trim()) {
                    this.targetSentences = [];
                    // We DO NOT clear the baseline if they clear manually, because Undo should still be possible.
                    this.saveSession();
                    return;
                }
                
                // We update targetSentences by splitting the text so that when they blur/leave the board reinstantiates correctly.
                this.targetSentences = this.textProcessor.splitIntoSentences(val);
                this.saveSession();
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

        if (elements.formattingToggle) {
            elements.formattingToggle.addEventListener('change', (e) => {
                if (this.textCreateApp.updateFormattingMode) {
                    this.textCreateApp.updateFormattingMode(e.target.checked);
                }
                this.saveSession();
            });
        }

        if (elements.aiContextMenuToggle) {
            elements.aiContextMenuToggle.addEventListener('change', (e) => {
                if (!e.target.checked) {
                    if (this.textCreateApp.selectionToolbar) {
                        this.textCreateApp.selectionToolbar.style.display = 'none';
                        this.textCreateApp.clearSelectionHighlight();
                        document.body.classList.remove('has-context-menu');
                    }
                }
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
                this.lastProcessedSourceText = '';
                this.lastTranslationResult = '';
                this.lastRephraseResult = '';
                this.targetSentences = [];
                this.baselineTargetSentences = [];
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
                this.saveSession();
                if (this.liveTranslationEnabled && this.uiManager.elements.sourceText?.value.trim()) {
                    this.translate();
                }
            });
        }

        if (elements.targetLang) {
            elements.targetLang.addEventListener('change', () => {
                this.preventSameLanguage('target');
                this.updateButtonState();
                this.saveSession();
                if (this.liveTranslationEnabled && this.uiManager.elements.sourceText?.value.trim()) {
                    this.translate();
                }
            });
        }
    }

    switchMode(mode, initialText = null, force = false) {
        if (!force && mode === this.currentMode) return;

        if (this.currentMode === 'translation') {
            this.lastTranslationSource = this.uiManager.elements.sourceText?.value || '';
            this.lastTranslationResult = this.uiManager.elements.translatedText?.value || '';
            this.lastTranslationSourceLang = this.uiManager.elements.sourceLang?.value || 'auto';
            this.lastTranslationTargetLang = this.uiManager.elements.targetLang?.value || 'en-gb';
            this.translationSourceSentences = [...this.sourceSentences];
            this.translationTargetSentences = [...this.targetSentences];
            this.translationBaselineTargetSentences = [...(this.baselineTargetSentences || [])];
        } else if (this.currentMode === 'rephrase') {
            this.lastRephraseSource = this.uiManager.elements.sourceText?.value || '';
            this.lastRephraseResult = this.uiManager.elements.translatedText?.value || '';
            this.lastRephraseSourceLang = this.uiManager.elements.sourceLang?.value || 'auto';
            this.lastRephraseDiffSource = this.lastSourceText;
            this.rephraseSourceSentences = [...this.sourceSentences];
            this.rephraseTargetSentences = [...this.targetSentences];
            this.rephraseBaselineTargetSentences = [...(this.baselineTargetSentences || [])];
        } else if (this.currentMode === 'create') {
            this.textCreateApp.lastCreateResult = this.textCreateApp.createMde ? this.textCreateApp.createMde.getMarkdown() : '';
            this.textCreateApp.lastCreateTargetLang = this.uiManager.elements.createTargetLang?.value || 'de';
            this.textCreateApp.createTargetSentences = [...this.targetSentences];
        }

        if (initialText !== null) {
            if (mode === 'rephrase') {
                this.lastRephraseSource = initialText;
                this.lastRephraseResult = '';
                this.lastRephraseDiffSource = '';
                this.rephraseSourceSentences = [];
                this.rephraseTargetSentences = [];
                this.rephraseBaselineTargetSentences = [];
            } else if (mode === 'translation') {
                this.lastTranslationSource = initialText;
                this.lastTranslationResult = '';
                this.translationSourceSentences = [];
                this.translationTargetSentences = [];
                this.translationBaselineTargetSentences = [];
            } else if (mode === 'create') {
                this.textCreateApp.lastCreateResult = initialText;
                this.textCreateApp.createTargetSentences = [];
            }
        }

        this.currentMode = mode;
        this.sentenceProcessor.hideWriteContextMenu();
        this.sentenceProcessor.clearCache();

        // Restore state
        if (mode === 'translation') {
            if (this.uiManager.elements.sourceText) this.uiManager.elements.sourceText.value = this.lastTranslationSource;
            if (this.uiManager.elements.translatedText) this.uiManager.elements.translatedText.value = this.lastTranslationResult;
            
            if (this.uiManager.elements.sourceLang) {
                this.uiManager.elements.sourceLang.value = this.lastTranslationSourceLang || 'auto';
                const event = new Event('change', { bubbles: true });
                event.isProgrammatic = true;
                this.uiManager.elements.sourceLang.dispatchEvent(event);
            }
            if (this.uiManager.elements.targetLang) {
                this.uiManager.elements.targetLang.value = this.lastTranslationTargetLang || 'en-gb';
                const event = new Event('change', { bubbles: true });
                event.isProgrammatic = true;
                this.uiManager.elements.targetLang.dispatchEvent(event);
            }
            
            this.sourceSentences = [...this.translationSourceSentences];
            this.targetSentences = [...this.translationTargetSentences];
            this.baselineTargetSentences = [...(this.translationBaselineTargetSentences || [])];
            this.lastSourceText = ''; // Translation mode doesn't rely on lastSourceText for diff by default unless enabled
        } else if (mode === 'rephrase') {
            if (this.uiManager.elements.sourceText) this.uiManager.elements.sourceText.value = this.lastRephraseSource;
            if (this.uiManager.elements.translatedText) this.uiManager.elements.translatedText.value = this.lastRephraseResult;
            
            if (this.uiManager.elements.sourceLang) {
                this.uiManager.elements.sourceLang.value = this.lastRephraseSourceLang || 'auto';
                const event = new Event('change', { bubbles: true });
                event.isProgrammatic = true;
                this.uiManager.elements.sourceLang.dispatchEvent(event);
            }
            
            this.sourceSentences = [...this.rephraseSourceSentences];
            this.targetSentences = [...this.rephraseTargetSentences];
            this.baselineTargetSentences = [...(this.rephraseBaselineTargetSentences || [])];
            this.lastSourceText = this.lastRephraseDiffSource;
        } else if (mode === 'create') {
            if (initialText !== null && this.textCreateApp.createMde) {
                this.textCreateApp.createMde.commands.setContent(this.textCreateApp.preprocessMarkdown(this.textCreateApp.lastCreateResult || ''), { contentType: 'markdown' });
            }
            
            if (this.uiManager.elements.createTargetLang) {
                this.uiManager.elements.createTargetLang.value = this.textCreateApp.lastCreateTargetLang || 'de';
                const event = new Event('change', { bubbles: true });
                event.isProgrammatic = true;
                this.uiManager.elements.createTargetLang.dispatchEvent(event);
            }
            
            this.sourceSentences = [];
            this.targetSentences = [...this.textCreateApp.createTargetSentences];
            this.baselineTargetSentences = [];
            this.lastSourceText = '';
            
            // Allow the user to edit text freely
            // TOAST UI Editor is always editable, we don't need to unset readOnly right now unless we locked it.
        }

        this.uiManager.updateCharCount(this.uiManager.elements.sourceText?.value || '');
        this.uiManager.updateTargetCharCount(this.uiManager.elements.translatedText?.value || '');
        
        // Trigger language detection if we have new text
        if (initialText !== null && initialText.trim().length > 20) {
            this.scheduleLanguageDetection();
        }

        // Update Board Visibility
        if (this.uiManager.elements.translateBoard) this.uiManager.elements.translateBoard.style.display = (mode === 'document' || mode === 'create') ? 'none' : 'grid';
        if (this.uiManager.elements.documentBoard) this.uiManager.elements.documentBoard.style.display = (mode === 'document') ? 'grid' : 'none';
        if (this.uiManager.elements.createBoard) {
            this.uiManager.elements.createBoard.style.display = (mode === 'create') ? 'grid' : 'none';
            if (mode === 'create') {
                const markdownTextarea = document.getElementById('createTextMarkdown');
                const markdownEditorContainer = document.getElementById('markdownEditorContainer');
                if (markdownTextarea && markdownEditorContainer && markdownEditorContainer.style.display !== 'none') {
                    // Slight delay to ensure layout is updated after display: grid
                    setTimeout(() => {
                        markdownTextarea.style.height = 'auto';
                        markdownTextarea.style.height = markdownTextarea.scrollHeight + 'px';
                    }, 10);
                }
            }
        }

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
                                    this.lastProcessedFormality !== this.selectedFormality ||
                                    this.lastProcessedTargetLang !== (this.uiManager.elements.targetLang?.value || 'en-gb') ||
                                    this.lastProcessedSourceLang !== (this.uiManager.elements.sourceLang?.value || 'auto') ||
                                    this.lastProcessedGlossaryIds !== Array.from(document.querySelectorAll('#sidebarGlossaryList input:checked')).map(cb => cb.value).join(',');

            let changedIndices = null;
            if (!settingsChanged && this.sourceSentences && this.sourceSentences.length > 0) {
                changedIndices = [];
                const maxSentences = Math.max(sourceSentences.length, this.sourceSentences.length);
                for (let i = 0; i < maxSentences; i++) {
                    const cur = sourceSentences[i] ? sourceSentences[i].trim() : undefined;
                    const prev = this.sourceSentences[i] ? this.sourceSentences[i].trim() : undefined;
                    if (cur !== prev) {
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

            if (changedIndices && changedIndices.length === 0) {
                this.isLoading = false;
                return;
            }

            this.uiManager.showSkeleton(true, changedIndices);

            const activeGlossaryIds = Array.from(document.querySelectorAll('#sidebarGlossaryList input:checked')).map(cb => cb.value);

            let sourceLang = this.uiManager.elements.sourceLang?.value || 'auto';
            let targetLang = this.uiManager.elements.targetLang?.value || 'en-gb';

            // Handle Auto-Detection and UI Update
            if (this.currentMode === 'rephrase') {
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
            if (this.currentMode !== 'rephrase' && sourceLang !== 'auto' && sourceLang === targetLang) {
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
            if (this.currentMode === 'rephrase') {
                data.type = 'improvement';
                result = await this.languageService.improve(data);
            } else {
                result = await this.languageService.process(data);
            }
            
            const rawText = result.data.text;
            const results = Array.isArray(rawText) ? rawText : [rawText];
            
            // Restore original trailing whitespace from source sentences if missing in result
            let updatedTargetSentences = [];
            
            // If we have a calculated changedIndices array, we perform a smart merge to preserve manual edits
            if (changedIndices !== null && this.sourceSentences && this.sourceSentences.length > 0) {
                // 1. Extract any extra target sentences directly added by the user without corresponding source sentences
                const extraTargetSentences = this.targetSentences && this.targetSentences.length > this.sourceSentences.length 
                    ? this.targetSentences.slice(this.sourceSentences.length) 
                    : [];
                
                // 2. Safely merge the backend results with existing target sentences
                for (let i = 0; i < sourceSentences.length; i++) {
                    const sourceS = sourceSentences[i] || '';
                    const match = sourceS.match(/\s+$/);
                    const trailing = match ? match[0] : '';
                    
                    let newT = '';
                    if (changedIndices.includes(i) || this.targetSentences[i] === undefined) {
                        // Use newly translated string from LLM
                        newT = results[i] !== undefined ? results[i] : (this.targetSentences[i] || '');
                    } else {
                        // Preserve user's manual edit
                        newT = this.targetSentences[i];
                    }
                    
                    updatedTargetSentences.push((trailing && !newT.endsWith(trailing)) ? newT.trimEnd() + trailing : newT);
                }
                
                // 3. Re-append the hanging manually typed target sentences
                updatedTargetSentences = updatedTargetSentences.concat(extraTargetSentences);
                
            } else {
                // Full overwrite logic
                updatedTargetSentences = results.map((s, i) => {
                    const sourceS = sourceSentences[i] || '';
                    const match = sourceS.match(/\s+$/);
                    const trailing = match ? match[0] : '';
                    return (trailing && !s.endsWith(trailing)) ? s.trimEnd() + trailing : s;
                });
            }
            
            this.targetSentences = updatedTargetSentences;

            this.sourceSentences = sourceSentences;
            this.baselineTargetSentences = [...this.targetSentences];
            this.lastSourceText = fullText;
            
            // Sync with mode-specific buffers to ensure switchMode captures the latest processed state
            if (this.currentMode === 'rephrase') {
                this.rephraseSourceSentences = [...this.sourceSentences];
                this.rephraseTargetSentences = [...this.targetSentences];
                this.rephraseBaselineTargetSentences = [...this.baselineTargetSentences];
                this.lastRephraseSource = fullText;
                this.lastRephraseResult = this.targetSentences.join('');
                this.lastRephraseSourceLang = sourceLang;
            } else if (this.currentMode === 'translation') {
                this.translationSourceSentences = [...this.sourceSentences];
                this.translationTargetSentences = [...this.targetSentences];
                this.translationBaselineTargetSentences = [...this.baselineTargetSentences];
                this.lastTranslationSource = fullText;
                this.lastTranslationResult = this.targetSentences.join('');
                this.lastTranslationSourceLang = sourceLang;
                this.lastTranslationTargetLang = targetLang;
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
            this.updateButtonState();
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

    scheduleLiveTranslation(val) {
        if (!this.liveTranslationEnabled) return;
        if (!val || val.trim().length === 0) return;
        
        // Anti-Spam: Nur für AI Modelle aktivieren
        if (!this.selectedModel || this.selectedModel.id === 'deepl' || this.selectedModel.provider === 'deepl') return;

        const countComplete = (text) => {
            if (!text) return 0;
            const sentences = this.textProcessor.splitIntoSentences(text);
            return sentences.filter(s => /[.!?]+(\s*['"»”]*\s*)$/.test(s)).length;
        };

        const currentComplete = countComplete(val);
        const lastComplete = countComplete(this.lastProcessedSourceText);

        // Sofortiger Trigger für neu fertiggestellte Sätze
        if (currentComplete > lastComplete && !this.isLoading) {
            if (this._liveDelayTimeout) clearTimeout(this._liveDelayTimeout);
            this.translate();
            return;
        }

        if (this._liveDelayTimeout) clearTimeout(this._liveDelayTimeout);
        this._liveDelayTimeout = setTimeout(() => {
            const currentVal = this.uiManager.elements.sourceText?.value;
            if (!currentVal || currentVal.trim() === '') return;

            if (this.isLoading) {
                // LLM rechnet noch -> Retry Loop
                this.scheduleLiveTranslation(currentVal);
            } else if (currentVal.trim() !== this.lastProcessedSourceText) {
                this.translate();
            }
        }, 800);
    }

    preventSameLanguage(side) {
        if (this.currentMode === 'rephrase') return;
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
        if (this.liveTranslationEnabled && this.uiManager.elements.sourceText?.value.trim()) {
            this.translate();
        }
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
        if (this.liveTranslationEnabled && this.uiManager.elements.sourceText?.value.trim()) {
            this.translate();
        }
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
        if (this.liveTranslationEnabled && this.uiManager.elements.sourceText?.value.trim()) {
            this.translate();
        }
    }

    resetStyleSelections() {
        this.selectedStyle = 'default';
        this.selectedTone = 'default';
        this.selectedFormality = 'default';
        this.uiManager.updateStyleUI(this.getState());
        this.uiManager.updateStyleLabel(this.getState());
        this.updateButtonState();
        this.saveSession();
        if (this.liveTranslationEnabled && this.uiManager.elements.sourceText?.value.trim()) {
            this.translate();
        }
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
            createTargetLang: this.uiManager.elements.createTargetLang?.value,
            createText: this.textCreateApp.createMde ? this.textCreateApp.createMde.getMarkdown() : '',
            createHtml: this.textCreateApp.createMde ? this.textCreateApp.createMde.getHTML() : '',
            style: this.selectedStyle,
            tone: this.selectedTone,
            formality: this.selectedFormality,
            showChanges: this.showChangesEnabled,
            aiContextMenu: this.uiManager.elements.aiContextMenuToggle ? this.uiManager.elements.aiContextMenuToggle.checked : true,
            liveTranslation: this.liveTranslationEnabled,
            selectedModelId: this.selectedModel?.id,
            lastUserModelId: this.lastUserModelId,
            glossaryIds: Array.from(document.querySelectorAll('#sidebarGlossaryList input:checked')).map(cb => cb.value),
            lastSourceText: this.lastSourceText,
            lastTranslationSource: this.lastTranslationSource,
            lastTranslationResult: this.lastTranslationResult,
            lastTranslationSourceLang: this.lastTranslationSourceLang,
            lastTranslationTargetLang: this.lastTranslationTargetLang,
            lastRephraseSource: this.lastRephraseSource,
            lastRephraseResult: this.lastRephraseResult,
            lastRephraseSourceLang: this.lastRephraseSourceLang,
            lastRephraseDiffSource: this.lastRephraseDiffSource,
            lastCreateResult: this.textCreateApp.lastCreateResult,
            lastCreateTargetLang: this.textCreateApp.lastCreateTargetLang,
            translationSourceSentences: this.translationSourceSentences,
            translationTargetSentences: this.translationTargetSentences,
            translationBaselineTargetSentences: this.translationBaselineTargetSentences,
            rephraseSourceSentences: this.rephraseSourceSentences,
            rephraseTargetSentences: this.rephraseTargetSentences,
            rephraseBaselineTargetSentences: this.rephraseBaselineTargetSentences,
            createTargetSentences: this.textCreateApp.createTargetSentences,
            baselineTargetSentences: this.baselineTargetSentences
        };
        sessionStorage.setItem('hawki_text_session', JSON.stringify(session));
    }

    loadSession() {
        const raw = sessionStorage.getItem('hawki_text_session');
        if (!raw) return;
        try {
            const s = JSON.parse(raw);
            this.currentMode = s.mode || 'translation';

            // Create mode may be role-restricted; fall back when its button is not rendered
            if (this.currentMode === 'create' && !document.getElementById('createModeBtn')) {
                this.currentMode = 'translation';
            }
            
            // Populate history variables so switchMode restores them correctly
            this.lastTranslationSource = (s.mode === 'translation' || !s.mode) ? (s.sourceText || '') : '';
            this.lastTranslationResult = (s.mode === 'translation' || !s.mode) ? (s.targetText || '') : '';
            this.lastRephraseResult = (s.mode === 'rephrase') ? (s.targetText || '') : (s.lastRephraseResult || '');
            
            this.lastTranslationSourceLang = (s.mode === 'translation' || !s.mode) ? (s.sourceLang || 'auto') : 'auto';
            this.lastTranslationTargetLang = (s.mode === 'translation' || !s.mode) ? (s.targetLang || 'en-gb') : 'en-gb';
            this.lastRephraseSourceLang = (s.mode === 'rephrase') ? (s.sourceLang || 'auto') : 'auto';
            
            this.textCreateApp.lastCreateTargetLang = (s.mode === 'create') ? (s.createTargetLang || 'de') : 'de';

            this.lastSourceText = s.lastSourceText || '';
            this.lastTranslationSource = s.lastTranslationSource || this.lastTranslationSource;
            this.lastTranslationResult = s.lastTranslationResult || this.lastTranslationResult;
            this.lastTranslationSourceLang = s.lastTranslationSourceLang || this.lastTranslationSourceLang;
            this.lastTranslationTargetLang = s.lastTranslationTargetLang || this.lastTranslationTargetLang;
            this.lastRephraseSource = s.lastRephraseSource || this.lastRephraseSource;
            this.lastRephraseResult = s.lastRephraseResult || this.lastRephraseResult;
            this.lastRephraseSourceLang = s.lastRephraseSourceLang || this.lastRephraseSourceLang;
            this.lastRephraseDiffSource = s.lastRephraseDiffSource || '';
            this.textCreateApp.lastCreateResult = s.lastCreateResult || this.textCreateApp.lastCreateResult;
            this.textCreateApp.lastCreateTargetLang = s.lastCreateTargetLang || this.textCreateApp.lastCreateTargetLang;

            this.translationSourceSentences = s.translationSourceSentences || [];
            this.translationTargetSentences = s.translationTargetSentences || [];
            this.translationBaselineTargetSentences = s.translationBaselineTargetSentences || [];
            this.rephraseSourceSentences = s.rephraseSourceSentences || [];
            this.rephraseTargetSentences = s.rephraseTargetSentences || [];
            this.rephraseBaselineTargetSentences = s.rephraseBaselineTargetSentences || [];
            this.textCreateApp.createTargetSentences = s.createTargetSentences || [];

            if (this.uiManager.elements.sourceText) {
                const val = s.sourceText || '';
                this.uiManager.elements.sourceText.value = val;
                this.uiManager.updateCharCount(val);
            }
            if (this.uiManager.elements.translatedText) {
                const val = s.targetText || '';
                this.uiManager.elements.translatedText.value = val;
            }
            if (this.textCreateApp.createMde) {
                const htmlVal = s.createHtml;
                const mdVal = s.createText || '';
                if (htmlVal) {
                    this.textCreateApp.createMde.commands.setContent(htmlVal, { contentType: 'html' });
                    this.textCreateApp.migrateLegacyMath();
                } else {
                    this.textCreateApp.createMde.commands.setContent(this.textCreateApp.preprocessMarkdown(mdVal), { contentType: 'markdown' });
                }
                const currentMd = this.textCreateApp.createMde.getMarkdown();
                if (this.uiManager.elements.createCharCount) {
                    this.uiManager.elements.createCharCount.textContent = currentMd.length.toLocaleString();
                }
                const wordCountEl = document.getElementById('createWordCount');
                if (wordCountEl) {
                    const words = currentMd.trim() ? currentMd.trim().split(/\s+/).length : 0;
                    wordCountEl.textContent = words.toLocaleString();
                }
            }
            
            if (s.sourceText) this.sourceSentences = this.textProcessor.splitIntoSentences(s.sourceText);
            if (s.targetText) this.targetSentences = this.textProcessor.splitIntoSentences(s.targetText);
            this.baselineTargetSentences = s.baselineTargetSentences || [...this.targetSentences];
            
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
            const systemLiveModeAllowed = window.TranslationData?.enableLiveMode !== false;
            this.liveTranslationEnabled = systemLiveModeAllowed ? !!s.liveTranslation : false;
            if (this.uiManager.elements.showChangesToggle) this.uiManager.elements.showChangesToggle.checked = this.showChangesEnabled;
            if (this.uiManager.elements.aiContextMenuToggle) this.uiManager.elements.aiContextMenuToggle.checked = s.aiContextMenu !== false;
            if (this.uiManager.elements.formattingToggle) {
                // Determine format state from session if applicable, or default to true
                // Note: we can store 'formattingEnabled' in session later if needed, but for now just use default true unless we explicitly save it.
            }
            
            this.updateLiveModeUI();

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
        if (this.currentMode === 'rephrase') {
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
        if (!currentText) return false;
        
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
        this.updateLiveModeUI();
        this.saveSession();
    }

    updateLiveModeUI() {
        if (!this.uiManager.elements.translateBtn) return;
        
        const isLiveEnabled = this.liveTranslationEnabled;
        const isDeepL = this.selectedModel?.id === 'deepl' || this.selectedModel?.provider === 'deepl';
        
        if (isLiveEnabled && !isDeepL) {
            document.documentElement.classList.add('live-mode-active');
        } else {
            document.documentElement.classList.remove('live-mode-active');
        }
        
        // Live translation toggle UI has been removed.
    }

    syncPushedSentence(targetText, sourceText, sourceIndex) {
        // Splice into baseline so mapping works instantly
        if (this.currentMode === 'rephrase') {
            // In writing mode, baseline is just sourceSentences, which was already spliced by caller.
        } else {
            if (this.baselineTargetSentences) {
                // Ensure targetText matches formatting by adding trailing space if needed
                let cleanTarget = targetText.trim();
                const matchTrailing = targetText.match(/[\s\n\r]+$/);
                cleanTarget += matchTrailing ? matchTrailing[0] : ' ';
                this.baselineTargetSentences.splice(sourceIndex, 0, cleanTarget);
            }
        }
        
        // Sync persistent storage arrays so mode switches don't erase it
        if (this.currentMode === 'translation') {
            this.translationSourceSentences = [...this.sourceSentences];
            this.translationTargetSentences = [...this.targetSentences];
            this.translationBaselineTargetSentences = [...(this.baselineTargetSentences || [])];
            this.lastTranslationSource = this.uiManager.elements.sourceText.value;
            this.lastProcessedSourceText = this.lastTranslationSource;
        } else if (this.currentMode === 'rephrase') {
            this.rephraseSourceSentences = [...this.sourceSentences];
            this.rephraseTargetSentences = [...this.targetSentences];
            this.rephraseBaselineTargetSentences = [...(this.baselineTargetSentences || [])];
            this.lastRephraseSource = this.uiManager.elements.sourceText.value;
            this.lastProcessedSourceText = this.lastRephraseSource;
        }
        
        this.uiManager.updateOutputUI();
        this.updateButtonState();
        this.saveSession();
    }

    isSentenceChanged(index) {
        if (this.currentMode === 'rephrase') {
            const mapping = this.getSentenceMapping(this.targetSentences, this.sourceSentences);
            const sourceIndex = mapping[index];
            return sourceIndex !== -1 && 
                   this.sourceSentences[sourceIndex] !== undefined && 
                   this.targetSentences[index] !== undefined && 
                   this.sourceSentences[sourceIndex] !== this.targetSentences[index];
        } else {
            const mapping = this.getSentenceMapping(this.targetSentences, this.baselineTargetSentences);
            const baselineIndex = mapping[index];
            return baselineIndex !== -1 && this.baselineTargetSentences && 
                   this.baselineTargetSentences[baselineIndex] !== undefined && 
                   this.targetSentences[index] !== undefined && 
                   this.baselineTargetSentences[baselineIndex] !== this.targetSentences[index];
        }
    }

    undoSentenceImprovement(index) {
        if (this.currentMode === 'rephrase') {
            const mapping = this.getSentenceMapping(this.targetSentences, this.sourceSentences);
            const sourceIndex = mapping[index];
            const original = sourceIndex !== -1 ? this.sourceSentences[sourceIndex] : undefined;
            if (original !== undefined) {
                this.targetSentences[index] = original;
                this.uiManager.updateOutputUI();
                this.updateButtonState();
                this.saveSession();
            }
        } else {
            const mapping = this.getSentenceMapping(this.targetSentences, this.baselineTargetSentences);
            const baselineIndex = mapping[index];
            const original = baselineIndex !== -1 && this.baselineTargetSentences ? this.baselineTargetSentences[baselineIndex] : undefined;
            if (original !== undefined) {
                this.targetSentences[index] = original;
                this.uiManager.updateOutputUI();
                this.updateButtonState();
                this.saveSession();
            }
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
        
        // Dynamically reflect user edits to allow highlighting even on unsaved edits
        if (currentText !== lastText) {
            const dynamicSentences = this.textProcessor.splitIntoSentences(sourceText.value);
            const html = this.sentenceProcessor ? this.sentenceProcessor.renderSourceBoard(dynamicSentences) : null;
            if (html) sourceBoard.innerHTML = html;
        }
        
        if (sourceBoard.style.display === 'none') {
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
        
        // Dynamically reflect user edits to allow hover linking even on unsaved edits
        if (currentText !== lastText) {
            const dynamicSentences = this.textProcessor.splitIntoSentences(sourceText.value);
            const html = this.sentenceProcessor ? this.sentenceProcessor.renderSourceBoard(dynamicSentences) : null;
            if (html) sourceBoard.innerHTML = html;
        }
        
        if (sourceBoard.style.display === 'none' && document.activeElement !== sourceText) {
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
