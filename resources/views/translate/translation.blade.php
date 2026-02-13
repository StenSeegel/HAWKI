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
    window.TranslationData = {
        Glossary: "{{ $translation['Glossary'] ?? 'Glossary' }}",
        NewGlossary: "{{ $translation['NewGlossary'] ?? 'New glossary' }}",
        Translate: "{{ $translation['Translate'] ?? 'Translate' }}",
        ImproveText: "{{ $translation['ImproveText'] ?? 'Text verbessern' }}",
        Err_EmptyInput: "{{ $translation['Err_EmptyInput'] ?? 'Bitte geben Sie Text ein' }}",
        Success_Translated: "{{ $translation['Success_Translated'] ?? 'Übersetzung erfolgreich!' }}",
        Success_Improved: "{{ $translation['Success_Improved'] ?? 'Text erfolgreich verbessert!' }}",
        Status_Error: "{{ $translation['Status_Error'] ?? 'Fehler beim Verarbeiten' }}",
        Err_CopyFailed: "{{ $translation['Err_CopyFailed'] ?? 'Kopieren fehlgeschlagen' }}"
    };
</script>
<script src="{{ asset('js/translate.js') }}"></script>
@endsection
