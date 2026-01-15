@extends('layouts.home')
@section('content')
<div class="main-panel-grid assistants-module">
	<div class="dy-sidebar expanded" id="assistants-sidebar">
		<div class="dy-sidebar-wrapper">
			<div class="header">
				<h3 class="title">{{ $translation["MyAssistants"] ?? "Meine Assistenten" }}</h3>
				<button class="btn-md-stroke" onclick="switchAssistantView('create')">
					<div class="icon">
						<x-icon name="plus"/>
					</div>
					<div class="label"><strong>{{ $translation["CreateNewAssistant"] ?? "Neuen Assistenten erstellen" }}</strong></div>
				</button>
			</div>
			
			<div class="assistant-nav-links">
				<a href="#" class="nav-link active" data-category="featured" onclick="filterAssistantsByCategory('featured'); return false;">
					{{ $translation['Suggestions'] ?? 'Vorschläge' }}
				</a>
				<a href="#" class="nav-link" data-category="store" onclick="filterAssistantsByCategory('store'); return false;">
					{{ $translation['Store'] ?? 'Store' }}
				</a>
				<a href="#" class="nav-link" data-category="favorites" onclick="filterAssistantsByCategory('favorites'); return false;">
					{{ $translation['Favorites'] ?? 'Favoriten' }}
				</a>
				<a href="#" class="nav-link" data-category="my_assistants" onclick="filterAssistantsByCategory('my_assistants'); return false;">
					{{ $translation['MyAssistants'] ?? 'Meine Assistenten' }}
				</a>
			</div>

			<div class="dy-sidebar-content-panel">
				<div class="dy-sidebar-scroll-panel">
					<div class="assistant-list" id="assistants-list">
						<!-- Populated by JavaScript -->
					</div>
				</div>
			</div>

			<div class="dy-sidebar-expand-btn" onclick="togglePanelClass('assistants-sidebar', 'expanded')">
				<x-icon name="chevron-right"/>
			</div>
		</div>
	</div>

	<div class="dy-main-panel">
		<!-- Browse View -->
		<div class="assistant-view" id="browse-view">
			<div class="assistant-browse-header">
				<div class="search-bar-main">
					<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
						<circle cx="8.5" cy="8.5" r="6.5" stroke="currentColor" stroke-width="2"/>
						<path d="M13 13L17 17" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
					</svg>
					<input type="text" 
						   id="assistant-search-main" 
						   placeholder="{{ $translation['SearchAssistants'] ?? 'Assistenten durchsuchen...' }}"
						   oninput="searchAssistantsMain(this.value)">
				</div>
			</div>

			<div class="assistant-grid-main" id="assistant-grid-main">
				<div class="loading-state">
					<div class="spinner"></div>
					<p>{{ $translation['Loading'] ?? 'Laden...' }}</p>
				</div>
			</div>
		</div>

		<!-- Create View -->
		<div class="assistant-view" id="create-view" style="display: none;">
			<div class="assistant-create-header">
				<button class="btn-back" onclick="switchAssistantView('browse')">
					<x-icon name="chevron-left"/>
					{{ $translation['Back'] ?? 'Zurück' }}
				</button>
				<h2>{{ $translation['CreateNewAssistant'] ?? 'Neuen Assistenten erstellen' }}</h2>
			</div>

			<div class="assistant-create-content-wrapper">
				<div class="main-content-card">
					<div class="card-title">
						<p>{{ $translation['CreateAssistant'] ?? 'Assistent erstellen' }}</p>
						<button type="button" class="icon-btn-info">
							<x-icon name="info"/>
						</button>
					</div>

					<form id="create-assistant-form-main" onsubmit="submitCreateAssistantMain(event)">
						<div class="create-steps-container">
							<!-- Step 1: System Prompt -->
							<div class="step-modern" data-step="1">
								<div class="step-header">
									<span class="step-num">1</span>
									<p class="step-instruction">Wie soll dein Assistent sich verhalten? Schreibe einen System Prompt.</p>
								</div>
								<div class="modern-input-field">
									<textarea id="assistant-system-prompt-main" 
											  name="full_system_prompt" 
											  rows="4"
											  required
											  placeholder="System Prompt schreiben"></textarea>
									<div class="trailing-icon"><x-icon name="edit"/></div>
								</div>
							</div>
							
							<!-- Step 2: Name -->
							<div class="step-modern" data-step="2">
								<div class="step-header">
									<span class="step-num">2</span>
									<p class="step-instruction">Wie soll dein Assistent heißen? Gebe deinem Assistenten einen Namen.</p>
								</div>
								<div class="modern-input-field">
									<input type="text" 
										   id="assistant-name-main" 
										   name="name" 
										   required 
										   maxlength="255"
										   placeholder="Name hinzufügen">
								</div>
							</div>
							
							<!-- Step 3: Description -->
							<div class="step-modern" data-step="3">
								<div class="step-header">
									<span class="step-num">3</span>
									<p class="step-instruction">Was sollen andere über deinen Assistenten wissen? Beschreibe die Funktion deines Assistenten.</p>
								</div>
								<div class="modern-input-field">
									<input type="text" 
										   id="assistant-description-main" 
										   name="description" 
										   maxlength="255"
										   placeholder="Funktion beschreiben">
								</div>
							</div>
							
							<!-- Step 4: Category -->
							<div class="step-modern" data-step="4">
								<div class="step-header">
									<span class="step-num">4</span>
									<p class="step-instruction">Welcher Kategorie soll dein Assistent zugeordnet werden? Wähle eine oder mehrere Kategorien.</p>
								</div>
								<div class="modern-chip-container">
									@foreach(['Studienorganisation', 'Studienplanung', 'Design Thinking', 'Typografie', 'Portfolioaufbau', 'Sonstige'] as $cat)
									<label class="modern-chip">
										<input type="checkbox" name="category[]" value="{{ $cat }}">
										<span>{{ $cat }}</span>
									</label>
									@endforeach
								</div>
							</div>
							
							<!-- Step 5: Model Selection -->
							<div class="step-modern" data-step="5">
								<div class="step-header">
									<span class="step-num">5</span>
									<p class="step-instruction">Welches Modell möchtest du nutzen? Wähle das Modell für die beste Leistung.</p>
								</div>
								<div class="modern-select-field">
									<select id="assistant-model-main" name="ai_model">
										<option value="">Standard-Modell verwenden</option>
									</select>
									<div class="select-chevron"><x-icon name="chevron-down"/></div>
								</div>
							</div>
							
							<!-- Step 6: Visibility -->
							<div class="step-modern" data-step="6">
								<div class="step-header">
									<span class="step-num">6</span>
									<p class="step-instruction">Soll dein Assistent privat oder öffentlich sein? Bestimme die Sichtbarkeit.</p>
								</div>
								<div class="modern-radio-group">
									<label class="modern-radio-item">
										<input type="radio" name="visibility" value="private" checked required>
										<span class="radio-content">
											<span class="radio-circle"></span>
											<span class="radio-text">Privat (Nur für dich sichtbar)</span>
										</span>
									</label>
									<label class="modern-radio-item">
										<input type="radio" name="visibility" value="public">
										<span class="radio-content">
											<span class="radio-circle"></span>
											<span class="radio-text">Öffentlich (Wird im Store veröffentlicht)</span>
										</span>
									</label>
									<div class="modern-notice">
										<p><strong>Hinweis:</strong> Öffentliche Assistenten werden vor der Veröffentlichung geprüft.</p>
									</div>
								</div>
							</div>

							<!-- Step 7: Title Image -->
							<div class="step-modern" data-step="7">
								<div class="step-header">
									<span class="step-num">7</span>
									<p class="step-instruction">Wie sieht dein Assistent aus? Füge ein Titelbild (optional).</p>
								</div>
								<div class="modern-file-uploader" id="avatar-uploader">
									<div class="upload-icon">
										<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
											<path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
									</div>
									<p class="upload-text">Ziehe das Bild in die Fläche zum Hochladen (png, jpg, svg).</p>
									<p class="upload-separator">– oder –</p>
									<button type="button" class="modern-btn-filled" onclick="document.getElementById('avatar-input').click()">Upload</button>
									<input type="file" id="avatar-input" name="avatar" hidden accept="image/*">
								</div>
							</div>

							<!-- Step 8: Datasets -->
							<div class="step-modern" data-step="8">
								<div class="step-header">
									<span class="step-num">8</span>
									<p class="step-instruction">Soll der Assistent bestimmte Dateien durchsuchen? Wähle die Datensätze aus (optional).</p>
								</div>
								<div class="modern-expansion-panel">
									<div class="expansion-item">
										<div class="expansion-header">
											<input type="checkbox" id="select-all-datasets">
											<label for="select-all-datasets">Alle auswählen</label>
										</div>
									</div>
									@foreach(['Datensätze', 'Dokumente', 'Webseiten', 'Cloud', 'Wiki'] as $source)
									<div class="expansion-item">
										<div class="expansion-header" onclick="toggleExpansion(this)">
											<div class="header-left">
												<input type="checkbox" name="sources[]" value="{{ $source }}" onclick="event.stopPropagation()">
												<span>{{ $source }}</span>
											</div>
											<x-icon name="chevron-down"/>
										</div>
										<div class="expansion-content" style="display: none;">
											<!-- Content populated dynamically -->
											<p class="empty-hint">Keine {{ $source }} verfügbar.</p>
										</div>
									</div>
									@endforeach
								</div>
								<div class="modern-file-uploader mt-4">
									<div class="upload-icon">
										<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
											<path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" stroke-linecap="round" stroke-linejoin="round"/>
										</svg>
									</div>
									<p class="upload-text">Ziehe eigene Datei (Datensatz) in die Fläche zum Hochladen (pdf).</p>
									<p class="upload-separator">– oder –</p>
									<button type="button" class="modern-btn-filled">Upload</button>
								</div>
							</div>
						</div>

						<div class="modern-form-actions">
							<button type="button" class="modern-btn-outlined" onclick="switchAssistantView('browse')">Zurücksetzen</button>
							<button type="submit" class="modern-btn-filled disabled" id="submit-assistant-btn-main">Starten</button>
						</div>
					</form>
				</div>
			</div>
		</div>

		<!-- Detail View (for future use) -->
		<div class="assistant-view" id="detail-view" style="display: none;">
			<!-- Assistant detail view will go here -->
		</div>
	</div>
