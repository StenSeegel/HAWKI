{{-- Assistant Selection Modal --}}
<div id="assistant-modal" class="modal assistant-modal">
    <div class="modal-backdrop" onclick="closeAssistantModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2>{{ $translation['SelectAssistant'] ?? 'Assistent auswählen' }}</h2>
            <button class="close-btn" onclick="closeAssistantModal()">
                <x-icon name="x"/>
            </button>
        </div>
        
        <div class="modal-body">
            {{-- Search Bar --}}
            <div class="search-bar">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="7" cy="7" r="5.5" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M11 11L14 14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <input type="text" 
                       id="assistant-search" 
                       placeholder="{{ $translation['SearchAssistants'] ?? 'Assistenten durchsuchen...' }}"
                       oninput="filterAssistants(this.value)">
            </div>
            
            {{-- Category Tabs --}}
            <div class="assistant-categories">
                <button class="category-btn active" data-category="featured">
                    {{ $translation['Featured'] ?? 'Empfohlen' }}
                </button>
                <button class="category-btn" data-category="my_assistants">
                    {{ $translation['MyAssistants'] ?? 'Meine Assistenten' }}
                </button>
                <button class="category-btn" data-category="favorites">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8 2L10 6L14 7L11 10L12 14L8 12L4 14L5 10L2 7L6 6L8 2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                    </svg>
                    {{ $translation['Favorites'] ?? 'Favoriten' }}
                </button>
                <button class="category-btn" data-category="all">
                    {{ $translation['AllAssistants'] ?? 'Alle' }}
                </button>
            </div>
            
            {{-- Assistant Grid --}}
            <div class="assistant-grid" id="assistant-grid">
                {{-- Populated by JavaScript --}}
                <div class="loading-state">
                    <div class="spinner"></div>
                    <p>{{ $translation['Loading'] ?? 'Laden...' }}</p>
                </div>
            </div>
        </div>
        
        <div class="modal-footer">
            <button class="btn-secondary" onclick="openCreateAssistantModal()">
                <x-icon name="plus"/>
                {{ $translation['CreateNewAssistant'] ?? 'Neuen Assistenten erstellen' }}
            </button>
        </div>
    </div>
</div>
