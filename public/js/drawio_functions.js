/**
 * draw.io in the chat: drawing a diagram a model wrote, and editing it.
 *
 * Both run against HAWKI's own draw.io instance, proxied by nginx at
 * DRAWIO_PATH (see _docker), so no diagram ever leaves this host. draw.io is a
 * client-side app; its viewer draws into the page, its editor runs in an
 * iframe that HAWKI talks to over the documented embed protocol (postMessage,
 * proto=json).
 */

const DRAWIO_PATH = '/drawio';

// Where the draw.io instance lives: HAWKI's own origin, unless a page says
// otherwise (window.DRAWIO_ORIGIN), which the render harness does.
function drawioOrigin() {
  return window.DRAWIO_ORIGIN || window.location.origin;
}

const DRAWIO_FILE_MIME = 'application/vnd.jgraph.mxfile';

// A complete draw.io document: an <mxfile> (what the app saves, one or more
// <diagram> pages, possibly compressed) or a bare <mxGraphModel>.
const COMPLETE_DRAWIO_REGEX = /^\s*(?:<\?xml[^>]*\?>\s*)?<(mxfile|mxGraphModel)\b[\s\S]*<\/\1>\s*$/i;

function isCompleteDrawio(text) {
  return COMPLETE_DRAWIO_REGEX.test(text || '');
}

let drawioViewerLoading = null;

/**
 * The viewer script, once. Every path it may fetch from - stencils, shapes,
 * images, math - is pointed at the HAWKI instance before it loads; left alone
 * it would default to viewer.diagrams.net.
 */
function loadDrawioViewer() {
  if (window.GraphViewer) {
    return Promise.resolve(window.GraphViewer);
  }
  if (drawioViewerLoading) {
    return drawioViewerLoading;
  }

  const base = drawioOrigin() + DRAWIO_PATH;
  window.DRAWIO_BASE_URL = base;
  window.DRAWIO_SERVER_URL = base + '/';
  window.DRAWIO_LIGHTBOX_URL = base;
  window.DRAWIO_VIEWER_URL = base + '/js/viewer-static.min.js';
  window.STENCIL_PATH = base + '/stencils';
  window.SHAPES_PATH = base + '/shapes';
  window.STYLE_PATH = base + '/styles';
  window.IMAGE_PATH = base + '/images';
  window.GRAPH_IMAGE_PATH = base + '/img';
  window.DRAW_MATH_URL = base + '/math/es5';
  window.PROXY_URL = base + '/proxy';
  window.mxBasePath = base + '/mxgraph';
  window.mxImageBasePath = base + '/mxgraph/images';
  window.mxLoadResources = false;
  window.mxLoadStylesheets = false;

  drawioViewerLoading = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = window.DRAWIO_VIEWER_URL;
    script.onload = () => {
      if (window.GraphViewer) {
        resolve(window.GraphViewer);
      } else {
        drawioViewerLoading = null;
        reject(new Error('The draw.io viewer did not define GraphViewer'));
      }
    };
    script.onerror = (error) => {
      drawioViewerLoading = null;
      reject(error);
    };
    document.head.appendChild(script);
  });

  return drawioViewerLoading;
}

/**
 * Draws a diagram into a preview. Resolves to whether it worked; the caller
 * keeps the code visible when it did not.
 */
async function renderDrawioInto(preview, xml) {
  try {
    const GraphViewer = await loadDrawioViewer();

    const element = document.createElement('div');
    element.classList.add('mxgraph');
    // No toolbar of the viewer's own: its zoom controls sit in the code box
    // header with the other actions (drawioZoomButtons), not in a grey bar
    // above the drawing.
    element.setAttribute('data-mxgraph', JSON.stringify({
      xml: String(xml).trim(),
      toolbar: null,
      nav: true,
      resize: true,
      'auto-fit': true,
      border: 10,
      lightbox: false,
      'check-visible-state': false,
    }));
    preview.appendChild(element);

    GraphViewer.createViewerForElement(element, (viewer) => {
      preview.drawioViewer = viewer;
    });

    if (!element.querySelector('svg')) {
      element.remove();
      return false;
    }
    return true;
  } catch (error) {
    console.warn('[CODE BOX] The draw.io diagram could not be drawn:', error?.message || error);
    return false;
  }
}

const DRAWIO_ZOOM_OUT_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>';
const DRAWIO_ZOOM_IN_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>';
const DRAWIO_ZOOM_FIT_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/></svg>';

/**
 * Zoom out, zoom in and fit for a drawn diagram - what the viewer's own toolbar
 * offers, as buttons in the code box header. The same calls the toolbar makes.
 */
