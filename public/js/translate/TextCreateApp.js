/**
 * TextCreateApp - Create Mode Manager for HAWKI Translation.
 * Handles Tiptap rich-text editor, Markdown editor, context menus, selection toolbars,
 * and AI-assisted text composition/improvement in Create Mode.
 */
import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { Markdown } from '@tiptap/markdown';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';
import { CodeBlockLowlight } from '@tiptap/extension-code-block-lowlight';
import { all, createLowlight } from 'lowlight';

const lowlight = createLowlight(all);

export class TextCreateApp {
    constructor(app) {
        this.app = app;
        this.t = app.t;

        // Create Mode Domain State
        this.createTargetSentences = [];
        this.lastCreateResult = '';
        this.lastCreateTargetLang = 'de';

        // Editor & Toolbar State
        this.createMde = null;
        this.savedTiptapSelection = null;
        this.savedDOMRange = null;
        this.selectionOverlays = null;
        this.savedSelectedText = null;
        this.savedMarkdownSelection = null;

        // Context Menu History State
        this.alternativeHistory = null;
        this.alternativePrompts = null;
        this.alternativeTitles = null;
        this.lastActiveHistoryIndex = null;
        this.isComposeFlow = false;
        this.composeStartTiptap = null;
        this.composeStartMarkdown = null;
        this.composeOriginalEndPos = null;
        this.resultBarTitle = '';
        this.lastStyleParam = '';
        this.isWebAgentEnabled = true;

        this.scrollTicking = false;
        this.selectionToolbar = null;
        this.selectionTriggerBtn = null;
        this.lastMousePos = { x: 0, y: 0 };
        this.injectStyles();
    }

    injectStyles() {
        if (document.getElementById('mermaid-editor-styles')) return;
        const style = document.createElement('style');
        style.id = 'mermaid-editor-styles';
        style.textContent = `
            .code-block-wrapper {
                position: relative;
                margin-bottom: 1.2em;
            }
            .code-block-wrapper.diagram-active {
                background: transparent !important;
            }
            .mermaid-preview {
                background: var(--background-secondary, #f8fafc);
                border: 1px solid var(--border-stroke-thin, #e2e8f0);
                border-radius: 16px;
                padding: 2.5rem;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                min-height: 120px;
                overflow-x: auto;
                margin-bottom: 1.2em;
                box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.02);
                transition: all 0.3s ease;
            }
            .mermaid-preview.hidden {
                display: none !important;
            }
            .mermaid-preview svg {
                max-width: 100%;
                height: auto;
                font-family: var(--font-sans, "Inter", sans-serif) !important;
            }
            .mermaid-preview svg text,
            .mermaid-preview svg text * {
                stroke: none !important;
                stroke-width: 0px !important;
            }
            [data-theme='dark'] .mermaid-preview,
            .dark .mermaid-preview {
                background: #0f172a;
                border-color: #1e293b;
                box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.2);
            }
            
            /* Modern gitGraph Line and Commit Color Palette Override */
            .mermaid-preview svg path.arrow {
                stroke-width: 4px !important;
                stroke-linecap: round !important;
                stroke-linejoin: round !important;
            }
            .mermaid-preview svg path.arrow0 { stroke: #2563eb !important; } /* main - Royal Blue */
            .mermaid-preview svg path.arrow1 { stroke: #10b981 !important; } /* develop - Emerald Green */
            .mermaid-preview svg path.arrow2 { stroke: #f59e0b !important; } /* feature - Amber Orange */
            .mermaid-preview svg path.arrow3 { stroke: #8b5cf6 !important; } /* release - Purple */
            .mermaid-preview svg path.arrow4 { stroke: #ef4444 !important; } /* hotfix - Red */
            .mermaid-preview svg path.arrow5 { stroke: #ec4899 !important; }
            .mermaid-preview svg path.arrow6 { stroke: #06b6d4 !important; }
            .mermaid-preview svg path.arrow7 { stroke: #64748b !important; }

            /* Gridlines/Horizontal branch track lines */
            .mermaid-preview svg line.branch {
                stroke: var(--border-stroke-thin, #e2e8f0) !important;
                stroke-width: 1.5px !important;
                stroke-dasharray: 4px 4px !important;
                opacity: 0.8 !important;
            }
            [data-theme='dark'] .mermaid-preview svg line.branch,
            .dark .mermaid-preview svg line.branch {
                stroke: #334155 !important;
            }

            /* Commit Circles */
            .mermaid-preview svg circle.commit {
                r: 6px !important;
                stroke-width: 0px !important;
            }
            .mermaid-preview svg circle.commit0 { fill: #2563eb !important; }
            .mermaid-preview svg circle.commit1 { fill: #10b981 !important; }
            .mermaid-preview svg circle.commit2 { fill: #f59e0b !important; }
            .mermaid-preview svg circle.commit3 { fill: #8b5cf6 !important; }
            .mermaid-preview svg circle.commit4 { fill: #ef4444 !important; }
            .mermaid-preview svg circle.commit5 { fill: #ec4899 !important; }
            .mermaid-preview svg circle.commit6 { fill: #06b6d4 !important; }
            .mermaid-preview svg circle.commit7 { fill: #64748b !important; }

            /* Branch Labels on Left (Clean & Borderless) */
            .mermaid-preview svg rect.branchLabelBkg {
                display: none !important;
            }
            .mermaid-preview svg .branchLabel text {
                fill: var(--text-color, #1e293b) !important;
                font-family: var(--font-sans, "Inter", sans-serif) !important;
                font-size: 13px !important;
                font-weight: 600 !important;
                letter-spacing: -0.02em !important;
                transform: translateX(-12px) !important; /* Premium gutter spacing */
            }
            [data-theme='dark'] .mermaid-preview svg .branchLabel text,
            .dark .mermaid-preview svg .branchLabel text {
                fill: #f1f5f9 !important;
            }

            /* Commit Text Labels */
            .mermaid-preview svg rect.commit-label-bkg {
                display: none !important;
            }
            .mermaid-preview svg text.commit-label {
                fill: var(--text-faded-color, #64748b) !important;
                font-family: var(--font-sans, "Inter", sans-serif) !important;
                font-size: 10px !important;
                font-weight: 500 !important;
                letter-spacing: -0.01em !important;
            }
            [data-theme='dark'] .mermaid-preview svg text.commit-label,
            .dark .mermaid-preview svg text.commit-label {
                fill: #94a3b8 !important;
            }

            /* Tag Labels (Beautiful Pills) */
            .mermaid-preview svg polygon.tag-label-bkg {
                fill: #eff6ff !important;
                stroke: #dbeafe !important;
                stroke-width: 1px !important;
            }
            [data-theme='dark'] .mermaid-preview svg polygon.tag-label-bkg,
            .dark .mermaid-preview svg polygon.tag-label-bkg {
                fill: rgba(37, 99, 235, 0.15) !important;
                stroke: rgba(37, 99, 235, 0.3) !important;
            }
            .mermaid-preview svg text.tag-label {
                fill: #2563eb !important;
                font-family: var(--font-sans, "Inter", sans-serif) !important;
                font-size: 9px !important;
                font-weight: 600 !important;
            }
            [data-theme='dark'] .mermaid-preview svg text.tag-label,
            .dark .mermaid-preview svg text.tag-label {
                fill: #60a5fa !important;
            }

            /* Dynamic Legend at the bottom */
            .mermaid-legend {
                display: flex;
                justify-content: center;
                align-items: center;
                gap: 20px;
                margin-top: 2rem;
                padding: 10px 24px;
                background: var(--background-main, #ffffff);
                border: 1px solid var(--border-stroke-thin, #e2e8f0);
                border-radius: 30px;
                font-family: var(--font-sans, "Inter", sans-serif);
                font-size: 0.75rem;
                font-weight: 500;
                color: var(--text-color, #334155);
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
                flex-wrap: wrap;
            }
            [data-theme='dark'] .mermaid-legend,
            .dark .mermaid-legend {
                background: #1e293b;
                border-color: #334155;
                color: #f1f5f9;
            }
            .legend-item {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .legend-dot {
                width: 10px;
                height: 10px;
                border-radius: 50%;
                display: inline-block;
            }
            .legend-dot.dot-main { background-color: #2563eb; }
            .legend-dot.dot-develop { background-color: #10b981; }
            .legend-dot.dot-feature { background-color: #f59e0b; }
            .legend-dot.dot-release { background-color: #8b5cf6; }
            .legend-dot.dot-hotfix { background-color: #ef4444; }
            
            /* Code actions container */
            .code-block-wrapper .code-actions {
                position: absolute;
                top: 8px;
                right: 8px;
                display: flex;
                gap: 6px;
                z-index: 10;
            }
            
            /* Default button styles (over dark code block background) */
            .code-block-wrapper .editor-toggle-btn,
            .code-block-wrapper .editor-copy-btn {
                background: rgba(255, 255, 255, 0.08) !important;
                border: 1px solid rgba(255, 255, 255, 0.15) !important;
                color: rgba(255, 255, 255, 0.85) !important;
                padding: 5px 10px !important;
                border-radius: 6px !important;
                cursor: pointer !important;
                font-size: 0.72rem !important;
                font-weight: 500 !important;
                display: inline-flex !important;
                align-items: center !important;
                gap: 5px !important;
                transition: all 0.2s ease !important;
                height: 28px !important;
                box-sizing: border-box !important;
                text-decoration: none !important;
            }
            .code-block-wrapper .editor-toggle-btn svg,
            .code-block-wrapper .editor-copy-btn svg {
                stroke: rgba(255, 255, 255, 0.85) !important;
                width: 13px !important;
                height: 13px !important;
                fill: none !important;
            }
            .code-block-wrapper .editor-toggle-btn:hover,
            .code-block-wrapper .editor-copy-btn:hover {
                background: rgba(255, 255, 255, 0.18) !important;
                color: #ffffff !important;
                border-color: rgba(255, 255, 255, 0.3) !important;
            }
            .code-block-wrapper .editor-toggle-btn:hover svg,
            .code-block-wrapper .editor-copy-btn:hover svg {
                stroke: #ffffff !important;
            }
            
            /* Adaptive button styles in diagram mode (integrates automatically with HAWKI light/dark variables) */
            .code-block-wrapper.diagram-active .editor-toggle-btn,
            .code-block-wrapper.diagram-active .editor-copy-btn {
                background: var(--background-main, #ffffff) !important;
                border: 1px solid var(--border-stroke-thin, #e2e8f0) !important;
                color: var(--text-color, #334155) !important;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05) !important;
            }
            .code-block-wrapper.diagram-active .editor-toggle-btn svg,
            .code-block-wrapper.diagram-active .editor-copy-btn svg {
                stroke: var(--text-color, #334155) !important;
            }
            .code-block-wrapper.diagram-active .editor-toggle-btn:hover,
            .code-block-wrapper.diagram-active .editor-copy-btn:hover {
                background: var(--background-secondary, #f8fafc) !important;
                border-color: var(--accent-color, #cbd5e1) !important;
                color: var(--accent-color, #0f172a) !important;
            }
            .code-block-wrapper.diagram-active .editor-toggle-btn:hover svg,
            .code-block-wrapper.diagram-active .editor-copy-btn:hover svg {
                stroke: var(--accent-color, #0f172a) !important;
            }

            .tiptap-container .ProseMirror pre::before {
                display: none !important;
                content: none !important;
            }
            .editor-code-header {
                display: flex !important;
                align-items: center !important;
                background: transparent !important;
                padding: 0 0 0.8rem 0 !important;
                border-bottom: 1px solid rgba(255, 255, 255, 0.15) !important;
                margin-bottom: 0.8rem !important;
                user-select: none !important;
                min-height: 20px !important;
            }
            .editor-lang-name {
                color: rgba(255, 255, 255, 0.5) !important;
                font-family: system-ui, -apple-system, sans-serif !important;
                font-size: 0.95rem !important;
                font-weight: 700 !important;
                cursor: pointer !important;
                text-transform: lowercase !important;
                transition: color 0.2s ease !important;
                display: inline-flex !important;
                align-items: center !important;
                height: 20px !important;
                line-height: 20px !important;
                padding: 0 !important;
                margin: 0 !important;
                border: none !important;
                outline: none !important;
                user-select: none !important;
            }
            .editor-lang-name:hover,
            .editor-lang-name:focus {
                color: rgba(255, 255, 255, 0.9) !important;
                outline: none !important;
                background: transparent !important;
            }
            .editor-lang-name[contenteditable="true"] {
                user-select: text !important;
                cursor: text !important;
            }
            .editor-lang-name[contenteditable="true"]:empty::before {
                content: "language..." !important;
                color: rgba(255, 255, 255, 0.3) !important;
            }

            .mermaid-error-msg {
                color: #dc3545;
                font-size: 0.85rem;
                font-family: system-ui, sans-serif;
                text-align: center;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
            }
            .tiptap-container .ProseMirror pre.hidden {
                display: none !important;
            }
            .tiptap-container .ProseMirror pre.visual-hide {
                height: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                overflow: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
                border: none !important;
            }
            /* Prevent native browser selection highlights inside visually hidden pre blocks */
            .tiptap-container .ProseMirror pre.visual-hide *::selection,
            .tiptap-container .ProseMirror pre.visual-hide::selection {
                background: transparent !important;
                color: inherit !important;
            }
        `;
        document.head.appendChild(style);
    }

