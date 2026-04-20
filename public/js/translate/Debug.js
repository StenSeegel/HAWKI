/**
 * Debug.js - State Inspection Utility for HAWKI Translation
 * 
 * Usage: 
 * 1. Import or include this script.
 * 2. Access via window.HawkiDebug
 * 3. Call HawkiDebug.inspectStatus() to see why the button is locked/unlocked.
 */

window.HawkiDebug = {
    getApp() {
        return window.translateApp;
    },

    inspectStatus() {
        const app = this.getApp();
        if (!app) {
            console.error("TranslateApp (window.translateApp) not found!");
            return;
        }

        const currentText = app.uiManager.elements.sourceText?.value.trim() || '';
        const currentSourceLang = app.uiManager.elements.sourceLang?.value || 'auto';
        const currentTargetLang = app.getCurrentTargetLang();
        const currentModel = app.selectedModel?.id || null;
        const currentStyle = app.selectedStyle || 'default';
        const currentTone = app.selectedTone || 'default';
        const currentFormality = app.selectedFormality || 'default';
        const activeGlossaryIds = Array.from(document.querySelectorAll('#sidebarGlossaryList input:checked')).map(cb => cb.value).join(',');

        console.group("HAWKI State Debugger");
        console.log("Current Mode:", app.currentMode);
        console.log("Is Loading:", app.isLoading);
        
        console.table({
            "Field": ["Text", "Source Lang", "Target Lang", "Model", "Style", "Tone", "Formality", "Glossaries"],
            "Current": [
                currentText.substring(0, 30) + (currentText.length > 30 ? "..." : ""),
                currentSourceLang,
                currentTargetLang,
                currentModel,
                currentStyle,
                currentTone,
                currentFormality,
                activeGlossaryIds
            ],
            "Last Processed": [
                app.lastProcessedSourceText.substring(0, 30) + (app.lastProcessedSourceText.length > 30 ? "..." : ""),
                app.lastProcessedSourceLang,
                app.lastProcessedTargetLang,
                app.lastProcessedModel || "null",
                app.lastProcessedStyle || "null",
                app.lastProcessedTone || "null",
                app.lastProcessedFormality || "null",
                app.lastProcessedGlossaryIds
            ],
            "Changed?": [
                currentText !== app.lastProcessedSourceText,
                currentSourceLang !== app.lastProcessedSourceLang,
                currentTargetLang !== app.lastProcessedTargetLang,
                currentModel !== app.lastProcessedModel,
                currentStyle !== (app.lastProcessedStyle || 'default'),
                currentTone !== (app.lastProcessedTone || 'default'),
                currentFormality !== (app.lastProcessedFormality || 'default'),
                activeGlossaryIds !== app.lastProcessedGlossaryIds
            ]
        });

        const hasChanges = app.hasChanges();
        console.log("%cHas Changes: " + hasChanges, "font-weight: bold; font-size: 14px; color: " + (hasChanges ? "green" : "red"));
        console.groupEnd();

        // Also run the live mode diagnostic
        this.testLiveModeSettings();
    },

    forceUnlock() {
        const app = this.getApp();
        if (app) {
            app.lastProcessedSourceText = "FORCE_INVALID";
            app.updateButtonState();
            console.log("Button force-unlocked.");
        }
    },

    testLiveModeSettings() {
        const app = this.getApp();
        if (!app) {
            console.error("TranslateApp not found!");
            return;
        }

        console.group("HAWKI LiveMode Diagnostics");
        
        const adminAllowed = window.TranslationData?.enableLiveMode !== false;
        
        let cachedLiveMode = false;
        try {
            const raw = sessionStorage.getItem('hawki_text_session');
            if (raw) {
                cachedLiveMode = !!JSON.parse(raw).liveTranslation;
            }
        } catch(e) {}

        const activeLiveMode = app.liveTranslationEnabled;
        const domClassPresent = document.documentElement.classList.contains('live-mode-active');
        const isDeepL = app.selectedModel?.id === 'deepl' || app.selectedModel?.provider === 'deepl';

        console.table({
            "Setting Source": ["1. Global Admin Allowed", "2. SessionStorage Cache", "3. Active JS App State", "4. Model Disallows (DeepL)", "5. DOM Mask (.live-mode-active)"],
            "Value": [
                adminAllowed,
                cachedLiveMode,
                activeLiveMode,
                isDeepL,
                domClassPresent
            ]
        });

        // Scenario checks
        if (!adminAllowed && cachedLiveMode) {
            console.log("%c⚠️ SCENARIO DETECTED: Cache has LiveMode=true, but Admin globally disabled it.", "color: orange; font-weight: bold;");
            if (!activeLiveMode) {
                console.log("%c✅ Correct behavior: The system correctly overrode the browser cache.", "color: green;");
            } else {
                console.log("%c❌ BUG: App State is true! The cache was not overridden.", "color: red;");
            }
        }
        
        if (activeLiveMode && isDeepL && domClassPresent) {
            console.log("%c❌ BUG: DeepL is selected but live-mode-active class is still present.", "color: red;");
        } else if (activeLiveMode && !isDeepL && !domClassPresent) {
            console.log("%c❌ BUG: LiveMode is active but DOM class is missing.", "color: red;");
        } else {
            console.log("%c✅ Visual DOM mask correctly reflects logic.", "color: green;");
        }

        console.groupEnd();
    }
};

console.log("HAWKI Debugger initialized. Call window.HawkiDebug.inspectStatus() or .testLiveModeSettings()");
