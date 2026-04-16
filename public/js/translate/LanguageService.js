/**
 * Language selection and detection services for HAWKI Translation.
 */
import { API_ENDPOINTS } from './Constants.js';

export class LanguageService {
    constructor() {
        this._langDetectCache = { sample: null, language: null };
        this._detectingPromise = null;
        this._detectingSample = null;
    }

    /**
     * Detect the language of the given text via the backend LLM endpoint.
     * @param {string} text
     * @returns {Promise<string|null>}
     */
    async detectLanguage(text) {
        if (!text) return null;
        const sample = text.substring(0, 50);

        // 1. Return cached result if input hasn't changed
        if (this._langDetectCache.sample === sample && this._langDetectCache.language !== null) {
            return this._langDetectCache.language;
        }

        // 2. Prevent concurrent duplicate requests for the same sample
        if (this._detectingPromise && this._detectingSample === sample) {
            return this._detectingPromise;
        }

        this._detectingSample = sample;
        this._detectingPromise = (async () => {
            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const response = await fetch(API_ENDPOINTS.DETECT_LANGUAGE, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ text: sample })
                });
                const data = await response.json();
                const language = (data.success && data.data?.language) ? data.data.language : null;

                // Update cache
                this._langDetectCache = { sample, language };

                return language;
            } catch (error) {
                console.error('Language detection failed:', error);
                return null;
            } finally {
                // Clear flight tracking but keep cache
                if (this._detectingSample === sample) {
                    this._detectingPromise = null;
                    this._detectingSample = null;
                }
            }
        })();

        return this._detectingPromise;
    }

    /**
     * Normalizes common two-letter codes to the first available variant in the UI.
     * @param {string} lang
     * @returns {string|null}
     */
    normalizeLanguageCode(lang) {
        if (!lang) return null;
        const l = lang.toLowerCase();
        const mapping = {
            'en': 'en-gb',
            'pt': 'pt',
            'zh': 'zh'
        };
        return mapping[l] || l;
    }

    /**
     * Process text (Translation).
     * @param {Object} data 
     * @returns {Promise<Object>}
     */
    async process(data) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const response = await fetch(API_ENDPOINTS.PROCESS, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.error || result.message || 'Processing failed');
        }
        return result;
    }

    /**
     * Improve text (Rewrite/Rephrase/Synonyms).
     * @param {Object} data 
     * @param {AbortSignal} signal
     * @returns {Promise<Object>}
     */
    async improve(data, signal = null) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const response = await fetch(API_ENDPOINTS.IMPROVE, {
            method: 'POST',
            signal: signal,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.error || result.message || 'Improvement failed');
        }
        return result;
    }

    /**
     * Get an alternative target language when a collision occurs.
     * @param {string} collisionLang
     * @param {string} userLocale
     * @returns {string}
     */
    getAlternativeTargetLang(collisionLang, userLocale) {
        let result;
        if (userLocale !== collisionLang) {
            result = userLocale;
        } else {
            const baseCollision = collisionLang.split('-')[0];
            result = (baseCollision === 'en') ? 'de' : 'en-gb';
        }
        return this.normalizeLanguageCode(result);
    }
}