    loadMermaid() {
        if (window.mermaid) {
            return Promise.resolve(window.mermaid);
        }
        if (this.mermaidPromise) {
            return this.mermaidPromise;
        }
        this.mermaidPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js';
            script.onload = () => {
                window.mermaid.initialize({
                    startOnLoad: false,
                    theme: document.body.classList.contains('dark') ? 'dark' : 'default',
                    securityLevel: 'loose'
                });
                resolve(window.mermaid);
            };
            script.onerror = (e) => reject(e);
            document.head.appendChild(script);
        });
        return this.mermaidPromise;
    }

    preprocessMarkdown(markdown) {
        if (typeof markdown !== 'string') return markdown;
        
        // Strip duplicate/invalid marks for inline code (e.g. **`code`** or `**code**`) to prevent ProseMirror schema validation errors
        markdown = markdown.replace(/\*\*`([^`]+)`\*\*/g, '`$1`');
        markdown = markdown.replace(/\*`([^`]+)`\*/g, '`$1`');
        markdown = markdown.replace(/_`([^`]+)`_/g, '`$1`');
        markdown = markdown.replace(/__`([^`]+)`__/g, '`$1`');
        markdown = markdown.replace(/`\*\*([^`]+)\*\*`/g, '`$1`');
        markdown = markdown.replace(/`\*([^`]+)\*`/g, '`$1`');
        markdown = markdown.replace(/`_([^`]+)_`/g, '`$1`');
        markdown = markdown.replace(/`__([^`]+)__`/g, '`$1`');
        
        // 1. Temporarily extract all code blocks (fenced and inline) to avoid altering code containing pipes or double-pipes (e.g. ||).
        const codeBlocks = [];
        const codeBlockRegex = /(```[\s\S]*?```|`[^`\n]+`)/g;
        
        let processed = markdown.replace(codeBlockRegex, (match) => {
            const placeholder = `__CODE_BLOCK_PLACEHOLDER_${codeBlocks.length}__`;
            codeBlocks.push(match);
            return placeholder;
        });

        // 1.5 Normalize list item indentation to prevent excessive indents from being parsed as code blocks.
        const lines = processed.split('\n');
        const listStack = [];
        const normalizedListStack = [];
        const normalizedLines = [];
        
        for (let i = 0; i < lines.length; i++) {
            let line = lines[i];
            const normalizedTabsLine = line.replace(/\t/g, '    ');
            const listMatch = normalizedTabsLine.match(/^(\s*)([-*+]\s|\d+\.\s)(.*)$/);
            
            if (listMatch) {
                const originalIndent = listMatch[1].length;
                const marker = listMatch[2];
                const content = listMatch[3];
                
                let normalizedIndent = 0;
                
                if (listStack.length === 0) {
                    normalizedIndent = 0;
                    listStack.push(originalIndent);
                    normalizedListStack.push(0);
                } else {
                    const prevOriginal = listStack[listStack.length - 1];
                    if (originalIndent > prevOriginal) {
                        normalizedIndent = normalizedListStack[normalizedListStack.length - 1] + 4;
                        listStack.push(originalIndent);
                        normalizedListStack.push(normalizedIndent);
                    } else if (originalIndent === prevOriginal) {
                        normalizedIndent = normalizedListStack[normalizedListStack.length - 1];
                    } else {
                        while (listStack.length > 0 && listStack[listStack.length - 1] > originalIndent) {
                            listStack.pop();
                            normalizedListStack.pop();
                        }
                        if (listStack.length === 0) {
                            normalizedIndent = 0;
                            listStack.push(originalIndent);
                            normalizedListStack.push(0);
                        } else {
                            normalizedIndent = normalizedListStack[normalizedListStack.length - 1];
                        }
                    }
                }
                
                normalizedLines.push(' '.repeat(normalizedIndent) + marker + content);
            } else {
                const trimLine = line.trim();
                if (trimLine === '') {
                    normalizedLines.push('');
                } else {
                    const leadingSpaces = line.replace(/\t/g, '    ').match(/^(\s*)/)[1].length;
                    if (leadingSpaces === 0) {
                        listStack.length = 0;
                        normalizedListStack.length = 0;
                        normalizedLines.push(line);
                    } else if (listStack.length > 0) {
                        const originalIndent = leadingSpaces;
                        const prevOriginal = listStack[listStack.length - 1];
                        const prevNormalized = normalizedListStack[normalizedListStack.length - 1];
                        const delta = prevNormalized - prevOriginal;
                        const newIndent = Math.max(0, originalIndent + delta);
                        normalizedLines.push(' '.repeat(newIndent) + line.trimStart());
                    } else {
                        normalizedLines.push(line);
                    }
                }
            }
        }
        processed = normalizedLines.join('\n');

        // 2. Locate separator rows to identify flattened tables.
        const separatorRegex = /\|(?:\s*:?-+:?\s*\|)+/g;
        
        let match;
        const separators = [];
        while ((match = separatorRegex.exec(processed)) !== null) {
            separators.push({
                index: match.index,
                text: match[0],
                length: match[0].length
            });
        }

        // Process from right to left so indices remain valid
        for (let i = separators.length - 1; i >= 0; i--) {
            const sep = separators[i];
            const pipeCount = (sep.text.match(/\|/g) || []).length;
            const beforeText = processed.substring(0, sep.index);
            
            let pipesFound = 0;
            let headerStartIndex = -1;
            
            for (let j = beforeText.length - 1; j >= 0; j--) {
                if (beforeText[j] === '\n') {
                    break;
                }
                if (beforeText[j] === '|') {
                    pipesFound++;
                    if (pipesFound === pipeCount) {
                        headerStartIndex = j;
                        break;
                    }
                }
            }
            
            if (headerStartIndex !== -1) {
                const prefix = processed.substring(0, headerStartIndex);
                const headerAndRest = processed.substring(headerStartIndex);
                
                if (prefix.length > 0 && !prefix.endsWith('\n')) {
                    processed = prefix + '\n' + headerAndRest;
                }
            }
        }

        // 3. Split glued table rows
        processed = processed.replace(/\|(\s*)\|/g, (match, spaces) => {
            if (spaces.includes('\n')) {
                return match;
            }
            return '|\n|';
        });

        // 4. Handle table endings / horizontal rules glued to the end of a table
        processed = processed.replace(/\|(\s*)---(\s*)$/gm, '|\n---');

        // 5. Restore code blocks
        for (let i = 0; i < codeBlocks.length; i++) {
            processed = processed.replace(`__CODE_BLOCK_PLACEHOLDER_${i}__`, codeBlocks[i]);
        }

        return processed;
    }

    init() {
        const { elements } = this.app.uiManager;

        if (elements.createEditTabBtn) {
            elements.createEditTabBtn.addEventListener('click', () => {
                elements.createEditTabBtn.classList.add('active');
                if (elements.createExportTabBtn) elements.createExportTabBtn.classList.remove('active');
                if (elements.createEditView) elements.createEditView.classList.remove('hidden');
                if (elements.createExportView) elements.createExportView.classList.add('hidden');
            });
        }

        if (elements.createExportTabBtn) {
            elements.createExportTabBtn.addEventListener('click', () => {
                elements.createExportTabBtn.classList.add('active');
                if (elements.createEditTabBtn) elements.createEditTabBtn.classList.remove('active');
                if (elements.createEditView) elements.createEditView.classList.add('hidden');
                if (elements.createExportView) elements.createExportView.classList.remove('hidden');
            });
        }
        
        if (elements.createUndoBtn) {
            elements.createUndoBtn.addEventListener('click', () => {
                if (this.createMde) this.createMde.commands.undo();
            });
        }
        
        if (elements.createRedoBtn) {
            elements.createRedoBtn.addEventListener('click', () => {
                if (this.createMde) this.createMde.commands.redo();
            });
        }

        if (elements.createText) {
            this.initTiptapEditor(elements.createText);
        }

        if (elements.exportTxtBtn) {
            elements.exportTxtBtn.addEventListener('click', () => this.exportAsTxt());
        }

        if (elements.exportMdBtn) {
            elements.exportMdBtn.addEventListener('click', () => this.exportAsMd());
        }

        if (elements.exportPdfBtn) {
            elements.exportPdfBtn.addEventListener('click', () => this.exportAsPdf());
        }

        if (elements.exportDocBtn) {
            elements.exportDocBtn.addEventListener('click', () => this.exportAsDocx());
        }

        this.updatePlaceholderVisibility();
    }

    exportAsTxt() {
        if (!this.createMde) return;
        const text = this.createMde.getText().trim();
        if (!text) {
            this.app.uiManager.showError(this.app.uiManager.t.Err_EmptyInput || "Bitte geben Sie zuerst einen Text ein.");
            return;
        }

        const filename = this.getExportFilename('txt');
        this.downloadFile(text, filename, 'text/plain;charset=utf-8');
    }

    exportAsMd() {
        if (!this.createMde) return;
        const markdown = this.createMde.getMarkdown().trim();
        if (!markdown) {
            this.app.uiManager.showError(this.app.uiManager.t.Err_EmptyInput || "Bitte geben Sie zuerst einen Text ein.");
            return;
        }

        const filename = this.getExportFilename('md');
        this.downloadFile(markdown, filename, 'text/markdown;charset=utf-8');
    }

    exportAsDocx() {
        if (!this.createMde) return;
        const json = this.createMde.getJSON();
        const content = json.content || [];
        const hasText = content.some(block => {
            if (block.content) {
                return block.content.some(c => c.text && c.text.trim().length > 0);
            }
            return false;
        });
        
        if (!hasText) {
            this.app.uiManager.showError(this.app.uiManager.t.Err_EmptyInput || "Bitte geben Sie zuerst einen Text ein.");
            return;
        }

        const btn = this.app.uiManager.elements.exportDocBtn;
        let originalText = "";
        const subtitleEl = btn ? btn.querySelector('.export-card-subtitle') : null;
        if (btn && subtitleEl) {
            originalText = subtitleEl.textContent;
            subtitleEl.textContent = "Generiere...";
            btn.disabled = true;
            btn.style.opacity = "0.7";
        }

        setTimeout(() => {
            try {
                this.generateDocx();
            } catch (err) {
                console.error("DOCX generation failed:", err);
                this.app.uiManager.showError("DOCX-Export fehlgeschlagen.");
            } finally {
                if (btn && subtitleEl) {
                    subtitleEl.textContent = originalText;
                    btn.disabled = false;
                    btn.style.opacity = "";
                }
            }
        }, 100);
    }

    generateDocx() {
        const json = this.createMde.getJSON();
        const content = json.content || [];
        
        const date = new Date();
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const formattedDate = `${day}.${month}.${year}`;
        
        const docxChildren = [];

        // Add a beautiful document header
        docxChildren.push(
            new window.docx.Paragraph({
                children: [
                    new window.docx.TextRun({
                        text: `HAWKI KI-Editor Export  |  Datum: ${formattedDate}`,
                        font: "Calibri",
                        size: 16,
                        color: "94A3B8"
                    })
                ],
                spacing: { after: 400 }
            })
        );

        // Helper to convert marks to properties
        const getRunProperties = (run) => {
            const props = { text: run.text, size: 22 }; // 11pt
            if (run.bold) props.bold = true;
            if (run.italic) props.italics = true;
            if (run.code) {
                props.font = "Courier New";
                props.color = "C7254E"; // pink-red for code
                props.shading = { fill: "F4F4F5" };
            } else {
                props.font = "Calibri";
                props.color = "1E293B"; // slate-800
            }
            return props;
        };

        const convertParagraphContent = (paraNode, defaultItalic = false) => {
            const runs = paraNode.content || [];
            const resultRuns = [];
            
            runs.forEach(child => {
                if (child.type === 'text') {
                    const marks = child.marks || [];
                    const isBold = marks.some(m => m.type === 'bold');
                    const isItalic = marks.some(m => m.type === 'italic') || defaultItalic;
                    const isCode = marks.some(m => m.type === 'code');
                    
                    resultRuns.push(
                        new window.docx.TextRun(
                            getRunProperties({
                                text: child.text,
                                bold: isBold,
                                italic: isItalic,
                                code: isCode
                            })
                        )
                    );
                }
            });
            return resultRuns;
        };

        content.forEach((block) => {
            if (block.type === 'heading') {
                const level = block.attrs.level || 1;
                const text = block.content ? block.content.map(c => c.text).join('') : '';
                const sizes = { 1: 40, 2: 32, 3: 26 }; // in half-points (20pt, 16pt, 13pt)
                
                docxChildren.push(
                    new window.docx.Paragraph({
                        children: [
                            new window.docx.TextRun({
                                text: text,
                                font: "Calibri",
                                bold: true,
                                size: sizes[level] || 24,
                                color: "0F172A" // slate-900
                            })
                        ],
                        spacing: { before: level === 1 ? 360 : 240, after: 120 }
                    })
                );
            }
            else if (block.type === 'paragraph') {
                const childRuns = convertParagraphContent(block);
                if (childRuns.length === 0) {
                    // Empty paragraph
                    docxChildren.push(new window.docx.Paragraph({ spacing: { after: 120 } }));
                    return;
                }
                docxChildren.push(
                    new window.docx.Paragraph({
                        children: childRuns,
                        spacing: { after: 120 }
                    })
                );
            }
            else if (block.type === 'blockquote') {
                const quoteParagraphs = block.content || [];
                quoteParagraphs.forEach(childBlock => {
                    if (childBlock.type === 'paragraph') {
                        const childRuns = convertParagraphContent(childBlock, true);
                        docxChildren.push(
                            new window.docx.Paragraph({
                                children: childRuns,
                                indent: { left: 540 },
                                spacing: { before: 80, after: 80 }
                            })
                        );
                    }
                });
            }
            else if (block.type === 'codeBlock') {
                const text = block.content ? block.content.map(c => c.text).join('') : '';
                const lines = text.split('\n');
                
                lines.forEach(line => {
                    docxChildren.push(
                        new window.docx.Paragraph({
                            children: [
                                new window.docx.TextRun({
                                    text: line,
                                    font: "Courier New",
                                    size: 18,
                                    color: "334155" // slate-700
                                })
                            ],
                            indent: { left: 360 },
                            spacing: { before: 40, after: 40 }
                        })
                    );
                });
            }
            else if (block.type === 'bulletList' || block.type === 'orderedList') {
                const listItems = block.content || [];
                listItems.forEach((item, itemIdx) => {
                    const para = item.content ? item.content.find(c => c.type === 'paragraph') : null;
                    if (!para) return;
                    
                    const childRuns = convertParagraphContent(para);
                    const prefix = block.type === 'bulletList' ? '•   ' : `${itemIdx + 1}.  `;
                    
                    childRuns.unshift(
                        new window.docx.TextRun({
                            text: prefix,
                            font: "Calibri",
                            bold: true,
                            color: "6366F1" // indigo color
                        })
                    );
                    
                    docxChildren.push(
                        new window.docx.Paragraph({
                            children: childRuns,
                            spacing: { after: 80 }
                        })
                    );
                });
            }
            else if (block.type === 'table') {
                const rows = block.content || [];
                if (rows.length === 0) return;
                
                const tableRows = [];
                rows.forEach((row, rowIdx) => {
                    const cells = row.content || [];
                    const tableCells = [];
                    
                    cells.forEach(cell => {
                        const cellParas = [];
                        const contentBlocks = cell.content || [];
                        
                        contentBlocks.forEach(cb => {
                            if (cb.type === 'paragraph') {
                                const runs = convertParagraphContent(cb);
                                cellParas.push(
                                    new window.docx.Paragraph({
                                        children: runs,
                                        spacing: { after: 60 }
                                    })
                                );
                            }
                        });
                        
                        if (cellParas.length === 0) {
                            cellParas.push(new window.docx.Paragraph({}));
                        }
                        
                        tableCells.push(
                            new window.docx.TableCell({
                                children: cellParas,
                                shading: rowIdx === 0 ? { fill: "F1F5F9" } : undefined,
                                margins: { top: 120, bottom: 120, left: 120, right: 120 }
                            })
                        );
                    });
                    
                    tableRows.push(
                        new window.docx.TableRow({
                            children: tableCells
                        })
                    );
                });
                
                docxChildren.push(
                    new window.docx.Table({
                        rows: tableRows,
                        width: { size: 100, type: window.docx.WidthType.PERCENTAGE }
                    })
                );
            }
            else if (block.type === 'horizontalRule') {
                docxChildren.push(
                    new window.docx.Paragraph({
                        border: {
                            bottom: { style: window.docx.BorderStyle.SINGLE, size: 6, color: "E2E8F0" }
                        },
                        spacing: { before: 120, after: 120 }
                    })
                );
            }
        });

        const doc = new window.docx.Document({
            styles: {
                default: {
                    document: {
                        run: {
                            font: "Calibri"
                        }
                    }
                }
            },
            sections: [
                {
                    headers: {
                        default: new window.docx.Header({
                            children: [
                                new window.docx.Paragraph({
                                    children: [
                                        new window.docx.TextRun({
                                            text: "HAWKI KI-Editor Dokumenten-Export",
                                            font: "Calibri",
                                            size: 18,
                                            color: "CBD5E1"
                                        })
                                    ]
                                })
                            ],
                        }),
                    },
                    properties: {
                        type: window.docx.SectionType.CONTINUOUS,
                    },
                    children: docxChildren,
                },
            ],
        });

        window.docx.Packer.toBlob(doc).then((blob) => {
            const url = URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = url;
            const filename = this.getExportFilename('docx');
            link.download = filename;
            link.click();
            URL.revokeObjectURL(url);
        });
    }

    exportAsPdf() {
        if (!this.createMde) return;
        const json = this.createMde.getJSON();
        const content = json.content || [];
        const hasText = content.some(block => {
            if (block.content) {
                return block.content.some(c => c.text && c.text.trim().length > 0);
            }
            return false;
        });
        
        if (!hasText) {
            this.app.uiManager.showError(this.app.uiManager.t.Err_EmptyInput || "Bitte geben Sie zuerst einen Text ein.");
            return;
        }

        const btn = this.app.uiManager.elements.exportPdfBtn;
        let originalText = "";
        const subtitleEl = btn ? btn.querySelector('.export-card-subtitle') : null;
        if (btn && subtitleEl) {
            originalText = subtitleEl.textContent;
            subtitleEl.textContent = "Generiere...";
            btn.disabled = true;
            btn.style.opacity = "0.7";
        }

        // Run in timeout to let UI render the "Generiere..." text
        setTimeout(() => {
            try {
                this.generatePdf();
            } catch (err) {
                console.error("PDF generation failed:", err);
                this.app.uiManager.showError("PDF-Export fehlgeschlagen.");
            } finally {
                if (btn && subtitleEl) {
                    subtitleEl.textContent = originalText;
                    btn.disabled = false;
                    btn.style.opacity = "";
                }
            }
        }, 100);
    }

    generatePdf() {
        const doc = new window.jsPDF();
        
        const margin = 20;
        const maxWidth = 210 - (margin * 2);
        const maxPageHeight = 270;
        let yOffset = 25;
        
        const json = this.createMde.getJSON();
        const content = json.content || [];
        
        const date = new Date();
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const formattedDate = `${day}.${month}.${year}`;

        // Helper to draw inline styled segments
        const drawLine = (line, x, y) => {
            let currentX = x;
            line.forEach(segment => {
                if (segment.code) {
                    doc.setFont('courier', 'normal');
                    doc.setTextColor(199, 37, 78); // pink-red for inline code
                    const width = doc.getTextWidth(segment.text);
                    doc.setFillColor(248, 250, 252);
                    doc.rect(currentX, y - 3.5, width, 5, 'F');
                } else {
                    doc.setFont('helvetica', segment.bold && segment.italic ? 'bolditalic' : (segment.bold ? 'bold' : (segment.italic ? 'italic' : 'normal')));
                    doc.setTextColor(30, 41, 59); // slate-800
                }
                
                doc.text(segment.text, currentX, y);
                currentX += doc.getTextWidth(segment.text);
            });
        };

        // Helper to wrap inline runs
        const wrapRuns = (runs, widthLimit) => {
            const lines = [];
            let currentLine = [];
            let currentLineWidth = 0;

            const getSegmentWidth = (text, style) => {
                doc.setFont('helvetica', style.bold && style.italic ? 'bolditalic' : (style.bold ? 'bold' : (style.italic ? 'italic' : 'normal')));
                if (style.code) doc.setFont('courier', 'normal');
                return doc.getTextWidth(text);
            };

            const words = [];
            runs.forEach(run => {
                const matches = run.text.match(/([^\s]+|\s+)/g) || [];
                matches.forEach(match => {
                    words.push({
                        text: match,
                        bold: run.bold,
                        italic: run.italic,
                        code: run.code,
                        strike: run.strike
                    });
                });
            });

            words.forEach(word => {
                const wordWidth = getSegmentWidth(word.text, word);
                if (currentLine.length === 0 && word.text.trim() === '') {
                    return;
                }

                if (currentLineWidth + wordWidth > widthLimit) {
                    if (wordWidth > widthLimit) {
                        currentLine.push(word);
                        lines.push(currentLine);
                        currentLine = [];
                        currentLineWidth = 0;
                    } else {
                        lines.push(currentLine);
                        if (word.text.trim() === '') {
                            currentLine = [];
                            currentLineWidth = 0;
                        } else {
                            currentLine = [word];
                            currentLineWidth = wordWidth;
                        }
                    }
                } else {
                    currentLine.push(word);
                    currentLineWidth += wordWidth;
                }
            });

            if (currentLine.length > 0) {
                lines.push(currentLine);
            }

            return lines;
        };

        // Render blocks
        content.forEach((block, index) => {
            if (block.type === 'heading') {
                const level = block.attrs.level || 1;
                const fontSizes = { 1: 20, 2: 16, 3: 13 };
                const size = fontSizes[level] || 12;
                
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(size);
                doc.setTextColor(15, 23, 42); // slate-900
                
                const text = block.content ? block.content.map(c => c.text).join('') : '';
                const lines = doc.splitTextToSize(text, maxWidth);
                
                if (index > 0) {
                    yOffset += (level === 1 ? 12 : 8);
                }
                
                const headingHeight = lines.length * (size * 0.4);
                if (yOffset + headingHeight > maxPageHeight) {
                    doc.addPage();
                    yOffset = 25;
                }
                
                lines.forEach(line => {
                    doc.text(line, margin, yOffset);
                    yOffset += (size * 0.4) + 2;
                });
                yOffset += 4;
            }
            else if (block.type === 'paragraph') {
                const runs = [];
                if (block.content) {
                    block.content.forEach(child => {
                        if (child.type === 'text') {
                            const marks = child.marks || [];
                            runs.push({
                                text: child.text,
                                bold: marks.some(m => m.type === 'bold'),
                                italic: marks.some(m => m.type === 'italic'),
                                code: marks.some(m => m.type === 'code'),
                                strike: marks.some(m => m.type === 'strike')
                            });
                        }
                    });
                }
                
                if (runs.length === 0) {
                    yOffset += 6;
                    return;
                }
                
                doc.setFontSize(10.5);
                const lines = wrapRuns(runs, maxWidth);
                
                if (index > 0) {
                    yOffset += 4;
                }
                
                lines.forEach(line => {
                    if (yOffset + 6 > maxPageHeight) {
                        doc.addPage();
                        yOffset = 25;
                    }
                    drawLine(line, margin, yOffset);
                    yOffset += 6;
                });
            }
            else if (block.type === 'blockquote') {
                const runs = [];
                if (block.content) {
                    block.content.forEach(childBlock => {
                        if (childBlock.type === 'paragraph' && childBlock.content) {
                            childBlock.content.forEach(child => {
                                if (child.type === 'text') {
                                    const marks = child.marks || [];
                                    runs.push({
                                        text: child.text,
                                        bold: marks.some(m => m.type === 'bold'),
                                        italic: true,
                                        code: marks.some(m => m.type === 'code'),
                                        strike: marks.some(m => m.type === 'strike')
                                    });
                                }
                            });
                        }
                    });
                }
                
                if (runs.length === 0) return;
                
                doc.setFontSize(10.5);
                const quoteMaxWidth = maxWidth - 10;
                const lines = wrapRuns(runs, quoteMaxWidth);
                
                if (index > 0) {
                    yOffset += 4;
                }
                
                const startY = yOffset;
                lines.forEach(line => {
                    if (yOffset + 6 > maxPageHeight) {
                        doc.setDrawColor(203, 213, 225);
                        doc.setLineWidth(1.2);
                        doc.line(margin + 2, startY - 2, margin + 2, yOffset - 4);
                        
                        doc.addPage();
                        yOffset = 25;
                    }
                    drawLine(line, margin + 8, yOffset);
                    yOffset += 6;
                });
                
                doc.setDrawColor(203, 213, 225);
                doc.setLineWidth(1.2);
                doc.line(margin + 2, startY - 2, margin + 2, yOffset - 4);
            }
            else if (block.type === 'codeBlock') {
                const text = block.content ? block.content.map(c => c.text).join('') : '';
                const rawLines = text.split('\n');
                
                doc.setFont('courier', 'normal');
                doc.setFontSize(9);
                
                const wrappedCodeLines = [];
                rawLines.forEach(line => {
                    const split = doc.splitTextToSize(line, maxWidth - 8);
                    split.forEach(s => wrappedCodeLines.push(s));
                });
                
                if (wrappedCodeLines.length === 0) return;
                
                if (index > 0) {
                    yOffset += 4;
                }
                
                let startY = yOffset;
                let pageLines = [];
                
                wrappedCodeLines.forEach(lineText => {
                    if (yOffset + 5 > maxPageHeight) {
                        if (pageLines.length > 0) {
                            doc.setFillColor(248, 250, 252);
                            doc.setDrawColor(226, 232, 240);
                            doc.rect(margin, startY - 3.5, maxWidth, (yOffset - startY) + 1.5, 'FD');
                            
                            doc.setFont('courier', 'normal');
                            doc.setFontSize(9);
                            doc.setTextColor(51, 65, 85);
                            let tempY = startY;
                            pageLines.forEach(pl => {
                                doc.text(pl, margin + 4, tempY);
                                tempY += 5;
                            });
                        }
                        
                        doc.addPage();
                        yOffset = 25;
                        startY = yOffset;
                        pageLines = [];
                    }
                    
                    pageLines.push(lineText);
                    yOffset += 5;
                });
                
                if (pageLines.length > 0) {
                    doc.setFillColor(248, 250, 252);
                    doc.setDrawColor(226, 232, 240);
                    doc.rect(margin, startY - 3.5, maxWidth, (yOffset - startY) + 1.5, 'FD');
                    
                    doc.setFont('courier', 'normal');
                    doc.setFontSize(9);
                    doc.setTextColor(51, 65, 85);
                    let tempY = startY;
                    pageLines.forEach(pl => {
                        doc.text(pl, margin + 4, tempY);
                        tempY += 5;
                    });
                }
                yOffset += 2;
            }
            else if (block.type === 'bulletList' || block.type === 'orderedList') {
                const listItems = block.content || [];
                yOffset += 2;
                
                listItems.forEach((item, itemIdx) => {
                    const para = item.content ? item.content.find(c => c.type === 'paragraph') : null;
                    if (!para) return;
                    
                    const runs = [];
                    if (para.content) {
                        para.content.forEach(child => {
                            if (child.type === 'text') {
                                const marks = child.marks || [];
                                runs.push({
                                    text: child.text,
                                    bold: marks.some(m => m.type === 'bold'),
                                    italic: marks.some(m => m.type === 'italic'),
                                    code: marks.some(m => m.type === 'code'),
                                    strike: marks.some(m => m.type === 'strike')
                                });
                            }
                        });
                    }
                    
                    doc.setFontSize(10.5);
                    const listMaxWidth = maxWidth - 8;
                    const lines = wrapRuns(runs, listMaxWidth);
                    
                    lines.forEach((line, lineIdx) => {
                        if (yOffset + 6 > maxPageHeight) {
                            doc.addPage();
                            yOffset = 25;
                        }
                        
                        if (lineIdx === 0) {
                            doc.setFont('helvetica', 'bold');
                            doc.setTextColor(99, 102, 241); // Indigo bullet
                            if (block.type === 'bulletList') {
                                doc.text('•', margin + 2, yOffset);
                            } else {
                                doc.text(`${itemIdx + 1}.`, margin + 2, yOffset);
                            }
                        }
                        
                        drawLine(line, margin + 8, yOffset);
                        yOffset += 6;
                    });
                    yOffset += 1.5;
                });
            }
            else if (block.type === 'table') {
                const rows = block.content || [];
                if (rows.length === 0) return;
                
                let colCount = 0;
                rows.forEach(row => {
                    colCount = Math.max(colCount, row.content ? row.content.length : 0);
                });
                if (colCount === 0) return;
                
                const colWidth = maxWidth / colCount;
                
                if (index > 0) {
                    yOffset += 4;
                }
                
                rows.forEach((row, rowIdx) => {
                    const cells = row.content || [];
                    const cellLines = [];
                    let maxLineCount = 1;
                    
                    cells.forEach(cell => {
                        const cellText = cell.content ? cell.content.map(c => {
                            return c.content ? c.content.map(tc => tc.text).join('') : '';
                        }).join('\n') : '';
                        
                        doc.setFont('helvetica', rowIdx === 0 ? 'bold' : 'normal');
                        doc.setFontSize(9.5);
                        const lines = doc.splitTextToSize(cellText, colWidth - 4);
                        cellLines.push(lines);
                        maxLineCount = Math.max(maxLineCount, lines.length);
                    });
                    
                    const rowHeight = maxLineCount * 5 + 4;
                    
                    if (yOffset + rowHeight > maxPageHeight) {
                        doc.addPage();
                        yOffset = 25;
                    }
                    
                    cells.forEach((cell, cellIdx) => {
                        const x = margin + cellIdx * colWidth;
                        const lines = cellLines[cellIdx];
                        const isHeader = rowIdx === 0 || cell.type === 'tableHeader';
                        
                        if (isHeader) {
                            doc.setFillColor(241, 245, 249);
                        } else {
                            doc.setFillColor(255, 255, 255);
                        }
                        doc.rect(x, yOffset - 3.5, colWidth, rowHeight, 'F');
                        
                        doc.setDrawColor(203, 213, 225);
                        doc.setLineWidth(0.2);
                        doc.rect(x, yOffset - 3.5, colWidth, rowHeight, 'S');
                        
                        doc.setTextColor(isHeader ? 15 : 51, isHeader ? 23 : 65, isHeader ? 42 : 85);
                        doc.setFont('helvetica', isHeader ? 'bold' : 'normal');
                        doc.setFontSize(9.5);
                        
                        lines.forEach((lineText, lineIdx) => {
                            doc.text(lineText, x + 2, yOffset + (lineIdx * 5) + 1);
                        });
                    });
                    
                    yOffset += rowHeight;
                });
                yOffset += 2;
            }
            else if (block.type === 'horizontalRule') {
                if (index > 0) {
                    yOffset += 4;
                }
                if (yOffset + 5 > maxPageHeight) {
                    doc.addPage();
                    yOffset = 25;
                }
                doc.setDrawColor(226, 232, 240);
                doc.setLineWidth(0.5);
                doc.line(margin, yOffset, margin + maxWidth, yOffset);
                yOffset += 6;
            }
        });

        // Second pass: Draw Header & Footer on all pages
        const pageCount = doc.internal.getNumberOfPages();
        for (let i = 1; i <= pageCount; i++) {
            doc.setPage(i);
            
            // Header
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8);
            doc.setTextColor(148, 163, 184); // slate-400
            doc.text('HAWKI KI-Editor Export', margin, 15);
            doc.text(formattedDate, 210 - margin - doc.getTextWidth(formattedDate), 15);
            
            doc.setDrawColor(226, 232, 240); // slate-200
            doc.setLineWidth(0.2);
            doc.line(margin, 17, 210 - margin, 17);

            // Footer
            doc.line(margin, 280, 210 - margin, 280);
            doc.text(`Seite ${i} von ${pageCount}`, 210 - margin - doc.getTextWidth(`Seite ${i} von ${pageCount}`), 285);
        }

        const filename = this.getExportFilename('pdf');
        doc.save(filename);
    }

    sanitizeFilename(str) {
        if (!str) return '';
        return str.toLowerCase()
            .replace(/ä/g, 'ae')
            .replace(/ö/g, 'oe')
            .replace(/ü/g, 'ue')
            .replace(/ß/g, 'ss')
            .replace(/[^a-z0-9\s-_]/g, '') // remove special chars
            .replace(/\s+/g, '-'); // replace spaces with hyphens
    }

    getExportFilename(extension) {
        let title = 'ki-editor-dokument';
        
        if (this.createMde) {
            const markdown = this.createMde.getMarkdown().trim();
            // Try to find the first header (e.g., # Header or ## Header)
            const headerMatch = markdown.match(/^(?:#+)\s*(.+)$/m);
            if (headerMatch && headerMatch[1]) {
                title = this.sanitizeFilename(headerMatch[1].trim());
            } else if (markdown) {
                // Fallback to the first line/sentence if no heading is found
                const firstLine = markdown.split('\n')[0].trim();
                title = this.sanitizeFilename(firstLine);
            }
        }
        
        // Limit length of title
        if (title.length > 50) {
            title = title.substring(0, 50);
        }
        
        const date = new Date();
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const formattedDate = `${year}-${month}-${day}`;
        
        return `${title || 'ki-editor-dokument'}_${formattedDate}.${extension}`;
    }

    downloadFile(content, filename, contentType) {
        const blob = new Blob([content], { type: contentType });
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    updatePlaceholderVisibility(editorInstance = null) {
        const tiptapPlaceholder = document.getElementById('createRichPlaceholder');
        if (tiptapPlaceholder) {
            const editor = editorInstance || this.createMde;
            const hasContent = editor && !editor.isEmpty;
            if (hasContent) {
                tiptapPlaceholder.style.opacity = '0';
                tiptapPlaceholder.style.visibility = 'hidden';
            } else {
                tiptapPlaceholder.style.opacity = '1';
                tiptapPlaceholder.style.visibility = 'visible';
            }
        }

        const mdPlaceholder = document.getElementById('createMarkdownPlaceholder');
        if (mdPlaceholder) {
            const markdownTextarea = document.getElementById('createTextMarkdown');
            const hasContent = markdownTextarea && markdownTextarea.value.length > 0;
            if (hasContent) {
                mdPlaceholder.style.opacity = '0';
                mdPlaceholder.style.visibility = 'hidden';
            } else {
                mdPlaceholder.style.opacity = '1';
                mdPlaceholder.style.visibility = 'visible';
            }
        }
    }

    initTiptapEditor(element) {
        const uiManager = this.app.uiManager;
        
        // Create a custom extension to handle the copy button inside the code block NodeView
        const self = this;
        const CustomCodeBlockLowlight = CodeBlockLowlight.extend({
            addNodeView() {
                return ({ node, HTMLAttributes, getPos, editor }) => {
                    const isMermaid = node.attrs.language === 'mermaid';
                    
                    const wrapper = document.createElement('div');
                    wrapper.className = 'code-block-wrapper';
                    if (node.attrs.language) {
                        wrapper.classList.add(`lang-${node.attrs.language}`);
                    }
                    if (isMermaid) {
                        wrapper.classList.add('diagram-active');
                    }

                    const dom = document.createElement('pre');
                    Object.entries(HTMLAttributes).forEach(([key, value]) => {
                        if (value !== undefined && value !== null) {
                            dom.setAttribute(key, value);
                        }
                    });
                    if (isMermaid) {
                        dom.classList.add('visual-hide');
                    }
                    
                    const contentDOM = document.createElement('code');
                    if (node.attrs.language) {
                        contentDOM.classList.add(`language-${node.attrs.language}`);
                    } else {
                        contentDOM.classList.add('language-code');
                    }
                    
                    dom.appendChild(contentDOM);
                    wrapper.appendChild(dom);

                    // Language Badge Selector
                    const codeHeader = document.createElement('div');
                    codeHeader.className = 'editor-code-header';
                    codeHeader.setAttribute('contenteditable', 'false');
                    
                    const langBadge = document.createElement('span');
                    langBadge.className = 'editor-lang-name';
                    langBadge.textContent = node.attrs.language || 'code';
                    codeHeader.appendChild(langBadge);
                    
                    dom.insertBefore(codeHeader, contentDOM);

                    langBadge.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // Make editable
                        langBadge.setAttribute('contenteditable', 'true');
                        langBadge.focus();
                        
                        // Select all text in span
                        const range = document.createRange();
                        range.selectNodeContents(langBadge);
                        const sel = window.getSelection();
                        sel.removeAllRanges();
                        sel.addRange(range);
                        
                        let isSaving = false;
                        const saveValue = () => {
                            if (isSaving) {
                                return;
                            }
                            isSaving = true;
                            
                            langBadge.setAttribute('contenteditable', 'false');
                            const newLang = langBadge.textContent.trim().toLowerCase();
                            
                            // Update tiptap editor attribute
                            const currentPos = getPos();
                            if (typeof currentPos === 'number') {
                                editor.commands.command(({ tr }) => {
                                    tr.setNodeMarkup(currentPos, undefined, {
                                        ...node.attrs,
                                        language: newLang || null,
                                    });
                                    return true;
                                });
                            }
                        };
                        
                        langBadge.addEventListener('keydown', (e) => {
                            e.stopPropagation();
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                saveValue();
                            } else if (e.key === 'Escape') {
                                e.preventDefault();
                                langBadge.setAttribute('contenteditable', 'false');
                                langBadge.textContent = node.attrs.language || 'code';
                                window.getSelection().removeAllRanges();
                            }
                        });
                        
                        langBadge.addEventListener('keyup', (e) => {
                            e.stopPropagation();
                        });
                        langBadge.addEventListener('keypress', (e) => {
                            e.stopPropagation();
                        });
                        
                        langBadge.addEventListener('blur', () => {
                            saveValue();
                        }, { once: true });
                    });

                    // Create preview container for mermaid
                    let previewContainer = null;
                    let isDiagramMode = isMermaid;
                    let toggleBtn = null;
                    let renderDiagram = null;

                    if (isMermaid) {
                        previewContainer = document.createElement('div');
                        previewContainer.className = 'mermaid-preview';
                        previewContainer.setAttribute('contenteditable', 'false');
                        wrapper.appendChild(previewContainer);
                        
                        // Start loading mermaid library immediately
                        self.loadMermaid().catch(err => console.error('Failed to load mermaid:', err));
                    }
                    
                    const btn = document.createElement('button');
                    btn.className = 'editor-copy-btn';
                    btn.setAttribute('contenteditable', 'false');
                    btn.setAttribute('type', 'button');
                    
                    const copyLabel = uiManager.t ? (uiManager.t['Copied'] || 'Kopiert') : 'Kopiert';
                    btn.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                        <div class="reaction">${copyLabel}</div>
                    `;
                    
                    btn.addEventListener('mousedown', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        btn.style.transform = 'scale(1.1)';
                    });
                    
                    btn.addEventListener('mouseup', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        btn.style.transform = 'scale(1.0)';
                        
                        const textToCopy = node.textContent;
                        navigator.clipboard.writeText(textToCopy);
                        
                        const reaction = btn.querySelector('.reaction');
                        if (reaction) {
                            reaction.style.display = 'block';
                            setTimeout(() => {
                                reaction.style.opacity = '1';
                            }, 50);
                            
                            setTimeout(() => {
                                reaction.style.opacity = '0';
                                setTimeout(() => {
                                    reaction.style.display = 'none';
                                }, 500);
                            }, 3000);
                        }
                    });
                    
                    const codeActions = document.createElement('div');
                    codeActions.className = 'code-actions';
                    codeActions.setAttribute('contenteditable', 'false');

                    // If it's a mermaid block, add toggle button
                    if (isMermaid) {
                        toggleBtn = document.createElement('button');
                        toggleBtn.className = 'editor-toggle-btn';
                        toggleBtn.setAttribute('contenteditable', 'false');
                        toggleBtn.setAttribute('type', 'button');
                        toggleBtn.innerHTML = `
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-code-2"><path d="m18 16 4-4-4-4"/><path d="m6 8-4 4 4 4"/><path d="m14.5 4-5 16"/></svg>
                            <span>Quellcode</span>
                        `;

                        renderDiagram = async (text) => {
                            console.log('[renderDiagram] Called with length:', text.length);
                            try {
                                const m = await self.loadMermaid();
                                const id = 'mermaid-' + Math.random().toString(36).substring(2, 9);
                                previewContainer.innerHTML = '<span style="color:var(--text-faded-color);font-size:0.85rem;">Generiere Diagramm...</span>';
                                
                                // Clean up the text for mermaid (strip HTML/entities)
                                let cleanedText = text.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#39;/g, "'").trim();
                                console.log('[renderDiagram] Cleaned text:', cleanedText);
                                
                                const { svg } = await m.render(id, cleanedText);
                                console.log('[renderDiagram] Success. SVG length:', svg.length);
                                previewContainer.innerHTML = svg;

                                // Dynamically append branch legend for Git Flow diagrams
                                if (cleanedText.includes('gitGraph')) {
                                    const legend = document.createElement('div');
                                    legend.className = 'mermaid-legend';
                                    legend.innerHTML = `
                                        <div class="legend-item"><span class="legend-dot dot-main"></span> main: stabile Releases</div>
                                        <div class="legend-item"><span class="legend-dot dot-develop"></span> develop: Integration</div>
                                        <div class="legend-item"><span class="legend-dot dot-feature"></span> feature: neue Funktion</div>
                                        <div class="legend-item"><span class="legend-dot dot-release"></span> release: Vorbereitung</div>
                                        <div class="legend-item"><span class="legend-dot dot-hotfix"></span> hotfix: schnelle Korrektur</div>
                                    `;
                                    previewContainer.appendChild(legend);
                                }
                            } catch (err) {
                                console.error('[Mermaid Render Error Exception]', err);
                                previewContainer.innerHTML = `
                                    <div class="mermaid-error-msg">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#dc3545" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                                        <span>Ungültige Mermaid-Syntax</span>
                                        <pre style="color: red; font-size: 10px; margin-top: 5px; white-space: pre-wrap;">${err.message || err}</pre>
                                    </div>
                                `;
                            }
                        };

                        toggleBtn.addEventListener('mousedown', (e) => {
                            console.log('[toggleBtn mousedown] Current isDiagramMode:', isDiagramMode);
                            e.preventDefault();
                            e.stopPropagation();
                            isDiagramMode = !isDiagramMode;
                            console.log('[toggleBtn mousedown] New isDiagramMode:', isDiagramMode);
                            
                            if (isDiagramMode) {
                                wrapper.classList.add('diagram-active');
                                dom.classList.add('visual-hide');
                                previewContainer.classList.remove('hidden');
                                toggleBtn.innerHTML = `
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-code-2"><path d="m18 16 4-4-4-4"/><path d="m6 8-4 4 4 4"/><path d="m14.5 4-5 16"/></svg>
                                    <span>Quellcode</span>
                                `;
                                renderDiagram(node.textContent);
                            } else {
                                wrapper.classList.remove('diagram-active');
                                dom.classList.remove('visual-hide');
                                previewContainer.classList.add('hidden');
                                toggleBtn.innerHTML = `
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-presentation-icon lucide-presentation"><path d="M2 3h20"/><path d="M21 3v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3"/><path d="m7 21 5-5 5 5"/></svg>
                                    <span>Diagramm</span>
                                `;
                            }
                        });
                        codeActions.appendChild(toggleBtn);
                        
                        // Immediately render the diagram on initialization
                        renderDiagram(node.textContent);
                    }

                    codeActions.appendChild(btn);
                    wrapper.appendChild(codeActions);
                    
                    return { 
                        dom: wrapper, 
                        contentDOM,
                        update(updatedNode) {
                            console.log('[NodeView Update]', { 
                                updatedNodeType: updatedNode.type.name, 
                                oldLang: node.attrs.language,
                                newLang: updatedNode.attrs.language,
                                isDiagramMode 
                            });
                            if (updatedNode.type !== node.type) {
                                return false;
                            }
                            if (updatedNode.attrs.language !== node.attrs.language) {
                                return false;
                            }
                            node = updatedNode;
                            if (isMermaid && isDiagramMode) {
                                renderDiagram(node.textContent);
                            }
                            return true;
                        },
                        ignoreMutation(mutation) {
                            return !contentDOM.contains(mutation.target);
                        },
                        destroy() {
                            console.log('[NodeView Destroyed]', { isDiagramMode }, new Error().stack);
                        }
                    };
                };
            }
        });

        // Initialize Tiptap Editor
        this.createMde = new Editor({
            element: element,
            extensions: [
                StarterKit.configure({
                    codeBlock: false,
                }),
                Table.configure({
                    resizable: true,
                }),
                TableRow,
                TableHeader,
                TableCell,
                Markdown.configure({
                    html: true,
                }),
                CustomCodeBlockLowlight.configure({
                    lowlight,
                }),
            ],
            content: '',
            editorProps: {
                handlePaste: (view, event) => {
                    const text = event.clipboardData.getData('text/plain');
                    if (text && (text.includes('|') || text.includes('---'))) {
                        const preprocessed = this.preprocessMarkdown(text);
                        if (preprocessed !== text) {
                            this.createMde.commands.insertContent(preprocessed, { contentType: 'markdown' });
                            return true;
                        }
                    }
                    return false;
                }
            },
            onCreate: ({ editor }) => {
                this.updatePlaceholderVisibility(editor);
            },
            onUpdate: ({ editor }) => {
                const val = editor.getMarkdown();
                if (this.app.uiManager.elements.createCharCount) {
                    this.app.uiManager.elements.createCharCount.textContent = val.length.toLocaleString();
                }
                const wordCountEl = document.getElementById('createWordCount');
                if (wordCountEl) {
                    const words = val.trim() ? val.trim().split(/\s+/) : [];
                    wordCountEl.textContent = val.trim() ? words.length.toLocaleString() : '0';
                }
                
                if (!val.trim()) {
                    this.app.targetSentences = [];
                }
                this.app.saveSession();
                
                // Track sentences for AI tools to work properly
                this.app.targetSentences = this.app.textProcessor.splitIntoSentences(val);
                this.app.saveSession();
                
                this.updatePlaceholderVisibility(editor);
                
                // Update toolbar active states
                this.updateTiptapToolbarState();

                // Auto-detect code block languages for blocks that do not have one set
                setTimeout(() => {
                    if (editor.isDestroyed) {
                        return;
                    }
                    
                    let tr = null;
                    editor.state.doc.descendants((node, pos) => {
                        if (node.type.name === 'codeBlock') {
                            const currentLang = node.attrs.language;
                            if (!currentLang) {
                                const text = node.textContent;
                                if (text.trim()) {
                                    const detected = this.detectCodeLanguage(text);
                                    if (detected && detected !== currentLang) {
                                        if (!tr) {
                                            tr = editor.state.tr;
                                        }
                                        tr.setNodeMarkup(pos, undefined, {
                                            ...node.attrs,
                                            language: detected,
                                        });
                                    }
                                }
                            }
                        }
                    });
                    
                    if (tr) {
                        editor.view.dispatch(tr);
                    }
                }, 0);
            },
            onSelectionUpdate: () => {
                this.updateTiptapToolbarState();
            }
        });
        
        this.initTiptapToolbar();
        this.initSelectionToolbar(element);
    }

    resolveDOMPosition(pos) {
        if (!this.createMde) return null;
        try {
            const dom = this.createMde.view.domAtPos(pos);
            let node = dom.node;
            let offset = dom.offset;
            
            if (node && node.nodeType === Node.ELEMENT_NODE) {
                if (offset < node.childNodes.length) {
                    node = node.childNodes[offset];
                    offset = 0;
                    while (node && node.nodeType === Node.ELEMENT_NODE) {
                        if (node.firstChild) {
                            node = node.firstChild;
                        } else {
                            break;
                        }
                    }
                } else {
                    if (node.lastChild) {
                        node = node.lastChild;
                        if (node.nodeType === Node.TEXT_NODE) {
                            offset = node.textContent.length;
                        } else {
                            offset = 0;
                            while (node && node.nodeType === Node.ELEMENT_NODE) {
                                if (node.lastChild) {
                                    node = node.lastChild;
                                } else {
                                    break;
                                }
                            }
                            if (node && node.nodeType === Node.TEXT_NODE) {
                                offset = node.textContent.length;
                            }
                        }
                    }
                }
            }
            return { node, offset };
        } catch (e) {
            console.warn('[ResolveDOM] Error:', e);
            return null;
        }
    }

    getRangeTextRects(range) {
        const rects = [];
        if (!range) return rects;

        const walker = document.createTreeWalker(
            range.commonAncestorContainer,
            NodeFilter.SHOW_TEXT,
            null
        );

        let node = walker.nextNode();
        while (node) {
            if (range.intersectsNode(node)) {
                const subRange = document.createRange();
                const start = (node === range.startContainer) ? range.startOffset : 0;
                const end = (node === range.endContainer) ? range.endOffset : node.textContent.length;
                
                if (end > start) {
                    subRange.setStart(node, start);
                    subRange.setEnd(node, end);
                    rects.push(...subRange.getClientRects());
                }
            }
            node = walker.nextNode();
        }

        if (rects.length === 0) {
            rects.push(...range.getClientRects());
        }
        return rects;
    }

    getRangeTextRectsWithNodes(range) {
        const results = [];
        if (!range) return results;

        const walker = document.createTreeWalker(
            range.commonAncestorContainer,
            NodeFilter.SHOW_TEXT,
            null
        );

        let node = walker.nextNode();
        while (node) {
            if (range.intersectsNode(node)) {
                const subRange = document.createRange();
                const start = (node === range.startContainer) ? range.startOffset : 0;
                const end = (node === range.endContainer) ? range.endOffset : node.textContent.length;
                
                if (end > start) {
                    subRange.setStart(node, start);
                    subRange.setEnd(node, end);
                    const rects = subRange.getClientRects();
                    for (let j = 0; j < rects.length; j++) {
                        results.push({
                            rect: rects[j],
                            node: node
                        });
                    }
                }
            }
            node = walker.nextNode();
        }

        if (results.length === 0) {
            const rects = range.getClientRects();
            for (let j = 0; j < rects.length; j++) {
                results.push({
                    rect: rects[j],
                    node: range.commonAncestorContainer
                });
            }
        }
        return results;
    }

    drawOverlayForRange(from, to, isOutput) {
        if (!this.createMde) return;
        try {
            const startDOM = this.resolveDOMPosition(from);
            const endDOM = this.resolveDOMPosition(to);
            if (startDOM && endDOM && startDOM.node && endDOM.node) {
                const range = document.createRange();
                range.setStart(startDOM.node, startDOM.offset);
                range.setEnd(endDOM.node, endDOM.offset);
                
                // Get the scroll container bounds to prevent highlight bleeding out
                const scrollContainer = document.getElementById('createText');
                let containerRect = null;
                if (scrollContainer) {
                    containerRect = scrollContainer.getBoundingClientRect();
                }
                
                const items = this.getRangeTextRectsWithNodes(range);
                for (let i = 0; i < items.length; i++) {
                    const { rect, node } = items[i];
                    if (rect.width > 0 && rect.height > 0) {
                        let finalLeft = rect.left + window.scrollX;
                        let finalTop = rect.top + window.scrollY;
                        let finalWidth = rect.width;
                        let finalHeight = rect.height;
                        
                        // Clip to scroll container bounds
                        if (containerRect) {
                            const cLeft = containerRect.left + window.scrollX;
                            const cRight = containerRect.right + window.scrollX;
                            const cTop = containerRect.top + window.scrollY;
                            const cBottom = containerRect.bottom + window.scrollY;
                            
                            const rLeft = finalLeft;
                            const rRight = finalLeft + finalWidth;
                            const rTop = finalTop;
                            const rBottom = finalTop + finalHeight;
                            
                            const clipLeft = Math.max(rLeft, cLeft);
                            const clipRight = Math.min(rRight, cRight);
                            const clipTop = Math.max(rTop, cTop);
                            const clipBottom = Math.min(rBottom, cBottom);
                            
                            if (clipBottom <= clipTop || clipRight <= clipLeft) {
                                // Completely out of bounds (scrolled out or clipped)
                                continue;
                            }
                            
                            finalLeft = clipLeft;
                            finalTop = clipTop;
                            finalWidth = clipRight - clipLeft;
                            finalHeight = clipBottom - clipTop;
                        }
                        
                        let isInPre = false;
                        let preElement = null;
                        if (node) {
                            if (node.nodeType === Node.TEXT_NODE && node.parentElement) {
                                preElement = node.parentElement.closest('pre');
                            } else if (typeof node.closest === 'function') {
                                preElement = node.closest('pre');
                            }
                            isInPre = !!preElement;
                        }

                        // Skip rendering highlights if the code block is currently in diagram mode
                        if (isInPre && preElement && preElement.classList.contains('visual-hide')) {
                            continue;
                        }

                        const overlay = document.createElement('div');
                        let className = isOutput 
                            ? 'custom-selection-highlight is-output' 
                            : 'custom-selection-highlight';
                        if (isInPre) {
                            className += ' is-in-pre';
                        }
                        overlay.className = className;
                        overlay.style.position = 'absolute';
                        overlay.style.left = `${finalLeft}px`;
                        overlay.style.top = `${finalTop}px`;
                        overlay.style.width = `${finalWidth}px`;
                        overlay.style.height = `${finalHeight}px`;
                        overlay.style.pointerEvents = 'none';
                        overlay.style.zIndex = '9999';
                        
                        document.body.appendChild(overlay);
                        this.selectionOverlays.push(overlay);
                    }
                }
            }
        } catch (e) {
            console.warn('[Highlight] Drawing range failed for positions:', from, to, e);
        }
    }

    showSelectionHighlight() {
        this.clearSelectionHighlight();
        
        const markdownTextarea = document.getElementById('createTextMarkdown');
        const isMarkdownMode = markdownTextarea && markdownTextarea.parentElement.style.display !== 'none';
        
        if (isMarkdownMode) {
            return;
        }

        const activeEl = document.activeElement;
        const isEditorFocused = activeEl && (activeEl.closest('.ProseMirror') || activeEl.closest('.tiptap-container'));
        const isToolbarOpen = this.selectionToolbar && this.selectionToolbar.style.display === 'flex';

        // If the editor is currently focused and the selection-toolbar is NOT open,
        // we can rely on the browser's native selection highlight.
        // However, if the toolbar is open, we disable native selection in CSS (make it transparent)
        // and always draw our custom overlays to support dual-colored highlights.
        if (isEditorFocused && !isToolbarOpen) {
            return;
        }

        this.selectionOverlays = [];

        // Check if we are in result-mode of compose flow with valid indices
        const isResultMode = this.selectionToolbar && this.selectionToolbar.classList.contains('result-mode');
        if (this.isComposeFlow && isResultMode && this.composeStartTiptap && this.savedTiptapSelection) {
            const inputFrom = this.savedTiptapSelection.from;
            const inputTo = Math.min(this.composeOriginalEndPos || this.composeStartTiptap.to, this.savedTiptapSelection.to);
            const outputFrom = inputTo;
            const outputTo = this.savedTiptapSelection.to;

            // Draw Input Context highlight
            if (inputTo > inputFrom) {
                this.drawOverlayForRange(inputFrom, inputTo, false);
            }
            // Draw Output highlight
            if (outputTo > outputFrom) {
                this.drawOverlayForRange(outputFrom, outputTo, true);
            }
        } else if (this.createMde && this.savedTiptapSelection) {
            // Standard single-color highlight for other actions
            const from = this.savedTiptapSelection.from;
            const to = this.savedTiptapSelection.to;
            if (to > from) {
                this.drawOverlayForRange(from, to, false);
            }
        }
    }

    clearSelectionHighlight() {
        if (this.selectionOverlays) {
            this.selectionOverlays.forEach(overlay => {
                if (overlay && overlay.parentNode) {
                    overlay.parentNode.removeChild(overlay);
                }
            });
            this.selectionOverlays = null;
        }
    }

    showSkeletons() {
        const markdownTextarea = document.getElementById('createTextMarkdown');
        const isMarkdownMode = markdownTextarea && markdownTextarea.parentElement.style.display !== 'none';
        
        let skeletonOverlays = [];
        const isCompose = this.isComposeFlow;

        if (!isMarkdownMode) {
            if (this.createMde) {
                try {
                    this.createMde.commands.focus();
                } catch (e) {}
            }

            // Draw custom highlights
            this.showSelectionHighlight();

            // Get the scroll container bounds to prevent skeleton bleeding out
            const scrollContainer = document.getElementById('createText');
            let containerRect = null;
            if (scrollContainer) {
                containerRect = scrollContainer.getBoundingClientRect();
            }

            // Check if we are in compose flow and ALREADY have a generated text that needs to be masked
            const hasExistingOutput = isCompose && this.alternativeHistory && this.alternativeHistory.length > 1 && this.composeStartTiptap && this.savedTiptapSelection;
            
            if (hasExistingOutput) {
                // Draw skeletons on top of the old output range to mask it
                const outputFrom = this.composeStartTiptap.to;
                const outputTo = this.savedTiptapSelection.to;
                
                if (outputTo > outputFrom) {
                    try {
                        const startDOM = this.resolveDOMPosition(outputFrom);
                        const endDOM = this.resolveDOMPosition(outputTo);
                        if (startDOM && endDOM && startDOM.node && endDOM.node) {
                            const range = document.createRange();
                            range.setStart(startDOM.node, startDOM.offset);
                            range.setEnd(endDOM.node, endDOM.offset);
                            
                            const rects = this.getRangeTextRects(range);
                            for (let i = 0; i < rects.length; i++) {
                                const rect = rects[i];
                                if (rect.width > 0 && rect.height > 0) {
                                    let finalLeft = rect.left + window.scrollX;
                                    let finalTop = rect.top + window.scrollY;
                                    let finalWidth = rect.width;
                                    let finalHeight = rect.height;
                                    
                                    if (containerRect) {
                                        const cLeft = containerRect.left + window.scrollX;
                                        const cRight = containerRect.right + window.scrollX;
                                        const cTop = containerRect.top + window.scrollY;
                                        const cBottom = containerRect.bottom + window.scrollY;
                                        
                                        const rLeft = finalLeft;
                                        const rRight = finalLeft + finalWidth;
                                        const rTop = finalTop;
                                        const rBottom = finalTop + finalHeight;
                                        
                                        const clipLeft = Math.max(rLeft, cLeft);
                                        const clipRight = Math.min(rRight, cRight);
                                        const clipTop = Math.max(rTop, cTop);
                                        const clipBottom = Math.min(rBottom, cBottom);
                                        
                                        if (clipBottom <= clipTop || clipRight <= clipLeft) {
                                            continue;
                                        }
                                        
                                        finalLeft = clipLeft;
                                        finalTop = clipTop;
                                        finalWidth = clipRight - clipLeft;
                                        finalHeight = clipBottom - clipTop;
                                    }

                                    const skeletonOverlay = document.createElement('div');
                                    skeletonOverlay.className = 'sentence-item is-loading-correction granular-mask';
                                    skeletonOverlay.style.position = 'absolute';
                                    skeletonOverlay.style.left = `${finalLeft}px`;
                                    skeletonOverlay.style.top = `${finalTop}px`;
                                    skeletonOverlay.style.width = `${finalWidth}px`;
                                    skeletonOverlay.style.height = `${finalHeight}px`;
                                    skeletonOverlay.style.zIndex = '1000';
                                    skeletonOverlay.style.pointerEvents = 'none';
                                    
                                    document.body.appendChild(skeletonOverlay);
                                    skeletonOverlays.push(skeletonOverlay);
                                }
                            }
                        }
                    } catch (err) {
                        console.warn('[Skeletons] Drawing output mask failed:', err);
                    }
                }
            } else if (isCompose) {
                // If it's the very first compose generation, draw the generic 2 skeleton lines at the bottom of the original selection
                if (this.savedDOMRange) {
                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(this.savedDOMRange);
                }

                const selection = window.getSelection();
                if (selection.rangeCount) {
                    const range = selection.getRangeAt(0);
                    const rectItems = this.getRangeTextRectsWithNodes(range);
                    if (rectItems.length > 0) {
                        const lastItem = rectItems[rectItems.length - 1];
                        const lastRect = lastItem.rect;
                        const lastNode = lastItem.node;
                        
                        // Check if the last node is inside a code block
                        let preEl = null;
                        if (lastNode) {
                            if (lastNode.nodeType === Node.TEXT_NODE && lastNode.parentElement) {
                                preEl = lastNode.parentElement.closest('pre');
                            } else if (typeof lastNode.closest === 'function') {
                                preEl = lastNode.closest('pre');
                            }
                        }
                        
                        let topPos = lastRect.bottom + 8 + window.scrollY;
                        let baseLeft = lastRect.left;
                        
                        if (preEl) {
                            const preRect = preEl.getBoundingClientRect();
                            topPos = preRect.bottom + 12 + window.scrollY;
                            baseLeft = preRect.left;
                        }
                        
                        const finalLeft = Math.max(16, Math.min(baseLeft, window.innerWidth - 320)) + window.scrollX;
                        
                        // Clip the line 1 and 2 skeleton boundaries as well
                        let drawSkeletons = true;
                        if (containerRect) {
                            const cTop = containerRect.top + window.scrollY;
                            const cBottom = containerRect.bottom + window.scrollY;
                            if (topPos < cTop || topPos > cBottom - 40) {
                                drawSkeletons = false;
                            }
                        }

                        if (drawSkeletons) {
                            // Line 1
                            const sk1 = document.createElement('div');
                            sk1.className = 'sentence-item is-loading-correction granular-mask';
                            sk1.style.position = 'absolute';
                            sk1.style.left = `${finalLeft}px`;
                            sk1.style.top = `${topPos}px`;
                            sk1.style.width = `280px`;
                            sk1.style.height = `14px`;
                            sk1.style.zIndex = '1000';
                            sk1.style.pointerEvents = 'none';
                            document.body.appendChild(sk1);
                            skeletonOverlays.push(sk1);

                            // Line 2
                            const sk2 = document.createElement('div');
                            sk2.className = 'sentence-item is-loading-correction granular-mask';
                            sk2.style.position = 'absolute';
                            sk2.style.left = `${finalLeft}px`;
                            sk2.style.top = `${topPos + 20}px`;
                            sk2.style.width = `180px`;
                            sk2.style.height = `14px`;
                            sk2.style.zIndex = '1000';
                            sk2.style.pointerEvents = 'none';
                            document.body.appendChild(sk2);
                            skeletonOverlays.push(sk2);
                        }
                    }
                }
            } else {
                // Standard mode (Rephrase, shorten etc.) - draw skeletons over the whole selection
                if (this.savedDOMRange) {
                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(this.savedDOMRange);
                }

                const selection = window.getSelection();
                if (selection.rangeCount) {
                    const range = selection.getRangeAt(0);
                    const rects = this.getRangeTextRects(range);
                    for (let i = 0; i < rects.length; i++) {
                        const rect = rects[i];
                        if (rect.width > 0 && rect.height > 0) {
                            let finalLeft = rect.left + window.scrollX;
                            let finalTop = rect.top + window.scrollY;
                            let finalWidth = rect.width;
                            let finalHeight = rect.height;
                            
                            if (containerRect) {
                                const cLeft = containerRect.left + window.scrollX;
                                const cRight = containerRect.right + window.scrollX;
                                const cTop = containerRect.top + window.scrollY;
                                const cBottom = containerRect.bottom + window.scrollY;
                                
                                const rLeft = finalLeft;
                                const rRight = finalLeft + finalWidth;
                                const rTop = finalTop;
                                const rBottom = finalTop + finalHeight;
                                
                                const clipLeft = Math.max(rLeft, cLeft);
                                const clipRight = Math.min(rRight, cRight);
                                const clipTop = Math.max(rTop, cTop);
                                const clipBottom = Math.min(rBottom, cBottom);
                                
                                if (clipBottom <= clipTop || clipRight <= clipLeft) {
                                    continue;
                                }
                                
                                finalLeft = clipLeft;
                                finalTop = clipTop;
                                finalWidth = clipRight - clipLeft;
                                finalHeight = clipBottom - clipTop;
                            }

                            const skeletonOverlay = document.createElement('div');
                            skeletonOverlay.className = 'sentence-item is-loading-correction granular-mask';
                            skeletonOverlay.style.position = 'absolute';
                            skeletonOverlay.style.left = `${finalLeft}px`;
                            skeletonOverlay.style.top = `${finalTop}px`;
                            skeletonOverlay.style.width = `${finalWidth}px`;
                            skeletonOverlay.style.height = `${finalHeight}px`;
                            skeletonOverlay.style.zIndex = '1000';
                            skeletonOverlay.style.pointerEvents = 'none';
                            
                            document.body.appendChild(skeletonOverlay);
                            skeletonOverlays.push(skeletonOverlay);
                        }
                    }
                }
            }
            
            if (!isCompose) {
                window.getSelection().removeAllRanges();
            }
        } else {
            // Markdown mode fallback
            const skeletonOverlay = document.createElement('div');
            skeletonOverlay.className = 'sentence-item is-loading-correction granular-mask';
            skeletonOverlay.style.position = 'absolute';
            const tLeft = parseFloat(this.selectionToolbar.style.left) || (window.innerWidth / 2);
            const tTop = parseFloat(this.selectionToolbar.style.top) || (window.innerHeight / 2);
            skeletonOverlay.style.left = `${tLeft - 50}px`;
            skeletonOverlay.style.top = `${tTop + 30}px`;
            skeletonOverlay.style.width = `150px`;
            skeletonOverlay.style.height = `24px`;
            skeletonOverlay.style.zIndex = '1000';
            skeletonOverlay.style.pointerEvents = 'none';
            
            document.body.appendChild(skeletonOverlay);
            skeletonOverlays.push(skeletonOverlay);
            
            if (!isCompose) {
                markdownTextarea.style.opacity = '0.6';
                markdownTextarea.style.pointerEvents = 'none';
            }
        }
        return skeletonOverlays;
    }

    clearSkeletons(overlays) {
        if (!overlays) return;
        overlays.forEach(overlay => {
            if (overlay && overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
        });
        
        const markdownTextarea = document.getElementById('createTextMarkdown');
        if (markdownTextarea) {
            markdownTextarea.style.opacity = '1';
            markdownTextarea.style.pointerEvents = 'auto';
        }
    }

    applyHistoryState(index) {
        if (index < 0 || index >= this.alternativeHistory.length) return;
        
        this.isUpdatingHistory = true;
        const prevIndex = this.currentHistoryIndex;
        this.currentHistoryIndex = index;
        
        if (index > 0) {
            this.lastActiveHistoryIndex = index;
        }
        
        // Update the prompt associated with this history index!
        if (this.alternativePrompts) {
            this.lastStyleParam = this.alternativePrompts[index] || '';
        }
        
        // Update the title associated with this history index!
        if (this.alternativeTitles) {
            this.resultBarTitle = (index === 0) ? 'Originaltext' : (this.alternativeTitles[index] || 'Umformulieren');
        }
        
        const text = this.alternativeHistory[index];
        
        const markdownTextarea = document.getElementById('createTextMarkdown');
        const isMarkdownMode = markdownTextarea && markdownTextarea.parentElement.style.display !== 'none';
        const isCompose = this.isComposeFlow;

        if (isMarkdownMode) {
            // Find the start and end of the block currently in the editor
            const start = isCompose ? this.composeStartMarkdown.start : this.savedMarkdownSelection.start;
            const end = this.savedMarkdownSelection.end;
            
            const val = markdownTextarea.value;
            const plainText = (text && typeof text === 'object') ? text.text : text;
            markdownTextarea.value = val.substring(0, start) + plainText + val.substring(end);
            
            // Calculate new selection range
            // In both normal and compose flows, we select from the start of the block to the end of the text
            // so that both the input context and the new output text are highlighted.
            let selectionStart = start;
            let selectionEnd = start + plainText.length;
            
            this.savedMarkdownSelection = { start: selectionStart, end: selectionEnd };
            this.createMde.commands.setContent(this.preprocessMarkdown(markdownTextarea.value), { contentType: 'markdown' });
            
            // Resize textarea after programmatic update
            markdownTextarea.style.height = 'auto';
            markdownTextarea.style.height = markdownTextarea.scrollHeight + 'px';
            
            // Visual text highlight in textarea
            try {
                markdownTextarea.focus();
                markdownTextarea.setSelectionRange(selectionStart, selectionEnd);
            } catch (e) {}
        } else {
            // Find the start and end of the block currently in the editor
            const from = (isCompose && this.composeStartTiptap)
                ? this.composeStartTiptap.from
                : (this.savedTiptapSelection ? this.savedTiptapSelection.from : this.createMde.state.selection.from);
            
            let to = this.savedTiptapSelection
                ? this.savedTiptapSelection.to
                : this.createMde.state.selection.to;
            
            const docSize = this.createMde.state.doc.content.size;
            if (to > docSize) {
                to = docSize;
            }

            console.log('[HAWKI applyHistoryState Tiptap]', {
                from,
                to,
                textLength: text ? (typeof text === 'object' ? 'object' : text.length) : 0,
                isCompose,
                composeStartTiptap: this.composeStartTiptap,
                savedTiptapSelection: this.savedTiptapSelection,
                docSize
            });
            
            // Focus the editor first and run the text insertion atomically using setTextSelection and insertContent
            try {
                const isFullDoc = (from <= 1 && to >= docSize - 1);
                
                if (text && typeof text === 'object') {
                    // Restoring index 0 (Original) using our lossless formats
                    if (text.json) {
                        if (isFullDoc) {
                            this.createMde.commands.setContent(text.json);
                        } else {
                            this.createMde.chain()
                                .focus()
                                .setTextSelection({ from, to })
                                .insertContent(text.json)
                                .run();
                        }
                    } else if (text.html) {
                        if (isFullDoc) {
                            this.createMde.commands.setContent(text.html, { contentType: 'html' });
                        } else {
                            this.createMde.chain()
                                .focus()
                                .setTextSelection({ from, to })
                                .insertContent(text.html, { contentType: 'html' })
                                .run();
                        }
                    } else {
                        if (isFullDoc) {
                            this.createMde.commands.setContent(text.text);
                        } else {
                            this.createMde.chain()
                                .focus()
                                .setTextSelection({ from, to })
                                .insertContent(text.text)
                                .run();
                        }
                    }
                } else {
                    // Editing / Rephrasing / Compose flow (index > 0)
                    if (isCompose) {
                        // In compose flow, we want to keep the original rich nodes intact, and only insert the AI's extension after it!
                        const originalState = this.alternativeHistory[0];
                        const originalText = (originalState && typeof originalState === 'object') ? originalState.text : (originalState || '');
                        
                        let completionText = text;
                        const trimmedOriginal = originalText.trim();
                        const trimmedNew = text.trim();
                        if (trimmedNew.startsWith(trimmedOriginal)) {
                            completionText = text.substring(originalText.length);
                        }
                        
                        const originalJSONOrHTML = (originalState && typeof originalState === 'object')
                            ? (originalState.json || originalState.html || originalState.text)
                            : originalState;
                        
                        const processedCompletion = this.preprocessMarkdown(completionText);
                        
                        if (originalJSONOrHTML) {
                            // Step 1: Losslessly restore/insert original rich text nodes
                            this.createMde.chain()
                                .focus()
                                .setTextSelection({ from, to })
                                .insertContent(originalJSONOrHTML)
                                .run();
                            
                            // Step 2: Get the updated selection position after the insertion
                            const insertPos = this.createMde.state.selection.to;
                            this.composeOriginalEndPos = insertPos;
                            
                            // Step 3: Insert the completion text as markdown at that exact position
                            this.createMde.chain()
                                .focus()
                                .setTextSelection({ from: insertPos, to: insertPos })
                                .insertContent(processedCompletion, { contentType: 'markdown' })
                                .run();
                        } else {
                            this.createMde.chain()
                                .focus()
                                .setTextSelection({ from, to })
                                .insertContent(this.preprocessMarkdown(text), { contentType: 'markdown' })
                                .run();
                            this.composeOriginalEndPos = from;
                        }
                    } else {
                        // Normal improvement / paraphrase flow
                        const isHTML = typeof text === 'string' && /<[a-z/][\s\S]*>/i.test(text);
                        if (isHTML) {
                            if (isFullDoc) {
                                this.createMde.commands.setContent(text, { contentType: 'html' });
                            } else {
                                this.createMde.chain()
                                    .focus()
                                    .setTextSelection({ from, to })
                                    .insertContent(text, { contentType: 'html' })
                                    .run();
                            }
                        } else {
                            const preprocessed = this.preprocessMarkdown(text);
                            if (isFullDoc) {
                                this.createMde.commands.setContent(preprocessed, { contentType: 'markdown' });
                            } else {
                                this.createMde.chain()
                                    .focus()
                                    .setTextSelection({ from, to })
                                    .insertContent(preprocessed, { contentType: 'markdown' })
                                    .run();
                            }
                        }
                    }
                }
            } catch (e) {
                console.warn('[Tiptap applyHistoryState] insertContent failed:', e);
            }
            
            // The cursor is now at the exact end of the inserted content in the fully updated document state
            const insertedEndPos = this.createMde.state.selection.to;
            
            if (isCompose && index === 0) {
                this.composeOriginalEndPos = insertedEndPos;
            }
            
            // Calculate the new selection range (from the start of the block to the end of the text)
            let selectionFrom = from;
            let selectionTo = insertedEndPos;
            
            if (selectionTo > this.createMde.state.doc.content.size) {
                selectionTo = this.createMde.state.doc.content.size;
            }
            if (selectionFrom > selectionTo) {
                selectionFrom = selectionTo;
            }
            
            try {
                this.createMde.chain()
                    .focus()
                    .setTextSelection({ from: selectionFrom, to: selectionTo })
                    .run();
                this.savedTiptapSelection = { from: selectionFrom, to: selectionTo };
            } catch (err) {
                console.warn('[Selection fallback] Setting TextSelection failed:', err);
                this.savedTiptapSelection = { from: selectionFrom, to: this.createMde.state.selection.to };
            }
            
            // Sync savedDOMRange directly using ProseMirror coordinates
            try {
                const startDOM = this.createMde.view.domAtPos(selectionFrom);
                const endDOM = this.createMde.view.domAtPos(selectionTo);
                if (startDOM && endDOM) {
                    const range = document.createRange();
                    range.setStart(startDOM.node, startDOM.offset);
                    range.setEnd(endDOM.node, endDOM.offset);
                    this.savedDOMRange = range;
                }
            } catch (e) {
                try {
                    const selection = window.getSelection();
                    if (selection.rangeCount > 0) {
                        this.savedDOMRange = selection.getRangeAt(0).cloneRange();
                    }
                } catch (err) {}
            }
        }
        
        this.updateResultBarUI();
        
        // Auto-reposition the result bar to align with the new text's dimensions
        if (this.selectionToolbar.classList.contains('result-mode')) {
            setTimeout(() => {
                this.updateToolbarPosition(true);
            }, 50);
        }

        setTimeout(() => {
            this.isUpdatingHistory = false;
        }, 200);

        // Ensure the visual selection highlight is always kept active and synchronized in result-mode
        if (this.selectionToolbar.classList.contains('result-mode')) {
            this.showSelectionHighlight();
            // Optional micro-sync after DOM settles
            setTimeout(() => this.showSelectionHighlight(), 20);
        }
    }

    renderResultBarHTML() {
        const isLoading = this.isCapsuleLoading;
        const disabledAttr = isLoading ? 'disabled' : '';
        
        const titleContent = isLoading 
            ? `<svg class="capsule-spinner" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" style="opacity: 0.25;"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" style="opacity: 0.75;"></path></svg> Wird bearbeitet...`
            : (this.resultBarTitle || 'Umformulieren');

        const totalVariations = this.alternativeHistory ? this.alternativeHistory.length : 0;
        const currentVariation = totalVariations > 0 ? (this.currentHistoryIndex + 1) : 0;

        const isCompose = this.isComposeFlow;
        const originalBtnStyle = isCompose ? 'style="display: none;"' : '';

        this.selectionToolbar.innerHTML = `
            <div class="result-bar-container">
                <button class="result-bar-btn reset-btn" ${disabledAttr} title="Zurücksetzen">Zurücksetzen</button>
                
                <button class="result-bar-icon-btn original-btn" ${disabledAttr} ${originalBtnStyle} title="Original anzeigen">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-list-restart-icon lucide-list-restart"><path d="M21 5H3"/><path d="M7 12H3"/><path d="M7 19H3"/><path d="M12 18a5 5 0 0 0 9-3 4.5 4.5 0 0 0-4.5-4.5c-1.33 0-2.54.54-3.41 1.41L11 14"/><path d="M11 10v4h4"/></svg>
                </button>
                
                <button class="result-bar-icon-btn edit-btn" ${disabledAttr} title="Bearbeiten">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sparkles"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
                </button>
                
                <span class="result-bar-title" title="Prompt anzeigen">
                    <span class="result-bar-title-text">${titleContent}</span>
                    <span class="result-bar-title-tooltip"></span>
                </span>
                
                <div class="result-bar-nav-group">
                    <button class="result-bar-nav-btn prev-btn" ${disabledAttr} title="Rückgängig">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-undo-2"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5v0a5.5 5.5 0 0 1-5.5 5.5H11"/></svg>
                    </button>
                    <div class="result-bar-nav-divider"></div>
                    <span class="result-bar-nav-counter">${currentVariation}/${totalVariations}</span>
                    <div class="result-bar-nav-divider"></div>
                    <button class="result-bar-nav-btn next-btn" ${disabledAttr} title="Wiederholen">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-redo-2"><path d="m15 14 5-5-5-5"/><path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5v0A5.5 5.5 0 0 0 9.5 20H13"/></svg>
                    </button>
                </div>
                
                <button class="result-bar-icon-btn regenerate-btn" ${disabledAttr} title="Regenerieren">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-refresh-cw"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                </button>
                
                <button class="result-bar-btn finish-btn" ${disabledAttr} title="Fertig">Fertig</button>
            </div>
        `;
    }

    renderResultBarEditHTML() {
        const isLoading = this.isCapsuleLoading;
        const disabledAttr = isLoading ? 'disabled' : '';
        const currentPrompt = this.lastStyleParam || '';
        
        this.selectionToolbar.innerHTML = `
            <div class="result-bar-container result-bar-edit-state">
                <button class="result-bar-icon-btn result-bar-back-btn" ${disabledAttr} title="Zurück">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-left"><path d="m15 18-6-6 6-6"/></svg>
                </button>

                <div class="result-bar-edit-input-wrapper">
                    <span class="result-bar-edit-sparkle">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sparkles"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
                    </span>
                    <input type="text" class="result-bar-edit-input" ${disabledAttr} placeholder="Änderung beschreiben..." value="${currentPrompt}" />
                </div>
                
                <button class="result-bar-icon-btn submit-btn" ${disabledAttr} title="Absenden">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </button>
            </div>
        `;
    }

    bindResultBarEditListeners() {
        const container = this.selectionToolbar;
        
        const submitBtn = container.querySelector('.submit-btn');
        const backBtn = container.querySelector('.result-bar-back-btn');
        const input = container.querySelector('.result-bar-edit-input');
        
        const triggerSubmit = async () => {
            if (input) {
                const promptValue = input.value.trim();
                if (promptValue) {
                    await this.processSelectedText(promptValue);
                }
            }
        };

        if (submitBtn) {
            submitBtn.addEventListener('click', triggerSubmit);
        }

        if (backBtn) {
            backBtn.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                this.renderResultBarHTML();
                this.bindResultBarListeners();
            });
        }
        
        if (input) {
            input.focus();
            
            // Listen for Enter key to trigger a new custom LLM revision
            input.addEventListener('keydown', async (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    await triggerSubmit();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    this.renderResultBarHTML();
                    this.bindResultBarListeners();
                }
            });
        }
    }

    bindResultBarListeners() {
        const container = this.selectionToolbar;
        if (this.isCapsuleLoading) return;
        
        container.querySelector('.reset-btn').addEventListener('click', () => {
            this.isCapsulePositionLocked = false;
            this.applyHistoryState(0);
            this.selectionToolbar.classList.remove('result-mode');
            this.selectionToolbar.style.display = 'none';
            document.body.classList.remove('has-context-menu');
        });
        
        container.querySelector('.original-btn').addEventListener('click', () => {
            if (this.currentHistoryIndex === 0) {
                const targetIndex = (this.lastActiveHistoryIndex !== undefined && this.lastActiveHistoryIndex > 0 && this.lastActiveHistoryIndex < this.alternativeHistory.length)
                    ? this.lastActiveHistoryIndex
                    : this.alternativeHistory.length - 1;
                this.applyHistoryState(targetIndex);
            } else {
                this.applyHistoryState(0);
            }
        });
        
        container.querySelector('.edit-btn').addEventListener('click', () => {
            this.renderResultBarEditHTML();
            this.bindResultBarEditListeners();
        });
        
        container.querySelector('.prev-btn').addEventListener('click', () => {
            if (this.currentHistoryIndex > 0) {
                this.applyHistoryState(this.currentHistoryIndex - 1);
            }
        });
        
        container.querySelector('.next-btn').addEventListener('click', () => {
            if (this.currentHistoryIndex < this.alternativeHistory.length - 1) {
                this.applyHistoryState(this.currentHistoryIndex + 1);
            }
        });
        
        container.querySelector('.regenerate-btn').addEventListener('click', async () => {
            this.isCapsuleLoading = true;
            this.renderResultBarHTML();
            this.updateToolbarPosition();
            
            const skeletonOverlays = this.showSkeletons();
            
            try {
                const originalText = (this.alternativeHistory[0] && typeof this.alternativeHistory[0] === 'object')
                    ? this.alternativeHistory[0].text
                    : this.alternativeHistory[0];
                const styleParam = this.lastStyleParam || 'Umformulieren';
                let agent = this.getAgentTypeAndTitle(styleParam);
                if (this.isComposeFlow) {
                    agent = { type: 'compose', title: 'Verfassen' };
                }
                const rawResult = await this.app.languageService.improve({
                    text: originalText,
                    context: null,
                    type: agent.type,
                    style: styleParam,
                    target_lang: this.app.uiManager.elements.createTargetLang?.value || 'de',
                    model: this.app.selectedModel?.id,
                    web_search: this.isWebAgentEnabled !== false
                });
                
                if (rawResult.success && rawResult.data && rawResult.data.text) {
                    let newText = Array.isArray(rawResult.data.text) ? rawResult.data.text[0] : rawResult.data.text;
                    
                    if (this.isComposeFlow) {
                        const trimmedOriginal = originalText.trim();
                        const trimmedNew = newText.trim();
                        if (!trimmedNew.startsWith(trimmedOriginal) && trimmedOriginal.length > 0) {
                            const separator = originalText.endsWith('\n') ? '\n' : '\n\n';
                            newText = originalText + separator + newText;
                        }
                    }

                    this.alternativeHistory.push(newText);
                    
                    if (this.alternativePrompts) {
                        this.alternativePrompts.push(this.lastStyleParam || '');
                    }
                    if (this.alternativeTitles) {
                        this.alternativeTitles.push(this.resultBarTitle || 'Umformulieren');
                    }
                    
                    this.isCapsuleLoading = false;
                    this.renderResultBarHTML();
                    this.bindResultBarListeners();
                    this.applyHistoryState(this.alternativeHistory.length - 1);
                }
            } catch (error) {
                console.error('[Regenerate] Variation failed:', error);
                this.isCapsuleLoading = false;
                this.renderResultBarHTML();
                this.bindResultBarListeners();
            } finally {
                this.clearSkeletons(skeletonOverlays);
            }
        });
        
        container.querySelector('.finish-btn').addEventListener('click', () => {
            this.isCapsulePositionLocked = false;
            this.selectionToolbar.classList.remove('result-mode');
            this.selectionToolbar.style.display = 'none';
            this.clearSelectionHighlight();
            document.body.classList.remove('has-context-menu');
        });

        const titleEl = container.querySelector('.result-bar-title');
        if (titleEl) {
            titleEl.addEventListener('click', (e) => {
                e.stopPropagation();
                const tooltipEl = titleEl.querySelector('.result-bar-title-tooltip');
                if (tooltipEl) {
                    const show = !tooltipEl.classList.contains('show');
                    
                    // Hide all other tooltips first
                    const allTooltips = document.querySelectorAll('.result-bar-title-tooltip');
                    allTooltips.forEach(el => el.classList.remove('show'));
                    
                    if (show) {
                        const promptText = this.lastStyleParam || 'Originaltext';
                        tooltipEl.textContent = promptText;
                        tooltipEl.classList.add('show');
                        
                        // Close on next click anywhere
                        const onDocumentClick = () => {
                            tooltipEl.classList.remove('show');
                            document.removeEventListener('click', onDocumentClick);
                        };
                        document.addEventListener('click', onDocumentClick);
                    }
                }
            });
        }
        
        this.updateResultBarUI();
    }

    updateResultBarUI() {
        const container = this.selectionToolbar;
        if (!container.classList.contains('result-mode') || this.isCapsuleLoading) return;
        
        const prevBtn = container.querySelector('.prev-btn');
        const nextBtn = container.querySelector('.next-btn');
        const originalBtn = container.querySelector('.original-btn');
        const counter = container.querySelector('.result-bar-nav-counter');
        const titleTextSpan = container.querySelector('.result-bar-title-text');
        
        if (prevBtn) prevBtn.disabled = (this.currentHistoryIndex <= 0);
        if (nextBtn) nextBtn.disabled = (this.currentHistoryIndex >= this.alternativeHistory.length - 1);
        
        if (originalBtn) {
            if (this.currentHistoryIndex === 0) {
                originalBtn.classList.add('is-active');
            } else {
                originalBtn.classList.remove('is-active');
            }
        }

        if (counter && this.alternativeHistory) {
            counter.textContent = `${this.currentHistoryIndex + 1}/${this.alternativeHistory.length}`;
        }

        if (titleTextSpan) {
            titleTextSpan.textContent = this.resultBarTitle || 'Umformulieren';
        }
    }

    renderSelectionToolbarHTML() {
        this.selectionToolbar.innerHTML = `
            <div class="context-menu-search-wrapper">
                <span class="context-menu-sparkles-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sparkles"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
                </span>
                <input type="text" class="context-menu-input" placeholder="Änderung beschreiben..." />
            </div>

            <div class="context-menu-grid">
                <button class="context-menu-grid-btn" data-action="Korrekturlesen" title="Korrekturlesen">
                    <span class="context-menu-grid-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-zoom-in"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                    </span>
                    <span class="context-menu-grid-label">Korrekturlesen</span>
                </button>
                <button class="context-menu-grid-btn" data-action="Umformulieren" title="Umformulieren">
                    <span class="context-menu-grid-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-refresh-cw"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                    </span>
                    <span class="context-menu-grid-label">Umformulieren</span>
                </button>
            </div>

            <div class="context-menu-divider"></div>

            <div class="context-menu-list">
                <button class="context-menu-list-item" data-action="Hauptpunkte" title="Hauptpunkte">
                    <span class="context-menu-list-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-megaphone-icon lucide-megaphone"><path d="M11 6a13 13 0 0 0 8.4-2.8A1 1 0 0 1 21 4v12a1 1 0 0 1-1.6.8A13 13 0 0 0 11 14H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/><path d="M6 14a12 12 0 0 0 2.4 7.2 2 2 0 0 0 3.2-2.4A8 8 0 0 1 10 14"/><path d="M8 6v8"/></svg>
                    </span>
                    <span class="context-menu-list-label">Hauptpunkte</span>
                </button>
                <button class="context-menu-list-item" data-action="Paraphrasieren" title="Paraphrasieren">
                    <span class="context-menu-list-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-message-square-quote-icon lucide-message-square-quote"><path d="M14 14a2 2 0 0 0 2-2V8h-2"/><path d="M22 17a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 21.286V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"/><path d="M8 14a2 2 0 0 0 2-2V8H8"/></svg>
                    </span>
                    <span class="context-menu-list-label">Paraphrasieren</span>
                </button>
                <button class="context-menu-list-item" data-action="Kürzen" title="Kürzen">
                    <span class="context-menu-list-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-list-chevrons-down-up-icon lucide-list-chevrons-down-up"><path d="M3 5h8"/><path d="M3 12h8"/><path d="M3 19h8"/><path d="m15 5 3 3 3-3"/><path d="m15 19 3-3 3 3"/></svg>
                    </span>
                    <span class="context-menu-list-label">Kürzen</span>
                </button>
                <button class="context-menu-list-item" data-action="Ausformulieren" title="Ausformulieren">
                    <span class="context-menu-list-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-list-chevrons-up-down-icon lucide-list-chevrons-up-down"><path d="M3 5h8"/><path d="M3 12h8"/><path d="M3 19h8"/><path d="m15 8 3-3 3 3"/><path d="m15 16 3 3 3-3"/></svg>
                    </span>
                    <span class="context-menu-list-label">Ausformulieren</span>
                </button>
                
                <div class="context-menu-divider"></div>
                
                <button class="context-menu-list-item" data-action="Liste" title="Liste">
                    <span class="context-menu-list-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="9" y1="6" x2="20" y2="6"></line>
                            <line x1="9" y1="12" x2="20" y2="12"></line>
                            <line x1="9" y1="18" x2="20" y2="18"></line>
                            <line x1="4" y1="6" x2="5" y2="6"></line>
                            <line x1="4" y1="12" x2="5" y2="12"></line>
                            <line x1="4" y1="18" x2="5" y2="18"></line>
                        </svg>
                    </span>
                    <span class="context-menu-list-label">Liste</span>
                </button>
                <button class="context-menu-list-item" data-action="Tabelle" title="Tabelle">
                    <span class="context-menu-list-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="3" y1="9" x2="21" y2="9"></line>
                            <line x1="3" y1="15" x2="21" y2="15"></line>
                            <line x1="12" y1="3" x2="12" y2="21"></line>
                        </svg>
                    </span>
                    <span class="context-menu-list-label">Tabelle</span>
                </button>
                
                <div class="context-menu-divider"></div>
                
                <button class="context-menu-list-item" data-action="Verfassen" title="Verfassen ...">
                    <span class="context-menu-list-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 20h9"></path>
                            <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
                        </svg>
                    </span>
                    <span class="context-menu-list-label">Verfassen ...</span>
                </button>
            </div>
        `;
    }

    bindSelectionToolbarListeners() {
        const handleAction = (btn) => {
            const action = btn.getAttribute('data-action');
            if (action === 'Verfassen') {
                this.showComposeInputState();
                return;
            }
            let styleParam = action;
            
            if (action === 'Freundlich') {
                styleParam = 'in einem freundlichen, herzlichen Ton formulieren';
            } else if (action === 'Sachlich') {
                styleParam = 'in einem sachlichen, objektiven und professionellen Ton formulieren';
            } else if (action === 'Kompakt') {
                styleParam = 'kurz und prägnant';
            } else if (action === 'Paraphrasieren') {
                styleParam = 'paraphrasieren (den Text mit anderen Worten umschreiben, aber den Inhalt und Sinn exakt beibehalten)';
            } else if (action === 'Kürzen') {
                styleParam = 'deutlich kürzen und auf das Wesentliche reduzieren';
            } else if (action === 'Ausformulieren') {
                styleParam = 'ausführlich ausformulieren (Details hinzufügen und den Text verständlich und flüssig ausschreiben)';
            } else if (action === 'Hauptpunkte') {
                styleParam = 'in Stichpunkten / Hauptpunkten zusammenfassen';
            } else if (action === 'Liste') {
                styleParam = 'als formatierte Liste ausgeben';
            } else if (action === 'Tabelle') {
                styleParam = 'als übersichtliche Tabelle darstellen';
            } else if (action === 'Verfassen') {
                styleParam = 'den Gedanken ausformulieren und weiterschreiben';
            } else if (action === 'Korrekturlesen') {
                styleParam = 'Korrekturlesen (nur Grammatik, Rechtschreibung und Zeichensetzung korrigieren, den Text nicht unnötig umformulieren)';
            } else if (action === 'Umformulieren') {
                const input = this.selectionToolbar.querySelector('.context-menu-input');
                const customText = input ? input.value.trim() : '';
                styleParam = customText || 'Umformulieren';
            }

            this.processSelectedText(styleParam);
        };

        this.selectionToolbar.querySelectorAll('.context-menu-grid-btn, .context-menu-list-item').forEach(btn => {
            btn.addEventListener('mousedown', (e) => {
                e.preventDefault(); // Prevent losing selection
                e.stopPropagation(); // Prevent document mousedown from closing the menu
                handleAction(btn);
            });
        });

        const input = this.selectionToolbar.querySelector('.context-menu-input');
        if (input) {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const customText = input.value.trim();
                    if (customText) {
                        this.processSelectedText(customText);
                    }
                }
            });
            
            input.addEventListener('focus', () => {
                this.showSelectionHighlight();
            });

            input.addEventListener('mousedown', (e) => {
                e.stopPropagation(); // Avoid closing menu when clicking input
            });
        }
    }

    showComposeInputState() {
        this.isComposeFlow = true;
        this.renderSelectionToolbarComposeHTML();
        this.bindSelectionToolbarComposeListeners();
        
        const input = this.selectionToolbar.querySelector('.context-menu-compose-input');
        if (input) {
            setTimeout(() => input.focus(), 50);
        }
    }

    renderSelectionToolbarComposeHTML() {
        const isLoading = this.isCapsuleLoading;
        const disabledAttr = isLoading ? 'disabled' : '';
        
        this.selectionToolbar.classList.add('result-mode');
        
        this.selectionToolbar.innerHTML = `
            <div class="result-bar-container result-bar-edit-state">
                <button class="result-bar-icon-btn result-bar-back-btn" ${disabledAttr} title="Zurück">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-chevron-left"><path d="m15 18-6-6 6-6"/></svg>
                </button>

                <div class="result-bar-edit-input-wrapper" style="position: relative; display: flex; align-items: center; flex: 1;">
                    <span class="result-bar-edit-sparkle">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sparkles"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
                    </span>
                    <input type="text" class="result-bar-edit-input context-menu-compose-input" ${disabledAttr} placeholder="Text verfassen zu..." value="" style="padding-right: 12px; transition: padding 0.2s ease;" />
                    <span class="compose-websearch-indicator" style="display: none; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); color: ${this.isWebAgentEnabled ? '#4f46e5' : '#dc2626'}; background: ${this.isWebAgentEnabled ? 'rgba(79, 70, 229, 0.1)' : 'rgba(220, 38, 38, 0.12)'}; border: 1px solid ${this.isWebAgentEnabled ? 'rgba(79, 70, 229, 0.2)' : 'rgba(220, 38, 38, 0.45)'}; border-radius: 9999px; cursor: pointer; transition: all 0.2s ease; width: 24px; height: 24px; align-items: center; justify-content: center;" title="${this.isWebAgentEnabled ? 'Web-Suche aktiv' : 'Web-Suche inaktiv'}">
                        ${this.isWebAgentEnabled 
                            ? `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><path d="M2 12h20"/></svg>`
                            : `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" opacity="0.6"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" opacity="0.6"/><path d="M2 12h20" opacity="0.6"/><line x1="2" y1="22" x2="22" y2="2" stroke="var(--background-main, #ffffff)" stroke-width="4.5" stroke-linecap="round" /><line x1="2" y1="22" x2="22" y2="2" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" /></svg>`
                        }
                    </span>
                </div>
                
                <button class="result-bar-icon-btn submit-btn" ${disabledAttr} title="Absenden">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                </button>
            </div>
        `;
    }

    bindSelectionToolbarComposeListeners() {
        const container = this.selectionToolbar;
        
        const submitBtn = container.querySelector('.submit-btn');
        const backBtn = container.querySelector('.result-bar-back-btn');
        const input = container.querySelector('.context-menu-compose-input');
        
        const triggerSubmit = async () => {
            if (input) {
                const promptValue = input.value.trim();
                if (promptValue) {
                    const styleParam = promptValue;
                    await this.processSelectedText(styleParam);
                }
            }
        };

        if (submitBtn) {
            submitBtn.addEventListener('mousedown', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                await triggerSubmit();
            });
        }

        if (backBtn) {
            backBtn.addEventListener('mousedown', (e) => {
                e.preventDefault();
                e.stopPropagation();
                
                if (this.isEditorCurrentlyEmpty) {
                    this.selectionToolbar.style.display = 'none';
                    if (this.selectionTriggerBtn) {
                        this.selectionTriggerBtn.style.display = 'flex';
                    }
                    this.isComposeFlow = false;
                    container.classList.remove('result-mode');
                    return;
                }
                
                container.classList.remove('result-mode');
                this.renderSelectionToolbarHTML();
                this.bindSelectionToolbarListeners();
                
                // Focus the editor back to restore active selection status and prevent auto-hiding
                const markdownTextarea = document.getElementById('createTextMarkdown');
                const isMarkdownMode = markdownTextarea && markdownTextarea.parentElement.style.display !== 'none';
                if (isMarkdownMode) {
                    if (markdownTextarea && this.savedMarkdownSelection) {
                        try {
                            markdownTextarea.focus();
                            markdownTextarea.setSelectionRange(this.savedMarkdownSelection.start, this.savedMarkdownSelection.end);
                        } catch (err) {}
                    }
                } else {
                    if (this.createMde && this.savedTiptapSelection) {
                        try {
                            this.createMde.commands.focus();
                            this.createMde.commands.setTextSelection(this.savedTiptapSelection);
                        } catch (err) {}
                    }
                }
                
                this.updateToolbarPosition();
            });
        }

        if (input) {
            input.addEventListener('input', () => {
                const value = input.value;
                const hasUrl = /https?:\/\/[^\s]+|www\.[^\s]+/i.test(value);
                const hasSearchSlash = /^\/suche(\s|$)/i.test(value.trim());
                const hasTrigger = hasUrl || hasSearchSlash;
                const indicator = container.querySelector('.compose-websearch-indicator');
                if (indicator) {
                    indicator.style.display = hasTrigger ? 'flex' : 'none';
                    input.style.paddingRight = hasTrigger ? '42px' : '12px';
                    
                    if (hasSearchSlash && !this.isWebAgentEnabled) {
                        this.isWebAgentEnabled = true;
                        indicator.style.color = '#4f46e5';
                        indicator.style.background = 'rgba(79, 70, 229, 0.1)';
                        indicator.style.borderColor = 'rgba(79, 70, 229, 0.2)';
                        indicator.title = 'Web-Suche aktiv';
                        indicator.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><path d="M2 12h20"/></svg>`;
                    }
                }
            });
            
            const indicator = container.querySelector('.compose-websearch-indicator');
            if (indicator) {
                indicator.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Toggle state
                    this.isWebAgentEnabled = !this.isWebAgentEnabled;
                    
                    // Update styling of indicator
                    if (this.isWebAgentEnabled) {
                        indicator.style.color = '#4f46e5';
                        indicator.style.background = 'rgba(79, 70, 229, 0.1)';
                        indicator.style.borderColor = 'rgba(79, 70, 229, 0.2)';
                        indicator.title = 'Web-Suche aktiv';
                        indicator.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><path d="M2 12h20"/></svg>`;
                    } else {
                        indicator.style.color = '#dc2626';
                        indicator.style.background = 'rgba(220, 38, 38, 0.12)';
                        indicator.style.borderColor = 'rgba(220, 38, 38, 0.45)';
                        indicator.title = 'Web-Suche inaktiv';
                        indicator.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10" opacity="0.6"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" opacity="0.6"/><path d="M2 12h20" opacity="0.6"/><line x1="2" y1="22" x2="22" y2="2" stroke="var(--background-main, #ffffff)" stroke-width="4.5" stroke-linecap="round" /><line x1="2" y1="22" x2="22" y2="2" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" /></svg>`;
                    }
                });
            }
            input.addEventListener('mousedown', (e) => {
                e.stopPropagation();
            });
            input.addEventListener('keydown', async (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    await triggerSubmit();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (this.isEditorCurrentlyEmpty) {
                        this.selectionToolbar.style.display = 'none';
                        this.isComposeFlow = false;
                        if (this.selectionTriggerBtn) {
                            this.selectionTriggerBtn.style.display = 'none';
                        }
                    } else {
                        container.classList.remove('result-mode');
                        this.renderSelectionToolbarHTML();
                        this.bindSelectionToolbarListeners();
                        
                        // Focus the editor back to restore active selection status and prevent auto-hiding
                        const markdownTextarea = document.getElementById('createTextMarkdown');
                        const isMarkdownMode = markdownTextarea && markdownTextarea.parentElement.style.display !== 'none';
                        if (isMarkdownMode) {
                            if (markdownTextarea && this.savedMarkdownSelection) {
                                try {
                                    markdownTextarea.focus();
                                    markdownTextarea.setSelectionRange(this.savedMarkdownSelection.start, this.savedMarkdownSelection.end);
                                } catch (err) {}
                            }
                        } else {
                            if (this.createMde && this.savedTiptapSelection) {
                                try {
                                    this.createMde.commands.focus();
                                    this.createMde.commands.setTextSelection(this.savedTiptapSelection);
                                } catch (err) {}
                            }
                        }
                        
                        this.updateToolbarPosition();
                    }
                }
            });
            input.addEventListener('focus', () => {
                this.showSelectionHighlight();
            });
        }
    }

    initSelectionToolbar(container) {
        // Create the toolbar DOM node
        this.selectionToolbar = document.createElement('div');
        this.selectionToolbar.id = 'selection-toolbar';
        this.renderSelectionToolbarHTML();
        document.body.appendChild(this.selectionToolbar);

        // Create the trigger button DOM node
        this.selectionTriggerBtn = document.createElement('button');
        this.selectionTriggerBtn.id = 'selection-trigger-btn';
        this.selectionTriggerBtn.title = 'AI Aktionen';
        this.selectionTriggerBtn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pen-line-icon lucide-pen-line"><path d="M13 21h8"/><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/></svg>
        `;
        document.body.appendChild(this.selectionTriggerBtn);

        this.bindSelectionToolbarListeners();

        // Prevent losing selection on trigger button mousedown
        this.selectionTriggerBtn.addEventListener('mousedown', (e) => {
            e.preventDefault();
        });

        // Show selection toolbar on click
        this.selectionTriggerBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.selectionTriggerBtn.style.display = 'none';
            this.selectionToolbar.style.display = 'flex';
            this.updateToolbarPosition();
            
            if (this.isEditorCurrentlyEmpty) {
                this.showComposeInputState();
            }
        });

        // Track when the user is interacting with the toolbar to prevent auto-hiding
        this.isInteractingWithToolbar = false;
        this.selectionToolbar.addEventListener('mousedown', () => {
            this.isInteractingWithToolbar = true;
        });
        document.addEventListener('mouseup', () => {
            setTimeout(() => {
                this.isInteractingWithToolbar = false;
            }, 100);
        });

        this.lastMouseDownPos = { x: 0, y: 0 };
        document.addEventListener('mousedown', (e) => {
            this.lastMouseDownPos = { x: e.clientX, y: e.clientY };
        });

        this.lastMousePos = { x: 0, y: 0 };
        document.addEventListener('mouseup', (e) => {
            this.lastMousePos = { x: e.clientX, y: e.clientY };
            if (this.app.currentMode !== 'create') return;
            const activeEl = document.activeElement;
            if (activeEl && activeEl.id === 'createTextMarkdown') {
                const isToolbarActive = this.selectionToolbar && 
                    (this.selectionToolbar.style.display === 'flex' || 
                     this.selectionToolbar.classList.contains('result-mode') || 
                     this.selectionToolbar.classList.contains('loading'));
                if (isToolbarActive) {
                    setTimeout(() => this.updateToolbarPosition(), 50);
                } else {
                    setTimeout(() => this.updateTriggerPosition(), 50);
                }
            }
        });

        document.addEventListener('keyup', (e) => {
            if (this.app.currentMode !== 'create') return;
            const activeEl = document.activeElement;
            if (activeEl && activeEl.id === 'createTextMarkdown') {
                if (e.key === 'Shift' || e.key.includes('Arrow')) {
                    const isToolbarActive = this.selectionToolbar && 
                        (this.selectionToolbar.style.display === 'flex' || 
                         this.selectionToolbar.classList.contains('result-mode') || 
                         this.selectionToolbar.classList.contains('loading'));
                    if (isToolbarActive) {
                        setTimeout(() => this.updateToolbarPosition(), 50);
                    } else {
                        setTimeout(() => this.updateTriggerPosition(), 50);
                    }
                }
            }
        });

        // Listen for selection changes inside the container
        document.addEventListener('selectionchange', () => {
            if (this.app.currentMode !== 'create') return;
            
            // Check if selection is inside the language selector span to prevent triggering selection toolbar
            const selection = window.getSelection();
            if (selection && selection.anchorNode) {
                const node = selection.anchorNode.nodeType === Node.TEXT_NODE ? selection.anchorNode.parentElement : selection.anchorNode;
                if (node && (node.classList.contains('editor-lang-name') || node.closest('.editor-lang-name'))) {
                    if (this.selectionToolbar && this.selectionToolbar.style.display === 'flex' && !this.selectionToolbar.classList.contains('result-mode')) {
                        this.selectionToolbar.style.display = 'none';
                        this.clearSelectionHighlight();
                        document.body.classList.remove('has-context-menu');
                    }
                    if (this.selectionTriggerBtn) {
                        this.selectionTriggerBtn.style.display = 'none';
                    }
                    return;
                }
            }
            
            const aiContextMenuToggle = document.getElementById('aiContextMenuToggle');
            const isAiContextMenuEnabled = !aiContextMenuToggle || aiContextMenuToggle.checked;
            
            // Keep highlights in sync dynamically when focus changes
            if (this.selectionToolbar && this.selectionToolbar.style.display === 'flex') {
                this.showSelectionHighlight();
            }

            if (this.isUpdatingHistory || this.selectionToolbar.classList.contains('loading') || this.isInteractingWithToolbar) {
                return;
            }

            const activeEl = document.activeElement;
            const isMarkdown = activeEl && activeEl.id === 'createTextMarkdown';
            const isEditor = activeEl && (activeEl.closest('.ProseMirror') || activeEl.closest('.tiptap-container') || isMarkdown);

            // If we are in result-mode and the focus is not in the editor, ignore selection changes
            if (this.selectionToolbar.classList.contains('result-mode') && !isEditor) {
                return;
            }

            // Do NOT hide the menu if the user has focused or clicked inside the selectionToolbar itself!
            if (this.selectionToolbar && (this.selectionToolbar.contains(activeEl) || activeEl?.closest('#selection-toolbar'))) {
                return;
            }

            if (isEditor && isAiContextMenuEnabled) {
                // Check if the selection toolbar is already visible or is in result-mode / loading
                const isToolbarActive = this.selectionToolbar && 
                    (this.selectionToolbar.style.display === 'flex' || 
                     this.selectionToolbar.classList.contains('result-mode') || 
                     this.selectionToolbar.classList.contains('loading'));

                if (isToolbarActive) {
                    if (!isMarkdown) {
                        document.body.classList.add('has-context-menu');
                    }
                    // Throttle positioning slightly
                    setTimeout(() => this.updateToolbarPosition(), 50);
                    if (this.selectionTriggerBtn) {
                        this.selectionTriggerBtn.style.display = 'none';
                    }
                } else {
                    // Show trigger button instead of selection toolbar
                    setTimeout(() => this.updateTriggerPosition(), 50);
                }
            } else if (!this.selectionToolbar.classList.contains('loading') && !this.selectionToolbar.classList.contains('result-mode')) {
                this.selectionToolbar.style.display = 'none';
                this.clearSelectionHighlight();
                document.body.classList.remove('has-context-menu');
                if (this.selectionTriggerBtn) {
                    this.selectionTriggerBtn.style.display = 'none';
                }
            }
        });
        
        document.addEventListener('mousedown', (e) => {
            if (this.app.currentMode !== 'create') return;

            const isSidebarBtn = e.target.closest('.sidebar-btn');
            const isMaximizeBtn = e.target.closest('#maximizeCreateBtn');
            if (isSidebarBtn || isMaximizeBtn) {
                e.preventDefault();
                return;
            }

            if (this.selectionToolbar.style.display === 'flex' && !this.selectionToolbar.contains(e.target) && (!this.selectionTriggerBtn || !this.selectionTriggerBtn.contains(e.target))) {
                if (!this.selectionToolbar.classList.contains('loading')) {
                    this.isCapsulePositionLocked = false;
                    this.isComposeFlow = false;
                    this.selectionToolbar.classList.remove('result-mode');
                    this.selectionToolbar.style.display = 'none';
                    this.clearSelectionHighlight();
                    document.body.classList.remove('has-context-menu');
                }
            }
            if (this.selectionTriggerBtn && this.selectionTriggerBtn.style.display === 'flex' && !this.selectionTriggerBtn.contains(e.target) && !e.target.closest('.tiptap-container') && !e.target.closest('.ProseMirror') && e.target.id !== 'createTextMarkdown') {
                this.selectionTriggerBtn.style.display = 'none';
            }
        });

        // Listen for scroll events globally (capturing style for nested scroll containers)
        this.scrollTicking = false;
        const handleScroll = () => {
            if (this.selectionToolbar && this.selectionToolbar.style.display === 'flex') {
                if (!this.scrollTicking) {
                    window.requestAnimationFrame(() => {
                        this.updateToolbarPosition(true);
                        this.scrollTicking = false;
                    });
                    this.scrollTicking = true;
                }
            } else if (this.selectionTriggerBtn && this.selectionTriggerBtn.style.display === 'flex') {
                if (!this.scrollTicking) {
                    window.requestAnimationFrame(() => {
                        this.updateTriggerPosition();
                        this.scrollTicking = false;
                    });
                    this.scrollTicking = true;
                }
            }
        };
        window.addEventListener('scroll', handleScroll, { capture: true, passive: true });

        // Listen for window resize events to keep the trigger and toolbar correctly aligned
        window.addEventListener('resize', () => {
            if (this.app.currentMode !== 'create') return;
            const isToolbarActive = this.selectionToolbar && 
                (this.selectionToolbar.style.display === 'flex' || 
                 this.selectionToolbar.classList.contains('result-mode') || 
                 this.selectionToolbar.classList.contains('loading'));
            if (isToolbarActive) {
                this.updateToolbarPosition(true);
            } else if (this.selectionTriggerBtn && this.selectionTriggerBtn.style.display === 'flex') {
                this.updateTriggerPosition();
            }
        }, { passive: true });

        // Observe resizes of the editor panel (e.g. when sidebar toggles or panel maximizes)
        const createPanel = document.querySelector('.create-panel');
        if (createPanel) {
            const resizeObserver = new ResizeObserver(() => {
                if (this.app.currentMode !== 'create') return;
                const isToolbarActive = this.selectionToolbar && 
                    (this.selectionToolbar.style.display === 'flex' || 
                     this.selectionToolbar.classList.contains('result-mode') || 
                     this.selectionToolbar.classList.contains('loading'));
                if (isToolbarActive) {
                    this.updateToolbarPosition(true);
                } else if (this.selectionTriggerBtn && this.selectionTriggerBtn.style.display === 'flex') {
                    this.updateTriggerPosition();
                }
            });
            resizeObserver.observe(createPanel);
        }
    }

    updateToolbarPosition(force = false) {
        if (!this.createMde) return;

        const aiContextMenuToggle = document.getElementById('aiContextMenuToggle');
        if (aiContextMenuToggle && !aiContextMenuToggle.checked) {
            if (this.selectionToolbar && !this.selectionToolbar.classList.contains('result-mode') && !this.selectionToolbar.classList.contains('loading')) {
                this.selectionToolbar.style.display = 'none';
                this.clearSelectionHighlight();
                document.body.classList.remove('has-context-menu');
            }
            if (this.selectionTriggerBtn) {
                this.selectionTriggerBtn.style.display = 'none';
            }
            return;
        }

        if (this.isCapsulePositionLocked && !force) return;
        
        const markdownTextarea = document.getElementById('createTextMarkdown');
        const isMarkdown = markdownTextarea && markdownTextarea.parentElement.style.display !== 'none';
        
        const isResultMode = this.selectionToolbar.classList.contains('result-mode');
        
        if (!isResultMode) {
            this.alternativeHistory = null;
            this.alternativePrompts = null;
            this.alternativeTitles = null;
            this.lastActiveHistoryIndex = null;
            this.isComposeFlow = false;
            this.composeStartTiptap = null;
            this.composeStartMarkdown = null;
            this.composeOriginalEndPos = null;
        }
        
        let selectedText = '';
        let markdownSelection = null;
        let tiptapSelection = null;
        let domRange = null;

        if (isMarkdown) {
            selectedText = markdownTextarea.value.substring(markdownTextarea.selectionStart, markdownTextarea.selectionEnd);
            markdownSelection = { start: markdownTextarea.selectionStart, end: markdownTextarea.selectionEnd };
        } else {
            selectedText = window.getSelection().toString();
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                domRange = selection.getRangeAt(0).cloneRange();
            }
            tiptapSelection = {
                from: this.createMde.state.selection.from,
                to: this.createMde.state.selection.to
            };
        }

        let isEditorEmpty = false;
        if (isMarkdown) {
            isEditorEmpty = markdownTextarea && markdownTextarea.value.trim() === '';
        } else {
            isEditorEmpty = this.createMde && this.createMde.isEmpty;
        }
        this.isEditorCurrentlyEmpty = isEditorEmpty;

        // In result-mode, we do NOT auto-dismiss the toolbar when the selection collapses or changes (e.g. from editor formatting or paragraph splits).
        // It must persist until the user explicitly clicks "Fertig" (Done), "Zurücksetzen" (Reset), or "Bearbeiten" (Edit).

        if (isResultMode) {
            if (!selectedText) {
                selectedText = this.savedSelectedText || '';
                markdownSelection = this.savedMarkdownSelection;
                tiptapSelection = this.savedTiptapSelection;
                domRange = this.savedDOMRange;
            }
        } else {
            if ((!selectedText || selectedText.trim() === '') && !isEditorEmpty) {
                const activeEl = document.activeElement;
                if (activeEl && (this.selectionToolbar.contains(activeEl) || activeEl.closest('#selection-toolbar'))) {
                    return;
                }
                this.selectionToolbar.style.display = 'none';
                this.clearSelectionHighlight();
                document.body.classList.remove('has-context-menu');
                if (this.selectionTriggerBtn) {
                    this.selectionTriggerBtn.style.display = 'none';
                }
                return;
            }

            this.savedSelectedText = selectedText;
            this.savedMarkdownSelection = markdownSelection;
            this.savedTiptapSelection = tiptapSelection;
            this.savedDOMRange = domRange;
        }

        // Temporarily set layout to flex and visibility to hidden to measure size accurately
        this.selectionToolbar.style.display = 'flex';
        this.selectionToolbar.style.visibility = 'hidden';
        this.selectionToolbar.classList.remove('loading');
        if (this.selectionTriggerBtn) {
            this.selectionTriggerBtn.style.display = 'none';
        }
        
        // Re-render buttons if they were replaced by loader/spinner
        if (!isResultMode && !this.selectionToolbar.querySelector('.context-menu-input')) {
            this.renderSelectionToolbarHTML();
            this.bindSelectionToolbarListeners();
        }

        let menuWidth = this.selectionToolbar.offsetWidth || 300;
        const menuHeight = this.selectionToolbar.offsetHeight || 420;
        
        if (isEditorEmpty || this.isComposeFlow || isResultMode) {
            menuWidth = 380;
        }
        
        // Restore visibility
        this.selectionToolbar.style.visibility = 'visible';

        const padding = 12;
        const scrollX = window.scrollX || window.pageXOffset;
        const scrollY = window.scrollY || window.pageYOffset;
        const viewportWidth = window.innerWidth;
        
        let idealLeft = 0;
        let idealTop = 0;
        let spaceAbove = 0;
        let selectionBottom = 0;

        if (isMarkdown) {
            const markdownTextarea = document.getElementById('createTextMarkdown');
            let estimatedLeft = 0;
            if (markdownTextarea) {
                const rect = markdownTextarea.getBoundingClientRect();
                const paddingLeft = 56; // 3.5rem left padding
                const charWidth = 9.6; // approx for 16px monospace
                
                if (isEditorEmpty) {
                    idealLeft = rect.left + paddingLeft + scrollX + (menuWidth / 2);
                    idealTop = rect.top + scrollY;
                    spaceAbove = 0; // Force position-bottom
                    selectionBottom = rect.top + 48 + scrollY; // visually places top edge at rect.top + 33 due to margin-top: -15px
                } else {
                    const text = markdownTextarea.value;
                    const startPos = markdownTextarea.selectionStart;
                    const lastNewline = text.lastIndexOf('\n', startPos - 1);
                    const charsBefore = startPos - (lastNewline + 1);
                    
                    const usableWidth = rect.width - paddingLeft - 24; // 1.5rem right padding
                    const maxCharsPerLine = Math.max(1, Math.floor(usableWidth / charWidth));
                    const visualCharsBefore = charsBefore % maxCharsPerLine;
                    
                    estimatedLeft = rect.left + paddingLeft + (visualCharsBefore * charWidth);
                    
                    const selectionLength = Math.abs(markdownTextarea.selectionEnd - markdownTextarea.selectionStart);
                    const offsetFromLeft = Math.min((selectionLength * charWidth) / 2, 80);
                    idealLeft = estimatedLeft + offsetFromLeft + scrollX;
                    
                    let totalVisualLines = 0;
                    const lines = text.substring(0, startPos).split('\n');
                    for (let i = 0; i < lines.length - 1; i++) {
                        const lineChars = lines[i].length;
                        totalVisualLines += Math.max(1, Math.ceil(lineChars / maxCharsPerLine));
                    }
                    totalVisualLines += Math.floor(charsBefore / maxCharsPerLine);
                    
                    const lineHeight = 25.6; // 1.6 * 16px
                    const estimatedTop = rect.top - markdownTextarea.scrollTop + (totalVisualLines * lineHeight);
                    idealTop = estimatedTop - 40 + scrollY;
                    spaceAbove = estimatedTop - 40;
                    selectionBottom = estimatedTop + lineHeight + scrollY;
                }
            } else {
                idealLeft = (this.lastMousePos ? this.lastMousePos.x : 0) + scrollX;
                const mouseY = this.lastMousePos ? this.lastMousePos.y : 0;
                idealTop = mouseY - 40 + scrollY;
                spaceAbove = mouseY - 40;
                selectionBottom = mouseY + 10 + scrollY;
            }
        } else {
            const range = domRange || this.savedDOMRange;
            let rect = null;
            if (range) {
                const rects = range.getClientRects();
                if (rects.length > 0) {
                    rect = range.getBoundingClientRect();
                }
            }
            
            if (rect && rect.width > 0 && rect.height > 0 && !isEditorEmpty) {
                // Align left-ish to trigger button to minimize mouse travel on long selections
                const offsetFromLeft = Math.min(rect.width / 2, 80);
                idealLeft = rect.left + offsetFromLeft + scrollX;
                idealTop = rect.top + scrollY;
                spaceAbove = rect.top;
                selectionBottom = rect.bottom + scrollY;
            } else if (this.createMde && isEditorEmpty) {
                const containerRect = document.getElementById('createText').getBoundingClientRect();
                idealLeft = containerRect.left + 56 + scrollX + (menuWidth / 2);
                idealTop = containerRect.top + 48 + scrollY;
                spaceAbove = 0; // Force position-bottom
                selectionBottom = containerRect.top + 95 + scrollY; // visually places top edge at containerRect.top + 80
            } else if (this.createMde && this.savedTiptapSelection) {
                // Fallback to ProseMirror coordsAtPos if range has no rects
                try {
                    const from = this.savedTiptapSelection.from;
                    const to = this.savedTiptapSelection.to;
                    const startCoords = this.createMde.view.coordsAtPos(from);
                    const endCoords = this.createMde.view.coordsAtPos(to);
                    if (startCoords && endCoords) {
                        const widthEstimate = Math.abs(endCoords.left - startCoords.left);
                        const offsetFromLeft = Math.min(widthEstimate / 2, 80);
                        idealLeft = startCoords.left + offsetFromLeft + scrollX;
                        idealTop = startCoords.top + scrollY;
                        spaceAbove = startCoords.top;
                        selectionBottom = endCoords.bottom + scrollY;
                    }
                } catch (e) {
                    console.warn('[Toolbar Position] coordsAtPos fallback failed:', e);
                }
            }
            
            if (idealLeft === 0 && idealTop === 0) {
                const mouseX = this.lastMousePos ? this.lastMousePos.x : 0;
                const mouseY = this.lastMousePos ? this.lastMousePos.y : 0;
                idealLeft = mouseX + scrollX;
                idealTop = mouseY - 40 + scrollY;
                spaceAbove = mouseY - 40;
                selectionBottom = mouseY + 10 + scrollY;
            }
        }

        let isTallSelection = false;
        let rectForSide = null;
        if (!isMarkdown) {
            const range = domRange || this.savedDOMRange;
            if (range) {
                const rects = range.getClientRects();
                if (rects.length > 0) {
                    rectForSide = range.getBoundingClientRect();
                }
            }
            if (rectForSide && rectForSide.width > 0 && rectForSide.height > 240) {
                isTallSelection = true;
            }
        }

        if (isTallSelection && rectForSide) {
            // Get visible selection bounding box within the editor viewport to align nicely
            const scrollContainer = document.getElementById('createText');
            const containerRect = scrollContainer ? scrollContainer.getBoundingClientRect() : null;
            
            // Center horizontally relative to the visible content text lines rather than the entire document width
            let targetRect = null;
            const range = domRange || this.savedDOMRange;
            const editorVisibleTop = containerRect ? containerRect.top : 0;
            const editorVisibleBottom = containerRect ? containerRect.bottom : window.innerHeight;

            if (range) {
                const rects = range.getClientRects();
                for (let i = 0; i < rects.length; i++) {
                    const r = rects[i];
                    // Filter out zero-width/height rects or tiny carriage returns/spaces
                    if (r.width > 15 && r.height > 5) {
                        // Check if this rect is within the visible viewport of the editor
                        if (r.bottom >= editorVisibleTop && r.top <= editorVisibleBottom) {
                            targetRect = r;
                            break;
                        }
                    }
                }
                
                // If no rect was visible inside the viewport, fallback to the first non-empty rect in the entire selection
                if (!targetRect) {
                    for (let i = 0; i < rects.length; i++) {
                        const r = rects[i];
                        if (r.width > 15 && r.height > 5) {
                            targetRect = r;
                            break;
                        }
                    }
                }
            }

            let idealLeft;
            if (targetRect) {
                const offsetFromLeft = Math.min(targetRect.width / 2, 80);
                idealLeft = targetRect.left + offsetFromLeft + scrollX;
            } else {
                const offsetFromLeft = Math.min(rectForSide.width / 2, 80);
                idealLeft = rectForSide.left + offsetFromLeft + scrollX;
            }
            
            // Bounds check horizontal
            const minLeft = scrollX + (menuWidth / 2) + padding;
            const maxLeft = scrollX + viewportWidth - (menuWidth / 2) - padding;
            idealLeft = Math.max(minLeft, Math.min(maxLeft, idealLeft));
            
            // Position vertically at the top of the visible selection area.
            // If the top of the selection is scrolled out, stick it to the top of the editor content area.
            let visibleTop = rectForSide.top;
            let forceBottom = false;
            
            if (containerRect) {
                const editorVisibleTop = containerRect.top + 10;
                if (rectForSide.top < editorVisibleTop) {
                    visibleTop = editorVisibleTop;
                    forceBottom = true; // Stuck at the top, render BELOW the coordinate (position-bottom)
                }
            }
            
            let finalTop = visibleTop + scrollY;
            
            this.selectionToolbar.classList.remove('position-side');
            
            // If the selection top is off-screen (stuck at top of viewport),
            // render the toolbar BELOW the coordinate (position-bottom) so it remains inside the viewport.
            if (forceBottom || visibleTop < menuHeight + 25) {
                this.selectionToolbar.classList.add('position-bottom');
                this.selectionToolbar.style.top = `${Math.round(finalTop + 15)}px`;
            } else {
                this.selectionToolbar.classList.remove('position-bottom');
                this.selectionToolbar.style.top = `${Math.round(finalTop)}px`;
            }
            
            this.selectionToolbar.style.transform = '';
            this.selectionToolbar.style.marginTop = '';
            this.selectionToolbar.style.left = `${Math.round(idealLeft)}px`;
        } else {
            this.selectionToolbar.classList.remove('position-side');
            this.selectionToolbar.style.transform = '';
            this.selectionToolbar.style.marginTop = '';
            
            // Horizontal bounds check
            const minLeft = scrollX + (menuWidth / 2) + padding;
            const maxLeft = scrollX + viewportWidth - (menuWidth / 2) - padding;
            const finalLeft = Math.max(minLeft, Math.min(maxLeft, idealLeft));

            // Vertical bounds check & flipping
            const threshold = menuHeight + 25; // height + spacing margin
            if (spaceAbove < threshold) {
                this.selectionToolbar.classList.add('position-bottom');
                this.selectionToolbar.style.top = `${Math.round(selectionBottom)}px`;
            } else {
                this.selectionToolbar.classList.remove('position-bottom');
                this.selectionToolbar.style.top = `${Math.round(idealTop)}px`;
            }

            this.selectionToolbar.style.left = `${Math.round(finalLeft)}px`;
        }

        // Ensure the custom selection highlight is visible while the context menu is open
        if (!isMarkdown) {
            this.showSelectionHighlight();
        }
        document.body.classList.add('has-context-menu');
    }

    updateTriggerPosition() {
        if (!this.createMde || !this.selectionTriggerBtn) return;

        const aiContextMenuToggle = document.getElementById('aiContextMenuToggle');
        if (aiContextMenuToggle && !aiContextMenuToggle.checked) {
            this.selectionTriggerBtn.style.display = 'none';
            return;
        }

        const markdownTextarea = document.getElementById('createTextMarkdown');
        const isMarkdown = markdownTextarea && markdownTextarea.parentElement.style.display !== 'none';
        
        let selectedText = '';
        let domRange = null;

        let isEditorEmpty = false;
        if (isMarkdown) {
            selectedText = markdownTextarea.value.substring(markdownTextarea.selectionStart, markdownTextarea.selectionEnd);
            isEditorEmpty = markdownTextarea.value.trim() === '';
        } else {
            selectedText = window.getSelection().toString();
            const selection = window.getSelection();
            if (selection.rangeCount > 0) {
                domRange = selection.getRangeAt(0).cloneRange();
            }
            isEditorEmpty = this.createMde && this.createMde.isEmpty;
        }
        this.isEditorCurrentlyEmpty = isEditorEmpty;

        if ((!selectedText || selectedText.trim() === '') && !isEditorEmpty) {
            this.selectionTriggerBtn.style.display = 'none';
            return;
        }

        const btnWidth = 32;
        const btnHeight = 32;
        const scrollX = window.scrollX || window.pageXOffset;
        const scrollY = window.scrollY || window.pageYOffset;

        let idealLeft = 0;
        let idealTop = 0;
        let spaceAbove = 0;
        let selectionBottom = 0;

        if (isMarkdown) {
            const markdownTextarea = document.getElementById('createTextMarkdown');
            if (markdownTextarea) {
                const rect = markdownTextarea.getBoundingClientRect();
                const paddingLeft = 56;
                const charWidth = 9.6;
                
                if (isEditorEmpty) {
                    idealLeft = rect.left + paddingLeft - btnWidth - 6 + scrollX;
                    idealTop = rect.top - 2 + scrollY;
                } else {
                    const text = markdownTextarea.value;
                    const startPos = markdownTextarea.selectionStart;
                    const lastNewline = text.lastIndexOf('\n', startPos - 1);
                    const charsBefore = startPos - (lastNewline + 1);
                    
                    const usableWidth = rect.width - paddingLeft - 24;
                    const maxCharsPerLine = Math.max(1, Math.floor(usableWidth / charWidth));
                    const visualCharsBefore = charsBefore % maxCharsPerLine;
                    
                    const estimatedLeft = rect.left + paddingLeft + (visualCharsBefore * charWidth);
                    idealLeft = estimatedLeft - btnWidth - 6 + scrollX;
                    
                    let totalVisualLines = 0;
                    const lines = text.substring(0, startPos).split('\n');
                    for (let i = 0; i < lines.length - 1; i++) {
                        const lineChars = lines[i].length;
                        totalVisualLines += Math.max(1, Math.ceil(lineChars / maxCharsPerLine));
                    }
                    totalVisualLines += Math.floor(charsBefore / maxCharsPerLine);
                    
                    const lineHeight = 25.6;
                    const estimatedTop = rect.top - markdownTextarea.scrollTop + (totalVisualLines * lineHeight);
                    idealTop = estimatedTop + (lineHeight / 2) - (btnHeight / 2) + scrollY;
                }
            } else {
                idealLeft = (this.lastMousePos ? this.lastMousePos.x : 0) - btnWidth - 6 + scrollX;
                const mouseY = this.lastMousePos ? this.lastMousePos.y : 0;
                idealTop = mouseY - (btnHeight / 2) + scrollY;
            }
        } else {
            const range = domRange || this.savedDOMRange;
            let rect = null;
            if (range) {
                const rects = range.getClientRects();
                if (rects.length > 0) {
                    rect = range.getBoundingClientRect();
                }
            }
            
            if (rect && rect.width > 0 && rect.height > 0 && !isEditorEmpty) {
                idealLeft = rect.left - btnWidth - 4 + scrollX;
                idealTop = rect.top + (rect.height - btnHeight) / 2 + scrollY;
            } else if (this.createMde && isEditorEmpty) {
                const containerRect = document.getElementById('createText').getBoundingClientRect();
                idealLeft = containerRect.left + 56 - btnWidth - 6 + scrollX;
                idealTop = containerRect.top + 48 - 2 + scrollY;
            } else {
                const mouseX = this.lastMousePos ? this.lastMousePos.x : 0;
                const mouseY = this.lastMousePos ? this.lastMousePos.y : 0;
                idealLeft = mouseX - btnWidth - 6 + scrollX;
                idealTop = mouseY - (btnHeight / 2) + scrollY;
            }
        }

        // Horizontal bounds check: ensure it doesn't go off-screen to the left
        const padding = 8;
        if (idealLeft < scrollX + padding) {
            idealLeft = scrollX + padding;
        }

        this.selectionTriggerBtn.style.left = `${Math.round(idealLeft)}px`;
        this.selectionTriggerBtn.style.top = `${Math.round(idealTop)}px`;
        this.selectionTriggerBtn.style.display = 'flex';
    }

    getAgentTypeAndTitle(actionStyle) {
        let displayTitle = 'Umformulieren';
        let apiType = 'rephrase';

        if (!actionStyle) {
            return { type: apiType, title: displayTitle };
        }

        if (actionStyle.includes('Korrekturlesen')) {
            displayTitle = 'Korrekturlesen';
            apiType = 'proofread';
        } else if (actionStyle.includes('freundlich')) {
            displayTitle = 'Freundlich';
            apiType = 'rephrase';
        } else if (actionStyle.includes('sachlich')) {
            displayTitle = 'Sachlich';
            apiType = 'rephrase';
        } else if (actionStyle.includes('prägnant') || actionStyle.includes('kompakt')) {
            displayTitle = 'Kompakt';
            apiType = 'rephrase';
        } else if (actionStyle.includes('Zusammenfassung') || actionStyle.includes('kürzen') || actionStyle.includes('Wesentliche reduzieren')) {
            displayTitle = 'Kürzen';
            apiType = 'shorten';
        } else if (actionStyle.includes('Hauptpunkten')) {
            displayTitle = 'Hauptpunkte';
            apiType = 'key_points';
        } else if (actionStyle.includes('Liste')) {
            displayTitle = 'Liste';
            apiType = 'list';
        } else if (/tabe|spalt|matrix/i.test(actionStyle)) {
            displayTitle = 'Tabelle';
            apiType = 'table';
        } else if (actionStyle.includes('weiterschreiben') || actionStyle.includes('verfassen')) {
            displayTitle = 'Verfassen';
            apiType = 'compose';
        } else if (actionStyle.includes('ausformulieren')) {
            displayTitle = 'Ausformulieren';
            apiType = 'expand';
        } else if (actionStyle.includes('paraphrasieren')) {
            displayTitle = 'Paraphrasieren';
            apiType = 'paraphrase';
        } else if (actionStyle.length < 20) {
            displayTitle = actionStyle;
        }

        return { type: apiType, title: displayTitle };
    }

    async processSelectedText(actionStyle) {
        if (!this.createMde) return;
        
        // Strip "/suche" command prefix if present
        if (actionStyle && /^\/suche(\s|$)/i.test(actionStyle.trim())) {
            actionStyle = actionStyle.trim().replace(/^\/suche\s*/i, '');
        }
        
        this.clearSelectionHighlight();
        
        const markdownTextarea = document.getElementById('createTextMarkdown');
        const isMarkdownMode = markdownTextarea && markdownTextarea.parentElement.style.display !== 'none';
        
        // Use saved selection if available, fallback to current
        let selectedText = this.savedSelectedText || '';
        let markdownSelection = isMarkdownMode ? this.savedMarkdownSelection : null;
        
        if (!selectedText) {
            if (isMarkdownMode) {
                selectedText = markdownTextarea.value.substring(markdownTextarea.selectionStart, markdownTextarea.selectionEnd);
                markdownSelection = { start: markdownTextarea.selectionStart, end: markdownTextarea.selectionEnd };
            } else {
                selectedText = window.getSelection().toString();
            }
        }
        
        if (!selectedText && !this.isEditorCurrentlyEmpty) return;

        // Retrieve rich content HTML for standard mode to preserve formatting
        let selectedHTML = selectedText;
        let selectedJSON = null;
        if (!isMarkdownMode && this.createMde && this.savedTiptapSelection) {
            try {
                const { from, to } = this.savedTiptapSelection;
                const slice = this.createMde.state.doc.slice(from, to);
                const dummyThis = {
                    state: { doc: { content: slice.content } },
                    schema: this.createMde.schema
                };
                selectedHTML = this.createMde.getHTML.call(dummyThis);
                selectedJSON = slice.content.toJSON();
            } catch (e) {
                console.warn('[Selection HTML] Serialization failed:', e);
                selectedHTML = selectedText;
            }
        }

        // Determine if we are starting a Compose Flow
        const isComposeFlow = this.isComposeFlow || !!((actionStyle && (actionStyle.includes('verfassen') || actionStyle.includes('weiterschreiben'))) ||
            (this.alternativePrompts && this.alternativePrompts.some(p => p && (p.includes('verfassen') || p.includes('weiterschreiben')))));
        this.isComposeFlow = isComposeFlow;

        if (!this.alternativeHistory) {
            this.composeStartTiptap = this.savedTiptapSelection ? { from: this.savedTiptapSelection.from, to: this.savedTiptapSelection.to } : null;
            this.composeStartMarkdown = this.savedMarkdownSelection ? { ...this.savedMarkdownSelection } : null;
            this.composeOriginalEndPos = this.composeStartTiptap ? this.composeStartTiptap.to : null;
        }

        // Hide normal loading state and switch instantly to result-mode loading
        this.selectionToolbar.classList.remove('loading');
        
        // Setup initial display title and agent type
        let agent = this.getAgentTypeAndTitle(actionStyle);
        if (isComposeFlow) {
            agent = { type: 'compose', title: 'Verfassen' };
        }
        this.resultBarTitle = agent.title;

        this.isCapsuleLoading = true;
        this.selectionToolbar.classList.add('result-mode');
        this.renderResultBarHTML();
        this.bindResultBarListeners();
        
        // Show capsule toolbar and position it immediately over the selected text
        this.selectionToolbar.style.display = 'flex';
        this.isCapsulePositionLocked = false;
        this.updateToolbarPosition();
        this.isCapsulePositionLocked = true;

        // Create skeletons using our unified helper
        const skeletonOverlays = this.showSkeletons();

        // If we already have a history context active, we should improve the original text variation (index 0)
        // to maintain quality and avoid repeated double-translation artifacts.
        const textToImprove = (this.alternativeHistory && this.alternativeHistory.length > 0)
            ? ((typeof this.alternativeHistory[0] === 'object') ? this.alternativeHistory[0].text : this.alternativeHistory[0])
            : selectedText;

        try {
            const rawResult = await this.app.languageService.improve({
                text: textToImprove,
                context: null,
                type: agent.type,
                style: actionStyle,
                target_lang: this.app.uiManager.elements.createTargetLang?.value || 'de',
                model: this.app.selectedModel?.id,
                web_search: this.isWebAgentEnabled !== false
            });
            
            if (rawResult.success && rawResult.data && rawResult.data.text) {
                let newText = Array.isArray(rawResult.data.text) ? rawResult.data.text[0] : rawResult.data.text;
                
                console.log('[HAWKI API Result]', {
                    rawText: rawResult.data.text,
                    newTextLength: newText ? newText.length : 0,
                    isComposeFlow,
                    selectedTextLength: selectedText ? selectedText.length : 0
                });

                if (isComposeFlow) {
                    const originalText = (this.alternativeHistory && this.alternativeHistory.length > 0)
                        ? ((typeof this.alternativeHistory[0] === 'object') ? this.alternativeHistory[0].text : this.alternativeHistory[0])
                        : selectedText;
                    
                    const trimmedOriginal = originalText.trim();
                    const trimmedNew = newText.trim();
                    if (!trimmedNew.startsWith(trimmedOriginal) && trimmedOriginal.length > 0) {
                        const separator = originalText.endsWith('\n') ? '\n' : '\n\n';
                        newText = originalText + separator + newText;
                    }
                }

                if (this.alternativeHistory && this.alternativeHistory.length > 0) {
                    // Append subsequent rephrasing steps to the first history context instead of overwriting it
                    this.alternativeHistory.push(newText);
                    
                    if (!this.alternativePrompts) {
                        this.alternativePrompts = [null, this.lastStyleParam || ''];
                    }
                    this.alternativePrompts.push(actionStyle);
                    
                    if (!this.alternativeTitles) {
                        this.alternativeTitles = [null, this.resultBarTitle || 'Umformulieren'];
                    }
                    this.alternativeTitles.push(agent.title);
                    
                    this.currentHistoryIndex = this.alternativeHistory.length - 1;
                } else {
                    // First rephrasing step
                    const originalState = {
                        text: selectedText,
                        html: selectedHTML,
                        json: selectedJSON
                    };
                    this.alternativeHistory = [originalState, newText];
                    this.alternativePrompts = [null, actionStyle];
                    this.alternativeTitles = [null, agent.title];
                    this.currentHistoryIndex = 1;
                }
                
                console.log('[HAWKI History updated]', {
                    alternativeHistoryLength: this.alternativeHistory.length,
                    currentHistoryIndex: this.currentHistoryIndex
                });
                
                this.lastStyleParam = actionStyle;

                // Stop loading state
                this.isCapsuleLoading = false;
                this.renderResultBarHTML();
                this.bindResultBarListeners();

                // Replace the text and visually select it
                this.applyHistoryState(this.currentHistoryIndex);
            }
        } catch (error) {
            console.error('[Selective Processing] AI formatting failed:', error);
            // Revert back to normal context menu
            this.isCapsulePositionLocked = false;
            this.isCapsuleLoading = false;
            this.selectionToolbar.classList.remove('result-mode');
            this.renderSelectionToolbarHTML();
            this.bindSelectionToolbarListeners();
            this.updateToolbarPosition();
        } finally {
            this.clearSkeletons(skeletonOverlays);
        }
    }

    detectCodeLanguage(text) {
        if (typeof text !== 'string') return null;
        const trimmed = text.trim();
        if (!trimmed) return null;

        // Heuristics for Mermaid
        const firstLines = trimmed.split('\n').slice(0, 5).join('\n');
        if (
            /^\s*(graph|flowchart|sequenceDiagram|gantt|classDiagram|stateDiagram-v2|stateDiagram|erDiagram|journey|pie|gitGraph|requirementDiagram|kanban)/.test(firstLines) ||
            (firstLines.includes('---') && (firstLines.includes('kanban') || firstLines.includes('gitGraph')))
        ) {
            return 'mermaid';
        }

        // Heuristics for JSON
        if ((trimmed.startsWith('{') && trimmed.endsWith('}')) || (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
            try {
                JSON.parse(trimmed);
                return 'json';
            } catch (e) {}
        }

        // Heuristics for PHP
        if (trimmed.includes('<?php') || trimmed.includes('namespace App\\') || trimmed.includes('use Illuminate\\')) {
            return 'php';
        }

        // Heuristics for HTML
        if (trimmed.startsWith('<!DOCTYPE html') || /^\s*<[a-zA-Z]+[^>]*>/.test(trimmed)) {
            return 'html';
        }

        // Use lowlight with a subset of common languages for auto-detection
        const subset = [
            'javascript', 'typescript', 'php', 'xml', 'css', 'json', 
            'python', 'sql', 'bash', 'java', 'cpp', 'rust', 'go', 'yaml', 'markdown'
        ];
        try {
            const result = lowlight.highlightAuto(text, { subset });
            let detected = result?.data?.language || result?.language;
            if (detected === 'xml') {
                return 'html';
            }
            return detected || null;
        } catch (e) {
            console.warn('[Auto Language Detection Error]', e);
        }
        return null;
    }

    initTiptapToolbar() {
        const toolbar = document.getElementById('tiptapToolbar');
        const formattingToggle = document.getElementById('formattingToggle');
        const markdownTextarea = document.getElementById('createTextMarkdown');
        const markdownEditorContainer = document.getElementById('markdownEditorContainer');
        const tiptapContainer = document.getElementById('createText');
        
        if (!toolbar || !this.createMde) return;
        
        // Toolbar buttons
        const buttons = toolbar.querySelectorAll('.toolbar-btn');
        buttons.forEach(btn => {
            btn.addEventListener('mousedown', (e) => {
                e.preventDefault();
            });
            btn.addEventListener('click', () => {
                const command = btn.getAttribute('data-command');
                const level = btn.getAttribute('data-level');
                
                if (!command) return;
                
                const isMarkdown = markdownEditorContainer && markdownEditorContainer.style.display !== 'none';
                
                if (isMarkdown) {
                    this.insertMarkdownFormatting(markdownTextarea, command, level);
                    return;
                }
                
                const chain = this.createMde.chain().focus();
                
                switch (command) {
                    case 'toggleHeading':
                        chain.toggleHeading({ level: parseInt(level) }).run();
                        break;
                    case 'toggleBold': chain.toggleBold().run(); break;
                    case 'toggleItalic': chain.toggleItalic().run(); break;
                    case 'toggleStrike': chain.toggleStrike().run(); break;
                    case 'toggleBlockquote': chain.toggleBlockquote().run(); break;
                    case 'toggleBulletList': chain.toggleBulletList().run(); break;
                    case 'toggleOrderedList': chain.toggleOrderedList().run(); break;
                    case 'liftListItem': chain.liftListItem('listItem').run(); break;
                    case 'sinkListItem':
                        if (this.createMde.isActive('listItem')) {
                            chain.sinkListItem('listItem').run();
                        } else {
                            chain.toggleBulletList().run();
                        }
                        break;
                    case 'toggleCode': chain.toggleCode().run(); break;
                    case 'toggleCodeBlock': {
                        if (this.createMde.isActive('codeBlock')) {
                            chain.toggleCodeBlock().run();
                        } else {
                            const { state } = this.createMde;
                            const { from, to } = state.selection;
                            let textToDetect = '';
                            if (from !== to) {
                                textToDetect = state.doc.textBetween(from, to, '\n');
                            } else {
                                const { $from } = state.selection;
                                textToDetect = $from.parent.textContent;
                            }
                            const detected = this.detectCodeLanguage(textToDetect);
                            if (detected) {
                                chain.toggleCodeBlock({ language: detected }).run();
                            } else {
                                chain.toggleCodeBlock().run();
                            }
                        }
                        break;
                    }
                    case 'insertTable': chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(); break;
                }
            });
        });
        
        // Toggle Markdown via Formatting Toggle
        if (formattingToggle && markdownTextarea && tiptapContainer) {
            // Initial sync (in case the toggle state differs on load)
            this.updateFormattingMode = (isFormattingOn) => {
                const isCurrentlyMarkdown = markdownEditorContainer && markdownEditorContainer.style.display !== 'none';
                
                // Toolbar remains visible in both modes
                if (toolbar) {
                    toolbar.style.display = 'flex';
                }

                if (isFormattingOn) {
                    // Switch to Rich Text
                    if (isCurrentlyMarkdown) {
                        const mdValue = markdownTextarea.value;
                        this.createMde.commands.setContent(this.preprocessMarkdown(mdValue), { contentType: 'markdown' });
                        if (markdownEditorContainer) markdownEditorContainer.style.display = 'none';
                        tiptapContainer.style.display = 'flex';
                    }
                } else {
                    // Switch to Markdown
                    if (!isCurrentlyMarkdown) {
                        const mdValue = this.createMde.getMarkdown();
                        markdownTextarea.value = mdValue;
                        tiptapContainer.style.display = 'none';
                        if (markdownEditorContainer) markdownEditorContainer.style.display = 'flex';
                        
                        setTimeout(() => {
                            markdownTextarea.style.height = 'auto';
                            markdownTextarea.style.height = markdownTextarea.scrollHeight + 'px';
                        }, 0);
                    }
                }
                
                this.updatePlaceholderVisibility();
            };

            // Set initial state based on checkbox
            this.updateFormattingMode(formattingToggle.checked);
            
            // Sync markdown changes to app state
            markdownTextarea.addEventListener('input', () => {
                markdownTextarea.style.height = 'auto';
                markdownTextarea.style.height = markdownTextarea.scrollHeight + 'px';
                
                const val = markdownTextarea.value;
                if (this.app.uiManager.elements.createCharCount) {
                    this.app.uiManager.elements.createCharCount.textContent = val.length.toLocaleString();
                }
                const wordCountEl = document.getElementById('createWordCount');
                if (wordCountEl) {
                    const words = val.trim() ? val.trim().split(/\s+/).length : 0;
                    wordCountEl.textContent = words.toLocaleString();
                }
                if (!val.trim()) {
                    this.app.targetSentences = [];
                }
                this.app.saveSession();
                
                this.app.targetSentences = this.app.textProcessor.splitIntoSentences(val);
                this.app.saveSession();
                
                this.updatePlaceholderVisibility();
            });
        }
    }

    updateTiptapToolbarState() {
        const toolbar = document.getElementById('tiptapToolbar');
        if (!toolbar || !this.createMde) return;
        
        const buttons = toolbar.querySelectorAll('.toolbar-btn');
        buttons.forEach(btn => {
            const command = btn.getAttribute('data-command');
            const level = btn.getAttribute('data-level');
            let isActive = false;
            
            switch (command) {
                case 'toggleHeading':
                    isActive = this.createMde.isActive('heading', { level: parseInt(level) });
                    break;
                case 'toggleBold': isActive = this.createMde.isActive('bold'); break;
                case 'toggleItalic': isActive = this.createMde.isActive('italic'); break;
                case 'toggleStrike': isActive = this.createMde.isActive('strike'); break;
                case 'toggleBlockquote': isActive = this.createMde.isActive('blockquote'); break;
                case 'toggleBulletList': isActive = this.createMde.isActive('bulletList'); break;
                case 'toggleOrderedList': isActive = this.createMde.isActive('orderedList'); break;
                case 'toggleCode': isActive = this.createMde.isActive('code'); break;
                case 'toggleCodeBlock': isActive = this.createMde.isActive('codeBlock'); break;
            }
            
            if (isActive) {
                btn.classList.add('is-active');
            } else {
                btn.classList.remove('is-active');
            }
        });
    }

    insertMarkdownFormatting(textarea, command, level) {
        if (!textarea) return;
        
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;
        const selectedText = text.substring(start, end);
        let prefix = '';
        let suffix = '';
        let replaceText = selectedText;
        
        switch (command) {
            case 'toggleHeading':
                prefix = '#'.repeat(parseInt(level)) + ' ';
                break;
            case 'toggleBold':
                prefix = '**'; suffix = '**';
                break;
            case 'toggleItalic':
                prefix = '*'; suffix = '*';
                break;
            case 'toggleStrike':
                prefix = '~~'; suffix = '~~';
                break;
            case 'toggleBlockquote':
                prefix = '> ';
                replaceText = selectedText.replace(/\n/g, '\n> ');
                break;
            case 'toggleBulletList':
                prefix = '- ';
                replaceText = selectedText.replace(/\n/g, '\n- ');
                break;
            case 'toggleOrderedList':
                prefix = '1. ';
                replaceText = selectedText.replace(/\n/g, '\n1. ');
                break;
            case 'liftListItem': {
                if (!selectedText) {
                    const lineStart = text.lastIndexOf('\n', start - 1) + 1;
                    const lineEnd = text.indexOf('\n', end);
                    const currentLine = text.substring(lineStart, lineEnd === -1 ? text.length : lineEnd);
                    
                    let newLine = currentLine;
                    const hasIndent = /^(\t| {2,4})/.test(currentLine);
                    if (hasIndent) {
                        newLine = currentLine.replace(/^(\t| {2,4})/, '');
                    } else {
                        newLine = currentLine.replace(/^(\s*[-*+]\s|\s*\d+\.\s)/, '');
                    }
                    
                    const newText = text.substring(0, lineStart) + newLine + text.substring(lineEnd === -1 ? text.length : lineEnd);
                    textarea.value = newText;
                    textarea.focus();
                    const newCursor = Math.max(lineStart, start - (currentLine.length - newLine.length));
                    textarea.setSelectionRange(newCursor, newCursor);
                    textarea.dispatchEvent(new Event('input'));
                    return;
                } else {
                    const hasIndent = /^(\t| {2,4})/.test(selectedText);
                    if (hasIndent) {
                        prefix = '';
                        replaceText = selectedText.replace(/^(\t| {2,4})/gm, '');
                    } else {
                        prefix = '';
                        replaceText = selectedText.replace(/^(\s*[-*+]\s|\s*\d+\.\s)/gm, '');
                    }
                }
                break;
            }
            case 'sinkListItem': {
                if (!selectedText) {
                    const lineStart = text.lastIndexOf('\n', start - 1) + 1;
                    const lineEnd = text.indexOf('\n', end);
                    const currentLine = text.substring(lineStart, lineEnd === -1 ? text.length : lineEnd);
                    
                    const hasListMarker = /^\s*([-*+]\s|\d+\.\s)/.test(currentLine);
                    let newLine = currentLine;
                    if (hasListMarker) {
                        newLine = '    ' + currentLine;
                    } else {
                        newLine = '- ' + currentLine;
                    }
                    
                    const newText = text.substring(0, lineStart) + newLine + text.substring(lineEnd === -1 ? text.length : lineEnd);
                    textarea.value = newText;
                    textarea.focus();
                    const newCursor = start + (newLine.length - currentLine.length);
                    textarea.setSelectionRange(newCursor, newCursor);
                    textarea.dispatchEvent(new Event('input'));
                    return;
                } else {
                    const hasListMarker = /^\s*([-*+]\s|\d+\.\s)/.test(selectedText);
                    if (hasListMarker) {
                        prefix = '';
                        replaceText = selectedText.replace(/^/gm, '    ');
                    } else {
                        prefix = '';
                        replaceText = selectedText.replace(/^/gm, '- ');
                    }
                }
                break;
            }
            case 'toggleCode':
                prefix = '`'; suffix = '`';
                break;
            case 'toggleCodeBlock': {
                let textToDetect = selectedText;
                let isLineWrapped = false;
                let actualStart = start;
                let actualEnd = end;
                let actualReplaceText = selectedText;

                if (!selectedText) {
                    const beforeCursor = text.substring(0, start);
                    const afterCursor = text.substring(start);
                    const lineStart = beforeCursor.lastIndexOf('\n') + 1;
                    const lineEnd = afterCursor.indexOf('\n');
                    const currentLine = text.substring(lineStart, lineEnd === -1 ? text.length : start + lineEnd);
                    
                    if (currentLine.trim()) {
                        textToDetect = currentLine;
                        actualStart = lineStart;
                        actualEnd = lineEnd === -1 ? text.length : start + lineEnd;
                        actualReplaceText = currentLine;
                        isLineWrapped = true;
                    }
                }

                const detected = this.detectCodeLanguage(textToDetect);
                prefix = `\n\`\`\`${detected || ''}\n`;
                suffix = '\n\`\`\`\n';
                
                if (isLineWrapped) {
                    const newText = text.substring(0, actualStart) + prefix + actualReplaceText + suffix + text.substring(actualEnd);
                    textarea.value = newText;
                    textarea.focus();
                    const newStart = actualStart + prefix.length;
                    textarea.setSelectionRange(newStart, newStart + actualReplaceText.length);
                    textarea.dispatchEvent(new Event('input'));
                    return;
                }
                break;
            }
            case 'insertTable':
                prefix = '\n| Spalte 1 | Spalte 2 | Spalte 3 |\n| --- | --- | --- |\n| Inhalt | Inhalt | Inhalt |\n| Inhalt | Inhalt | Inhalt |\n';
                replaceText = '';
                break;
        }
        
        const newText = text.substring(0, start) + prefix + replaceText + suffix + text.substring(end);
        textarea.value = newText;
        
        // Adjust selection to highlight the text inside the formatting
        textarea.focus();
        textarea.setSelectionRange(start + prefix.length, start + prefix.length + replaceText.length);
        
        // Trigger input event to update state and char counts
        textarea.dispatchEvent(new Event('input'));
    }
}
