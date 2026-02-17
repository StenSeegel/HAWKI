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
        ImproveText: "{{ $translation['ImproveText'] ?? 'Überarbeiten' }}",
        Terms: "{{ $translation['Terms'] ?? 'Begriffe' }}",
        EditGlossary: "{{ $translation['EditGlossary'] ?? 'Glossar bearbeiten' }}",
        Update: "{{ $translation['Update'] ?? 'Aktualisieren' }}",
        Created: "{{ $translation['Created'] ?? 'Erstellt!' }}",
        Updated: "{{ $translation['Updated'] ?? 'Aktualisiert!' }}",
        Error: "{{ $translation['Error'] ?? 'Fehler' }}",
        NameRequired: "{{ $translation['NameRequired'] ?? 'Name erforderlich' }}",
        TermPairRequired: "{{ $translation['TermPairRequired'] ?? 'Mindestens ein Begriffspaar erforderlich' }}",
        Public: "{{ $translation['Public'] ?? 'Öffentlich' }}",
        Organization: "{{ $translation['Organization'] ?? 'Organisation' }}",
        Team: "{{ $translation['Team'] ?? 'Team' }}",
        Private: "{{ $translation['Private'] ?? 'Privat' }}",
        Edit: "{{ $translation['Edit'] ?? 'Bearbeiten' }}",
        Delete: "{{ $translation['Delete'] ?? 'Löschen' }}",
        DeleteGlossaryTitle: "{{ $translation['DeleteGlossaryTitle'] ?? 'Glossar löschen: :name' }}",
        NoGlossaries: "{{ $translation['NoGlossaries'] ?? 'Keine Glossare vorhanden.' }}",
        GlossaryNamePlaceholder: "{{ $translation['GlossaryNamePlaceholder'] ?? 'z.B. Fachbegriffe' }}",
        SourceTerm: "{{ $translation['SourceTerm'] ?? 'Ausgangsbegriff' }}",
        TargetTerm: "{{ $translation['TargetTerm'] ?? 'Zielbegriff' }}",
        RemoveTermPair: "{{ $translation['RemoveTermPair'] ?? 'Begriffspaar entfernen' }}",
        Err_EmptyInput: "{{ $translation['Err_EmptyInput'] ?? 'Bitte geben Sie Text ein' }}",
        Success_Translated: "{{ $translation['Success_Translated'] ?? 'Übersetzung erfolgreich!' }}",
        Success_Improved: "{{ $translation['Success_Improved'] ?? 'Text erfolgreich verbessert!' }}",
        Err_ProcessFailed: "{{ $translation['Err_ProcessFailed'] ?? 'Fehler beim Verarbeiten' }}",
        Err_CopyFailed: "{{ $translation['Err_CopyFailed'] ?? 'Kopieren fehlgeschlagen' }}",
        StandardModel: "{{ $translation['StandardModel'] ?? 'Standardmodell' }}",
        NoModelsConfigured: "{{ $translation['NoModelsConfigured'] ?? 'Keine Modelle konfiguriert' }}",
        Unknown: "{{ $translation['Unknown'] ?? 'Unbekannt' }}"
    };
</script>
<script src="{{ asset('js/translate.js') }}"></script>
@endsection
