/**
 * Sentence Processor for HAWKI Translation.
 * Handles interactive board rendering, rephrasing, synonyms, and context menu logic.
 */
import { DOM_IDS, getTranslations } from './Constants.js';
import { escapeHtml } from './Utils.js';

export class SentenceProcessor {
    constructor(app) {
        this.app = app;
        this.t = getTranslations();
        
        this.activeContextSentence = null;
        this.activeContextWord = null;
        this.activeWordText = null;
        this.activeWordTokenIndex = null;
        this.rephraseMode = 'sentence'; // 'sentence' or 'word'
        this.activeAbortController = null;
        
        this.sentenceAlternativesCache = {}; // { index: [alternatives] }
        this.lastImprovedWords = {}; // { "index-tokenIndex": [synonyms] }
        
        this.elements = {};
        this.initElements();
        this.attachListeners();
    }

    initElements() {
        const camelToKebab = (value) => value.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
        const resolveById = (id) => {
            const key = id.replace(/[A-Z]/g, letter => `_${letter}`).toUpperCase();
            const mappedId = DOM_IDS[key];
            const kebabId = camelToKebab(id);
            return (mappedId ? document.getElementById(mappedId) : null) ||
                document.getElementById(id) ||
                document.getElementById(kebabId);
        };

        this.elements.diffView = resolveById('diffView');
        this.elements.writeContextMenu = resolveById('writeContextMenu');
        this.elements.suggestionsDropdown =
            resolveById('suggestionsDropdown') ||
            document.getElementById('write-suggestions-dropdown');

        // Buttons are class-based in the current board markup.
        this.elements.undoBtn = this.elements.writeContextMenu?.querySelector('.undo-btn') || null;
        this.elements.rephraseBtn = this.elements.writeContextMenu?.querySelector('.rephrase-btn') || null;
        this.elements.replaceWordBtn = this.elements.writeContextMenu?.querySelector('.replace-word-btn') || null;
        this.elements.closeBtn = this.elements.writeContextMenu?.querySelector('.close-btn') || null;
    }

