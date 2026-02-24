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
        glossaries: []
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

    function resetForm() {
        if(createGlossaryBtn) {
            createGlossaryBtn.dataset.mode = 'create';
            createGlossaryBtn.dataset.id = '';
            // Reset text - assuming default is "Create" or similar. 
            // Better strategy: save initial text references or just hardcode for now
            createGlossaryBtn.textContent = 'Erstellen'; 
        }
        if(document.getElementById('newGlossaryName')) {
            document.getElementById('newGlossaryName').value = '';
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
                    <span class="visibility-icon" title="${
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
                    </span>
                    <button class="edit-glossary-btn" data-id="${glossary.id}" title="${t.Edit || 'Bearbeiten'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    </button>
                    <button class="delete-glossary-btn" data-id="${glossary.id}" title="${t.Delete || 'Löschen'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                    </button>
                </div>
            `;

            row.querySelector('.delete-glossary-btn').addEventListener('click', (e) => {
                e.stopPropagation();
                openDeleteModal(glossary.id, glossary.display_name);
            });

            row.querySelector('.edit-glossary-btn').addEventListener('click', (e) => {
                e.stopPropagation();
                editGlossary(glossary.id);
            });

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
                
                // Set Edit Mode
                createGlossaryBtn.dataset.mode = 'edit';
                createGlossaryBtn.dataset.id = glossary.id;
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
                <select class="styleless-select border" style="width: 80px;">
                    <option value="DE" ${data && data.source_language === 'DE' ? 'selected' : ''}>DE</option>
                    <option value="EN" ${data && data.source_language === 'EN' ? 'selected' : ''}>EN</option>
                </select>
                <input type="text" class="term-input" placeholder="${t.SourceTerm || 'Ausgangsbegriff'}" value="${data ? data.source_term : ''}">
            </div>
            <span style="color: var(--text-faded-color);">→</span>
            <div class="term-pair-inputs">
                <select class="styleless-select border" style="width: 80px;">
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
                        visibility: 'private',
                        terms
                    })
                });

                const data = await response.json();
                if (data.success) {
                    createGlossaryBtn.classList.remove('btn-loading');
                    createGlossaryBtn.textContent = mode === 'edit' ? (t.Updated || 'Aktualisiert!') : (t.Created || 'Erstellt!');
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

        this.isLoading = false;
        this.currentMode = 'translation';
        this.availableModels = [];
        this.userSetSourceLang = false; // true = user manually selected; false = auto-detected or default
        this._langDetectCache = { sample: null, language: null }; // same-input cache

        this.init();
    }

    async init() {
        this.setupEventListeners();
        this.updateCharCount(); // Set initial state (count + delete button visibility)
        await this.loadAvailableModels();
        this.initTranslatedDocsEvents();
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
                
                // Ensure default selection handles 'deepl' if present
                this.selectedModel = this.availableModels.find(m => m.id === 'deepl') || this.availableModels[0];
                
                this.populateModelDropdown();
                this.renderModelSubmenu();
                this.updateSelectedModelLabel();
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
                if (this.translatedText) {
                    this.translatedText.value = '';
                }
                // Clear diff view
                this.lastSourceText = '';
                if (this.diffView) {
                    this.diffView.innerHTML = '';
                    this.diffView.style.display = 'none';
                }
                if (this.translatedText) this.translatedText.style.display = '';
                if (this.targetCharCount) this.targetCharCount.textContent = '0';
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
            });
        }
        if (this.targetLang) this.targetLang.addEventListener('change', () => this.preventSameLanguage('target'));

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
            });
        }
    }

    resetStyleSelections() {
        this.selectedStyle = 'default';
        this.selectedTone = 'default';
        this.selectedFormality = 'default';
        this.updateStyleLabel();
        this.updateStyleUI();
        this.closeStyleSubview();
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
                const activeGlossaryCheckbox = document.querySelector('#sidebarGlossaryList input[type="checkbox"]:checked');
                if (activeGlossaryCheckbox) {
                    formData.append('glossary_id', activeGlossaryCheckbox.value);
                }

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
        this.currentMode = mode;
        if(this.translatedText) this.translatedText.value = '';
        if(this.targetCharCount) this.targetCharCount.textContent = '0';
        this.hideMessages();

        // Reset diff view when switching modes
        this.lastSourceText = '';
        if (this.diffView) {
            this.diffView.innerHTML = '';
            this.diffView.style.display = 'none';
        }
        if (this.translatedText) this.translatedText.style.display = '';
        
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
            if(this.sourceLang) this.sourceLang.style.display = 'block';
            if(this.targetLang) this.targetLang.style.display = 'block';
            if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'block';
            if (this.styleSection) this.styleSection.style.display = 'none';
            if (this.toneSection) this.toneSection.style.display = 'none';
            if (this.formalitySection) this.formalitySection.style.display = 'block';
            if (this.glossaryBtn) this.glossaryBtn.style.display = 'flex';
            if (this.toolsInfoText) this.toolsInfoText.style.display = 'block';
            if (this.editingToolsSection) this.editingToolsSection.style.display = 'none';
        } else if (mode === 'writing') {
            if(this.writingModeBtn) this.writingModeBtn.classList.add('active');
            if (btnLabel) btnLabel.textContent = this.t.ImproveText || "Rewrite";
            if(this.sourceLang) this.sourceLang.style.display = 'block'; // Ensure source dropdown is visible
            if(this.targetLang) this.targetLang.style.display = 'none';
            if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'block';
            if (this.styleSection) this.styleSection.style.display = 'block';
            if (this.toneSection) this.toneSection.style.display = 'block';
            if (this.formalitySection) this.formalitySection.style.display = 'block';
            if (this.glossaryBtn) this.glossaryBtn.style.display = 'none';
            if (this.toolsInfoText) this.toolsInfoText.style.display = 'none';
            if (this.editingToolsSection) this.editingToolsSection.style.display = 'block';
        } else if (mode === 'document') {
            if(this.documentModeBtn) this.documentModeBtn.classList.add('active');
            if(this.sourceLang) this.sourceLang.style.display = 'block';
            if(this.targetLang) this.targetLang.style.display = 'block';
            if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'block';
            if (this.styleSection) this.styleSection.style.display = 'none';
            if (this.toneSection) this.toneSection.style.display = 'none';
            if (this.formalitySection) this.formalitySection.style.display = 'block';
            if (this.glossaryBtn) this.glossaryBtn.style.display = 'flex';
            if (this.toolsInfoText) this.toolsInfoText.style.display = 'none';
            if (this.editingToolsSection) this.editingToolsSection.style.display = 'none';
        }

        // Update swap button tooltip based on mode
        const swapTooltip = this.swapLanguagesBtn ? this.swapLanguagesBtn.querySelector('.tooltip') : null;
        if (swapTooltip) {
            swapTooltip.textContent = (mode === 'writing') 
                ? (this.t.ReplaceSourceWithImprovedToolTip || "Ausgangstext durch umformulierten Text ersetzen")
                : (this.t.SwapLanguages || "Sprachen tauschen");
        }

        // Trigger an immediate detection if switching to a mode that shows the source lang
        if (mode !== 'document' && this.sourceText && this.sourceText.value.trim().length > 5) {
            this.scheduleLanguageDetection();
        }
    }


    updateCharCount() {
        if(!this.sourceText || !this.charCount) return;
        const count = this.sourceText.value.length;
        this.charCount.textContent = count.toLocaleString();

        // If source is cleared, also clear the target
        if (count === 0) {
            if (this.translatedText) this.translatedText.value = '';
            if (this.targetCharCount) this.targetCharCount.textContent = '0';
            this.hideMessages();
            if (this.translatedText) this.adjustFontSize(this.translatedText);
        }

        // Toggle delete button visibility based on whether there's text
        if (this.deleteSourceBtn) {
            this.deleteSourceBtn.style.display = count > 0 ? 'flex' : 'none';
        }

        // Dynamic font size scaling
        this.adjustFontSize(this.sourceText);
        if (this.translatedText) this.adjustFontSize(this.translatedText);
    }

    /**
     * Adjusts the font size of the textarea based on content length/multiline.
     */
    adjustFontSize(textarea) {
        if (!textarea) return;
        const text = textarea.value;
        // Case: Text contains newline or is long enough to likely wrap
        const isLong = text.includes('\n') || text.length > 55;
        
        if (isLong) {
            textarea.classList.add('small-text');
        } else {
            textarea.classList.remove('small-text');
        }
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

        // Clear the output immediately so stale content isn't shown during the request
        if (this.translatedText) this.translatedText.value = '';
        if (this.targetCharCount) this.targetCharCount.textContent = '0';

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            let endpoint, requestData;
            
            // Detection Logic (runs for both Translation and Improvement if source lang is not manually locked)
            if (!this.userSetSourceLang && this.sourceLang) {
                const detected = await this.detectLanguage(this.sourceText.value);
                if (detected) {
                    this.sourceLang.value = detected;
                    
                    // Specific to translation: prevent same-language collision if target is also active
                    if (this.currentMode === 'translation' && this.targetLang && detected === this.targetLang.value) {
                        this.targetLang.value = this.getAlternativeTargetLang(detected);
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

                let glossaryId = null;
                const activeCheckbox = document.querySelector('#sidebarGlossaryList input[type="checkbox"]:checked');
                if (activeCheckbox) {
                    glossaryId = activeCheckbox.value;
                }

                endpoint = '/req/text/process';
                requestData = {
                    text: this.sourceText.value,
                    source_lang: (this.sourceLang && this.sourceLang.value === 'auto') ? null : (this.sourceLang ? this.sourceLang.value : null),
                    target_lang: this.targetLang ? this.targetLang.value : 'en',
                    glossary_id: glossaryId,
                    model: this.selectedModel ? this.selectedModel.id : null,
                    formality: this.selectedFormality !== 'default' ? this.selectedFormality : null,
                };
            } else {
                // In improve/rephrase mode: use the source language so DeepL rephrases
                // in the same language. If source is 'auto' (auto-detect), send null.
                const sourceLangForImprove = (this.sourceLang && this.sourceLang.value && this.sourceLang.value !== 'auto')
                    ? this.sourceLang.value
                    : null;

                endpoint = '/req/text/improve';
                requestData = {
                    text: this.sourceText.value,
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

            this.translatedText.value = data.data.text;
            this.targetCharCount.textContent = data.data.text.length.toLocaleString();
            this.adjustFontSize(this.translatedText);

            // Store source text for diff and render if in writing mode with show-changes on
            if (this.currentMode === 'writing') {
                this.lastSourceText = this.sourceText.value;
                this.toggleDiffView();
            }

            // After translation, update the source dropdown with confirmed detected language.
            // If user never manually set it, keep userSetSourceLang = false so detection re-runs next time.
            if (this.currentMode === 'translation' && data.data.detected_source_language && this.sourceLang && !this.userSetSourceLang) {
                this.sourceLang.value = data.data.detected_source_language.toLowerCase();
            }

        } catch (error) {
            this.showError(error.message || this.t.Err_ProcessFailed || "Fehler beim Verarbeiten");
        } finally {
            this.isLoading = false;
            if(this.translateBtn) this.translateBtn.classList.remove('btn-loading');
        }
    }

    /**
     * Detect the language of the given text via the backend LLM endpoint.
     * Returns an ISO 639-1 code (e.g. 'de') or null on failure.
     * Failure is silently ignored — translation proceeds as normal.
     * Same-input cache: if the first 50 characters match the previous call, returns cached result.
     */
    async detectLanguage(text) {
        const sample = text.substring(0, 50);

        // Return cached result if input hasn't changed
        if (this._langDetectCache.sample === sample && this._langDetectCache.language !== null) {
            return this._langDetectCache.language;
        }

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
        }
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
             }
             return;
         }

         const sourceVal = this.sourceLang.value;
         const targetVal = this.targetLang.value;
         
         // Swap languages
         this.sourceLang.value = targetVal;
         // If source was auto, default to user locale or English
         this.targetLang.value = (sourceVal === 'auto') ? this.getAlternativeTargetLang(targetVal) : sourceVal;

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
        if (text.length < 5) return; 
        
        this._langDetectTimeout = setTimeout(async () => {
            // Check again if conditions still met
            if (this.userSetSourceLang) return;
            
            const detected = await this.detectLanguage(text);
            if (detected && this.sourceLang.value !== detected) {
                this.sourceLang.value = detected;
                
                // Specific to translation: prevent same-language collision
                if (this.currentMode === 'translation' && this.targetLang && detected === this.targetLang.value) {
                    this.targetLang.value = this.getAlternativeTargetLang(detected);
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
    }

    selectTone(tone) {
        this.selectedTone = tone;
        this.selectedStyle = 'default';
        this.selectedFormality = 'default';

        this.updateStyleUI();
        this.updateStyleLabel();
        this.closeStyleSubview();
    }

    selectFormality(formality) {
        this.selectedFormality = formality;
        this.selectedStyle = 'default';
        this.selectedTone = 'default';

        this.updateStyleUI();
        this.updateStyleLabel();
        this.closeStyleSubview();
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

        if (this.currentMode === 'writing' && this.lastSourceText && this.translatedText.value) {
            this.renderDiffView(this.showChangesEnabled);
            this.translatedText.style.display = 'none';
            this.diffView.style.display = 'block';
        } else {
            this.diffView.style.display = 'none';
            this.translatedText.style.display = '';
        }
    }
}

document.addEventListener('DOMContentLoaded', () => new TranslateApp());
