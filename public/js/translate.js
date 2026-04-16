/**
 * HAWKI Translation - Entry Point
 * This file initializes the modular TranslateApp.
 */
import { TranslateApp } from './translate/TranslateApp.js';
import './translate/Debug.js';

document.addEventListener('DOMContentLoaded', () => {
    // Initialize the main application and expose it to window for debugging/legacy support
    window.translateApp = new TranslateApp();
});
