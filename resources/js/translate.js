// Translate Interface JavaScript

class TranslateApp {
    constructor() {
        this.sourceText = document.getElementById('sourceText');
        this.translatedText = document.getElementById('translatedText');
        this.sourceLang = document.getElementById('sourceLang');
        this.targetLang = document.getElementById('targetLang');
        this.translateBtn = document.getElementById('translateBtn');
        this.clearBtn = document.getElementById('clearBtn');
        this.copyBtn = document.getElementById('copyBtn');
        this.charCount = document.getElementById('charCount');
        this.targetCharCount = document.getElementById('targetCharCount');
        this.errorMessage = document.getElementById('errorMessage');
        this.successMessage = document.getElementById('successMessage');
        this.swapButton = document.querySelector('.swap-button');
        this.settingsToggle = document.getElementById('settingsToggle');
        this.settingsPanel = document.getElementById('settingsPanel');
        this.darkModeToggle = document.getElementById('darkModeToggle');
        this.autoTranslateToggle = document.getElementById('autoTranslateToggle');

        this.isLoading = false;
        this.autoTranslate = false;
        this.autoTranslateTimeout = null;

        this.init();
    }

    init() {
        this.setupEventListeners();
        this.loadPreferences();
        this.createSwapButton();
    }

    setupEventListeners() {
        this.translateBtn.addEventListener('click', () => this.translate());
        this.clearBtn.addEventListener('click', () => this.clear());
        this.copyBtn.addEventListener('click', () => this.copy());
        this.sourceText.addEventListener('input', () => this.updateCharCount());
        this.sourceText.addEventListener('input', () => this.handleAutoTranslate());
        this.sourceLang.addEventListener('change', () => this.handleAutoTranslate());
        this.targetLang.addEventListener('change', () => this.handleAutoTranslate());
        this.settingsToggle.addEventListener('click', () => this.toggleSettingsPanel());
        this.darkModeToggle.addEventListener('change', (e) => this.toggleDarkMode(e.target.checked));
        this.autoTranslateToggle.addEventListener('change', (e) => {
            this.autoTranslate = e.target.checked;
            this.savePreferences();
            if (this.autoTranslate && this.sourceText.value) {
                this.translate();
            }
        });

        // Close settings panel when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.settings-panel') && !e.target.closest('.settings-toggle')) {
                this.settingsPanel.classList.remove('show');
            }
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                this.translate();
            }
            if ((e.ctrlKey || e.metaKey) && e.shiftKey && e.key === 'x') {
                this.swap();
            }
        });
    }

    createSwapButton() {
        const header = document.querySelector('.translate-wrapper');
        const swapContainer = document.createElement('div');
        swapContainer.style.cssText = `
            display: flex;
            justify-content: center;
            align-items: center;
            position: absolute;
            left: 50%;
            top: 80px;
            transform: translateX(-50%);
        `;

        const swapBtn = document.createElement('button');
        swapBtn.className = 'swap-button';
        swapBtn.innerHTML = '⇄';
        swapBtn.addEventListener('click', () => this.swap());
        swapBtn.title = 'Swap languages (Ctrl+Shift+X)';

        swapContainer.appendChild(swapBtn);
        header.parentElement.insertBefore(swapContainer, header);
    }

    updateCharCount() {
        const count = this.sourceText.value.length;
        this.charCount.textContent = count.toLocaleString();

        const charCountEl = this.charCount.parentElement;
        charCountEl.classList.remove('warning', 'error');

        if (count > 45000) {
            charCountEl.classList.add('error');
        } else if (count > 40000) {
            charCountEl.classList.add('warning');
        }
    }

    handleAutoTranslate() {
        if (!this.autoTranslate) return;

        clearTimeout(this.autoTranslateTimeout);
        this.autoTranslateTimeout = setTimeout(() => {
            if (this.sourceText.value.trim()) {
                this.translate();
            }
        }, 1000);
    }

    async translate() {
        if (!this.sourceText.value.trim()) {
            this.showError('Please enter text to translate');
            return;
        }

        if (this.isLoading) return;

        this.isLoading = true;
        this.translateBtn.classList.add('loading');
        this.hideMessages();

        try {
            // Mock translation API call
            // In production, this will call the actual DeepL API endpoint
            await this.simulateTranslation();
        } catch (error) {
            this.showError('Translation failed. Please try again.');
            console.error('Translation error:', error);
        } finally {
            this.isLoading = false;
            this.translateBtn.classList.remove('loading');
        }
    }

    async simulateTranslation() {
        // Simulate API delay
        return new Promise((resolve) => {
            setTimeout(() => {
                // Mock translation - reverse text for demo
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
        // Simple mock translation for demo
        // This will be replaced with actual API calls
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
        this.handleAutoTranslate();
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
            
            // Change button state temporarily
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

    toggleSettingsPanel() {
        this.settingsPanel.classList.toggle('show');
    }

    toggleDarkMode(enabled) {
        const html = document.documentElement;
        if (enabled) {
            html.classList.add('darkMode');
            html.classList.remove('lightMode');
        } else {
            html.classList.add('lightMode');
            html.classList.remove('darkMode');
        }
        this.savePreferences();
    }

    savePreferences() {
        const preferences = {
            darkMode: document.documentElement.classList.contains('darkMode'),
            autoTranslate: this.autoTranslate,
            sourceLang: this.sourceLang.value,
            targetLang: this.targetLang.value,
        };
        localStorage.setItem('translatePreferences', JSON.stringify(preferences));
    }

    loadPreferences() {
        const saved = localStorage.getItem('translatePreferences');
        if (saved) {
            const preferences = JSON.parse(saved);
            
            // Load dark mode preference
            if (preferences.darkMode) {
                document.documentElement.classList.add('darkMode');
                document.documentElement.classList.remove('lightMode');
                this.darkModeToggle.checked = true;
            }

            // Load auto translate preference
            if (preferences.autoTranslate) {
                this.autoTranslateToggle.checked = true;
                this.autoTranslate = true;
            }

            // Load language preferences
            if (preferences.sourceLang) {
                this.sourceLang.value = preferences.sourceLang;
            }
            if (preferences.targetLang) {
                this.targetLang.value = preferences.targetLang;
            }
        } else {
            // Default to system dark mode preference if available
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('darkMode');
                document.documentElement.classList.remove('lightMode');
                this.darkModeToggle.checked = true;
            }
        }
    }
}

// Initialize app when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new TranslateApp();
});
