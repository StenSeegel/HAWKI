


<div class="input-container admin-only editor-only" id="input-container">
    <div class="input-stats-container">
        <div class="isTypingStatus"></div>
        <div id="input-feedback-msg"></div>
    </div>

    <div class="input-controls" id="input-controls">
        @if(!$lite)
        <button class="btn-xs expand-btn" onclick="toggleRelativePanelClass('input-controls', this,'expanded')">
            <div class="icon">
                <x-icon name="chevron-up"/>
            </div>
        </button>
        @endif

        <div class="minimized-content">
            <div class="left">

                @if($activeModule === 'chat')
                    <button class="btn-xs fast-access-btn" onclick="startNewChat()">
                        <x-icon name="new"/>
                        <div class="tooltip">
                            {{ $translation["StartNewChat"] }}
                        </div>
                    </button>
                @endif

                @if(!$lite && $activeModule === 'chat')
                    <button class="btn-xs fast-access-btn" value="system_prompt_panel" onclick="toggleRelativePanelClass('input-controls', this,'expanded'); switchControllerProp(this, 'system_prompt_panel')">
                        <x-icon name="sliders"/>
                        <div class="tooltip">
                            {{ $translation["SystemPrompt"] }}
                        </div>
                    </button>

                @endif

                @if(!$lite)
                    <button class="btn-xs fast-access-btn" value="export-panel" onclick="toggleRelativePanelClass('input-controls', this,'expanded'); switchControllerProp(this, 'export-panel')">
                        <x-icon name="download"/>
                        <div class="tooltip">
                            {{ $translation["Export"] }}
                        </div>
                    </button>
                @endif

                @if($webSearchAvailable)
                <button id="websearch-btn" class="btn-xs fast-access-btn" onclick="selectWebSearchModel(this)" @if(!config('hawki.websearch')) style="display:none;" @endif>
                    <x-icon class="websearch-icon" name="world"/>
                    <div class="tooltip">
                        {{ $translation["WebSearch"] }}
                    </div>
                </button>
                @endif

                @if($reasoningAvailable)
                <div class="reasoning-controls">
                    <button id="reasoning-btn" class="btn-xs fast-access-btn" data-effort="medium" onclick="toggleReasoningDropdown(this)">
                        <x-icon class="reasoning-icon" name="cpu"/>
                        <div class="reasoning-effort-indicator">
                            <span class="effort-dot" data-level="1"></span>
                            <span class="effort-dot active" data-level="2"></span>
                            <span class="effort-dot active" data-level="3"></span>
                        </div>
                        <div class="tooltip">
                            {{ $translation["Reasoning"] ?? "Reasoning" }}
                        </div>
                    </button>
                    <div class="reasoning-dropdown burger-dropdown anchor-top-right" id="reasoning-dropdown" style="display: none;">
                        <div class="reasoning-options">
                            <button class="burger-item reasoning-option" data-effort="low" onclick="selectReasoningEffort(this, 'low')">
                                <div class="label">Low</div>
                            </button>
                            <button class="burger-item reasoning-option selected" data-effort="medium" onclick="selectReasoningEffort(this, 'medium')">
                                <div class="label">Medium</div>
                            </button>
                            <button class="burger-item reasoning-option" data-effort="high" onclick="selectReasoningEffort(this, 'high')">
                                <div class="label">High</div>
                            </button>
                        </div>
                    </div>
                </div>
                @endif

                @if($imageGenerationAvailable && Auth::user()->hasAccess('image_generation.access'))
                    <div class="image-generation-controls" style="position: relative; display: inline-block;">
                        <button id="image-generation-btn" class="btn-xs fast-access-btn" data-size="" onclick="toggleImageGenerationDropdown(this)" @if(!config('hawki.image_generation')) style="display:none;" @endif>
                            <x-icon class="image-generation-icon" name="image"/>
                            <div class="image-size-indicator" style="display:none;"></div>
                            <div class="tooltip">
                                {{ $translation["ImageGeneration"] }}
                            </div>
                        </button>
                        <div class="image-size-dropdown burger-dropdown anchor-top-right" id="image-size-dropdown" style="display: none;">
                            <div class="image-size-options">
                                <button class="burger-item image-size-option" data-size="small" onclick="selectImageGenerationSize(this, 'small')">
                                    <div class="label">Small (512x512)</div>
                                </button>
                                <button class="burger-item image-size-option" data-size="medium" onclick="selectImageGenerationSize(this, 'medium')">
                                    <div class="label">Medium (1024x1024)</div>
                                </button>
                                <button class="burger-item image-size-option" data-size="big" onclick="selectImageGenerationSize(this, 'big')">
                                    <div class="label">Big (1536x1024)</div>
                                </button>
                            </div>
                        </div>
                    </div>
                @endif


                <button class="btn-xs fast-access-btn file-upload file-upload-btn" onclick="selectFile(this)" @if(!config('hawki.file_upload')) style="display:none;" @endif>
                    <x-icon name="paperclip"/>
                    <div class="tooltip">
                        {{ $translation["UploadFile"] }}
                    </div>
                </button>


            </div>

            <div class="right">
                <div id="model-selectors">

                    <div class="burger-dropdown anchor-top-right" id="model-selector-burger">
                        @include('partials.home.components.models-list')
                    </div>

                    <div class="burger-btn-arrow burger-btn" onclick="openBurgerMenu('model-selector-burger', this, false, true, true)">
                        <div class="icon">
                            <x-icon name="chevron-up"/>
                        </div>
                        <div class="label model-selector-label"></div>
                    </div>

                </div>
            </div>
        </div>

        @if(!$lite)
        <div class="expanded-content">

            <div class="expanded-left">
                <div class="controls-container scroll-container">

                    <div class="control-buttons scroll-panel">
                        @if($activeModule === 'chat')

                        <button class="btn-xs menu-item" value="" onclick="switchControllerProp(this); startNewChat(); toggleRelativePanelClass('input-controls', this,'expanded');">
                            <x-icon name="new"/>
                            <div class="label">{{ $translation["StartNewChat"] }}</div>
                        </button>
                        @endif

                        <button class="btn-xs menu-item" value="models_panel" onclick="switchControllerProp(this, 'models_panel')">
                            <x-icon name="layers"/>
                            <div class="label">{{ $translation["Models"] }}</div>
                        </button>

                        @if($activeModule === 'chat')
                        <button class="btn-xs menu-item" value="system_prompt_panel" onclick="switchControllerProp(this, 'system_prompt_panel')">
                            <x-icon name="sliders"/>
                            <div class="label">{{ $translation["SystemPrompt"] }}</div>
                        </button>
                        @endif

                        <button class="btn-xs menu-item" value="export-panel" onclick="switchControllerProp(this, 'export-panel')">
                            <x-icon name="download"/>
                            <div class="label">{{ $translation["Export"] }}</div>
                        </button>

                        </button>
                    </div>

                </div>
            </div>
            <div class="expanded-right">
                <div class="controls-props scroll-container">

                    <div class="scroll-panel" id="input-controls-props-panel">

                        <div id="system_prompt_panel" class="prop-content">
                            <div contenteditable class="system_prompt_field" id="system_prompt_field"></div>
                        </div>

                        <div id="models_panel" class="prop-content">
                            @include('partials.home.components.models-list')
                        </div>

                        <div id="export-panel" class="prop-content">

                            <button class="burger-item" id="export-btn-print" onclick="exportPrintPage()">
                                <div class="icon"></div>
                                <div class="label">{{ $translation["Print"] }}</div>
                            </button>

                            <button class="burger-item" id="export-btn-pdf" onclick="exportAsPDF()">
                                <div class="loading loading-sm">
                                    <x-icon name="loading"/>
                                </div>
                                <div class="icon"></div>
                                <div class="label">PDF {{ $translation["Download"] }}</div>
                            </button>

                            <button class="burger-item" id="export-btn-word" onclick="exportAsWord()">
                                <div class="loading loading-sm">
                                    <x-icon name="loading"/>
                                </div>
                                <div class="icon"></div>
                                <div class="label">Word {{ $translation["Download"] }}</div>
                            </button>

                            <button class="burger-item" id="export-btn-csv" onclick="exportAsCsv()">
                                <div class="loading loading-sm">
                                    <x-icon name="loading"/>
                                </div>
                                <div class="icon"></div>
                                <div class="label">CSV {{ $translation["Download"] }}</div>
                            </button>

                            <button class="burger-item" id="export-btn-json" onclick="exportAsJson()">
                                <div class="icon"></div>
                                <div class="label">JSON {{ $translation["Download"] }}</div>
                            </button>
                        </div>

                    </div>

                </div>

            </div>
        </div>
        @endif

    </div>
    <div class="input" id="0">
        <input type="file" class="file-upload-input" id="file-upload-input" style="display:none;"/>
        <div class="file-attachments">
            <div class="attachments-list">
            </div>
        </div>



        <div class="input-content">

            <div class="input-wrapper">
                <textarea
                    class="input-field"
                    type="text"

                    @if($activeModule === 'chat')

                        placeholder="{{ $translation['Input_Placeholder_Chat'] }}"
                        oninput="resizeInputField(this);"
                        onkeypress="onHandleKeydownConv(event)"

                    @elseif($activeModule === 'groupchat')

                        placeholder="{{ $translation['Input_Placeholder_Room'] ." ". config('hawki.aiHandle')}}"
                        oninput="resizeInputField(this); onGroupchatType()"
                        onkeypress="onHandleKeydownRoom(event)"

                    @endif

                    onfocus="onInputFieldFocus(this); toggleOffRelativeInputControl(this)"
                    onfocusout="onInputFieldFocusOut(this)"></textarea>
                <div class="realtime-typing-indicator" id="realtime-typing-indicator">
                    <span class="rti-dot"></span>
                    <span class="rti-dot"></span>
                    <span class="rti-dot"></span>
                </div>
            </div>

            {{-- <div class="input-main-btn file-upload tooltip-parent">
                <input type="file" id="file-upload-input" style="display:none;" />
                <div class="file-upload-btn" onclick="selectFile()">
                    <x-icon name="paperclip"/>
                    <div class="label tooltip tt-abs-up">
                        upload file
                    </div>
                </div>
            </div> --}}

            <div class="input-actions-group">
                <div class="input-main-btn input-send tooltip-parent">
                    @if($activeModule === 'chat')
                        <div id="send-btn" onClick="onSendClickConv(this)">
                    @elseif($activeModule === 'groupchat')
                        <div id="send-btn" onClick="onSendClickRoom(this)">
                    @endif
                            <div id="send-icon" class="send-btn-icon" >
                                <x-icon name="arrow-up"/>
                            </div>
                            <div id="stop-icon" class="send-btn-icon" style="display:none">
                                <x-icon name="stop"/>
                            </div>
                            <div id="loading-icon" class="send-btn-icon loading loading-lg" style="display:none">
                                <div class="loading">
                                    <x-icon name="loading"/>
                                </div>
                            </div>
                    </div>
                    <div class="label tooltip tt-abs-up">
                        {{ $translation["Send"] }}
                    </div>
                </div>

                <div class="realtime-transcription-outer tooltip-parent" id="realtime-transcription-outer">
                    <div class="label tooltip tt-abs-up">Spracheingabe</div>
                    <div class="realtime-transcription-group" id="realtime-transcription-group">
                        <div class="realtime-transcription-btn" id="realtime-mic-btn" onclick="toggleRealtimeTranscription(this)">
                            <span class="rt-icon-mic"><x-icon name="microphone"/></span>
                            <span class="rt-icon-connecting loading">
                                <x-icon name="loading"/>
                            </span>
                        </div>
                        <div class="realtime-btn-separator"></div>
                        <button class="realtime-device-toggle btn-xs tooltip-parent" onclick="event.stopPropagation(); window.toggleRealtimeDeviceDropdown()">
                            <x-icon name="chevron-up"/>
                            <div class="label tooltip tt-abs-up">Mikrofon auswählen</div>
                        </button>
                    </div>
                    <div class="realtime-device-dropdown" id="realtime-device-dropdown" style="display:none;">
                        <select id="live-input-device-select" class="realtime-device-select">
                            <option value="">Standardmikrofon</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="prompt-improvement-btn tooltip-parent" onclick="requestPromptImprovement(this, 'input')">
                <div class="input-main-btn">
                    <x-icon name="vector"/>
                    <div class="label tooltip tt-abs-up">
                        {{ $translation["PromptImprovement"] }}
                    </div>
                </div>
            </div>

        </div>


    </div>

    @include('partials.home.dragDropOverlay')

    @if(!$lite)
        @include('partials.home.components.model-info-card')
    @endif

</div>
