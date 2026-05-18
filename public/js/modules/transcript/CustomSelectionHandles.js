export class CustomSelectionHandles {
    constructor(app) {
        this.app = app;
        this.startHandle = document.createElement('div');
        this.startHandle.className = 'custom-drag-handle start hidden';
        this.endHandle = document.createElement('div');
        this.endHandle.className = 'custom-drag-handle end hidden';
        
        document.body.appendChild(this.startHandle);
        document.body.appendChild(this.endHandle);
        
        this.isDragging = false;
        this.activeHandle = null;
        
        this.bindEvents();
        this.injectStyles();
    }

    injectStyles() {
        if (document.getElementById('custom-drag-styles')) return;
        const style = document.createElement('style');
        style.id = 'custom-drag-styles';
        style.innerHTML = `
            .custom-drag-handle {
                position: absolute;
                width: 14px;
                height: 14px;
                background-color: var(--primary-color, #007aff);
                border-radius: 50%;
                cursor: pointer;
                z-index: 9999;
                transform: translate(-50%, -50%);
                box-shadow: 0 1px 3px rgba(0,0,0,0.3);
                transition: transform 0.1s;
                touch-action: none;
            }
            .custom-drag-handle.start::after {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                width: 2px;
                height: 28px;
                background-color: var(--primary-color, #007aff);
                transform: translateX(-50%);
                pointer-events: none;
            }
            .custom-drag-handle.end::after {
                content: '';
                position: absolute;
                bottom: 50%;
                left: 50%;
                width: 2px;
                height: 28px;
                background-color: var(--primary-color, #007aff);
                transform: translateX(-50%);
                pointer-events: none;
            }
            .custom-drag-handle.hidden {
                display: none;
            }
            #transcription-result ::selection {
                background-color: rgba(0, 122, 255, 0.25) !important;
                color: inherit !important;
            }
        `;
        document.head.appendChild(style);
    }

    bindEvents() {
        document.addEventListener('selectionchange', () => this.updateHandles());
        // Listen to scroll events on any container (capture phase)
        document.addEventListener('scroll', () => {
            if (this.app.state.editModeActive && !this.isDragging) {
                this.updateHandles();
            }
        }, true);
        
        const startDrag = (e, handleType) => {
            if (!this.app.state.editModeActive) return;
            this.isDragging = true;
            this.activeHandle = handleType;
            e.preventDefault();
            e.stopPropagation();
        };

        this.startHandle.addEventListener('mousedown', e => startDrag(e, 'start'));
        this.endHandle.addEventListener('mousedown', e => startDrag(e, 'end'));
        this.startHandle.addEventListener('touchstart', e => startDrag(e.touches[0], 'start'), {passive: false});
        this.endHandle.addEventListener('touchstart', e => startDrag(e.touches[0], 'end'), {passive: false});

        const moveDrag = (e) => {
            if (!this.isDragging || !this.app.state.editModeActive) return;
            
            const clientX = e.clientX !== undefined ? e.clientX : e.touches[0].clientX;
            const clientY = e.clientY !== undefined ? e.clientY : e.touches[0].clientY;

            const selection = window.getSelection();
            if (!selection || selection.rangeCount === 0) return;
            const range = selection.getRangeAt(0);

            let caretNode, caretOffset;
            if (document.caretPositionFromPoint) {
                const pos = document.caretPositionFromPoint(clientX, clientY);
                if (pos) { caretNode = pos.offsetNode; caretOffset = pos.offset; }
            } else if (document.caretRangeFromPoint) {
                const cRange = document.caretRangeFromPoint(clientX, clientY);
                if (cRange) { caretNode = cRange.startContainer; caretOffset = cRange.startOffset; }
            }

            if (caretNode) {
                // If it's an element, try to find a text node inside it
                if (caretNode.nodeType === 1) {
                    const walker = document.createTreeWalker(caretNode, NodeFilter.SHOW_TEXT, null, false);
                    let foundText = walker.nextNode();
                    if (foundText) {
                        caretNode = foundText;
                        caretOffset = caretOffset === 0 ? 0 : foundText.length;
                    }
                }
                
                if (caretNode.nodeType === 3) {
                    const speakerSegment = caretNode.parentElement ? caretNode.parentElement.closest('.transcript-segment') : null;
                    
                    if (!speakerSegment) return;

                    const newRange = document.createRange();
                    if (this.activeHandle === 'start') {
                        const endSpeakerSegment = range.endContainer.parentElement ? range.endContainer.parentElement.closest('.transcript-segment') : null;
                        if (speakerSegment !== endSpeakerSegment) return;

                        try {
                            newRange.setStart(caretNode, caretOffset);
                            newRange.setEnd(range.endContainer, range.endOffset);
                            selection.removeAllRanges();
                            selection.addRange(newRange);
                        } catch(e) {}
                    } else {
                        const startSpeakerSegment = range.startContainer.parentElement ? range.startContainer.parentElement.closest('.transcript-segment') : null;
                        if (speakerSegment !== startSpeakerSegment) return;

                        try {
                            newRange.setStart(range.startContainer, range.startOffset);
                            newRange.setEnd(caretNode, caretOffset);
                            selection.removeAllRanges();
                            selection.addRange(newRange);
                        } catch(e) {}
                    }
                }
            }
        };

        document.addEventListener('mousemove', moveDrag);
        document.addEventListener('touchmove', moveDrag, {passive: false});

        const endDrag = () => {
            if (this.isDragging) {
                this.isDragging = false;
                this.activeHandle = null;
            }
        };

        document.addEventListener('mouseup', endDrag);
        document.addEventListener('touchend', endDrag);
    }

    updateHandles() {
        if (!this.app.state.editModeActive || this.isDragging) {
            // Keep handles visible and don't reposition them constantly while dragging 
            // wait, selectionchange fires during drag, so we DO want to reposition them!
            // if we hide them, they disappear during drag.
        }

        if (!this.app.state.editModeActive) {
            this.hide();
            return;
        }

        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || selection.toString().trim() === '') {
            this.hide();
            return;
        }

        const range = selection.getRangeAt(0);

        // Ensure handles only appear for selection inside .transcript-text
        let commonAncestor = range.commonAncestorContainer;
        if (commonAncestor.nodeType === 3) {
            commonAncestor = commonAncestor.parentNode;
        }
        
        if (!commonAncestor || !commonAncestor.closest || !commonAncestor.closest('.transcript-text')) {
            this.hide();
            return;
        }

        const rects = range.getClientRects();
        
        if (rects.length === 0) {
            this.hide();
            return;
        }

        const firstRect = rects[0];
        const lastRect = rects[rects.length - 1];

        const container = commonAncestor.closest('.transcript-view-content');
        const containerRect = container ? container.getBoundingClientRect() : null;

        // Place handles vertically outside the text, no horizontal gap
        this.startHandle.style.left = `${firstRect.left + window.scrollX}px`;
        this.startHandle.style.top = `${firstRect.top + window.scrollY - 6}px`;
        
        this.endHandle.style.left = `${lastRect.right + window.scrollX}px`;
        this.endHandle.style.top = `${lastRect.bottom + window.scrollY + 6}px`;

        if (containerRect) {
            const BUFFER = 25; // Account for handle visual height
            // Hide handles if they scroll out of the visible container area
            if ((firstRect.top - BUFFER) < containerRect.top || firstRect.bottom > containerRect.bottom) {
                this.startHandle.classList.add('hidden');
            } else {
                this.startHandle.classList.remove('hidden');
            }

            if (lastRect.top < containerRect.top || (lastRect.bottom + BUFFER) > containerRect.bottom) {
                this.endHandle.classList.add('hidden');
            } else {
                this.endHandle.classList.remove('hidden');
            }

            const toolbar = document.getElementById('selection-toolbar');
            if (toolbar) {
                if (firstRect.top - BUFFER < containerRect.top || firstRect.bottom > containerRect.bottom) {
                    toolbar.style.display = 'none';
                } else {
                    toolbar.style.display = 'flex';
                    toolbar.style.top = `${firstRect.top + window.scrollY}px`;
                    toolbar.style.left = `${firstRect.left + (firstRect.width / 2)}px`;
                }
            }
        } else {
            this.startHandle.classList.remove('hidden');
            this.endHandle.classList.remove('hidden');
        }
    }

    hide() {
        this.startHandle.classList.add('hidden');
        this.endHandle.classList.add('hidden');
    }
}
