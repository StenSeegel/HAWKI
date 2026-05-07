import { Utils } from './Utils.js';

export class HistoryManager {
    constructor(app) {
        this.app = app;
    }

    getLocalTranscriptionHistory() {
        try {
            return JSON.parse(localStorage.getItem("transcriptionHistory")) || [];
        } catch (e) {
            return [];
        }
    }

    setLocalTranscriptionHistory(history) {
        localStorage.setItem("transcriptionHistory", JSON.stringify(history));
    }

    saveTranscriptToHistory(text, slug = null, serverTitle = null, segments = null) {
        const timestamp = new Date().toLocaleString();
        const id = slug || `transcript-${Date.now()}`;
        const title = serverTitle || `Transkription vom ${timestamp}`;
        const nowIso = new Date().toISOString();
        const entry = { id, title, content: text, slug: slug, segments: segments, created_at_local: nowIso, updated_at_local: nowIso };
        let history = this.getLocalTranscriptionHistory();
        history.unshift(entry);
        this.setLocalTranscriptionHistory(history);
    }

    filterHistory() {
        const query = (document.getElementById('history-search')?.value || '').trim().toLowerCase();
        const list = document.getElementById('chats-list');
        if (!list) return;

        const children = Array.from(list.children);
        let currentCategory = null;
        let categoryHasVisibleEntries = false;

        const flushCategoryVisibility = () => {
            if (currentCategory) {
                currentCategory.classList.toggle('hidden', !categoryHasVisibleEntries);
            }
        };

        children.forEach((node) => {
            if (node.classList.contains('history-category')) {
                flushCategoryVisibility();
                currentCategory = node;
                categoryHasVisibleEntries = false;
                return;
            }

            if (node.classList.contains('history-entry')) {
                const label = node.querySelector('.label');
                const title = (label?.textContent || '').toLowerCase();
                const isVisible = query === '' || title.includes(query);
                node.classList.toggle('hidden', !isVisible);
                if (isVisible) categoryHasVisibleEntries = true;
            }
        });

        flushCategoryVisibility();
    }

