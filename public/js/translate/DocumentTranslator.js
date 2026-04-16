/**
 * Document Translator for HAWKI Translation.
 * Handles file upload, polling, and download logic using the DeepL document API.
 */
import { DOM_IDS, getTranslations } from './Constants.js';
import { formatFileSize, escapeHtml, getFileExtension } from './Utils.js';

export class DocumentTranslator {
    constructor(app) {
        this.app = app;
        this.t = getTranslations();
        this.selectedDocFiles = [];
        this.translatedDocResults = [];
        this.elements = {};
        this.initElements();
        this.attachListeners();
        this.initTranslatedDocsEvents();
    }

    initElements() {
        const ids = [
            'selectFilesBtn', 'docFileInput', 'docDropZone', 'cancelDocBtn', 
            'uploadMoreBtn', 'translateDocsBtn', 'docUploadStep', 'docProcessingStep', 
            'docCompletedStep', 'docFileList', 'docFooterStats', 'docLangHeader', 
            'docTargetLang', 'docSourceLang', 'docCompletedList', 'docCompletedStats',
            'translatedDocsToggle', 'translatedDocsHistory', 'translatedDocsList', 
            'translatedDocsCount'
        ];

        const camelToKebab = (value) => value.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();
        ids.forEach(id => {
            const key = id.replace(/[A-Z]/g, letter => `_${letter}`).toUpperCase();
            const mappedId = DOM_IDS[key];
            const kebabId = camelToKebab(id);
            this.elements[id] =
                (mappedId ? document.getElementById(mappedId) : null) ||
                document.getElementById(id) ||
                document.getElementById(kebabId);
        });
    }

    attachListeners() {
        const { elements } = this;

        if (elements.selectFilesBtn && elements.docFileInput) {
            elements.selectFilesBtn.addEventListener('click', (e) => {
                e.preventDefault();
                elements.docFileInput.click();
            });
        }

        if (elements.docFileInput) {
            elements.docFileInput.addEventListener('change', (e) => {
                const files = Array.from(e.target.files);
                if (files.length > 0) {
                    this.addDocFiles(files);
                    this.showDocFileList();
                }
                elements.docFileInput.value = '';
            });
        }

        if (elements.docDropZone) {
            elements.docDropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                e.stopPropagation();
                elements.docDropZone.classList.add('drag-over');
            });

            elements.docDropZone.addEventListener('dragleave', (e) => {
                e.preventDefault();
                e.stopPropagation();
                elements.docDropZone.classList.remove('drag-over');
            });

