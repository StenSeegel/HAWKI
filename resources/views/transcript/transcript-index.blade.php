@extends('layouts.home')

@section('content')
    <div class="main-panel-grid">
        @include('transcript.components.sidebar.sidebar-index')
        @include('transcript.components.board.board-index')
    </div>

    <div id="custom-context-menu" class="context-menu"></div>
@endsection
