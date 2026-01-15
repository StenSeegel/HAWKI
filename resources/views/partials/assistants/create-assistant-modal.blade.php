{{-- Create Assistant Modal --}}
<div id="create-assistant-modal" class="modal create-assistant-modal">
    <div class="modal-backdrop" onclick="closeCreateAssistantModal()"></div>
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h2>{{ $translation['CreateNewAssistant'] ?? 'Neuen Assistenten erstellen' }}</h2>
            <button class="close-btn" onclick="closeCreateAssistantModal()">
                <x-icon name="x"/>
            </button>
        </div>
        
        <form id="create-assistant-form" onsubmit="submitCreateAssistant(event)">
            <div class="modal-body">
                {{-- Step 1: System Prompt --}}
                <div class="form-section">
                    <h3>1. {{ $translation['Instructions'] ?? 'Anweisungen' }}</h3>
                    <p class="form-hint">{{ $translation['InstructionsHint'] ?? 'Beschreiben Sie, wie sich der Assistent verhalten soll.' }}</p>
                    
                    <div class="form-group">
                        <textarea id="assistant-system-prompt" 
                                  name="full_system_prompt" 
                                  rows="8"
                                  required
                                  placeholder="{{ $translation['SystemPromptPlaceholder'] ?? 'Geben Sie detaillierte Anweisungen für den Assistenten ein...' }}"></textarea>
                    </div>
                </div>
                
                {{-- Step 2: Name --}}
                <div class="form-section">
                    <h3>2. {{ $translation['AssistantName'] ?? 'Name' }}</h3>
                    
                    <div class="form-group">
                        <input type="text" 
                               id="assistant-name" 
                               name="name" 
                               required 
                               maxlength="255"
                               placeholder="{{ $translation['AssistantNamePlaceholder'] ?? 'z.B. Mein Studienplaner' }}">
                    </div>
                    
                    {{-- Suggested chips --}}
                    <div class="suggestion-chips">
                        <button type="button" class="chip" onclick="fillAssistantName('@erstiplaner')">@erstiplaner</button>
                        <button type="button" class="chip" onclick="fillAssistantName('@starterhelfer')">@starterhelfer</button>
                        <button type="button" class="chip" onclick="fillAssistantName('@studieneinsteiger')">@studieneinsteiger</button>
                    </div>
                </div>
                
                {{-- Step 3: Description --}}
                <div class="form-section">
                    <h3>3. {{ $translation['AssistantDescription'] ?? 'Beschreibung' }}</h3>
                    
                    <div class="form-group">
                        <textarea id="assistant-description" 
                                  name="description" 
                                  rows="3"
                                  placeholder="{{ $translation['AssistantDescriptionPlaceholder'] ?? 'Beschreiben Sie, wofür dieser Assistent gedacht ist...' }}"></textarea>
                    </div>
                    
                    {{-- Suggested chips --}}
                    <div class="suggestion-chips">
                        <button type="button" class="chip" onclick="fillDescription('Hilft Erstis bei Semesterplanung')">Hilft Erstis bei Semesterplanung</button>
                        <button type="button" class="chip" onclick="fillDescription('Organisiert den Studienstart')">Organisiert den Studienstart</button>
                    </div>
                </div>
                
                {{-- Step 4: Categories --}}
                <div class="form-section">
                    <h3>4. Kategorie</h3>
                    
                    <div class="category-chips">
                        <label class="chip-checkbox">
                            <input type="checkbox" name="category" value="Studienorganisation">
                            <span>Studienorganisation</span>
                        </label>
                        <label class="chip-checkbox">
                            <input type="checkbox" name="category" value="Studienplanung">
                            <span>Studienplanung</span>
                        </label>
                        <label class="chip-checkbox">
                            <input type="checkbox" name="category" value="Design Thinking">
                            <span>Design Thinking</span>
                        </label>
                        <label class="chip-checkbox">
                            <input type="checkbox" name="category" value="Typografie">
                            <span>Typografie</span>
                        </label>
                        <label class="chip-checkbox">
                            <input type="checkbox" name="category" value="Portfolioaufbau">
                            <span>Portfolioaufbau</span>
                        </label>
                        <label class="chip-checkbox">
                            <input type="checkbox" name="category" value="Sonstige">
                            <span>Sonstige</span>
                        </label>
                    </div>
                </div>
                
                {{-- Step 5: Model Selection --}}
                <div class="form-section">
                    <h3>5. {{ $translation['AIModel'] ?? 'KI-Modell' }}</h3>
                    <p class="form-hint">{{ $translation['ModelHint'] ?? 'Wählen Sie ein empfohlenes Modell.' }}</p>
                    
                    <div class="form-group">
                        <select id="assistant-model" name="ai_model">
                            <option value="">{{ $translation['UseDefaultModel'] ?? 'Standard-Modell verwenden' }}</option>
                            {{-- Populated by JavaScript from available models --}}
                        </select>
                    </div>
                </div>
                
                {{-- Step 6: Visibility --}}
                <div class="form-section">
                    <h3>6. {{ $translation['Visibility'] ?? 'Sichtbarkeit' }}</h3>
                    
                    <div class="visibility-options">
                        <label class="visibility-option">
                            <input type="radio" 
                                   name="visibility" 
                                   value="private" 
                                   checked
                                   required>
                            <span class="option-content">
                                <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <rect x="5" y="8" width="10" height="8" rx="1" stroke="currentColor" stroke-width="1.5"/>
                                    <path d="M7 8V6C7 4.34315 8.34315 3 10 3C11.6569 3 13 4.34315 13 6V8" stroke="currentColor" stroke-width="1.5"/>
                                </svg>
                                <span class="option-label">{{ $translation['Private'] ?? 'Privat' }}</span>
                                <span class="option-desc">{{ $translation['PrivateDesc'] ?? 'Nur für Sie sichtbar' }}</span>
                            </span>
                        </label>
                        
                        <label class="visibility-option">
                            <input type="radio" 
                                   name="visibility" 
                                   value="public">
                            <span class="option-content">
                                <x-icon name="world"/>
                                <span class="option-label">{{ $translation['Public'] ?? 'Öffentlich' }}</span>
                                <span class="option-desc">{{ $translation['PublicDesc'] ?? 'Für alle Nutzer sichtbar' }}</span>
                            </span>
                        </label>
                    </div>
                    
                    <p class="form-hint public-hint" style="display: none;">
                        <x-icon name="info"/>
                        Öffentliche Assistenten werden vor der Veröffentlichung geprüft.
                    </p>
                </div>
                
                {{-- Step 7: Conversation Starters --}}
                <div class="form-section">
                    <h3>7. {{ $translation['ConversationStarters'] ?? 'Gesprächsstarter' }}</h3>
                    <p class="form-hint">{{ $translation['ConversationStartersHint'] ?? 'Geben Sie Beispielfragen ein.' }}</p>
                    
                    <div id="conversation-starters">
                        <div class="starter-input">
                            <input type="text" 
                                   name="conversation_starters[]" 
                                   maxlength="200"
                                   placeholder="{{ $translation['StarterPlaceholder'] ?? 'z.B. Erkläre mir...' }}">
                        </div>
                    </div>
                    <button type="button" 
                            class="btn-link" 
                            onclick="addConversationStarter()"
                            id="add-starter-btn">
                        + {{ $translation['AddStarter'] ?? 'Weiteren Starter hinzufügen' }}
                    </button>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" 
                        class="btn-secondary" 
                        onclick="closeCreateAssistantModal()">
                    {{ $translation['Cancel'] ?? 'Abbrechen' }}
                </button>
                <button type="submit" class="btn-primary" id="submit-assistant-btn">
                    {{ $translation['CreateAssistant'] ?? 'Assistent erstellen' }}
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Helper functions for create assistant modal
function fillAssistantName(name) {
    document.getElementById('assistant-name').value = name;
}

