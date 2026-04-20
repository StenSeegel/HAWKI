/**
 * Constants and Configuration for the Translation Application.
 */

export const DOM_IDS = {
    // Core areas
    SOURCE_TEXT: 'sourceText',
    TRANSLATED_TEXT: 'translatedText',
    DIFF_VIEW: 'diffView',
    
    // Counters
    SOURCE_CHAR_COUNT: 'sourceCharCount',
    TARGET_CHAR_COUNT: 'targetCharCount',
    
    // Language selectors
    SOURCE_LANG: 'sourceLang',
    TARGET_LANG: 'targetLang',
    SOURCE_LANG_DROPDOWN: 'sourceLangDropdown',
    TARGET_LANG_DROPDOWN: 'targetLangDropdown',
    
    // Controls
    TRANSLATE_BTN: 'translateBtn',
    SWAP_LANG_BTN: 'swapBtn',
    GLOSSARY_BTN: 'glossary-btn',
    IMPROVE_TARGET_BTN: 'improveTargetBtn',
    TRANSLATE_TARGET_BTN: 'translateTargetBtn',
    CLEAR_SOURCE_BTN: 'clearSourceBtn',
    COPY_SOURCE_BTN: 'copySourceBtn',
    COPY_TARGET_BTN: 'copyTargetBtn',
    MODE_TABS: '.mode-tab', // selector
    MODEL_SELECT: 'modelSelect',
    
    // Document Translation
    DOC_TAB: 'mode-documents',
    DOC_UPLOAD_FORM: 'doc-upload-form',
    DOC_DROP_ZONE: 'doc-drop-zone',
    DOC_FILE_INPUT: 'doc-file-input',
    DOC_FILE_INFO: 'doc-file-info',
    DOC_PROGRESS_CONTAINER: 'doc-progress-container',
    DOC_PROGRESS_BAR: 'doc-progress-bar',
    DOC_STATUS_TEXT: 'doc-status-text',
    DOC_SOURCE_LANG: 'docSourceLang',
    DOC_TARGET_LANG: 'docTargetLang',
    DOC_SOURCE_DROPDOWN: 'docSourceLangDropdown',
    DOC_TARGET_DROPDOWN: 'docTargetLangDropdown',
    
    // Document History
    DOC_HISTORY: 'translatedDocsHistory',
    DOC_LIST: 'translatedDocsList',
    DOC_COUNT: 'translatedDocsCount',
    DOC_TOGGLE: 'translatedDocsToggle',
    
    // Style/Writing View
    STYLE_LABEL: 'selectedStyleLabel',
    STYLE_SUBVIEW: 'sidebarStyleSubview',
    STYLE_SECTION: 'styleSection',
    TONE_SECTION: 'toneSection',
    FORMALITY_SECTION: 'formalitySection',
    STYLE_SELECTORS: '.style-selector', 
    TONE_SELECTORS: '.tone-selector',
    FORMALITY_SELECTORS: '.formality-selector',
    GLOBAL_STANDARD_BTN: 'globalStandardBtn',
    TRANSLATION_MODE_BTN: 'translationModeBtn',
    REPHRASE_MODE_BTN: 'rephraseModeBtn',
    DOCUMENT_MODE_BTN: 'documentModeBtn',
    SHOW_CHANGES_TOGGLE: 'showChangesToggle',
    MODEL_SELECTOR_BTN: 'model-selector-btn',
    MODEL_SUBVIEW: 'sidebarModelSubview',
    MODEL_SUBVIEW_BACK_BTN: 'modelSubviewBackBtn',
    SELECTED_MODEL_LABEL: 'selectedModelLabel',
    MODEL_LIST: 'sidebarModelList',
    
    // Context Menu
    WRITE_CONTEXT_MENU: 'write-context-menu',
    SUGGESTIONS_DROPDOWN: 'suggestions-dropdown',
    UNDO_BTN: 'undo-sentence-btn',
    REPHRASE_BTN: 'rephrase-sentence-btn',
    
    // Feedback
    ERROR_MESSAGE: 'error-message',
    OUTPUT_SKELETON: 'output-skeleton',
    MODAL_FILE_VIEWER: '#file-viewer-modal'
};

export const SESSION_KEYS = {
    TEXT_SESSION: 'hawki_text_session'
};

export const API_ENDPOINTS = {
    PROCESS: '/req/text/process',
    IMPROVE: '/req/text/improve',
    DETECT_LANGUAGE: '/req/text/detect-language',
    DOC_UPLOAD: '/req/text/upload-document',
    DOC_STATUS: '/req/text/document-status',
    DOC_HISTORY: '/req/text/translated-documents',
    DOC_DELETE: '/req/text/delete-document',
    DOC_DOWNLOAD: '/req/text/download-document',
    DOC_VIEW: '/req/text/view-document'
};

/**
 * Get localized strings from the global window object.
 */
export function getTranslations() {
    return window.TranslationData || {};
}
