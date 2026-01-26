<style>
    /* Ensure columns in the MCP playground have equal height cards */
    .mcp-playground-container .row.d-md-flex {
        align-items: stretch;
    }
    .mcp-playground-container .row.d-md-flex > div {
        display: flex;
        flex-direction: column;
    }
    .mcp-playground-container .card, 
    .mcp-playground-container .bg-white.rounded.shadow-sm,
    .mcp-playground-container [role="presentation"] {
        height: 100%;
        flex: 1;
        margin-bottom: 0 !important;
    }
    /* Ensure the internal row of Layout::rows also takes full height */
    .mcp-playground-container .bg-white.rounded.shadow-sm > .row {
        height: 100%;
    }
</style>
<div class="mcp-playground-container-marker" style="display:none;"></div>
