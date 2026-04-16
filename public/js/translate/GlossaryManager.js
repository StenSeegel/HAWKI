/**
 * Glossary Manager for HAWKI Translation.
 * Handles Glossary CRUD, Modals, CSV Import, and Sidebar Selection.
 */
import { DOM_IDS, getTranslations, API_ENDPOINTS } from './Constants.js';

export class GlossaryManager {
    constructor(app = null) {
        this.app = app;
        this.t = getTranslations();
        this.state = {
            activeGlossaries: new Set(),
            glossaries: [],
            availableRoles: []
        };
        
        this.isSubmitting = false;
        this.pendingDeleteId = null;
        this.pendingDeleteName = null;

        this.elements = {};
        this.initElements();
        this.attachListeners();
        
        // Load initial data if the button exists
        if (this.elements.glossaryBtn) {
            this.loadGlossaries();
        }
    }

    initElements() {
        const ids = [
            'glossaryBtn', 'glossaryModalOverlay', 'glossaryCloseBtn', 'newGlossaryBtn', 
            'glossaryBackBtn', 'glossaryListView', 'glossaryCreateView', 'glossaryImportView', 
            'importGlossaryBtn', 'importBackBtn', 'glossaryDetailsView', 'glossaryDetailsContent', 
            'detailsCloseBtn', 'detailsEditBtn', 'detailsGlossaryName', 'detailsGlossaryDomain', 
            'glossaryModalTitle', 'createGlossaryBtn', 'submitImportBtn', 'csvDropZone', 
            'csvFileInput', 'csvFileNameDisplay', 'termPairsContainer', 'addTermPairBtn',
            'sidebarGlossarySubview', 'glossarySubviewBackBtn', 'sidebarGlossaryList', 
            'manageGlossariesBtn', 'glossaryCountDisplay', 'glossaryCountBadge',
            'deleteGlossaryModalOverlay', 'deleteGlossaryCloseBtn', 'deleteCancelBtn', 
            'deleteConfirmBtn', 'deleteGlossaryName'
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
    }

    attachListeners() {
        const { elements } = this;

        if (elements.glossaryBtn) {
            elements.glossaryBtn.addEventListener('click', (e) => {
                if (e.target.closest('.toggle-switch')) return;
                e.preventDefault();
                this.openGlossarySubview();
            });
        }

        if (elements.glossarySubviewBackBtn) {
            elements.glossarySubviewBackBtn.addEventListener('click', () => this.closeGlossarySubview());
        }

        if (elements.manageGlossariesBtn && elements.glossaryModalOverlay) {
            elements.manageGlossariesBtn.addEventListener('click', () => {
                elements.glossaryModalOverlay.style.display = 'flex';
            });
        }

        if (elements.glossaryCloseBtn && elements.glossaryModalOverlay) {
            elements.glossaryCloseBtn.addEventListener('click', () => {
                elements.glossaryModalOverlay.style.display = 'none';
                this.resetView();
                this.resetImportForm();
            });
        }

        if (elements.glossaryModalOverlay) {
            elements.glossaryModalOverlay.addEventListener('click', (e) => {
                if (e.target === elements.glossaryModalOverlay) {
                    elements.glossaryModalOverlay.style.display = 'none';
                    this.resetView();
                }
            });
        }

        if (elements.newGlossaryBtn) {
            elements.newGlossaryBtn.addEventListener('click', () => {
                this.resetForm();
                elements.glossaryListView.classList.remove('active');
                elements.glossaryCreateView.classList.add('active');
                elements.glossaryModalTitle.textContent = this.t.NewGlossary || "New glossary";
            });
        }

        if (elements.glossaryBackBtn) {
            elements.glossaryBackBtn.addEventListener('click', () => {
                elements.glossaryCreateView.classList.remove('active');
                elements.glossaryListView.classList.add('active');
                elements.glossaryModalTitle.textContent = this.t.Glossary || "Glossary";
                this.resetForm();
            });
        }

        if (elements.importGlossaryBtn) {
            elements.importGlossaryBtn.addEventListener('click', () => {
                elements.glossaryListView.classList.remove('active');
                elements.glossaryImportView.classList.add('active');
                elements.glossaryModalTitle.textContent = this.t.ImportGlossary || "Glossar importieren";
            });
        }

        if (elements.importBackBtn) {
            elements.importBackBtn.addEventListener('click', () => {
                elements.glossaryImportView.classList.remove('active');
                elements.glossaryListView.classList.add('active');
                elements.glossaryModalTitle.textContent = this.t.Glossary || "Glossary";
                this.resetImportForm();
            });
        }

        if (elements.detailsCloseBtn) {
            elements.detailsCloseBtn.addEventListener('click', () => {
                elements.glossaryDetailsView.classList.remove('active');
                elements.glossaryListView.classList.add('active');
                elements.glossaryModalTitle.textContent = this.t.Glossary || "Glossary";
            });
        }

        this.initImportListeners();
        this.initCreateListeners();
        this.initDeleteListeners();

        // Global listener for mini edit buttons
        document.addEventListener('edit-glossary-requested', (e) => {
            this.editGlossary(e.detail.id);
        });
    }

    // Modal navigation helpers
    openGlossarySubview() {
        if (this.elements.sidebarGlossarySubview) {
            this.elements.sidebarGlossarySubview.style.display = 'flex';
            this.renderSidebarGlossaryList();
        }
    }

    closeGlossarySubview() {
        if (this.elements.sidebarGlossarySubview) {
            this.elements.sidebarGlossarySubview.style.display = 'none';
        }
    }

    resetView() {
        const { elements } = this;
        if (elements.glossaryListView) elements.glossaryListView.classList.add('active');
        if (elements.glossaryCreateView) elements.glossaryCreateView.classList.remove('active');
        if (elements.glossaryImportView) elements.glossaryImportView.classList.remove('active');
        if (elements.glossaryDetailsView) elements.glossaryDetailsView.classList.remove('active');
        if (elements.glossaryModalTitle) elements.glossaryModalTitle.textContent = this.t.Glossary || "Glossary";
    }

    // Import Logic
    initImportListeners() {
        const { elements } = this;
        if (elements.csvDropZone && elements.csvFileInput) {
            elements.csvDropZone.addEventListener('click', () => elements.csvFileInput.click());
            elements.csvFileInput.addEventListener('change', (e) => this.handleFileSelect(e.target.files[0]));
            
            elements.csvDropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                elements.csvDropZone.classList.add('drag-over');
            });
            elements.csvDropZone.addEventListener('dragleave', () => elements.csvDropZone.classList.remove('drag-over'));
            elements.csvDropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                elements.csvDropZone.classList.remove('drag-over');
                const file = e.dataTransfer.files[0];
                if (file && (file.type === 'text/csv' || file.name.endsWith('.csv') || file.name.endsWith('.txt'))) {
                    elements.csvFileInput.files = e.dataTransfer.files;
                    this.handleFileSelect(file);
                }
            });
        }

        if (elements.submitImportBtn) {
            elements.submitImportBtn.addEventListener('click', () => this.submitImport());
        }
    }

    handleFileSelect(file) {
        if (!file) return;
        const { elements } = this;
        if (elements.csvFileNameDisplay) elements.csvFileNameDisplay.textContent = file.name;
        const nameInput = document.getElementById('importGlossaryName');
        if (nameInput && !nameInput.value) {
            nameInput.value = file.name.replace(/\.[^/.]+$/, "");
        }
    }

    resetImportForm() {
        const { elements } = this;
        const nameInput = document.getElementById('importGlossaryName');
        const descInput = document.getElementById('importGlossaryDescription');
        if (nameInput) nameInput.value = '';
        if (descInput) descInput.value = '';
        if (elements.csvFileInput) elements.csvFileInput.value = '';
        if (elements.csvFileNameDisplay) elements.csvFileNameDisplay.textContent = this.t.FormatHint || "Format: Begriff1,Begriff2 (Source,Target)";
        if (elements.submitImportBtn) {
            elements.submitImportBtn.classList.remove('btn-loading');
            elements.submitImportBtn.disabled = false;
        }
    }

    async submitImport() {
        const name = document.getElementById('importGlossaryName').value;
        const description = document.getElementById('importGlossaryDescription').value;
        const sourceLang = document.getElementById('importSourceLang').value;
        const targetLang = document.getElementById('importTargetLang').value;
        const file = this.elements.csvFileInput.files[0];

        if (!name) return alert(this.t.NameRequired || "Name erforderlich");
        if (!file) return alert(this.t.FileRequired || "CSV-Datei erforderlich");

        const formData = new FormData();
        formData.append('file', file);
        formData.append('name', name);
        formData.append('description', description);
        formData.append('source_language', sourceLang);
        formData.append('target_language', targetLang);
        formData.append('visibility', 'private');

        const btn = this.elements.submitImportBtn;
        btn.classList.add('btn-loading');
        btn.disabled = true;

        try {
            const response = await fetch('/req/glossary/import', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: formData
            });

            const data = await response.json();
            if (data.success) {
                await this.loadGlossaries();
                this.elements.glossaryImportView.classList.remove('active');
                this.elements.glossaryListView.classList.add('active');
                this.elements.glossaryModalTitle.textContent = this.t.Glossary || "Glossary";
                this.resetImportForm();
            } else {
                alert((this.t.Error || 'Fehler') + ': ' + data.message);
            }
        } catch (error) {
            console.error('Import failed:', error);
            alert(this.t.ImportFailed || "Import fehlgeschlagen");
        } finally {
            btn.classList.remove('btn-loading');
            btn.disabled = false;
        }
    }

    // CRUD Logic
    async loadGlossaries() {
        try {
            const response = await fetch('/req/glossary', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                }
            });
            const data = await response.json();
            if (data.success) {
                this.state.glossaries = data.data.glossaries;
                this.state.availableRoles = data.data.available_roles || [];
                this.renderGlossaryList(this.state.glossaries);
                this.renderSidebarGlossaryList();
                
                if (this.app) {
                    this.app.restoreSession();
                } else if (window.translateApp) {
                    window.translateApp.restoreSession();
                }
            }
        } catch (error) {
            console.error('Failed to load glossaries:', error);
        }
    }

    renderGlossaryList(glossaries) {
        const { elements } = this;
        if (!elements.glossaryListView) return;
        
        const emptyMsg = elements.glossaryListView.querySelector('.glossary-list-empty');
        let listContainer = elements.glossaryListView.querySelector('.glossary-list-container');
        
        if (!listContainer) {
            listContainer = document.createElement('div');
            listContainer.className = 'glossary-list-container';
            if (emptyMsg) {
                elements.glossaryListView.insertBefore(listContainer, emptyMsg);
            } else {
                elements.glossaryListView.appendChild(listContainer);
            }
        }
        
        listContainer.querySelectorAll('.glossary-item-row').forEach(row => row.remove());

        if (glossaries.length === 0) {
            if (emptyMsg) emptyMsg.style.display = 'block';
            listContainer.style.display = 'none';
            return;
        }

        if (emptyMsg) emptyMsg.style.display = 'none';
        listContainer.style.display = 'block';

        glossaries.forEach(glossary => {
            const row = document.createElement('div');
            row.className = 'sidebar-item glossary-item-row';
            Object.assign(row.style, {
                justifyContent: 'space-between',
                padding: '0.75rem',
                borderRadius: '8px',
                background: 'var(--bg-secondary-color)',
                cursor: 'default'
            });
            
            row.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div class="sidebar-item-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                    </div>
                    <div>
                        <div style="font-weight: 600;">${glossary.display_name}</div>
                        <div style="font-size: 11px; color: var(--text-faded-color);">${glossary.entries_count} ${this.t.Terms || "Begriffe"}</div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button class="visibility-btn visibility-icon" title="${this.getVisibilityLabel(glossary.visibility)}">${this.getVisibilityIcon(glossary.visibility)}</button>
                    ${glossary.can_edit ? `
                    <button class="edit-glossary-btn" data-id="${glossary.id}" title="${this.t.Edit || 'Bearbeiten'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </button>
                    ` : ''}
                    ${glossary.can_delete ? `
                    <button class="delete-glossary-btn" data-id="${glossary.id}" title="${this.t.Delete || 'Löschen'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                    </button>
                    ` : ''}
                </div>
            `;

            row.querySelector('.visibility-btn').addEventListener('click', (e) => {
                e.stopPropagation();
                this.showGlossaryDetails(glossary);
            });

            if (glossary.can_edit) {
                row.querySelector('.edit-glossary-btn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.editGlossary(glossary.id);
                });
            }

            if (glossary.can_delete) {
                row.querySelector('.delete-glossary-btn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.openDeleteModal(glossary.id, glossary.display_name);
                });
            }
            
            listContainer.appendChild(row);
        });
    }

    renderSidebarGlossaryList() {
        const { elements, state } = this;
        if (!elements.sidebarGlossaryList) return;
        
        elements.sidebarGlossaryList.innerHTML = '';
        const updateCounts = () => {
             const text = `${state.activeGlossaries.size}/${state.glossaries.length}`;
             if (elements.glossaryCountDisplay) elements.glossaryCountDisplay.textContent = text;
             if (elements.glossaryCountBadge) elements.glossaryCountBadge.textContent = text;
        };
        updateCounts();

        if (state.glossaries.length === 0) {
            elements.sidebarGlossaryList.innerHTML = `<div style="color: var(--text-faded-color); padding: 1.5rem; text-align: center; font-size: 0.9rem;">${this.t.NoGlossaries || "Keine Glossare vorhanden."}</div>`;
            return;
        }

        state.glossaries.forEach(glossary => {
             const row = document.createElement('label');
             row.className = 'selection-item';
             const isChecked = state.activeGlossaries.has(String(glossary.id));
             if (isChecked) row.classList.add('active');
             
             row.innerHTML = `
                <input type="checkbox" value="${glossary.id}" ${isChecked ? 'checked' : ''}>
                <div class="label">
                    <span style="font-weight: 500; font-size: 0.9rem; color: var(--text-color);">${glossary.display_name}</span>
                    <span style="font-size: 0.75rem; color: var(--text-faded-color); margin-left: 0.5rem;">• ${glossary.entries_count || 0} ${this.t.Terms || "Begriffe"}</span>
                </div>
             `;
             
             const checkbox = row.querySelector('input');
             checkbox.addEventListener('change', (e) => {
                 if (e.target.checked) {
                     state.activeGlossaries.add(String(glossary.id));
                     row.classList.add('active');
                 } else {
                     state.activeGlossaries.delete(String(glossary.id));
                     row.classList.remove('active');
                 }
                 updateCounts();
                 if (this.app) {
                     this.app.updateButtonState();
                     this.app.saveSession();
                 } else if (window.translateApp) {
                     window.translateApp.updateButtonState();
                     window.translateApp.saveSession();
                 }
             });

             elements.sidebarGlossaryList.appendChild(row);
        });
    }

    // Modal Details View
    showGlossaryDetails(glossary) {
        const { elements } = this;
        if (!elements.glossaryDetailsContent || !elements.detailsGlossaryName || !elements.detailsGlossaryDomain) return;

        elements.detailsGlossaryName.textContent = glossary.display_name;
        elements.detailsGlossaryDomain.textContent = glossary.domain || 'GENERAL';

        const termsCountElem = document.getElementById('detailsTermsCount');
        if (termsCountElem) {
            const count = (glossary.entries_count !== undefined && glossary.entries_count !== null) ? glossary.entries_count : (glossary.entries ? glossary.entries.length : 0);
            termsCountElem.textContent = `${count} ${count === 1 ? (this.t.Term || 'Begriff') : (this.t.Terms || 'Begriffe')}`;
        }

        const visibilityLabel = this.getVisibilityLabel(glossary.visibility);

        const formatDate = (dateStr) => {
            if (!dateStr) return '-';
            try {
                const date = new Date(dateStr);
                const options = { month: 'short', day: 'numeric', year: 'numeric' };
                const datePart = date.toLocaleDateString('en-US', options);
                const timePart = date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
                return `${datePart} • ${timePart}`;
            } catch (e) { return dateStr; }
        };

        // Attach global window functions for inline editing (legacy support or keep within class)
        window._gm = this; // Temporary reference for inline onclicks

        elements.glossaryDetailsContent.innerHTML = this.renderDetailsTemplate(glossary, visibilityLabel, formatDate);

        elements.glossaryListView.classList.remove('active');
        elements.glossaryDetailsView.classList.add('active');
        elements.glossaryModalTitle.textContent = this.t.GlossaryDetails || "Glossar Details";
    }

    renderDetailsTemplate(glossary, visibilityLabel, formatDate) {
        return `
            <div class="detail-card">
                <div class="card-header">
                    <span class="card-title">${this.t.Description || 'Beschreibung'}</span>
                    ${(glossary.can_edit) ? `<span class="mini-edit-btn" onclick="_gm.toggleInlineEditDescription(${glossary.id})">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </span>` : ''}
                </div>
                <div class="card-body" id="detailsDescriptionContainer">
                    <p style="margin: 0; color: var(--text-color); font-size: 0.9rem;" id="detailsDescriptionText">${glossary.description || '-'}</p>
                </div>
            </div>
            <div class="detail-card">
                <div class="card-header">
                    <span class="card-title">${this.t.Permissions || 'Permissions'}</span>
                    ${(glossary.can_edit) ? `<span class="mini-edit-btn" onclick="_gm.toggleInlineEditDetails(${glossary.id})">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </span>` : ''}
                </div>
                <div class="card-body detail-grid" id="detailsInfoContainer" style="padding: 0;">
                    <div class="detail-col" style="padding: 1rem;">
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                            <span class="info-label">${this.t.Creator || 'Ersteller'}</span>
                            <span class="info-value">${glossary.creator_name || 'System'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></span>
                            <span class="info-label">${this.t.EditRights || 'Edit Rights'}</span>
                            <span class="info-value">${glossary.editor_role_name || (this.t.OwnerOnly || 'Owner')}</span>
                        </div>
                    </div>
                    <div class="detail-col" style="padding: 1rem;">
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg></span>
                            <span class="info-label">${this.t.Visibility || 'Visibility'}</span>
                            <span class="info-value">${visibilityLabel}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></span>
                            <span class="info-label">${this.t.VisibleFor || 'Sichtbar für'}</span>
                            <span class="info-value">${this.getVisibleForText(glossary)}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="detail-card">
                <div class="card-header"><span class="card-title">${this.t.Meta || 'Meta'}</span></div>
                <div class="card-body detail-grid" style="padding: 0;">
                    <div class="detail-col" style="padding: 1rem;">
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></span>
                            <span class="info-label">${this.t.Created || 'Created'}</span>
                            <span class="info-value" style="white-space: nowrap;">${formatDate(glossary.created_at)}</span>
                        </div>
                    </div>
                    <div class="detail-col" style="padding: 1rem;">
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"></path><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg></span>
                            <span class="info-label">${this.t.Updated || 'Updated'}</span>
                            <span class="info-value" style="white-space: nowrap;">${formatDate(glossary.updated_at)}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    // Inline edit description
    toggleInlineEditDescription(id) {
        const glossary = this.state.glossaries.find(g => g.id === id);
        if (!glossary) return;
        const container = document.getElementById('detailsDescriptionContainer');
        if (!container || container.querySelector('textarea')) return;

        container.innerHTML = `
            <textarea id="descriptionInput" class="text-input" style="min-height: 80px; width: 100%; margin-bottom: 0.5rem; font-size: 0.9rem;">${glossary.description || ''}</textarea>
            <div class="info-row" style="margin-bottom: 1rem; flex-wrap: wrap; gap: 8px;">
                <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg></span>
                <span class="info-label">${this.t.Category || 'Category'}</span>
                <input type="text" id="domainInput" class="styleless-input border" style="width: auto; min-width: 150px; font-size: 0.85rem;" value="${glossary.domain || ''}" placeholder="e.g. GENERAL">
            </div>
            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <button class="btn-xs-stroke" onclick="_gm.renderDetailsContent(${glossary.id})">${this.t.Cancel || 'Abbrechen'}</button>
                <button class="btn-primary" id="saveDescriptionBtn" onclick="_gm.saveInlineDescription(${glossary.id})">${this.t.Save || 'Speichern'}</button>
            </div>
        `;
    }

    async saveInlineDescription(id) {
        const textarea = document.getElementById('descriptionInput');
        const domainInput = document.getElementById('domainInput');
        const saveBtn = document.getElementById('saveDescriptionBtn');
        if (!textarea || !domainInput || !saveBtn) return;

        saveBtn.disabled = true;
        saveBtn.classList.add('btn-loading');

        try {
            const response = await fetch(`/req/glossary/${id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: JSON.stringify({
                    description: textarea.value,
                    domain: domainInput.value
                })
            });

            const data = await response.json();
            if (data.success) {
                const updated = data.data.glossary;
                const idx = this.state.glossaries.findIndex(g => g.id === id);
                if (idx !== -1) this.state.glossaries[idx] = updated;
                this.showGlossaryDetails(updated);
            } else {
                alert(data.message || 'Update failed');
                saveBtn.disabled = false;
                saveBtn.classList.remove('btn-loading');
            }
        } catch (error) {
            console.error('Failed to update description:', error);
            saveBtn.disabled = false;
            saveBtn.classList.remove('btn-loading');
        }
    }

    // Inline edit details (permissions/visibility)
    toggleInlineEditDetails(id) {
        const glossary = this.state.glossaries.find(g => g.id === id);
        if (!glossary) return;
        const container = document.getElementById('detailsInfoContainer');
        if (!container) return;

        const roleOptions = this.state.availableRoles.map(role => `
            <option value="${role.id}" ${parseInt(glossary.organization_id) === parseInt(role.id) ? 'selected' : ''}>${role.name}</option>
        `).join('');
        const editorRoleOptions = this.state.availableRoles.map(role => `
            <option value="${role.slug}" ${glossary.editor_role === role.slug ? 'selected' : ''}>${role.name}</option>
        `).join('');

        container.innerHTML = `
            <div class="detail-col" style="padding: 1rem;">
                <div class="info-row" style="flex-wrap: wrap; gap: 8px;">
                    <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                    <span class="info-label">${this.t.Creator || 'Ersteller'}</span>
                    <span class="info-value">${glossary.creator_name || 'System'}</span>
                </div>
                <div class="info-row" id="editRightsRow" style="display: ${glossary.visibility === 'private' ? 'none' : 'flex'}; flex-wrap: wrap; gap: 8px;">
                    <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></span>
                    <span class="info-label">${this.t.EditRights || 'Edit Rights'}</span>
                    <select id="editRightsSelect" class="styleless-select border" style="width: auto; min-width: 120px;">
                        <option value="">${this.t.OwnerOnly || 'Owner'}</option>
                        ${editorRoleOptions}
                    </select>
                </div>
            </div>
            <div class="detail-col" style="padding: 1rem;">
                <div class="info-row" style="flex-wrap: wrap; gap: 8px;">
                    <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg></span>
                    <span class="info-label">${this.t.Visibility || 'Visibility'}</span>
                    <select id="visibilitySelect" class="styleless-select border" onchange="_gm.toggleOrganizationSelect(this.value)">
                        <option value="private" ${glossary.visibility === 'private' ? 'selected' : ''}>${this.t.Private || 'Privat'}</option>
                        <option value="org" ${glossary.visibility === 'org' ? 'selected' : ''}>${this.t.Organization || 'Organisation'}</option>
                        <option value="public" ${glossary.visibility === 'public' ? 'selected' : ''}>${this.t.Public || 'Öffentlich'}</option>
                    </select>
                </div>
                <div class="info-row" id="orgSelectRow" style="display: ${glossary.visibility === 'org' ? 'flex' : 'none'}; flex-wrap: wrap; gap: 8px;">
                    <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></span>
                    <span class="info-label">${this.t.VisibleFor || 'Sichtbar für'}</span>
                    <select id="organizationSelect" class="styleless-select border">
                        <option value="">${this.t.SelectRole || 'Rolle auswählen'}</option>
                        ${roleOptions}
                    </select>
                </div>
            </div>
            <div class="detail-footer" style="grid-column: 1 / -1; display: flex; gap: 8px; justify-content: flex-end; padding: 1rem;">
                <button class="btn-xs-stroke" onclick="_gm.renderDetailsContent(${glossary.id})">${this.t.Cancel || 'Abbrechen'}</button>
                <button class="btn-primary" id="saveDetailsBtn" onclick="_gm.saveInlineDetails(${glossary.id})">${this.t.Save || 'Speichern'}</button>
            </div>
        `;
    }

    toggleOrganizationSelect(visibility) {
        const orgRow = document.getElementById('orgSelectRow');
        const editRow = document.getElementById('editRightsRow');
        const editSelect = document.getElementById('editRightsSelect');
        if (orgRow) orgRow.style.display = visibility === 'org' ? 'flex' : 'none';
        if (editRow && editSelect) {
            if (visibility === 'private') {
                editSelect.value = '';
                editRow.style.display = 'none';
            } else {
                editRow.style.display = 'flex';
            }
        }
    }

    async saveInlineDetails(id) {
        const saveBtn = document.getElementById('saveDetailsBtn');
        const visibility = document.getElementById('visibilitySelect').value;
        const organization_id = document.getElementById('organizationSelect').value;
        const editor_role = document.getElementById('editRightsSelect').value;

        if (!saveBtn) return;
        saveBtn.disabled = true;
        saveBtn.classList.add('btn-loading');

        try {
            const response = await fetch(`/req/glossary/${id}`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: JSON.stringify({ visibility, organization_id, editor_role })
            });

            const data = await response.json();
            if (data.success) {
                const updated = data.data.glossary;
                const idx = this.state.glossaries.findIndex(g => g.id === id);
                if (idx !== -1) this.state.glossaries[idx] = updated;
                this.showGlossaryDetails(updated);
            } else {
                alert(data.message || 'Update failed');
                saveBtn.disabled = false;
                saveBtn.classList.remove('btn-loading');
            }
        } catch (error) {
            console.error('Failed to update details:', error);
            saveBtn.disabled = false;
            saveBtn.classList.remove('btn-loading');
        }
    }

    renderDetailsContent(id) {
        const glossary = this.state.glossaries.find(g => g.id === id);
        if (glossary) this.showGlossaryDetails(glossary);
    }

    // Edit/Update Logic
    async editGlossary(id) {
        try {
            const response = await fetch('/req/glossary/' + id, {
                 headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                }
            });
            const data = await response.json();
            if (data.success) {
                const glossary = data.data.glossary;
                const { elements } = this;
                elements.glossaryListView.classList.remove('active');
                elements.glossaryCreateView.classList.add('active');
                elements.glossaryModalTitle.textContent = this.t.EditGlossary || 'Glossar bearbeiten';
                
                document.getElementById('newGlossaryName').value = glossary.display_name;
                const descInput = document.getElementById('newGlossaryDescription');
                if (descInput) descInput.value = glossary.description || '';
                
                elements.createGlossaryBtn.dataset.mode = 'edit';
                elements.createGlossaryBtn.dataset.id = glossary.id;
                elements.createGlossaryBtn.dataset.visibility = glossary.visibility;
                elements.createGlossaryBtn.textContent = this.t.Update || 'Aktualisieren';
                
                elements.termPairsContainer.innerHTML = '';
                glossary.entries.forEach(entry => this.addTermRow(entry));
                if (glossary.entries.length === 0) this.addTermRow();
            }
        } catch (error) {
            console.error('Failed to load glossary details:', error);
        }
    }

    initCreateListeners() {
        const { elements } = this;
        if (elements.addTermPairBtn) {
            elements.addTermPairBtn.addEventListener('click', () => this.addTermRow());
        }
        if (elements.createGlossaryBtn) {
            elements.createGlossaryBtn.addEventListener('click', () => this.submitCreateOrUpdate());
        }
    }

    addTermRow(data = null) {
        const row = document.createElement('div');
        row.className = 'term-pair-row';
        Object.assign(row.style, { display: 'flex', gap: '10px', marginBottom: '10px' });
        
        row.innerHTML = `
            <div class="term-pair-inputs">
                <select class="styleless-select border">
                    <option value="DE" ${data && data.source_language === 'DE' ? 'selected' : ''}>DE</option>
                    <option value="EN" ${data && data.source_language === 'EN' ? 'selected' : ''}>EN</option>
                </select>
                <input type="text" class="term-input" placeholder="${this.t.SourceTerm || 'Ausgangsbegriff'}" value="${data ? data.source_term : ''}">
            </div>
            <span style="color: var(--text-faded-color);">→</span>
            <div class="term-pair-inputs">
                <select class="styleless-select border">
                    <option value="EN" ${data && data.target_language === 'EN' ? 'selected' : ''}>EN</option>
                    <option value="DE" ${data && data.target_language === 'DE' ? 'selected' : ''}>DE</option>
                </select>
                <input type="text" class="term-input" placeholder="${this.t.TargetTerm || 'Zielbegriff'}" value="${data ? data.target_term : ''}">
            </div>
            <button class="delete-term-btn" title="${this.t.RemoveTermPair || 'Begriffspaar entfernen'}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
            </button>
        `;
        
        row.querySelector('.delete-term-btn').addEventListener('click', () => {
            if (this.elements.termPairsContainer.querySelectorAll('.term-pair-row').length > 1) {
                row.remove();
            } else {
                row.querySelectorAll('input').forEach(i => i.value = '');
            }
        });
        
        this.elements.termPairsContainer.appendChild(row);
    }

    async submitCreateOrUpdate() {
        if (this.isSubmitting) return;
        const name = document.getElementById('newGlossaryName').value;
        if (!name) return alert(this.t.NameRequired || 'Name erforderlich');
        
        const terms = [];
        this.elements.termPairsContainer.querySelectorAll('.term-pair-row').forEach(row => {
            const inputs = row.querySelectorAll('input');
            const selects = row.querySelectorAll('select');
            if (inputs[0].value && inputs[1].value) {
                 terms.push({
                    source_language: selects[0].value,
                    source_term: inputs[0].value,
                    target_language: selects[1].value,
                    target_term: inputs[1].value,
                    case_sensitive: false
                });
            }
        });

        if (terms.length === 0) return alert(this.t.TermPairRequired || 'Mindestens ein Begriffspaar erforderlich');
        
        const { createGlossaryBtn } = this.elements;
        const mode = createGlossaryBtn.dataset.mode || 'create';
        const id = createGlossaryBtn.dataset.id;
        const method = mode === 'edit' ? 'PUT' : 'POST';
        const url = mode === 'edit' ? `/req/glossary/${id}` : '/req/glossary';

        this.isSubmitting = true;
        const originalText = createGlossaryBtn.textContent;
        createGlossaryBtn.classList.add('btn-loading');

        try {
            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                },
                body: JSON.stringify({
                    name,
                    description: document.getElementById('newGlossaryDescription')?.value || '',
                    visibility: createGlossaryBtn.dataset.visibility || 'private',
                    terms
                })
            });

            const data = await response.json();
            if (data.success) {
                createGlossaryBtn.classList.remove('btn-loading');
                createGlossaryBtn.textContent = mode === 'edit' ? (this.t.Updated || 'Aktualisiert') : (this.t.Created || 'Erstellt');
                createGlossaryBtn.style.backgroundColor = '#10b981';
                
                setTimeout(() => {
                    createGlossaryBtn.textContent = originalText;
                    createGlossaryBtn.style.backgroundColor = '';
                    this.isSubmitting = false;
                    this.resetForm(); 
                    this.elements.glossaryCreateView.classList.remove('active');
                    this.elements.glossaryListView.classList.add('active');
                    this.elements.glossaryModalTitle.textContent = this.t.Glossary || "Glossary";
                    this.loadGlossaries();
                }, 1000);
            } else {
                createGlossaryBtn.classList.remove('btn-loading');
                this.isSubmitting = false;
                alert((this.t.Error || 'Fehler') + ': ' + data.message);
            }
        } catch (error) {
            createGlossaryBtn.classList.remove('btn-loading');
            this.isSubmitting = false;
            console.error('Failed to create/update glossary:', error);
        }
    }

    resetForm() {
        const { createGlossaryBtn } = this.elements;
        if (createGlossaryBtn) {
            createGlossaryBtn.dataset.mode = 'create';
            createGlossaryBtn.dataset.id = '';
            createGlossaryBtn.dataset.visibility = '';
            createGlossaryBtn.textContent = 'Erstellen'; 
        }
        const nameInput = document.getElementById('newGlossaryName');
        const descInput = document.getElementById('newGlossaryDescription');
        if (nameInput) nameInput.value = '';
        if (descInput) descInput.value = '';
        if (this.elements.termPairsContainer) {
            this.elements.termPairsContainer.innerHTML = '';
            this.addTermRow(); 
        }
    }

    // Delete Logic
    initDeleteListeners() {
        const { elements } = this;
        if (elements.deleteGlossaryCloseBtn) elements.deleteGlossaryCloseBtn.addEventListener('click', () => this.closeDeleteModal());
        if (elements.deleteCancelBtn) elements.deleteCancelBtn.addEventListener('click', () => this.closeDeleteModal());
        if (elements.deleteConfirmBtn) {
            elements.deleteConfirmBtn.addEventListener('click', () => {
                if (this.pendingDeleteId) {
                    this.executeDeleteGlossary(this.pendingDeleteId);
                    this.closeDeleteModal();
                }
            });
        }
        if (elements.deleteGlossaryModalOverlay) {
            elements.deleteGlossaryModalOverlay.addEventListener('click', (e) => {
                if (e.target === elements.deleteGlossaryModalOverlay) this.closeDeleteModal();
            });
        }
    }

    openDeleteModal(id, name) {
        this.pendingDeleteId = id;
        this.pendingDeleteName = name;
        const modalTitle = this.elements.deleteGlossaryModalOverlay.querySelector('.glossary-modal-header h3');
        if (modalTitle) {
            modalTitle.textContent = (this.t.DeleteGlossaryTitle || "Glossar löschen: :name").replace(':name', name);
        }
        this.elements.deleteGlossaryModalOverlay.style.display = 'flex';
    }

    closeDeleteModal() {
        this.elements.deleteGlossaryModalOverlay.style.display = 'none';
        this.pendingDeleteId = null;
        this.pendingDeleteName = null;
    }

    async executeDeleteGlossary(id) {
        try {
            const response = await fetch('/req/glossary/' + id, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                }
            });
            if (response.ok) this.loadGlossaries();
        } catch (error) {
            console.error('Failed to delete glossary:', error);
        }
    }

    // Helpers
    getVisibilityLabel(vis) {
        switch(vis) {
            case 'public': return this.t.Public || 'Öffentlich';
            case 'org': return this.t.Organization || 'Organisation';
            case 'team': return this.t.Team || 'Team';
            default: return this.t.Private || 'Privat';
        }
    }

    getVisibilityIcon(vis) {
        switch(vis) {
            case 'public': return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>';
            case 'org': return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>';
            case 'team': return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>';
            default: return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>';
        }
    }

    getVisibleForText(glossary) {
        if (glossary.visibility === 'public') return this.t.All || 'Alle';
        if (glossary.visibility === 'private') return this.t.OnlyYou || 'nur für dich';
        if (glossary.visibility === 'org') return glossary.organization_name || this.t.Organization || 'Organisation';
        return this.t.Team || 'Team';
    }
}
