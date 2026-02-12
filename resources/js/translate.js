// Translate Application Logic

document.addEventListener('DOMContentLoaded', () => {
    const glossaryBtn = document.getElementById('glossary-btn');
    const modalOverlay = document.getElementById('glossaryModalOverlay');
    const closeBtn = document.getElementById('glossaryCloseBtn');
    const newGlossaryBtn = document.getElementById('newGlossaryBtn');
    const backBtn = document.getElementById('glossaryBackBtn');
    const listView = document.getElementById('glossaryListView');
    const createView = document.getElementById('glossaryCreateView');
    const modalTitle = document.getElementById('glossaryModalTitle');
    
    // Fallback translations if window.TranslationData is missing
    const t = window.TranslationData || {};

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
            modalTitle.textContent = t.NewGlossary || "New glossary";
        });
    }

    // Back to List View
    if(backBtn) {
        backBtn.addEventListener('click', () => {
            createView.classList.remove('active');
            listView.classList.add('active');
            modalTitle.textContent = t.Glossary || "Glossary";
        });
    }

    function resetView() {
        createView.classList.remove('active');
        listView.classList.add('active');
        modalTitle.textContent = t.Glossary || "Glossary";
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


class TranslateApp {
    constructor() {
        this.t = window.TranslationData || {};
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
        if (!this.aiModel) return;
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
            // Use server-provided model label which might be localized or just "GPT-4"
            option.textContent = model.label; 
            if (index === 0) option.selected = true;
            this.aiModel.appendChild(option);
        });
    }

    setupEventListeners() {
        if (this.translateBtn) this.translateBtn.addEventListener('click', () => this.translate());
        if (this.translationModeBtn) this.translationModeBtn.addEventListener('click', () => this.switchMode('translation'));
        if (this.writingModeBtn) this.writingModeBtn.addEventListener('click', () => this.switchMode('writing'));
        if (this.copyInputBtn) this.copyInputBtn.addEventListener('click', () => this.copyText(this.sourceText, this.copyInputBtn));
        if (this.copyOutputBtn) this.copyOutputBtn.addEventListener('click', () => this.copyText(this.translatedText, this.copyOutputBtn));
        if (this.swapLanguagesBtn) this.swapLanguagesBtn.addEventListener('click', () => this.swapLanguages());
        if (this.sourceText) this.sourceText.addEventListener('input', () => this.updateCharCount());

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
        if(this.translatedText) this.translatedText.value = '';
        if(this.targetCharCount) this.targetCharCount.textContent = '0';
        this.hideMessages();
        
        const btnLabel = this.translateBtn ? this.translateBtn.querySelector('.label span') : null;

        if (mode === 'translation') {
            if(this.translationModeBtn) this.translationModeBtn.classList.add('active');
            if(this.writingModeBtn) this.writingModeBtn.classList.remove('active');
            if (btnLabel) btnLabel.textContent = this.t.Translate || "Translate"; // Use translate key
            if(this.sourceLang) this.sourceLang.style.display = 'block';
            if(this.targetLang) this.targetLang.style.display = 'block';
            if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'none';
            if (this.toolsInfoText) this.toolsInfoText.style.display = 'block';
        } else {
            if(this.writingModeBtn) this.writingModeBtn.classList.add('active');
            if(this.translationModeBtn) this.translationModeBtn.classList.remove('active');
            if (btnLabel) btnLabel.textContent = this.t.ImproveText || "Text verbessern"; // Use ImproveText key
            if(this.sourceLang) this.sourceLang.style.display = 'none';
            if(this.targetLang) this.targetLang.style.display = 'none';
            if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'block';
            if (this.toolsInfoText) this.toolsInfoText.style.display = 'none';
        }
    }

    updateCharCount() {
        if(!this.sourceText || !this.charCount) return;
        const count = this.sourceText.value.length;
        this.charCount.textContent = count.toLocaleString();
    }

    toggleSidebar() {
       // Sidebar toggle logic is handled by global function togglePanelClass
    }

    async translate() {
        if (!this.sourceText.value.trim()) {
            this.showError(this.t.Err_EmptyInput || "Bitte geben Sie Text ein");
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
                    source_lang: (this.sourceLang && this.sourceLang.value === 'auto') ? null : (this.sourceLang ? this.sourceLang.value : null),
                    target_lang: this.targetLang ? this.targetLang.value : 'en'
                };
                successMessage = this.t.Success_Translated || "Übersetzung erfolgreich!";
            } else {
                endpoint = '/req/ai/write';
                requestData = {
                    text: this.sourceText.value,
                    target_lang: null,
                    model: this.aiModel ? this.aiModel.value : '',
                    style: this.writingStyle ? this.writingStyle.value : ''
                };
                successMessage = this.t.Success_Improved || "Text erfolgreich verbessert!";
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

            if (this.currentMode === 'translation' && data.data.detected_source_language && this.sourceLang && this.sourceLang.value === 'auto') {
                this.sourceLang.value = data.data.detected_source_language.toLowerCase();
            }

            this.showSuccess(successMessage);
        } catch (error) {
            this.showError(error.message || this.t.Status_Error || "Fehler beim Verarbeiten");
        } finally {
            this.isLoading = false;
            if(this.translateBtn) this.translateBtn.classList.remove('btn-loading');
        }
    }

    async swapLanguages() {
         if(!this.sourceLang || !this.targetLang) return;
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
        if (!element || !element.value) return;
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
            // this.showSuccess(this.t.Success_Copied || "Kopiert!"); 
        } catch (error) {
            this.showError(this.t.Err_CopyFailed || "Kopieren fehlgeschlagen");
        }
    }

    showError(message) {
        if(!this.errorMessage) return;
        this.errorMessage.textContent = message;
        this.errorMessage.style.display = 'block';
        setTimeout(() => this.errorMessage.style.display = 'none', 5000);
    }

    showSuccess(message) {
        if(!this.successMessage) return;
        this.successMessage.textContent = message;
        this.successMessage.style.display = 'block';
        setTimeout(() => this.successMessage.style.display = 'none', 3000);
    }

    hideMessages() {
        if(this.errorMessage) this.errorMessage.style.display = 'none';
        if(this.successMessage) this.successMessage.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', () => new TranslateApp());
