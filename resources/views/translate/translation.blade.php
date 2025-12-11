@extends('layouts.home')

@section('content')
<style>
    @import url('/css/translate.css');
</style>
<div class="main-panel-grid" style="display: flex; justify-content: center; align-items: flex-start;">
    <div style="width: 100%; max-width: 1400px; display: flex; flex-direction: column; align-items: center; padding: 20px;">
        <div style="text-align: center; margin-bottom: 40px; margin-top: 0;">
            <h1 style="font-size: 2.5rem; font-weight: 700; margin-bottom: 10px; background: linear-gradient(135deg, #3b82f6, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">Translate | Writing</h1>
            <p style="color: #6b7280; font-size: 1rem;">Der sichere Übersetzungsdienst des Hochschulrechenzentrums. Dieser Übersetzer kann verwendet werden, um Daten zu verarbeiten, die die JLU nicht verlassen sollen. Deine Eingaben werden von einem lokalen Sprachmodell des HRZs verarbeitet.</p>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; width: 100%; margin-bottom: 30px; position: relative;" class="translate-wrapper">
            <!-- Source Language Panel -->
            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); display: flex; flex-direction: column;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #e5e7eb;">
                    <span style="font-size: 0.875rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">Eingabetext</span>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <select id="sourceLang" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; background: white; color: #111827; font-size: 0.875rem; cursor: pointer; min-width: 150px;">
                            <option value="auto">Auto Detect</option>
                            <option value="en">English</option>
                            <option value="de">Deutsch</option>
                            <option value="fr">Français</option>
                            <option value="es">Español</option>
                            <option value="it">Italiano</option>
                            <option value="nl">Nederlands</option>
                            <option value="pl">Polski</option>
                            <option value="pt">Português</option>
                            <option value="ru">Русский</option>
                            <option value="zh">中文</option>
                            <option value="ja">日本語</option>
                        </select>
                        <select id="aiModel" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; background: white; color: #111827; font-size: 0.875rem; cursor: pointer; min-width: 150px; display: none;">
                            <!-- Models will be loaded dynamically -->
                        </select>
                    </div>
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; position: relative;">
                    <textarea id="sourceText" placeholder="Text zum Überarbeiten eingeben..." maxlength="50000" style="width: 100%; min-height: 250px; padding: 15px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb; color: #111827; font-size: 0.95rem; font-family: inherit; resize: vertical;"></textarea>
                    <div style="margin-top: 10px; font-size: 0.75rem; color: #6b7280; text-align: right;">
                        <span id="charCount">0</span> / 50,000 characters
                    </div>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 15px;">
                   
                    <button id="translateBtn" style="padding: 10px 20px; border: none; border-radius: 6px; font-size: 0.875rem; font-weight: 600; cursor: pointer; background: #3b82f6; color: white; flex: 1; display: flex; align-items: center; justify-content: center; gap: 8px;">
                        <span>Translate</span>
                    </button>
                </div>
            </div>

            <!-- Target Language Panel -->
            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 25px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07); display: flex; flex-direction: column; position: relative;" class="output-panel">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #e5e7eb;">
                    <div style="display: inline-flex; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
                        <button id="translationModeBtn" style="padding: 8px 16px; border: none; background: #3b82f6; color: white; font-size: 0.875rem; font-weight: 600; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.2s;">Übersetzung</button>
                        <button id="writingModeBtn" style="padding: 8px 16px; border: none; background: white; color: #6b7280; font-size: 0.875rem; font-weight: 600; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; transition: all 0.2s;">Schreiben</button>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <select id="targetLang" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; background: white; color: #111827; font-size: 0.875rem; cursor: pointer; min-width: 150px;">
                            <option value="en">English</option>
                            <option value="de">Deutsch</option>
                            <option value="fr">Français</option>
                            <option value="es">Español</option>
                            <option value="it">Italiano</option>
                            <option value="nl">Nederlands</option>
                            <option value="pl">Polski</option>
                            <option value="pt">Português</option>
                            <option value="ru">Русский</option>
                            <option value="zh">中文</option>
                            <option value="ja">日本語</option>
                        </select>
                        <select id="writingStyle" style="padding: 8px 12px; border: 1px solid #e5e7eb; border-radius: 6px; background: white; color: #111827; font-size: 0.875rem; cursor: pointer; min-width: 150px; display: none;">
                            <option value="formal">Formal</option>
                            <option value="casual">Casual</option>
                            <option value="business">Business</option>
                            <option value="academic">Academic</option>
                            <option value="creative">Creative</option>
                            <option value="simple">Simple</option>
                        </select>
                    </div>
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; position: relative;">
                    <button id="copyBtn" title="Copy translation" style="position: absolute; top: 40px; right: 15px; background: rgba(59, 130, 246, 0.9); color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; font-size: 0.75rem; opacity: 0; z-index: 10; transition: all 0.2s ease;">
                        📋 Copy
                    </button>
                    <textarea id="translatedText" placeholder="Der überarbeitete Text erscheint hier..." readonly style="width: 100%; min-height: 250px; padding: 15px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb; color: #111827; font-size: 0.95rem; font-family: inherit; resize: vertical;"></textarea>
                    <div style="margin-top: 10px; font-size: 0.75rem; color: #6b7280; text-align: right;">
                        <span id="targetCharCount">0</span> characters
                    </div>
                </div>
                <div id="errorMessage" style="display: none; padding: 12px; background: rgba(239, 68, 68, 0.1); color: #ef4444; border-radius: 6px; margin-bottom: 15px; font-size: 0.875rem; border-left: 3px solid #ef4444;"></div>
                <div id="successMessage" style="display: none; padding: 12px; background: rgba(16, 185, 129, 0.1); color: #10b981; border-radius: 6px; margin-bottom: 15px; font-size: 0.875rem; border-left: 3px solid #10b981;"></div>
            </div>
        </div>
    </div>
