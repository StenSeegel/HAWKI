/**
 * Assistant Management Module
 * Handles assistant selection, creation, and management in the frontend
 */

class AssistantManager {
    constructor() {
        this.currentAssistant = null;
        this.assistants = {
            featured: [],
            my_assistants: [],
            favorites: [],
            organization: [],
            all: []
        };
        this.isLoading = false;
    }

    /**
     * Get auth headers for API requests
     */
    getAuthHeaders() {
        const headers = {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        };
        
        // Use Sanctum token if available (external communication)
        if (typeof window.sanctumToken !== 'undefined' && window.sanctumToken) {
            headers['Authorization'] = `Bearer ${window.sanctumToken}`;
        } else {
            // Fall back to CSRF token (internal communication)
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            if (csrfToken) {
                headers['X-CSRF-TOKEN'] = csrfToken;
            }
        }
        
        return headers;
    }

    async init() {
        await this.loadAssistants();
        this.setupEventListeners();
    }

    /**
     * Load all assistants from API
     */
    async loadAssistants() {
        if (this.isLoading) return;
        
        console.log('📡 AssistantManager.loadAssistants() called');
        this.isLoading = true;
        try {
            const headers = this.getAuthHeaders();
            console.log('🔑 Request headers:', headers);
            
            const response = await fetch('/api/assistants', {
                headers: headers
            });
            
            console.log('📥 API Response status:', response.status, response.statusText);

            if (!response.ok) {
                throw new Error('Failed to load assistants');
            }

            const data = await response.json();
            console.log('📦 API Response data:', data);
            
            if (data.success) {
                console.log('✅ Assistants loaded successfully:', data.data);
                this.assistants = data.data;
                this.assistants.all = [
                    ...data.data.featured,
                    ...data.data.my_assistants,
                    ...data.data.organization
                ];
            } else {
                console.error('❌ API returned success=false:', data);
            }
        } catch (error) {
            console.error('❌ Error loading assistants:', error);
        } finally {
            this.isLoading = false;
            console.log('🏁 loadAssistants() finished, isLoading:', this.isLoading);
        }
    }

    /**
     * Open assistant selection modal
     */
    openAssistantModal() {
        const modal = document.getElementById('assistant-modal');
        if (!modal) {
            console.error('Assistant modal not found');
            return;
        }
        
        modal.classList.add('active');
        this.renderAssistantGrid('featured');
    }

    /**
     * Close assistant modal
     */
    closeAssistantModal() {
        const modal = document.getElementById('assistant-modal');
        if (modal) {
            modal.classList.remove('active');
        }
    }

    /**
     * Render assistant grid based on category
     */
    renderAssistantGrid(category = 'featured') {
        const grid = document.getElementById('assistant-grid');
        if (!grid) return;

        const assistants = this.assistants[category] || [];
        
        if (assistants.length === 0) {
            grid.innerHTML = `
                <div class="empty-state">
                    <p>${translation.NoAssistantsFound || 'Keine Assistenten gefunden'}</p>
                </div>
            `;
            return;
        }

        grid.innerHTML = assistants.map(assistant => this.renderAssistantCard(assistant)).join('');
    }

    /**
     * Render assistant list for main view (sidebar)
     */
    renderAssistantList(category = 'featured') {
        const list = document.getElementById('assistants-list');
        const grid = document.getElementById('assistant-grid-main');
        
        const assistants = this.assistants[category] || [];
        
        if (assistants.length === 0) {
            const emptyHTML = `
                <div class="empty-state">
                    <p>${translation.NoAssistantsFound || 'Keine Assistenten gefunden'}</p>
                </div>
            `;
            if (list) list.innerHTML = emptyHTML;
            if (grid) grid.innerHTML = emptyHTML;
            return;
        }

        // Render in sidebar list
        if (list) {
            list.innerHTML = assistants.map(a => this.renderAssistantListItem(a)).join('');
        }
        
        // Render in main grid
        if (grid) {
            grid.innerHTML = assistants.map(assistant => this.renderAssistantCard(assistant)).join('');
        }
    }

