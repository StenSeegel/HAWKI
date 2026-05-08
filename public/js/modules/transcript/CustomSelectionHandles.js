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
            .custom-drag-handle:hover {
                transform: translate(-50%, -50%) scale(1.3);
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
        
        const startDrag = (e, handleType) => {
            if (!this.app.state.reorderModeActive) return;
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
            if (!this.isDragging || !this.app.state.reorderModeActive) return;
            
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

            if (caretNode && caretNode.nodeType === 3) {
                const segItem = caretNode.parentElement ? caretNode.parentElement.closest('.transcript-seg-item') : null;
                const speakerSegment = caretNode.parentElement ? caretNode.parentElement.closest('.transcript-segment') : null;
                
                if (!segItem || !speakerSegment) return; // Prevent selecting text outside the transcript items

                const newRange = document.createRange();
                if (this.activeHandle === 'start') {
                    const endSpeakerSegment = range.endContainer.parentElement ? range.endContainer.parentElement.closest('.transcript-segment') : null;
                    if (speakerSegment !== endSpeakerSegment) return; // Prevent crossing speaker boundaries

                    try {
                        newRange.setStart(caretNode, caretOffset);
                        newRange.setEnd(range.endContainer, range.endOffset);
                        selection.removeAllRanges();
                        selection.addRange(newRange);
                    } catch(e) { /* ignore invalid range */ }
                } else {
                    const startSpeakerSegment = range.startContainer.parentElement ? range.startContainer.parentElement.closest('.transcript-segment') : null;
                    if (speakerSegment !== startSpeakerSegment) return; // Prevent crossing speaker boundaries

                    try {
                        newRange.setStart(range.startContainer, range.startOffset);
                        newRange.setEnd(caretNode, caretOffset);
                        selection.removeAllRanges();
                        selection.addRange(newRange);
                    } catch(e) { /* ignore invalid range */ }
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
        if (!this.app.state.reorderModeActive || this.isDragging) {
            // Keep handles visible and don't reposition them constantly while dragging 
            // wait, selectionchange fires during drag, so we DO want to reposition them!
            // if we hide them, they disappear during drag.
        }

        if (!this.app.state.reorderModeActive) {
            this.hide();
            return;
        }

        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || selection.toString().trim() === '') {
            this.hide();
            return;
        }

        const range = selection.getRangeAt(0);
        const rects = range.getClientRects();
        
        if (rects.length === 0) {
            this.hide();
            return;
        }

        const firstRect = rects[0];
        const lastRect = rects[rects.length - 1];

        // Place handles vertically outside the text, no horizontal gap
        this.startHandle.style.left = `${firstRect.left + window.scrollX}px`;
        this.startHandle.style.top = `${firstRect.top + window.scrollY - 6}px`;
        
        this.endHandle.style.left = `${lastRect.right + window.scrollX}px`;
        this.endHandle.style.top = `${lastRect.bottom + window.scrollY + 6}px`;

        this.startHandle.classList.remove('hidden');
        this.endHandle.classList.remove('hidden');
    }

    hide() {
        this.startHandle.classList.add('hidden');
        this.endHandle.classList.add('hidden');
    }
}
