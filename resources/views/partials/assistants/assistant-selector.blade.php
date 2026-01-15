{{-- Assistant Selector Button Component --}}
<div class="assistant-selector" id="assistant-selector">
    <button class="assistant-selector-btn" onclick="openAssistantModal()" title="{{ $translation['SelectAssistant'] ?? 'Assistent auswählen' }}">
        <div class="assistant-avatar-mini" id="current-assistant-avatar">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M8 1L9 5L13 6L9 7L8 11L7 7L3 6L7 5L8 1Z" fill="currentColor"/>
                <path d="M12 9L12.5 10.5L14 11L12.5 11.5L12 13L11.5 11.5L10 11L11.5 10.5L12 9Z" fill="currentColor"/>
            </svg>
        </div>
        <span class="assistant-name">{{ $translation['SelectAssistant'] ?? 'Assistent wählen' }}</span>
        <x-icon name="chevron-down" class="chevron"/>
    </button>
    
    {{-- Quick actions when assistant is selected --}}
    <div class="assistant-quick-actions" id="assistant-quick-actions" style="display: none;">
        <button class="quick-action-btn" onclick="clearCurrentAssistant()" title="{{ $translation['ClearAssistant'] ?? 'Assistent entfernen' }}">
            <x-icon name="x"/>
        </button>
    </div>
</div>

<style>
.assistant-selector {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.assistant-selector-btn {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.625rem 1rem;
    background: var(--bg-secondary, #f8f9fa);
    border: 1px solid var(--border-color, #e0e0e0);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.assistant-selector-btn:hover {
    background: var(--bg-hover, #e9ecef);
    border-color: var(--primary-color, #007bff);
}

.assistant-avatar-mini {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: var(--primary-gradient, linear-gradient(135deg, #667eea 0%, #764ba2 100%));
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;
}

.assistant-avatar-mini img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 8px;
}

.assistant-name {
    flex: 1;
    text-align: left;
    font-weight: 500;
    font-size: 0.9rem;
    color: var(--text-primary, #333);
}

.assistant-selector-btn .chevron {
    opacity: 0.5;
    transition: transform 0.2s ease;
}

.assistant-selector-btn:hover .chevron {
    opacity: 1;
}

.quick-action-btn {
    padding: 0.5rem;
    background: white;
    border: 1px solid var(--border-color, #e0e0e0);
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.quick-action-btn:hover {
    background: var(--danger-light, #ffe6e6);
    border-color: var(--danger-color, #dc3545);
}
</style>

<script>
function clearCurrentAssistant() {
    sessionStorage.removeItem('currentAssistant');
    
    // Reset UI
    const avatarEl = document.getElementById('current-assistant-avatar');
    const nameEl = document.querySelector('.assistant-selector .assistant-name');
    const quickActions = document.getElementById('assistant-quick-actions');
    
    if (avatarEl) {
        avatarEl.innerHTML = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M8 1L9 5L13 6L9 7L8 11L7 7L3 6L7 5L8 1Z" fill="currentColor"/><path d="M12 9L12.5 10.5L14 11L12.5 11.5L12 13L11.5 11.5L10 11L11.5 10.5L12 9Z" fill="currentColor"/></svg>';
    }
    
    if (nameEl) {
        nameEl.textContent = translation.SelectAssistant || 'Assistent wählen';
    }
    
    if (quickActions) {
        quickActions.style.display = 'none';
    }
    
    if (window.assistantManager) {
        assistantManager.currentAssistant = null;
    }
}

// Update UI when assistant is selected (called from assistant_manager.js)
function updateAssistantSelectorUI(assistant) {
    const avatarEl = document.getElementById('current-assistant-avatar');
    const nameEl = document.querySelector('.assistant-selector .assistant-name');
    const quickActions = document.getElementById('assistant-quick-actions');
    
    if (avatarEl) {
        if (assistant.avatar_path) {
            avatarEl.innerHTML = `<img src="${assistant.avatar_path}" alt="${assistant.name}">`;
        } else {
            const initials = assistant.name.substring(0, 2).toUpperCase();
            avatarEl.innerHTML = `<span>${initials}</span>`;
        }
    }
    
    if (nameEl) {
        nameEl.textContent = assistant.name;
    }
    
    if (quickActions) {
        quickActions.style.display = 'flex';
    }
}
</script>
