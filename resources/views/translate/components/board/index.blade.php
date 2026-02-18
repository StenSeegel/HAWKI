<div class="scroll-container translate-module" id="translate">
    <div class="scroll-panel">
        <div class="translate-container">

        <div id="translateBoard" class="translate-board-3col">
            <div class="board-panel-group">
                    @include('translate.components.board.header')
                    @include('translate.components.board.source-panel')
                    @include('translate.components.board.target-panel')
                    @include('translate.components.board.footer')
            </div>

        </div>

        @include('translate.components.board.document-panel')


        <div id="errorMessage" class="error-msg-container" style="display: none;"></div>
        <div id="successMessage" class="success-msg-container" style="display: none;"></div>

    </div>
</div>
</div>