</div>

<script>
// Ensure translation exists as fallback
if (typeof translation === 'undefined') {
	window.translation = {
		Loading: 'Laden...',
		CreateAssistant: 'Assistent erstellen',
		NoAssistantsFound: 'Keine Assistenten gefunden',
		AssistantCreated: 'Assistent erfolgreich erstellt!',
		StarterPlaceholder: 'z.B. Erkläre mir...'
	};
	console.warn('⚠️ Translation object not found, using fallback');
}

// View switching
function switchAssistantView(view) {
	document.querySelectorAll('.assistant-view').forEach(v => v.style.display = 'none');
	document.getElementById(view + '-view').style.display = 'flex';
}

function toggleExpansion(header) {
	const content = header.nextElementSibling;
	const icon = header.querySelector('x-icon[name="chevron-down"], svg');
	if (content.style.display === 'none') {
		content.style.display = 'block';
		if (icon) icon.style.transform = 'rotate(180deg)';
	} else {
		content.style.display = 'none';
		if (icon) icon.style.transform = 'rotate(0deg)';
	}
}

// Category filtering
function filterAssistantsByCategory(category) {
	// Update nav links active state
	document.querySelectorAll('.assistant-nav-links .nav-link').forEach(link => link.classList.remove('active'));
	document.querySelector(`.assistant-nav-links [data-category="${category}"]`)?.classList.add('active');
	
	if (window.assistantManager) {
		assistantManager.renderAssistantList(category);
	}
}

