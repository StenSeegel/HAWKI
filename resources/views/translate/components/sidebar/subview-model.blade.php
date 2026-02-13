<!-- Subview: Model Selector -->
<div id="sidebarModelSubview" class="dy-sidebar-subview" style="display: none; position: absolute; top:0; left:0; width:100%; height:100%; background-color: var(--background-main); z-index: 100; flex-direction: column;">
    <div class="header" style="padding: 1.5rem 1rem; border-bottom: var(--border-stroke-thin); display: flex; align-items: center; gap: 12px;">
        <button class="btn-xs" id="modelSubviewBackBtn" style="padding: 0; color: var(--text-color);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        </button>
        <h3 class="title" style="margin: 0; padding-left: 0; font-size: 1.1rem; flex: 1;">{{ $translation["LanguageModel"] ?? "Sprachmodell" }}</h3>
    </div>
    
    <div class="dy-sidebar-content-panel" style="flex: 1; margin-right: 0;">
        <div class="dy-sidebar-scroll-panel">
            <div id="sidebarModelList" style="padding: 0.25rem 0.25rem 0 0;">
                <!-- Models rendered via JS -->
            </div>
        </div>
    </div>
</div>
