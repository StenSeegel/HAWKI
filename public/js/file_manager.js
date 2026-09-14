
//#region UPLOAD DOWNLOAD

/**
 * Upload a file with progress tracking and cancel support.
 *
 * @param {object} fileData - The file metadata and File/Blob object.
 * @param {string} url - The server upload URL.
 * @param {function} progressCallback - Called with (tempId, status, percent, fileUrl).
 * @returns {{ promise: Promise<object>, abort: () => void }}
 */
function uploadFileToServer(fileData, url, progressCallback) {
    let xhr = new XMLHttpRequest();

    const promise = new Promise((resolve, reject) => {
        const formData = new FormData();
        formData.append('file', fileData.file);
        const tempId = fileData.tempId;

        console.log('Uploading file:', {
            name: fileData.name,
            size: fileData.size,
            type: fileData.mime,
            url: url
        });

        // Initial progress state
        if (progressCallback) {
            progressCallback(tempId, 'uploading', 0);
        }

        xhr.open('POST', url, true);

        const csrfToken = document.querySelector('meta[name="csrf-token"]');
        if (csrfToken) {
            xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken.getAttribute('content'));
        }
        
        // Ensure server knows this is an AJAX request expecting JSON
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        // Upload progress tracking
        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable && progressCallback) {
                const percent = Math.round((event.loaded / event.total) * 100);
                progressCallback(tempId, 'uploading', percent);
            }
        };

        // Upload success
        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    // Check if response is HTML (session timeout/redirect)
                    const contentType = xhr.getResponseHeader('Content-Type');
                    if (contentType && contentType.includes('text/html')) {
                        console.error('Received HTML response instead of JSON - possible session timeout', {
                            status: xhr.status,
                            contentType: contentType,
                            responsePreview: xhr.responseText.substring(0, 500)
                        });
                        progressCallback?.(tempId, 'error', 100);
                        reject('Session timeout or authentication error - please refresh the page');
                        return;
                    }
                    
                    const responseData = JSON.parse(xhr.responseText);
                    
                    // Check if upload was successful
                    if (responseData.success === false) {
                        console.error('Upload failed:', responseData);
                        progressCallback?.(tempId, 'error', 100);
                        reject(responseData.message || 'Upload failed');
                        return;
                    }
                    
                    if (progressCallback) {
                        progressCallback(responseData.requestId || tempId, 'complete', 100, responseData.fileUrl);
                    }
                    resolve(responseData);
                } catch (e) {
                    console.error('Failed to parse server response:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText.substring(0, 500),
                        error: e
                    });
                    progressCallback?.(tempId, 'error', 100);
                    reject(`Invalid server response: ${e.message}`);
                }
            } else {
                // Handle HTTP errors (4xx, 5xx)
                let errorMessage = `Upload failed: ${xhr.statusText}`;
                
                try {
                    const errorData = JSON.parse(xhr.responseText);
                    if (errorData.message) {
                        errorMessage = errorData.message;
                    }
                    if (errorData.errors) {
                        // Laravel validation errors
                        const validationErrors = Object.values(errorData.errors).flat();
                        errorMessage = validationErrors.join(', ');
                    }
                } catch (e) {
                    // If response is not JSON, use default error message
                }
                
                console.error('Upload failed with status:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText.substring(0, 500),
                    parsedError: errorMessage
                });
                progressCallback?.(tempId, 'error', 100);
                reject(errorMessage);
            }
        };

        // Network error
        xhr.onerror = () => {
            progressCallback?.(tempId, 'error', 100);
            reject('Network error occurred during upload');
        };

        // Aborted
        xhr.onabort = () => {
            progressCallback?.(tempId, 'aborted', 0);
            reject('Upload aborted by user');
        };

        xhr.send(formData);
    });

    // Return both promise and abort method
    return {
        promise,
        abort: () => {
            if (xhr) xhr.abort();
        }
    };
}


async function requestFileUrl(uuid, category){
    try {
        const response = await fetch(`/req/${category}/attachment/getLink/${uuid}`, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        });

        const data = await response.json();

        if (data.success && data.url) {
        // Automatically start download

        return data.url;

        } else {
        console.error('Failed to get download link');
        }
    } catch (err) {
        console.error('Download error:', err);
        console.error('An error occurred while requesting the file.');
    }
}