</div>

<style>
    .output-panel:hover #copyBtn {
        opacity: 1;
    }

    #copyBtn:hover {
        background: #3b82f6;
    }

    #copyBtn.copied {
        background: #10b981;
    }

    #translateBtn.btn-loading {
        opacity: 0.7;
        pointer-events: none;
    }

    #errorMessage.show {
        display: block;
    }

    #successMessage.show {
        display: block;
    }

    @media (max-width: 768px) {
        .translate-wrapper {
            grid-template-columns: 1fr !important;
            gap: 20px;
        }
    }
</style>

<script>
    class TranslateApp {
        constructor() {
            this.sourceText = document.getElementById('sourceText');
            this.translatedText = document.getElementById('translatedText');
            this.sourceLang = document.getElementById('sourceLang');
            this.targetLang = document.getElementById('targetLang');
            this.translateBtn = document.getElementById('translateBtn');
            this.translationModeBtn = document.getElementById('translationModeBtn');
            this.writingModeBtn = document.getElementById('writingModeBtn');
            this.writingStyle = document.getElementById('writingStyle');
            this.aiModel = document.getElementById('aiModel');
            this.copyBtn = document.getElementById('copyBtn');
            this.charCount = document.getElementById('charCount');
            this.targetCharCount = document.getElementById('targetCharCount');
            this.errorMessage = document.getElementById('errorMessage');
            this.successMessage = document.getElementById('successMessage');

            this.isLoading = false;
            this.currentMode = 'translation'; // 'translation' or 'writing'
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
                
                const response = await fetch('/req/ai/models', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    }
                });

                if (!response.ok) {
                    throw new Error('Failed to load models');
                }

                const data = await response.json();
                
                if (data.success && data.data.models) {
                    this.availableModels = data.data.models;
                    this.populateModelDropdown();
                }
            } catch (error) {
                console.error('Failed to load AI models:', error);
                // Fallback: keep default option
            }
        }

        populateModelDropdown() {
            // Clear existing options
            this.aiModel.innerHTML = '';
            
            // If no models available, add a default option
            if (this.availableModels.length === 0) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'Standard Modell';
                option.selected = true;
                this.aiModel.appendChild(option);
                return;
            }
            
            // Add models to dropdown
            this.availableModels.forEach((model, index) => {
                const option = document.createElement('option');
                option.value = model.id;
                option.textContent = model.label;
                if (index === 0) {
                    option.selected = true;
                }
                this.aiModel.appendChild(option);
            });
        }

        setupEventListeners() {
            this.translateBtn.addEventListener('click', () => this.translate());
            this.translationModeBtn.addEventListener('click', () => this.switchMode('translation'));
            this.writingModeBtn.addEventListener('click', () => this.switchMode('writing'));
            this.copyBtn.addEventListener('click', () => this.copy());
            this.sourceText.addEventListener('input', () => this.updateCharCount());

            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                    this.translate();
                }
            });
        }

        switchMode(mode) {
            this.currentMode = mode;
            
            // Clear output when switching modes
            this.translatedText.value = '';
            this.targetCharCount.textContent = '0';
            this.hideMessages();
            
            if (mode === 'translation') {
                this.translationModeBtn.style.background = '#3b82f6';
                this.translationModeBtn.style.color = 'white';
                this.writingModeBtn.style.background = 'white';
                this.writingModeBtn.style.color = '#6b7280';
                this.translateBtn.querySelector('span').textContent = 'Translate';
                this.sourceLang.style.display = 'block';
                this.aiModel.style.display = 'none';
                this.targetLang.style.display = 'block';
                this.writingStyle.style.display = 'none';
            } else {
                this.writingModeBtn.style.background = '#3b82f6';
                this.writingModeBtn.style.color = 'white';
                this.translationModeBtn.style.background = 'white';
                this.translationModeBtn.style.color = '#6b7280';
                this.translateBtn.querySelector('span').textContent = 'Improve Text';
                this.sourceLang.style.display = 'none';
                this.aiModel.style.display = 'block';
                this.targetLang.style.display = 'none';
                this.writingStyle.style.display = 'block';
            }
        }

  
        updateCharCount() {
            const count = this.sourceText.value.length;
            this.charCount.textContent = count.toLocaleString();
        }

        async translate() {
            if (!this.sourceText.value.trim()) {
                this.showError('Bitte geben Sie Text ein');
                return;
            }

            if (this.isLoading) return;

            this.isLoading = true;
            this.translateBtn.classList.add('btn-loading');
            this.hideMessages();

            try {
                // Get CSRF token from meta tag
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                
                if (!csrfToken) {
                    throw new Error('CSRF token not found');
                }
                
                let endpoint, requestData, successMessage;
                
                if (this.currentMode === 'translation') {
                    // Translation mode
                    endpoint = '/req/deepl/translate';
                    requestData = {
                        text: this.sourceText.value,
                        source_lang: this.sourceLang.value === 'auto' ? null : this.sourceLang.value,
                        target_lang: this.targetLang.value
                    };
                    successMessage = 'Übersetzung erfolgreich!';
                } else {
                    // Writing mode
                    endpoint = '/req/ai/write';
                    requestData = {
                        text: this.sourceText.value,
                        target_lang: null,
                        model: this.aiModel.value,
                        style: this.writingStyle.value
                    };
                    successMessage = 'Text erfolgreich verbessert!';
                }
                
                console.log('Sending request:', { mode: this.currentMode, endpoint, requestData });

                // Send request to appropriate endpoint
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(requestData)
                });

                let data;
                try {
                    data = await response.json();
                    console.log('Server response:', { status: response.status, ok: response.ok, data });
                } catch (parseError) {
                    console.error('Failed to parse JSON response:', parseError, 'Response status:', response.status);
                    throw new Error('Server returned invalid response');
                }

                if (!response.ok || !data.success) {
                    const errorMessage = typeof data.error === 'string' 
                        ? data.error 
                        : (data.message || 'Request failed');
                    console.error('Request failed:', { response: response.status, data });
                    throw new Error(errorMessage);
                }

                // Update translated text
                this.translatedText.value = data.data.text;
                this.targetCharCount.textContent = data.data.text.length.toLocaleString();

                // Update source language if auto-detected (translation mode only)
                if (this.currentMode === 'translation' && data.data.detected_source_language && this.sourceLang.value === 'auto') {
                    const detectedLang = data.data.detected_source_language.toLowerCase();
                    this.sourceLang.value = detectedLang;
                }

                this.showSuccess(successMessage);
            } catch (error) {
                const errorMessage = error instanceof Error ? error.message : 'Request fehlgeschlagen. Bitte versuchen Sie es erneut.';
                this.showError(errorMessage);
                console.error('Request error:', errorMessage, error);
                console.error('Request error:', errorMessage, error);
            } finally {
                this.isLoading = false;
                this.translateBtn.classList.remove('btn-loading');
            }
        }

        async simulateTranslation() {
            return new Promise((resolve) => {
                setTimeout(() => {
                    const sourceText = this.sourceText.value;
                    const translated = this.getMockTranslation(sourceText);
                    
                    this.translatedText.value = translated;
                    this.targetCharCount.textContent = translated.length.toLocaleString();
                    
                    this.showSuccess('Translation complete!');
                    resolve();
                }, 800);
            });
        }

        getMockTranslation(text) {
            const mockTranslations = {
                'hello': 'hola',
                'goodbye': 'adiós',
                'good morning': 'buenos días',
                'thank you': 'gracias',
                'please': 'por favor',
            };

            let result = text;
            Object.keys(mockTranslations).forEach(key => {
                const regex = new RegExp(`\\b${key}\\b`, 'gi');
                result = result.replace(regex, mockTranslations[key]);
            });

            return result || text;
        }

        swap() {
            const tempLang = this.sourceLang.value;
            this.sourceLang.value = this.targetLang.value;
            this.targetLang.value = tempLang;

            const tempText = this.sourceText.value;
            this.sourceText.value = this.translatedText.value;
            this.translatedText.value = tempText;

            this.updateCharCount();
        }

        clear() {
            this.sourceText.value = '';
            this.translatedText.value = '';
            this.charCount.textContent = '0';
            this.targetCharCount.textContent = '0';
            this.hideMessages();
            this.sourceText.focus();
        }

        async copy() {
            if (!this.translatedText.value) {
                this.showError('No translation to copy');
                return;
            }

            try {
                await navigator.clipboard.writeText(this.translatedText.value);
                this.showSuccess('Translation copied to clipboard!');
                
                const originalText = this.copyBtn.textContent;
                this.copyBtn.textContent = '✓ Copied';
                this.copyBtn.classList.add('copied');
                
                setTimeout(() => {
                    this.copyBtn.textContent = originalText;
                    this.copyBtn.classList.remove('copied');
                }, 2000);
            } catch (error) {
                this.showError('Failed to copy to clipboard');
                console.error('Copy error:', error);
            }
        }

        showError(message) {
            this.errorMessage.textContent = message;
            this.errorMessage.classList.add('show');
            this.successMessage.classList.remove('show');

            setTimeout(() => {
                this.errorMessage.classList.remove('show');
            }, 5000);
        }

        showSuccess(message) {
            this.successMessage.textContent = message;
            this.successMessage.classList.add('show');
            this.errorMessage.classList.remove('show');

            setTimeout(() => {
                this.successMessage.classList.remove('show');
            }, 3000);
        }

        hideMessages() {
            this.errorMessage.classList.remove('show');
            this.successMessage.classList.remove('show');
        }
    }

    // Initialize app when DOM is ready
    document.addEventListener('DOMContentLoaded', () => {
        new TranslateApp();
    });
</script>

@endsection
