@php
    // label key => ratio sent in the prompt, plus the shape shown in the menu.
    $galleryAspectRatios = [
        'AspectRatioSquare'     => '1:1',
        'AspectRatioPortrait'   => '3:4',
        'AspectRatioStory'      => '9:16',
        'AspectRatioLandscape'  => '4:3',
        'AspectRatioWidescreen' => '16:9',
    ];
@endphp
<div class="modal" id="image-gallery-modal">
    <div class="gallery-panel">
        <div class="closeButton" onclick="closeModal(this)" title="{{ $translation['Close'] }}">
            <x-icon name="x"/>
        </div>
        <div class="gallery-image-frame">
            <img class="gallery-image" id="gallery-image" src="" alt="">

            {{-- Spans the picture and is the size container the toolbar reacts to:
                 the labels have to give way on a narrow image, not on a narrow
                 window. --}}
            <div class="gallery-toolbar-area">
            <div class="gallery-toolbar">
                <button type="button" class="gallery-tool" onclick="commentOnGalleryImage()"
                        title="{{ $translation['CommentImage'] }}">
                    <x-icon name="message-circle"/>
                    <span>{{ $translation['CommentImage'] }}</span>
                </button>

                <button type="button" class="gallery-tool" onclick="removeGalleryImageBackground()"
                        title="{{ $translation['RemoveBackground'] }}">
                    <x-icon name="eraser"/>
                    <span>{{ $translation['RemoveBackground'] }}</span>
                </button>

                <div class="gallery-tool-group">
                    <button type="button" class="gallery-tool" onclick="toggleGalleryRatioMenu(this)"
                            title="{{ $translation['ChangeAspectRatio'] }}">
                        <x-icon name="fullscreen"/>
                        <span>{{ $translation['ChangeAspectRatio'] }}</span>
                    </button>

                    <div class="gallery-ratio-menu" id="gallery-ratio-menu">
                        <div class="gallery-ratio-hint">{{ $translation['AspectRatioHint'] }}</div>
                        @foreach($galleryAspectRatios as $labelKey => $ratio)
                            <button type="button" class="gallery-ratio-option"
                                    onclick="applyGalleryAspectRatio('{{ $ratio }}')">
                                <span class="gallery-ratio-shape" style="aspect-ratio: {{ str_replace(':', ' / ', $ratio) }};"></span>
                                <span class="gallery-ratio-label">{{ $translation[$labelKey] }}</span>
                                <span class="gallery-ratio-value">{{ $ratio }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
            </div>

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
