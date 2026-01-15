// Assistant Debug Script - Remove after testing
console.log('=== ASSISTANT DEBUG ===');
console.log('1. AssistantManager loaded:', typeof assistantManager !== 'undefined');
console.log('2. injectAssistantContext loaded:', typeof injectAssistantContext !== 'undefined');
console.log('3. getCurrentAssistant loaded:', typeof getCurrentAssistant !== 'undefined');
console.log('4. Modal element exists:', !!document.getElementById('assistant-modal'));
console.log('5. Create modal exists:', !!document.getElementById('create-assistant-modal'));
console.log('6. Selector exists:', !!document.getElementById('assistant-selector'));
console.log('7. Sanctum token:', typeof window.sanctumToken !== 'undefined' && !!window.sanctumToken);
console.log('8. CSRF token:', !!document.querySelector('meta[name="csrf-token"]'));
console.log('9. Translation object:', typeof translation !== 'undefined');
console.log('10. Active module:', typeof activeModule !== 'undefined' ? activeModule : 'undefined');

// Try to open modal (for testing)
window.testAssistantModal = function() {
    console.log('Opening assistant modal...');
    if (typeof openAssistantModal === 'function') {
        openAssistantModal();
    } else {
        console.error('openAssistantModal function not found');
    }
};

console.log('Type testAssistantModal() to test the modal');
console.log('======================');
