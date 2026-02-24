@extends('layouts.home')

@section('content')
<div class="main-panel-grid">
    <link rel="stylesheet" href="{{ asset('css/translate.css') }}">
    
    @include('translate.components.sidebar.index')

    <div class="dy-main-panel">
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
