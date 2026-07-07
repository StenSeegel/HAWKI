@extends('layouts.home')

@section('content')
<script>console.log('TRANSCRIPT INDEX RENDERED');</script>
    <div class="main-panel-grid">
        @include('transcript.components.sidebar.sidebar-index')
        @include('transcript.components.board.board-index')
    </div>

    <div id="custom-context-menu" class="context-menu hidden"></div>

    @include('transcript.components.js-templates')
    @include('transcript.components.modals.speaker-mapping-modal')
@endsection