    async renderHistory() {
        console.log("📊 renderHistory aufgerufen");
        if (!document.getElementById('transcript-sidebar')) {
            return;
        }
        const list = document.getElementById("chats-list");
        if (!list) return;

        const renderSeq = ++this.app.state.transcriptHistoryRenderSeq;
        const previousHtml = list.innerHTML;

        let historyItems = [];
        let serverHistoryLoaded = false;
        let serverRequestFailed = false;

        try {
            const data = await Utils.fetchJsonWithRetry('/req/transcriptions', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (data.success && data.transcriptions) {
                serverHistoryLoaded = true;
                historyItems = data.transcriptions.map(t => ({
                    id: t.slug,
                    slug: t.slug,
                    title: t.title,
                    fromServer: true,
                    created_at: t.created_at,
                    updated_at: t.updated_at
                }));
            }
        } catch (error) {
            serverRequestFailed = true;
            console.warn('Konnte Transkripte nicht vom Server laden:', error);
        }

        const localHistory = this.getLocalTranscriptionHistory();
        if (serverHistoryLoaded) {
            const localOnlyEntries = localHistory.filter(entry => !entry || !entry.slug);
            localOnlyEntries.forEach(entry => {
                if (!historyItems.find(h => h.id === entry.id)) {
                    historyItems.push({ ...entry, fromServer: false });
                }
            });
            this.setLocalTranscriptionHistory(localOnlyEntries);
        } else {
            localHistory.forEach(entry => {
                if (!historyItems.find(h => h.id === entry.id)) {
                    historyItems.push({ ...entry, fromServer: false });
                }
            });
        }

        const template = document.getElementById('selection-item-template');
        if (!template) {
            console.error('Selection item template not found');
            return;
        }

        historyItems.sort((a, b) => {
            const aDate = Utils.parseHistoryEntryDate(a);
            const bDate = Utils.parseHistoryEntryDate(b);
            return (bDate?.getTime() || 0) - (aDate?.getTime() || 0);
        });

        if (renderSeq !== this.app.state.transcriptHistoryRenderSeq) {
            return;
        }

        if (historyItems.length === 0 && serverRequestFailed && previousHtml.trim() !== '') {
            console.warn('History refresh failed, keeping previous sidebar list.');
            return;
        }

        list.innerHTML = '';
        let currentGroup = null;

        historyItems.forEach(entry => {
            const entryDate = Utils.parseHistoryEntryDate(entry);
            const groupKey = Utils.getHistoryGroupKey(entryDate);
            if (groupKey !== currentGroup) {
                currentGroup = groupKey;
                const category = document.createElement('div');
                category.className = 'date-separator';
                category.textContent = Utils.getHistoryGroupLabel(groupKey);
                list.appendChild(category);
            }

            const clone = template.content.cloneNode(true);
            const wrapper = clone.querySelector(".selection-item");
            const label = clone.querySelector(".label");

            wrapper.classList.add("history-entry");
            wrapper.classList.remove("hidden");

            wrapper.setAttribute('slug', entry.slug || entry.id);
            wrapper.setAttribute('data-id', entry.id);
            wrapper.setAttribute('data-server', entry.fromServer);
            label.textContent = entry.title;

            wrapper.onclick = (e) => {
                if (e.target.closest('.burger-btn') || e.target.closest('.title-edit-wrapper')) return;
                this.loadTranscript(wrapper);
            };

            wrapper.oncontextmenu = (e) => e.preventDefault();
            list.appendChild(clone);
        });
        
        this.filterHistory();
        console.log(`📊 renderHistory abgeschlossen. ${historyItems.length} Items gerendert.`);
    }

    async loadTranscript(target, fromServer = null) {
        console.log("📖 loadTranscript aufgerufen");
        try {
            let rawSegments = [];
            let rawText = '';
            let content = null;
            let id = null;
            let title = null;
            let activeItem = null;

            if (target instanceof HTMLElement) {
                activeItem = target.closest('.selection-item');
                if (activeItem) {
                    id = activeItem.getAttribute('slug');
                    fromServer = activeItem.getAttribute('data-server') === 'true';
                }
            } else {
                id = target;
                activeItem = document.querySelector(`.selection-item[slug="${id}"]`);
            }

            if (!id) {
                throw new Error('Missing transcription id/slug');
            }

            document.querySelectorAll('#chats-list .selection-item').forEach(item => item.classList.remove('active'));
            if (activeItem) activeItem.classList.add('active');

            let allowLocalFallback = !fromServer;
            if (fromServer) {
                try {
                    const data = await Utils.fetchJsonWithRetry(`/req/transcription/${id}`, {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    }, 1);
                    if (!data.success || !data.transcription) {
                        throw new Error('Server returned no transcription payload');
                    }
                    const trans = data.transcription;
                    title = trans.title;
                    rawSegments = Utils.normalizeSegments(trans.segments);
                    rawText = rawSegments.map(s => s.text?.trim() || '').join(' ');
                    content = this.app.processor.formatTranscriptionWithSpeakers(rawSegments, rawText);
                } catch (error) {
                    if (error?.status === 404 || error?.status === 410) {
                        const history = this.getLocalTranscriptionHistory();
                        this.setLocalTranscriptionHistory(history.filter(e => e.id !== id && e.slug !== id));
                        allowLocalFallback = false;
                    } else {
                        allowLocalFallback = true;
                    }
                    console.warn('Fehler beim Laden vom Server:', error);
                }
            }

            if (!content && allowLocalFallback) {
                const history = this.getLocalTranscriptionHistory();
                const entry = history.find(e => e.id === id || e.slug === id);
                if (entry) {
                    title = entry.title;
                    rawSegments = Utils.normalizeSegments(entry.segments);
                    rawText = Utils.normalizeTranscriptText(entry.content);
                    content = this.app.processor.formatTranscriptionWithSpeakers(rawSegments, rawText);
                }
            }

            if (!content) {
                console.error('Transkription konnte nicht geladen werden');
                return;
            }

            this.app.state.currentTranscriptSegments = rawSegments;
            this.app.state.currentTranscriptText = rawText;
            this.app.state.currentTranscriptSlug = id;
            this.app.state.reorderModeActive = false;
            this.app.state.transcriptUndoStack = [];
            
            this.app.processor.updateUndoButtonState();

            const resDiv = document.getElementById('transcription-result');
            if (resDiv) resDiv.innerHTML = content;

            const titleDiv = document.getElementById('current-transcript-title');
            if (titleDiv) {
                if (title) {
                    titleDiv.textContent = title;
                    titleDiv.classList.remove('hidden');
                } else {
                    titleDiv.textContent = '';
                    titleDiv.classList.add('hidden');
                }
            }

            this.app.ui.switchTranscriptView('view-transcript');
            this.app.ui.toggleSidebarMenu('edit');

            const downloadBtn = document.getElementById('download-transcript-btn');
            if (downloadBtn) {
                downloadBtn.onclick = () => {
                    const text = resDiv ? resDiv.innerText : '';
                    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = `transkription-${id || 'export'}.txt`;
                    a.click();
                    URL.revokeObjectURL(url);
                };
            }
        } catch (error) {
            console.error('Unerwarteter Fehler beim Laden der Transkription:', error);
        }
    }

    async editTranscriptionTitle() {
        if (typeof window.closeBurgerMenus === 'function') window.closeBurgerMenus();
        
        const burgerMenu = document.getElementById('quick-actions');
        let slugOrId = burgerMenu ? burgerMenu.getAttribute('data-room-slug') : null;
        let activeItem = null;
        let label = null;

        if (slugOrId) {
            activeItem = document.querySelector(`.selection-item[slug="${slugOrId}"]`);
            if (activeItem) label = activeItem.querySelector('.label');
        }

        if (!label) {
            activeItem = document.querySelector('.selection-item.active');
            if (activeItem) {
                label = activeItem.querySelector('.label');
                slugOrId = activeItem.getAttribute('slug');
            }
        }

        if (!activeItem || !label) return;

        const originalText = label.textContent;
        const isFromServer = activeItem.getAttribute('data-server') === 'true';
        const id = activeItem.getAttribute('data-id');

        const wrapper = document.createElement('div');
        wrapper.className = 'title-edit-wrapper';

        const input = Object.assign(document.createElement('input'), {
            value: originalText,
            className: 'title-edit-input',
            maxLength: 35,
            onclick: (e) => e.stopPropagation(),
            onkeydown: (e) => {
                if (e.key === 'Enter') confirmBtn.click();
                if (e.key === 'Escape') cancelBtn.click();
            }
        });

        const confirmBtn = document.createElement('button');
        confirmBtn.className = 'btn-xs title-edit-confirm';
        const confirmTmpl = document.getElementById('tmpl-history-confirm-btn');
        if (confirmTmpl) confirmBtn.appendChild(confirmTmpl.content.cloneNode(true));
        confirmBtn.onclick = async (e) => {
            e.stopPropagation();
            document.removeEventListener('click', outsideClickHandler);
            const title = input.value.trim() || originalText;

            if (title !== originalText) {
                if (isFromServer) {
                    try {
                        await fetch(`/req/transcription/${slugOrId}/title`, {
                            method: 'PATCH',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            },
                            body: JSON.stringify({ title })
                        });
                        label.textContent = title;
                    } catch (err) { console.error(err); }
                } else {
                    let history = this.getLocalTranscriptionHistory();
                    const entry = history.find(e => e.id === id);
                    if (entry) {
                        entry.title = title;
                        this.setLocalTranscriptionHistory(history);
                    }
                    label.textContent = title;
                }
            }
            wrapper.replaceWith(label);
        };
        
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn-xs title-edit-cancel';
        const cancelTmpl = document.getElementById('tmpl-history-cancel-btn');
        if (cancelTmpl) cancelBtn.appendChild(cancelTmpl.content.cloneNode(true));
        
        const outsideClickHandler = (e) => {
            if (!wrapper.contains(e.target)) cancelBtn.click();
        };

        cancelBtn.onclick = (e) => {
            e.stopPropagation();
            wrapper.replaceWith(label);
            document.removeEventListener('click', outsideClickHandler);
        };

        wrapper.appendChild(input);
        wrapper.appendChild(confirmBtn);
        wrapper.appendChild(cancelBtn);
        label.replaceWith(wrapper);
        input.focus();
        input.select();
        if (typeof window.closeBurgerMenus === 'function') window.closeBurgerMenus();
        setTimeout(() => document.addEventListener('click', outsideClickHandler), 0);
    }