// Search
function searchAssistantsMain(query) {
	if (window.assistantManager) {
		assistantManager.searchAssistantsMain(query);
	}
}

// Toggle description expand/collapse
function toggleDescription(assistantId) {
	const desc = document.getElementById(`desc-${assistantId}`);
	const card = document.getElementById(`assistant-card-${assistantId}`);
	
	if (desc && card) {
		card.classList.toggle('description-expanded');
	}
}

// Add conversation starter
function addConversationStarterMain() {
	const container = document.getElementById('conversation-starters-main');
	const starterInputs = container.querySelectorAll('.starter-input');
	
	if (starterInputs.length >= 4) {
		document.getElementById('add-starter-btn-main').style.display = 'none';
		return;
	}
	
	const newInput = document.createElement('div');
	newInput.className = 'starter-input';
	newInput.innerHTML = `
		<input type="text" 
			   name="conversation_starters[]" 
			   maxlength="200"
			   placeholder="${translation.StarterPlaceholder || 'z.B. Erkläre mir...'}">
		<button type="button" class="btn-icon" onclick="this.parentElement.remove(); updateStarterButtonMain()">
			<x-icon name="x"/>
		</button>
	`;
	container.appendChild(newInput);
	
	if (starterInputs.length >= 3) {
		document.getElementById('add-starter-btn-main').style.display = 'none';
	}
}

