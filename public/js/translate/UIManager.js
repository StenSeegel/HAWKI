/**
 * UI Manager for HAWKI Translation.
 * Handles general UI components, dropdowns, subviews, and state synchronization.
 */
import { DOM_IDS, getTranslations } from './Constants.js';

export class UIManager {
    constructor(app) {
        this.app = app;
        this.t = getTranslations();
        this.elements = {};
        this.initElements();
        this.initCustomDropdowns();
        this.attachListeners();
    }

    initElements() {
        const ids = [
            'sourceText', 'sourceBoard', 'translatedText', 'sourceLang', 'targetLang',
            'sourceLangDropdown', 'targetLangDropdown', 'docSourceLangDropdown', 'docTargetLangDropdown',
            'translateBtn', 'translationModeBtn', 'rephraseModeBtn', 'documentModeBtn',
            'translateBoard', 'documentBoard', 'rephraseStyle', 'rephraseStyleWrapper',
            'toolsInfoText', 'aiModel', 'copyInputBtn', 'copyOutputBtn', 'swapLanguagesBtn',
            'charCount', 'targetCharCount', 'outputSkeleton', 'improveTargetBtn',
            'translateTargetBtn', 'errorMessage', 'deleteSourceBtn', 'lockOutputIcon',
            'styleSelectorBtn', 'sidebarStyleSubview', 'styleSubviewBackBtn', 'selectedStyleLabel',
            'styleSection', 'toneSection', 'formalitySection', 'globalStandardBtn', 'glossaryBtn',
            'showChangesToggle', 'diffView', 'editingToolsSection',
            'modelSelectorBtn', 'selectedModelLabel', 'sidebarModelSubview', 'modelSubviewBackBtn', 'sidebarModelList'
        ];

        const camelToKebab = (value) => value.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
        ids.forEach(id => {
            const key = id.replace(/[A-Z]/g, letter => `_${letter}`).toUpperCase();
            const mappedId = DOM_IDS[key];
            const kebabId = camelToKebab(id);
            this.elements[id] =
                (mappedId ? document.getElementById(mappedId) : null) ||
                document.getElementById(id) ||
                document.getElementById(kebabId);
        });

        this.elements.styleSelectors = document.querySelectorAll('.style-selector');
        this.elements.toneSelectors = document.querySelectorAll('.tone-selector');
        this.elements.formalitySelectors = document.querySelectorAll('.formality-selector');
    }

