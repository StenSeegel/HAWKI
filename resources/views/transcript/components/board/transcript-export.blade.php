            <!-- TAB: EXPORT -->
            <div id="tab-export" class="tab-content tab-content-wrapper hidden">
                <style>
                    /* Template select panel layout */
                    .template-panel {
                        display: flex;
                        flex-direction: column;
                        flex: 1;
                        height: 100%;
                        overflow: hidden;
                        background: var(--background-main);
                        box-sizing: border-box;
                    }
                    .template-panel-header {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 0 1.5rem;
                        flex-shrink: 0;
                        border-top-left-radius: 12px;
                        border-top-right-radius: 12px;
                        position: relative;
                    }
                    .template-header-back {
                        display: flex;
                        align-items: center;
                        gap: 12px;
                        cursor: pointer;
                    }
                    .template-header-actions {
                        position: relative;
                        display: flex;
                        align-items: center;
                        gap: 8px;
                    }
                    .template-search-input {
                        display: none;
                        padding: 6px 10px;
                        font-size: 13px;
                        border-radius: 6px;
                        border: var(--border-stroke-thin, 1px solid #cbd5e1);
                        background: var(--panel-secondary, #ffffff);
                        color: var(--text-color);
                        width: 140px;
                        box-sizing: border-box;
                    }
                    .template-scrollable-content {
                        flex: 1;
                        overflow-y: auto;
                        padding: 24px;
                        box-sizing: border-box;
                    }
                    .template-grid-user, .template-grid-library {
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        gap: 16px;
                    }
                    .template-grid-user {
                        margin-bottom: 24px;
                    }

                    /* Template editor header layout */
                    .template-header-left {
                        display: flex;
                        align-items: center;
                        gap: 16px;
                        flex: 1;
                    }
                    .template-back-arrow {
                        cursor: pointer;
                        display: flex;
                        align-items: center;
                    }
                    .template-editor-title-container {
                        display: flex;
                        align-items: center;
                        gap: 4px;
                        flex: 1;
                        max-width: 500px;
                    }
                    .template-name-input-wrapper {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                    }
                    .template-name-input {
                        padding: 2px 4px;
                        margin: 0;
                        font-size: 16px;
                        font-weight: 700;
                        border-radius: 6px;
                        border: 1px solid transparent;
                        background: transparent;
                        color: var(--text-color);
                        outline: none;
                        transition: all 0.2s;
                        width: auto;
                        max-width: 320px;
                    }
                    .template-name-input:focus {
                        border: 1px solid #cbd5e1;
                        background: var(--panel-secondary, #ffffff);
                        padding-left: 8px;
                        padding-right: 8px;
                    }
                    .template-edit-icon {
                        color: var(--text-faded-color);
                        flex-shrink: 0;
                        pointer-events: none;
                        margin-left: 2px;
                    }

                    /* Template editor grid layout */
                    .template-editor-grid {
                        flex: 1;
                        display: grid;
                        grid-template-columns: 1fr 1fr;
                        overflow-y: auto;
                        background: var(--background-main);
                        height: calc(100% - 56px);
                        align-items: start;
                    }
                    .template-editor-col-left {
                        display: flex;
                        flex-direction: column;
                        padding: 20px;
                        box-sizing: border-box;
                    }
                    .template-element-palette {
                        margin-bottom: 20px;
                        background: var(--panel-secondary, #ffffff);
                        border: var(--border-stroke-thin);
                        border-radius: 8px;
                        padding: 16px;
                        box-sizing: border-box;
                        display: flex;
                        flex-direction: column;
                        gap: 12px;
                    }
                    .palette-row {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        font-size: 13px;
                        flex-wrap: wrap;
                    }
                    .palette-row-align-start {
                        align-items: flex-start;
                    }
                    .palette-row-label {
                        color: var(--text-faded-color);
                        font-weight: 600;
                        width: 120px;
                        flex-shrink: 0;
                    }
                    .palette-row-label-margin-top {
                        margin-top: 2px;
                    }
                    .palette-row-items {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 8px;
                        flex: 1;
                    }
                    .palette-divider {
                        height: 1px;
                        background: #cbd5e1;
                        opacity: 0.3;
                    }
                    .palette-btn {
                        padding: 2px 8px;
                        border-radius: 4px;
                        font-size: 12px;
                        font-weight: 500;
                        cursor: pointer;
                        box-sizing: border-box;
                    }
                    .palette-btn-blue {
                        border: 1px solid #3b82f6;
                        background: #eff6ff;
                        color: #1d4ed8;
                    }
                    .palette-btn-purple {
                        border: 1px solid #8b5cf6;
                        background: #f5f3ff;
                        color: #6d28d9;
                    }
                    .palette-btn-purple-dashed {
                        border: 1px dashed #8b5cf6;
                        background: #f5f3ff;
                        color: #6d28d9;
                    }

                    /* Template editor lists and legend */
                    .template-editor-blocks-list {
                        display: flex;
                        flex-direction: column;
                        gap: 16px;
                    }
                    .template-legend {
                        display: flex;
                        align-items: center;
                        gap: 16px;
                        margin-top: 24px;
                        font-size: 12px;
                        color: var(--text-faded-color);
                    }
                    .legend-item {
                        display: flex;
                        align-items: center;
                        gap: 6px;
                    }
                    .legend-color-box {
                        display: inline-block;
                        width: 12px;
                        height: 12px;
                        border-radius: 3px;
                    }
                    .legend-color-data {
                        background: #eff6ff;
                        border: 1px solid #3b82f6;
                    }
                    .legend-color-ai {
                        background: #f5f3ff;
                        border: 1px solid #8b5cf6;
                    }

                    /* Preview column layout */
                    .template-editor-col-right {
                        display: flex;
                        flex-direction: column;
                        position: sticky;
                        top: 0;
                        padding: 20px;
                        box-sizing: border-box;
                    }
                    .preview-bubble-card {
                        background: var(--panel-secondary, #ffffff);
                        border: var(--border-stroke-thin);
                        border-radius: 8px;
                        overflow: hidden;
                        display: flex;
                        flex-direction: column;
                    }
                    .preview-actions-header {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 12px 20px;
                        border-bottom: var(--border-stroke-thin);
                    }
                    .preview-notice-banner {
                        background: #eff6ff;
                        border: 1px solid #bfdbfe;
                        border-radius: 6px;
                        color: #1e3a8a;
                        padding: 10px 16px;
                        margin: 12px 20px 0 20px;
                        font-size: 12px;
                        display: flex;
                        align-items: flex-start;
                        gap: 8px;
                        line-height: 1.4;
                    }
                    .preview-notice-icon {
                        flex-shrink: 0;
                        margin-top: 2px;
                    }
                    .preview-render-area {
                        padding: 24px;
                        box-sizing: border-box;
                    }
                    .preview-footer-notice {
                        padding: 10px 20px;
                        border-top: var(--border-stroke-thin);
                        font-size: 11px;
                        color: var(--text-faded-color);
                        display: flex;
                        align-items: center;
                        gap: 6px;
                        background: var(--background-secondary, #f8fafc);
                    }
                    .palette-title {
                        font-size: 11px;
                        font-weight: 700;
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        color: var(--text-faded-color);
                    }

                    /* Dynamic blocks layout in JS (ExportManager) */
                    .editor-block-card {
                        background: var(--panel-secondary, #ffffff);
                        border: var(--border-stroke-thin);
                        border-radius: 8px;
                        padding: 12px 16px 32px 16px;
                        position: relative;
                        transition: border 0.15s ease, opacity 0.15s ease;
                    }
                    .block-controls {
                        position: absolute;
                        top: 12px;
                        right: 12px;
                        display: flex;
                        gap: 6px;
                        align-items: center;
                    }
                    .btn-block-ctrl {
                        border: none;
                        background: transparent;
                        cursor: pointer;
                        color: var(--text-faded-color);
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        width: 22px;
                        height: 22px;
                        box-sizing: border-box;
                        border-radius: 4px;
                        transition: background-color 0.15s ease, color 0.15s ease;
                        padding: 0;
                    }
                    .btn-block-ctrl:hover {
                        background-color: var(--background-secondary, #f1f5f9);
                        color: var(--text-color);
                    }
                    .btn-block-ctrl.delete {
                        color: #ef4444;
                    }
                    .block-card-type-header {
                        font-size: 11px;
                        font-weight: 700;
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        margin-bottom: 8px;
                        display: flex;
                        align-items: center;
                        gap: 4px;
                    }
                    .block-card-type-header.heading, .block-card-type-header.text {
                        color: #3b82f6;
                    }
                    .block-card-type-header.section {
                        color: #8b5cf6;
                    }
                    .block-card-type-header.divider {
                        color: #64748b;
                    }
                    .block-type-dot {
                        display: inline-block;
                        width: 6px;
                        height: 6px;
                        border-radius: 50%;
                    }
                    .block-type-dot.blue {
                        background: #3b82f6;
                    }
                    .block-type-dot.purple {
                        background: #8b5cf6;
                    }
                    .block-type-dot.gray {
                        background: #64748b;
                    }
                    .block-card-body-row {
                        margin-right: 80px;
                        display: flex;
                        gap: 8px;
                        align-items: center;
                    }
                    .block-card-body-column {
                        margin-right: 80px;
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                    }
                    .block-heading-level {
                        width: 90px;
                        flex-shrink: 0;
                        padding: 6px 30px 6px 10px;
                        font-size: 13px;
                        border-radius: 6px;
                        border: var(--border-stroke-thin, 1px solid #cbd5e1);
                        background: var(--panel-secondary, #ffffff) url('data:image/svg+xml,%3csvg xmlns=%27http://www.w3.org/2000/svg%27 fill=%27none%27 viewBox=%270 0 20 20%27%3e%3cpath stroke=%27%236b7280%27 stroke-linecap=%27round%27 stroke-linejoin=%27round%27 stroke-width=%271.5%27 d=%27M6 8l4 4 4-4%27/%3e%3c/svg%3e') no-repeat right 8px center/16px 16px;
                        color: var(--text-color, #334155);
                        cursor: pointer;
                        appearance: none;
                        -webkit-appearance: none;
                        -moz-appearance: none;
                        box-sizing: border-box;
                        height: 38px;
                        line-height: 1.5;
                    }
                    .block-heading-level option {
                        background: var(--panel-secondary, #ffffff);
                        color: var(--text-color, #334155);
                    }
                    .block-text-input {
                        flex: 1;
                        padding: 6px 10px;
                        font-size: 13px;
                        border-radius: 6px;
                        border: var(--border-stroke-thin, 1px solid #cbd5e1);
                        background: transparent;
                        color: var(--text-color, #334155);
                        box-sizing: border-box;
                        height: 38px;
                    }
                    .block-section-heading {
                        padding: 6px 10px;
                        font-size: 13px;
                        font-weight: 600;
                        border-radius: 6px;
                        border: var(--border-stroke-thin, 1px solid #cbd5e1);
                        background: transparent;
                        color: var(--text-color, #334155);
                        box-sizing: border-box;
                        height: 38px;
                    }
                    .block-section-instruction {
                        padding: 6px 10px;
                        font-size: 12px;
                        border-radius: 6px;
                        border: var(--border-stroke-thin, 1px solid #cbd5e1);
                        background: #faf5ff;
                        border-color: #d8b4fe;
                        color: var(--text-color);
                        min-height: 50px;
                        resize: vertical;
                        font-family: inherit;
                    }
                    .block-divider-hr {
                        flex: 1;
                        border: none;
                        border-top: var(--border-stroke-thin, 1px solid #cbd5e1);
                        margin: 0;
                    }
                    .drag-handle {
                        cursor: grab;
                    }
                    .btn-block-ctrl-delete-bottom {
                        position: absolute;
                        bottom: 10px;
                        right: 12px;
                        cursor: pointer;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        border: none;
                        background: transparent;
                        color: #ef4444;
                        width: 22px;
                        height: 22px;
                        box-sizing: border-box;
                        border-radius: 4px;
                        transition: background-color 0.15s ease, opacity 0.15s ease;
                        padding: 0;
                    }
                    .btn-block-ctrl-delete-bottom:hover {
                        background-color: #fee2e2;
                        opacity: 1;
                    }

                    /* Template card list cards styling in JS */
                    .template-card-header {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        margin-bottom: 8px;
                        width: 100%;
                    }
                    .template-header-left-inner {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                    }
                    .template-title {
                        margin: 0;
                        font-size: 14px;
                        font-weight: 700;
                        color: var(--text-color);
                    }
                    .template-desc {
                        margin: 0 0 16px 0;
                        font-size: 12px;
                        color: var(--text-faded-color);
                        line-height: 1.4;
                    }
                    .template-actions {
                        display: flex;
                        gap: 16px;
                        align-items: center;
                        width: 100%;
                    }
                    .template-action-link.delete {
                        color: #ef4444;
                        margin-left: auto;
                    }
                    .new-template-card {
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        justify-content: center;
                        border: 1.5px dashed var(--border-stroke-thin, #cbd5e1);
                        border-radius: 8px;
                        padding: 24px;
                        cursor: pointer;
                        background: var(--background-secondary, #f8fafc);
                        transition: all 0.2s ease;
                        box-sizing: border-box;
                    }
                    .new-template-card:hover {
                        border-color: var(--color-primary-blue, #3b82f6);
                        background: var(--panel-secondary, #ffffff);
                    }
                    .new-template-card-icon {
                        color: var(--text-faded-color);
                        margin-bottom: 8px;
                    }
                    .new-template-card-title {
                        margin: 0;
                        font-size: 14px;
                        font-weight: 700;
                        color: var(--text-color);
                    }
                    .new-template-card-desc {
                        margin: 2px 0 0 0;
                        font-size: 11px;
                        color: var(--text-faded-color);
                    }
                    .refresh-sec-icon {
                        cursor: pointer;
                        display: inline-flex;
                        margin-left: 4px;
                        vertical-align: middle;
                        color: var(--text-faded-color);
                    }
                    .skeleton-line {
                        height: 10px;
                        background: #cbd5e1;
                        border-radius: 4px;
                    }
                    .skeleton-line.active,
                    .skeleton-line.pulse {
                        animation: pulse 1.5s infinite;
                    }
                    .skeleton-line.w-full {
                        margin-bottom: 6px;
                    }
                    @keyframes pulse {
                        0%, 100% {
                            opacity: 1;
                        }
                        50% {
                            opacity: 0.5;
                        }
                    }
                    .loader-spinner {
                        border: 2px solid #ffffff;
                        border-top: 2px solid transparent;
                        border-radius: 50%;
                        width: 12px;
                        height: 12px;
                        display: inline-block;
                        animation: spin 1s linear infinite;
                        margin-right: 6px;
                    }
                    .btn-editor-test-preview, .btn-editor-save {
                        padding: 6px 14px;
                        font-size: 12px;
                        font-weight: 600;
                        border-radius: 6px;
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                        height: auto;
                        transition: opacity 0.2s ease, background-color 0.2s ease;
                        color: var(--invert-Text-color);
                        background: var(--button-color);
                        border: none;
                        cursor: pointer;
                    }
                    .btn-editor-test-preview svg, .btn-editor-save svg {
                        width: 14px;
                        height: 14px;
                        flex-shrink: 0;
                        stroke: var(--invert-Text-color);
                    }
                    .btn-editor-test-preview:disabled {
                        opacity: 0.5;
                        cursor: not-allowed;
                    }

                    /* Layout and Spacing Helpers for Export Panel */
                    .sidebar-export-tools {
                        gap: 16px;
                    }
                    .section-title-margin {
                        margin-top: 0;
                        margin-bottom: 4px;
                    }
                    .transcript-main-view-wrapper {
                        position: relative;
                    }
                    .export-preview-panel-container {
                        display: flex;
                        flex-direction: column;
                        flex: 1;
                        height: 100%;
                        overflow: hidden;
                    }
                    .flex-align-center {
                        display: flex;
                        align-items: center;
                    }
                    .flex-justify-end-gap {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        justify-content: flex-end;
                    }
                    .text-uppercase-header {
                        text-transform: uppercase;
                    }
                    .export-output-container {
                        background: transparent;
                        border: none;
                        box-shadow: none;
                        padding: 20px;
                        display: flex;
                        flex-direction: column;
                        flex: 1;
                        overflow-y: auto;
                    }
                    .export-result-container {
                        background: transparent;
                        border: none;
                        box-shadow: none;
                        padding: 0;
                        flex: 1;
                    }
                    .export-preview-title {
                        font-size: 18px;
                        font-weight: 700;
                        color: #0f172a;
                        margin-top: 0;
                        margin-bottom: 4px;
                    }
                    .export-preview-participants {
                        font-size: 13px;
                        color: #64748b;
                        margin-top: 0;
                        margin-bottom: 16px;
                    }
                    .export-preview-meta-panel hr {
                        border: 0;
                        border-top: 1px solid #e2e8f0;
                        margin-bottom: 16px;
                    }
                    .export-preview-meta-panel h4 {
                        font-size: 14px;
                        font-weight: 700;
                        color: #0f172a;
                        margin-bottom: 8px;
                    }
                    .export-preview-text {
                        white-space: normal;
                        font-family: inherit;
                        font-size: inherit;
                    }
                    .transcript-view-footer {
                        padding: 16px 20px;
                        border-top: 1px solid #e2e8f0;
                        display: flex;
                        flex-direction: column;
                        background: #f8fafc;
                        box-sizing: border-box;
                        flex-shrink: 0;
                        gap: 12px;
                        width: 100%;
                        height: auto;
                    }
                    .footer-row-flex {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        width: 100%;
                    }
                    .footer-row-flex-gap {
                        display: flex;
                        align-items: center;
                        gap: 12px;
                        width: 100%;
                        margin-top: 4px;
                    }
                    .footer-label {
                        font-size: 11px;
                        font-weight: 700;
                        color: #475569;
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                    }
                    .footer-status {
                        font-size: 11px;
                        font-weight: 500;
                        color: #64748b;
                    }
                    .export-format-options-footer-container {
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                        width: 100%;
                    }
                    .export-format-options-footer {
                        display: flex;
                        gap: 8px;
                    }
                    .icon-margin-right {
                        margin-right: 6px;
                    }
                    .icon-size-16 {
                        width: 16px;
                        height: 16px;
                    }
                    .icon-transition {
                        transition: transform 0.2s;
                    }
                    .icon-transition.rotate-180 {
                        transform: rotate(180deg);
                    }
                    .icon-color-text {
                        color: var(--text-color);
                    }
                    .icon-color-yellow {
                        color: #eab308;
                    }
                    .template-title-margin {
                        text-transform: uppercase;
                        flex-shrink: 0;
                        margin-top: 1px;
                    }
                    .display-flex {
                        display: flex !important;
                    }
                    .display-none {
                        display: none !important;
                    }
                    .export-markdown-preview {
                        display: flex;
                        flex-direction: column;
                        gap: 16px;
                        color: var(--text-color);
                        font-family: inherit;
                    }
                    .preview-heading-lvl-1 {
                        margin: 16px 0 8px 0;
                        font-weight: 700;
                        color: var(--text-color);
                        font-size: 18px;
                    }
                    .preview-heading-lvl-2 {
                        margin: 16px 0 8px 0;
                        font-weight: 700;
                        color: var(--text-color);
                        font-size: 15px;
                        border-bottom: 1px solid #cbd5e1;
                        padding-bottom: 4px;
                    }
                    .preview-heading-lvl-3 {
                        margin: 16px 0 8px 0;
                        font-weight: 700;
                        color: var(--text-color);
                        font-size: 13px;
                    }
                    .preview-text-block {
                        margin: 0 0 8px 0;
                        font-size: 13px;
                        color: var(--text-faded-color);
                        line-height: 1.5;
                    }
                    .preview-divider-hr {
                        border: none;
                        border-top: var(--border-stroke-thin);
                        margin: 16px 0;
                    }
                    .preview-section-container {
                        margin-bottom: 16px;
                    }
                    .preview-section-header-flex {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        margin-bottom: 6px;
                    }
                    .preview-section-heading-h4 {
                        margin: 0;
                        font-size: 14px;
                        font-weight: 700;
                        color: var(--text-color);
                    }
                    .badge-container-flex {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                    }
                    .badge-stale-instruction {
                        background: var(--panel-secondary, #f1f5f9);
                        border: 1px solid #cbd5e1;
                        color: var(--text-faded-color);
                        font-size: 10px;
                        font-weight: 600;
                        padding: 1px 6px;
                        border-radius: 4px;
                    }
                    .refresh-link-inline {
                        color: #3b82f6;
                        cursor: pointer;
                        font-size: 11px;
                        font-weight: 600;
                    }
                    .section-preview-body {
                        font-size: 13px;
                        line-height: 1.5;
                    }
                    .section-preview-body.stale {
                        opacity: 0.5;
                        border: 1px dashed #cbd5e1;
                        padding: 8px 12px;
                        border-radius: 6px;
                    }
                    .section-preview-body.empty {
                        color: var(--text-faded-color);
                        font-style: italic;
                    }
                    .opacity-1 {
                        opacity: 1 !important;
                    }
                    .measure-span-hidden {
                        visibility: hidden;
                        position: absolute;
                        white-space: pre;
                    }
                    .drag-dragging {
                        opacity: 0.4 !important;
                    }
                    .drag-over-top {
                        border-top: 2px solid #3b82f6 !important;
                    }
                    .drag-over-bottom {
                        border-bottom: 2px solid #3b82f6 !important;
                    }
                    
                    /* Transcript formatting template selector card */
                    .transcript-dropdown-card {
                        position: absolute;
                        top: calc(100% + 4px);
                        right: 0;
                        width: 100%;
                        max-width: 320px;
                        background: var(--panel-secondary, #ffffff);
                        border: var(--border-stroke-thin, 1px solid #cbd5e1);
                        border-radius: 8px;
                        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                        z-index: 100;
                        display: flex;
                        flex-direction: column;
                        padding: 12px;
                        box-sizing: border-box;
                    }
                    .darkMode .transcript-dropdown-card {
                        background: var(--panel-secondary, #1e1e1e);
                        border-color: var(--border-stroke-thin, #333);
                        box-shadow: 0 4px 25px rgba(0, 0, 0, 0.4);
                    }
                    .transcript-dropdown-section {
                        margin-bottom: 12px;
                        border-bottom: 1px solid var(--border-color, #e2e8f0);
                        padding-bottom: 8px;
                    }
                    .darkMode .transcript-dropdown-section {
                        border-bottom-color: #2e2e2e;
                    }
                    .transcript-dropdown-section:last-of-type {
                        border-bottom: none;
                        margin-bottom: 0;
                        padding-bottom: 0;
                    }
                    .transcript-dropdown-sec-title {
                        font-size: 10px;
                        font-weight: 700;
                        color: var(--text-faded-color);
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        margin-bottom: 8px;
                        padding-left: 4px;
                    }
                    .transcript-dropdown-list {
                        display: flex;
                        flex-direction: column;
                        gap: 4px;
                    }
                    .transcript-dropdown-item {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 8px;
                        border-radius: 6px;
                        cursor: pointer;
                        transition: background-color 0.15s ease;
                    }
                    .transcript-dropdown-item:hover {
                        background: var(--background-secondary, #f1f5f9);
                    }
                    .darkMode .transcript-dropdown-item:hover {
                        background: var(--background-main, #121212);
                    }
                    .transcript-dropdown-item.active {
                        background: #eff6ff;
                        color: #1d4ed8;
                    }
                    .darkMode .transcript-dropdown-item.active {
                        background: #1e3a8a;
                        color: #eff6ff;
                    }
                    .transcript-item-content {
                        display: flex;
                        flex-direction: column;
                        gap: 2px;
                    }
                    .transcript-item-title {
                        font-size: 13px;
                        font-weight: 600;
                        color: var(--text-color);
                    }
                    .transcript-dropdown-item.active .transcript-item-title {
                        color: #1e3a8a;
                    }
                    .darkMode .transcript-dropdown-item.active .transcript-item-title {
                        color: #ffffff;
                    }
                    .transcript-item-desc {
                        font-size: 11px;
                        color: var(--text-faded-color);
                    }
                    .transcript-dropdown-item.active .transcript-item-desc {
                        color: #2563eb;
                    }
                    .darkMode .transcript-dropdown-item.active .transcript-item-desc {
                        color: #93c5fd;
                    }
                    .transcript-item-checkmark {
                        color: #2563eb;
                        flex-shrink: 0;
                    }
                    .darkMode .transcript-item-checkmark {
                        color: #60a5fa;
                    }
                    
                    /* Custom Accordion inside card */
                    .transcript-dropdown-accordion {
                        display: flex;
                        flex-direction: column;
                        margin-top: 4px;
                    }
                    .transcript-accordion-header {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 8px 4px;
                        cursor: pointer;
                        font-size: 11px;
                        font-weight: 700;
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        color: var(--text-faded-color);
                        border-top: var(--border-stroke-thin);
                        margin-top: 4px;
                    }
                    .darkMode .transcript-accordion-header {
                        border-top-color: var(--border-color);
                    }
                    .transcript-accordion-header-left {
                        display: flex;
                        align-items: center;
                        gap: 6px;
                    }
                    .transcript-accordion-content {
                        display: flex;
                        flex-direction: column;
                        gap: 8px;
                        padding: 8px 4px 4px 4px;
                    }
                    .transcript-toggle-row {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        font-size: 13px;
                        font-weight: 500;
                        color: var(--text-color);
                    }
                    .transcript-toggle-row-vertical {
                        display: flex;
                        flex-direction: column;
                        gap: 6px;
                        margin-top: 4px;
                    }
                    .transcript-row-label {
                        font-size: 11px;
                        font-weight: 600;
                        color: var(--text-faded-color);
                        text-transform: uppercase;
                    }
                    
                    /* Custom Switch toggle slider */
                    .transcript-switch {
                        position: relative;
                        display: inline-block;
                        width: 34px;
                        height: 20px;
                    }
                    .transcript-switch input {
                        opacity: 0;
                        width: 0;
                        height: 0;
                    }
                    .transcript-slider {
                        position: absolute;
                        cursor: pointer;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background-color: #cbd5e1;
                        transition: .2s;
                        border-radius: 20px;
                    }
                    .darkMode .transcript-slider {
                        background-color: #475569;
                    }
                    .transcript-slider:before {
                        position: absolute;
                        content: "";
                        height: 14px;
                        width: 14px;
                        left: 3px;
                        bottom: 3px;
                        background-color: white;
                        transition: .2s;
                        border-radius: 50%;
                    }
                    .transcript-switch input:checked + .transcript-slider {
                        background-color: #3b82f6;
                    }
                    .transcript-switch input:checked + .transcript-slider:before {
                        transform: translateX(14px);
                    }
                    
                    /* Segmented Control */
                    .transcript-segmented-control {
                        display: flex;
                        border: 1px solid #cbd5e1;
                        border-radius: 6px;
                        overflow: hidden;
                        background: var(--background-secondary, #f8fafc);
                    }
                    .darkMode .transcript-segmented-control {
                        border-color: #333;
                        background: #121212;
                    }
                    .transcript-segmented-control button {
                        flex: 1;
                        padding: 6px 12px;
                        border: none;
                        background: transparent;
                        font-size: 11px;
                        font-weight: 600;
                        cursor: pointer;
                        color: var(--text-faded-color);
                        transition: background-color 0.15s ease, color 0.15s ease;
                    }
                    .transcript-segmented-control button.active {
                        background-color: var(--highlight-color);
                        color: var(--text-color);
                    }
                    .darkMode .transcript-segmented-control button.active {
                        background-color: var(--highlight-color);
                        color: var(--text-color);
                    }
                    
                    /* Speaker chips */
                    .transcript-speakers-chips-container {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 6px;
                    }
                    .transcript-speaker-chip {
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                        padding: 4px 8px;
                        border: 1px solid #cbd5e1;
                        border-radius: 12px;
                        font-size: 11px;
                        font-weight: 500;
                        cursor: pointer;
                        color: var(--text-color);
                        background: var(--panel-secondary, #ffffff);
                        transition: all 0.15s ease;
                    }
                    .darkMode .transcript-speaker-chip {
                        border-color: #333;
                        background: #1e1e1e;
                    }
                    .transcript-speaker-chip.disabled {
                        opacity: 0.5;
                        border-style: dashed;
                        text-decoration: line-through;
                    }
                    .transcript-speaker-chip-dot {
                        width: 6px;
                        height: 6px;
                        border-radius: 50%;
                        display: inline-block;
                    }
                    
                    /* Speaker dot colors */
                    .speaker-color-1 { background: linear-gradient(135deg, #1A73E8, #4ECDC4) !important; }
                    .speaker-color-2 { background: linear-gradient(135deg, #FA709A, #FEE140) !important; }
                    .speaker-color-3 { background: linear-gradient(135deg, #e36efb, #a777e3) !important; }
                    .speaker-color-4 { background: linear-gradient(135deg, #f5fdc4, #aaff9b) !important; }
                    .speaker-color-5 { background: linear-gradient(135deg, #43E97B, #38F9D7) !important; }
                    .speaker-color-6 { background: linear-gradient(135deg, #11998E, #38EF7D) !important; }
                    .speaker-color-7 { background: linear-gradient(135deg, #667EEA, #764BA2) !important; }
                    .speaker-color-8 { background: linear-gradient(135deg, #FF416C, #FF4B2B) !important; }
                    .speaker-color-9 { background: linear-gradient(135deg, #B224EF, #7579FF) !important; }
                    .speaker-color-10 { background: linear-gradient(135deg, #1E3C72, #2A5298) !important; }
                    
                    /* Eigene Vorlage button */
                    .btn-save-custom-template {
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        gap: 6px;
                        width: 100%;
                        padding: 8px;
                        border: 1px solid #cbd5e1;
                        border-radius: 6px;
                        background: transparent;
                        font-size: 11px;
                        font-weight: 600;
                        color: var(--text-color);
                        cursor: pointer;
                        margin-top: 8px;
                        transition: background-color 0.15s ease;
                    }
                    .darkMode .btn-save-custom-template {
                        border-color: #333;
                    }
                    .btn-save-custom-template:hover {
                        background: var(--background-secondary, #f8fafc);
                    }
                    .darkMode .btn-save-custom-template:hover {
                        background: #121212;
                    }
                    
                    /* Notice text */
                    .transcript-formatting-notice {
                        display: flex;
                        align-items: center;
                        gap: 4px;
                        font-size: 10px;
                        color: var(--text-faded-color);
                        margin-top: -4px;
                        margin-bottom: 8px;
                        padding-left: 4px;
                    }
                    .transcript-formatting-notice svg {
                        color: #eab308;
                        flex-shrink: 0;
                    }
                    
                    /* Template section header */
                    .template-section-header {
                        font-size: 11px;
                        font-weight: 700;
                        text-transform: uppercase;
                        letter-spacing: 0.05em;
                        color: var(--text-faded-color);
                        margin-top: 24px;
                        margin-bottom: 12px;
                        padding-left: 4px;
                    }

                    /* ==================================================
                       DARK MODE OVERRIDES for this panel
                       Same mapping as the dark-mode section in
                       public/css/transcript.css: white/#f8fafc surfaces ->
                       panel/background tokens, slate borders ->
                       rgb(61,61,61), slate headings -> --text-color,
                       muted slate -> --text-faded-color, blue accents ->
                       --accent-color. Semantic hues are kept and only their
                       tinted backgrounds are darkened.
                       ================================================== */

                    .darkMode .export-preview-title,
                    .darkMode .export-preview-meta-panel h4 {
                        color: var(--text-color) !important;
                    }
                    .darkMode .export-preview-participants,
                    .darkMode .footer-label,
                    .darkMode .footer-status {
                        color: var(--text-faded-color) !important;
                    }
                    .darkMode .export-preview-meta-panel hr {
                        border-top-color: rgb(61, 61, 61) !important;
                    }
                    .darkMode .export-output-container,
                    .darkMode .export-result-container {
                        background: transparent !important;
                    }
                    .darkMode .preview-heading-lvl-2 {
                        border-bottom-color: rgb(61, 61, 61) !important;
                    }
                    .darkMode .section-preview-body.stale {
                        border-color: rgb(61, 61, 61) !important;
                    }
                    .darkMode .palette-divider {
                        background: rgb(61, 61, 61) !important;
                    }
                    .darkMode .template-name-input:focus {
                        border-color: rgb(61, 61, 61) !important;
                        background: var(--panel-secondary) !important;
                    }
                    .darkMode .palette-btn-blue {
                        background: rgba(59, 130, 246, 0.18) !important;
                        color: #93c5fd !important;
                    }
                    .darkMode .palette-btn-purple,
                    .darkMode .palette-btn-purple-dashed {
                        background: rgba(139, 92, 246, 0.18) !important;
                        color: #c4b5fd !important;
                    }
                    .darkMode .legend-color-data {
                        background: rgba(59, 130, 246, 0.3) !important;
                    }
                    .darkMode .legend-color-ai {
                        background: rgba(139, 92, 246, 0.3) !important;
                    }
                    .darkMode .preview-notice-banner {
                        background: rgba(30, 58, 138, 0.35) !important;
                        border-color: #3b82f6 !important;
                        color: #93c5fd !important;
                    }
                    .darkMode .block-section-instruction {
                        background: rgba(139, 92, 246, 0.12) !important;
                        border-color: rgba(139, 92, 246, 0.5) !important;
                    }
                    .darkMode .block-card-type-header.heading,
                    .darkMode .block-card-type-header.text {
                        color: #93c5fd !important;
                    }
                    .darkMode .block-card-type-header.section {
                        color: #c4b5fd !important;
                    }
                    .darkMode .block-card-type-header.divider {
                        color: var(--text-faded-color) !important;
                    }
                    .darkMode .block-type-dot.blue {
                        background: #93c5fd !important;
                    }
                    .darkMode .block-type-dot.purple {
                        background: #c4b5fd !important;
                    }
                    .darkMode .block-type-dot.gray {
                        background: var(--text-faded-color) !important;
                    }
                    .darkMode .btn-block-ctrl.delete,
                    .darkMode .btn-block-ctrl-delete-bottom,
                    .darkMode .template-action-link.delete {
                        color: #f87171 !important;
                    }
                    .darkMode .btn-block-ctrl-delete-bottom:hover {
                        background-color: rgba(239, 68, 68, 0.18) !important;
                    }
                    .darkMode .refresh-link-inline {
                        color: #93c5fd !important;
                    }
                    .darkMode .drag-over-top {
                        border-top-color: var(--accent-color) !important;
                    }
                    .darkMode .drag-over-bottom {
                        border-bottom-color: var(--accent-color) !important;
                    }
                    /* The save/preview buttons turn light in dark mode
                       (--button-color is white), so the white spinner needs
                       the inverted text color to stay visible. */
                    .darkMode .loader-spinner {
                        border-color: var(--invert-Text-color) !important;
                        border-top-color: transparent !important;
                    }

                    
                </style>
                <!-- Sidebar Export List -->
                <div id="export-tools-sidebar" class="export-options-list sidebar-tools sidebar-export-tools">
                    <h4 class="section-title field-label-uppercase section-title-margin">WAS MÖCHTEST DU EXPORTIEREN?</h4>
                    
                    <!-- Category: Dokumente -->
                    <div class="export-sidebar-category">Dokumente</div>
                    
                    <div class="sidebar-export-card active" data-option="summary" onclick="window.app.exportManager.selectExportOption('summary');">
                        <div class="card-icon-box">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-summary-icon lucide-summary"><path d="M15 4H7"/><path d="m18 16 3 3-3 3"/><path d="M3 4v13a2 2 0 0 0 2 2h16"/><path d="M7 14h7"/><path d="M7 9h12"/></svg>
                        </div>
                        <div class="card-details">
                            <h4>Zusammenfassung</h4>
                            <p>Kernaussagen & Ergebnisse</p>
                        </div>
                    </div>

                    <div class="sidebar-export-card" data-option="transcript" onclick="window.app.exportManager.selectExportOption('transcript');">
                        <div class="card-icon-box">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-messages-square-icon lucide-messages-square"><path d="M16 10a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 14.286V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/><path d="M20 9a2 2 0 0 1 2 2v10.286a.71.71 0 0 1-1.212.502l-2.202-2.202A2 2 0 0 0 17.172 19H10a2 2 0 0 1-2-2v-1"/></svg>
                        </div>
                        <div class="card-details">
                            <h4>Volltext-Transkript</h4>
                            <p>Wort für Wort, mit Sprechern</p>
                        </div>
                    </div>

                    <!-- Category: Video -->
                    <div class="export-sidebar-category">Video</div>

                    <div class="sidebar-export-card" data-option="srt" onclick="window.app.exportManager.selectExportOption('srt');">
                        <div class="card-icon-box">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-closed-caption-icon lucide-closed-caption"><path d="M10 9.17a3 3 0 1 0 0 5.66"/><path d="M17 9.17a3 3 0 1 0 0 5.66"/><rect x="2" y="5" width="20" height="14" rx="2"/></svg>
                        </div>
                        <div class="card-details">
                            <h4>Untertitel</h4>
                            <p>Für Video & Social</p>
                        </div>
                    </div>

                    <!-- Category: Daten -->
                    <div class="export-sidebar-category">Daten</div>

                    <div class="sidebar-export-card" data-option="json" onclick="window.app.exportManager.selectExportOption('json');">
                        <div class="card-icon-box">
                            <!-- Code Icon -->
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>
                        </div>
                        <div class="card-details">
                            <h4>Rohdaten (JSON)</h4>
                            <p>Für eigene Tools & KI</p>
                        </div>
                    </div>
                </div>

                <!-- Export Preview Area -->
                <div class="transcript-main-view transcript-main-view-wrapper">
                    <!-- Preview Panel (Default view) -->
                    <div id="export-preview-panel" class="export-preview-panel-container">
                        <!-- Pinned Header -->
                        <div class="transcript-view-header panel-header">
                            <div class="flex-align-center">
                                <span class="transcript-view-title text-uppercase-header" id="export-preview-subtitle">Vorschau</span>
                            </div>
                            <div id="export-header-actions" class="flex-justify-end-gap">
                                <button onclick="window.app.exportManager.regenerateCurrentExport()" class="btn-header-action" title="Ansicht neu generieren">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Sub Header (Template selection and format selector) -->
                        <div id="export-subheader" class="transcript-view-subheader panel-header">
                            <!-- Row 1: Template Selector (Only visible for 'summary') -->
                            <div id="export-template-panel" class="export-template-panel">
                                <span class="template-label">Vorlage</span>
                                <div class="export-template-select-trigger" onclick="window.app.exportManager.showTemplateSelect()">
                                    <span class="export-template-select-trigger-left">
                                        <span id="export-active-template-icon-container" class="export-active-template-icon">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-user-icon lucide-file-user"><path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z"/><path d="M14 2v5a1 1 0 0 0 1 1h5"/><path d="M16 22a4 4 0 0 0-8 0"/><circle cx="12" cy="15" r="3"/></svg>
                                        </span>
                                        <span id="export-active-template-name">Mein Interview-Format</span>
                                    </span>
                                    <span class="export-template-select-trigger-right">
                                        Ändern
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Template Selector for 'transcript' (Volltext-Transkript) view -->
                            <div id="export-transcript-template-panel" class="export-template-panel hidden">
                                <span class="template-label">Formatierung</span>
                                <div class="export-template-select-trigger" onclick="window.app.exportManager.showTranscriptSettings()">
                                    <span class="export-template-select-trigger-left">
                                        <span id="export-active-transcript-template-icon-container" class="export-active-template-icon">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sliders-horizontal"><line x1="21" y1="4" x2="14" y2="4"/><line x1="10" y1="4" x2="3" y2="4"/><line x1="21" y1="12" x2="12" y2="12"/><line x1="8" y1="12" x2="3" y2="12"/><line x1="21" y1="20" x2="16" y2="20"/><line x1="12" y1="20" x2="3" y2="20"/><line x1="14" y1="2" x2="14" y2="6"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="16" y1="18" x2="16" y2="22"/></svg>
                                        </span>
                                        <span id="export-active-transcript-template-name">Dialog (Standard)</span>
                                    </span>
                                    <span class="export-template-select-trigger-right">
                                        Ändern
                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Main Output Area (Scrollable) -->
                        <div id="export-output" class="transcription-output-container transcript-view-content export-output-container">
                            
                            <!-- Dotted Placeholder Card (Initially visible for 'ergebnis') -->
                            <div id="export-placeholder-view" class="export-placeholder-card">
                                <div class="export-placeholder-icon-circle">
                                    <!-- Sparkles Icon -->
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/><path d="m5 3 1 2.5L8.5 6 6 7 5 9.5 4 7 1.5 6 4 5 5 3Z"/><path d="m19 17 1 2.5 2.5.5-2.5 1-1 2.5-1-2.5-2.5-1 2.5-1 1-2.5Z"/></svg>
                                </div>
                                <h3 class="export-placeholder-title">Noch keine Zusammenfassung</h3>
                                <p class="export-placeholder-desc" id="export-placeholder-template-desc">Wird nach deiner Vorlage „Mein Interview-Format“ erstellt.</p>
                                
                                <button onclick="window.app.exportManager.generateErgebnisprotokoll(true)" class="btn-generate-placeholder">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-margin-right"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
                                    Zusammenfassung erstellen
                                </button>

                                <div class="export-placeholder-divider-container">
                                    <div class="export-placeholder-divider-line"></div>
                                    <span class="export-placeholder-divider-text">Das wird erstellt</span>
                                </div>

                                <div class="export-placeholder-skeleton-list">
                                    <div class="skeleton-item">
                                        <span class="skeleton-label">Zusammenfassung</span>
                                        <div class="skeleton-line-group">
                                            <div class="skeleton-line w-full"></div>
                                            <div class="skeleton-line w-2-3"></div>
                                        </div>
                                    </div>
                                    <div class="skeleton-item">
                                        <span class="skeleton-label">Entscheidungen</span>
                                        <div class="skeleton-line-group">
                                            <div class="skeleton-line w-1-2"></div>
                                        </div>
                                    </div>
                                    <div class="skeleton-item">
                                        <span class="skeleton-label">Aufgaben</span>
                                        <div class="skeleton-line-group">
                                            <div class="skeleton-line w-2-3"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>


                            <!-- Generated Content Area -->
                            <div class="transcription-box transcript-view-box hidden export-result-container" id="export-result-container">
                                <div id="export-preview-meta-panel" class="hidden export-preview-meta-panel">
                                    <h3 id="export-preview-title" class="export-preview-title"></h3>
                                    <p id="export-preview-participants" class="export-preview-participants"></p>
                                    <hr>
                                    <h4>Zusammenfassung</h4>
                                </div>
                                <div id="export-preview-content" class="export-preview-text"></div>
                            </div>
                        </div>

                        <!-- Pinned Footer -->
                        <div class="transcript-view-footer">
                            <!-- Row 1: Header / Status -->
                            <div class="footer-row-flex">
                                <span class="footer-label">Herunterladen als</span>
                                <span id="export-footer-status" class="footer-status">
                                    Zusammenfassung noch nicht erstellt
                                </span>
                            </div>

                            <!-- Row 2: Format Selector Row (Hidden by default) -->
                            <div id="export-format-options-footer-container" class="hidden export-format-options-footer-container">
                                <div id="export-format-options-footer" class="export-format-options-footer">
                                    <!-- Populated dynamically in selectExportOption -->
                                </div>
                            </div>

                            <!-- Row 3: Action Buttons -->
                            <div class="footer-row-flex-gap">
                                <div class="split-download-container">
                                    <button id="export-footer-download-btn" onclick="window.app.exportManager.triggerExportDownload()" class="btn-export-download-primary-split" disabled>
                                        <svg xmlns="http://www.w3.org/2000/svg" class="icon-size-16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                                        Als DOCX herunterladen
                                    </button>
                                    <div id="export-footer-split-separator" class="split-separator"></div>
                                    <button id="export-footer-chevron-btn" onclick="window.app.exportManager.toggleFormatOptions()" class="btn-export-download-chevron" disabled>
                                        <svg id="export-footer-chevron-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon-transition"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                    </button>
                                </div>
                                <button id="export-footer-copy-btn" onclick="window.app.exportManager.triggerExportCopy()" class="btn-export-copy-secondary" disabled>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="icon-size-16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></svg>
                                    Kopieren
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Template Select Panel (Initially hidden) -->
                    <div id="export-template-select-panel" class="hidden template-panel">
                        <!-- Header of Vorlage wählen -->
                        <div class="template-panel-header panel-header">
                            <div class="template-header-back" onclick="window.app.exportManager.hideTemplateSelect()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="icon-color-text"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                <span class="transcript-view-title text-uppercase-header">Vorlage wählen</span>
                            </div>
                            <div class="template-header-actions">
                                <input type="text" id="export-template-search-input" onkeyup="window.app.exportManager.filterTemplates(this.value)" placeholder="Suchen..." class="template-search-input">
                                <button onclick="window.app.exportManager.toggleSearchInput()" class="btn-header-action" title="Vorlage suchen">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Template Selection Content (Scrollable) -->
                        <div class="template-content-area template-scrollable-content">
                            
                            <!-- Category 1: MEINE VORLAGEN -->
                            <div class="template-section-header">Meine Vorlagen</div>
                            <div class="export-template-grid template-grid-user" id="user-templates-grid">
                                <!-- Dynamically populated -->
                            </div>

                            <!-- Category 2: BIBLIOTHEK -->
                            <div class="template-section-header">Bibliothek</div>
                            <div class="export-template-grid template-grid-library" id="library-templates-grid">
                                <!-- Dynamically populated -->
                            </div>
                        </div>
                    </div>

                    <!-- Template Editor Panel (Initially hidden) -->
                    <div id="export-template-editor-panel" class="hidden template-panel">
                        <!-- Header -->
                        <div class="template-panel-header panel-header">
                            <div class="template-header-left">
                                <div class="template-back-arrow" onclick="window.app.exportManager.closeTemplateEditor()">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="icon-color-text"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                </div>
                                <div class="template-editor-title-container">
                                    <span class="transcript-view-title template-title-margin">Vorlage bearbeiten</span>
                                    <span class="transcript-view-divider">|</span>
                                    <div class="template-name-input-wrapper">
                                        <input type="text" id="editor-template-name" placeholder="Vorlagenname" class="template-name-input">
                                        <label for="editor-template-name" style="cursor: pointer; display: flex; align-items: center; margin: 0;">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="template-edit-icon"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <button onclick="window.app.exportManager.saveTemplate()" class="btn-primary-blue btn-editor-save">Speichern</button>
                            </div>
                        </div>

                        <!-- Editor & Preview Grid -->
                        <div class="template-editor-grid">
                            
                            <!-- Left Column: Edit Template -->
                            <div class="template-editor-col-left">
                                
                                <!-- Insert Placeholders Row -->
                                <div style="display: flex; align-items: center; margin-bottom: 12px; min-height: 32px;">
                                    <div class="template-section-header" style="margin: 0;">Element-Palette</div>
                                </div>
                                <div class="editor-insert-toolbar template-element-palette">
                                    <!-- Row 1: Daten (Placeholders) -->
                                    <div class="palette-row">
                                        <span class="palette-row-label">Daten:</span>
                                        <div class="palette-row-items">
                                            <button type="button" onmousedown="event.preventDefault()" class="btn-tag-insert insert-data-placeholder palette-btn palette-btn-blue" data-placeholder="@{{titel}}" onclick="window.app.exportManager.insertPlaceholder('@{{titel}}')">+ Titel</button>
                                            <button type="button" onmousedown="event.preventDefault()" class="btn-tag-insert insert-data-placeholder palette-btn palette-btn-blue" data-placeholder="@{{datum}}" onclick="window.app.exportManager.insertPlaceholder('@{{datum}}')">+ Datum</button>
                                            <button type="button" onmousedown="event.preventDefault()" class="btn-tag-insert insert-data-placeholder palette-btn palette-btn-blue" data-placeholder="@{{teilnehmer}}" onclick="window.app.exportManager.insertPlaceholder('@{{teilnehmer}}')">+ Teilnehmer</button>
                                            <button type="button" onmousedown="event.preventDefault()" class="btn-tag-insert insert-data-placeholder palette-btn palette-btn-blue" data-placeholder="@{{dauer}}" onclick="window.app.exportManager.insertPlaceholder('@{{dauer}}')">+ Dauer</button>
                                        </div>
                                    </div>

                                    <!-- Divider -->
                                    <div class="palette-divider"></div>

                                    <!-- Row 2: Static Elements -->
                                    <div class="palette-row">
                                        <span class="palette-row-label">Statische Elemente:</span>
                                        <div class="palette-row-items">
                                            <button type="button" class="btn-tag-insert insert-heading-btn palette-btn palette-btn-blue" onclick="window.app.exportManager.addNewHeadingBlock()">+ Überschrift</button>
                                            <button type="button" class="btn-tag-insert insert-text-btn palette-btn palette-btn-blue" onclick="window.app.exportManager.addNewTextBlock()">+ Textfeld</button>
                                            <button type="button" class="btn-tag-insert insert-divider-btn palette-btn palette-btn-blue" onclick="window.app.exportManager.addNewDividerBlock()">+ Trennlinie</button>
                                        </div>
                                    </div>

                                    <!-- Divider -->
                                    <div class="palette-divider"></div>

                                    <!-- Row 3: AI Elements -->
                                    <div class="palette-row palette-row-align-start">
                                        <span class="palette-row-label palette-row-label-margin-top">KI-Elemente:</span>
                                        <div class="palette-row-items">
                                            <button type="button" class="btn-tag-insert insert-section-btn palette-btn palette-btn-purple" onclick="window.app.exportManager.addNewSectionBlock('Zusammenfassung', 'Fasse das Gespräch in 3–4 Sätzen zusammen')">+ Zusammenfassung</button>
                                            <button type="button" class="btn-tag-insert insert-section-btn palette-btn palette-btn-purple" onclick="window.app.exportManager.addNewSectionBlock('To-Dos', 'Erstelle eine To-do-Liste mit Aufgaben, Zuständigkeiten und Fristen')">+ To-Dos</button>
                                            <button type="button" class="btn-tag-insert insert-section-btn palette-btn palette-btn-purple" onclick="window.app.exportManager.addNewSectionBlock('Entscheidungen', 'Liste alle getroffenen Entscheidungen und Beschlüsse als Stichpunkte')">+ Entscheidungen</button>
                                            <button type="button" class="btn-tag-insert insert-section-btn palette-btn palette-btn-purple" onclick="window.app.exportManager.addNewSectionBlock('Ergebnisse', 'Fasse die wichtigsten Ergebnisse zusammen')">+ Ergebnisse</button>
                                            <button type="button" class="btn-tag-insert insert-section-btn palette-btn palette-btn-purple" onclick="window.app.exportManager.addNewSectionBlock('Kernaussagen', 'Fasse die Hauptthemen und wichtigsten Kernaussagen zusammen')">+ Kernaussagen</button>
                                            <button type="button" class="btn-tag-insert insert-section-btn palette-btn palette-btn-purple" onclick="window.app.exportManager.addNewSectionBlock('Zitate', 'Extrahiere besonders prägnante und repräsentative Zitate')">+ Zitate</button>
                                            <button type="button" class="btn-tag-insert insert-section-btn palette-btn palette-btn-purple" onclick="window.app.exportManager.addNewSectionBlock('Themen', 'Gliedere das Gespräch in die behandelten Themenschwerpunkte')">+ Themen</button>
                                            <button type="button" class="btn-tag-insert insert-section-btn palette-btn palette-btn-purple-dashed" onclick="window.app.exportManager.addNewSectionBlock('Freier KI-Abschnitt', 'Anweisung für die KI eingeben')">+ Freier KI-Abschnitt</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="template-section-header">Vorlage bearbeiten</div>
                                
                                <!-- Editor Blocks Container -->
                                <div id="editor-blocks-list" class="template-editor-blocks-list">
                                    <!-- Populated dynamically via JS -->
                                </div>
                                
                                <!-- Legend -->
                                <div class="template-legend">
                                    <div class="legend-item">
                                        <span class="legend-color-box legend-color-data"></span>
                                        <span>wird automatisch ausgefüllt</span>
                                    </div>
                                    <div class="legend-item">
                                        <span class="legend-color-box legend-color-ai"></span>
                                        <span>die KI schreibt hier</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right Column: Live Preview -->
                            <div class="template-editor-col-right">
                                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; min-height: 32px;">
                                    <div class="template-section-header" style="margin: 0;">So sieht das Ergebnis aus</div>
                                    <button id="editor-btn-test-preview" onclick="window.app.exportManager.testPreview()" class="btn-primary-blue btn-editor-test-preview">
                                        <!-- Flask icon -->
                                        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-flask-conical-icon lucide-flask-conical"><path d="M14 2v6a2 2 0 0 0 .245.96l5.51 10.08A2 2 0 0 1 18 22H6a2 2 0 0 1-1.755-2.96l5.51-10.08A2 2 0 0 0 10 8V2"/><path d="M6.453 15h11.094"/><path d="M8.5 2h7"/></svg>
                                        Vorschau testen
                                    </button>
                                </div>
                                <div class="preview-notice-banner" style="margin: 0 0 12px 0;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="preview-notice-icon"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    <span>Test-Vorschau auf Basis eines Transkript-Ausschnitts. Der finale Export nutzt das komplette Gespräch und ist ausführlicher.</span>
                                </div>
                                <div class="preview-bubble-card" style="border-radius: 12px;">
                                    <!-- Preview Render Area -->
                                    <div id="editor-preview-render" class="preview-render-area">
                                        <!-- Preview rendered here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transcript Settings Panel (Initially hidden) -->
                    <div id="export-transcript-settings-panel" class="hidden template-panel">
                        <!-- Header -->
                        <div class="template-panel-header panel-header">
                            <div class="template-header-left">
                                <div class="template-back-arrow" onclick="window.app.exportManager.hideTranscriptSettings()">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="icon-color-text"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
                                    <span class="transcript-view-title text-uppercase-header">Formatierung anpassen</span>
                                </div>
                            </div>
                        </div>

                        <!-- Grid -->
                        <div class="template-editor-grid">
                            <!-- Left Column: Settings -->
                            <div class="template-editor-col-left" style="gap: 24px; display: flex; flex-direction: column;">
                                
                                <!-- Category 1: MEINE VORLAGEN -->
                                <div id="ts-settings-custom-section" class="hidden">
                                    <div class="template-section-header" style="margin-top: 0; margin-bottom: 12px;">Meine Vorlagen</div>
                                    <div id="transcript-custom-templates-list" class="export-template-grid template-grid-user" style="margin-bottom: 12px;">
                                        <!-- Dynamically populated -->
                                    </div>
                                </div>
                                
                                <!-- Category 2: VOREINSTELLUNGEN -->
                                <div>
                                    <div class="template-section-header" style="margin-top: 0; margin-bottom: 12px;">Voreinstellungen</div>
                                    <div class="export-template-grid template-grid-library">
                                        <div class="template-select-card active" data-preset="dialog_standard" onclick="window.app.exportManager.selectTranscriptPreset('dialog_standard')">
                                            <div class="template-card-header">
                                                <div class="template-header-left-inner">
                                                    <div class="template-icon-box">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-sliders-horizontal"><line x1="21" y1="4" x2="14" y2="4"/><line x1="10" y1="4" x2="3" y2="4"/><line x1="21" y1="12" x2="12" y2="12"/><line x1="8" y1="12" x2="3" y2="12"/><line x1="21" y1="20" x2="16" y2="20"/><line x1="12" y1="20" x2="3" y2="20"/><line x1="14" y1="2" x2="14" y2="6"/><line x1="8" y1="10" x2="8" y2="14"/><line x1="16" y1="18" x2="16" y2="22"/></svg>
                                                    </div>
                                                    <h4 class="template-title">Dialog (Standard)</h4>
                                                </div>
                                                <span class="badge-active-pill">AKTIV</span>
                                            </div>
                                            <p class="template-desc">Namen · Zeitstempel · Avatare · chronologisch</p>
                                        </div>
                                        <div class="template-select-card" data-preset="lesefassung" onclick="window.app.exportManager.selectTranscriptPreset('lesefassung')">
                                            <div class="template-card-header">
                                                <div class="template-header-left-inner">
                                                    <div class="template-icon-box">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-book-open"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                                    </div>
                                                    <h4 class="template-title">Lesefassung</h4>
                                                </div>
                                                <span class="badge-active-pill hidden">AKTIV</span>
                                            </div>
                                            <p class="template-desc">Namen, ohne Zeitstempel — ruhig zum Lesen</p>
                                        </div>
                                        <div class="template-select-card" data-preset="zeitcodes" onclick="window.app.exportManager.selectTranscriptPreset('zeitcodes')">
                                            <div class="template-card-header">
                                                <div class="template-header-left-inner">
                                                    <div class="template-icon-box">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-clock"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                                    </div>
                                                    <h4 class="template-title">Mit Zeitcodes</h4>
                                                </div>
                                                <span class="badge-active-pill hidden">AKTIV</span>
                                            </div>
                                            <p class="template-desc">Zeitstempel im Vordergrund — für Belege</p>
                                        </div>
                                        <div class="template-select-card" data-preset="sprecher_gruppiert" onclick="window.app.exportManager.selectTranscriptPreset('sprecher_gruppiert')">
                                            <div class="template-card-header">
                                                <div class="template-header-left-inner">
                                                    <div class="template-icon-box">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-users"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                                    </div>
                                                    <h4 class="template-title">Nach Sprecher gruppiert</h4>
                                                </div>
                                                <span class="badge-active-pill hidden">AKTIV</span>
                                            </div>
                                            <p class="template-desc">Aussagen je Person gebündelt</p>
                                        </div>
                                        <div class="template-select-card" data-preset="fliesstext" onclick="window.app.exportManager.selectTranscriptPreset('fliesstext')">
                                            <div class="template-card-header">
                                                <div class="template-header-left-inner">
                                                    <div class="template-icon-box">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-file-text"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                                                    </div>
                                                    <h4 class="template-title">Nur Fließtext</h4>
                                                </div>
                                                <span class="badge-active-pill hidden">AKTIV</span>
                                            </div>
                                            <p class="template-desc">Ohne Namen & Zeitstempel</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Category 3: ANPASSEN -->
                                <div>
                                    <div class="template-section-header" style="margin-top: 0; margin-bottom: 12px;">Anpassen</div>
                                    
                                    <div style="display: flex; flex-direction: column; gap: 16px; background: var(--panel-secondary, #ffffff); border: var(--border-stroke-thin); border-radius: 8px; padding: 16px; box-sizing: border-box;">
                                        <!-- Sprechernamen Switch -->
                                        <div class="transcript-toggle-row">
                                            <span>Sprechernamen</span>
                                            <label class="transcript-switch">
                                                <input type="checkbox" id="ts-toggle-speakers" checked onchange="window.app.exportManager.updateTranscriptCustomFormat()">
                                                <span class="transcript-slider"></span>
                                            </label>
                                        </div>
                                        <!-- Zeitstempel Switch -->
                                        <div class="transcript-toggle-row">
                                            <span>Zeitstempel</span>
                                            <label class="transcript-switch">
                                                <input type="checkbox" id="ts-toggle-timestamps" checked onchange="window.app.exportManager.updateTranscriptCustomFormat()">
                                                <span class="transcript-slider"></span>
                                            </label>
                                        </div>
                                        <!-- Anonymize Switch -->
                                        <div class="transcript-toggle-row">
                                            <span>Sprecher anonymisieren</span>
                                            <label class="transcript-switch">
                                                <input type="checkbox" id="ts-toggle-anonymize" onchange="window.app.exportManager.updateTranscriptCustomFormat()">
                                                <span class="transcript-slider"></span>
                                            </label>
                                        </div>
                                        <!-- Avatare Switch -->
                                        <div class="transcript-toggle-row">
                                            <span>Avatare</span>
                                            <label class="transcript-switch">
                                                <input type="checkbox" id="ts-toggle-avatars" checked onchange="window.app.exportManager.updateTranscriptCustomFormat()">
                                                <span class="transcript-slider"></span>
                                            </label>
                                        </div>
                                        <!-- Sprechblasen Switch -->
                                        <div class="transcript-toggle-row">
                                            <span>Sprechblasen</span>
                                            <label class="transcript-switch">
                                                <input type="checkbox" id="ts-toggle-bubbles" checked onchange="window.app.exportManager.updateTranscriptCustomFormat()">
                                                <span class="transcript-slider"></span>
                                            </label>
                                        </div>
                                        
                                        <!-- Reihenfolge -->
                                        <div class="transcript-toggle-row-vertical">
                                            <span class="transcript-row-label">Reihenfolge</span>
                                            <div class="transcript-segmented-control">
                                                <button id="ts-order-chronological" class="active" onclick="window.app.exportManager.setTranscriptOrder('chronological')">Chronologisch</button>
                                                <button id="ts-order-speaker" onclick="window.app.exportManager.setTranscriptOrder('speaker')">Nach Sprecher</button>
                                            </div>
                                        </div>
                                        
                                        <!-- Sprecher anzeigen -->
                                        <div class="transcript-toggle-row-vertical">
                                            <span class="transcript-row-label">Sprecher anzeigen</span>
                                            <div id="transcript-speakers-chips" class="transcript-speakers-chips-container">
                                                <!-- Chips dynamically rendered -->
                                            </div>
                                        </div>
                                        
                                        <!-- Speichern Button -->
                                        <div class="transcript-toggle-row-vertical" style="margin-top: 8px;">
                                            <span class="transcript-row-label">Als eigene Vorlage speichern</span>
                                            <input type="text" id="ts-template-name-input" placeholder="Vorlagenname eingeben..." style="width: 100%; padding: 8px 12px; font-size: 13px; border-radius: 6px; border: var(--border-stroke-thin, 1px solid #cbd5e1); background: var(--panel-secondary, #ffffff); color: var(--text-color); box-sizing: border-box; outline: none; margin-top: 4px;">
                                            <button class="btn-save-custom-template" onclick="window.app.exportManager.saveCustomTranscriptTemplate()">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                                                Vorlage speichern
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Column: Live Preview -->
                            <div class="template-editor-col-right">
                                <div class="template-section-header" style="margin-top: 0; margin-bottom: 12px;">So sieht das Ergebnis aus</div>
                                <div class="preview-bubble-card" style="border-radius: 12px;">
                                    <!-- Main Output Area (Scrollable) -->
                                    <div id="transcript-settings-preview-content" class="preview-render-area export-preview-text">
                                        <!-- Preview rendered here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
