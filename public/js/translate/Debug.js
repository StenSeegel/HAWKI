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
    },

    forceUnlock() {
        const app = this.getApp();
        if (app) {
            app.lastProcessedSourceText = "FORCE_INVALID";
            app.updateButtonState();
            console.log("Button force-unlocked.");
        }
    }
};

console.log("HAWKI Debugger initialized. Call window.HawkiDebug.inspectStatus() in console.");