    /**
     * Render assistant list item for sidebar
     */
    renderAssistantListItem(assistant) {
        return `
            <div class="selection-item assistant-list-item" data-assistant-id="${assistant.id}" onclick="assistantManager.selectAssistantMain(${assistant.id})">
                <div class="item-content">
                    <div class="assistant-avatar-small">
                        ${assistant.avatar_path ? `<img src="${assistant.avatar_path}" alt="${assistant.name}">` : `<span>${assistant.name.substring(0, 2).toUpperCase()}</span>`}
                    </div>
                    <div class="item-info">
                        <h4>${assistant.name}</h4>
                        <p>${assistant.description || ''}</p>
                    </div>
                </div>
            </div>
        `;
    }

    /**
     * Search in main view
     */
    searchAssistantsMain(query) {
        const searchLower = query.toLowerCase();
        const filtered = this.assistants.all.filter(a => 
            a.name.toLowerCase().includes(searchLower) ||
            (a.description && a.description.toLowerCase().includes(searchLower))
        );
        
        const grid = document.getElementById('assistant-grid-main');
        if (!grid) return;
        
        if (filtered.length === 0) {
            grid.innerHTML = `<div class="empty-state"><p>${translation.NoAssistantsFound || 'Keine Assistenten gefunden'}</p></div>`;
            return;
        }
        
        grid.innerHTML = filtered.map(a => this.renderAssistantCard(a)).join('');
    }

    /**
     * Select assistant in main view
     */
    async selectAssistantMain(assistantId) {
        try {
            const response = await fetch(`/api/assistants/${assistantId}`, {
                headers: {
                    'Authorization': `Bearer ${window.sanctumToken}`,
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error('Failed to load assistant');
            }

            const data = await response.json();
            if (data.success) {
                this.currentAssistant = data.data;
                
                // Track usage
                this.trackUsage(assistantId);
                
                // Show detail view (future implementation)
                console.log('Selected assistant:', data.data);
            }
        } catch (error) {
            console.error('Error selecting assistant:', error);
        }
    }

    /**
     * Render a single assistant card
     */
    renderAssistantCard(assistant) {
        const isFavorited = assistant.is_favorited || false;
        const handle = assistant.handle || assistant.name.toLowerCase().replace(/\s+/g, '');
        const description = assistant.description || 'Keine Beschreibung verfügbar';
        const cardId = `assistant-card-${assistant.id}`;
        
        const avatarHtml = assistant.avatar_path 
            ? `<img src="${assistant.avatar_path}" alt="${assistant.name}">`
            : `<div class="avatar-placeholder">${assistant.name.substring(0, 2).toUpperCase()}</div>`;

        return `
            <div class="assistant-card" id="${cardId}" data-assistant-id="${assistant.id}">
                <div class="assistant-card-header">
                    <span class="assistant-handle">@${handle}</span>
                </div>
                <div class="assistant-card-body">
                    <div class="assistant-card-image">
                        ${avatarHtml}
                    </div>
                    <div class="assistant-card-description">
                        <p class="description-text" id="desc-${assistant.id}">
                            ${description}
                        </p>
                        <button class="description-toggle" 
                                onclick="event.stopPropagation(); toggleDescription(${assistant.id})"
                                aria-label="Beschreibung ausklappen">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                                <path d="M4 6L8 10L12 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="assistant-card-footer">
                    <button class="favorite-btn ${isFavorited ? 'favorited' : ''}" 
                            onclick="event.stopPropagation(); assistantManager.toggleFavorite(${assistant.id})"
                            data-favorited="${isFavorited}"
                            aria-label="Zu Favoriten hinzufügen">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="${isFavorited ? 'currentColor' : 'none'}">
                            <path d="M10 3.5C10 3.5 6 0 3 3C0 6 0 10 3 13L10 20L17 13C20 10 20 6 17 3C14 0 10 3.5 10 3.5Z" 
                                  stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                        </svg>
                    </button>
                    <button class="try-btn" onclick="assistantManager.selectAssistant(${assistant.id})">
                        ${translation?.TryOut || 'Ausprobieren'}
                    </button>
                </div>
            </div>
        `;
    }

    /**
     * Select an assistant
     */
    async selectAssistant(assistantId) {
        try {
            const response = await fetch(`/api/assistants/${assistantId}`, {
                headers: this.getAuthHeaders()
            });

            if (!response.ok) {
                throw new Error('Failed to load assistant');
            }

            const data = await response.json();
            if (data.success) {
                this.currentAssistant = data.data;
                this.updateUIForAssistant(data.data);
                this.closeAssistantModal();
                
                // Track usage
                this.trackUsage(assistantId);
            }
        } catch (error) {
            console.error('Error selecting assistant:', error);
        }
    }

    /**
     * Update UI when assistant is selected
     */
    updateUIForAssistant(assistant) {
        // Update assistant selector if present
        if (typeof updateAssistantSelectorUI === 'function') {
            updateAssistantSelectorUI(assistant);
        }

        // Store in session for API requests
        sessionStorage.setItem('currentAssistant', JSON.stringify(assistant));
    }

    /**
     * Toggle favorite status
     */
    async toggleFavorite(assistantId) {
        try {
            const response = await fetch(`/api/assistants/${assistantId}/favorite`, {
                method: 'POST',
                headers: this.getAuthHeaders()
            });

            if (!response.ok) {
                throw new Error('Failed to toggle favorite');
            }

            const data = await response.json();
            if (data.success) {
                // Update UI
                const btn = document.querySelector(`[data-assistant-id="${assistantId}"] .favorite-btn`);
                if (btn) {
                    btn.classList.toggle('favorited', data.is_favorite);
                    btn.dataset.favorited = data.is_favorite;
                    const icon = btn.querySelector('i');
                    if (icon) {
                        icon.className = data.is_favorite ? 'icon-star-filled' : 'icon-star';
                    }
                }
                
                // Reload assistants to update favorites list
                await this.loadAssistants();
            }
        } catch (error) {
            console.error('Error toggling favorite:', error);
        }
    }

    /**
     * Track assistant usage
     */
    async trackUsage(assistantId) {
        try {
            await fetch(`/api/assistants/${assistantId}/use`, {
                method: 'POST',
                headers: this.getAuthHeaders()
            });
        } catch (error) {
            console.error('Error tracking usage:', error);
        }
    }

    /**
     * Filter assistants by category
     */
    filterByCategory(category) {
        // Update active category button
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        document.querySelector(`[data-category="${category}"]`)?.classList.add('active');
        
        // Render grid
        this.renderAssistantGrid(category);
    }

    /**
     * Search assistants
     */
    searchAssistants(query) {
        const searchLower = query.toLowerCase();
        const filtered = this.assistants.all.filter(a => 
            a.name.toLowerCase().includes(searchLower) ||
            (a.description && a.description.toLowerCase().includes(searchLower))
        );
        
        const grid = document.getElementById('assistant-grid');
        if (!grid) return;
        
        if (filtered.length === 0) {
            grid.innerHTML = `<div class="empty-state"><p>${translation.NoAssistantsFound || 'Keine Assistenten gefunden'}</p></div>`;
            return;
        }
        
        grid.innerHTML = filtered.map(a => this.renderAssistantCard(a)).join('');
    }

    /**
     * Setup event listeners
     */
    setupEventListeners() {
        // Search input
        const searchInput = document.getElementById('assistant-search');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.searchAssistants(e.target.value);
            });
        }

