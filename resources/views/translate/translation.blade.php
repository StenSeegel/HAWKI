@extends('layouts.home')

@section('content')
<div class="main-panel-grid">
    <link rel="stylesheet" href="{{ asset('css/translate.css') }}">
    
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
    window.TranslationData.userLocale = "{{ $userLocale ?? 'en' }}";
</script>
<script src="{{ asset('js/textDiff.js') }}"></script>
<script src="{{ asset('js/translate.js') }}"></script>
@endsection