    async requestDeleteTranscription() {
        if (typeof window.closeBurgerMenus === 'function') window.closeBurgerMenus();
        
        const burgerMenu = document.getElementById('quick-actions');
        let slugOrId = burgerMenu ? burgerMenu.getAttribute('data-room-slug') : null;
        let activeItem = null;

        if (slugOrId) activeItem = document.querySelector(`.selection-item[slug="${slugOrId}"]`);
        if (!activeItem) {
            activeItem = document.querySelector('.selection-item.active');
            if (activeItem) slugOrId = activeItem.getAttribute('slug');
        }
        if (!activeItem) return;

        if (typeof window.openModal === 'function' && typeof window.ModalType !== 'undefined') {
            const confirmed = await window.openModal(window.ModalType.WARNING, window.translation?.DeleteChat || "Diesen Eintrag wirklich löschen?");
            if (!confirmed) return;
        } else if (!confirm("Diesen Eintrag wirklich löschen?")) {
            return;
        }

        const isFromServer = activeItem.getAttribute('data-server') === 'true';
        const id = activeItem.getAttribute('data-id');

        if (isFromServer) {
            await fetch(`/req/transcription/${slugOrId}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
            });
        } else {
            let history = this.getLocalTranscriptionHistory();
            this.setLocalTranscriptionHistory(history.filter(e => e.id !== id));
        }

        const wasActive = activeItem.classList.contains('active');
        activeItem.remove();
        if (wasActive) this.app.ui.showTranscriptChoice();
    }
}
