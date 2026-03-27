// Translate Application Logic

document.addEventListener('DOMContentLoaded', () => {
    const glossaryBtn = document.getElementById('glossary-btn');
    const modalOverlay = document.getElementById('glossaryModalOverlay');
    const closeBtn = document.getElementById('glossaryCloseBtn');
    const newGlossaryBtn = document.getElementById('newGlossaryBtn');
    const backBtn = document.getElementById('glossaryBackBtn');
    const listView = document.getElementById('glossaryListView');
    const createView = document.getElementById('glossaryCreateView');
    const importView = document.getElementById('glossaryImportView');
    const importGlossaryBtn = document.getElementById('importGlossaryBtn');
    const importBackBtn = document.getElementById('importBackBtn');
    const detailsView = document.getElementById('glossaryDetailsView');
    const detailsContent = document.getElementById('glossaryDetailsContent');
    const detailsCloseBtn = document.getElementById('detailsCloseBtn');
    const detailsEditBtn = document.getElementById('detailsEditBtn');
    const detailsGlossaryName = document.getElementById('detailsGlossaryName');
    const detailsGlossaryDomain = document.getElementById('detailsGlossaryDomain');
    const modalTitle = document.getElementById('glossaryModalTitle');
    
    // Fallback translations if window.TranslationData is missing
    const t = window.TranslationData || {};

    const createGlossaryBtn = document.getElementById('createGlossaryBtn');
    const submitImportBtn = document.getElementById('submitImportBtn');
    const csvDropZone = document.getElementById('csvDropZone');
    const csvFileInput = document.getElementById('csvFileInput');
    const csvFileNameDisplay = document.getElementById('csvFileNameDisplay');
    const termPairsContainer = document.getElementById('termPairsContainer');
    const addTermPairBtn = document.getElementById('addTermPairBtn');

    // Subview Elements
    const glossarySubview = document.getElementById('sidebarGlossarySubview');
    const glossarySubviewBackBtn = document.getElementById('glossarySubviewBackBtn');
    const sidebarGlossaryList = document.getElementById('sidebarGlossaryList');
    const manageGlossariesBtn = document.getElementById('manageGlossariesBtn');
    const glossaryCountDisplay = document.getElementById('glossaryCountDisplay');
    const glossaryCountBadge = document.getElementById('glossaryCountBadge');

    // State
    const state = {
        activeGlossaries: new Set(), // Store IDs of active glossaries
        glossaries: [],
        availableRoles: [] // Store roles for dropdowns
    };

    // Toggle Subview (Old: Toggle Modal)
    if(glossaryBtn) {
        glossaryBtn.addEventListener('click', (e) => {
            // Prevent if clicking toggle inside sidebar item (if it existed, but we removed it)
            if (e.target.closest('.toggle-switch')) return;
            
            e.preventDefault();
            openGlossarySubview();
        });
    }

    // Subview Navigation
    if(glossarySubviewBackBtn) {
        glossarySubviewBackBtn.addEventListener('click', closeGlossarySubview);
    }
    
    if(manageGlossariesBtn) {
        manageGlossariesBtn.addEventListener('click', () => {
             // Open Modal for management
             modalOverlay.style.display = 'flex';
        });
    }

    function openGlossarySubview() {
        if(glossarySubview) {
            glossarySubview.style.display = 'flex';
            renderSidebarGlossaryList();
        }
    }

    function closeGlossarySubview() {
        if(glossarySubview) {
            glossarySubview.style.display = 'none';
        }
    }


    // Model Selector Submenu Elements
    const modelSelectorBtn = document.getElementById('model-selector-btn');
    const modelSubview = document.getElementById('sidebarModelSubview');
    const modelSubviewBackBtn = document.getElementById('modelSubviewBackBtn');
    const sidebarModelList = document.getElementById('sidebarModelList');
    const selectedModelLabel = document.getElementById('selectedModelLabel');

    // Toggle Model Subview
    if(modelSelectorBtn) {
        modelSelectorBtn.addEventListener('click', (e) => {
            e.preventDefault();
            openModelSubview();
        });
    }

    // Model Subview Navigation
    if(modelSubviewBackBtn) {
        modelSubviewBackBtn.addEventListener('click', closeModelSubview);
    }

    function openModelSubview() {
        if(modelSubview) {
            modelSubview.style.display = 'flex';
        }
    }

    function closeModelSubview() {
        if(modelSubview) {
            modelSubview.style.display = 'none';
        }
    }



    // Close Modal
    if(closeBtn) {
        closeBtn.addEventListener('click', () => {
            modalOverlay.style.display = 'none';
            resetView();
            resetImportForm();
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
            resetForm(); // Ensure clean slate
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
            resetForm();
        });
    }

    // Switch to Import View
    if(importGlossaryBtn) {
        importGlossaryBtn.addEventListener('click', () => {
            listView.classList.remove('active');
            importView.classList.add('active');
            modalTitle.textContent = t.ImportGlossary || "Glossar importieren";
        });
    }

    // Back from Import
    if(importBackBtn) {
        importBackBtn.addEventListener('click', () => {
            importView.classList.remove('active');
            listView.classList.add('active');
            modalTitle.textContent = t.Glossary || "Glossary";
            resetImportForm();
        });
    }

    // Close / Back from Details
    if(detailsCloseBtn) {
        detailsCloseBtn.addEventListener('click', () => {
            detailsView.classList.remove('active');
            listView.classList.add('active');
            modalTitle.textContent = t.Glossary || "Glossary";
        });
    }

    function resetImportForm() {
        if(document.getElementById('importGlossaryName')) document.getElementById('importGlossaryName').value = '';
        if(document.getElementById('importGlossaryDescription')) document.getElementById('importGlossaryDescription').value = '';
        if(csvFileInput) csvFileInput.value = '';
        if(csvFileNameDisplay) csvFileNameDisplay.textContent = t.FormatHint || "Format: Begriff1,Begriff2 (Source,Target)";
        if(submitImportBtn) {
            submitImportBtn.classList.remove('btn-loading');
            submitImportBtn.disabled = false;
        }
    }

    // CSV File Selection Logic
    if(csvDropZone && csvFileInput) {
        csvDropZone.addEventListener('click', () => csvFileInput.click());
        
        csvFileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if(file) {
                csvFileNameDisplay.textContent = file.name;
                // If glossary name is empty, pre-fill with filename (sans extension)
                const nameInput = document.getElementById('importGlossaryName');
                if(nameInput && !nameInput.value) {
                    nameInput.value = file.name.replace(/\.[^/.]+$/, "");
                }
            }
        });

        // Drag & Drop
        csvDropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            csvDropZone.classList.add('drag-over');
        });
        csvDropZone.addEventListener('dragleave', () => {
            csvDropZone.classList.remove('drag-over');
        });
        csvDropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            csvDropZone.classList.remove('drag-over');
            const file = e.dataTransfer.files[0];
            if(file && (file.type === 'text/csv' || file.name.endsWith('.csv') || file.name.endsWith('.txt'))) {
                csvFileInput.files = e.dataTransfer.files;
                csvFileNameDisplay.textContent = file.name;
                const nameInput = document.getElementById('importGlossaryName');
                if(nameInput && !nameInput.value) {
                    nameInput.value = file.name.replace(/\.[^/.]+$/, "");
                }
            }
        });
    }

    // Submit Import
    if(submitImportBtn) {
        submitImportBtn.addEventListener('click', async () => {
            const name = document.getElementById('importGlossaryName').value;
            const description = document.getElementById('importGlossaryDescription').value;
            const sourceLang = document.getElementById('importSourceLang').value;
            const targetLang = document.getElementById('importTargetLang').value;
            const file = csvFileInput.files[0];

            if(!name) return alert(t.NameRequired || "Name erforderlich");
            if(!file) return alert(t.FileRequired || "CSV-Datei erforderlich");

            const formData = new FormData();
            formData.append('file', file);
            formData.append('name', name);
            formData.append('description', description);
            formData.append('source_language', sourceLang);
            formData.append('target_language', targetLang);
            formData.append('visibility', 'private');

            submitImportBtn.classList.add('btn-loading');
            submitImportBtn.disabled = true;

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
                if(data.success) {
                    // Success
                    await loadGlossaries();
                    importView.classList.remove('active');
                    listView.classList.add('active');
                    modalTitle.textContent = t.Glossary || "Glossary";
                    resetImportForm();
                } else {
                    alert((t.Error || 'Fehler') + ': ' + data.message);
                }
            } catch (error) {
                console.error('Import failed:', error);
                alert(t.ImportFailed || "Import fehlgeschlagen");
            } finally {
                submitImportBtn.classList.remove('btn-loading');
                submitImportBtn.disabled = false;
            }
        });
    }

    function resetView() {
        listView.classList.add('active');
        createView.classList.remove('active');
        importView.classList.remove('active');
        if(detailsView) detailsView.classList.remove('active');
        modalTitle.textContent = t.Glossary || "Glossary";
    }

    function showGlossaryDetails(glossary) {
        if(!detailsContent || !detailsGlossaryName || !detailsGlossaryDomain) return;

        // Set Header Info
        detailsGlossaryName.textContent = glossary.display_name;
        detailsGlossaryDomain.textContent = glossary.domain || 'GENERAL';

        const termsCountElem = document.getElementById('detailsTermsCount');
        if (termsCountElem) {
            const count = (glossary.entries_count !== undefined && glossary.entries_count !== null) 
                ? glossary.entries_count 
                : (glossary.entries ? glossary.entries.length : 0);
            termsCountElem.textContent = `${count} ${count === 1 ? (t.Term || 'Begriff') : (t.Terms || 'Begriffe')}`;
        }

        const visibilityLabel = 
            glossary.visibility === 'public' ? (t.Public || 'Öffentlich') :
            glossary.visibility === 'org' ? (t.Organization || 'Organisation') :
            glossary.visibility === 'team' ? (t.Team || 'Team') :
            (t.Private || 'Privat');

        const formatDate = (dateStr) => {
            if(!dateStr) return '-';
            try {
                // Formatting to match mockup: "Mar 10, 2026 • 11:56"
                const date = new Date(dateStr);
                const options = { month: 'short', day: 'numeric', year: 'numeric' };
                const datePart = date.toLocaleDateString('en-US', options);
                const timePart = date.toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' });
                return `${datePart} • ${timePart}`;
            } catch(e) { return dateStr; }
        };

        detailsContent.innerHTML = `
            <!-- Description Card -->
            <div class="detail-card">
                <div class="card-header">
                    <span class="card-title">${t.Description || 'Beschreibung'}</span>
                    ${(glossary.can_edit === true) ? `
                    <span class="mini-edit-btn" onclick="toggleInlineEditDescription(${glossary.id})">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </span>
                    ` : ''}
                </div>
                <div class="card-body" id="detailsDescriptionContainer">
                    <p style="margin: 0; color: var(--text-color); font-size: 0.9rem;" id="detailsDescriptionText">${glossary.description || '-'}</p>
                </div>
            </div>

            <!-- Details Card -->
            <div class="detail-card">
                <div class="card-header">
                    <span class="card-title">${t.Permissions || 'Permissions'}</span>
                    ${(glossary.can_edit === true) ? `
                    <span class="mini-edit-btn" onclick="toggleInlineEditDetails(${glossary.id})">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </span>
                    ` : ''}
                </div>
                <div class="card-body detail-grid" id="detailsInfoContainer" style="padding: 0;">
                    <div class="detail-col" style="padding: 1rem;">
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                            <span class="info-label">${t.Creator || 'Ersteller'}</span>
                            <span class="info-value">${glossary.creator_name || 'System'}</span>
                        </div>
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></span>
                            <span class="info-label">${t.EditRights || 'Edit Rights'}</span>
                            <span class="info-value">${glossary.editor_role_name || (t.OwnerOnly || 'Owner')}</span>
                        </div>
                    </div>
                    <div class="detail-col" style="padding: 1rem;">
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg></span>
                            <span class="info-label">${t.Visibility || 'Visibility'}</span>
                            <span class="info-value">
                                ${visibilityLabel}
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></span>
                            <span class="info-label">${t.VisibleFor || 'Sichtbar für'}</span>
                            <span class="info-value">
                                ${
                                    glossary.visibility === 'public' ? (t.All || 'Alle') :
                                    glossary.visibility === 'private' ? (t.OnlyYou || 'nur für dich') :
                                    glossary.visibility === 'org' ? (glossary.organization_name || t.Organization || 'Organisation') :
                                    (t.Team || 'Team')
                                }
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Meta Card -->
            <div class="detail-card">
                <div class="card-header">
                    <span class="card-title">${t.Meta || 'Meta'}</span>
                </div>
                <div class="card-body detail-grid" style="padding: 0;">
                    <div class="detail-col" style="padding: 1rem;">
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></span>
                            <span class="info-label">${t.Created || 'Created'}</span>
                            <span class="info-value" style="white-space: nowrap;">${formatDate(glossary.created_at)}</span>
                        </div>
                    </div>
                    <div class="detail-col" style="padding: 1rem;">
                        <div class="info-row">
                            <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"></path><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg></span>
                            <span class="info-label">${t.Updated || 'Updated'}</span>
                            <span class="info-value" style="white-space: nowrap;">${formatDate(glossary.updated_at)}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;


        listView.classList.remove('active');
        detailsView.classList.add('active');
        modalTitle.textContent = t.GlossaryDetails || "Glossar Details";
    }

    window.toggleInlineEditDescription = function(id) {
        const glossary = state.glossaries.find(g => g.id === id);
        if (!glossary) return;

        const container = document.getElementById('detailsDescriptionContainer');
        const textElem = document.getElementById('detailsDescriptionText');
        if (!container || !textElem) return;

        // If already in edit mode, don't do anything or toggle back?
        if (container.querySelector('textarea')) return;

        const currentDesc = glossary.description || '';
        const currentDomain = glossary.domain || '';
        
        container.innerHTML = `
            <textarea id="descriptionInput" class="text-input" style="min-height: 80px; width: 100%; margin-bottom: 0.5rem; font-size: 0.9rem;">${currentDesc}</textarea>
            <div class="info-row" style="margin-bottom: 1rem; flex-wrap: wrap; gap: 8px;">
                <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path></svg></span>
                <span class="info-label">${t.Category || 'Category'}</span>
                <input type="text" id="domainInput" class="styleless-input border" style="width: auto; min-width: 150px; font-size: 0.85rem;" value="${currentDomain}" placeholder="e.g. GENERAL">
            </div>
            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                <button class="btn-xs-stroke" onclick="cancelInlineEditDescription(${glossary.id})">${t.Cancel || 'Abbrechen'}</button>
                <button class="btn-primary" id="saveDescriptionBtn" onclick="saveInlineDescription(${glossary.id})">${t.Save || 'Speichern'}</button>
            </div>
        `;
    };

    window.cancelInlineEditDescription = function(id) {
        renderDetailsContent(id);
    };

    window.saveInlineDescription = async function(id) {
        const container = document.getElementById('detailsDescriptionContainer');
        const textarea = document.getElementById('descriptionInput');
        const domainInput = document.getElementById('domainInput');
        const saveBtn = document.getElementById('saveDescriptionBtn');
        if (!textarea || !domainInput || !saveBtn) return;

        const newDesc = textarea.value;
        const newDomain = domainInput.value;
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
                    description: newDesc,
                    domain: newDomain
                })
            });

            const data = await response.json();
            if (data.success) {
                const updatedGlossary = data.data.glossary;
                // Update local state
                if (state.glossaries) {
                    const idx = state.glossaries.findIndex(g => g.id === id);
                    if (idx !== -1) state.glossaries[idx] = updatedGlossary;
                }
                // Refresh UI (includes Badge in header)
                showGlossaryDetails(updatedGlossary);
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
    };

    window.toggleInlineEditDetails = function(id) {
        const glossary = state.glossaries.find(g => g.id === id);
        if (!glossary) return;

        const container = document.getElementById('detailsInfoContainer');
        if (!container) return;

        // Populate dropdown options
        const roleOptions = state.availableRoles.map(role => `
            <option value="${role.id}" data-slug="${role.slug}" ${parseInt(glossary.organization_id) === parseInt(role.id) ? 'selected' : ''}>
                ${role.name}
            </option>
        `).join('');
        const editorRoleOptions = state.availableRoles.map(role => `
            <option value="${role.slug}" ${glossary.editor_role === role.slug ? 'selected' : ''}>
                ${role.name}
            </option>
        `).join('');

        container.innerHTML = `
            <div class="detail-col" style="padding: 1rem;">
                <div class="info-row" style="flex-wrap: wrap; gap: 8px;">
                    <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg></span>
                    <span class="info-label">${t.Creator || 'Ersteller'}</span>
                    <span class="info-value">${glossary.creator_name || 'System'}</span>
                </div>
                <div class="info-row" id="editRightsRow" style="display: ${glossary.visibility === 'private' ? 'none' : 'flex'}; flex-wrap: wrap; gap: 8px;">
                    <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></span>
                    <span class="info-label">${t.EditRights || 'Edit Rights'}</span>
                    <select id="editRightsSelect" class="styleless-select border" style="width: auto; min-width: 120px;">
                        <option value="">${t.OwnerOnly || 'Owner'}</option>
                        ${editorRoleOptions}
                    </select>
                </div>
            </div>
            <div class="detail-col" style="padding: 1rem;">
                <div class="info-row" style="flex-wrap: wrap; gap: 8px;">
                    <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg></span>
                    <span class="info-label">${t.Visibility || 'Visibility'}</span>
                    <select id="visibilitySelect" class="styleless-select border" onchange="toggleOrganizationSelect(this.value)">
                        <option value="private" ${glossary.visibility === 'private' ? 'selected' : ''}>${t.Private || 'Privat'}</option>
                        <option value="org" ${glossary.visibility === 'org' ? 'selected' : ''}>${t.Organization || 'Organisation'}</option>
                        <option value="public" ${glossary.visibility === 'public' ? 'selected' : ''}>${t.Public || 'Öffentlich'}</option>
                    </select>
                </div>
                <div class="info-row" id="orgSelectRow" style="display: ${glossary.visibility === 'org' ? 'flex' : 'none'}; flex-wrap: wrap; gap: 8px;">
                    <span class="info-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg></span>
                    <span class="info-label">${t.VisibleFor || 'Sichtbar für'}</span>
                    <select id="organizationSelect" class="styleless-select border">
                        <option value="">${t.SelectRole || 'Rolle auswählen'}</option>
                        ${roleOptions}
                    </select>
                </div>
            </div>
            <div class="detail-footer" style="grid-column: 1 / -1; display: flex; gap: 8px; justify-content: flex-end; padding: 1rem;">
                <button class="btn-xs-stroke" onclick="renderDetailsContent(${glossary.id})">${t.Cancel || 'Abbrechen'}</button>
                <button class="btn-primary" id="saveDetailsBtn" onclick="saveInlineDetails(${glossary.id})">${t.Save || 'Speichern'}</button>
            </div>
        `;

        // Set current values (backup in case construction didn't capture it)
        const editSelect = document.getElementById('editRightsSelect');
        if (editSelect) editSelect.value = glossary.editor_role || '';
        
        const orgSelect = document.getElementById('organizationSelect');
        if (orgSelect && glossary.organization_id) orgSelect.value = glossary.organization_id;
    };

    window.toggleOrganizationSelect = function(visibility) {
        const orgRow = document.getElementById('orgSelectRow');
        if (orgRow) orgRow.style.display = visibility === 'org' ? 'flex' : 'none';

        const editRow = document.getElementById('editRightsRow');
        const editSelect = document.getElementById('editRightsSelect');
        if (editRow && editSelect) {
            if (visibility === 'private') {
                editSelect.value = ''; // Reset to Owner
                editRow.style.display = 'none';
            } else {
                editRow.style.display = 'flex';
            }
        }
    };

    window.saveInlineDetails = async function(id) {
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
                body: JSON.stringify({
                    visibility,
                    organization_id,
                    editor_role
                })
            });

            const data = await response.json();
            if (data.success) {
                const updatedGlossary = data.data.glossary;
                // Update local state
                if (state.glossaries) {
                    const idx = state.glossaries.findIndex(g => g.id === id);
                    if (idx !== -1) state.glossaries[idx] = updatedGlossary;
                }
                // Re-render the whole card content to show updated labels
                renderGlossaryDetailsInPlace(updatedGlossary);
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
    };

    // Helper to refresh details without full showGlossaryDetails (which changes headers etc)
    function renderGlossaryDetailsInPlace(glossary) {
        const container = document.getElementById('glossaryDetailsContent');
        if (!container) return;
        
        // This is a bit redundant but safe. Better: just re-call showGlossaryDetails(glossary) but it resets modal state.
        // Let's just re-render everything to be consistent.
        showGlossaryDetails(glossary);
    }
    
    // Alias for cancel button
    window.renderDetailsContent = function(id) {
        const glossary = state.glossaries.find(g => g.id === id);
        if (glossary) showGlossaryDetails(glossary);
    };

    // Global listener for mini edit buttons
    document.addEventListener('edit-glossary-requested', (e) => {
        editGlossary(e.detail.id);
    });

    function resetForm() {
        if(createGlossaryBtn) {
            createGlossaryBtn.dataset.mode = 'create';
            createGlossaryBtn.dataset.id = '';
            createGlossaryBtn.dataset.visibility = '';
            // Reset text - assuming default is "Create" or similar. 
            // Better strategy: save initial text references or just hardcode for now
            createGlossaryBtn.textContent = 'Erstellen'; 
        }
        if(document.getElementById('newGlossaryName')) {
            document.getElementById('newGlossaryName').value = '';
        }
        if(document.getElementById('newGlossaryDescription')) {
            document.getElementById('newGlossaryDescription').value = '';
        }
        // Reset terms to one empty row
        if(termPairsContainer) {
            termPairsContainer.innerHTML = '';
            addTermRow(); 
        }
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

    // List fetching & Rendering
    async function loadGlossaries() {
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
                state.glossaries = data.data.glossaries; // Update state
                state.availableRoles = data.data.available_roles || []; 
                renderGlossaryList(state.glossaries);
                renderSidebarGlossaryList(); // Render subview list too
            }
        } catch (error) {
            console.error('Failed to load glossaries:', error);
        }
    }

    function renderGlossaryList(glossaries) {
        const listView = document.getElementById('glossaryListView');
        const emptyMsg = listView.querySelector('.glossary-list-empty');
        
        // Find or create the glossary list container
        let listContainer = listView.querySelector('.glossary-list-container');
        if (!listContainer) {
            listContainer = document.createElement('div');
            listContainer.className = 'glossary-list-container';
            // Insert before empty message if it exists, otherwise append
            if (emptyMsg) {
                listView.insertBefore(listContainer, emptyMsg);
            } else {
                listView.appendChild(listContainer);
            }
        }
        
        // Remove old rows
        listContainer.querySelectorAll('.glossary-item-row').forEach(row => row.remove());

        if (glossaries.length === 0) {
            if (emptyMsg) emptyMsg.style.display = 'block';
            listContainer.style.display = 'none';
            return;
        }

        if (emptyMsg) emptyMsg.style.display = 'none';
        listContainer.style.display = 'block';

        glossaries.forEach(glossary => {
            const canEdit = glossary.can_edit;
            const row = document.createElement('div');
            row.className = 'sidebar-item glossary-item-row';
            row.style.justifyContent = 'space-between';
            row.style.padding = '0.75rem';
            row.style.borderRadius = '8px';
            row.style.background = 'var(--bg-secondary-color)';
            
            row.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div class="sidebar-item-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path></svg>
                    </div>
                    <div>
                        <div style="font-weight: 600;">${glossary.display_name}</div>
                        <div style="font-size: 11px; color: var(--text-faded-color);">${glossary.entries_count} ${t.Terms || "Begriffe"}</div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <button class="visibility-btn visibility-icon" title="${
                        glossary.visibility === 'public' ? (t.Public || 'Öffentlich') :
                        glossary.visibility === 'org' ? (t.Organization || 'Organisation') :
                        glossary.visibility === 'team' ? (t.Team || 'Team') :
                        (t.Private || 'Privat')
                    }">
                        ${
                            glossary.visibility === 'public' 
                                ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>'
                                : glossary.visibility === 'org'
                                ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>'
                                : glossary.visibility === 'team'
                                ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>'
                                : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>'
                        }
                    </button>
                    ${glossary.can_edit ? `
                    <button class="edit-glossary-btn" data-id="${glossary.id}" title="${t.Edit || 'Bearbeiten'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </button>
                    ` : ''}
                    ${glossary.can_delete ? `
                    <button class="delete-glossary-btn" data-id="${glossary.id}" title="${t.Delete || 'Löschen'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                    </button>
                    ` : ''}
                </div>
            `;

            if (glossary.can_edit) {
                row.querySelector('.edit-glossary-btn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    editGlossary(glossary.id);
                });
            }

            row.querySelector('.visibility-btn').addEventListener('click', (e) => {
                e.stopPropagation();
                showGlossaryDetails(glossary);
            });

            if (glossary.can_delete) {
                row.querySelector('.delete-glossary-btn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    openDeleteModal(glossary.id, glossary.display_name);
                });
            }

            // Click on row to edit (optional, but keep for better UX if not clicking controls)
            row.style.cursor = 'default'; // Changed from pointer to default to emphasize buttons
            // We can remove the row click active behavior if a specific button is requested to avoid confusion.
            // User asked for "dedicated button", often implying "don't make the whole row clickable" or "I can't find how to edit".
            // I will remove the row click listener to rely solely on the explicit button as requested.
            // row.addEventListener('click', ... ); REMOVED
            
            listContainer.appendChild(row);

        });
    }

    function renderSidebarGlossaryList() {
        if(!sidebarGlossaryList) return;
        sidebarGlossaryList.innerHTML = '';
        
        if (glossaryCountDisplay) {
            glossaryCountDisplay.textContent = `${state.activeGlossaries.size}/${state.glossaries.length}`;
        }
        if (glossaryCountBadge) {
            glossaryCountBadge.textContent = `${state.activeGlossaries.size}/${state.glossaries.length}`;
        }

        if (state.glossaries.length === 0) {
            sidebarGlossaryList.innerHTML = `<div style="color: var(--text-faded-color); padding: 1.5rem; text-align: center; font-size: 0.9rem;">${t.NoGlossaries || "Keine Glossare vorhanden."}</div>`;
            return;
        }

        state.glossaries.forEach(glossary => {
             const row = document.createElement('label');
             row.className = 'selection-item';
             
             const isChecked = state.activeGlossaries.has(String(glossary.id));
             
             row.innerHTML = `
                <input type="checkbox" value="${glossary.id}" ${isChecked ? 'checked' : ''}>
                <div class="label">
                    <span style="font-weight: 500; font-size: 0.9rem; color: var(--text-color);">${glossary.display_name}</span>
                    <span style="font-size: 0.75rem; color: var(--text-faded-color); margin-left: 0.5rem;">• ${glossary.entries_count || 0} ${t.Terms || "Begriffe"}</span>
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
                 // Update count
                 if (glossaryCountDisplay) {
                    glossaryCountDisplay.textContent = `${state.activeGlossaries.size}/${state.glossaries.length}`;
                 }
                 if (glossaryCountBadge) {
                    glossaryCountBadge.textContent = `${state.activeGlossaries.size}/${state.glossaries.length}`;
                 }
             });

             if (isChecked) {
                 row.classList.add('active');
             }

             sidebarGlossaryList.appendChild(row);
        });
    }
    
    async function editGlossary(id) {
        try {
            const response = await fetch('/req/glossary/' + id, {
                 headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                }
            });
            const data = await response.json();
            if(data.success) {
                const glossary = data.data.glossary;
                
                // Switch view
                listView.classList.remove('active');
                createView.classList.add('active');
                modalTitle.textContent = t.EditGlossary || 'Glossar bearbeiten';
                
                // Populate Form
                document.getElementById('newGlossaryName').value = glossary.display_name;
                if(document.getElementById('newGlossaryDescription')) {
                    document.getElementById('newGlossaryDescription').value = glossary.description || '';
                }
                
                // Set Edit Mode
                createGlossaryBtn.dataset.mode = 'edit';
                createGlossaryBtn.dataset.id = glossary.id;
                createGlossaryBtn.dataset.visibility = glossary.visibility;
                createGlossaryBtn.textContent = t.Update || 'Aktualisieren';
                
                // Populate Terms
                // First clear existing
                 const rows = termPairsContainer.querySelectorAll('.term-pair-row');
                 rows.forEach(r => r.remove());
                 
                 // We need a template. If we removed all, we lost the template.
                 // We should store a template on init or keep one hidden.
                 // WORKAROUND: The original HTML has one row. If the user deleted all rows manually, we'd have issues too.
                 // Let's assume there's always a way to create a row either from a template or string.
                 // Since I don't want to rewrite the whole HTML structure management, I will re-create rows using innerHTML or logic.
                 // Actually, the `addTermPairBtn` logic clones the first row. 
                 // Strategy: We need to reconstruct the DOM for terms.
                 
                 // Let's grab the HTML of a row from the DOM *before* we clear it, assuming one exists.
                 // Or better, define getRowTemplate()
                 
                 glossary.entries.forEach(entry => {
                      addTermRow(entry);
                 });
                 
                 // If no entries (weird), add one empty
                 if(glossary.entries.length === 0) addTermRow();
            }
        } catch (error) {
            console.error('Failed to load glossary details:', error);
        }
    }
    
    function addTermRow(data = null) {
        const row = document.createElement('div');
        row.className = 'term-pair-row';
        row.style.display = 'flex';
        row.style.gap = '10px';
        row.style.marginBottom = '10px';
        
    
        
        row.innerHTML = `
            <div class="term-pair-inputs">
                <select class="styleless-select border">
                    <option value="DE" ${data && data.source_language === 'DE' ? 'selected' : ''}>DE</option>
                    <option value="EN" ${data && data.source_language === 'EN' ? 'selected' : ''}>EN</option>
                </select>
                <input type="text" class="term-input" placeholder="${t.SourceTerm || 'Ausgangsbegriff'}" value="${data ? data.source_term : ''}">
            </div>
            <span style="color: var(--text-faded-color);">→</span>
            <div class="term-pair-inputs">
                <select class="styleless-select border">
                    <option value="EN" ${data && data.target_language === 'EN' ? 'selected' : ''}>EN</option>
                    <option value="DE" ${data && data.target_language === 'DE' ? 'selected' : ''}>DE</option>
                </select>
                <input type="text" class="term-input" placeholder="${t.TargetTerm || 'Zielbegriff'}" value="${data ? data.target_term : ''}">
            </div>
            <button class="delete-term-btn" title="${t.RemoveTermPair || 'Begriffspaar entfernen'}">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
            </button>
        `;
        
        // Attach delete listener
        row.querySelector('.delete-term-btn').addEventListener('click', function() {
              if(termPairsContainer.querySelectorAll('.term-pair-row').length > 1) {
                  row.remove();
              } else {
                  row.querySelectorAll('input').forEach(i => i.value = '');
              }
        });
        
        termPairsContainer.appendChild(row);
    }

    // Delete Modal Elements
    const deleteModalOverlay = document.getElementById('deleteGlossaryModalOverlay');
    const deleteGlossaryCloseBtn = document.getElementById('deleteGlossaryCloseBtn');
    const deleteCancelBtn = document.getElementById('deleteCancelBtn');
    const deleteConfirmBtn = document.getElementById('deleteConfirmBtn');
    const deleteGlossaryNameDisplay = document.getElementById('deleteGlossaryName');
    
    let pendingDeleteId = null;
    let pendingDeleteName = null;

    // Delete Modal Functions
    function openDeleteModal(id, name) {
        pendingDeleteId = id;
        pendingDeleteName = name;
        const modalTitle = deleteModalOverlay.querySelector('.glossary-modal-header h3');
        if (modalTitle) {
            modalTitle.textContent = (t.DeleteGlossaryTitle || "Glossar löschen: :name").replace(':name', name);
        }
        deleteModalOverlay.style.display = 'flex';
    }

    function closeDeleteModal() {
        deleteModalOverlay.style.display = 'none';
        pendingDeleteId = null;
        pendingDeleteName = null;
    }

    // Delete Modal Event Listeners
    if (deleteGlossaryCloseBtn) {
        deleteGlossaryCloseBtn.addEventListener('click', closeDeleteModal);
    }
    if (deleteCancelBtn) {
        deleteCancelBtn.addEventListener('click', closeDeleteModal);
    }
    if (deleteConfirmBtn) {
        deleteConfirmBtn.addEventListener('click', () => {
            if (pendingDeleteId) {
                executeDeleteGlossary(pendingDeleteId);
                closeDeleteModal();
            }
        });
    }
    if (deleteModalOverlay) {
        deleteModalOverlay.addEventListener('click', (e) => {
            if (e.target === deleteModalOverlay) {
                closeDeleteModal();
            }
        });
    }

    async function executeDeleteGlossary(id) {
        try {
            const response = await fetch('/req/glossary/' + id, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                }
            });
            if (response.ok) {
                loadGlossaries();
            }
        } catch (error) {
            console.error('Failed to delete glossary:', error);
        }
    }


    // Load initial data
    if(glossaryBtn) loadGlossaries();

    // Create Glossary Logic
    let isSubmitting = false;
    if(createGlossaryBtn) {
        createGlossaryBtn.addEventListener('click', async () => {
            // Prevent double submission
            if (isSubmitting) return;
            
            const name = document.getElementById('newGlossaryName').value;
            if (!name) return alert(t.NameRequired || 'Name erforderlich');
            
            const terms = [];
            termPairsContainer.querySelectorAll('.term-pair-row').forEach(row => {
                const inputs = row.querySelectorAll('input');
                const selects = row.querySelectorAll('select');
                if(inputs[0].value && inputs[1].value) {
                     terms.push({
                        source_language: selects[0].value,
                        source_term: inputs[0].value,
                        target_language: selects[1].value,
                        target_term: inputs[1].value,
                        case_sensitive: false
                    });
                }
            });

            if (terms.length === 0) return alert(t.TermPairRequired || 'Mindestens ein Begriffspaar erforderlich');
            
            const mode = createGlossaryBtn.dataset.mode || 'create';
            const id = createGlossaryBtn.dataset.id;
            const method = mode === 'edit' ? 'PUT' : 'POST';
            const url = mode === 'edit' ? `/req/glossary/${id}` : '/req/glossary';

            // Add loading state
            isSubmitting = true;
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
                    createGlossaryBtn.textContent = mode === 'edit' ? (t.Updated || 'Aktualisiert') : (t.Created || 'Erstellt');
                    createGlossaryBtn.style.backgroundColor = '#10b981'; // Green success color with fallback
                    
                    setTimeout(() => {
                        createGlossaryBtn.textContent = originalText;
                        createGlossaryBtn.style.backgroundColor = '';
                        isSubmitting = false;
                        document.getElementById('newGlossaryName').value = '';
                        resetForm(); 
                        createView.classList.remove('active');
                        listView.classList.add('active');
                        modalTitle.textContent = t.Glossary || "Glossary";
                        loadGlossaries();
                    }, 1000);
                } else {
                    // Remove loading state on error
                    createGlossaryBtn.classList.remove('btn-loading');
                    isSubmitting = false;
                    alert((t.Error || 'Fehler') + ': ' + data.message);
                }
            } catch (error) {
                // Remove loading state on error
                createGlossaryBtn.classList.remove('btn-loading');
                isSubmitting = false;
                console.error('Failed to create glossary:', error);
            }
        });
    }
});


class TranslateApp {
    constructor() {
        this.t = window.TranslationData || {};
        this.userLocale = this.t.userLocale || 'en';
        this.sourceText = document.getElementById('sourceText');
        this.translatedText = document.getElementById('translatedText');
        this.sourceLang = document.getElementById('sourceLang');
        this.targetLang = document.getElementById('targetLang');
        this.sourceLangDropdown = document.getElementById('sourceLangDropdown');
        this.targetLangDropdown = document.getElementById('targetLangDropdown');
        this.docSourceLangDropdown = document.getElementById('docSourceLangDropdown');
        this.docTargetLangDropdown = document.getElementById('docTargetLangDropdown');
        this.translateBtn = document.getElementById('translateBtn');
        this.translationModeBtn = document.getElementById('translationModeBtn');
        this.writingModeBtn = document.getElementById('writingModeBtn');
        this.documentModeBtn = document.getElementById('documentModeBtn');
        this.translateBoard = document.getElementById('translateBoard');
        this.documentBoard = document.getElementById('documentBoard');
        this.writingStyle = document.getElementById('writingStyle');

        this.writingStyleWrapper = document.getElementById('writingStyleWrapper');
        this.toolsInfoText = document.getElementById('toolsInfoText');
        this.aiModel = document.getElementById('aiModel');
        this.copyInputBtn = document.getElementById('copyInputBtn');
        this.copyOutputBtn = document.getElementById('copyOutputBtn');
        this.swapLanguagesBtn = document.getElementById('swapLanguagesBtn');
        this.charCount = document.getElementById('charCount');
        this.targetCharCount = document.getElementById('targetCharCount');
        this.improveTargetBtn = document.getElementById('improveTargetBtn');
        this.translateTargetBtn = document.getElementById('translateTargetBtn');
        this.errorMessage = document.getElementById('errorMessage');
        this.deleteSourceBtn = document.getElementById('deleteSourceBtn');

        // Writing Style & Tone Selector elements
        this.styleSelectorBtn = document.getElementById('style-selector-btn');
        this.sidebarStyleSubview = document.getElementById('sidebarStyleSubview');
        this.styleSubviewBackBtn = document.getElementById('styleSubviewBackBtn');
        this.selectedStyleLabel = document.getElementById('selectedStyleLabel');
        this.styleSelectors = document.querySelectorAll('.style-selector');
        this.toneSelectors = document.querySelectorAll('.tone-selector');
        this.formalitySelectors = document.querySelectorAll('.formality-selector');
        
        this.selectedStyle = 'default';
        this.selectedTone = 'default';
        this.selectedFormality = 'default';

        this.styleSection = document.getElementById('style-section');
        this.toneSection = document.getElementById('tone-section');
        this.formalitySection = document.getElementById('formality-section');
        this.glossaryBtn = document.getElementById('glossary-btn');
        this.globalStandardBtn = document.getElementById('global-standard-btn');

        // Show Changes (Diff View)
        this.showChangesToggle = document.getElementById('showChangesToggle');
        this.diffView = document.getElementById('diffView');
        this.editingToolsSection = document.getElementById('editingToolsSection');
        this.showChangesEnabled = false;
        this.lastSourceText = '';
        this.lastTranslationSource = '';
        this.lastTranslationResult = '';
        this.lastWritingSource = '';
        this.lastWritingResult = '';
        this.lastWritingDiffSource = '';

        this.isLoading = false;
        this.currentMode = 'translation';
        this.availableModels = [];
        this.userSetSourceLang = false; // true = user manually selected; false = auto-detected or default
        this._langDetectCache = { sample: null, language: null }; // same-input cache
        this._detectingPromise = null; // tracking in-flight detection
        this._detectingSample = null; 

        // Sentence-level processing state
        this.sourceSentences = []; // Array of original sentences
        this.targetSentences = []; // Array of translated/improved sentences
        this.lastViewportTop = null; // Track last clicked sentence/word Y
        this.activeContextSentence = null;
        this.lastProcessedSourceLang = null;
        this.lastProcessedTargetLang = null;
        this.lastProcessedModel = null;
        this.lastProcessedFormality = null;
        this.lastProcessedGlossaryIds = '';


        this.activeContextWord = null;
        this.lastImprovedSentences = []; // Cache for rephrase toggle (main Process)
        this.sentenceAlternativesCache = {}; // Cache for sentence rephrase alternatives (Context Menu)
        this.lastImprovedWords = {}; // Cache for synonyms: key = "sentenceIndex-tokenIndex"

        this.rephraseMode = 'sentence'; // 'sentence' or 'word'
        this.activeWordTokenIndex = null;
        this.activeWordText = null;

        // Write Context Menu
        this.writeContextMenu = document.getElementById('write-context-menu');
        this.suggestionsDropdown = document.getElementById('write-suggestions-dropdown');
        if (this.writeContextMenu) {
            this.undoBtn = this.writeContextMenu.querySelector('.undo-btn');
            this.rephraseBtn = this.writeContextMenu.querySelector('.rephrase-btn');
            this.replaceBtn = this.writeContextMenu.querySelector('.replace-word-btn');
            this.closeMenuBtn = this.writeContextMenu.querySelector('.close-btn');
        }

        this.activeAbortController = null;

        this.initCustomDropdowns();
        this.init();
    }

    async init() {
        this.setupEventListeners();
        await this.loadAvailableModels();
        this.restoreSession();
        this.updateCharCount(); // Set initial state (count + delete button visibility)
        this.initTranslatedDocsEvents();
    }

    // ========================================
    // Session Persistence
    // ========================================

    /**
     * Save current state to sessionStorage.
     */
    saveSession() {
        try {
            const state = {
                mode: this.currentMode,
                modelId: this.selectedModel ? this.selectedModel.id : null,
                style: this.selectedStyle,
                tone: this.selectedTone,
                formality: this.selectedFormality,
                showChanges: this.showChangesEnabled,
                sourceText: this.sourceText ? this.sourceText.value : '',
                translatedText: this.translatedText ? this.translatedText.value : '',
                sourceLang: this.sourceLang ? this.sourceLang.value : 'auto',
                targetLang: this.targetLang ? this.targetLang.value : '',
                lastSourceText: this.lastSourceText || '',
                lastTranslationSource: this.lastTranslationSource || '',
                lastTranslationResult: this.lastTranslationResult || '',
                lastWritingSource: this.lastWritingSource || '',
                lastWritingResult: this.lastWritingResult || '',
                lastWritingDiffSource: this.lastWritingDiffSource || '',
                lastProcessedSourceLang: this.lastProcessedSourceLang,
                lastProcessedTargetLang: this.lastProcessedTargetLang,
                lastProcessedModel: this.lastProcessedModel,
                lastProcessedFormality: this.lastProcessedFormality,
                lastProcessedGlossaryIds: this.lastProcessedGlossaryIds,
            };
            sessionStorage.setItem('hawki_text_session', JSON.stringify(state));
        } catch (e) {
            // Silently ignore storage errors
        }
    }

    /**
     * Restore state from sessionStorage after models have loaded.
     */
    restoreSession() {
        try {
            const raw = sessionStorage.getItem('hawki_text_session');
            if (!raw) return;
            const state = JSON.parse(raw);

            // Restore mode
            if (state.mode && state.mode !== 'translation') {
                this.switchMode(state.mode);
            }

            // Restore model
            if (state.modelId && this.availableModels.length > 0) {
                const model = this.availableModels.find(m => m.id === state.modelId);
                if (model) this.selectModel(model);
            }

            // If we are in document mode, re-apply the disabled state so that
            // "DeepL API Pro" is shown instead of the model label that selectModel() just wrote.
            // At this point this.selectedModel is already the correctly restored model,
            // so _modelBeforeDocument will be set to the right value.
            if (this.currentMode === 'document') {
                this._setModelSelectorEnabled(false);
            }

            // Restore style / tone / formality
            if (state.style && state.style !== 'default') {
                this.selectedStyle = state.style;
            }
            if (state.tone && state.tone !== 'default') {
                this.selectedTone = state.tone;
            }
            if (state.formality && state.formality !== 'default') {
                this.selectedFormality = state.formality;
            }
            this.updateStyleUI();
            this.updateStyleLabel();

            // Restore show changes toggle
            if (state.showChanges) {
                this.showChangesEnabled = true;
                if (this.showChangesToggle) this.showChangesToggle.checked = true;
            }

            // Restore languages
            if (state.sourceLang && this.sourceLang) {
                this.sourceLang.value = state.sourceLang;
                if (state.sourceLang !== 'auto') this.userSetSourceLang = true;
                this.sourceLang.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (state.targetLang && this.targetLang) {
                this.targetLang.value = state.targetLang;
                this.targetLang.dispatchEvent(new Event('change', { bubbles: true }));
            }

            // Restore last processed state to maintain re-translation logic
            if (state.lastProcessedSourceLang !== undefined) this.lastProcessedSourceLang = state.lastProcessedSourceLang;
            if (state.lastProcessedTargetLang !== undefined) this.lastProcessedTargetLang = state.lastProcessedTargetLang;
            if (state.lastProcessedModel !== undefined) this.lastProcessedModel = state.lastProcessedModel;
            if (state.lastProcessedFormality !== undefined) this.lastProcessedFormality = state.lastProcessedFormality;
            if (state.lastProcessedGlossaryIds !== undefined) this.lastProcessedGlossaryIds = state.lastProcessedGlossaryIds;

            // Restore text content
            if (state.sourceText && this.sourceText) {
                this.sourceText.value = state.sourceText;
            }
            if (state.translatedText && this.translatedText) {
                this.translatedText.value = state.translatedText;
                if (this.targetCharCount) {
                    this.targetCharCount.textContent = state.translatedText.length.toLocaleString();
                }
                if (this.improveTargetBtn) {
                    this.improveTargetBtn.style.display = (this.currentMode === 'translation' && state.translatedText.length > 0) ? 'flex' : 'none';
                }
                if (this.translateTargetBtn) {
                    this.translateTargetBtn.style.display = (this.currentMode === 'writing' && state.translatedText.length > 0) ? 'flex' : 'none';
                }
            }

            // Rebuild sentence state
            this.sourceSentences = this.splitIntoSentences(this.sourceText ? this.sourceText.value : '');
            this.targetSentences = this.splitIntoSentences(this.translatedText ? this.translatedText.value : '');

            // Restore diff view state (base text for comparison)
            if (state.lastSourceText) {
                this.lastSourceText = state.lastSourceText;
            }

            // Always call toggleDiffView if there's a result, to restore hover effects/diffs in both modes
            if (this.translatedText && this.translatedText.value) {
                this.toggleDiffView();
            }

            // Restore hidden state buffers
            if (state.lastTranslationSource) this.lastTranslationSource = state.lastTranslationSource;
            if (state.lastTranslationResult) this.lastTranslationResult = state.lastTranslationResult;
            if (state.lastWritingSource) this.lastWritingSource = state.lastWritingSource;
            if (state.lastWritingResult) this.lastWritingResult = state.lastWritingResult;
            if (state.lastWritingDiffSource) this.lastWritingDiffSource = state.lastWritingDiffSource;
        } catch (e) {
            // Silently ignore parse errors
        }
    }

    async loadAvailableModels() {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const response = await fetch('/req/text/models', {
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

                // Use admin-configured default if present; fall back to first model
                const defaultId = data.data.default_model;
                this.selectedModel = (defaultId && this.availableModels.find(m => m.id === defaultId))
                    || this.availableModels[0];

                this.populateModelDropdown();
                this.renderModelSubmenu();
                this.updateSelectedModelLabel();
            }
            // Note: session restore happens in init() after this method completes
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
            option.textContent = this.t.StandardModel || 'Standardmodell';
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

    renderModelSubmenu() {
        const sidebarModelList = document.getElementById('sidebarModelList');
        if (!sidebarModelList) return;
        
        sidebarModelList.innerHTML = '';
        
        if (this.availableModels.length === 0) {
            sidebarModelList.innerHTML = `<div style="color: var(--text-faded-color); padding: 1.5rem; text-align: center; font-size: 0.9rem;">${this.t.NoModelsConfigured || "Keine Modelle konfiguriert"}</div>`;
            return;
        }

        // Group models by provider
        const groupedModels = {};
        this.availableModels.forEach(model => {
            const providerName = model.provider_name || (this.t.Unknown || 'Unbekannt');
            if (!groupedModels[providerName]) {
                groupedModels[providerName] = {
                    name: providerName,
                    models: [],
                    display_order: model.provider_display_order || model.provider?.display_order || 9999
                };
            }
            groupedModels[providerName].models.push(model);
        });

        // Sort providers by display_order
        const sortedProviders = Object.values(groupedModels).sort((a, b) => {
            if (a.display_order !== b.display_order) {
                return a.display_order - b.display_order;
            }
            return a.name.localeCompare(b.name);
        });

        // Render each provider group
        sortedProviders.forEach(providerGroup => {
            const providerDiv = document.createElement('div');
            providerDiv.className = 'provider-group';
            
            const headerDiv = document.createElement('div');
            headerDiv.className = 'provider-header';
            headerDiv.innerHTML = `<span class="provider-name">${providerGroup.name}</span>`;
            providerDiv.appendChild(headerDiv);

            // Sort models by display_order within provider
            const sortedModels = providerGroup.models.sort((a, b) => {
                const orderA = a.display_order || 9999;
                const orderB = b.display_order || 9999;
                if (orderA !== orderB) return orderA - orderB;
                return a.label.localeCompare(b.label);
            });

            // Render each model
            sortedModels.forEach(model => {
                if (model.visible === false) return;
                
                const button = document.createElement('button');
                button.className = 'model-selector burger-item';
                button.dataset.modelId = model.id;
                if (model.status === 'offline') {
                    button.disabled = true;
                }

                // Add status indicator
                let statusDot = '';
                switch(model.status) {
                    case 'online':
                        statusDot = '<span class="dot grn-c"></span>';
                        break;
                    case 'unknown':
                        statusDot = '<span class="dot org-c"></span>';
                        break;
                    case 'offline':
                        statusDot = '<span class="dot red-c"></span>';
                        break;
                    default:
                        statusDot = '<span class="dot grn-c"></span>';
                }

                button.innerHTML = `
                    ${statusDot}
                    <span>${model.label}</span>
                `;

                button.addEventListener('click', () => {
                    this.selectModel(model);
                    const closeBtn = document.getElementById('modelSubviewBackBtn');
                    if (closeBtn) closeBtn.click();
                });

                providerDiv.appendChild(button);
            });

            sidebarModelList.appendChild(providerDiv);
        });
    }

    selectModel(model) {
        this.selectedModel = model;
        
        // Update UI active state
        const modelBtns = document.querySelectorAll('.model-selector');
        modelBtns.forEach(btn => {
            btn.classList.toggle('active', btn.dataset.modelId === model.id);
        });
        
        this.updateSelectedModelLabel();
        this.saveSession();
    }

    updateSelectedModelLabel() {
        const label = document.getElementById('selectedModelLabel');
        if (label && this.selectedModel) {
            label.textContent = this.selectedModel.label;
        }
    }


    setupEventListeners() {
        if (this.translateBtn) this.translateBtn.addEventListener('click', () => this.translate());
        if (this.translationModeBtn) this.translationModeBtn.addEventListener('click', () => this.switchMode('translation'));
        if (this.writingModeBtn) this.writingModeBtn.addEventListener('click', () => this.switchMode('writing'));
        if (this.documentModeBtn) this.documentModeBtn.addEventListener('click', () => this.switchMode('document'));
        if (this.deleteSourceBtn) {
            this.deleteSourceBtn.addEventListener('click', () => {
                if (this.sourceText) {
                    this.sourceText.value = '';
                    this.sourceText.dispatchEvent(new Event('input'));
                }
                this.clearTarget();
                this.saveSession();
            });
        }
        
        if (this.improveTargetBtn) {
            this.improveTargetBtn.addEventListener('click', () => {
                if (!this.translatedText || !this.translatedText.value.trim()) return;
                
                // Transfer text to writing mode buffer
                this.lastWritingSource = this.translatedText.value;
                this.lastWritingResult = '';
                this.lastWritingDiffSource = '';
                
                // Sync language: Target from Translate becomes Source for Rephrase
                if (this.targetLang && this.sourceLang) {
                    this.sourceLang.value = this.targetLang.value;
                    this.userSetSourceLang = (this.sourceLang.value !== 'auto');
                }

                // Switch to rephrase mode
                this.switchMode('writing');
                
                // Focus the new source
                if (this.sourceText) this.sourceText.focus();
            });
        }
        
        if (this.translateTargetBtn) {
            this.translateTargetBtn.addEventListener('click', () => {
                if (!this.translatedText || !this.translatedText.value.trim()) return;
                
                // Transfer text to translation mode buffer
                this.lastTranslationSource = this.translatedText.value;
                this.lastTranslationResult = '';
                
                // Switch to translation mode
                this.switchMode('translation');
                
                // Focus the new source
                if (this.sourceText) this.sourceText.focus();
            });
        }
        
        // Document Translation - Real File Upload
        const selectFilesBtn = document.getElementById('select-files-btn');
        const fileInput = document.getElementById('doc-file-input');
        const dropZone = document.getElementById('doc-drop-zone');
        const cancelDocBtn = document.getElementById('cancel-doc-btn');
        const uploadMoreBtn = document.getElementById('upload-more-btn');
        const translateDocsBtn = document.getElementById('translate-docs-btn');
        const uploadStep = document.getElementById('doc-upload-step');
        const processingStep = document.getElementById('doc-processing-step');
        const completedStep = document.getElementById('doc-completed-step');

        // Store selected files
        this.selectedDocFiles = [];

        // "Select from your computer" opens native file picker
        if (selectFilesBtn && fileInput) {
            selectFilesBtn.addEventListener('click', (e) => {
                e.preventDefault();
                fileInput.click();
            });
        }

        // When files are selected via the file picker
        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                const files = Array.from(e.target.files);
                if (files.length > 0) {
                    this.addDocFiles(files);
                    this.showDocFileList();
                }
                fileInput.value = ''; // Reset so same file can be selected again
            });
        }

        // Drag & Drop on the drop zone
        if (dropZone) {
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.add('drag-over');
            });

            dropZone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.remove('drag-over');
            });

            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropZone.classList.remove('drag-over');
                const files = Array.from(e.dataTransfer.files);
                if (files.length > 0) {
                    this.addDocFiles(files);
                    this.showDocFileList();
                }
            });
        }

        // Cancel goes back to upload step
        if (cancelDocBtn) {
            cancelDocBtn.addEventListener('click', () => {
                this.selectedDocFiles = [];
                if (processingStep) processingStep.style.display = 'none';
                if (uploadStep) uploadStep.style.display = 'block';
                this.updateDocLangHeader(false);
            });
        }

        // Upload more resets to upload step
        if (uploadMoreBtn) {
            uploadMoreBtn.addEventListener('click', () => {
                this.selectedDocFiles = [];
                if (completedStep) completedStep.style.display = 'none';
                if (uploadStep) uploadStep.style.display = 'block';
                this.updateDocLangHeader(false);
            });
        }

        // Translate button (placeholder for backend integration)
        if (translateDocsBtn) {
            translateDocsBtn.addEventListener('click', () => {
                this.translateDocuments();
            });
        }

        if (this.copyOutputBtn) this.copyOutputBtn.addEventListener('click', () => this.copyText(this.translatedText, this.copyOutputBtn));
        if (this.swapLanguagesBtn) this.swapLanguagesBtn.addEventListener('click', () => this.swapLanguages());
        if (this.sourceText) {
            this.sourceText.addEventListener('input', () => {
                this.updateCharCount();
                this.scheduleLanguageDetection(); // Trigger detection while typing
                this.saveSession();
            });

            // Auto-switch to document mode when dragging files over the textarea
            ['dragenter', 'dragover'].forEach(eventName => {
                this.sourceText.addEventListener(eventName, (e) => {
                    if (e.dataTransfer.types && Array.from(e.dataTransfer.types).includes('Files')) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (this.currentMode !== 'document') {
                            this.switchMode('document');
                        }
                    }
                });
            });
        }

        // Same-language prevention + track user intent on source language
        if (this.sourceLang) {
            this.sourceLang.addEventListener('change', () => {
                // User explicitly selected 'auto' → back to auto-detection mode
                this.userSetSourceLang = this.sourceLang.value !== 'auto';
                this.preventSameLanguage('source');
                this.saveSession();
            });
        }
        if (this.targetLang) {
            this.targetLang.addEventListener('change', () => {
                this.preventSameLanguage('target');
                this.saveSession();
            });
        }

        /* const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        if (sidebarToggleBtn) {
            sidebarToggleBtn.addEventListener('click', () => this.toggleSidebar());
        } */

        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                this.translate();
            }
        });
        
        // Style & Tone Selector Events
        if (this.styleSelectorBtn) this.styleSelectorBtn.addEventListener('click', () => this.openStyleSubview());
        if (this.styleSubviewBackBtn) this.styleSubviewBackBtn.addEventListener('click', () => this.closeStyleSubview());
        
        this.styleSelectors.forEach(btn => {
            btn.addEventListener('click', (e) => {
                const style = e.currentTarget.dataset.style;
                this.selectStyle(style);
            });
        });
        
        this.toneSelectors.forEach(btn => {
            btn.addEventListener('click', (e) => {
                const tone = e.currentTarget.dataset.tone;
                this.selectTone(tone);
            });
        });

        this.formalitySelectors.forEach(btn => {
            btn.addEventListener('click', (e) => {
                const formality = e.currentTarget.dataset.formality;
                this.selectFormality(formality);
            });
        });

        if (this.globalStandardBtn) {
            this.globalStandardBtn.addEventListener('click', () => {
                this.resetStyleSelections();
            });
        }

        // Show Changes toggle
        if (this.showChangesToggle) {
            this.showChangesToggle.addEventListener('change', () => {
                this.showChangesEnabled = this.showChangesToggle.checked;
                this.toggleDiffView();
                this.saveSession();
            });
        }

        // Test-JS: Click on a highlighted span (word) to log context
        if (this.diffView) {
            this.diffView.addEventListener('click', (e) => {
                const wordSpan = e.target.closest('.word-item');
                if (wordSpan) {
                    const sentenceSpan = wordSpan.closest('.sentence-item');
                    const word = wordSpan.textContent;
                    const sentence = sentenceSpan ? sentenceSpan.textContent : '';
                    console.log('geklicktes Wort:', word);
                    console.log('geklickter Satz:', sentence);

                    // Show Suggest Alternatives Context Menu
                    // Align with the beginning of the diffView horizontally
                    // and above the START of the sentence vertically
                    const rect = (sentenceSpan || wordSpan).getBoundingClientRect();
                    this.showWriteContextMenu(rect.top, wordSpan, sentenceSpan);
                } else {
                    // Clicked elsewhere in diffView
                    this.hideWriteContextMenu();
                }
            });
        }

        // Write Context Menu Events
        if (this.closeMenuBtn) {
            this.closeMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.hideWriteContextMenu();
            });
        }

        if (this.undoBtn) {
            this.undoBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.activeContextSentence) {
                    const index = parseInt(this.activeContextSentence.dataset.index);
                    this.undoSentenceImprovement(index);
                }
            });
        }

        if (this.replaceBtn) {
            this.replaceBtn.addEventListener('mouseenter', () => {
                if (this.activeContextWord) this.activeContextWord.classList.add('active-context');
            });
            this.replaceBtn.addEventListener('mouseleave', () => {
                if (this.activeContextWord) this.activeContextWord.classList.remove('active-context');
            });
        }

        if (this.replaceBtn) {
            this.replaceBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.suggestionsDropdown && this.activeContextSentence) {
                    const isVisible = this.suggestionsDropdown.style.display === 'flex' && this.rephraseMode === 'word';
                    this.rephraseMode = 'word';
                    this.suggestionsDropdown.style.display = isVisible ? 'none' : 'flex';
                    this.replaceBtn.classList.toggle('active', !isVisible);
                    if (this.rephraseBtn) this.rephraseBtn.classList.remove('active');
                    
                    if (!isVisible) {
                        const index = parseInt(this.activeContextSentence.dataset.index);
                        this.renderSuggestions(index, true);
                    }
                }
            });
        }

        if (this.rephraseBtn) {
            this.rephraseBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                if (this.suggestionsDropdown && this.activeContextSentence) {
                    const isVisible = this.suggestionsDropdown.style.display === 'flex' && this.rephraseMode === 'sentence';
                    this.rephraseMode = 'sentence';
                    this.suggestionsDropdown.style.display = isVisible ? 'none' : 'flex';
                    this.rephraseBtn.classList.toggle('active', !isVisible);
                    if (this.replaceBtn) this.replaceBtn.classList.remove('active');
                    
                    if (!isVisible) {
                        const index = parseInt(this.activeContextSentence.dataset.index);
                        this.renderSuggestions(index, true);
                    }
                }
            });
            this.rephraseBtn.addEventListener('mouseenter', () => {
                if (this.activeContextSentence) this.activeContextSentence.classList.add('active-context');
            });
            this.rephraseBtn.addEventListener('mouseleave', () => {
                // Keep permanent highlight if menu is open? 
                // User said: "Öffnen des Context Menu muss den selektierten Satz dauerhaft highlighten, biss das Context Menu geschlossen wird."
                // So rephraseBtn mouseleave should NOT remove it if it was added by showWriteContextMenu
            });
        }

        // Global debug function
        window.showContextMenuDebug = () => {
            console.log('Debug: Showing Context Menu');
            
            // Generate mock data if empty
            if (this.sourceSentences.length === 0) {
                this.sourceSentences = ["Dies ist ein Beispielsatz.", "Und noch einer."];
                this.targetSentences = ["Dies ist ein Beispielsatz.", "Und noch einer."];
                this.lastImprovedSentences = ["Dies ist ein verbesserter Beispielsatz.", "Und noch einer."];
            }
            
            // Mock a sentence span
            document.querySelectorAll('.sentence-item').forEach(el => el.remove());
            const mockSpan = document.createElement('span');
            mockSpan.className = 'sentence-item';
            mockSpan.dataset.index = "0";
            mockSpan.innerText = this.sourceSentences[0];
            this.diffView.appendChild(mockSpan);
            
            const rect = mockSpan.getBoundingClientRect();
            this.showWriteContextMenu(rect.top > 0 ? rect.top : 300, null, mockSpan);
        };

        window.showSuggestionsDebug = () => {
            console.log('Debug: Showing Context Menu with multiple suggestions');
            
            // Generate multiple mock versions
            this.sourceSentences = ["Dort beginne ich meinen Tag meist mit einem Blick auf die eingegangenen Anfragen."];
            this.targetSentences = [...this.sourceSentences];
            this.lastImprovedSentences = [
                [
                    "Dort beginne ich meinen Tag in der Regel mit einem Blick auf die eingegangenen Anfragen.",
                    "An diesem Ort starte ich den Tag meistens mit einer Durchsicht der eingegangenen Anfragen.",
                    "Dort fange ich meinen Arbeitstag üblicherweise mit einem Check der neuen Anfragen an.",
                    "Dort beginnt mein Tag meist mit einem kurzen Blick über alle eingegangenen Anfragen.",
                    "Dort wird mein Tag meist mit einer Kontrolle der eingegangenen Anfragen begonnen."
                ]
            ];
            
            // Mock a sentence span
            document.querySelectorAll('.sentence-item').forEach(el => el.remove());
            const mockSpan = document.createElement('span');
            mockSpan.className = 'sentence-item';
            mockSpan.dataset.index = "0";
            mockSpan.innerText = this.sourceSentences[0];
            this.diffView.appendChild(mockSpan);
            
            const rect = mockSpan.getBoundingClientRect();
            this.showWriteContextMenu(rect.top > 0 ? rect.top : 300, null, mockSpan);

            setTimeout(() => {
                if (this.suggestionsDropdown) {
                    this.renderSuggestions(0, true);
                    console.log('Debug: Suggestions Dropdown forced visible with 5 items');
                }
            }, 100);
        };

        window.addEventListener('resize', () => {
            if (this.writeContextMenu && this.writeContextMenu.style.display === 'flex' && this.lastViewportTop !== null) {
                this.showWriteContextMenu(this.lastViewportTop);
            }
        });
    }

    resetStyleSelections() {
        this.selectedStyle = 'default';
        this.selectedTone = 'default';
        this.selectedFormality = 'default';
        this.updateStyleLabel();
        this.updateStyleUI();
        this.closeStyleSubview();
        this.saveSession();
    }

    /**
     * Add files to the selected documents list, filtering by allowed types.
     */
    addDocFiles(files) {
        const allowedExtensions = ['pdf', 'doc', 'docx', 'pptx', 'ppt', 'jpg', 'jpeg', 'png'];
        files.forEach(file => {
            const ext = this.getFileExtension(file.name);
            if (allowedExtensions.includes(ext)) {
                const exists = this.selectedDocFiles.some(f => f.name === file.name && f.size === file.size);
                if (!exists) {
                    this.selectedDocFiles.push(file);
                }
            }
        });
    }

    /**
     * Transition from upload step to file list view.
     */
    showDocFileList() {
        const uploadStep = document.getElementById('doc-upload-step');
        const processingStep = document.getElementById('doc-processing-step');
        if (uploadStep) uploadStep.style.display = 'none';
        if (processingStep) processingStep.style.display = 'block';
        this.renderDocFileList();
        this.updateDocLangHeader(true);
    }

    /**
     * Render the file list from this.selectedDocFiles.
     */
    renderDocFileList() {
        const fileList = document.getElementById('doc-file-list');
        const footerStats = document.getElementById('doc-footer-stats');
        if (!fileList) return;

        fileList.innerHTML = '';

        this.selectedDocFiles.forEach((file, index) => {
            const ext = this.getFileExtension(file.name);
            const item = document.createElement('div');
            item.className = 'doc-item';
            item.innerHTML = `
                <div class="doc-item-info">
                    <div class="doc-item-icon">${ext}</div>
                    <div class="doc-details">
                        <div class="doc-name">${this.escapeHtml(file.name)}</div>
                        <div class="doc-file-size">${this.formatFileSize(file.size)}</div>
                    </div>
                </div>
                <div class="doc-item-actions">
                    <button class="doc-remove-btn" data-index="${index}" title="${this.t['Remove'] || 'Remove'}">&times;</button>
                </div>
            `;
            fileList.appendChild(item);
        });

        fileList.querySelectorAll('.doc-remove-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const idx = parseInt(e.currentTarget.dataset.index, 10);
                this.removeDocFile(idx);
            });
        });

        if (footerStats) {
            const count = this.selectedDocFiles.length;
            const label = count === 1 ? (this.t['FileSelected'] || 'file selected') : (this.t['FilesSelected'] || 'files selected');
            footerStats.textContent = `${count} ${label}`;
        }
    }

    /**
     * Remove a file from the selected list by index.
     */
    removeDocFile(index) {
        this.selectedDocFiles.splice(index, 1);
        if (this.selectedDocFiles.length === 0) {
            const uploadStep = document.getElementById('doc-upload-step');
            const processingStep = document.getElementById('doc-processing-step');
            if (processingStep) processingStep.style.display = 'none';
            if (uploadStep) uploadStep.style.display = 'block';
            this.updateDocLangHeader(false);
        } else {
            this.renderDocFileList();
        }
    }

    /**
     * Toggle the document language header between active and inactive states.
     */
    updateDocLangHeader(isActive) {
        const header = document.getElementById('docLangHeader');
        const targetSelect = document.getElementById('docTargetLang');
        if (header) {
            header.classList.toggle('inactive', !isActive);
            header.classList.toggle('active', isActive);
        }
        if (targetSelect) {
            targetSelect.disabled = !isActive;
        }
    }

    /**
     * Translate documents via the DeepL document translation API.
     * Uses async polling: upload → poll status → download when done.
     */
    async translateDocuments() {
        if (this.selectedDocFiles.length === 0) return;

        const translateDocsBtn = document.getElementById('translate-docs-btn');
        const cancelDocBtn = document.getElementById('cancel-doc-btn');
        const fileList = document.getElementById('doc-file-list');
        const footerStats = document.getElementById('doc-footer-stats');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const targetLang = document.getElementById('docTargetLang')?.value || 'en';

        // Disable buttons during translation
        if (translateDocsBtn) {
            translateDocsBtn.disabled = true;
            translateDocsBtn.classList.add('btn-loading');
        }
        if (cancelDocBtn) cancelDocBtn.disabled = true;

        // Re-render file list with progress bars
        if (fileList) {
            fileList.innerHTML = '';
            this.selectedDocFiles.forEach((file, index) => {
                const ext = this.getFileExtension(file.name);
                const item = document.createElement('div');
                item.className = 'doc-item processing';
                item.innerHTML = `
                    <div class="doc-item-info">
                        <div class="doc-item-icon">${ext}</div>
                        <div class="doc-details">
                            <div class="doc-name">${this.escapeHtml(file.name)}</div>
                            <div class="doc-file-size">${this.formatFileSize(file.size)}</div>
                        </div>
                    </div>
                    <div class="doc-status">
                        <span class="status-text" id="doc-status-text-${index}">${this.t['Translating'] || 'Translating...'}</span>
                        <div class="progress-bar"><div class="progress-fill" id="doc-progress-${index}" style="width: 0%;"></div></div>
                    </div>
                `;
                fileList.appendChild(item);
            });
        }

        if (footerStats) {
            footerStats.textContent = `0 / ${this.selectedDocFiles.length} ${this.t['XOfYTranslated'] || 'translated'}`;
        }

        // Process files sequentially
        this.translatedDocResults = [];
        let completedCount = 0;

        for (let index = 0; index < this.selectedDocFiles.length; index++) {
            const file = this.selectedDocFiles[index];
            const progressBar = document.getElementById(`doc-progress-${index}`);
            const statusText = document.getElementById(`doc-status-text-${index}`);

            try {
                // Step 1: Upload file (returns immediately with job_id)
                if (progressBar) progressBar.style.width = '10%';
                if (statusText) statusText.textContent = 'Uploading...';

                const formData = new FormData();
                formData.append('file', file);
                formData.append('target_lang', targetLang);

                // Add source_lang if available (important for glossaries)
                const sourceLang = document.getElementById('docSourceLang')?.value;
                if (sourceLang && sourceLang !== 'auto') {
                    formData.append('source_lang', sourceLang);
                }

                // Add Formality
                if (this.selectedFormality && this.selectedFormality !== 'default') {
                    formData.append('formality', this.selectedFormality);
                }

                // Add Glossary
                const activeGlossaryCheckboxes = document.querySelectorAll('#sidebarGlossaryList input[type="checkbox"]:checked');
                activeGlossaryCheckboxes.forEach(cb => {
                    formData.append('glossary_id[]', cb.value);
                });

                const uploadResponse = await fetch('/req/text/translate-document', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: formData
                });

                const uploadData = await uploadResponse.json();

                if (!uploadResponse.ok || !uploadData.success) {
                    let errorMessage;
                    if (uploadData.error_code === 'same_language') {
                        errorMessage = this.t['Err_DocSameLanguage'] || 'The detected language of the source document is the same as the target language.';
                    } else {
                        errorMessage = uploadData.error || uploadData.message || 'Upload failed';
                    }
                    throw new Error(errorMessage);
                }

                const jobId = uploadData.data.job_id;

                // Step 2: Poll for status until done or error
                const pollResult = await this.pollDocumentStatus(jobId, index, csrfToken);

                // Step 3: Store result
                this.translatedDocResults.push({
                    originalName: file.name,
                    originalSize: file.size,
                    downloadId: pollResult.download_id,
                    outputName: uploadData.data.original_name,
                    outputExtension: uploadData.data.output_extension,
                    targetLang: uploadData.data.target_lang,
                    success: true
                });

                completedCount++;

            } catch (error) {
                console.error(`Translation failed for ${file.name}:`, error);

                if (progressBar) {
                    progressBar.style.width = '100%';
                    progressBar.style.backgroundColor = '#ef4444';
                }
                if (statusText) {
                    statusText.textContent = this.t['Error'] || '✗ Error';
                    statusText.style.color = '#ef4444';
                }

                this.translatedDocResults.push({
                    originalName: file.name,
                    originalSize: file.size,
                    success: false,
                    error: error.message
                });
            }

            if (footerStats) {
                footerStats.textContent = `${completedCount} / ${this.selectedDocFiles.length} ${this.t['XOfYTranslated'] || 'translated'}`;
            }
        }

        // Show completed step
        this.showDocCompleted();
    }

    /**
     * Poll the document translation status until done or error.
     * Updates progress bar and status text in real-time.
     *
     * @param {string} jobId - The translation job ID
     * @param {number} index - The file index for UI updates
     * @param {string} csrfToken - CSRF token
     * @returns {Promise<object>} The final status data with download_id
     */
    async pollDocumentStatus(jobId, index, csrfToken) {
        const progressBar = document.getElementById(`doc-progress-${index}`);
        const statusText = document.getElementById(`doc-status-text-${index}`);
        const maxPollTime = 5 * 60 * 1000; // 5 minutes timeout
        const startTime = Date.now();
        let pollInterval = 3000; // Start with 3s

        while (true) {
            // Timeout check
            if (Date.now() - startTime > maxPollTime) {
                throw new Error('Translation timed out after 5 minutes.');
            }

            // Wait before polling
            await new Promise(resolve => setTimeout(resolve, pollInterval));

            const statusResponse = await fetch(`/req/text/document-status/${jobId}`, {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                }
            });

            const statusData = await statusResponse.json();

            if (!statusResponse.ok || !statusData.success) {
                let errorMessage;
                if (statusData.error_code === 'same_language') {
                    errorMessage = this.t['Err_DocSameLanguage'] || 'The detected language of the source document is the same as the target language.';
                } else {
                    errorMessage = statusData.error || 'Status check failed';
                }
                throw new Error(errorMessage);
            }

            const data = statusData.data;

            if (data.status === 'queued') {
                if (progressBar) progressBar.style.width = '15%';
                if (statusText) statusText.textContent = this.t['Translating'] || 'Queued...';

            } else if (data.status === 'translating') {
                // Calculate progress based on seconds_remaining
                let progress = 50;
                if (data.seconds_remaining !== null && data.seconds_remaining > 0) {
                    // Map remaining time: more remaining = less progress
                    progress = Math.min(85, Math.max(30, 90 - data.seconds_remaining * 2));
                    if (statusText) {
                        statusText.textContent = `${this.t['Translating'] || 'Translating...'} (~${data.seconds_remaining}s)`;
                    }
                } else {
                    if (statusText) statusText.textContent = this.t['Translating'] || 'Translating...';
                }
                if (progressBar) progressBar.style.width = `${progress}%`;

            } else if (data.status === 'done') {
                // Translation complete
                if (progressBar) progressBar.style.width = '100%';
                if (statusText) {
                    statusText.textContent = this.t['Done'] || '✓ Done';
                    statusText.style.color = '#10b981';
                }
                return data;

            } else if (data.status === 'error') {
                throw new Error(data.error_message || 'Translation failed on DeepL side.');
            }

            // Adaptive polling: increase interval slightly over time
            pollInterval = Math.min(pollInterval + 500, 5000);
        }
    }

    /**
     * Show the completed state with download links for translated documents.
     */
    showDocCompleted() {
        const processingStep = document.getElementById('doc-processing-step');
        const completedStep = document.getElementById('doc-completed-step');
        const completedList = document.getElementById('doc-completed-list');
        const completedStats = document.getElementById('doc-completed-stats');
        const translateDocsBtn = document.getElementById('translate-docs-btn');
        const cancelDocBtn = document.getElementById('cancel-doc-btn');

        // Re-enable buttons
        if (translateDocsBtn) {
            translateDocsBtn.disabled = false;
            translateDocsBtn.classList.remove('btn-loading');
        }
        if (cancelDocBtn) cancelDocBtn.disabled = false;

        // Switch steps
        if (processingStep) processingStep.style.display = 'none';
        if (completedStep) completedStep.style.display = 'block';

        // Render completed file list
        if (completedList) {
            completedList.innerHTML = '';
            const results = this.translatedDocResults || [];

            results.forEach((result) => {
                const ext = this.getFileExtension(result.originalName);
                const item = document.createElement('div');
                item.className = result.success ? 'doc-item completed' : 'doc-item completed error';

                if (result.success) {
                    const langSuffix = result.targetLang || 'translated';
                    const downloadFilename = `${result.outputName}_${langSuffix}.${result.outputExtension}`;
                    const downloadUrl = `/req/text/download-document/${result.downloadId}?name=${encodeURIComponent(result.outputName)}&lang=${encodeURIComponent(langSuffix)}`;
                    item.innerHTML = `
                        <div class="doc-item-info">
                            <div class="doc-item-icon">${ext}</div>
                            <div class="doc-details">
                                <div class="doc-name">${this.escapeHtml(result.originalName)}</div>
                                <div class="doc-file-size">${this.formatFileSize(result.originalSize)}</div>
                            </div>
                        </div>
                        <div class="doc-item-actions">
                            <span class="doc-status-icon doc-status-success" title="${this.t['Translated'] || 'Übersetzt'}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            </span>
                            <a href="${downloadUrl}" download="${this.escapeHtml(downloadFilename)}" class="download-doc-btn" title="${this.t['DownloadFile'] || 'Herunterladen'}">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                            </a>
                        </div>
                    `;
                } else {
                    item.innerHTML = `
                        <div class="doc-item-info">
                            <div class="doc-item-icon">${ext}</div>
                            <div class="doc-details">
                                <div class="doc-name">${this.escapeHtml(result.originalName)}</div>
                                <div class="doc-file-size">${this.escapeHtml(result.error || '')}</div>
                            </div>
                        </div>
                        <div class="doc-item-actions">
                            <span class="doc-status-icon doc-status-error" title="${result.error || this.t['TranslationFailed'] || 'Fehlgeschlagen'}">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                            </span>
                        </div>
                    `;
                }
                completedList.appendChild(item);
            });
        }

        if (completedStats) {
            const successCount = (this.translatedDocResults || []).filter(r => r.success).length;
            const totalCount = (this.translatedDocResults || []).length;
            completedStats.textContent = `${successCount} / ${totalCount} ${this.t['SuccessfullyTranslated'] || 'successfully translated'}`;
        }

        // Refresh the persistent history list
        this.loadTranslatedDocsList();
    }

    /**
     * Format bytes to human-readable file size.
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return parseFloat((bytes / Math.pow(1024, i)).toFixed(1)) + ' ' + units[i];
    }

    /**
     * Get file extension from filename.
     */
    getFileExtension(filename) {
        return filename.split('.').pop().toLowerCase();
    }

    /**
     * Escape HTML to prevent XSS in file names.
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    switchMode(mode) {
        // Step 1: Save current source and result before switching
        if (this.currentMode === 'translation') {
            if (this.sourceText) this.lastTranslationSource = this.sourceText.value;
            if (this.translatedText) this.lastTranslationResult = this.translatedText.value;
        } else if (this.currentMode === 'writing') {
            if (this.sourceText) this.lastWritingSource = this.sourceText.value;
            if (this.translatedText) this.lastWritingResult = this.translatedText.value;
            this.lastWritingDiffSource = this.lastSourceText; // Keep the base for diff
        }

        // When leaving document mode, restore the saved model
        if (this.currentMode === 'document' && mode !== 'document') {
            this._restoreModelFromDocumentMode();
        }

        this.currentMode = mode;
        this.hideMessages();
        this.hideWriteContextMenu(); // Ensure menu is hidden when switching tabs
        
        this.lastImprovedSentences = [];
        this.sentenceAlternativesCache = {};
        this.lastImprovedWords = {};

        // Step 2: Restore source and result for the new mode
        if (mode === 'translation') {
            if (this.sourceText) this.sourceText.value = this.lastTranslationSource;
            if (this.translatedText) this.translatedText.value = this.lastTranslationResult;
            // Rebuild sentence state
            this.sourceSentences = this.splitIntoSentences(this.sourceText.value);
            this.targetSentences = this.splitIntoSentences(this.translatedText.value);
            this.lastSourceText = '';
        } else if (mode === 'writing') {
            if (this.sourceText) this.sourceText.value = this.lastWritingSource;
            if (this.translatedText) this.translatedText.value = this.lastWritingResult;
            this.lastSourceText = this.lastWritingDiffSource;
        } else {
            // Document mode: clear for now or handle specifically
            if (this.translatedText) this.translatedText.value = '';
            this.lastSourceText = '';
        }

        // Update UI counters
        this.updateCharCount();
        if (this.targetCharCount && this.translatedText) {
            this.targetCharCount.textContent = this.translatedText.value.length.toLocaleString();
        }

        // Step 3: Handle diff view visibility based on restored values
        if (this.diffView) {
            this.diffView.innerHTML = '';
            this.diffView.style.display = 'none';
        }
        if (this.translatedText) this.translatedText.style.display = '';

        if (mode === 'writing' && this.lastWritingResult && this.lastWritingSource) {
            this.toggleDiffView();
        }

        if (this.improveTargetBtn) {
            this.improveTargetBtn.style.display = (mode === 'translation' && this.translatedText && this.translatedText.value.trim()) ? 'flex' : 'none';
        }

        if (this.translateTargetBtn) {
            this.translateTargetBtn.style.display = (mode === 'writing' && this.translatedText && this.translatedText.value.trim()) ? 'flex' : 'none';
        }

        const btnLabel = this.translateBtn ? this.translateBtn.querySelector('.label span') : null;

        // Reset active states
        if(this.translationModeBtn) this.translationModeBtn.classList.remove('active');
        if(this.writingModeBtn) this.writingModeBtn.classList.remove('active');
        if(this.documentModeBtn) this.documentModeBtn.classList.remove('active');

        // Toggle Boards
        const docsHistory = document.getElementById('translatedDocsHistory');
        if (this.translateBoard) this.translateBoard.style.display = (mode === 'document') ? 'none' : 'grid';
        if (this.documentBoard) this.documentBoard.style.display = (mode === 'document') ? 'grid' : 'none';
        if (docsHistory) {
            if (mode === 'document') {
                this.loadTranslatedDocsList();
            } else {
                docsHistory.style.display = 'none';
            }
        }


        // Update Rich Placeholder in Source Textarea
        const richTitle = document.querySelector('.rich-placeholder .placeholder-title');
        const richSubtitle = document.querySelector('.rich-placeholder .placeholder-subtitle');
        if (richTitle && richSubtitle) {
            if (mode === 'writing') {
                richTitle.textContent = this.t.Writing_Placeholder_Title || "Type or paste text here to see improvement suggestions";
                richSubtitle.textContent = this.t.Writing_Placeholder_Subtitle || "Click on any word to get synonyms or to rephrase a sentence.";
            } else {
                richTitle.textContent = this.t.Translate_Placeholder_Title || "Type to translate.";
                richSubtitle.textContent = this.t.Translate_Placeholder_Subtitle || "Drag and drop to translate PDF, Word (.docx), and PowerPoint (.pptx) files with our document translator.";
            }
        }


        if (mode === 'translation') {
            if(this.translationModeBtn) this.translationModeBtn.classList.add('active');
            if (btnLabel) btnLabel.textContent = this.t.Translate || "Translate"; 
            if (this.sourceLangDropdown) this.sourceLangDropdown.style.display = 'block';
            if (this.targetLangDropdown) this.targetLangDropdown.style.display = 'block';
            if (this.swapLanguagesBtn) this.swapLanguagesBtn.style.display = 'flex';
            if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'block';
            if (this.styleSection) this.styleSection.style.display = 'none';
            if (this.toneSection) this.toneSection.style.display = 'none';
            if (this.formalitySection) this.formalitySection.style.display = 'block';
            if (this.glossaryBtn) this.glossaryBtn.style.display = 'flex';
            if (this.toolsInfoText) this.toolsInfoText.style.display = 'block';
            if (this.editingToolsSection) this.editingToolsSection.style.display = 'none';
            this._setModelSelectorEnabled(true);
        } else if (mode === 'writing') {
            if(this.writingModeBtn) this.writingModeBtn.classList.add('active');
            if (btnLabel) btnLabel.textContent = this.t.ImproveText || "Rewrite";
            if (this.sourceLangDropdown) this.sourceLangDropdown.style.display = 'block';
            if (this.targetLangDropdown) this.targetLangDropdown.style.display = 'none';
            if (this.swapLanguagesBtn) this.swapLanguagesBtn.style.display = 'flex';
            if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'block';
            if (this.styleSection) this.styleSection.style.display = 'block';
            if (this.toneSection) this.toneSection.style.display = 'block';
            if (this.formalitySection) this.formalitySection.style.display = 'block';
            if (this.glossaryBtn) this.glossaryBtn.style.display = 'none';
            if (this.toolsInfoText) this.toolsInfoText.style.display = 'none';
            if (this.editingToolsSection) this.editingToolsSection.style.display = 'block';
            this._setModelSelectorEnabled(true);
        } else if (mode === 'document') {
            if(this.documentModeBtn) this.documentModeBtn.classList.add('active');
            // Document mode has its own board with its own dropdowns, 
            // the header ones are usually hidden or replaced by the document board's ones.
            // But we keep them in sync if they share the same logic.
            if (this.swapLanguagesBtn) this.swapLanguagesBtn.style.display = 'none'; // No swapping in doc mode
            if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'block';
            if (this.styleSection) this.styleSection.style.display = 'none';
            if (this.toneSection) this.toneSection.style.display = 'none';
            if (this.formalitySection) this.formalitySection.style.display = 'block';
            if (this.glossaryBtn) this.glossaryBtn.style.display = 'flex';
            if (this.toolsInfoText) this.toolsInfoText.style.display = 'none';
            if (this.editingToolsSection) this.editingToolsSection.style.display = 'none';
            this._setModelSelectorEnabled(false);
        }

        const swapTooltip = this.swapLanguagesBtn ? this.swapLanguagesBtn.querySelector('.tooltip') : null;
        if (swapTooltip) {
            swapTooltip.textContent = (mode === 'writing') 
                ? (this.t.ReplaceSourceWithImprovedToolTip || "Ausgangstext durch umformulierten Text ersetzen")
                : (this.t.SwapLanguages || "Sprachen tauschen");
        }

        this.saveSession();
    }

    /**
     * Disable or re-enable the model selector sidebar item.
     * When disabled (document mode) the button shows "DeepL API Pro" and cannot be clicked.
     * When enabled the previously selected model label is restored.
     */
    _setModelSelectorEnabled(enabled) {
        const btn = document.getElementById('model-selector-btn');
        const label = document.getElementById('selectedModelLabel');
        if (!btn) return;

        if (!enabled) {
            // Save the real model before overriding the label
            this._modelBeforeDocument = this.selectedModel;

            // Visually disable
            btn.style.pointerEvents = 'none';
            btn.style.opacity = '0.4';
            btn.style.cursor = 'default';

            // Show static DeepL label
            const deeplLabel = btn.dataset.deeplLabel || 'DeepL API Pro';
            if (label) label.textContent = deeplLabel;
        } else {
            // Re-enable
            btn.style.pointerEvents = '';
            btn.style.opacity = '';
            btn.style.cursor = 'pointer';
        }
    }

    /**
     * After leaving document mode, restore the model the user had selected before.
     */
    _restoreModelFromDocumentMode() {
        if (this._modelBeforeDocument) {
            this.selectModel(this._modelBeforeDocument);
            this._modelBeforeDocument = null;
        } else {
            // Nothing saved – just refresh the label from the current selectedModel
            this.updateSelectedModelLabel();
        }
    }


    updateCharCount() {
        if(!this.sourceText || !this.charCount) return;
        const count = this.sourceText.value.length;
        this.charCount.textContent = count.toLocaleString();

        // If source is cleared, also clear the target
        if (count === 0) {
            this.clearTarget();
        }

        // Toggle delete button visibility based on whether there's text
        if (this.deleteSourceBtn) {
            this.deleteSourceBtn.style.display = count > 0 ? 'flex' : 'none';
        }

        // Dynamic font size scaling – always sync both sides together
        this.syncFontSize();
    }

    /**
     * Clear all target contents, rich views, and relevant session state.
     */
    clearTarget() {
        if (this.translatedText) {
            this.translatedText.value = '';
            this.translatedText.style.display = '';
        }

        if (this.targetCharCount) {
            this.targetCharCount.textContent = '0';
        }

        // Clear diff view state
        if (this.diffView) {
            this.diffView.innerHTML = '';
            this.diffView.style.display = 'none';
        }

        // Clear session buffers
        this.lastSourceText = '';
        this.sourceSentences = [];
        this.targetSentences = [];
        this._sentenceHtml = '';
        this.lastImprovedSentences = [];
        this.sentenceAlternativesCache = {};
        this.lastImprovedWords = {};

        if (this.currentMode === 'translation') {
            this.lastTranslationSource = '';
            this.lastTranslationResult = '';
        } else if (this.currentMode === 'writing') {
            this.lastWritingSource = '';
            this.lastWritingResult = '';
            this.lastWritingDiffSource = '';
        }

        // Reset auto language detection
        if (this.sourceLang) {
            this.sourceLang.value = 'auto';
            this.userSetSourceLang = false;
        }

        // Hide action buttons
        if (this.improveTargetBtn) this.improveTargetBtn.style.display = 'none';
        if (this.translateTargetBtn) this.translateTargetBtn.style.display = 'none';

        this.hideWriteContextMenu();
        this.hideMessages();
    }

    /**
     * Synchronises the font size of source and target textareas.
     * Both containers switch to small-text together so the typography
     * is always consistent, regardless of which side is longer.
     */
    syncFontSize() {
        const sourceText = this.sourceText ? this.sourceText.value : '';
        const targetText = this.translatedText ? this.translatedText.value : '';

        const isLong =
            sourceText.includes('\n') || sourceText.length > 55 ||
            targetText.includes('\n') || targetText.length > 55;

        [this.sourceText, this.translatedText, this.diffView].forEach((el) => {
            if (!el) return;
            if (isLong) {
                el.classList.add('small-text');
            } else {
                el.classList.remove('small-text');
            }
        });
    }

    toggleSidebar() {
       // Sidebar toggle logic is handled by global function togglePanelClass
    }

    async translate() {
        const fullText = this.sourceText.value.trim();
        if (!fullText) {
            this.showError(this.t.Err_EmptyInput || "Bitte geben Sie Text ein");
            return;
        }

        if (this.isLoading) return;

        // Clear previous alternatives cache when starting a new full-doc process
        this.lastImprovedSentences = [];
        this.sentenceAlternativesCache = {};
        this.lastImprovedWords = {};

        const currentTargetLang = this.targetLang ? this.targetLang.value : 'en';
        const currentSourceLang = this.sourceLang ? this.sourceLang.value : 'auto';
        const currentModel = this.selectedModel ? this.selectedModel.id : null;
        const currentFormality = this.selectedFormality !== 'default' ? this.selectedFormality : null;
        
        let currentGlossaryIds = [];
        const activeCheckboxes = document.querySelectorAll('#sidebarGlossaryList input[type="checkbox"]:checked');
        activeCheckboxes.forEach(cb => {
            currentGlossaryIds.push(cb.value);
        });
        currentGlossaryIds = currentGlossaryIds.sort().join(',');

        // If languages or core settings have changed, we MUST re-translate everything
        // unless they are identical to the last processed state.
        const settingsChanged = (
            currentTargetLang !== this.lastProcessedTargetLang ||
            currentSourceLang !== this.lastProcessedSourceLang ||
            currentModel !== this.lastProcessedModel ||
            currentFormality !== this.lastProcessedFormality ||
            currentGlossaryIds !== this.lastProcessedGlossaryIds
        );

        if (settingsChanged) {
            // Force re-translation by clearing the match-cache
            this.sourceSentences = [];
            this.targetSentences = [];
        }


        const currentSentences = this.splitIntoSentences(fullText);
        const toTranslate = [];
        const toTranslateIndices = [];
        const nextTargetSentences = new Array(currentSentences.length).fill(undefined);

        // Track used source indices to avoid re-using the same translation for multiple identical source sentences
        const usedSourceIndices = new Set();

        // 1. Pass: Match exact sentences at the same index (preferred)
        currentSentences.forEach((s, i) => {
            if (s === this.sourceSentences[i]) {
                nextTargetSentences[i] = this.targetSentences[i];
                usedSourceIndices.add(i);
            }
        });

        // 2. Pass: Match exact sentences that shifted position
        currentSentences.forEach((s, i) => {
            if (nextTargetSentences[i] === undefined) {
                // Find this sentence anywhere in the previous source sentences
                const oldIdx = this.sourceSentences.findIndex((prevS, prevIdx) => 
                    prevS === s && !usedSourceIndices.has(prevIdx)
                );
                
                if (oldIdx !== -1) {
                    nextTargetSentences[i] = this.targetSentences[oldIdx];
                    usedSourceIndices.add(oldIdx);
                } else {
                    // Truly new or changed sentence
                    toTranslate.push(s);
                    toTranslateIndices.push(i);
                }
            }
        });

        // Special case: if nothing changed at all
        if (toTranslate.length === 0 && currentSentences.length === this.sourceSentences.length && nextTargetSentences.every((s, i) => s === this.targetSentences[i])) {
            return;
        }

        // If something changed, but it was just deletions or shifts that don't require re-translation
        // (Optimistic: in this version we re-translate shifted sentences to ensure context,
        // but we handle local deletions by simply updating state and UI)
        if (toTranslate.length === 0) {
            this.sourceSentences = [...currentSentences];
            this.targetSentences = nextTargetSentences;
            this.lastImprovedSentences = [...nextTargetSentences];
            this.updateOutputUI();
            return;
        }

        this.isLoading = true;
        this.translateBtn.classList.add('btn-loading');
        this.hideMessages();

        try {
            const csrfToken = document.querySelector('meta[name=\"csrf-token\"]')?.getAttribute('content');
            let endpoint, requestData;
            
            if (!this.userSetSourceLang && this.sourceLang) {
                const detected = await this.detectLanguage(fullText);
                if (detected) {
                    const changed = (this.sourceLang.value !== detected);
                    this.sourceLang.value = detected;
                    
                    // Specific to translation: prevent same-language collision if target is also active
                    if (this.currentMode === 'translation' && this.targetLang && detected === this.targetLang.value) {
                        this.targetLang.value = this.getAlternativeTargetLang(detected);
                        this.targetLang.dispatchEvent(new Event('change', { bubbles: true }));
                    }

                    if (changed) {
                        this.sourceLang.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            }

            if (this.currentMode === 'translation') {
                // Pre-validate: fix known same-language collision (source is not auto)
                if (this.sourceLang && this.targetLang) {
                    const sourceVal = this.sourceLang.value;
                    if (sourceVal !== 'auto' && sourceVal === this.targetLang.value) {
                        this.targetLang.value = this.getAlternativeTargetLang(sourceVal);
                    }
                }

                let glossaryIds = [];
                const activeCheckboxes = document.querySelectorAll('#sidebarGlossaryList input[type=\"checkbox\"]:checked');
                activeCheckboxes.forEach(cb => {
                    glossaryIds.push(cb.value);
                });

                endpoint = '/req/text/process';
                requestData = {
                    text: toTranslate, 
                    source_lang: (this.sourceLang && this.sourceLang.value === 'auto') ? null : (this.sourceLang ? this.sourceLang.value : null),
                    target_lang: this.targetLang ? this.targetLang.value : 'en',
                    glossary_id: glossaryIds.length > 0 ? glossaryIds : null,
                    model: this.selectedModel ? this.selectedModel.id : null,
                    formality: this.selectedFormality !== 'default' ? this.selectedFormality : null,
                };
            } else {
                const sourceLangForImprove = (this.sourceLang && this.sourceLang.value && this.sourceLang.value !== 'auto')
                    ? this.sourceLang.value
                    : null;

                endpoint = '/req/text/improve';
                requestData = {
                    text: toTranslate, 
                    source_lang: sourceLangForImprove,
                    target_lang: sourceLangForImprove,
                    model: this.selectedModel ? this.selectedModel.id : null,
                    style: this.selectedStyle !== 'default' ? this.selectedStyle : null,
                    tone: this.selectedTone !== 'default' ? this.selectedTone : null,
                    formality: this.selectedFormality !== 'default' ? this.selectedFormality : null,
                };
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

            // Update nextTargetSentences with results
            const receivedTranslations = Array.isArray(data.data.text) ? data.data.text : [data.data.text];
            
            toTranslateIndices.forEach((idx, i) => {
                nextTargetSentences[idx] = receivedTranslations[i] || toTranslate[i];
            });

            this.sourceSentences = [...currentSentences];
            this.targetSentences = nextTargetSentences;
            this.lastImprovedSentences = [...nextTargetSentences];
            
            this.updateOutputUI();

            // Store source text for diff and render if in writing mode with show-changes on
            if (this.currentMode === 'writing') {
                this.lastSourceText = fullText;
                this.toggleDiffView();
            }

            if (this.currentMode === 'translation' && data.data.detected_source_language && this.sourceLang && !this.userSetSourceLang) {
                this.sourceLang.value = data.data.detected_source_language.toLowerCase();
                this.sourceLang.dispatchEvent(new Event('change', { bubbles: true }));
            }

            this.lastProcessedSourceLang = currentSourceLang;
            this.lastProcessedTargetLang = currentTargetLang;
            this.lastProcessedModel = currentModel;
            this.lastProcessedFormality = currentFormality;
            this.lastProcessedGlossaryIds = currentGlossaryIds;

            this.saveSession();

        } catch (error) {
            this.showError(error.message || this.t.Err_ProcessFailed || "Fehler beim Verarbeiten");
        } finally {
            this.isLoading = false;
            if(this.translateBtn) this.translateBtn.classList.remove('btn-loading');
        }
    }

    /**
     * Update the output textarea and related UI elements from current sentence state.
     */
    updateOutputUI() {
        if (!this.translatedText) return;
        
        const translatedFullText = this.targetSentences.join(' ');
        this.translatedText.value = translatedFullText;
        
        if (this.targetCharCount) {
            this.targetCharCount.textContent = translatedFullText.length.toLocaleString();
        }
        
        this.tagSentencesInOutput();
        this.syncFontSize();
        this.toggleDiffView();

        if (this.improveTargetBtn) {
            this.improveTargetBtn.style.display = (this.currentMode === 'translation' && translatedFullText.length > 0) ? 'flex' : 'none';
        }
        if (this.translateTargetBtn) {
            this.translateTargetBtn.style.display = (this.currentMode === 'writing' && translatedFullText.length > 0) ? 'flex' : 'none';
        }
    }

    tagSentencesInOutput() {
        if (!this.diffView) return;
        
        let content = '';
        
        // If we are in writing mode and showChanges is OFF, we want to highlight changes WITH hover effects
        if (this.currentMode === 'writing' && !this.showChangesEnabled && this.lastSourceText && window.TextDiff) {
            const fullText = this.translatedText.value;
            const ops = window.TextDiff.compute(this.lastSourceText, fullText);
            
            let currentSentence = [];
            let resultParts = [];

            let sentenceIndex = 0;
            ops.forEach(op => {
                if (op.type === 'delete') return;
                
                // Tokenize words + spaces
                const parts = op.text.split(/(\s+)/);
                
                parts.forEach(p => {
                    if (!p) return;
                    if (p.trim().length === 0) {
                        // Whitespace
                        if (currentSentence.length === 0) {
                            // Space between sentences
                            resultParts.push(this.escapeHtml(p));
                        } else {
                            // Space inside a sentence
                            currentSentence.push(this.escapeHtml(p));
                        }
                    } else {
                        // Word or punctuation
                        const diffClass = op.type === 'insert' ? ' diff-highlight' : '';
                        currentSentence.push(`<span class="word-item${diffClass}">${this.escapeHtml(p)}</span>`);
                        
                        // Check if p ends with sentence terminator
                        if (/[.!?]$/.test(p.trim())) {
                            resultParts.push(`<span class="sentence-item" data-index="${sentenceIndex}">${currentSentence.join('')}</span>`);
                            currentSentence = [];
                            sentenceIndex++;
                        }
                    }
                });
            });
            
            if (currentSentence.length > 0) {
                resultParts.push(`<span class="sentence-item" data-index="${sentenceIndex}">${currentSentence.join('')}</span>`);
                sentenceIndex++;
            }
            
            content = resultParts.join('');
        } else {
            // Standard mode (Translation or Writing without source context context)
            content = (this.targetSentences || []).map((s, index) => {
                if (!s) return '';
                const parts = s.split(/(\s+)/).filter(p => p !== '');
                const wrappedParts = parts.map(p => {
                    if (!p) return '';
                    if (p.trim().length === 0) return this.escapeHtml(p); // whitespace
                    return `<span class="word-item">${this.escapeHtml(p)}</span>`;
                }).join('');
                
                return `<span class="sentence-item" data-index="${index}">${wrappedParts}</span>`;
            }).join(' ');
        }

        this._sentenceHtml = content;
    }

    /**
     * Detect the language of the given text via the backend LLM endpoint.
     * Returns an ISO 639-1 code (e.g. 'de') or null on failure.
     * Failure is silently ignored — translation proceeds as normal.
     * Same-input cache: if the first 50 characters match the previous call, returns cached result.
     */
    async detectLanguage(text) {
        if (!text) return null;
        const sample = text.substring(0, 50);

        // 1. Return cached result if input hasn't changed
        if (this._langDetectCache.sample === sample && this._langDetectCache.language !== null) {
            return this._langDetectCache.language;
        }

        // 2. Prevent concurrent duplicate requests for the same sample
        if (this._detectingPromise && this._detectingSample === sample) {
            return this._detectingPromise;
        }

        this._detectingSample = sample;
        this._detectingPromise = (async () => {
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const response = await fetch('/req/text/detect-language', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ text: sample })
                });
                const data = await response.json();
                const language = (data.success && data.data?.language) ? data.data.language : null;

                // Update cache
                this._langDetectCache = { sample, language };

                return language;
            } catch {
                return null;
            } finally {
                // Clear flight tracking but keep cache
                if (this._detectingSample === sample) {
                    this._detectingPromise = null;
                    this._detectingSample = null;
                }
            }
        })();

        return this._detectingPromise;
    }

    /**
     * Splits text into sentences while preserving trailing punctuation and whitespace.
     */
    /**
     * Splits text into sentences while preserving trailing punctuation and whitespace.
     * Uses an abbreviation-aware approach inspired by LanguageTool to prevent incorrect
     * splits at common linguistic markers (titles, units, ordinals).
     */
    splitIntoSentences(text) {
        if (!text) return [];
        
        // Comprehensive list of German/English abbreviations
        const abbrevs = [
            'z.b', 'u.a', 'd.h', 'bzw', 'etc', 'vgl', 'usw', 'ca', 'inkl', 'exkl', 
            'm.e', 'i.d.r', 'u.v.m', 'o.ä', 'u.ä', 's.o', 'v.a',
            'dr', 'prof', 'st', 'fr', 'hr', 'dipl', 'ing', 'mag', 'nr', 'no',
            'jan', 'feb', 'mrz', 'mär', 'apr', 'mai', 'jun', 'jul', 'aug', 'sep', 'okt', 'nov', 'dez',
            'min', 'std', 'sek', 'tel', 's', 'p.a', 'v.v', 'a.d', 'o.g'
        ];

        // Titles that should almost never trigger a sentence break
        const titleRegex = /^(dr|prof|st|fr|hr|dipl|ing|mag|nr|no)$/i;

        // 1. Initial split at potential ends of sentences (.!? followed by whitespace or end)
        const rawParts = text.match(/.*?([.!?]+(?:\s+|$))|.+$/sg) || [];
        const result = [];
        let buffer = '';

        rawParts.forEach((part, index) => {
            buffer += part;
            const currentTrimmed = buffer.trim();
            const nextPart = rawParts[index + 1] || '';
            const nextTrimmed = nextPart.trim();
            const nextFirstChar = nextTrimmed.charAt(0);
            
            // Detect if next character is Uppercase (signals new sentence)
            const isNextUpper = nextFirstChar && /[A-ZÄÖÜ]/.test(nextFirstChar);
            
            // Extract context for abbreviation check
            const words = currentTrimmed.split(/\s+/);
            const lastPart = words[words.length - 1]; // e.g. "z." or "etc."
            const lastWord = lastPart.toLowerCase().replace(/\.+$/, '');
            const secondLastWord = words.length > 1 ? words[words.length - 2].toLowerCase().replace(/\.+$/, '') : '';

            // Check if THIS part + START of next part forms a known abbreviation (e.g., "z." + " B.")
            const nextWords = nextTrimmed.split(/\s+/);
            const nextWordRaw = nextWords[0].replace(/\.+$/, '');
            const lookaheadCombined = (lastWord + '.' + nextWordRaw).replace(/\s/g, '').toLowerCase();

            // Check if ends with multi-dot abbreviation without spaces: u.a., z.B.
            let isAbbrev = abbrevs.includes(lastWord) || abbrevs.includes(lastPart.toLowerCase().replace(/[.]$/, ''));
            
            // Check if ends with single letter abbreviation part: "z." or "u."
            if (!isAbbrev && lastWord.length === 1 && /[a-z]/i.test(lastWord)) isAbbrev = true;

            // Check for common combined forms in CURRENT buffer
            const combined = (secondLastWord + '.' + lastWord).replace(/\s/g, ''); 
            if (abbrevs.includes(combined)) isAbbrev = true;
            
            // Special case: digits like "1." or "25." (Ordinal or Index)
            const isDigit = /^\d+$/.test(lastWord);
            if (isDigit) isAbbrev = true;

            let shouldBreak = true;
            
            // Lookahead check for multi-part abbreviations starting across split points
            if (abbrevs.includes(lookaheadCombined) || lookaheadCombined === 'z.b') {
                shouldBreak = false;
            } else if (isAbbrev) {
                if (titleRegex.test(lastWord)) {
                    shouldBreak = false;
                } else if (!isNextUpper && nextPart) {
                    shouldBreak = false;
                } else if (isDigit && isNextUpper && nextPart.length > 1) {
                    shouldBreak = false;
                }
            }

            if (shouldBreak || !nextPart) {
                result.push(buffer.trim());
                buffer = '';
            }
        });

        if (buffer.trim()) {
            result.push(buffer.trim());
        }

        return result.length > 0 ? result : [text.trim()];
    }

    /**
     * Get an alternative target language when a collision occurs.
     * Prefers user's locale language, falls back to English, then German.
     */
    getAlternativeTargetLang(collisionLang) {
        if (this.userLocale !== collisionLang) {
            return this.userLocale;
        }
        return collisionLang === 'en' ? 'de' : 'en';
    }

    /**
     * Prevent same source and target language selection.
     * Called when either dropdown changes.
     */
    preventSameLanguage(changedSide) {
        if (!this.sourceLang || !this.targetLang) return;
        const sourceVal = this.sourceLang.value;
        const targetVal = this.targetLang.value;

        // Skip if source is auto-detect
        if (sourceVal === 'auto') return;

        if (sourceVal === targetVal) {
            if (changedSide === 'source') {
                // User changed source to match target → switch target
                this.targetLang.value = this.getAlternativeTargetLang(sourceVal);
            } else {
                // User changed target to match source → switch source to auto
                this.sourceLang.value = 'auto';
            }
        }
        this.saveSession();
    }

    async swapLanguages() {
         if(!this.sourceLang || !this.targetLang) return;

         if (this.currentMode === 'writing') {
             // In writing mode, replace source with improved text
             if (this.translatedText && this.translatedText.value.trim()) {
                 this.sourceText.value = this.translatedText.value;
                 this.translatedText.value = '';
                 this.updateCharCount();
                 this.sourceText.focus();
                 // Clear diff view
                 this.lastSourceText = '';
                 if (this.diffView) {
                     this.diffView.innerHTML = '';
                     this.diffView.style.display = 'none';
                 }
                 if (this.translatedText) this.translatedText.style.display = '';
                 if (this.targetCharCount) this.targetCharCount.textContent = '0';
                 this.saveSession();
             }
             return;
         }

         const sourceVal = this.sourceLang.value;
         const targetVal = this.targetLang.value;
         
         // Swap languages
         this.sourceLang.value = targetVal;
         // If source was auto, default to user locale or English
         this.targetLang.value = (sourceVal === 'auto') ? this.getAlternativeTargetLang(targetVal) : sourceVal;
         
         // Trigger change events so custom dropdowns and other listeners sync
         if (this.sourceLang) this.sourceLang.dispatchEvent(new Event('change', { bubbles: true }));
         if (this.targetLang) this.targetLang.dispatchEvent(new Event('change', { bubbles: true }));

         // Swap text content
         const sourceTextVal = this.sourceText.value;
         const targetTextVal = this.translatedText.value;
         
         this.sourceText.value = targetTextVal;
         this.translatedText.value = sourceTextVal;
         
         this.updateCharCount();
         
         // Trigger translation
         if (this.sourceText.value.trim() && this.currentMode === 'translation') {
             this.translate();
         }
         this.saveSession();
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

            // Toggle dropdown
            trigger.addEventListener('click', (e) => {
                if (dropdown.classList.contains('locked') || (hiddenSelect && hiddenSelect.disabled)) return;
                
                e.stopPropagation();
                // Close others
                document.querySelectorAll('.custom-dropdown.open').forEach(d => {
                    if (d !== dropdown) d.classList.remove('open');
                });
                dropdown.classList.toggle('open');
            });

            // Selection
            items.forEach(item => {
                item.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const value = item.getAttribute('data-value');
                    const text = item.textContent;

                    selectedText.textContent = text;
                    items.forEach(i => i.classList.remove('selected'));
                    item.classList.add('selected');

                    if (hiddenSelect) {
                        hiddenSelect.value = value;
                        hiddenSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    dropdown.classList.remove('open');
                });
            });

            // Global close
            if (!window._dropdownGlobalCloseAdded) {
                document.addEventListener('click', () => {
                    document.querySelectorAll('.custom-dropdown.open').forEach(d => d.classList.remove('open'));
                });
                window._dropdownGlobalCloseAdded = true;
            }

            // Sync UI when hidden select changes
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


    hideMessages() {
        if(this.errorMessage) this.errorMessage.style.display = 'none';
    }

    /**
     * Initialize events for the translated documents history section.
     */
    initTranslatedDocsEvents() {
        const toggle = document.getElementById('translatedDocsToggle');
        const history = document.getElementById('translatedDocsHistory');
        if (toggle && history) {
            toggle.addEventListener('click', () => {
                history.classList.toggle('collapsed');
            });
        }
    }

    /**
     * Load the list of previously translated (but not yet downloaded) documents.
     */
    async loadTranslatedDocsList() {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const response = await fetch('/req/text/translated-documents', {
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                }
            });

            if (!response.ok) return;
            const data = await response.json();

            if (data.success) {
                this.renderTranslatedDocsList(data.data);
            }
        } catch (error) {
            console.error('Failed to load translated documents list:', error);
        }
    }

    renderTranslatedDocsList(docs) {
        const history = document.getElementById('translatedDocsHistory');
        const list = document.getElementById('translatedDocsList');
        const count = document.getElementById('translatedDocsCount');

        if (!history || !list) return;

        if (!docs || docs.length === 0) {
            history.style.display = 'none';
            return;
        }

        history.style.display = 'block';
        if (count) count.textContent = docs.length;

        list.innerHTML = '';
        docs.forEach(doc => {
            const langSuffix = doc.target_lang || 'translated';
            const downloadFilename = `${doc.original_name}_${langSuffix}.${doc.output_extension}`;
            const queryParams = `?name=${encodeURIComponent(doc.original_name)}&lang=${encodeURIComponent(langSuffix)}`;
            const downloadUrl = `/req/text/download-document/${doc.download_id}${queryParams}`;
            const viewUrl = `/req/text/view-document/${doc.download_id}${queryParams}`;
            const ext = doc.output_extension.toUpperCase();
            const fileSize = doc.file_size ? this.formatFileSize(doc.file_size) : '';

            const previewableExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            const canPreview = previewableExtensions.includes(doc.output_extension.toLowerCase());

            const item = document.createElement('div');
            item.className = 'translated-doc-item';
            item.setAttribute('data-download-id', doc.download_id);
            item.innerHTML = `
                <div class="doc-item-info">
                    <div class="doc-item-icon">${ext}</div>
                    <div class="doc-details">
                        <div class="doc-name">${this.escapeHtml(downloadFilename)}</div>
                        <div class="doc-file-size">${fileSize}</div>
                    </div>
                </div>
                <div class="doc-item-actions" style="display: flex; align-items: center; gap: 8px;">
                    ${canPreview ? `<button class="view-doc-btn" title="${this.t['ViewFile'] || 'Anzeigen'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </button>` : ''}
                    <a href="${downloadUrl}" download="${this.escapeHtml(downloadFilename)}" class="download-doc-btn" title="${this.t['DownloadFile'] || 'Herunterladen'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    </a>
                    <button class="delete-doc-btn" title="${this.t['Delete'] || 'Löschen'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                    </button>
                </div>
            `;

            // View: open in file viewer modal (same as /chat)
            item.querySelector('.view-doc-btn')?.addEventListener('click', async () => {
                try {
                    const response = await fetch(viewUrl);
                    if (!response.ok) throw new Error('File not found');

                    const blob = await response.blob();
                    const mime = blob.type || '';
                    const type = typeof checkFileFormat === 'function' ? checkFileFormat(mime) : null;

                    if (!type) {
                        // Unsupported format for modal preview → open in new tab
                        window.open(viewUrl, '_blank');
                        return;
                    }

                    switch (type) {
                        case 'image':
                            await renderImage(blob);
                            break;
                        case 'pdf':
                            await renderPdf(blob);
                            break;
                        case 'docx':
                            await renderDocx(blob);
                            break;
                    }

                    const modal = document.querySelector('#file-viewer-modal');
                    if (modal) {
                        modal.style.display = 'flex';
                        const scrollContainer = modal.querySelector('#file-scroll-container');
                        if (scrollContainer) scrollContainer.scrollTop = 0;
                    }
                } catch (e) {
                    console.error('Failed to preview document:', e);
                    window.open(viewUrl, '_blank');
                }
            });

            // Download: keep in list after download (file persists until scheduler cleanup)

            // Delete: remove file from server and list
            item.querySelector('.delete-doc-btn').addEventListener('click', async () => {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                try {
                    await fetch(`/req/text/delete-document/${doc.download_id}`, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        }
                    });
                } catch (e) {
                    console.error('Failed to delete document:', e);
                }
                item.remove();
                this.updateTranslatedDocsCount();
            });

            list.appendChild(item);
        });
    }

    /**
     * Update the translated docs counter and hide section if empty.
     */
    updateTranslatedDocsCount() {
        const history = document.getElementById('translatedDocsHistory');
        const list = document.getElementById('translatedDocsList');
        const count = document.getElementById('translatedDocsCount');
        if (!list) return;

        const remaining = list.querySelectorAll('.translated-doc-item').length;
        if (count) count.textContent = remaining;
        if (remaining === 0 && history) history.style.display = 'none';
    }

    /**
     * Debounced language detection on input.
     */
    scheduleLanguageDetection() {
        if (!this.sourceText || !this.sourceLang || this.userSetSourceLang) return;
        
        clearTimeout(this._langDetectTimeout);
        const text = this.sourceText.value.trim();
        
        // Use a higher threshold (20 chars) to avoid noise while typing the first few chars
        if (text.length < 20) return; 
        
        this._langDetectTimeout = setTimeout(async () => {
            // Check again if conditions still met
            if (this.userSetSourceLang) return;
            
            const detected = await this.detectLanguage(text);
            if (detected && this.sourceLang.value !== detected) {
                this.sourceLang.value = detected;
                this.sourceLang.dispatchEvent(new Event('change', { bubbles: true }));
                
                // Specific to translation: prevent same-language collision
                if (this.currentMode === 'translation' && this.targetLang && detected === this.targetLang.value) {
                    this.targetLang.value = this.getAlternativeTargetLang(detected);
                    this.targetLang.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        }, 800); // 800ms debounce
    }

    openStyleSubview() {
        if (this.sidebarStyleSubview) {
            this.sidebarStyleSubview.style.display = 'flex';
            
            // Toggle visibility of sections based on mode
            if (this.currentMode === 'writing') {
                if (this.styleSection) this.styleSection.style.display = 'block';
                if (this.toneSection) this.toneSection.style.display = 'block';
                if (this.formalitySection) this.formalitySection.style.display = 'block';
            } else {
                if (this.styleSection) this.styleSection.style.display = 'none';
                if (this.toneSection) this.toneSection.style.display = 'none';
                if (this.formalitySection) this.formalitySection.style.display = 'block';
            }
        }
    }

    closeStyleSubview() {
        if (this.sidebarStyleSubview) {
            this.sidebarStyleSubview.style.display = 'none';
        }
    }

    selectStyle(style) {
        this.selectedStyle = style;
        this.selectedTone = 'default';
        this.selectedFormality = 'default';

        this.updateStyleUI();
        this.updateStyleLabel();
        this.closeStyleSubview();
        this.saveSession();
    }

    selectTone(tone) {
        this.selectedTone = tone;
        this.selectedStyle = 'default';
        this.selectedFormality = 'default';

        this.updateStyleUI();
        this.updateStyleLabel();
        this.closeStyleSubview();
        this.saveSession();
    }

    selectFormality(formality) {
        this.selectedFormality = formality;
        this.selectedStyle = 'default';
        this.selectedTone = 'default';

        this.updateStyleUI();
        this.updateStyleLabel();
        this.closeStyleSubview();
        this.saveSession();
    }

    updateStyleUI() {
        // Global Single Choice UI: Only one button in the entire subview can be active.
        // If a specific style/tone/formality is selected, other sections' defaults must not be active.
        const allDefault = (this.selectedStyle === 'default' && this.selectedTone === 'default' && this.selectedFormality === 'default');

        if (this.globalStandardBtn) {
            this.globalStandardBtn.classList.toggle('active', allDefault);
        }

        this.styleSelectors.forEach(btn => {
            const isActive = (this.selectedStyle !== 'default' && btn.dataset.style === this.selectedStyle);
            btn.classList.toggle('active', isActive);
        });

        this.toneSelectors.forEach(btn => {
            const isActive = (this.selectedTone !== 'default' && btn.dataset.tone === this.selectedTone);
            btn.classList.toggle('active', isActive);
        });

        this.formalitySelectors.forEach(btn => {
            const isActive = (this.selectedFormality !== 'default' && btn.dataset.formality === this.selectedFormality);
            btn.classList.toggle('active', isActive);
        });
    }

    updateStyleLabel() {
        if (!this.selectedStyleLabel) return;
        
        const valueSpan = this.selectedStyleLabel.querySelector('.selection-value');
        if (!valueSpan) return;

        let value = this.t['StyleDefault'] || 'Standard';

        if (this.selectedStyle !== 'default') {
            value = this.t[`Style${this.capitalizeFirstLetter(this.selectedStyle)}`] || this.selectedStyle;
        } else if (this.selectedTone !== 'default') {
            value = this.t[`Tone${this.capitalizeFirstLetter(this.selectedTone)}`] || this.selectedTone;
        } else if (this.selectedFormality !== 'default') {
            value = this.t[`Formality${this.capitalizeFirstLetter(this.selectedFormality)}`] || this.selectedFormality;
        }
            
        valueSpan.textContent = value;
    }

    capitalizeFirstLetter(string) {
        return string.charAt(0).toUpperCase() + string.slice(1);
    }

    // ========================================
    // Show Changes – Diff View (uses textDiff.js)
    // ========================================

    /**
     * Render the diff view with inline changes.
     * Delegates to window.TextDiff for the actual diff computation and HTML rendering.
     *
     * @param {boolean} fullDiff – true = full diff (strikethrough + arrows), false = highlight only (underline)
     */
    renderDiffView(fullDiff) {
        if (!this.diffView || !this.lastSourceText || !this.translatedText) return;
        if (!this.translatedText.value) return;
        if (!window.TextDiff) { console.warn('textDiff.js not loaded'); return; }

        const ops = window.TextDiff.compute(this.lastSourceText, this.translatedText.value);
        this.diffView.innerHTML = fullDiff
            ? window.TextDiff.renderHTML(ops)
            : window.TextDiff.renderHighlightHTML(ops);
    }

    /**
     * Toggle between textarea and diff view based on toggle state.
     * In writing mode with results: always show the rich div.
     *   - Show Changes ON  → full diff (strikethrough + arrows + highlights)
     *   - Show Changes OFF → highlight only (changed words underlined in green)
     */
    toggleDiffView() {
        if (!this.diffView || !this.translatedText) return;

        const val = this.translatedText.value;
        if (!val) {
            this.diffView.style.display = 'none';
            this.translatedText.style.display = '';
            return;
        }

        if (this.currentMode === 'writing' && this.lastSourceText && this.showChangesEnabled) {
            // SHOW FULL DIFF (Strikethrough, arrows)
            this.renderDiffView(true);
            this.translatedText.style.display = 'none';
            this.diffView.style.display = 'block';
        } else {
            // SHOW TAGGED SENTENCES (Hover support)
            // Works for translation mode AND writing mode (when detailed changes are OFF or source text is missing)
            
            // Re-sync sentence arrays if they are empty or logically outdated
            if (this.currentMode === 'writing' && this.lastSourceText) {
                this.sourceSentences = this.splitIntoSentences(this.lastSourceText);
                this.targetSentences = this.splitIntoSentences(val);
                if (this.lastImprovedSentences.length === 0) {
                    this.lastImprovedSentences = [...this.targetSentences];
                }
            } else if (this.currentMode === 'translation' && this.sourceText) {
                this.sourceSentences = this.splitIntoSentences(this.sourceText.value);
                this.targetSentences = this.splitIntoSentences(val);
                if (this.lastImprovedSentences.length === 0) {
                    this.lastImprovedSentences = [...this.targetSentences];
                }
            }

            this.tagSentencesInOutput();
            this.diffView.innerHTML = this._sentenceHtml || this.escapeHtml(val);
            this.translatedText.style.display = 'none';
            this.diffView.style.display = 'block';
        }
    }

    /**
     * Show context menu for Suggest Alternatives.
     * @param {number} viewportTop - The top coordinate of the clicked element relative to the viewport.
     * @param {HTMLElement} wordSpan
     * @param {HTMLElement} sentenceSpan
     */
    showWriteContextMenu(viewportTop, wordSpan, sentenceSpan) {
        if (!this.writeContextMenu) return;
        
        this.hideWriteContextMenu(); // Clear previous highlight
        if (!sentenceSpan) return;
        
        this.lastViewportTop = viewportTop;
        this.activeContextWord = wordSpan;
        this.activeContextSentence = sentenceSpan;

        if (wordSpan && sentenceSpan) {
            this.activeWordText = wordSpan.textContent;
            // Get index of wordSpan among all child nodes of sentenceSpan
            const children = Array.from(sentenceSpan.childNodes);
            this.activeWordTokenIndex = children.indexOf(wordSpan);
        } else {
            this.activeWordText = null;
            this.activeWordTokenIndex = null;
        }

        // Persistent highlight for the entire sentence
        if (this.activeContextSentence) {
            this.activeContextSentence.classList.add('active-context');
            
            // Enable/Disable Undo button
            if (this.undoBtn) {
                const index = parseInt(this.activeContextSentence.dataset.index);
                const isChanged = this.sourceSentences[index] !== undefined && 
                                  this.targetSentences[index] !== undefined && 
                                  this.sourceSentences[index] !== this.targetSentences[index];
                
                this.undoBtn.classList.toggle('disabled', !isChanged);
                this.undoBtn.style.opacity = isChanged ? '1' : '0.5';
                this.undoBtn.style.pointerEvents = isChanged ? 'auto' : 'none';
            }
        }

        const groupElement = this.writeContextMenu.parentElement; // .board-panel-group
        if (!groupElement) return;

        const groupRect = groupElement.getBoundingClientRect();
        const diffRect = this.diffView.getBoundingClientRect();
        
        console.log('Context Menu Positioning Debug:', {
            viewportTop,
            groupRect: { left: groupRect.left, top: groupRect.top, width: groupRect.width },
            diffRect: { left: diffRect.left, top: diffRect.top, width: diffRect.width }
        });

        // 1. Horizontal position: Left-aligned with diffView (relative to group)
        let left = diffRect.left - groupRect.left;
        if (left < 0) {
            console.warn('Negative left position detected, defaulting to 16px padding.');
            left = 16;
        }

        // 2. Vertical position: Above the clicked element (relative to group)
        const menuHeight = this.writeContextMenu.offsetHeight || 42;
        const top = viewportTop - groupRect.top - menuHeight - 12; // 12px offset above

        console.log('Final Calculated Position:', { left, top });

        this.writeContextMenu.style.left = `${left}px`;
        this.writeContextMenu.style.top = `${top}px`;
        this.writeContextMenu.style.display = 'flex';

        // Suggestions Dropdown (prepared but hidden by default)
        if (sentenceSpan) {
            const index = parseInt(sentenceSpan.dataset.index);
            this.renderSuggestions(index, false);
        }
    }

    /**
     * Fetch an improvement for a specific sentence or word.
     * @param {number} index
     * @param {Array} exclusions
     * @param {string} type
     * @param {string|null} customText
     * @param {string|null} context
     * @returns {Promise<string|null>}
     */
    async fetchImprovement(index, exclusions = [], type = 'alternatives', customText = null, context = null) {
        const source = customText || (this.currentMode === 'translation' ? this.targetSentences[index] : this.sourceSentences[index]);
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        try {
            const sourceLangVal = (this.sourceLang && this.sourceLang.value !== 'auto') ? this.sourceLang.value : null;
            const targetLangVal = (this.targetLang && this.targetLang.value !== 'auto') ? this.targetLang.value : null;
            
            if (this.activeAbortController) {
                this.activeAbortController.abort();
            }
            this.activeAbortController = new AbortController();

            // Determine the actual language of the text we are about to improve/correct
            // In rephrase/synonym mode, this is the language of the current text panel
            const currentPanelLang = (this.currentMode === 'translation' && (type === 'alternatives' || type === 'synonyms' || type === 'correction'))
                ? (targetLangVal || sourceLangVal) // Correcting the result area
                : sourceLangVal; // Improving the source area

            const response = await fetch('/req/text/improve', {
                method: 'POST',
                signal: this.activeAbortController.signal,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    text: source,
                    source_lang: currentPanelLang,
                    target_lang: (this.currentMode === 'writing' || type === 'alternatives' || type === 'synonyms' || type === 'correction') ? currentPanelLang : (targetLangVal || sourceLangVal),
                    model: this.selectedModel ? this.selectedModel.id : null,
                    style: this.selectedStyle !== 'default' ? this.selectedStyle : null,
                    tone: this.selectedTone !== 'default' ? this.selectedTone : null,
                    formality: this.selectedFormality !== 'default' ? this.selectedFormality : null,
                    exclusions: exclusions.length > 0 ? exclusions : null,
                    type: type,
                    context: context
                })
            });

            const data = await response.json();
            if (data.success && data.data.text !== undefined) {
                return data.data.text;
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                console.log('AI Request aborted');
                return null;
            }
            console.error('Failed to fetch improvement:', error);
        } finally {
            this.activeAbortController = null;
        }
        return null;
    }

    /**
     * Render suggestions in the dropdown for the given sentence index.
     */
    async renderSuggestions(index, show = false) {
        if (!this.suggestionsDropdown || !this.activeContextSentence) return;
        
        // Clear previous list immediately when showing to avoid visual leakage
        if (show) {
            this.suggestionsDropdown.innerHTML = '';
        }

        const isWordMode = this.rephraseMode === 'word';
        const source = isWordMode ? this.activeWordText : (this.currentMode === 'translation' ? this.targetSentences[index] : this.sourceSentences[index]);
        const cacheKey = isWordMode ? `${index}-${this.activeWordTokenIndex}` : index;
        
        this.suggestionsDropdown.classList.toggle('is-word-mode', isWordMode);

        const positionDropdown = () => {
            if (!this.writeContextMenu || !this.suggestionsDropdown) return;

            const menuRect = this.writeContextMenu.getBoundingClientRect();
            const groupRect = this.writeContextMenu.parentElement.getBoundingClientRect();
            const viewportWidth = window.innerWidth;
            const viewportHeight = window.innerHeight;
            const padding = 16; // 1rem
            
            // Toggle word mode class
            this.suggestionsDropdown.classList.toggle('is-word-mode', isWordMode);
            
            // Measure the dropdown (force display temporarily to get size)
            const originalDisplay = this.suggestionsDropdown.style.display;
            this.suggestionsDropdown.style.display = 'flex';
            this.suggestionsDropdown.style.visibility = 'hidden';
            
            // Ensure width is set for measurement, but allow it to be overridden by CSS max-width
            const dropdownWidth = this.suggestionsDropdown.offsetWidth;
            const dropdownHeight = this.suggestionsDropdown.offsetHeight;
            
            this.suggestionsDropdown.style.visibility = '';
            
            // 1. Horizontal Positioning
            const relativeMenuLeft = parseInt(this.writeContextMenu.style.left);
            let finalLeft = relativeMenuLeft;
            let absLeft = groupRect.left + finalLeft;
            
            if (viewportWidth < 768) {
                // On mobile, pin to viewport edges with padding
                finalLeft = padding - groupRect.left;
                this.suggestionsDropdown.style.width = `calc(100vw - ${padding * 2}px)`;
            } else {
                this.suggestionsDropdown.style.width = ''; // Reset to CSS default
                // On desktop, try to align with menu left
                if (absLeft + dropdownWidth + padding > viewportWidth) {
                    // Overflow right -> Shift left
                    absLeft = viewportWidth - dropdownWidth - padding;
                    finalLeft = absLeft - groupRect.left;
                }
                // Prevent overflow left
                if (absLeft < padding) {
                    absLeft = padding;
                    finalLeft = absLeft - groupRect.left;
                }
            }
            this.suggestionsDropdown.style.left = `${finalLeft}px`;
            this.suggestionsDropdown.style.right = 'auto'; // Disable centering logic
            
            // 2. Vertical Positioning
            const relativeMenuTop = parseInt(this.writeContextMenu.style.top);
            const menuHeight = menuRect.height || 42;
            const absMenuBottom = menuRect.bottom;
            const absMenuTop = menuRect.top;
            
            // Default: Show BELOW the menu
            let finalTop = relativeMenuTop + menuHeight + 4;
            let absDropdownBottom = absMenuBottom + 4 + dropdownHeight;
            
            // If it would overflow the bottom of the viewport
            if (absDropdownBottom + padding > viewportHeight) {
                // Try showing it ABOVE the menu
                const topAboveMenu = relativeMenuTop - dropdownHeight - 4;
                const absTopAboveMenu = absMenuTop - 4 - dropdownHeight;
                
                // If it fits above without overflowing top of viewport
                if (absTopAboveMenu > padding) {
                    finalTop = topAboveMenu;
                } else {
                    // Doesn't fit above or below? Choose the side with more space
                    const spaceBelow = viewportHeight - absMenuBottom - padding;
                    const spaceAbove = absMenuTop - padding;
                    
                    if (spaceAbove > spaceBelow && spaceAbove > 150) {
                        finalTop = relativeMenuTop - dropdownHeight - 4;
                        // It might still overflow top, CSS max-height will handle scrolling
                    } else {
                        // Stay below, CSS max-height handles it
                    }
                }
            }
            
            this.suggestionsDropdown.style.top = `${finalTop}px`;
            
            // Restore visibility/display
            if (show) {
                this.suggestionsDropdown.style.display = 'flex';
                this.suggestionsDropdown.classList.add('visible');
            } else {
                this.suggestionsDropdown.style.display = originalDisplay;
            }
        };

        const isLikelyFullSentence = (suggestion, original) => {
            if (!suggestion || !original || suggestion.trim().length <= original.trim().length * 0.4) return false;
            const sWords = suggestion.toLowerCase().split(/\s+/).filter(w => w.length > 1);
            const oWords = original.toLowerCase().split(/\s+/).filter(w => w.length > 1);
            if (sWords.length === 0) return false;
            const matches = sWords.filter(w => oWords.includes(w)).length;
            return (matches / sWords.length > 0.5);
        };

        const renderProposalMarkup = (improved, isFirst) => {
            let displayHtml = '';
            const originalSentence = this.targetSentences[index];

            if (isWordMode) {
                const tokens = originalSentence.split(/(\s+)/).filter(t => t !== '');
                if (tokens[this.activeWordTokenIndex] !== undefined) {
                    const startToken = Math.max(0, this.activeWordTokenIndex - 2);
                    const endToken = Math.min(tokens.length, this.activeWordTokenIndex + 3);
                    const subset = tokens.slice(startToken, endToken);
                    
                    // improved is now just the synonym word/phrase
                    subset[this.activeWordTokenIndex - startToken] = `<b><i>${this.escapeHtml(improved)}</i></b>`;
                    
                    let contextText = subset.join('');
                    if (startToken > 0) contextText = '...' + contextText;
                    if (endToken < tokens.length) contextText = contextText + '...';
                    displayHtml = `&bdquo;${contextText}&ldquo;`;
                } else {
                    displayHtml = `&bdquo;${this.escapeHtml(improved)}&ldquo;`;
                }
            } else if (window.TextDiff && improved !== source && !isWordMode) {
                const ops = window.TextDiff.compute(source, improved);
                displayHtml = ops.map(op => {
                    if (op.type === 'delete') return '';
                    const parts = op.text.split(/(\s+)/);
                    return parts.map(p => {
                        if (!p) return '';
                        if (p.trim().length === 0) return this.escapeHtml(p);
                        const diffClass = op.type === 'insert' ? ' class="suggestion-diff-highlight"' : '';
                        return `<span${diffClass}>${this.escapeHtml(p)}</span>`;
                    }).join('');
                }).join('');
            } else {
                displayHtml = `&bdquo;${this.escapeHtml(improved)}&ldquo;`;
            }
            const extraClass = isFirst ? ' is-original' : '';
            return `<div class="suggestion-proposal${extraClass}">${displayHtml}</div>`;
        };

        let improvedList = isWordMode 
            ? (this.lastImprovedWords[cacheKey] || []) 
            : (this.sentenceAlternativesCache[index] || []);

        // Auto-fetch first suggestion if missing when opening
        if (show && improvedList.length === 0) {
            // Show original word/sentence + loading indicator
            const loadingHtml = renderProposalMarkup(source, true) + 
                '<div class="suggestion-proposal is-loading" style="justify-content: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i>&nbsp;Generiere Vorschläge...</div>';
            
            this.suggestionsDropdown.innerHTML = loadingHtml;
            positionDropdown();
            this.suggestionsDropdown.classList.add('visible');
            
            let result;
            if (isWordMode) {
                const context = this.targetSentences[index];
                // Tag the target word within the context to give LLM exact reference
                const tokens = context.split(/(\s+)/);
                if (tokens[this.activeWordTokenIndex] !== undefined) {
                    tokens[this.activeWordTokenIndex] = `[[TARGET]]${tokens[this.activeWordTokenIndex]}[[TARGET]]`;
                }
                const taggedContext = tokens.join('');
                
                const rawResult = await this.fetchImprovement(index, [], 'synonyms', source, taggedContext);
                try {
                    result = typeof rawResult === 'string' ? JSON.parse(rawResult) : rawResult;
                } catch (e) {
                    console.error('Failed to parse synonyms JSON', rawResult);
                    result = [];
                }
            } else {
                result = await this.fetchImprovement(index);
            }

            if (result) {
                if (isWordMode) {
                    this.lastImprovedWords[cacheKey] = Array.isArray(result) ? result : [result];
                    improvedList = this.lastImprovedWords[cacheKey];
                } else {
                    if (!this.sentenceAlternativesCache[index]) this.sentenceAlternativesCache[index] = [];
                    // Handle array of alternatives from backend
                    if (Array.isArray(result)) {
                        this.sentenceAlternativesCache[index].push(...result);
                    } else {
                        this.sentenceAlternativesCache[index].push(result);
                    }
                    improvedList = this.sentenceAlternativesCache[index];
                }
            } else {
                this.suggestionsDropdown.innerHTML = renderProposalMarkup(source, true) + 
                    '<div class="suggestion-proposal" style="color: #ef4444; justify-content: center;">Fehler beim Laden.</div>';
                return;
            }
        }

        const validImprovements = improvedList.filter(imp => imp && imp !== source);
        const allProposals = [source, ...validImprovements];

        const proposalsHtml = allProposals.map((imp, i) => renderProposalMarkup(imp, i === 0));
        
        const actionBtnHtml = `
            <div class="suggestion-action-btn" id="generate-more-btn">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                <span>Mehr Alternativen generieren</span>
            </div>
        `;
        
        proposalsHtml.push(actionBtnHtml);

        this.suggestionsDropdown.innerHTML = proposalsHtml.join('');
        positionDropdown();
        this.suggestionsDropdown.classList.add('visible');

        // Add click listeners for all proposals
        this.suggestionsDropdown.querySelectorAll('.suggestion-proposal:not(.is-loading)').forEach((proposal, i) => {
            // Source is at index 0, so if there's an original entry we match correctly
            proposal.addEventListener('click', () => {
                const selectedText = allProposals[i];
                if (selectedText) {
                    this.applySpecificRephrase(index, selectedText);
                }
            });
        });

        // Add listener for the action button
        const moreBtn = this.suggestionsDropdown.querySelector('#generate-more-btn');
        if (moreBtn) {
            moreBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                this.generateMoreAlternatives(index);
            });
        }
    }

    /**
     * Generate another alternative using the backend, telling it to avoid existing ones.
     */
    async generateMoreAlternatives(index) {
        const actionBtn = this.suggestionsDropdown.querySelector('#generate-more-btn');
        if (actionBtn) {
            actionBtn.classList.add('is-loading');
            actionBtn.style.pointerEvents = 'none';
            actionBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Generiere...</span>';
        }

        const isWordMode = this.rephraseMode === 'word';
        const source = isWordMode ? this.activeWordText : (this.currentMode === 'translation' ? this.targetSentences[index] : this.sourceSentences[index]);
        const cacheKey = isWordMode ? `${index}-${this.activeWordTokenIndex}` : index;
        const existing = isWordMode ? (this.lastImprovedWords[cacheKey] || []) : (this.sentenceAlternativesCache[index] || []);

        let nextAlternative;
        if (isWordMode) {
            const context = this.targetSentences[index];
            const tokens = context.split(/(\s+)/);
            if (tokens[this.activeWordTokenIndex] !== undefined) {
                tokens[this.activeWordTokenIndex] = `[[TARGET]]${tokens[this.activeWordTokenIndex]}[[TARGET]]`;
            }
            const taggedContext = tokens.join('');
            
            const rawResult = await this.fetchImprovement(index, existing, 'synonyms', source, taggedContext);
            try {
                const results = typeof rawResult === 'string' ? JSON.parse(rawResult) : rawResult;
                nextAlternative = Array.isArray(results) ? results : [results];
            } catch (e) {
                console.error('Failed to parse synonyms JSON', rawResult);
            }
        } else {
            nextAlternative = await this.fetchImprovement(index, existing);
        }

        if (nextAlternative) {
            if (isWordMode) {
                if (!this.lastImprovedWords[cacheKey]) this.lastImprovedWords[cacheKey] = [];
                const addList = Array.isArray(nextAlternative) ? nextAlternative : [nextAlternative];
                this.lastImprovedWords[cacheKey].push(...addList);
            } else {
                if (!this.sentenceAlternativesCache[index]) this.sentenceAlternativesCache[index] = [];
                const addList = Array.isArray(nextAlternative) ? nextAlternative : [nextAlternative];
                this.sentenceAlternativesCache[index].push(...addList);
            }
            this.renderSuggestions(index, false); // Re-render without full showing logic
        } else {
            if (actionBtn) {
                actionBtn.classList.remove('is-loading');
                actionBtn.style.pointerEvents = 'auto';
                actionBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i><span>Fehler (Erneut versuchen)</span>';
            }
        }
    }

    /**
     * Apply a specific rephrased text to a sentence or word.
     */
    async applySpecificRephrase(index, text) {
        if (this.rephraseMode === 'word') {
            // Replace only the specific token in current sentence
            const originalSentence = this.targetSentences[index];
            const tokens = originalSentence.split(/(\s+)/).filter(t => t !== '');
            
            if (tokens[this.activeWordTokenIndex] !== undefined) {
                tokens[this.activeWordTokenIndex] = text.replace(/\[\[TARGET\]\]/g, '');
                const insertedSentence = tokens.join('');
                
                // Show intermediate state briefly if desired, but we want to trigger correction immediately
                this.targetSentences[index] = insertedSentence;
                this.finalizeRephrase(); // Update UI to show the word inserted

                // Second step: Promptly trigger correction assistant to fix grammar/separable verbs
                if (this.diffView) {
                    const sentenceEl = this.diffView.querySelector(`.sentence-item[data-index="${index}"]`);
                    if (sentenceEl) {
                        sentenceEl.classList.add('is-loading-correction');
                    }
                }

                const corrected = await this.fetchImprovement(index, [], 'correction', insertedSentence, originalSentence);
                if (corrected) {
                    this.targetSentences[index] = corrected;
                }
                this.finalizeRephrase();
            }
        } else {
            this.targetSentences[index] = text;
            this.finalizeRephrase();
        }
    }

    /**
     * Replace a specific word token in a sentence.
     */
    applyWordReplacement(sentenceIndex, tokenIndex, newWord) {
        const sentence = this.targetSentences[sentenceIndex];
        const tokens = sentence.split(/(\s+)/);
        
        if (tokens[tokenIndex] !== undefined) {
            let finalizedWord = newWord;
            
            // Repetition safety during actual replacement
            const prevWord = tokens[tokenIndex - 2]?.trim();
            const nextWord = tokens[tokenIndex + 2]?.trim();
            const parts = newWord.trim().split(/\s+/);
            
            if (prevWord && parts.length > 1 && parts[0].toLowerCase() === prevWord.toLowerCase()) {
                parts.shift();
            }
            if (nextWord && parts.length > 1 && parts[parts.length - 1].toLowerCase() === nextWord.toLowerCase()) {
                parts.pop();
            }
            finalizedWord = parts.join(' ');

            tokens[tokenIndex] = finalizedWord;
            this.targetSentences[sentenceIndex] = tokens.join('');
            this.finalizeRephrase();
        }
    }

    /**
     * Common finalization logic for rephrasing (sentence or word).
     */
    finalizeRephrase() {
        const newVal = this.targetSentences.join(' ');
        if (this.translatedText) {
            this.translatedText.value = newVal;
        }
        this.updateOutputUI();
        this.hideWriteContextMenu();
        this.saveSession();
        this.toggleDiffView();
    }

    hideWriteContextMenu() {
        if (this.activeAbortController) {
            this.activeAbortController.abort();
            this.activeAbortController = null;
        }

        if (this.activeContextSentence) {
            this.activeContextSentence.classList.remove('active-context');
        }
        if (this.activeContextWord) {
            this.activeContextWord.classList.remove('active-context');
        }
        this.activeContextSentence = null;
        this.activeContextWord = null;
        this.activeWordTokenIndex = null;
        this.activeWordText = null;
        this.rephraseMode = 'sentence';

        if (this.writeContextMenu) {
            this.writeContextMenu.style.display = 'none';
            const rephraseBtn = this.writeContextMenu.querySelector('.rephrase-btn');
            const replaceBtn = this.writeContextMenu.querySelector('.replace-word-btn');
            if (rephraseBtn) rephraseBtn.classList.remove('active');
            if (replaceBtn) replaceBtn.classList.remove('active');
        }
        if (this.suggestionsDropdown) {
            this.suggestionsDropdown.style.display = 'none';
        }
    }

    /**
     * Revert a sentence in the target area to its original source version.
     */
    undoSentenceImprovement(index) {
        if (!this.sourceSentences || !this.targetSentences) return;
        
        const original = this.sourceSentences[index];
        if (original !== undefined && this.targetSentences[index] !== original) {
            console.log(`Undo sentence ${index}: "${this.targetSentences[index]}" -> "${original}"`);
            this.targetSentences[index] = original;
            
            // Reconstruct the full text
            if (this.translatedText) {
                this.translatedText.value = this.targetSentences.join(' ');
                this.translatedText.dispatchEvent(new Event('input'));
            }
            
            // Refresh view and close menu
            this.toggleDiffView();
            this.hideWriteContextMenu();
        }
    }

    /**
     * Trigger rephrase for a specific sentence.
     */
    rephraseSentenceImprovement(index) {
        this.renderSuggestions(index, true);
    }
}

document.addEventListener('DOMContentLoaded', () => new TranslateApp());
