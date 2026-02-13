<!-- Subview: Glossaries -->
<div id="sidebarGlossarySubview" class="dy-sidebar-subview" style="display: none; position: absolute; top:0; left:0; width:100%; height:100%; background-color: var(--background-main); z-index: 100; flex-direction: column;">
    <div class="header" style="padding: 1.5rem 1rem; border-bottom: var(--border-stroke-thin); display: flex; align-items: center; gap: 12px;">
        <button class="btn-xs" id="glossarySubviewBackBtn" style="padding: 0; color: var(--text-color);">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        </button>
        <h3 class="title" style="margin: 0; padding-left: 0; font-size: 1.1rem; flex: 1;">{{ $translation["Glossaries"] ?? "Glossaries" }}</h3>
        <span id="glossaryCountDisplay" style="color: var(--text-faded-color); font-size: 0.85rem; font-weight: 500;"></span>
    </div>
    
    <div class="dy-sidebar-content-panel" style="flex: 1; margin-right: 0;">
        <div class="dy-sidebar-scroll-panel">
            <div class="selection-list" id="sidebarGlossaryList">
                <!-- Items rendered via JS -->
            </div>
        </div>
    </div>

    <div class="subview-footer" style="padding: 1rem; border-top: var(--border-stroke-thin);">
        <button class="btn-md-stroke" id="manageGlossariesBtn" style="width: 100%; justify-content: center;"> 
            <div class="icon">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
<g clip-path="url(#clip0_1519_2533)">
<path d="M8 10c1.105 0 2-0.895 2-2s-0.895-2-2-2-2 0.895-2 2 0.895 2 2 2z" stroke="currentColor" stroke-width="1.33" stroke-linecap="round" stroke-linejoin="round"></path>
<path d="M12.933 10c-0.089 0.201-0.115 0.424-0.076 0.64s0.143 0.414 0.296 0.573l0.04 0.04c0.124 0.124 0.223 0.271 0.291 0.433s0.103 0.335 0.103 0.51-0.035 0.348-0.103 0.51-0.167 0.309-0.291 0.433c-0.124 0.124-0.271 0.223-0.433 0.291s-0.335 0.103-0.51 0.103-0.348-0.035-0.51-0.103-0.309-0.167-0.433-0.291l-0.04-0.04c-0.157-0.153-0.357-0.257-0.573-0.296s-0.44-0.013-0.64 0.076c-0.197 0.084-0.365 0.228-0.484 0.416s-0.181 0.408-0.181 0.64v0.113c0 0.354-0.14 0.693-0.391 0.943s-0.589 0.391-0.943 0.391-0.693-0.14-0.943-0.391-0.391-0.589-0.391-0.943v-0.06c-0.005-0.221-0.067-0.437-0.181-0.625s-0.278-0.345-0.479-0.444c-0.201-0.089-0.424-0.115-0.64-0.076s-0.414 0.143-0.573 0.296l-0.04 0.04c-0.124 0.124-0.271 0.223-0.433 0.291s-0.335 0.103-0.51 0.103-0.348-0.035-0.51-0.103-0.309-0.167-0.433-0.291c-0.124-0.124-0.223-0.271-0.291-0.433s-0.103-0.335-0.103-0.51 0.035-0.348 0.103-0.51 0.167-0.309 0.291-0.433l0.04-0.04c0.153-0.157 0.257-0.357 0.296-0.573s0.013-0.44-0.076-0.64c-0.084-0.197-0.228-0.365-0.416-0.484s-0.408-0.181-0.64-0.181h-0.113c-0.354 0-0.693-0.14-0.943-0.391s-0.391-0.589-0.391-0.943 0.14-0.693 0.391-0.943 0.589-0.391 0.943-0.391h0.06c0.221-0.005 0.437-0.067 0.625-0.181s0.345-0.278 0.444-0.479c0.089-0.201 0.115-0.424 0.076-0.64s-0.143-0.414-0.296-0.573l-0.04-0.04c-0.124-0.124-0.223-0.271-0.291-0.433s-0.103-0.335-0.103-0.51 0.035-0.348 0.103-0.51 0.167-0.309 0.291-0.433c0.124-0.124 0.271-0.223 0.433-0.291s0.335-0.103 0.51-0.103 0.348 0.035 0.51 0.103 0.309 0.167 0.433 0.291l0.04 0.04c0.157 0.153 0.357 0.257 0.573 0.296s0.44 0.013 0.64-0.076h0.060c0.197-0.084 0.365-0.228 0.484-0.416s0.181-0.408 0.181-0.64v-0.113c0-0.354 0.14-0.693 0.391-0.943s0.589-0.391 0.943-0.391 0.693 0.14 0.943 0.391 0.391 0.589 0.391 0.943v0.06c0.005 0.221 0.067 0.437 0.181 0.625s0.278 0.345 0.479 0.444c0.201 0.089 0.424 0.115 0.64 0.076s0.414-0.143 0.573-0.296l0.04-0.04c0.124-0.124 0.271-0.223 0.433-0.291s0.335-0.103 0.51-0.103 0.348 0.035 0.51 0.103 0.309 0.167 0.433 0.291c0.124 0.124 0.223 0.271 0.291 0.433s0.103 0.335 0.103 0.51-0.035 0.348-0.103 0.51-0.167 0.309-0.291 0.433l-0.04 0.04c-0.153 0.157-0.257 0.357-0.296 0.573s-0.013 0.44 0.076 0.64v0.060c0.084 0.197 0.228 0.365 0.416 0.484s0.408 0.181 0.64 0.181h0.113c0.354 0 0.693 0.14 0.943 0.391s0.391 0.589 0.391 0.943-0.14 0.693-0.391 0.943-0.589 0.391-0.943 0.391h-0.06c-0.221 0.005-0.437 0.067-0.625 0.181s-0.345 0.278-0.444 0.479v0z" stroke="currentColor" stroke-width="1.33" stroke-linecap="round" stroke-linejoin="round"></path>
</g>
</svg>
            </div>
            <div class="label"><strong>{{ $translation["ManageGlossaries"] ?? "Manage Glossaries" }}</strong></div>
        </button>
    </div>
</div>
