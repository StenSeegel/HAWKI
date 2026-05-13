import { Utils } from './Utils.js';

export class TranscriptService {
    constructor(app) {
        this.app = app;
    }

    saveTranscriptSettings() {
        console.log("⚙️ saveTranscriptSettings aufgerufen");
        
        const provider = document.getElementById('settings-provider-select').value;
        const model = document.getElementById('settings-model-select').value;
        
        if (!provider || !model) {
            console.error("Provider oder Model fehlt beim Speichern.");
            return;
        }
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        
        fetch('/req/transcription-config', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                provider: provider,
                model: model
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                console.log("Einstellungen erfolgreich gespeichert.");
                this.app.ui.closeTranscriptSettings();
            } else {
                console.error("Fehler beim Speichern der Einstellungen:", data.message);
            }
        })
        .catch(err => {
            console.error("Fehler beim Speichern:", err);
        });
    }

    loadTranscriptConfig() {
        fetch('/req/transcription-config')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.data) {
                    const providerSelect = document.getElementById('settings-provider-select');
                    const modelSelect = document.getElementById('settings-model-select');
                    
                    if (!providerSelect || !modelSelect) return;

                    providerSelect.innerHTML = '';
                    modelSelect.innerHTML = '';
                    
                    const currentConfig = data.data.current;
                    const providers = data.data.providers || [];
                    
                    window.transcriptProviders = providers;
                    
                    if (providers.length === 0) {
                        providerSelect.innerHTML = '<option value="">Keine Provider verfügbar</option>';
                        modelSelect.innerHTML = '<option value="">-</option>';
                        return;
                    }
                    
                    providers.forEach(p => {
                        const opt = document.createElement('option');
                        opt.value = p.unique_name;
                        opt.text = p.provider_name || p.name || p.unique_name;
                        if (p.unique_name === currentConfig.provider.unique_name) {
                            opt.selected = true;
                        }
                        providerSelect.appendChild(opt);
                    });
                    
                    const populateModels = (providerUniqueName) => {
                        modelSelect.innerHTML = '';
                        const provider = providers.find(p => p.unique_name === providerUniqueName);
                        
                        if (provider && provider.models && provider.models.length > 0) {
                            provider.models.forEach(m => {
                                const opt = document.createElement('option');
                                const modelId = m.id || m.model_id || m.name || m;
                                opt.value = modelId;
                                opt.text = m.label || m.name || m.id || modelId;
                                if (modelId === currentConfig.model.model_id || modelId === currentConfig.model.id) {
                                    opt.selected = true;
                                }
                                modelSelect.appendChild(opt);
                            });
                        } else {
                            const opt = document.createElement('option');
                            opt.value = currentConfig.model.model_id || 'whisper-1';
                            opt.text = currentConfig.model.label || currentConfig.model.model_id || 'whisper-1';
                            opt.selected = true;
                            modelSelect.appendChild(opt);
                        }
                    };
                    
                    populateModels(providerSelect.value);
                    
                    providerSelect.addEventListener('change', (e) => {
                        populateModels(e.target.value);
                    });
                }
            })
            .catch(err => console.error("Error loading config:", err));
    }

    async saveTranscriptionToDatabase(transcriptionData, audioFile) {
        const response = await fetch('/req/transcription/save', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            },
            body: JSON.stringify({
                segments: transcriptionData.segments || null,
                words: transcriptionData.words || null,
                language: transcriptionData.language || 'de',
                duration: transcriptionData.duration || null,
                model_used: transcriptionData.model_used || transcriptionData.model || null,
                provider: transcriptionData.provider || null,
                original_filename: audioFile ? audioFile.name : null,
                file_size: audioFile ? audioFile.size : null,
                metadata: { timestamp: new Date().toISOString() }
            })
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.error);
        return result.transcription;
    }

    async updateTranscriptionTitle(slug, initialTitle) {
        try {
            const result = await Utils.fetchJsonWithRetry(`/req/transcription/${slug}`, {
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
            }, 1);
            if (result.success && result.transcription) {
                const newTitle = result.transcription.title;
                if (newTitle && newTitle !== initialTitle) {
                    let history = this.app.history.getLocalTranscriptionHistory();
                    const entry = history.find(e => e.slug === slug);
                    if (entry) {
                        entry.title = newTitle;
                        this.app.history.setLocalTranscriptionHistory(history);
                    }
                    this.app.history.renderHistory();
                    
                    if (this.app.state.currentTranscriptSlug === slug) {
                        const titleDiv = document.getElementById('current-transcript-title');
                        if (titleDiv) {
                            titleDiv.textContent = newTitle;
                            titleDiv.classList.remove('hidden');
                        }
                        const titleDivInline = document.getElementById('current-transcript-title-inline');
                        if (titleDivInline) {
                            titleDivInline.textContent = newTitle;
                            titleDivInline.classList.remove('hidden');
                        }
                    }
                    return true;
                }
            }
        } catch (err) { }
        return false;
    }

    pollForTitleUpdate(slug, initialTitle, maxAttempts = 5, interval = 2000) {
        let attempts = 0;
        const checkTitle = async () => {
            attempts++;
            if (await this.updateTranscriptionTitle(slug, initialTitle)) return;
            if (attempts < maxAttempts) setTimeout(checkTitle, interval);
        };
        setTimeout(checkTitle, interval);
    }
}