function fillDescription(desc) {
    document.getElementById('assistant-description').value = desc;
}

function addConversationStarter() {
    const container = document.getElementById('conversation-starters');
    const starterInputs = container.querySelectorAll('.starter-input');
    
    if (starterInputs.length >= 4) {
        document.getElementById('add-starter-btn').style.display = 'none';
        return;
    }
    
    const newInput = document.createElement('div');
    newInput.className = 'starter-input';
    newInput.innerHTML = `
        <input type="text" 
               name="conversation_starters[]" 
               maxlength="200"
               placeholder="${translation.StarterPlaceholder || 'z.B. Erkläre mir...'}">
        <button type="button" class="btn-icon" onclick="this.parentElement.remove(); updateStarterButton()">
            <x-icon name="x"/>
        </button>
    `;
    container.appendChild(newInput);
    
    if (starterInputs.length >= 3) {
        document.getElementById('add-starter-btn').style.display = 'none';
    }
}

function updateStarterButton() {
    const starterInputs = document.querySelectorAll('#conversation-starters .starter-input');
    document.getElementById('add-starter-btn').style.display = starterInputs.length < 4 ? 'block' : 'none';
}

// Toggle public hint visibility
document.querySelectorAll('input[name="visibility"]').forEach(radio => {
    radio.addEventListener('change', (e) => {
        const hint = document.querySelector('.public-hint');
        if (hint) {
            hint.style.display = e.target.value === 'public' ? 'block' : 'none';
        }
    });
});

// Submit create assistant form
async function submitCreateAssistant(event) {
    event.preventDefault();
    
    const form = event.target;
    const submitBtn = document.getElementById('submit-assistant-btn');
    submitBtn.disabled = true;
    submitBtn.textContent = translation.Loading || 'Wird erstellt...';
    
    try {
        const formData = new FormData(form);
        
        // Get selected category (first checked)
        const selectedCategory = form.querySelector('input[name="category"]:checked');
        const category = selectedCategory ? selectedCategory.value : null;
        
        // Build conversation starters array
        const starters = Array.from(formData.getAll('conversation_starters[]'))
            .filter(s => s.trim() !== '');
        
        const data = {
            name: formData.get('name'),
            description: formData.get('description') || null,
            full_system_prompt: formData.get('full_system_prompt'),
            ai_model: formData.get('ai_model') || null,
            visibility: formData.get('visibility'),
            category: category,
            conversation_starters: starters.length > 0 ? starters : null
        };
        
        const response = await fetch('/api/assistants', {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${window.sanctumToken}`,
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        if (!response.ok) {
            throw new Error('Failed to create assistant');
        }
        
        const result = await response.json();
        
        if (result.success) {
            // Show success message
            alert(translation.AssistantCreated || 'Assistent erfolgreich erstellt!');
            
            // Close modal and reload
            closeCreateAssistantModal();
            form.reset();
            
            // Reload assistants
            if (window.assistantManager) {
                await assistantManager.loadAssistants();
            }
        }
    } catch (error) {
        console.error('Error creating assistant:', error);
        alert('Fehler beim Erstellen des Assistenten');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = translation.CreateAssistant || 'Assistent erstellen';
    }
}
</script>