    attachListeners() {
        const { elements } = this;

        if (elements.styleSelectorBtn) elements.styleSelectorBtn.addEventListener('click', () => this.openStyleSubview());
        if (elements.styleSubviewBackBtn) elements.styleSubviewBackBtn.addEventListener('click', () => this.closeStyleSubview());
        
        if (elements.modelSelectorBtn) elements.modelSelectorBtn.addEventListener('click', () => this.openModelSubview());
        if (elements.modelSubviewBackBtn) elements.modelSubviewBackBtn.addEventListener('click', () => this.closeModelSubview());

        if (elements.translationModeBtn) elements.translationModeBtn.addEventListener('click', () => this.app.switchMode('translation'));
        if (elements.rephraseModeBtn) elements.rephraseModeBtn.addEventListener('click', () => this.app.switchMode('rephrase'));
        if (elements.documentModeBtn) elements.documentModeBtn.addEventListener('click', () => this.app.switchMode('document'));

        if (elements.improveTargetBtn) {
            elements.improveTargetBtn.addEventListener('click', () => {
                const text = elements.translatedText?.value;
                if (text && elements.sourceText) {
                    const currentTargetLang = elements.targetLang?.value;
                    this.app.switchMode('rephrase', text);
                    
                    if (currentTargetLang && elements.sourceLang) {
                        elements.sourceLang.value = currentTargetLang;
                        const event = new Event('change', { bubbles: true });
                        elements.sourceLang.dispatchEvent(event);
                    }
                    this.app.translate();
                }
            });
        }
        if (elements.translateTargetBtn) {
            elements.translateTargetBtn.addEventListener('click', () => {
                const text = elements.translatedText?.value;
                if (text && elements.sourceText) {
                    const currentTargetLang = elements.targetLang?.value;
                    this.app.switchMode('translation', text);
                    
                    if (currentTargetLang && elements.sourceLang) {
                        elements.sourceLang.value = currentTargetLang;
                        const event = new Event('change', { bubbles: true });
                        elements.sourceLang.dispatchEvent(event);
                    }
                    this.app.translate();
                }
            });
        }

        elements.styleSelectors.forEach(btn => {
            btn.addEventListener('click', (e) => this.app.selectStyle(e.currentTarget.dataset.style));
        });
        
        elements.toneSelectors.forEach(btn => {
            btn.addEventListener('click', (e) => this.app.selectTone(e.currentTarget.dataset.tone));
        });

        elements.formalitySelectors.forEach(btn => {
            btn.addEventListener('click', (e) => this.app.selectFormality(e.currentTarget.dataset.formality));
        });

        if (elements.globalStandardBtn) {
            elements.globalStandardBtn.addEventListener('click', () => this.app.resetStyleSelections());
        }

        if (elements.copyInputBtn) elements.copyInputBtn.addEventListener('click', () => this.copyText(elements.sourceText, elements.copyInputBtn));
        if (elements.copyOutputBtn) elements.copyOutputBtn.addEventListener('click', () => this.copyText(elements.translatedText, elements.copyOutputBtn));
        

        window.addEventListener('resize', () => {
            const sentenceProcessor = this.app?.sentenceProcessor;
            const menu = sentenceProcessor?.elements?.writeContextMenu;
            if (!menu || menu.style.display !== 'flex') return;

            const anchor = sentenceProcessor.activeContextSentence;
            if (!anchor) return;

            const rect = anchor.getBoundingClientRect();
            sentenceProcessor.showWriteContextMenu(rect.top, sentenceProcessor.activeContextWord, sentenceProcessor.activeContextSentence);
        });

        const sourcePanelContent = elements.sourceText?.closest('.panel-content');
        if (sourcePanelContent && elements.sourceBoard) {
            
            // 1) Hide highlight board instantly on hover
            sourcePanelContent.addEventListener('mouseenter', () => {
                if (elements.sourceBoard.style.display !== 'none') {
                    elements.sourceBoard.style.display = 'none';
                    elements.sourceText.style.display = 'block';
                }
            });

            // 2) Restore highlight board if we leave and not focused / not edited
            sourcePanelContent.addEventListener('mouseleave', () => {
                if (document.activeElement !== elements.sourceText) {
                    const currentText = elements.sourceText.value.trim();
                    const lastText = (this.app.lastProcessedSourceText || '').trim();
                    if (currentText === lastText && elements.diffView && elements.diffView.style.display !== 'none') {
                        // Check if we have any active highlights to show, otherwise there's no reason to restore it
                        if (elements.sourceBoard.querySelector('.active-context')) {
                            elements.sourceText.style.display = 'none';
                            elements.sourceBoard.style.display = 'block';
                        }
                    }
                }
            });

            // 3) Restore when focus is lost (e.g. tabbing away) and mouse is already outside
            elements.sourceText.addEventListener('blur', () => {
                setTimeout(() => {
                    const isHovering = sourcePanelContent.matches(':hover');
                    if (!isHovering) {
                        const currentText = elements.sourceText.value.trim();
                        const lastText = (this.app.lastProcessedSourceText || '').trim();
                        if (currentText === lastText && elements.diffView && elements.diffView.style.display !== 'none') {
                            if (elements.sourceBoard.querySelector('.active-context')) {
                                elements.sourceText.style.display = 'none';
                                elements.sourceBoard.style.display = 'block';
                            }
                        }
                    }
                }, 50);
            });
        }

        if (elements.diffView && elements.translatedText) {
            let _diffUpdateTimeout = null;
            let _executeDiffUpdate = null;
            
            const forceDiffUpdate = () => {
                if (_executeDiffUpdate) {
                    clearTimeout(_diffUpdateTimeout);
                    _executeDiffUpdate();
                    _executeDiffUpdate = null;
                }
            };

            elements.diffView.addEventListener('input', () => {
                elements.translatedText.value = elements.diffView.innerText;
                // Trigger the app's standard save/character count loops
                elements.translatedText.dispatchEvent(new Event('input', { bubbles: true }));
                
                // Hide context menus once user physically types
                if (this.app.sentenceProcessor) {
                    this.app.sentenceProcessor.hideWriteContextMenu();
                }
                
                _executeDiffUpdate = () => {
                    const active = document.activeElement === elements.diffView;
                    let caret = 0;
                    if (active) {
                        try {
                            const selection = window.getSelection();
                            if (selection.rangeCount > 0) {
                                const range = selection.getRangeAt(0);
                                const preSelectionRange = range.cloneRange();
                                preSelectionRange.selectNodeContents(elements.diffView);
                                preSelectionRange.setEnd(range.startContainer, range.startOffset);
                                caret = preSelectionRange.toString().length;
                            }
                        } catch (e) {}
                    }
                    
                    this.toggleDiffView();
                    
                    if (active) {
                        try {
                            let charIndex = 0;
                            const range = document.createRange();
                            range.setStart(elements.diffView, 0);
                            range.collapse(true);
                            let nodeStack = [elements.diffView], node, stop = false;
                            
                            while (!stop && (node = nodeStack.pop())) {
                                if (node.nodeType === 3) {
                                    const nextCharIndex = charIndex + node.length;
                                    if (caret >= charIndex && caret <= nextCharIndex) {
                                        range.setStart(node, caret - charIndex);
                                        range.setEnd(node, caret - charIndex);
                                        stop = true;
                                    }
                                    charIndex = nextCharIndex;
                                } else {
                                    let i = node.childNodes.length;
                                    while (i--) {
                                        nodeStack.push(node.childNodes[i]);
                                    }
                                }
                            }
                            const sel = window.getSelection();
                            sel.removeAllRanges();
                            sel.addRange(range);
                        } catch (e) {}
                    }
                };

                // Debounced UI update to auto-refresh diff rendering with caret preservation
                clearTimeout(_diffUpdateTimeout);
                _diffUpdateTimeout = setTimeout(() => {
                    forceDiffUpdate();
                }, 800);
            });
            
            elements.diffView.addEventListener('mouseup', () => {
                // If there is an update pending, force it immediately because the user moved the mouse
                setTimeout(forceDiffUpdate, 10);
            });
            
            elements.diffView.addEventListener('keyup', (e) => {
                if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(e.key)) {
                    setTimeout(forceDiffUpdate, 10);
                }
            });
            
            elements.diffView.addEventListener('blur', () => {
                // Once editing is complete, force re-render from state to restore spans 
                // formatted correctly, keeping the UI completely in sync.
                setTimeout(() => {
                    this.toggleDiffView();
                }, 50);
            });
            
            // To prevent issues if user accidentally pastes rich HTML (like tables, images)
            elements.diffView.addEventListener('paste', (e) => {
                e.preventDefault();
                const text = (e.originalEvent || e).clipboardData.getData('text/plain');
                document.execCommand('insertText', false, text);
            });
        }
    }

    initCustomDropdowns() {
        const dropdownIds = ['sourceLangDropdown', 'targetLangDropdown', 'docSourceLangDropdown', 'docTargetLangDropdown'];
        dropdownIds.forEach(dropdownId => {
            const dropdown = document.getElementById(dropdownId);
            if (!dropdown) return;

            const trigger = dropdown.querySelector('.dropdown-trigger');
            const items = dropdown.querySelectorAll('.dropdown-item');
            const hiddenSelect = dropdown.querySelector('select');
            const selectedText = trigger.querySelector('.selected-text');

            trigger.addEventListener('click', (e) => {
                if (dropdown.classList.contains('locked') || (hiddenSelect && hiddenSelect.disabled)) return;
                e.stopPropagation();
                document.querySelectorAll('.custom-dropdown.open').forEach(d => {
                    if (d !== dropdown) d.classList.remove('open');
                });
                dropdown.classList.toggle('open');
            });

            items.forEach(item => {
                item.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const value = item.getAttribute('data-value');
                    selectedText.textContent = item.textContent;
                    items.forEach(i => i.classList.remove('selected'));
                    item.classList.add('selected');

                    if (hiddenSelect) {
                        hiddenSelect.value = value;
                        hiddenSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    dropdown.classList.remove('open');
                });
            });

            if (hiddenSelect) {
                hiddenSelect.addEventListener('change', () => {
                    const val = hiddenSelect.value;
                    const activeItem = Array.from(items).find(i => i.getAttribute('data-value') === val);
                    if (activeItem) {
                        selectedText.textContent = activeItem.textContent;
                        items.forEach(i => i.classList.remove('selected'));
                        activeItem.classList.add('selected');
                    }
                });
            }
        });

        if (!window._dropdownGlobalCloseAdded) {
            document.addEventListener('click', () => {
                document.querySelectorAll('.custom-dropdown.open').forEach(d => d.classList.remove('open'));
            });
            window._dropdownGlobalCloseAdded = true;
        }
    }

    openStyleSubview() {
        if (this.elements.sidebarStyleSubview) {
            this.elements.sidebarStyleSubview.style.display = 'flex';
            if (this.app.currentMode === 'rephrase') {
                if (this.elements.styleSection) this.elements.styleSection.style.display = 'block';
                if (this.elements.toneSection) this.elements.toneSection.style.display = 'block';
            } else {
                if (this.elements.styleSection) this.elements.styleSection.style.display = 'none';
                if (this.elements.toneSection) this.elements.toneSection.style.display = 'none';
            }
            if (this.elements.formalitySection) this.elements.formalitySection.style.display = 'block';
        }
    }

    closeStyleSubview() {
        if (this.elements.sidebarStyleSubview) {
            this.elements.sidebarStyleSubview.style.display = 'none';
        }
    }

    openModelSubview() {
        if (this.elements.sidebarModelSubview) {
            this.elements.sidebarModelSubview.style.display = 'flex';
            this.initModelList();
        }
    }

    closeModelSubview() {
        if (this.elements.sidebarModelSubview) {
            this.elements.sidebarModelSubview.style.display = 'none';
        }
    }

    initModelList() {
        const list = this.elements.sidebarModelList;
        if (!list) return;

        const models = this.app.getAvailableModels();
        const selectedId = this.app.selectedModel?.id;
        const configSystem = window.TranslationData.configSystem;

        let html = '';

        if (configSystem) {
            // Group and sort by provider (Database mode)
            const groups = {};
            models.forEach(model => {
                if (model.visible === false) return;
                const provider = model.provider_name || 'Unknown';
                if (!groups[provider]) {
                    groups[provider] = {
                        name: provider,
                        models: [],
                        display_order: model.provider_display_order ?? 9999
                    };
                }
                groups[provider].models.push(model);
            });

            const sortedGroups = Object.values(groups).sort((a, b) => {
                if (a.display_order !== b.display_order) return a.display_order - b.display_order;
                return a.name.localeCompare(b.name);
            });

            sortedGroups.forEach(group => {
                html += `
                    <div class="provider-group">
                        <div class="provider-header">
                            <span class="provider-name">${group.name}</span>
                        </div>`;
                
                group.models.sort((a, b) => {
                    const orderA = a.display_order ?? 9999;
                    const orderB = b.display_order ?? 9999;
                    if (orderA !== orderB) return orderA - orderB;
                    return (a.label || '').localeCompare(b.label || '');
                });

                group.models.forEach(model => {
                    html += this._renderModelItem(model, selectedId);
                });
                html += `</div>`;
            });
        } else {
            // Simple list (Config mode)
            models.forEach(model => {
                if (model.visible === false) return;
                html += this._renderModelItem(model, selectedId);
            });
        }

        list.innerHTML = html || `
            <button class="model-selector burger-item" disabled>
                <span class="dot red-c"></span>
                <span>No Models Configured</span>
            </button>`;

        // Add listeners to new items
        list.querySelectorAll('.model-selector:not([disabled])').forEach(item => {
            item.addEventListener('click', (e) => {
                const modelId = e.currentTarget.getAttribute('data-model-id');
                this.app.selectModel(modelId);
                this.closeModelSubview();
            });
        });
    }

    _renderModelItem(model, selectedId) {
        const status = model.status || 'online';
        const dotClass = status === 'online' ? 'grn-c' : (status === 'unknown' ? 'org-c' : 'red-c');
        const activeClass = selectedId === model.id ? 'active' : '';
        const disabledAttr = status === 'offline' ? 'disabled' : '';
        
        let toolsHtml = '';
        if (model.tools) {
            if (model.tools.vision) {
                toolsHtml += `<svg style="width: 14px; height: 14px; opacity: 0.5; stroke: currentColor; fill:none;"><use href="#icon-eye"></use></svg>`;
            }
            if (model.tools.file_upload) {
                toolsHtml += `<svg style="width: 14px; height: 14px; opacity: 0.5; stroke: currentColor; fill:none;"><use href="#icon-paperclip"></use></svg>`;
            }
            if (model.tools.web_search) {
                toolsHtml += `<svg style="width: 14px; height: 14px; opacity: 0.5; stroke: currentColor; fill:none;"><use href="#icon-world"></use></svg>`;
            }
            if (model.tools.reasoning) {
                toolsHtml += `<svg style="width: 14px; height: 14px; opacity: 0.5; stroke: currentColor; fill:none;"><use href="#icon-cpu"></use></svg>`;
            }
        }

        return `
            <button class="model-selector burger-item ${activeClass}" 
                    data-model-id="${model.id}" 
                    ${disabledAttr}>
                <span class="dot ${dotClass}"></span>
                <span>${model.label || model.id}</span>
                <div style="margin-left: auto; display: flex; gap: 0.25rem; align-items: center;">
                    ${toolsHtml}
                </div>
            </button>
        `;
    }

    updateModelLabel() {
        const { elements } = this;
        if (!elements.selectedModelLabel) return;
        const modelName = this.app.selectedModel ? (this.app.selectedModel.label || this.app.selectedModel.name || this.app.selectedModel.id) : null;
        elements.selectedModelLabel.textContent = modelName || (this.t.SelectModel || 'Select Model');
    }

    updateStyleUI(state) {
        const { elements } = this;
        const allDefault = (state.style === 'default' && state.tone === 'default' && state.formality === 'default');

        if (elements.globalStandardBtn) {
            elements.globalStandardBtn.classList.toggle('active', allDefault);
        }

        elements.styleSelectors.forEach(btn => {
            btn.classList.toggle('active', state.style !== 'default' && btn.dataset.style === state.style);
        });

        elements.toneSelectors.forEach(btn => {
            btn.classList.toggle('active', state.tone !== 'default' && btn.dataset.tone === state.tone);
        });

        elements.formalitySelectors.forEach(btn => {
            btn.classList.toggle('active', state.formality !== 'default' && btn.dataset.formality === state.formality);
        });
    }

    updateStyleLabel(state) {
        const { elements } = this;
        if (!elements.selectedStyleLabel) return;
        
        const valueSpan = elements.selectedStyleLabel.querySelector('.selection-value');
        if (!valueSpan) return;

        let value = this.t['StyleDefault'] || 'Standard';

        if (state.style !== 'default') {
            value = this.t[`Style${this.capitalizeFirstLetter(state.style)}`] || state.style;
        } else if (state.tone !== 'default') {
            value = this.t[`Tone${this.capitalizeFirstLetter(state.tone)}`] || state.tone;
        } else if (state.formality !== 'default') {
            value = this.t[`Formality${this.capitalizeFirstLetter(state.formality)}`] || state.formality;
        }

        valueSpan.textContent = value;
    }

    capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    showSkeleton(show, indices = null) {
        const { elements } = this;
        if (!elements.outputSkeleton) return;

        if (show) {
            // Check if we can do partial masking on the board instead of full panel skeleton
            const isBoardVisible = elements.diffView && elements.diffView.style.display !== 'none';
            if (isBoardVisible && indices && indices.length > 0) {
                 this.maskSentences(indices);
                 return;
            }

            const source = elements.sourceText?.value || '';
            const lines = source.split('\n');

            // Sync the font size class (small-text) with source text
            const isSmall = elements.sourceText?.classList.contains('small-text');
            elements.outputSkeleton.classList.toggle('small-text', isSmall);

            let skeletonHtml = '';
            lines.forEach(line => {
                const trimmed = line.trim();
                if (trimmed.length === 0) {
                    // Empty line placeholder to maintain vertical spacing
                    skeletonHtml += '<div class="skeleton-line empty" style="height: 1.25rem; opacity: 0; margin-bottom: 0.75rem;"></div>';
                } else {
                    // Approximate wrapping for long paragraphs
                    const charsPerLine = isSmall ? 100 : 70;
                    const numSkeletonLines = Math.max(1, Math.ceil(trimmed.length / charsPerLine));
                    
                    for (let i = 0; i < numSkeletonLines; i++) {
                        const isLast = (i === numSkeletonLines - 1);
                        let width;
                        if (numSkeletonLines === 1) {
                            width = Math.min(100, Math.max(20, (trimmed.length / charsPerLine) * 100));
                        } else {
                            width = isLast ? Math.max(30, ((trimmed.length % charsPerLine) / charsPerLine) * 100) : 100;
                            if (width === 0) width = 100;
                        }
                        skeletonHtml += `<div class="skeleton-line" style="width: ${width}%; margin-bottom: 0.75rem;"></div>`;
                    }
                }
            });

            if (lines.length > 50) {
               skeletonHtml += '<div class="skeleton-line" style="width: 40%; opacity: 0.5;"></div>';
            }

            elements.outputSkeleton.innerHTML = skeletonHtml || '<div class="skeleton-line" style="width: 80%;"></div>';
            elements.outputSkeleton.style.display = 'flex';
            elements.outputSkeleton.scrollTop = 0;
            
            if (elements.translatedText) {
                elements.translatedText.style.display = 'none';
            }
            if (elements.diffView) {
                elements.diffView.style.display = 'none';
            }
        } else {
            elements.outputSkeleton.style.display = 'none';
            this.clearPartialSkeletons();
            // Restore visibility will be handled by updateOutputUI or regular display logic
        }
    }

    maskSentences(indices, excludedTokenIndex = null, board = 'target') {
        const { elements } = this;
        const targetBoard = board === 'source' ? elements.sourceBoard : elements.diffView;
        
        if (!targetBoard) return;

        indices.forEach(idx => {
            const el = targetBoard.querySelector(`.sentence-item[data-index="${idx}"]`);
            if (el) {
                el.classList.add('is-loading');
                if (excludedTokenIndex !== null) {
                    el.querySelectorAll(`[data-token-index="${excludedTokenIndex}"]`).forEach(excludedEl => {
                        excludedEl.classList.add('skip-skeleton');
                    });
                }
            }
        });
        
        // Also dim the board slightly to indicate processing
        targetBoard.style.opacity = '0.7';
    }

    clearPartialSkeletons(board = 'target') {
        const { elements } = this;
        const targetBoard = board === 'source' ? elements.sourceBoard : elements.diffView;
        
        if (!targetBoard) return;
        
        targetBoard.querySelectorAll('.sentence-item.is-loading').forEach(el => {
            el.classList.remove('is-loading');
        });
        targetBoard.style.opacity = '1';
        
        // Also ensure fallback cleanup
        if (board === 'target' && elements.sourceBoard) {
            elements.sourceBoard.querySelectorAll('.sentence-item.is-loading').forEach(el => el.classList.remove('is-loading'));
            elements.sourceBoard.style.opacity = '1';
        }
    }

    updateCharCount(text) {
        if (this.elements.charCount) {
            this.elements.charCount.textContent = text.length.toLocaleString();
        }
        if (this.elements.deleteSourceBtn) {
            this.elements.deleteSourceBtn.style.display = text.length > 0 ? 'flex' : 'none';
        }
        this.syncTextSizes();
    }

    updateTargetCharCount(text) {
        if (this.elements.targetCharCount) {
            this.elements.targetCharCount.textContent = text.length.toLocaleString();
        }
        this.syncTextSizes();
    }

    syncTextSizes() {
        const { elements } = this;
        if (!elements.sourceText || !elements.translatedText) return;

        const srcLen = elements.sourceText.value.length;
        const targetLen = elements.translatedText.value.length;
        const isSmall = srcLen > 50 || targetLen > 50;

        elements.sourceText.classList.toggle('small-text', isSmall);
        elements.translatedText.classList.toggle('small-text', isSmall);
        
        if (elements.diffView) {
            elements.diffView.classList.toggle('small-text', isSmall);
        }
        if (elements.sourceBoard) {
            elements.sourceBoard.classList.toggle('small-text', isSmall);
        }
    }

    clearTarget() {
        if (this.elements.translatedText) this.elements.translatedText.value = '';
        if (this.elements.targetCharCount) this.elements.targetCharCount.textContent = '0';
        if (this.elements.diffView) this.elements.diffView.innerHTML = '';
        if (this.elements.improveTargetBtn) this.elements.improveTargetBtn.style.display = 'none';
        if (this.elements.translateTargetBtn) this.elements.translateTargetBtn.style.display = 'none';
    }

    updateModeUI(mode) {
        if (this.elements.translationModeBtn) this.elements.translationModeBtn.classList.toggle('active', mode === 'translation');
        if (this.elements.rephraseModeBtn) this.elements.rephraseModeBtn.classList.toggle('active', mode === 'rephrase');
        if (this.elements.documentModeBtn) this.elements.documentModeBtn.classList.toggle('active', mode === 'document');

        // Hide target language and swap btn in writing mode
        const isRephrase = mode === 'rephrase';
        const targetLangWrapper = this.elements.targetLangDropdown?.closest('.language-selector-wrapper');
        const swapBtn = this.elements.swapLanguagesBtn;
        if (targetLangWrapper) targetLangWrapper.style.display = isRephrase ? 'none' : 'block';
        if (swapBtn) swapBtn.style.display = isRephrase ? 'none' : 'flex';

        // Model management across modes
        if (mode === 'document') {
            this.app.selectModel('deepl', true); // Programmatic lock
            if (this.elements.modelSelectorBtn) {
                this.elements.modelSelectorBtn.setAttribute('disabled', 'disabled');
                this.elements.modelSelectorBtn.style.pointerEvents = 'none';
                this.elements.modelSelectorBtn.style.opacity = '0.6';
                this.elements.modelSelectorBtn.style.filter = 'grayscale(1)';
            }
            if (this.elements.selectedModelLabel) {
                this.elements.selectedModelLabel.textContent = 'DeepL API Pro';
                this.elements.selectedModelLabel.style.opacity = '0.7';
            }
        } else {
            // Restore last user choice or fallback to mode-specific default
            const defaults = window.TranslationData?.defaults || {};
            const defaultId = (mode === 'rephrase' ? defaults.rephrase_model : defaults.translate_model);
            const targetId = this.app.lastUserModelId || defaultId;
            
            if (targetId) {
                this.app.selectModel(targetId, true);
            }

            if (this.elements.modelSelectorBtn) {
                this.elements.modelSelectorBtn.removeAttribute('disabled');
                this.elements.modelSelectorBtn.style.pointerEvents = '';
                this.elements.modelSelectorBtn.style.opacity = '';
                this.elements.modelSelectorBtn.style.filter = '';
            }
            if (this.app.selectedModel && this.elements.selectedModelLabel) {
                this.elements.selectedModelLabel.textContent = this.app.selectedModel.label;
                this.elements.selectedModelLabel.style.opacity = '';
            }
        }

        // Sidebar elements visibility
        if (this.elements.editingToolsSection) {
            this.elements.editingToolsSection.style.display = (mode === 'rephrase') ? 'block' : 'none';
        }
        if (this.elements.glossaryBtn) {
            this.elements.glossaryBtn.style.display = (mode === 'translation' || mode === 'document') ? 'flex' : 'none';
        }

        // Action button label
        if (this.elements.translateBtn) {
            const labelSpan = this.elements.translateBtn.querySelector('.label span');
            if (labelSpan) {
                labelSpan.textContent = (mode === 'rephrase') ? (this.t.ImproveText || 'Text überarbeiten') : (this.t.Translate || 'Text übersetzen');
            }
        }

        this.toggleDiffView();
    }

    showError(message) {
        if (!this.elements.errorMessage) return;
        this.elements.errorMessage.textContent = message;
        this.elements.errorMessage.style.display = 'block';
        setTimeout(() => this.elements.errorMessage.style.display = 'none', 5000);
    }

    hideMessages() {
        if (this.elements.errorMessage) this.elements.errorMessage.style.display = 'none';
    }

    /**
     * Update the output display after translation or rephrasing.
     */
    updateOutputUI() {
        const text = this.app.targetSentences.join('');
        if (this.elements.translatedText) {
            this.elements.translatedText.value = text;
        }
        this.updateTargetCharCount(text);
        
        if (this.elements.improveTargetBtn) {
            this.elements.improveTargetBtn.style.display = (this.app.currentMode === 'translation' && text) ? 'flex' : 'none';
        }
        if (this.elements.translateTargetBtn) {
            this.elements.translateTargetBtn.style.display = (this.app.currentMode === 'rephrase' && text) ? 'flex' : 'none';
        }

        this.toggleDiffView();
    }

    /**
     * Render the diff view or interactive board.
     * @param {boolean} fullDiff
     */
    renderDiffView(fullDiff) {
        if (!this.elements.diffView || !this.app.lastSourceText) return;
        if (!window.TextDiff) { console.warn('textDiff.js not loaded'); return; }

        const fullText = this.app.targetSentences.join('');
        const ops = window.TextDiff.compute(this.app.lastSourceText, fullText);
        this.elements.diffView.innerHTML = fullDiff
            ? window.TextDiff.renderHTML(ops)
            : window.TextDiff.renderHighlightHTML(ops);
    }

    /**
     * Toggle visibility between textarea and interactive board.
     */
    toggleDiffView() {
        const { elements } = this;
        if (!elements.diffView || !elements.translatedText) return;

        const val = elements.translatedText.value.trim();
        if (!val) {
            elements.diffView.style.display = 'none';
            elements.translatedText.style.display = 'block';
            if (elements.lockOutputIcon) elements.lockOutputIcon.style.display = 'none';
            if (elements.sourceBoard && elements.sourceText) {
                elements.sourceBoard.style.display = 'none';
                elements.sourceText.style.display = 'block';
            }
            return;
        }

        if (this.app.currentMode === 'rephrase' && this.app.lastSourceText && this.app.showChangesEnabled) {
            this.renderDiffView(true);
            elements.translatedText.style.display = 'none';
            elements.diffView.style.display = 'block';
            elements.diffView.setAttribute('contenteditable', 'false');
            if (elements.lockOutputIcon) elements.lockOutputIcon.style.display = 'flex';
            
            if (elements.sourceBoard && elements.sourceText) {
                elements.sourceBoard.style.display = 'none';
                elements.sourceText.style.display = 'block';
            }
        } else {
            // Interactive Board Mode
            elements.diffView.setAttribute('contenteditable', 'true');
            if (elements.lockOutputIcon) elements.lockOutputIcon.style.display = 'none';
            const isHtml = val.includes('<') && val.includes('>') && /<[a-z/][^>]*>/i.test(val);
            const mapping = typeof this.app.getSentenceMapping === 'function' && this.app.baselineTargetSentences
                ? this.app.getSentenceMapping(this.app.targetSentences, this.app.baselineTargetSentences) 
                : null;
            
            const content = this.app.sentenceProcessor.renderBoard(
                this.app.targetSentences, 
                this.app.sourceSentences, 
                this.app.lastSourceText, 
                this.app.showChangesEnabled, 
                this.app.currentMode,
                isHtml,
                mapping
            );
            
            elements.diffView.innerHTML = content || val;
            elements.translatedText.style.display = 'none';
            elements.diffView.style.display = 'block';
            
            if (elements.sourceBoard && elements.sourceText) {
                const srcContent = this.app.sentenceProcessor.renderSourceBoard(this.app.sourceSentences);
                elements.sourceBoard.innerHTML = srcContent || elements.sourceText.value;
                
                const sourcePanelContent = elements.sourceText.closest('.panel-content');
                const isHovering = sourcePanelContent && sourcePanelContent.matches(':hover');
                const isFocused = document.activeElement === elements.sourceText;
                
                if (!isHovering && !isFocused) {
                    elements.sourceText.style.display = 'none';
                    elements.sourceBoard.style.display = 'block';
                }
            }
        }
    }

    async updateButtonState(enabled) {
        if (this.elements.translateBtn) {
            this.elements.translateBtn.disabled = !enabled;
            this.elements.translateBtn.classList.toggle('btn-disabled', !enabled);
        }
    }

    async copyText(element, btn) {
        if (!element || !element.value) return;
        try {
            await navigator.clipboard.writeText(element.value);
            const reaction = btn.querySelector('.reaction');
            if (reaction) {
                reaction.style.opacity = '1';
                reaction.style.visibility = 'visible';
                setTimeout(() => {
                    reaction.style.opacity = '0';
                    reaction.style.visibility = 'hidden';
                }, 1500);
            } else {
                 const originalContent = btn.innerHTML;
                 btn.style.color = 'var(--success-color)';
                 setTimeout(() => btn.style.color = '', 1000);
            }
        } catch (error) {
            this.showError(this.t.Err_CopyFailed || "Kopieren fehlgeschlagen");
        }
    }
}
