@extends('layouts.home')

@section('content')
<script>
    // Anti-flicker script: Sets global state for Live Mode CSS before UI parses
    (function() {
        try {
            const isLiveAdminAllowed = {{ ($enableLiveMode ?? true) ? 'true' : 'false' }};
            const raw = sessionStorage.getItem('hawki_text_session');
            if (raw && isLiveAdminAllowed) {
                const s = JSON.parse(raw);
                if (s.liveTranslation === true && s.selectedModelId !== 'deepl') {
                    document.documentElement.classList.add('live-mode-active');
                }
            }
        } catch(e) {}
    })();
</script>
<div class="main-panel-grid">
    <link rel="stylesheet" href="{{ asset('css/translate.css') }}">
    <link rel="stylesheet" href="{{ asset('css/text_create.css') }}">
    
    @include('translate.components.sidebar.index')

    <div class="dy-main-panel">
        @if($showBetaMessage && $betaMessageText)
            <div class="beta-message-alert" role="alert">
                <span class="beta-message-alert__text">{{ $betaMessageText }}</span>
                <button type="button" class="beta-message-alert__close" onclick="this.parentElement.remove()" aria-label="Close">&#x2715;</button>
            </div>
        @endif
        <div class="dy-main-content">
             @include('translate.components.board.index')
        </div>
    </div>
</div>

@include('translate.components.modals.glossary')
@include('translate.components.modals.delete-glossary')

<script>
    window.TranslationData = @json($translation);
    window.TranslationData.models = @json($models['models'] ?? []);
    window.TranslationData.userLocale = "{{ $userLocale ?? 'en' }}";
    window.TranslationData.configSystem = {{ config('hawki.ai_config_system') ? 'true' : 'false' }};
    window.TranslationData.enableLiveMode = {{ ($enableLiveMode ?? true) ? 'true' : 'false' }};
    window.TranslationData.defaults = @json($defaults ?? []);
</script>
<script src="{{ asset('js/textDiff.js') }}"></script>
<script type="module" src="{{ asset('js/translate.js') }}"></script>
@endsection