    attachListeners() {
        const { elements } = this;
        
        if (elements.diffView) {
            elements.diffView.addEventListener('click', (e) => {
                e.stopPropagation();
                const wordSpan = e.target.closest('.word-item');
                const sentenceSpan = e.target.closest('.sentence-item');
                
                if (sentenceSpan) {
                    const rect = sentenceSpan.getBoundingClientRect();
                    this.showWriteContextMenu(rect.top, wordSpan, sentenceSpan);
                }
            });

            elements.diffView.addEventListener('mouseover', (e) => {
                const sentenceSpan = e.target.closest('.sentence-item');
                if (sentenceSpan && this.app && typeof this.app.hoverSourceSentence === 'function') {
                    const sourceIndexRaw = sentenceSpan.getAttribute('data-source-index');
                    if (sourceIndexRaw !== null && sourceIndexRaw !== '') {
                        this.app.hoverSourceSentence(parseInt(sourceIndexRaw, 10));
                    }
                }
            });

            elements.diffView.addEventListener('mouseout', (e) => {
                if (e.target.closest('.sentence-item') && this.app && typeof this.app.clearSourceHover === 'function') {
                    this.app.clearSourceHover();
                }
            });
        }

        if (elements.undoBtn) {
            elements.undoBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.activeContextSentence) {
                    const index = parseInt(this.activeContextSentence.dataset.index);
                    this.app.undoSentenceImprovement(index);
                }
            });
        }

        if (elements.rephraseBtn) {
            elements.rephraseBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.activeContextSentence) {
                    const index = parseInt(this.activeContextSentence.dataset.index);
                    this.rephraseMode = 'sentence';
                    elements.rephraseBtn.classList.add('active');
                    if (elements.replaceWordBtn) elements.replaceWordBtn.classList.remove('active');
                    this.renderSuggestions(index, true);
                }
            });
        }

        if (elements.replaceWordBtn) {
            elements.replaceWordBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.activeContextWord && this.activeContextSentence) {
                    const index = parseInt(this.activeContextSentence.dataset.index);
                    this.rephraseMode = 'word';
                    elements.replaceWordBtn.classList.add('active');
                    if (elements.rephraseBtn) elements.rephraseBtn.classList.remove('active');
                    this.renderSuggestions(index, true);
                }
            });
        }

        if (elements.closeBtn) {
            elements.closeBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.hideWriteContextMenu();
            });
        }

        document.addEventListener('click', (e) => {
            if (elements.writeContextMenu && !elements.writeContextMenu.contains(e.target) && 
                elements.suggestionsDropdown && !elements.suggestionsDropdown.contains(e.target)) {
                this.hideWriteContextMenu();
            }
        });
    }

    /**
     * Renders the interactive board content (sentences and tokens).
     */
    renderBoard(targetSentences, sourceSentences, lastSourceText, showChanges, currentMode, isHtml, sentenceMapping = null) {
        if (!this.elements.diffView) return '';

        let content = '';
        
        if (currentMode === 'writing' && !showChanges && lastSourceText && window.TextDiff && !isHtml) {
            const fullText = targetSentences.join('');
            const ops = window.TextDiff.compute(lastSourceText, fullText);
            
            const isInsertMap = new Array(fullText.length).fill(false);
            let ptr = 0;
            ops.forEach(op => {
                if (op.type === 'delete') return;
                if (op.type === 'insert') {
                    for (let i = 0; i < op.text.length; i++) {
                        isInsertMap[ptr + i] = true;
                    }
                }
                ptr += op.text.length;
            });

            let globalCharOffset = 0;
            content = targetSentences.map((s, sIndex) => {
                if (!s) return '';
                const tokens = this.app.textProcessor.getSentenceTokens(s);
                const wrapped = tokens.map((t, tIndex) => {
                    const startOffset = globalCharOffset;
                    globalCharOffset += t.length;
                    if (t.trim().length === 0) return escapeHtml(t);
                    
                    let hasInsert = false;
                    for (let i = 0; i < t.length; i++) {
                        if (isInsertMap[startOffset + i]) { hasInsert = true; break; }
                    }
                    
                    const cls = hasInsert ? 'word-item diff-highlight' : 'word-item';
                    return `<span class="${cls}" data-token-index="${tIndex}">${escapeHtml(t)}</span>`;
                }).join('');
                
                const sourceIndex = sentenceMapping ? sentenceMapping[sIndex] : sIndex;
                const srcAttr = sourceIndex !== null && sourceIndex !== -1 ? `data-source-index="${sourceIndex}"` : '';
                return `<span class="sentence-item" data-index="${sIndex}" ${srcAttr}>${wrapped}</span>`;
            }).join('');
        } else {
            content = targetSentences.map((s, index) => {
                if (!s) return '';
                const tokens = this.app.textProcessor.getSentenceTokens(s);
                const wrappedTokens = tokens.map((t, tIndex) => {
                    if (t.trim().length === 0) return escapeHtml(t);
                    const isTag = t.startsWith('<') && t.endsWith('>');
                    const isComment = t.startsWith('<!--') && t.endsWith('-->');
                    const classAttr = (isTag || isComment) ? 'word-item code-tag' : 'word-item';
                    return `<span class="${classAttr}" data-token-index="${tIndex}">${escapeHtml(t)}</span>`;
                }).join('');
                
                const sourceIndex = sentenceMapping ? sentenceMapping[index] : index;
                const srcAttr = sourceIndex !== null && sourceIndex !== -1 ? `data-source-index="${sourceIndex}"` : '';
                return `<span class="sentence-item" data-index="${index}" ${srcAttr}>${wrappedTokens}</span>`;
            }).join('');
        }

        return content;
    }

    renderSourceBoard(sourceSentences) {
        if (!sourceSentences || sourceSentences.length === 0) return '';
        
        // Add leading whitespace from textarea to match visually if needed
        let initialWhitespaceHtml = '';
        if (this.app.uiManager.elements.sourceText) {
            const raw = this.app.uiManager.elements.sourceText.value;
            const match = raw.match(/^\s+/);
            if (match) {
                initialWhitespaceHtml = escapeHtml(match[0]);
            }
        }
        
        return initialWhitespaceHtml + sourceSentences.map((s, index) => {
            if (!s) return '';
            const tokens = this.app.textProcessor.getSentenceTokens(s);
            const wrappedTokens = tokens.map((t, tIndex) => {
                if (t.trim().length === 0) return escapeHtml(t);
                const isTag = t.startsWith('<') && t.endsWith('>');
                const isComment = t.startsWith('<!--') && t.endsWith('-->');
                const classAttr = (isTag || isComment) ? 'word-item code-tag' : 'word-item';
                return `<span class="${classAttr}">${escapeHtml(t)}</span>`;
            }).join('');
            return `<span class="sentence-item" data-index="${index}">${wrappedTokens}</span>`;
        }).join('');
    }

    showWriteContextMenu(viewportTop, wordSpan, sentenceSpan) {
        const { elements } = this;
        if (!elements.writeContextMenu) return;
        
        this.hideWriteContextMenu();
        if (!sentenceSpan) return;
        
        this.activeContextWord = wordSpan;
        this.activeContextSentence = sentenceSpan;

        if (wordSpan) {
            this.activeWordText = wordSpan.textContent;
            const attrIdx = wordSpan.dataset.tokenIndex;
            this.activeWordTokenIndex = attrIdx !== undefined ? parseInt(attrIdx) : -1;
            this.activeContextWord.classList.add('active-context');
        }

        if (this.activeContextSentence) {
            this.activeContextSentence.classList.add('active-context');
            
            const sourceIndexRaw = this.activeContextSentence.getAttribute('data-source-index');
            const hasSourceLink = sourceIndexRaw !== null && sourceIndexRaw !== '';
            
            if (elements.undoBtn) {
                if (hasSourceLink) {
                    elements.undoBtn.style.display = 'flex';
                    const index = parseInt(this.activeContextSentence.dataset.index);
                    const isChanged = this.app.isSentenceChanged(index);
                    elements.undoBtn.classList.toggle('disabled', !isChanged);
                    elements.undoBtn.style.opacity = isChanged ? '1' : '0.5';
                    elements.undoBtn.style.pointerEvents = isChanged ? 'auto' : 'none';
                } else {
                    elements.undoBtn.style.display = 'none';
                }
            }
        }

        const groupElement = elements.writeContextMenu.parentElement;
        if (!groupElement) return;

        const groupRect = groupElement.getBoundingClientRect();
        const diffRect = elements.diffView.getBoundingClientRect();
        
        const left = diffRect.left - groupRect.left;
        
        // Ensure menu is measurable
        elements.writeContextMenu.style.visibility = 'hidden';
        elements.writeContextMenu.style.display = 'flex';
        const menuHeight = elements.writeContextMenu.offsetHeight || 42;
        elements.writeContextMenu.style.visibility = 'visible';

        const top = viewportTop - groupRect.top - menuHeight - 12;

        elements.writeContextMenu.style.left = `${Math.max(16, left)}px`;
        elements.writeContextMenu.style.top = `${top}px`;
        elements.writeContextMenu.style.zIndex = '1001'; // Ensure it's above the board
        elements.writeContextMenu.style.display = 'flex';

        if (sentenceSpan) {
            const tgtIndex = parseInt(sentenceSpan.dataset.index);
            const sourceIndexRaw = sentenceSpan.getAttribute('data-source-index');
            const hasSourceLink = sourceIndexRaw !== null && sourceIndexRaw !== '';
            
            if (hasSourceLink) {
                this.app.highlightSourceSentence(parseInt(sourceIndexRaw, 10));
            }
            
            // Check if we need to show the 'push-to-source' link icon or hide it
            if (elements.writeContextMenu) {
                const linkUIs = elements.writeContextMenu.querySelectorAll('.push-to-source-btn-container');
                linkUIs.forEach(ui => {
                    ui.style.display = !hasSourceLink ? 'flex' : 'none';
                });
                
                const linkBtn = elements.writeContextMenu.querySelector('button.push-to-source-btn');
                if (linkBtn) {
                    const newLinkBtn = linkBtn.cloneNode(true);
                    linkBtn.parentNode.replaceChild(newLinkBtn, linkBtn);
                    newLinkBtn.addEventListener('click', async (e) => {
                        e.stopPropagation();
                        // Get the current target sentence string
                        const sentenceText = this.app.targetSentences[tgtIndex];
                        if (!sentenceText || !this.app.uiManager.elements.sourceText) return;
                        
                        const st = this.app.uiManager.elements.sourceText;
                        
                        // 1. Find the correct injection point by scanning backward
                        let insertAfterSourceIndex = -1;
                        for (let ptr = tgtIndex - 1; ptr >= 0; ptr--) {
                            const siblingSpan = elements.diffView.querySelector(`.sentence-item[data-index="${ptr}"]`);
                            if (siblingSpan) {
                                const rawSrc = siblingSpan.getAttribute('data-source-index');
                                if (rawSrc !== null && rawSrc !== '') {
                                    insertAfterSourceIndex = parseInt(rawSrc, 10);
                                    break;
                                }
                            }
                        }
                        
                        // 2. Inject Literal Text 1:1 first (immediate feedback)
                        let newSrcArr = [...this.app.sourceSentences];
                        
                        let injectionText = sentenceText.trim();
                        // Force punctuation so it doesn't fuse with the next sentence
                        if (!/[.!?]+$/.test(injectionText)) {
                            injectionText += '.';
                        }
                        
                        const matchTrailing = sentenceText.match(/[\s\n\r]+$/);
                        injectionText += matchTrailing ? matchTrailing[0] : ' ';
                        
                        const newSourceIndex = insertAfterSourceIndex === -1 ? 0 : insertAfterSourceIndex + 1;
                        
                        // Ensure it doesn't fuse with the PREVIOUS sentence by adding a leading space if needed
                        if (newSourceIndex > 0) {
                            const prevSentence = newSrcArr[newSourceIndex - 1];
                            if (prevSentence && !/[\s\n\r]$/.test(prevSentence)) {
                                injectionText = ' ' + injectionText;
                            }
                        }
                        
                        newSrcArr.splice(newSourceIndex, 0, injectionText);
                        
                        st.value = newSrcArr.join('');
                        // Instantly re-render source board so mask finding works
                        this.app.sourceSentences = this.app.textProcessor.splitIntoSentences(st.value);
                        if (this.app.uiManager.elements.sourceBoard) {
                            this.app.uiManager.elements.sourceBoard.innerHTML = this.renderSourceBoard(this.app.sourceSentences);
                        }
                        st.dispatchEvent(new Event('input', { bubbles: true }));
                        
                        elements.diffView.style.pointerEvents = 'none';
                        
                        // Prevent link button from appearing again during transition by temporarily adding the attribute
                        if (sentenceSpan) {
                            sentenceSpan.setAttribute('data-source-index', newSourceIndex);
                        }
                        
                        // 3. If in translation mode, mask the new source and translate it
                        if (this.app.currentMode === 'translation') {
                            if (this.app.uiManager) {
                                this.app.uiManager.maskSentences([newSourceIndex], null, 'source');
                            }
                            
                            try {
                                const sourceLang = this.app.uiManager.elements.sourceLang?.value;
                                const targetLang = this.app.getCurrentTargetLang();
                                
                                const result = await this.app.languageService.process({
                                    text: [sentenceText],
                                    source_lang: targetLang && targetLang !== 'auto' ? targetLang : null,
                                    target_lang: sourceLang && sourceLang !== 'auto' ? sourceLang : 'de', 
                                    model: this.app.selectedModel?.id
                                });
                                
                                if (result && result.data && result.data.text) {
                                    let textRes = Array.isArray(result.data.text) ? result.data.text[0] : result.data.text;
                                    let translatedInjectionText = textRes.trim();
                                    
                                    if (!/[.!?]+$/.test(translatedInjectionText)) {
                                        translatedInjectionText += '.';
                                    }
                                    translatedInjectionText += matchTrailing ? matchTrailing[0] : ' ';
                                    
                                    // Update the recently inserted string directly
                                    let finalSrcArr = [...this.app.sourceSentences];
                                    finalSrcArr[newSourceIndex] = translatedInjectionText;
                                    
                                    st.value = finalSrcArr.join('');
                                    this.app.sourceSentences = this.app.textProcessor.splitIntoSentences(st.value);
                                    if (this.app.uiManager.elements.sourceBoard) {
                                        this.app.uiManager.elements.sourceBoard.innerHTML = this.renderSourceBoard(this.app.sourceSentences);
                                    }
                                    st.dispatchEvent(new Event('input', { bubbles: true }));
                                    
                                    this.app.syncPushedSentence(sentenceText, translatedInjectionText, newSourceIndex);
                                }
                            } catch (error) {
                                console.error('Back-translation failed', error);
                                // Fallback: literal string remains
                                this.app.syncPushedSentence(sentenceText, injectionText, newSourceIndex);
                            } finally {
                                if (this.app.uiManager) {
                                    this.app.uiManager.clearPartialSkeletons('source');
                                }
                                this.hideWriteContextMenu();
                                elements.diffView.style.pointerEvents = 'auto';
                                this.app.saveSession();
                            }
                        } else {
                            // Rephrase mode - literal copy is sufficient
                            this.app.syncPushedSentence(sentenceText, injectionText, newSourceIndex);
                            this.hideWriteContextMenu();
                            elements.diffView.style.pointerEvents = 'auto';
                            this.app.saveSession();
                        }
                    });
                }
            }
            
            this.renderSuggestions(tgtIndex, false);
        }
    }

    async renderSuggestions(index, show = false) {
        const { elements } = this;
        if (!elements.suggestionsDropdown || !this.activeContextSentence) return;
        
        if (!show) {
            elements.suggestionsDropdown.style.display = 'none';
            return;
        }

        elements.suggestionsDropdown.innerHTML = '';

        const isWordMode = this.rephraseMode === 'word';
        const source = isWordMode ? this.activeWordText : (this.app.currentMode === 'translation' ? this.app.targetSentences[index] : this.app.sourceSentences[index]);
        const cacheKey = isWordMode ? `${index}-${this.activeWordTokenIndex}` : index;
        
        elements.suggestionsDropdown.classList.toggle('is-word-mode', isWordMode);

        const positionDropdown = () => {
            const menuRect = elements.writeContextMenu.getBoundingClientRect();
            const groupRect = elements.writeContextMenu.parentElement.getBoundingClientRect();
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;
            const padding = 16;
            
            elements.suggestionsDropdown.style.display = 'flex';
            elements.suggestionsDropdown.style.visibility = 'hidden';
            const dropdownWidth = elements.suggestionsDropdown.offsetWidth;
            const dropdownHeight = elements.suggestionsDropdown.offsetHeight;
            elements.suggestionsDropdown.style.visibility = '';
            
            let finalLeft = parseInt(elements.writeContextMenu.style.left);
            let absLeft = groupRect.left + finalLeft;
            
            if (viewportWidth < 768) {
                finalLeft = padding - groupRect.left;
                elements.suggestionsDropdown.style.width = `calc(100vw - ${padding * 2}px)`;
            } else {
                elements.suggestionsDropdown.style.width = '';
                if (absLeft + dropdownWidth + padding > viewportWidth) {
                    absLeft = viewportWidth - dropdownWidth - padding;
                    finalLeft = absLeft - groupRect.left;
                }
                if (absLeft < padding) {
                    absLeft = padding;
                    finalLeft = absLeft - groupRect.left;
                }
            }
            elements.suggestionsDropdown.style.left = `${finalLeft}px`;
            
            const relativeMenuTop = parseInt(elements.writeContextMenu.style.top);
            const menuHeight = menuRect.height || 42;
            let finalTop = relativeMenuTop + menuHeight + 4;
            
            if (menuRect.bottom + 4 + dropdownHeight + padding > viewportHeight) {
                const topAboveMenu = relativeMenuTop - dropdownHeight - 4;
                if (menuRect.top - 4 - dropdownHeight > padding) {
                    finalTop = topAboveMenu;
                }
            }
            elements.suggestionsDropdown.style.top = `${finalTop}px`;
            
            if (show) {
                elements.suggestionsDropdown.style.display = 'flex';
                elements.suggestionsDropdown.classList.add('visible');
            } else {
                elements.suggestionsDropdown.style.display = 'none';
            }
        };

        let improvedList = isWordMode ? (this.lastImprovedWords[cacheKey] || []) : (this.sentenceAlternativesCache[index] || []);

        if (show && improvedList.length === 0) {
            const placeholder = escapeHtml(source || "...");
            elements.suggestionsDropdown.innerHTML = this.renderProposalMarkup(source, true, index) + 
                `<div class="suggestion-proposal" style="padding: 1.25rem; pointer-events: none;"><span class="sentence-item is-loading">${placeholder}</span></div>`;
            positionDropdown();
            elements.suggestionsDropdown.classList.add('visible');
            
            let result;
            if (isWordMode) {
                const context = this.app.targetSentences[index];
                const tokens = this.app.textProcessor.getSentenceTokens(context);
                if (tokens[this.activeWordTokenIndex] !== undefined) {
                    tokens[this.activeWordTokenIndex] = `[[TARGET]]${tokens[this.activeWordTokenIndex]}[[TARGET]]`;
                }
                const taggedContext = tokens.join('');
                const rawResult = await this.app.languageService.improve({
                    text: source,
                    type: 'synonyms',
                    context: taggedContext,
                    target_lang: this.app.getCurrentTargetLang()
                }, this.activeAbortController?.signal);
                const textData = rawResult.data.text;
                if (Array.isArray(textData)) {
                    result = textData;
                } else if (typeof textData === 'string') {
                    try {
                        result = JSON.parse(textData);
                    } catch (e) {
                        console.warn('Synonym response was not valid JSON, using as single recommendation:', textData);
                        result = [textData.replace(/[\[\]"]/g, '').trim()];
                    }
                } else {
                    result = textData;
                }

            } else {
                const rawResult = await this.app.languageService.improve({
                    text: source,
                    type: 'alternatives',
                    target_lang: this.app.getCurrentTargetLang(),
                    model: this.app.selectedModel?.id,
                    style: this.app.selectedStyle !== 'default' ? this.app.selectedStyle : null,
                    tone: this.app.selectedTone !== 'default' ? this.app.selectedTone : null,
                    formality: this.app.selectedFormality !== 'default' ? this.app.selectedFormality : null
                }, this.activeAbortController?.signal);
                result = rawResult.data.text;
            }

            if (result) {
                if (isWordMode) {
                    this.lastImprovedWords[cacheKey] = Array.isArray(result) ? result : [result];
                    improvedList = this.lastImprovedWords[cacheKey];
                } else {
                    if (!this.sentenceAlternativesCache[index]) this.sentenceAlternativesCache[index] = [];
                    const addList = Array.isArray(result) ? result : [result];
                    this.sentenceAlternativesCache[index].push(...addList);
                    improvedList = this.sentenceAlternativesCache[index];
                }
            }
        }

        const validImprovements = improvedList.filter(imp => imp && imp !== source);
        const allProposals = [source, ...validImprovements];

        elements.suggestionsDropdown.innerHTML = allProposals.map((imp, i) => this.renderProposalMarkup(imp, i === 0, index)).join('') + 
            `<div class="suggestion-action-btn" id="generate-more-btn"><i class="fa-solid fa-wand-magic-sparkles"></i><span>Mehr Alternativen</span></div>`;
        
        positionDropdown();
        elements.suggestionsDropdown.classList.add('visible');

        elements.suggestionsDropdown.querySelectorAll('.suggestion-proposal:not(.is-loading)').forEach((proposal, i) => {
            proposal.addEventListener('click', () => {
                const selectedText = allProposals[i];
                if (selectedText) this.app.applySpecificRephrase(index, selectedText, this.rephraseMode, this.activeWordTokenIndex);
            });
        });

        const moreBtn = elements.suggestionsDropdown.querySelector('#generate-more-btn');
        if (moreBtn) {
            moreBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.generateMoreAlternatives(index);
            });
        }
    }

    renderProposalMarkup(improved, isFirst, index) {
        let displayHtml = '';
        const source = this.rephraseMode === 'word' ? this.activeWordText : (this.app.currentMode === 'translation' ? this.app.targetSentences[index] : this.app.sourceSentences[index]);

        if (this.rephraseMode === 'word') {
            const originalSentence = this.app.targetSentences[index];
            const tokens = this.app.textProcessor.getSentenceTokens(originalSentence);
            if (tokens[this.activeWordTokenIndex] !== undefined) {
                const startToken = Math.max(0, this.activeWordTokenIndex - 2);
                const endToken = Math.min(tokens.length, this.activeWordTokenIndex + 3);
                const subset = tokens.slice(startToken, endToken).map(t => escapeHtml(t));
                subset[this.activeWordTokenIndex - startToken] = `<b>${escapeHtml(improved)}</b>`;
                let contextText = subset.join('');
                if (startToken > 0) contextText = '...' + contextText;
                if (endToken < tokens.length) contextText = contextText + '...';
                displayHtml = `&bdquo;${contextText}&ldquo;`;
            } else {
                displayHtml = escapeHtml(improved);
            }
        } else if (window.TextDiff && improved !== source) {
            const ops = window.TextDiff.compute(source, improved);
            displayHtml = ops.map(op => {
                if (op.type === 'delete') return '';
                return op.text.split(/(\s+)/).map(p => {
                    if (!p) return '';
                    if (p.trim().length === 0) return escapeHtml(p);
                    const diffClass = op.type === 'insert' ? ' class="suggestion-diff-highlight"' : '';
                    return `<span${diffClass}>${escapeHtml(p)}</span>`;
                }).join('');
            }).join('');
        } else {
            displayHtml = `&bdquo;${escapeHtml(improved)}&ldquo;`;
        }
        const extraClass = isFirst ? ' is-original' : '';
        return `<div class="suggestion-proposal${extraClass}">${displayHtml}</div>`;
    }

    async generateMoreAlternatives(index) {
        const actionBtn = this.elements.suggestionsDropdown.querySelector('#generate-more-btn');
        const isWordMode = this.rephraseMode === 'word';
        const source = isWordMode ? this.activeWordText : (this.app.currentMode === 'translation' ? this.app.targetSentences[index] : this.app.sourceSentences[index]);

        if (actionBtn) {
            const placeholder = escapeHtml(source || "...");
            const tempSkeleton = document.createElement('div');
            tempSkeleton.className = 'suggestion-proposal';
            tempSkeleton.style.padding = '1.25rem';
            tempSkeleton.innerHTML = `<span class="sentence-item is-loading">${placeholder}</span>`;
            actionBtn.before(tempSkeleton);
            actionBtn.style.display = 'none';
        }

        const cacheKey = isWordMode ? `${index}-${this.activeWordTokenIndex}` : index;
        const existing = isWordMode ? (this.lastImprovedWords[cacheKey] || []) : (this.sentenceAlternativesCache[index] || []);

        try {
            let result;
            if (isWordMode) {
                const context = this.app.targetSentences[index];
                const tokens = this.app.textProcessor.getSentenceTokens(context);
                if (tokens[this.activeWordTokenIndex] !== undefined) {
                    tokens[this.activeWordTokenIndex] = `[[TARGET]]${tokens[this.activeWordTokenIndex]}[[TARGET]]`;
                }
                const taggedContext = tokens.join('');
                const rawResult = await this.app.languageService.improve({
                    text: source,
                    type: 'synonyms',
                    context: taggedContext,
                    target_lang: this.app.getCurrentTargetLang(),
                    exclusions: existing
                });
                const textData = rawResult.data.text;
                if (Array.isArray(textData)) {
                    result = textData;
                } else if (typeof textData === 'string') {
                    try {
                        result = JSON.parse(textData);
                    } catch (e) {
                        console.warn('Synonym response was not valid JSON, using as single recommendation:', textData);
                        result = [textData.replace(/[\[\]"]/g, '').trim()];
                    }
                } else {
                    result = textData;
                }

            } else {
                const rawResult = await this.app.languageService.improve({
                    text: source,
                    type: 'alternatives',
                    target_lang: this.app.getCurrentTargetLang(),
                    exclusions: existing
                });
                result = rawResult.data.text;
            }

            if (result) {
                const addList = Array.isArray(result) ? result : [result];
                if (isWordMode) {
                    if (!this.lastImprovedWords[cacheKey]) this.lastImprovedWords[cacheKey] = [];
                    this.lastImprovedWords[cacheKey].push(...addList);
                } else {
                    if (!this.sentenceAlternativesCache[index]) this.sentenceAlternativesCache[index] = [];
                    this.sentenceAlternativesCache[index].push(...addList);
                }
            }
        } finally {
            this.renderSuggestions(index, true);
        }
    }

    hideWriteContextMenu() {
        if (this.activeAbortController) this.activeAbortController.abort();
        if (this.activeContextSentence) this.activeContextSentence.classList.remove('active-context');
        if (this.activeContextWord) this.activeContextWord.classList.remove('active-context');
        this.activeContextSentence = null;
        this.activeContextWord = null;
        
        const { elements } = this;
        if (elements.writeContextMenu) elements.writeContextMenu.style.display = 'none';
        if (elements.suggestionsDropdown) {
            elements.suggestionsDropdown.style.display = 'none';
            elements.suggestionsDropdown.classList.remove('visible');
        }
        
        if (this.app && typeof this.app.clearSourceHighlight === 'function') {
            this.app.clearSourceHighlight();
        }
    }

    clearCache() {
        this.sentenceAlternativesCache = {};
        this.lastImprovedWords = {};
    }
}