        // Category buttons
        document.querySelectorAll('.category-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const category = e.target.dataset.category;
                this.filterByCategory(category);
            });
        });

        // Modal close on backdrop
        const modal = document.getElementById('assistant-modal');
        if (modal) {
            const backdrop = modal.querySelector('.modal-backdrop');
            if (backdrop) {
                backdrop.addEventListener('click', () => this.closeAssistantModal());
            }
        }
    }

    /**
     * Open create assistant modal
     */
    openCreateAssistantModal() {
        const modal = document.getElementById('create-assistant-modal');
        if (modal) {
            modal.classList.add('active');
        }
    }

    /**
     * Close create assistant modal
     */
    closeCreateAssistantModal() {
        const modal = document.getElementById('create-assistant-modal');
        if (modal) {
            modal.classList.remove('active');
        }
    }
}

// Global instance
const assistantManager = new AssistantManager();

// Helper functions for global access
function openAssistantModal() {
    assistantManager.openAssistantModal();
}

function closeAssistantModal() {
    assistantManager.closeAssistantModal();
}

function openCreateAssistantModal() {
    assistantManager.openCreateAssistantModal();
}

function closeCreateAssistantModal() {
    assistantManager.closeCreateAssistantModal();
}

function filterAssistants(query) {
    assistantManager.searchAssistants(query);
}

// Initialize AssistantManager instance
window.assistantManager = new AssistantManager();
console.log('✅ AssistantManager instance created:', window.assistantManager);
