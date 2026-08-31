<div class="modal" id="image-gallery-modal">
    <div class="gallery-panel">
        <div class="closeButton" onclick="closeModal(this)" title="{{ $translation['Close'] }}">
            <x-icon name="x"/>
        </div>
        <img class="gallery-image" id="gallery-image" src="" alt="">
        <div class="gallery-prompt">
            <div class="gallery-prompt-label">{{ $translation['GeneratedImagePrompt'] }}</div>
            <div class="gallery-prompt-text" id="gallery-prompt-text"></div>
        </div>
    </div>
</div>