function drawioZoomButtons(preview) {
  const group = document.createElement('span');
  group.classList.add('editor-zoom-group');

  const viewer = () => preview.drawioViewer;
  const button = (icon, title, action) => {
    const element = document.createElement('button');
    element.type = 'button';
    element.classList.add('editor-zoom-btn');
    element.title = title;
    element.innerHTML = icon;
    element.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      const graph = viewer()?.graph;
      if (graph) {
        action(graph);
      }
    });
    return element;
  };

  group.appendChild(button(DRAWIO_ZOOM_OUT_ICON, translation?.ZoomOut || 'Zoom out', (graph) => graph.zoomOut()));
  group.appendChild(button(DRAWIO_ZOOM_IN_ICON, translation?.ZoomIn || 'Zoom in', (graph) => graph.zoomIn()));
  group.appendChild(button(DRAWIO_ZOOM_FIT_ICON, translation?.ZoomFit || 'Fit', (graph) => {
    const initial = graph.initialViewState;
    if (initial) {
      graph.view.scaleAndTranslate(initial.scale, initial.translate.x, initial.translate.y);
    }
  }));

  return group;
}

/**
 * The editor, in a modal over the chat. The diagram is handed to it, and the
 * user takes the result back out as a .drawio download or as an attachment to
 * the next message - so the model can go on working on the edited version.
 * The message itself is not changed.
 */
function openDrawioEditor(xml, { name = 'diagram.drawio', anchor = null } = {}) {
  document.querySelector('.drawio-editor-modal')?.remove();

  const modal = document.createElement('div');
  modal.classList.add('drawio-editor-modal');
  modal.innerHTML = `
    <div class="drawio-editor-dialog" role="dialog" aria-modal="true">
      <div class="drawio-editor-header">
        <span class="drawio-editor-title">${escapeHTML(translation?.DiagramEditor || 'Diagram editor')}</span>
        <span class="drawio-editor-actions">
          <button type="button" class="drawio-editor-download">${escapeHTML(translation?.DownloadDrawio || 'Download .drawio')}</button>
          <button type="button" class="drawio-editor-attach">${escapeHTML(translation?.AttachToMessage || 'Attach to next message')}</button>
          <button type="button" class="drawio-editor-close" title="${escapeHTML(translation?.Close || 'Close')}">&times;</button>
        </span>
      </div>
      <iframe class="drawio-editor-frame" title="draw.io"></iframe>
    </div>`;

  const iframe = modal.querySelector('iframe');
  const language = String(window.activeLocale || 'en').slice(0, 2);
  // The editor's own save and exit buttons stay hidden: what "save" means here
  // is decided in the header, and closing is the modal's business.
  iframe.src = `${drawioOrigin()}${DRAWIO_PATH}/?embed=1&proto=json&spin=1&libraries=1&noSaveBtn=1&noExitBtn=1&saveAndExit=0&ui=min&lang=${encodeURIComponent(language)}`;

  const post = (message) => iframe.contentWindow?.postMessage(JSON.stringify(message), '*');

  // Answers to an export request, in order asked.
  const pendingExports = [];

  const onMessage = (event) => {
    if (event.source !== iframe.contentWindow || typeof event.data !== 'string' || event.data === '') {
      return;
    }
    let message;
    try {
      message = JSON.parse(event.data);
    } catch (error) {
      return;
    }

    if (message.event === 'init') {
      post({ action: 'load', xml: String(xml), autosave: 0, title: name });
    } else if (message.event === 'export') {
      pendingExports.shift()?.(message);
    }
  };

  const currentXml = () => new Promise((resolve) => {
    pendingExports.push((message) => resolve(message.xml || message.data || ''));
    post({ action: 'export', format: 'xml' });
  });

  const close = () => {
    window.removeEventListener('message', onMessage);
    document.removeEventListener('keydown', onKey);
    modal.remove();
  };
  const onKey = (event) => {
    if (event.key === 'Escape') {
      close();
    }
  };

  window.addEventListener('message', onMessage);
  document.addEventListener('keydown', onKey);
  modal.querySelector('.drawio-editor-close').addEventListener('click', close);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) {
      close();
    }
  });

  modal.querySelector('.drawio-editor-download').addEventListener('click', async () => {
    downloadTextFile(name, await currentXml(), DRAWIO_FILE_MIME);
  });

  modal.querySelector('.drawio-editor-attach').addEventListener('click', async (event) => {
    const button = event.currentTarget;
    button.disabled = true;
    try {
      const attached = await attachDrawioToNextMessage(await currentXml(), name, anchor);
      if (attached) {
        close();
      }
    } finally {
      button.disabled = false;
    }
  });

  document.body.appendChild(modal);
  return modal;
}

/**
 * Puts the diagram into the attachment queue of the input that belongs to the
 * message, as a .drawio file - the same path a file the user picked takes, so
 * it is uploaded with the next message and reaches the model as its XML.
 */
async function attachDrawioToNextMessage(xml, name, anchor) {
  const inputField = typeof inputFieldForMessage === 'function' ? inputFieldForMessage(anchor) : null;
  const input = (inputField || document.querySelector('.input[id="0"] .input-field'))?.closest('.input');
  if (!input || typeof handleSelectedFiles !== 'function') {
    console.error('[DRAWIO] No input to attach the diagram to');
    return false;
  }

  const file = new File([String(xml)], name, { type: 'application/xml' });
  await handleSelectedFiles([file], input);
  return true;
}

function downloadTextFile(name, text, mime) {
  const blob = new Blob([String(text)], { type: mime });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = name;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  URL.revokeObjectURL(url);
}