function updateStarterButtonMain() {
	const starterInputs = document.querySelectorAll('#conversation-starters-main .starter-input');
	document.getElementById('add-starter-btn-main').style.display = starterInputs.length < 4 ? 'block' : 'none';
}

// Toggle public hint
document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('input[name="visibility"]').forEach(radio => {
		radio.addEventListener('change', (e) => {
			const hint = document.querySelector('.public-hint');
			if (hint) {
				hint.style.display = e.target.value === 'public' ? 'block' : 'none';
			}
		});
	});

	// Populate model dropdown
	if (typeof modelsList !== 'undefined') {
		const select = document.getElementById('assistant-model-main');
		if (select) {
			modelsList.forEach(model => {
				const option = document.createElement('option');
				option.value = model.id;
				option.textContent = model.label || model.name || model.id;
				select.appendChild(option);
			});
		}
	}
});

// Submit form
async function submitCreateAssistantMain(event) {
	event.preventDefault();
	
	const form = event.target;
	const submitBtn = document.getElementById('submit-assistant-btn-main');
	submitBtn.disabled = true;
	submitBtn.textContent = translation.Loading || 'Wird erstellt...';
	
	try {
		const formData = new FormData(form);
		
		const selectedCategory = form.querySelector('input[name="category"]:checked');
		const category = selectedCategory ? selectedCategory.value : null;
		
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
			headers: window.assistantManager ? 
				window.assistantManager.getAuthHeaders() : 
				{
					'Accept': 'application/json',
					'Content-Type': 'application/json',
					'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]').getAttribute('content')
				},
			body: JSON.stringify(data)
		});
		
		if (!response.ok) {
			throw new Error('Failed to create assistant');
		}
		
		const result = await response.json();
		
		if (result.success) {
			alert(translation.AssistantCreated || 'Assistent erfolgreich erstellt!');
			form.reset();
			switchAssistantView('browse');
			
			if (window.assistantManager) {
				await assistantManager.loadAssistants();
				assistantManager.renderAssistantList('my_assistants');
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

// Initialize on load
window.addEventListener('DOMContentLoaded', async function() {
	console.log('🔍 Assistants module - DOMContentLoaded');
	console.log('assistantManager exists:', !!window.assistantManager);
	console.log('activeModule:', typeof activeModule !== 'undefined' ? activeModule : 'undefined');
	console.log('translation exists:', typeof translation !== 'undefined');
	
	if (window.assistantManager) {
		console.log('🚀 Initializing AssistantManager...');
		await assistantManager.init();
		assistantManager.renderAssistantList('featured');
		console.log('✅ AssistantManager initialized');
	} else {
		console.error('❌ AssistantManager not found!');
	}
});
</script>
@endsection
