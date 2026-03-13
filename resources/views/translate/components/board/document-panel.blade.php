            <div id="documentBoard" class="translate-board-3col" style="display: none;">
                <div class="board-panel-group">

                    <!-- Document Language Header (one-way: auto-detect → target) -->
                    <div class="group-header doc-lang-header inactive" id="docLangHeader">
                        <div class="doc-source-wrapper">
                            <span class="doc-source-label">{{ $translation["AutoDetect"] ?? "Automatisch erkennen" }}</span>
                        </div>
                        <x-icon name="arrow-right" class="doc-arrow-icon" width="16" height="16"/>
                        <div class="language-selector-wrapper">
                            <select id="docTargetLang" class="styleless-select" disabled>
                                <option value="en" {{ ($defaultTarget ?? 'en') === 'en' ? 'selected' : '' }}>English</option>
                                <option value="de" {{ ($defaultTarget ?? 'en') === 'de' ? 'selected' : '' }}>Deutsch</option>
                                <option value="fr" {{ ($defaultTarget ?? 'en') === 'fr' ? 'selected' : '' }}>Français</option>
                                <option value="es" {{ ($defaultTarget ?? 'en') === 'es' ? 'selected' : '' }}>Español</option>
                                <option value="it" {{ ($defaultTarget ?? 'en') === 'it' ? 'selected' : '' }}>Italiano</option>
                                <option value="nl" {{ ($defaultTarget ?? 'en') === 'nl' ? 'selected' : '' }}>Nederlands</option>
                                <option value="pl" {{ ($defaultTarget ?? 'en') === 'pl' ? 'selected' : '' }}>Polski</option>
                                <option value="pt" {{ ($defaultTarget ?? 'en') === 'pt' ? 'selected' : '' }}>Português</option>
                                <option value="ru" {{ ($defaultTarget ?? 'en') === 'ru' ? 'selected' : '' }}>Русский</option>
                                <option value="zh" {{ ($defaultTarget ?? 'en') === 'zh' ? 'selected' : '' }}>中文</option>
                                <option value="ja" {{ ($defaultTarget ?? 'en') === 'ja' ? 'selected' : '' }}>日本語</option>
                            </select>
                        </div>
                    </div>

                    <div class="board-panel">
                        <div class="panel-content">

                <!-- Hidden file input -->
                <input type="file" id="doc-file-input" multiple accept=".pdf,.doc,.docx,.pptx,.ppt,.jpg,.jpeg,.png" style="display: none;" />

                <!-- State 1: Upload / Drop Zone -->
                <div id="doc-upload-step" class="doc-step active">
                    <div class="doc-drop-zone" id="doc-drop-zone">
                        <x-icon name="file-text" width="48" height="48"/>
                        <h2>{{ $translation["DragDocumentsHere"] ?? "Drag your documents here, or" }}</h2>
                        <button class="btn-primary" type="button" id="select-files-btn">{{ $translation["SelectFromComputer"] ?? "Select from your computer" }}</button>
                        <div class="doc-support-info">
                            <p>{{ $translation["DocSupportInfo"] ?? "We support .doc(x), .pdf, and .pptx." }}</p>
                            <p>{{ $translation["ImgSupportInfo"] ?? "We also support .jp(e)g and .png in beta." }}</p>
                        </div>
                    </div>
                </div>

                <!-- State 2: File List / Processing -->
                <div id="doc-processing-step" class="doc-step" style="display: none; padding: 40px;">
                    <div class="doc-list" id="doc-file-list">
                        {{-- File items will be dynamically inserted here by JS --}}
                    </div>
                    <div class="doc-footer">
                        <div class="footer-stats" id="doc-footer-stats"></div>
                        <div class="doc-footer-actions">
                            <button class="btn-secondary" type="button" id="cancel-doc-btn">{{ $translation["Cancel"] ?? "Cancel" }}</button>
                            <button class="btn-primary" type="button" id="translate-docs-btn">{{ $translation["TranslateDocuments"] ?? "Translate" }}</button>
                        </div>
                    </div>
                </div>

                <!-- State 3: Completed / Download -->
                <div id="doc-completed-step" class="doc-step" style="display: none; padding: 40px;">
                    <div class="doc-list" id="doc-completed-list">
                        {{-- Completed items will be dynamically inserted here by JS --}}
                    </div>
                    <div class="doc-footer">
                        <div class="footer-stats" id="doc-completed-stats"></div>
                        <button class="btn-primary" type="button" id="upload-more-btn">{{ $translation["UploadMoreDocuments"] ?? "Upload more documents" }}</button>
                    </div>
                </div>

                        </div>
                    </div>
                </div>
            {{-- Persistent list of translated documents --}}
            <div id="translatedDocsHistory" class="translated-docs-history" style="display: none;">
                <div class="translated-docs-header" id="translatedDocsToggle">
                    <span class="translated-docs-title">{{ $translation["TranslatedDocuments"] ?? "Translated documents" }}</span>
                    <span class="translated-docs-count" id="translatedDocsCount">0</span>
                    <x-icon name="chevron-down" width="16" height="16" class="toggle-icon"/>
                </div>
                <div class="translated-docs-list" id="translatedDocsList">
                    {{-- Items rendered by JS --}}
                </div>
            </div>
        </div>