async function downloadFile(uuid, category, filename) {
    try {
        // Get signed file URL from your backend
        const url = await requestFileUrl(uuid, category);
        console.log(url);
        // Fetch the file as blob
        const response = await fetch(url);
        console.log(response);
        if (!response.ok) {
            throw new Error(`Download failed: ${response.statusText}`);
        }

        const blob = await response.blob();

        // Create a temporary object URL for the blob
        const objectUrl = URL.createObjectURL(blob);

        // Create a hidden link
        const link = document.createElement("a");
        link.href = objectUrl;
        link.download = filename || "download";

        // Trigger the download
        document.body.appendChild(link);
        link.click();

        // Cleanup
        document.body.removeChild(link);
        URL.revokeObjectURL(objectUrl);
        return true;

    } catch (err) {
        console.error("Download error:", err);
        alert("Failed to download file.");
        return false;
    }
}


//#endregion


//#region PREVIEW

async function previewFile(provider, fileData, category) {
    const indicator = provider.querySelector('.status-indicator');

    try {
        const url = await requestFileUrl(fileData.uuid, category);
        if (!url) {
            console.log('No download link');
            return Promise.reject(new Error('No download link'));
        }

        const response = await fetch(url);
        const blob = await response.blob();

        const type = checkFileFormat(fileData.mime, fileData.name);

        switch (type) {
            case 'image':
                await renderImage(blob);
                break;
            case 'pdf':
                await renderPdf(blob);
                break;
            case 'docx':
                // docx-preview reads OOXML and nothing else: an .odt, .rtf or
                // .pages is the same kind of file but not the same format.
                if (['docx', 'docm'].includes(extensionOf(fileData.name))) {
                    await renderDocx(blob);
                } else {
                    renderFileStub(fileData, category);
                }
                break;
            default:
                // An .epub, an .eml, a spreadsheet: nothing in the browser
                // renders it, so the modal offers the file itself.
                renderFileStub(fileData, category);
        }

        const modal = document.querySelector('#file-viewer-modal');

        modal.style.display = "flex";
        const scrollContainer = modal.querySelector('#file-scroll-container');
        scrollContainer.scrollTop = 0;

        // ✅ return something meaningful to the caller
        return { success: true, type, blob };

    } catch (err) {
        console.error('Error in previewFile:', err);
        return Promise.reject(err);
    }
}

/*
 * The preview for a file kind HAWKI cannot render: its name and a button that
 * saves it. Better than an empty modal, which is what an .xlsx used to get.
 */
function renderFileStub(fileData, category) {
    const container = document.getElementById('file-preview-container');
    container.innerHTML = '';

    const wrapper = document.createElement('div');
    wrapper.classList.add('file-stub-preview');

    const name = document.createElement('p');
    name.classList.add('file-stub-name');
    name.innerText = fileData.name || '';
    wrapper.appendChild(name);

    const hint = document.createElement('p');
    hint.classList.add('file-stub-hint');
    hint.innerText = window.translation?.Input_NoPreview || 'No preview available for this file type.';
    wrapper.appendChild(hint);

    const button = document.createElement('button');
    button.classList.add('btn-lg-fill');
    button.innerText = window.translation?.Download || 'Download';
    button.addEventListener('click', () => downloadFile(fileData.uuid, category, fileData.name));
    wrapper.appendChild(button);

    container.appendChild(wrapper);
}

function scrollToTop(){
    const modal = document.querySelector('#file-viewer-modal');
    const scrollContainer = modal.querySelector('#file-scroll-container');
    scrollContainer.scrollTop = 0;
}

async function renderPdf(blob) {

    const arrayBuffer = await blob.arrayBuffer();

    const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;

    const container = document.getElementById('file-preview-container');
    container.innerHTML = ''; // Clear previous pages

    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
        const pdfPage  = await pdf.getPage(pageNum);
        const viewport = pdfPage.getViewport({ scale: 1 });

        // ── Page wrapper ───────────────────────────────────────────────
        const pageDiv = document.createElement('div');
        pageDiv.className = 'pdf-page';
        pageDiv.style.position = 'relative';
        pageDiv.style.margin = '1rem auto';
        pageDiv.style.width = '100%';
        pageDiv.style.maxWidth = `${viewport.width}px`;

        // ── Responsive canvas ─────────────────────────────────────────
        const canvas   = document.createElement('canvas');
        const context  = canvas.getContext('2d');
        canvas.width   = viewport.width;
        canvas.height  = viewport.height;
        canvas.style.width  = '100%';
        canvas.style.height = 'auto';
        canvas.style.display = 'block';
        pageDiv.appendChild(canvas);

        await pdfPage.render({ canvasContext: context, viewport }).promise;

        // ── Text layer ────────────────────────────────────────────────
        // const textLayerBuilder = new TextLayerBuilder({
        //     pdfPage,
        //     textLayerMode: 2 // Use enhanced layout for better accuracy
        // });
        // console.log(textLayerBuilder)
        // await textLayerBuilder.render({ viewport });

        // const textLayerDiv = textLayerBuilder.div;
        // textLayerDiv.style.position = 'absolute';
        // textLayerDiv.style.top  = '0';
        // textLayerDiv.style.left = '0';
        // textLayerDiv.style.width  = '100%';
        // textLayerDiv.style.height = '100%';

        // pageDiv.appendChild(textLayerDiv);

        // ── Append to container ───────────────────────────────────────
        container.appendChild(pageDiv);
    }

}