            elements.docDropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                e.stopPropagation();
                elements.docDropZone.classList.remove('drag-over');
                const files = Array.from(e.dataTransfer.files);
                if (files.length > 0) {
                    this.addDocFiles(files);
                    this.showDocFileList();
                }
            });
        }

        if (elements.cancelDocBtn) {
            elements.cancelDocBtn.addEventListener('click', () => {
                this.selectedDocFiles = [];
                if (elements.docProcessingStep) elements.docProcessingStep.style.display = 'none';
                if (elements.docUploadStep) elements.docUploadStep.style.display = 'block';
                this.updateDocLangHeader(false);
            });
        }

        if (elements.uploadMoreBtn) {
            elements.uploadMoreBtn.addEventListener('click', () => {
                this.selectedDocFiles = [];
                if (elements.docCompletedStep) elements.docCompletedStep.style.display = 'none';
                if (elements.docUploadStep) elements.docUploadStep.style.display = 'block';
                this.updateDocLangHeader(false);
            });
        }

        if (elements.translateDocsBtn) {
            elements.translateDocsBtn.addEventListener('click', () => {
                this.translateDocuments();
            });
        }
    }

    addDocFiles(files) {
        const allowedExtensions = ['pdf', 'doc', 'docx', 'pptx', 'ppt', 'xlsx', 'xls', 'txt', 'html', 'htm', 'xlf', 'xliff', 'srt', 'jpg', 'jpeg', 'png'];
        files.forEach(file => {
            const ext = getFileExtension(file.name);
            if (allowedExtensions.includes(ext)) {
                const exists = this.selectedDocFiles.some(f => f.name === file.name && f.size === file.size);
                if (!exists) {
                    this.selectedDocFiles.push(file);
                }
            }
        });
    }

    showDocFileList() {
        const { elements } = this;
        if (elements.docUploadStep) elements.docUploadStep.style.display = 'none';
        if (elements.docProcessingStep) elements.docProcessingStep.style.display = 'block';
        this.renderDocFileList();
        this.updateDocLangHeader(true);
    }

    renderDocFileList() {
        const { elements } = this;
        if (!elements.docFileList) return;

        elements.docFileList.innerHTML = '';

        this.selectedDocFiles.forEach((file, index) => {
            const ext = getFileExtension(file.name);
            const item = document.createElement('div');
            item.className = 'doc-item';
            item.innerHTML = `
                <div class="doc-item-info">
                    <div class="doc-item-icon">${ext}</div>
                    <div class="doc-details">
                        <div class="doc-name">${escapeHtml(file.name)}</div>
                        <div class="doc-file-size">${formatFileSize(file.size)}</div>
                    </div>
                </div>
                <div class="doc-item-actions">
                    <button class="doc-remove-btn" data-index="${index}" title="${this.t['Remove'] || 'Remove'}">&times;</button>
                </div>
            `;
            elements.docFileList.appendChild(item);
        });

        elements.docFileList.querySelectorAll('.doc-remove-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const idx = parseInt(e.currentTarget.dataset.index, 10);
                this.removeDocFile(idx);
            });
        });

        if (elements.docFooterStats) {
            const count = this.selectedDocFiles.length;
            const label = count === 1 ? (this.t['FileSelected'] || 'file selected') : (this.t['FilesSelected'] || 'files selected');
            elements.docFooterStats.textContent = `${count} ${label}`;
        }
    }

    removeDocFile(index) {
        this.selectedDocFiles.splice(index, 1);
        if (this.selectedDocFiles.length === 0) {
            const { elements } = this;
            if (elements.docProcessingStep) elements.docProcessingStep.style.display = 'none';
            if (elements.docUploadStep) elements.docUploadStep.style.display = 'block';
            this.updateDocLangHeader(false);
        } else {
            this.renderDocFileList();
        }
    }

    updateDocLangHeader(isActive) {
        const { elements } = this;
        if (elements.docLangHeader) {
            elements.docLangHeader.classList.toggle('inactive', !isActive);
            elements.docLangHeader.classList.add(isActive ? 'active' : 'inactive');
        }
        if (elements.docTargetLang) {
            elements.docTargetLang.disabled = !isActive;
        }
    }

    async translateDocuments() {
        if (this.selectedDocFiles.length === 0) return;

        const { elements } = this;
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const targetLang = elements.docTargetLang?.value || 'en';

        if (elements.translateDocsBtn) {
            elements.translateDocsBtn.disabled = true;
            elements.translateDocsBtn.classList.add('btn-loading');
        }
        if (elements.cancelDocBtn) elements.cancelDocBtn.disabled = true;

        if (elements.docFileList) {
            elements.docFileList.innerHTML = '';
            this.selectedDocFiles.forEach((file, index) => {
                const ext = getFileExtension(file.name);
                const item = document.createElement('div');
                item.className = 'doc-item processing';
                item.innerHTML = `
                    <div class="doc-item-info">
                        <div class="doc-item-icon">${ext}</div>
                        <div class="doc-details">
                            <div class="doc-name">${escapeHtml(file.name)}</div>
                            <div class="doc-file-size">${formatFileSize(file.size)}</div>
                        </div>
                    </div>
                    <div class="doc-status">
                        <span class="status-text" id="doc-status-text-${index}">${this.t['Translating'] || 'Translating...'}</span>
                        <div class="progress-bar"><div class="progress-fill" id="doc-progress-${index}" style="width: 0%;"></div></div>
                    </div>
                `;
                elements.docFileList.appendChild(item);
            });
        }

        this.translatedDocResults = [];
        let completedCount = 0;

        for (let index = 0; index < this.selectedDocFiles.length; index++) {
            const file = this.selectedDocFiles[index];
            const progressBar = document.getElementById(`doc-progress-${index}`);
            const statusText = document.getElementById(`doc-status-text-${index}`);

            try {
                if (progressBar) progressBar.style.width = '10%';
                if (statusText) statusText.textContent = 'Uploading...';

                const formData = new FormData();
                formData.append('file', file);
                formData.append('target_lang', targetLang);

                const sourceLang = elements.docSourceLang?.value;
                if (sourceLang && sourceLang !== 'auto') {
                    formData.append('source_lang', sourceLang);
                }

                if (this.app.selectedFormality && this.app.selectedFormality !== 'default') {
                    formData.append('formality', this.app.selectedFormality);
                }

                const activeGlossaryCheckboxes = document.querySelectorAll('#sidebarGlossaryList input[type="checkbox"]:checked');
                activeGlossaryCheckboxes.forEach(cb => {
                    formData.append('glossary_id[]', cb.value);
                });

                const uploadResponse = await fetch('/req/text/translate-document', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                    body: formData
                });

                const uploadData = await uploadResponse.json();
                if (!uploadResponse.ok || !uploadData.success) {
                    throw new Error(uploadData.error_code === 'same_language' ? (this.t['Err_DocSameLanguage'] || 'Same language error') : (uploadData.error || uploadData.message || 'Upload failed'));
                }

                const pollResult = await this.pollDocumentStatus(uploadData.data.job_id, index, csrfToken);

                this.translatedDocResults.push({
                    originalName: file.name,
                    downloadId: pollResult.download_id,
                    outputName: uploadData.data.original_name,
                    outputExtension: uploadData.data.output_extension,
                    targetLang: uploadData.data.target_lang,
                    success: true
                });

                completedCount++;
            } catch (error) {
                console.error(`Translation failed for ${file.name}:`, error);
                if (progressBar) { progressBar.style.width = '100%'; progressBar.style.backgroundColor = '#ef4444'; }
                if (statusText) { statusText.textContent = this.t['Error'] || '✗ Error'; statusText.style.color = '#ef4444'; }
                this.translatedDocResults.push({ originalName: file.name, success: false, error: error.message });
            }

            if (elements.docFooterStats) {
                elements.docFooterStats.textContent = `${completedCount} / ${this.selectedDocFiles.length} ${this.t['XOfYTranslated'] || 'translated'}`;
            }
        }

        this.showDocCompleted();
        this.loadTranslatedDocsList(); // Refresh history
    }

    async pollDocumentStatus(jobId, index, csrfToken) {
        const progressBar = document.getElementById(`doc-progress-${index}`);
        const statusText = document.getElementById(`doc-status-text-${index}`);
        const maxPollTime = 5 * 60 * 1000;
        const startTime = Date.now();
        let pollInterval = 3000;

        while (true) {
            if (Date.now() - startTime > maxPollTime) throw new Error('Translation timed out.');
            await new Promise(resolve => setTimeout(resolve, pollInterval));

            const statusResponse = await fetch(`/req/text/document-status/${jobId}`, {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
            });

            const statusData = await statusResponse.json();
            if (!statusResponse.ok || !statusData.success) {
                throw new Error(statusData.error_code === 'same_language' ? (this.t['Err_DocSameLanguage'] || 'Same language error') : (statusData.error || 'Status check failed'));
            }

            const data = statusData.data;
            if (data.status === 'queued') {
                if (progressBar) progressBar.style.width = '15%';
                if (statusText) statusText.textContent = this.t['Translating'] || 'Queued...';
            } else if (data.status === 'translating') {
                let progress = data.seconds_remaining !== null && data.seconds_remaining > 0 ? Math.min(85, Math.max(30, 90 - data.seconds_remaining * 2)) : 50;
                if (statusText) statusText.textContent = `${this.t['Translating'] || 'Translating...'} ${data.seconds_remaining ? `(~${data.seconds_remaining}s)` : ''}`;
                if (progressBar) progressBar.style.width = `${progress}%`;
            } else if (data.status === 'done') {
                if (progressBar) progressBar.style.width = '100%';
                if (statusText) { statusText.textContent = this.t['Done'] || '✓ Done'; statusText.style.color = '#10b981'; }
                return data;
            } else if (data.status === 'error') {
                throw new Error(data.error_message || 'DeepL error.');
            }
            pollInterval = Math.min(pollInterval + 500, 5000);
        }
    }

    showDocCompleted() {
        const { elements } = this;
        if (elements.translateDocsBtn) { elements.translateDocsBtn.disabled = false; elements.translateDocsBtn.classList.remove('btn-loading'); }
        if (elements.cancelDocBtn) elements.cancelDocBtn.disabled = false;

        if (elements.docProcessingStep) elements.docProcessingStep.style.display = 'none';
        if (elements.docCompletedStep) elements.docCompletedStep.style.display = 'block';

        if (elements.docCompletedList) {
            elements.docCompletedList.innerHTML = '';
            this.translatedDocResults.forEach((result) => {
                const ext = getFileExtension(result.originalName);
                const item = document.createElement('div');
                item.className = result.success ? 'doc-item completed' : 'doc-item completed error';

                if (result.success) {
                    const langSuffix = result.targetLang || 'translated';
                    const downloadFilename = `${result.outputName}_${langSuffix}.${result.outputExtension}`;
                    const downloadUrl = `/req/text/download-document/${result.downloadId}?name=${encodeURIComponent(result.outputName)}&lang=${encodeURIComponent(langSuffix)}`;
                    item.innerHTML = `
                        <div class="doc-item-info">
                            <div class="doc-item-icon">${ext}</div>
                            <div class="doc-details">
                                <div class="doc-name">${escapeHtml(downloadFilename)}</div>
                            </div>
                        </div>
                        <div class="doc-item-actions">
                            <a href="${downloadUrl}" download="${escapeHtml(downloadFilename)}" class="doc-download-link">${this.t['Download'] || 'Download'}</a>
                        </div>
                    `;
                } else {
                    item.innerHTML = `
                        <div class="doc-item-info">
                            <div class="doc-item-icon error">${ext}</div>
                            <div class="doc-details"><div class="doc-name">${escapeHtml(result.originalName)}</div><div class="doc-error">${result.error}</div></div>
                        </div>
                    `;
                }
                elements.docCompletedList.appendChild(item);
            });
        }
    }

    initTranslatedDocsEvents() {
        const { elements } = this;
        if (elements.translatedDocsToggle && elements.translatedDocsHistory) {
            elements.translatedDocsToggle.addEventListener('click', () => {
                elements.translatedDocsHistory.classList.toggle('collapsed');
            });
        }
        this.loadTranslatedDocsList();
    }

    async loadTranslatedDocsList() {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const response = await fetch('/req/text/translated-documents', {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken }
            });
            if (!response.ok) return;
            const data = await response.json();
            if (data.success) this.renderTranslatedDocsList(data.data);
        } catch (error) {
            console.error('Failed to load translated documents list:', error);
        }
    }

    renderTranslatedDocsList(docs) {
        const { elements } = this;
        if (!elements.translatedDocsHistory || !elements.translatedDocsList) return;

        if (!docs || docs.length === 0) {
            elements.translatedDocsHistory.style.display = 'none';
            return;
        }

        elements.translatedDocsHistory.style.display = 'block';
        if (elements.translatedDocsCount) elements.translatedDocsCount.textContent = docs.length;

        elements.translatedDocsList.innerHTML = '';
        docs.forEach(doc => {
            const langSuffix = doc.target_lang || 'translated';
            const downloadFilename = `${doc.original_name}_${langSuffix}.${doc.output_extension}`;
            const queryParams = `?name=${encodeURIComponent(doc.original_name)}&lang=${encodeURIComponent(langSuffix)}`;
            const downloadUrl = `/req/text/download-document/${doc.download_id}${queryParams}`;
            const ext = doc.output_extension.toUpperCase();

            const item = document.createElement('div');
            item.className = 'translated-doc-item';
            item.innerHTML = `
                <div class="doc-item-info">
                    <div class="doc-item-icon">${ext}</div>
                    <div class="doc-details">
                        <div class="doc-name">${escapeHtml(downloadFilename)}</div>
                        <div class="doc-file-size">${doc.file_size ? formatFileSize(doc.file_size) : ''}</div>
                    </div>
                </div>
                <div class="doc-item-actions" style="display: flex; align-items: center; gap: 8px;">
                    <a href="${downloadUrl}" download="${escapeHtml(downloadFilename)}" class="download-doc-btn" title="${this.t['DownloadFile'] || 'Herunterladen'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    </a>
                    <button class="delete-doc-btn" title="${this.t['Delete'] || 'Löschen'}">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                    </button>
                </div>
            `;

            item.querySelector('.delete-doc-btn').addEventListener('click', async () => {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                try {
                    await fetch(`/req/text/delete-document/${doc.download_id}`, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
                    });
                } catch (e) {
                    console.error('Failed to delete document:', e);
                }
                item.remove();
                this.updateTranslatedDocsCount();
            });

            elements.translatedDocsList.appendChild(item);
        });
    }

    updateTranslatedDocsCount() {
        const { elements } = this;
        if (!elements.translatedDocsList) return;
        const remaining = elements.translatedDocsList.querySelectorAll('.translated-doc-item').length;
        if (elements.translatedDocsCount) elements.translatedDocsCount.textContent = remaining;
        if (remaining === 0 && elements.translatedDocsHistory) elements.translatedDocsHistory.style.display = 'none';
    }
}
