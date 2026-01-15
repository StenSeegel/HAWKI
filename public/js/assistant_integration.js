/**
 * Assistant Integration for Chat
 * Handles assistant context injection into AI requests
 */

/**
 * Get current assistant from session
 */
function getCurrentAssistant() {
    const stored = sessionStorage.getItem('currentAssistant');
    return stored ? JSON.parse(stored) : null;
}

/**
 * Build system prompt with assistant context
 */
async function buildSystemPromptWithAssistant(basePrompt = '') {
    const assistant = getCurrentAssistant();
    
    if (!assistant) {
        return basePrompt;
    }
    
    try {
        // Fetch full assistant details with prompt template
        const response = await fetch(`/api/assistants/${assistant.id}`, {
            headers: {
                'Authorization': `Bearer ${window.sanctumToken}`,
                'Accept': 'application/json'
            }
        });
        
        if (!response.ok) {
            console.error('Failed to fetch assistant details');
            return basePrompt;
        }
        
        const data = await response.json();
        if (!data.success) {
            return basePrompt;
        }
        
        const fullAssistant = data.data;
        
        // Build combined prompt
        let combinedPrompt = fullAssistant.full_system_prompt || '';
        
        // Add file context if available
        if (fullAssistant.files && fullAssistant.files.length > 0) {
            combinedPrompt += '\n\n## Available Knowledge Base:\n';
            fullAssistant.files.forEach(file => {
                combinedPrompt += `- ${file.attachment.name}`;
                if (file.description) {
                    combinedPrompt += `: ${file.description}`;
                }
                combinedPrompt += '\n';
            });
        }
        
        // Append any user-provided base prompt
        if (basePrompt) {
            combinedPrompt += '\n\n' + basePrompt;
        }
        
        return combinedPrompt;
        
    } catch (error) {
        console.error('Error building assistant prompt:', error);
        return basePrompt;
    }
}

/**
 * Get assistant model override if set
 */
function getAssistantModelOverride() {
    const assistant = getCurrentAssistant();
    return assistant?.ai_model || null;
}

/**
 * Inject assistant context into AI request payload
 */
async function injectAssistantContext(payload) {
    const assistant = getCurrentAssistant();
    
    if (!assistant) {
        return payload;
    }
    
    // Get system prompt from first message or create new one
    const systemMessage = payload.messages?.find(m => m.role === 'system');
    const basePrompt = systemMessage?.content?.text || '';
    
    // Build enhanced prompt with assistant context
    const enhancedPrompt = await buildSystemPromptWithAssistant(basePrompt);
    
    // Update or add system message
    if (systemMessage) {
        systemMessage.content.text = enhancedPrompt;
    } else {
        if (!payload.messages) {
            payload.messages = [];
        }
        payload.messages.unshift({
            role: 'system',
            content: { text: enhancedPrompt }
        });
    }
    
    // Override model if assistant has preference and not forced by config
    const modelOverride = getAssistantModelOverride();
    if (modelOverride && !forceDefaultModel) {
        payload.model = modelOverride;
    }
    
    return payload;
}

/**
 * Show assistant context indicator in chat
 */
function showAssistantContextIndicator() {
    const assistant = getCurrentAssistant();
    
    if (!assistant) {
        return;
    }
    
    const chatInfo = document.querySelector('.chat-info');
    if (!chatInfo) {
        return;
    }
    
    let indicator = chatInfo.querySelector('.assistant-context-indicator');
    
    if (!indicator) {
        indicator = document.createElement('div');
        indicator.className = 'assistant-context-indicator';
        chatInfo.appendChild(indicator);
    }
    
    indicator.innerHTML = `
        <div class="assistant-badge">
            <span class="badge-icon">✨</span>
            <span class="badge-text">Using: ${assistant.name}</span>
            <button class="badge-close" onclick="clearCurrentAssistant()" title="Remove assistant">×</button>
        </div>
    `;
}

/**
 * Hide assistant context indicator
 */
function hideAssistantContextIndicator() {
    const indicator = document.querySelector('.assistant-context-indicator');
    if (indicator) {
        indicator.remove();
    }
}

// Add CSS for assistant indicator
if (typeof document !== 'undefined') {
    const style = document.createElement('style');
    style.textContent = `
        .assistant-context-indicator {
            margin-bottom: 1rem;
        }
        
        .assistant-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .assistant-badge .badge-icon {
            font-size: 1rem;
        }
        
        .assistant-badge .badge-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            line-height: 1;
            transition: background 0.2s ease;
        }
        
        .assistant-badge .badge-close:hover {
            background: rgba(255, 255, 255, 0.3);
        }
    `;
    document.head.appendChild(style);
}

// Update indicator when assistant changes
if (typeof window !== 'undefined') {
    const originalUpdateAssistantSelectorUI = window.updateAssistantSelectorUI;
    window.updateAssistantSelectorUI = function(assistant) {
        if (originalUpdateAssistantSelectorUI) {
            originalUpdateAssistantSelectorUI(assistant);
        }
        showAssistantContextIndicator();
    };
    
    const originalClearCurrentAssistant = window.clearCurrentAssistant;
    window.clearCurrentAssistant = function() {
        if (originalClearCurrentAssistant) {
            originalClearCurrentAssistant();
        }
        hideAssistantContextIndicator();
    };
}