async function renderDocx(blob){
    const container = document.getElementById('file-preview-container');
    container.innerHTML = '';

    docxPreview.renderAsync(blob, container)
        .then(x => console.log("docx: finished"));
}

async function renderImage(blob){
    const container = document.getElementById('file-preview-container');
    container.innerHTML = '';


    // Create a local URL for the blob
    const url = URL.createObjectURL(blob);

    // Create an <img> element
    const img = document.createElement('img');
    img.src = url;
    img.classList.add('image-preview');

    // Optionally: Clean up the object URL after image loads to avoid memory leaks
    img.onload = () => {
        URL.revokeObjectURL(url);
    };


    const wrapper = document.createElement('div');
    wrapper.classList.add('image-preview-wrapper');

    // Append the image to the DOM, e.g., to the body or a specific container
    wrapper.appendChild(img);
    container.appendChild(wrapper);

}



//#endregion



//#region Utils

// The extension of a file name, lower case, without the dot.
function extensionOf(name) {
    const match = /\.([A-Za-z0-9]+)$/.exec(String(name || '').trim());
    return match ? match[1].toLowerCase() : '';
}

// The MIME an extension stands for, from the list the server injected.
function mimeForExtension(extension) {
    return window.uploadFormats?.mimes?.[String(extension || '').toLowerCase()] || null;
}

/*
 * What the browser says a file is, corrected.
 *
 * It says nothing at all for most formats the converter reads (.adoc, .eml,
 * .typ, .org) and 'application/zip' for every zip-based one (.docx, .pptx,
 * .epub, .pages), so where it is silent or generic the extension decides -
 * the same rule the server follows.
 */
function resolveFileMime(mime, name) {
    const declared = String(mime || '').toLowerCase().split(';')[0].trim();
    const generic = declared === '' || declared === 'application/octet-stream' ||
                    (declared === 'application/zip' && extensionOf(name) !== 'zip');

    if (!generic) {
        return declared;
    }

    return mimeForExtension(extensionOf(name)) || declared;
}

/*
 * The kind of a file, for its icon, its preview and the model filter it needs.
 * The name is optional but worth passing: without it a .docx is a zip and a
 * .adoc is nothing at all.
 */
function checkFileFormat(mime, name = ''){
    const resolved = resolveFileMime(mime, name);

    if (resolved.startsWith('image/')) {
        return 'image';
    } else if (resolved.includes('pdf')) {
        return 'pdf';
    } else if (resolved.includes('msword') ||
               resolved.includes('wordprocessingml') ||
               resolved.includes('opendocument.text') ||
               resolved.includes('iwork-pages') ||
               resolved.includes('wordperfect') ||
               resolved === 'application/rtf' ||
               resolved === 'text/rtf') {
        return 'docx';
    } else if (resolved.includes('presentationml') ||
               resolved.includes('ms-powerpoint') ||
               resolved.includes('opendocument.presentation') ||
               resolved.includes('iwork-keynote')) {
        return 'pptx';
    } else if (resolved.includes('spreadsheetml') ||
               resolved.includes('ms-excel') ||
               resolved.includes('opendocument.spreadsheet') ||
               resolved.includes('iwork-numbers')) {
        return 'xlsx';
    } else if (resolved.startsWith('audio/') || resolved.startsWith('video/')) {
        return 'audio';
    } else if (resolved === 'application/zip' ||
               resolved.includes('x-tar') ||
               resolved.includes('gzip') ||
               resolved.includes('7z-compressed') ||
               resolved.includes('outlook-pst')) {
        return 'archive';
    } else if (isTextMime(resolved)) {
        return 'text';
    } else if (resolved !== '') {
        // Everything else the converter reads: an .epub, an .eml, a .hwp. It
        // has no renderer of its own, but it is a document all the same.
        return 'document';
    } else {
        return null;
    }
}

// Files whose bytes are their content: a .drawio diagram, XML, JSON, CSV,
// Markdown. They need no converter to reach a model.
function isTextMime(mime) {
    return /^text\//i.test(mime) || /(?:^|\/|\+)(?:xml|json)$/i.test(mime) || mime === 'application/vnd.jgraph.mxfile';
}


// Generate a unique ID for the file
function generateUniqueId() {
    return Date.now().toString(36) + Math.random().toString(36).substr(2, 5);
}

//#endregion
