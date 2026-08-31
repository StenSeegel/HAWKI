<div class="modal" id="image-gallery-modal">
    <div class="gallery-panel">
        <div class="closeButton" onclick="closeModal(this)" title="{{ $translation['Close'] }}">
            <x-icon name="x"/>
        </div>
        <div class="gallery-image-frame">
            <img class="gallery-image" id="gallery-image" src="" alt="">
            <button type="button" class="image-download-btn" id="gallery-download-btn"
                    onclick="downloadImage(this)" title="{{ $translation['Download'] }}">
                <x-icon name="download"/>
            </button>
        </div>
        <div class="gallery-prompt">
            <div class="gallery-prompt-label">{{ $translation['GeneratedImagePrompt'] }}</div>
            <div class="gallery-prompt-text" id="gallery-prompt-text"></div>
        </div>
    </div>
</div>
