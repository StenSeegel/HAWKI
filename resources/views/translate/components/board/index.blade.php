<div class="scroll-container translate-module" id="translate">
    <div class="scroll-panel">
        <div class="translate-container">

        <div id="translateBoard" class="translate-board-3col">
            <div class="board-panel-group">
                    @include('translate.components.board.header')
                    @include('translate.components.board.source-panel')
                    @include('translate.components.board.target-panel')
                    @include('translate.components.board.footer')

                    <!-- Write Context Menu for Suggest Alternatives -->
                    <div id="write-context-menu" class="write-context-menu" style="display: none;">
                        <button class="menu-item undo-btn" title="{{ $translation['Undo'] ?? 'Rückgängig' }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7v6h6"></path><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"></path></svg>
                        </button>
                        <div class="menu-separator"></div>
                        <button class="menu-item text-btn rephrase-btn">{{ $translation['RephraseSentence'] ?? 'Satz umformulieren' }}</button>
                        <div class="menu-separator"></div>
                        <button class="menu-item text-btn replace-word-btn">{{ $translation['ReplaceWord'] ?? 'Wort ersetzen' }}</button>
                        <div class="menu-separator"></div>
                        <button class="menu-item close-btn" title="{{ $translation['Close'] ?? 'Schließen' }}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    </div>

                    <!-- Suggestions Dropdown for "Suggest Alternatives" -->
                    <div id="write-suggestions-dropdown" class="write-suggestions-dropdown" style="display: none;"></div>
            </div>

        </div>

        @include('translate.components.board.document-panel')


        <div id="errorMessage" class="error-msg-container" style="display: none;"></div>

    </div>
</div>
</div>
