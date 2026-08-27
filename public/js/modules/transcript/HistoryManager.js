import { Utils } from './Utils.js';

const WORKSPACE_TITLE_HINT = (window.translation?.TranscriptEditTitleHint ?? 'Klicken, um den Titel zu bearbeiten');
const WORKSPACE_SUBTITLE_HINT = (window.translation?.TranscriptEditSubtitleHint ?? 'Klicken, um die Unterzeile zu bearbeiten');
const WORKSPACE_SUBTITLE_PLACEHOLDER = (window.translation?.TranscriptSubtitlePlaceholder ?? 'Ergebnisprotokoll bereit zur Prüfung');

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

    saveTranscriptToHistory(text, slug = null, serverTitle = null, segments = null, metadata = null) {
        const timestamp = new Date().toLocaleString();
        const id = slug || `transcript-${Date.now()}`;
        const title = serverTitle || (window.translation?.TranscriptDefaultTitle ?? 'Transkription vom {timestamp}').replace('{timestamp}', timestamp);
        const nowIso = new Date().toISOString();
        const entry = { id, title, content: text, slug: slug, segments: segments, metadata: metadata, created_at_local: nowIso, updated_at_local: nowIso };
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
            let metadata = null;

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
                    metadata = trans.metadata;
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
                    metadata = entry.metadata;
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
            this.app.state.currentTranscriptMetadata = metadata;
            this.app.state.transcriptUndoStack = [];
            this.app.state.summaryGenerated = false;

            // Restore speaker color map from metadata if available
            this.app.state.speakerColorMap = new Map();
            if (metadata && metadata.speaker_color_map) {
                Object.entries(metadata.speaker_color_map).forEach(([key, val]) => {
                    this.app.state.speakerColorMap.set(key, val);
                });
            }
            
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

            const subtitle = (metadata && typeof metadata.subtitle === 'string') ? metadata.subtitle : '';
            this.app.state.currentTranscriptSubtitle = subtitle;
            this.renderWorkspaceSubtitle(subtitle);

            this.app.ui.switchTranscriptView('view-transcript');
            this.app.ui.switchTab('vorschau');

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

    /**
     * Inline-Umbenennung des Workspace-Titels (#current-transcript-title).
     * Enter oder Blur bestätigt, Escape verwirft die Änderung.
     */
    editWorkspaceTranscriptTitle(event) {
        if (event) event.stopPropagation();

        const target = document.getElementById('current-transcript-title');
        if (!target || target.querySelector('input')) return;

        const slug = this.app.state.currentTranscriptSlug;
        if (!slug) return;

        const originalTitle = target.textContent.trim();
        let finished = false;

        // Setzt den Titel als reinen Text zurück, damit der Export weiterhin
        // sauberes textContent erhält (keine verschachtelten Elemente).
        const restoreTitle = (title) => {
            finished = true;
            target.innerHTML = Utils.escapeHTML(title);
            target.setAttribute('title', WORKSPACE_TITLE_HINT);
            target.onclick = (e) => this.editWorkspaceTranscriptTitle(e);
        };

        const commit = async () => {
            if (finished) return;
            const newTitle = input.value.trim();
            if (!newTitle || newTitle === originalTitle) {
                restoreTitle(originalTitle);
                return;
            }

            restoreTitle(newTitle);
            const saved = await this.saveTranscriptionTitle(slug, newTitle);
            if (!saved) restoreTitle(originalTitle);
        };

        target.onclick = null;
        target.removeAttribute('title');
        target.innerHTML = '';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'transcript-title-input';
        input.value = originalTitle;
        input.maxLength = 255;
        input.onclick = (e) => e.stopPropagation();
        input.onkeydown = (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                commit();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                restoreTitle(originalTitle);
            }
        };
        input.onblur = () => commit();

        target.appendChild(input);
        input.focus();
        // Caret at the end instead of select(): the highlight over the
        // transparent background made the existing title unreadable.
        input.setSelectionRange(input.value.length, input.value.length);
    }

    /**
     * Persistiert einen neuen Titel und synchronisiert lokale History,
     * Sidebar-Eintrag und den Inline-Titel.
     */
    async saveTranscriptionTitle(slug, title) {
        const sidebarItem = document.querySelector(`.selection-item[slug="${slug}"]`);
        const isFromServer = sidebarItem ? sidebarItem.getAttribute('data-server') === 'true' : true;

        if (isFromServer) {
            try {
                const response = await fetch(`/req/transcription/${slug}/title`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ title })
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok || result.success === false) {
                    throw new Error(result.error || result.message || `HTTP ${response.status}`);
                }
            } catch (err) {
                console.error('Failed to update transcription title:', err);
                this.app.ui.errorDialog((window.translation?.TranscriptTitleSaveFailed ?? 'Der Titel konnte nicht gespeichert werden.'));
                return false;
            }
        }

        let history = this.getLocalTranscriptionHistory();
        const entry = history.find(e => e.slug === slug || e.id === slug);
        if (entry) {
            entry.title = title;
            this.setLocalTranscriptionHistory(history);
        }

        // Sidebar gezielt aktualisieren, nur als Fallback komplett neu rendern
        const label = sidebarItem ? sidebarItem.querySelector('.label') : null;
        if (label) {
            label.textContent = title;
            this.filterHistory();
        } else {
            this.renderHistory();
        }

        const titleDivInline = document.getElementById('current-transcript-title-inline');
        if (titleDivInline) titleDivInline.textContent = title;

        return true;
    }

    /**
     * Zeigt die Unterzeile an (#current-transcript-subtitle). Ohne gespeicherte
     * Unterzeile bleibt der neutrale Platzhalter stehen. Der Status-Punkt liegt
     * außerhalb dieses Elements und wird nie überschrieben.
     */
    renderWorkspaceSubtitle(subtitle) {
        const target = document.getElementById('current-transcript-subtitle');
        if (!target) return;

        target.classList.remove('is-generating');
        target.innerHTML = Utils.escapeHTML(subtitle || WORKSPACE_SUBTITLE_PLACEHOLDER);
        target.setAttribute('title', WORKSPACE_SUBTITLE_HINT);
        target.onclick = (e) => this.editWorkspaceTranscriptSubtitle(e);
    }

    /**
     * Inline-Bearbeitung der Unterzeile (#current-transcript-subtitle).
     * Enter oder Blur bestätigt, Escape verwirft die Änderung.
     */
    editWorkspaceTranscriptSubtitle(event) {
        if (event) event.stopPropagation();

        const target = document.getElementById('current-transcript-subtitle');
        if (!target || target.querySelector('input')) return;

        const slug = this.app.state.currentTranscriptSlug;
        if (!slug) return;

        // Der Platzhalter ist kein Inhalt, deshalb wird der gespeicherte Wert
        // aus dem State genommen und nicht der angezeigte Text.
        const originalSubtitle = this.app.state.currentTranscriptSubtitle || '';
        let finished = false;

        const restoreSubtitle = (subtitle) => {
            finished = true;
            this.renderWorkspaceSubtitle(subtitle);
        };

        const commit = async () => {
            if (finished) return;
            const newSubtitle = input.value.trim();
            if (newSubtitle === originalSubtitle) {
                restoreSubtitle(originalSubtitle);
                return;
            }

            this.app.state.currentTranscriptSubtitle = newSubtitle;
            restoreSubtitle(newSubtitle);
            const saved = await this.saveTranscriptionSubtitle(slug, newSubtitle);
            if (!saved) {
                this.app.state.currentTranscriptSubtitle = originalSubtitle;
                restoreSubtitle(originalSubtitle);
            }
        };

        target.onclick = null;
        target.removeAttribute('title');
        target.classList.remove('is-generating');
        target.innerHTML = '';

        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'transcript-subtitle-input';
        input.value = originalSubtitle;
        input.placeholder = WORKSPACE_SUBTITLE_PLACEHOLDER;
        input.maxLength = 255;
        input.onclick = (e) => e.stopPropagation();
        input.onkeydown = (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                commit();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                restoreSubtitle(originalSubtitle);
            }
        };
        input.onblur = () => commit();

        target.appendChild(input);
        input.focus();
        // Caret at the end instead of select(): the highlight over the
        // transparent background made the existing subtitle unreadable.
        input.setSelectionRange(input.value.length, input.value.length);
    }

    /**
     * Persistiert die Unterzeile in metadata['subtitle'] und hält die lokale
     * History synchron.
     */
    async saveTranscriptionSubtitle(slug, subtitle) {
        const sidebarItem = document.querySelector(`.selection-item[slug="${slug}"]`);
        const isFromServer = sidebarItem ? sidebarItem.getAttribute('data-server') === 'true' : true;

        if (isFromServer) {
            try {
                const response = await fetch(`/req/transcription/${slug}/subtitle`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ subtitle })
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok || result.success === false) {
                    throw new Error(result.error || result.message || `HTTP ${response.status}`);
                }
            } catch (err) {
                console.error('Failed to update transcription subtitle:', err);
                this.app.ui.errorDialog((window.translation?.TranscriptSubtitleSaveFailed ?? 'Die Unterzeile konnte nicht gespeichert werden.'));
                return false;
            }
        }

        this.storeSubtitleInMetadata(slug, subtitle);

        return true;
    }

    /**
     * Schreibt die Unterzeile in die lokale History und in die Metadaten der
     * aktiven Transkription, damit ein Tab-Wechsel sie nicht verliert.
     */
    storeSubtitleInMetadata(slug, subtitle) {
        const history = this.getLocalTranscriptionHistory();
        const entry = history.find(e => e.slug === slug || e.id === slug);
        if (entry) {
            entry.metadata = { ...(entry.metadata || {}), subtitle };
            this.setLocalTranscriptionHistory(history);
        }

        if (this.app.state.currentTranscriptSlug === slug) {
            this.app.state.currentTranscriptMetadata = {
                ...(this.app.state.currentTranscriptMetadata || {}),
                subtitle
            };
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
            const confirmed = await window.openModal(window.ModalType.WARNING, window.translation?.TranscriptConfirmDeleteEntry ?? 'Diesen Eintrag wirklich löschen?');
            if (!confirmed) return;
        } else if (!confirm(window.translation?.TranscriptConfirmDeleteEntry ?? 'Diesen Eintrag wirklich löschen?')) {
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
