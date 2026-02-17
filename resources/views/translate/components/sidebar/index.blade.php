<div class="dy-sidebar expanded" id="text-sidebar">
    <div class="dy-sidebar-wrapper" style="position: relative; height: 100%; display: flex; flex-direction: column;">
        @include('translate.components.sidebar.main-panel')

        <div class="dy-sidebar-expand-btn" onclick="togglePanelClass('text-sidebar', 'expanded')">
            <x-icon name="chevron-right"/>
        </div>
        
        @include('translate.components.sidebar.subview-glossary')
        @include('translate.components.sidebar.subview-model')
    </div>
</div>
