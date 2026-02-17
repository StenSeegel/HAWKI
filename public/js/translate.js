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
        this.sourceText = document.getElementById('sourceText');
        this.translatedText = document.getElementById('translatedText');
        this.sourceLang = document.getElementById('sourceLang');
        this.targetLang = document.getElementById('targetLang');
        this.translateBtn = document.getElementById('translateBtn');
        this.translationModeBtn = document.getElementById('translationModeBtn');
        this.writingModeBtn = document.getElementById('writingModeBtn');
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
        this.successMessage = document.getElementById('successMessage');

        this.isLoading = false;
        this.currentMode = 'translation';
        this.availableModels = [];

        this.init();
    }

    async init() {
        this.setupEventListeners();
        await this.loadAvailableModels();
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
        if (this.copyInputBtn) this.copyInputBtn.addEventListener('click', () => this.copyText(this.sourceText, this.copyInputBtn));
        if (this.copyOutputBtn) this.copyOutputBtn.addEventListener('click', () => this.copyText(this.translatedText, this.copyOutputBtn));
        if (this.swapLanguagesBtn) this.swapLanguagesBtn.addEventListener('click', () => this.swapLanguages());
        if (this.sourceText) this.sourceText.addEventListener('input', () => this.updateCharCount());

        /* const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        if (sidebarToggleBtn) {
            sidebarToggleBtn.addEventListener('click', () => this.toggleSidebar());
        } */

        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                this.translate();
            }
        });
    }

    switchMode(mode) {
        this.currentMode = mode;
        if(this.translatedText) this.translatedText.value = '';
        if(this.targetCharCount) this.targetCharCount.textContent = '0';
        this.hideMessages();
        
        const btnLabel = this.translateBtn ? this.translateBtn.querySelector('.label span') : null;

        if (mode === 'translation') {
            if(this.translationModeBtn) this.translationModeBtn.classList.add('active');
            if(this.writingModeBtn) this.writingModeBtn.classList.remove('active');
            if (btnLabel) btnLabel.textContent = this.t.Translate || "Translate"; 
            if(this.sourceLang) this.sourceLang.style.display = 'block';
            if(this.targetLang) this.targetLang.style.display = 'block';
            if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'none';
            if (this.toolsInfoText) this.toolsInfoText.style.display = 'block';
        } else {
            if(this.writingModeBtn) this.writingModeBtn.classList.add('active');
            if(this.translationModeBtn) this.translationModeBtn.classList.remove('active');
            if (btnLabel) btnLabel.textContent = this.t.ImproveText || "Rewrite";
            if(this.sourceLang) this.sourceLang.style.display = 'none';
            if(this.targetLang) this.targetLang.style.display = 'none';
            if (this.writingStyleWrapper) this.writingStyleWrapper.style.display = 'block';
            if (this.toolsInfoText) this.toolsInfoText.style.display = 'none';
        }
    }

    updateCharCount() {
        if(!this.sourceText || !this.charCount) return;
        const count = this.sourceText.value.length;
        this.charCount.textContent = count.toLocaleString();
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

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            let endpoint, requestData, successMessage;
            
            if (this.currentMode === 'translation') {
                // Get active glossary ID (User requested multiple, but backend supports single currently)
                // We pick the first one for now or handle logic in backend later.
                // Assuming `state.activeGlossaries` is available globally or we access DOM
                // Accessing `state` from global closure if possible, or querying checkboxes in subview
                
                let glossaryId = null;
                // Try to find from state if available globally (it is inside DOMContentLoaded which means TranslateApp can't see it easily unless we expose it)
                // Quick fix: Query the checkboxes in the sidebar which are synced with state
                const activeCheckbox = document.querySelector('#sidebarGlossaryList input[type="checkbox"]:checked');
                if (activeCheckbox) {
                    glossaryId = activeCheckbox.value;
                }
                
                // If multiple were selected, we'd need to send array: glossary_ids. 
                // Currently maintaining single ID compatibility.
                
                endpoint = '/req/text/process';
                requestData = {
                    text: this.sourceText.value,
                    source_lang: (this.sourceLang && this.sourceLang.value === 'auto') ? null : (this.sourceLang ? this.sourceLang.value : null),
                    target_lang: this.targetLang ? this.targetLang.value : 'en',
                    glossary_id: glossaryId,
                    model: this.selectedModel ? this.selectedModel.id : null
                };
                successMessage = this.t.Success_Translated || "Übersetzung erfolgreich!";
            } else {
                endpoint = '/req/text/improve';
                requestData = {
                    text: this.sourceText.value,
                    target_lang: null,
                    model: this.selectedModel ? this.selectedModel.id : '',
                    style: this.writingStyle ? this.writingStyle.value : ''
                };
                successMessage = this.t.Success_Improved || "Text erfolgreich verbessert!";
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

            if (this.currentMode === 'translation' && data.data.detected_source_language && this.sourceLang && this.sourceLang.value === 'auto') {
                this.sourceLang.value = data.data.detected_source_language.toLowerCase();
            }

            this.showSuccess(successMessage);
        } catch (error) {
            this.showError(error.message || this.t.Err_ProcessFailed || "Fehler beim Verarbeiten");
        } finally {
            this.isLoading = false;
            if(this.translateBtn) this.translateBtn.classList.remove('btn-loading');
        }
    }

    async swapLanguages() {
         if(!this.sourceLang || !this.targetLang) return;
         const sourceVal = this.sourceLang.value;
         const targetVal = this.targetLang.value;
         
         // Swap languages
         this.sourceLang.value = targetVal;
         // If source was auto, default to English or keep target if valid (simple fallback)
         this.targetLang.value = (sourceVal === 'auto') ? 'en' : sourceVal;

         // Swap text content
         const sourceTextVal = this.sourceText.value;
         const targetTextVal = this.translatedText.value;
         
         this.sourceText.value = targetTextVal;
         // Clear translated text to trigger fresh translation logic effectively or set it?
         // Usually set it, then translate.
         this.translatedText.value = sourceTextVal;
         
         this.updateCharCount();
         
         // Trigger translation
         if (this.sourceText.value.trim()) {
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

    showSuccess(message) {
        if(!this.successMessage) return;
        this.successMessage.textContent = message;
        this.successMessage.style.display = 'block';
        setTimeout(() => this.successMessage.style.display = 'none', 3000);
    }

    hideMessages() {
        if(this.errorMessage) this.errorMessage.style.display = 'none';
        if(this.successMessage) this.successMessage.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', () => new TranslateApp());
