
// 0. initializeMessageFormating: resets all variables to start message.(at request function)
// 1. Gets the received Chunk.
// 2. escape HTML to prevent injection or mistaken rendering.
// 3. format text for code blocks.
// 4. replace markdown syntaxes for interface rendering

let summedText = '';
let randomId = '';

function initializeMessageFormating() {
  summedText = '';
}

function formatChunk(chunk, groundingMetadata) {
  // Validate input
  if (chunk === undefined || chunk === null) {
    console.warn('Received empty chunk in formatChunk');
    return summedText ? formatMessage(summedText, groundingMetadata) : '';
  }

  // If chunk is an object, something went wrong - skip it
  if (typeof chunk === 'object') {
    console.error('Received object instead of string in formatChunk:', chunk);
    return summedText ? formatMessage(summedText, groundingMetadata) : '';
  }

  // Ensure chunk is a string
  const chunkStr = String(chunk);

  // Append the incoming chunk to the summedText
  summedText += chunkStr;

  // Create a temporary copy for formatting
  let formatText = summedText;

  try {
    // Balance code blocks - ensure all blocks are closed
    const backtickCount = (summedText.match(/```/g) || []).length;
    if (backtickCount % 2 !== 0) {
      formatText += '```';
    }

    // Balance thinking blocks - ensure all blocks are closed
    const thinkOpenCount = (summedText.match(/<think>/g) || []).length;
    const thinkCloseCount = (summedText.match(/<\/think>/g) || []).length;
    if (thinkOpenCount > thinkCloseCount) {
      formatText += '</think>';
    }

    // Render the formatted text using markdown processor
    return formatMessage(formatText, groundingMetadata);
  } catch (error) {
    console.error('Error in formatChunk:', error);
    // Fallback to basic rendering without special processing
    return escapeHTML(summedText);
  }
}

function escapeHTML(text) {
  return text.replace(/[<>&"']/g, function (match) {
    return {
      '&': '&amp;',
      '"': '&quot;',
      "'": '&#039;',
      '<': '&lt;',
      '>': '&gt;',
    }[match];
  });
}

/**
 * Replace HTML links in formatted text with inline citation indices
 * @param {HTMLElement} element - The element containing formatted HTML
 * @param {Array} citations - Array of citation objects with url
 * @param {string} messageId - Unique message ID for citation anchors
 */
/**
 * Puts a citation marker after the node it belongs to, and after the
 * punctuation that closes the sentence.
 *
 * The model writes its link before the full stop, so a marker inserted where
 * the link stood lands between the last word and the '.'. The marker belongs
 * outside it, the way a footnote index sits after the punctuation it follows.
 *
 * @param {Node} node - The node the marker is placed behind
 * @param {HTMLElement} marker - The <sup> to insert
 */
function insertMarkerAfterPunctuation(node, marker) {
  const parent = node.parentNode;
  let before = node.nextSibling;

  // Closing quotes and brackets ride along with the punctuation.
  if (before && before.nodeType === Node.TEXT_NODE) {
    const closing = before.textContent.match(
      /^(?:[)\]"'\u201c\u201d\u2018\u2019]+[.,;:!?\u2026]*|[.,;:!?\u2026]+[)\]"'\u201c\u201d\u2018\u2019]*)/
    );

    if (closing) {
      const rest = document.createTextNode(before.textContent.slice(closing[0].length));

      before.textContent = closing[0];
      parent.insertBefore(rest, before.nextSibling);
      before = rest;
    }
  }

  parent.insertBefore(marker, before);
}

let citationScopeCounter = 0;

/**
 * The id the citation anchors of one message are namespaced with.
 *
 * Anchors are looked up with getElementById, which returns the first match in
 * the whole document - so two messages sharing a namespace send every marker of
 * the second one to the first one's sources. 'dataset.messageId' is read in
 * several places here but written nowhere, so it was always undefined and every
 * message answered to 'msg'. The element's own id carries the message id
 * ('1.000', '2.000'); the counter is only for an element that has none.
 *
 * @param {HTMLElement} messageElement
 * @returns {string}
 */
function citationScopeOf(messageElement) {
  if (messageElement.dataset.citationScope) {
    return messageElement.dataset.citationScope;
  }

  const scope = messageElement.id || messageElement.dataset.messageId || `m${++citationScopeCounter}`;
  messageElement.dataset.citationScope = scope;

  return scope;
}

/**
 * Strips the citation markers the model wrote into its own prose.
 *
 * A model told to cite often writes a bracketed number of its own next to the
 * link it was asked for, and some write nothing else. Those survive as plain
 * text beside the marker HAWKI renders, which is the same number twice - once
 * unstyled, once styled. Only numbers that address a source of this message are
 * taken, and never inside code.
 *
 * @param {HTMLElement} element - The .message-text element
 * @param {number} sourceCount - How many sources the message has
 */
function stripModelWrittenMarkers(element, sourceCount) {
  if (!element || sourceCount < 1) {
    return;
  }

  const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT, {
    acceptNode: node => node.parentElement && node.parentElement.closest('code, pre')
      ? NodeFilter.FILTER_REJECT
      : NodeFilter.FILTER_ACCEPT
  });

  const texts = [];
  while (walker.nextNode()) {
    texts.push(walker.currentNode);
  }

  // '[1]', '[1, 2]', '[1][3]' - a run of bracketed numbers, plus the space
  // holding it off the word before it.
  const marker = /[ \t]?\[\s*\d{1,3}(?:\s*[,;]\s*\d{1,3})*\s*\]/g;

  texts.forEach(node => {
    if (!marker.test(node.textContent)) {
      marker.lastIndex = 0;

      return;
    }

    marker.lastIndex = 0;
    node.textContent = node.textContent.replace(marker, match => {
      const numbers = match.match(/\d{1,3}/g) || [];

      // A number naming no source of this message is something else - a
      // footnote of the page it quoted, an array index in prose.
      return numbers.every(n => Number(n) >= 1 && Number(n) <= sourceCount) ? '' : match;
    });
  });
}

/**
 * Removes a sources list the model wrote at the end of its answer.
 *
 * HAWKI appends its own list of chips, so a hand written one is the same
 * sources twice - a heading and a line of titles above the container that
 * already shows them. Models do this even when the prompt asks them not to.
 *
 * Deliberately narrow: only trailing blocks, only ones opening with a sources
 * word, and the rules between them. Anything else the model wrote is left where
 * it is.
 *
 * @param {HTMLElement} element - The .message-text element
 */
function stripModelWrittenSourceList(element) {
  if (!element) {
    return;
  }

  const opensWithSourcesWord = /^\s*(?:\*\*)?\s*(quellen|quellenangaben|sources|referenzen|references|literatur)\b\s*:?/i;

  // At most the last few blocks: a heading, its list, and the rule above them.
  for (let step = 0; step < 4; step++) {
    const last = element.lastElementChild;

    if (!last) {
      return;
    }

    if (last.tagName === 'HR') {
      last.remove();

      continue;
    }

    if (opensWithSourcesWord.test(last.textContent || '')) {
      last.remove();

      continue;
    }

    return;
  }
}

/**
 * Whether a link's label says no more than the numbered marker that replaces it
 * - the label is the URL itself, the bare domain a provider prints as a
 * citation label, or already a citation number of the model's own making.
 *
 * Such a link is swallowed by the marker. Any other label is words the model
 * wrote into its sentence, and dropping them would edit the answer.
 *
 * @param {string} label - The link's text content
 * @param {string} url - The link's href
 * @returns {boolean}
 */
function isBareUrlLabel(label, url) {
  const strip = value => String(value || '').trim().replace(/^https?:\/\//i, '').replace(/^www\./i, '').replace(/\/+$/, '');

  const text = strip(label);
  if (text === '') {
    return true;
  }

  // '[1](url)', '[(2)](url)' and the like: the model numbered the citation
  // itself. Keeping that as prose would print the number twice, once bare and
  // once as the marker.
  if (/^[\[(]?\s*\d{1,3}\s*[\])]?[.,]?$/.test(String(label || '').trim())) {
    return true;
  }

  if (text.toLowerCase() === strip(url).toLowerCase()) {
    return true;
  }

  let host = '';
  try {
    host = new URL(url).hostname.replace(/^www\./i, '');
  } catch (error) {
    return false;
  }

  return host !== '' && text.toLowerCase() === host.toLowerCase();
}

function replaceHtmlLinksWithCitations(element, citations, messageId, indexMapping = null) {
  if (!citations || citations.length === 0 || !element) {
    return;
  }

  // Build URL to display index mapping
  // If indexMapping is provided, use it to map original indices to display indices
  const urlToDisplayIndex = new Map();

  if (indexMapping) {
    // Use provided index mapping (original index → display index)
    citations.forEach((citation, originalIndex) => {
      if (citation && citation.url) {
        const displayIndex = indexMapping[originalIndex];
        if (displayIndex !== undefined) {
          urlToDisplayIndex.set(citation.url, displayIndex + 1); // +1 for 1-based display
        }
      }
    });
  } else {
    // Fallback: deduplicate URLs and assign sequential indices
    citations.forEach((citation, index) => {
      if (citation && citation.url && !urlToDisplayIndex.has(citation.url)) {
        urlToDisplayIndex.set(citation.url, index + 1);
      }
    });
  }

  // Find all <a> elements in the content
  const links = element.querySelectorAll('a[href]');

  links.forEach(link => {
    const url = link.getAttribute('href');
    const citationIndex = urlToDisplayIndex.get(url);

    if (citationIndex) {
      // Create citation marker
      const citationMarker = document.createElement('sup');
      const span = document.createElement('span');
      const citationLink = document.createElement('a');
      citationLink.className = 'inline-citation';
      citationLink.href = `#source${messageId}:${citationIndex}`;
      citationLink.textContent = citationIndex;

      // Note: Click handler is added by initializeInlineCitationHandlers()

      span.appendChild(citationLink);
      citationMarker.appendChild(span);

      // A link whose label carries meaning is part of the sentence: the marker
      // is added after its words, never in place of them. Only a label that is
      // just the URL or its bare domain says nothing the marker does not, and
      // that one is swallowed together with the parentheses around it.
      if (!isBareUrlLabel(link.textContent, url)) {
        const label = document.createTextNode(link.textContent);

        link.parentNode.replaceChild(label, link);
        insertMarkerAfterPunctuation(label, citationMarker);

        return;
      }

      // Check for surrounding parentheses in text nodes
      const prevNode = link.previousSibling;
      const nextNode = link.nextSibling;

      // Remove opening parenthesis before link
      if (prevNode && prevNode.nodeType === Node.TEXT_NODE && prevNode.textContent.endsWith('(')) {
        prevNode.textContent = prevNode.textContent.slice(0, -1);
      }

      // Remove closing parenthesis after link
      if (nextNode && nextNode.nodeType === Node.TEXT_NODE && nextNode.textContent.startsWith(')')) {
        nextNode.textContent = nextNode.textContent.slice(1);
      }

      // The link says nothing the marker does not, so the marker takes its
      // place - but still lands after the punctuation that follows it.
      const placeholder = document.createTextNode('');

      link.parentNode.replaceChild(placeholder, link);
      insertMarkerAfterPunctuation(placeholder, citationMarker);
      placeholder.remove();

    }
  });
}

/**
 * Replace markdown links with inline citation indices
 * @param {string} text - The markdown text with links
 * @param {Array} citations - Array of citation objects with url
 * @param {string} messageId - Unique message ID for citation anchors
 * @returns {string} Text with markdown links replaced by citation indices
 * @deprecated Use replaceHtmlLinksWithCitations instead - work with already formatted HTML
 */
function replaceMarkdownLinksWithCitations(text, citations, messageId) {
  if (!citations || citations.length === 0 || !text) {
    return text;
  }

  // Build URL to index mapping (deduplicated URLs get same index)
  const urlToIndex = new Map();
  citations.forEach((citation, index) => {
    if (!urlToIndex.has(citation.url)) {
      urlToIndex.set(citation.url, index + 1);
    }
  });

  // Find all markdown links: [text](url)
  const markdownLinkRegex = /\[([^\]]+)\]\(([^)]+)\)/g;

  let modifiedText = text;
  const replacements = [];

  // Collect all matches first (to avoid regex state issues)
  let match;
  while ((match = markdownLinkRegex.exec(text)) !== null) {
    replacements.push({
      fullMatch: match[0],
      linkText: match[1],
      url: match[2],
      index: match.index
    });
  }

  // Process replacements in reverse order (to maintain indices)
  replacements.reverse().forEach(({ fullMatch, url, index }) => {
    const citationIndex = urlToIndex.get(url);
    if (citationIndex) {
      // Replace [text](url) with citation marker
      const citationMarker = `<sup><span><a class="inline-citation" href="#source${messageId}:${citationIndex}">${citationIndex}</a></span></sup>`;
      modifiedText = modifiedText.substring(0, index) + citationMarker + modifiedText.substring(index + fullMatch.length);
    }
  });

  return modifiedText;
}

/**
 * Insert inline citations into text based on OpenAI Responses API annotations
 * @param {string} text - The text content
 * @param {Array} citations - Array of citation objects with start_index, end_index, url, title
 * @param {string} messageId - Unique message ID for citation anchors
 * @returns {string} Text with inline citation markers inserted
 * @deprecated Use replaceMarkdownLinksWithCitations instead - LLM returns markdown links
 */
function insertInlineCitations(text, citations, messageId) {
  if (!citations || citations.length === 0 || !text) {
    return text;
  }

  // Sort citations by start_index in reverse order (process from end to start)
  // This way indices don't shift as we insert
  const sortedCitations = [...citations].sort((a, b) => b.start_index - a.start_index);

  // Build URL to index mapping (deduplicated URLs get same index)
  const urlToIndex = new Map();
  const uniqueUrls = [];
  citations.forEach(citation => {
    if (!urlToIndex.has(citation.url)) {
      uniqueUrls.push(citation.url);
      urlToIndex.set(citation.url, uniqueUrls.length);
    }
  });

  let modifiedText = text;

  // Group citations by position (same end_index = group together)
  const positionGroups = new Map();
  sortedCitations.forEach(citation => {
    const key = citation.end_index;
    if (!positionGroups.has(key)) {
      positionGroups.set(key, []);
    }
    positionGroups.get(key).push(citation);
  });

  // Process each position group
  Array.from(positionGroups.entries())
    .sort((a, b) => b[0] - a[0]) // Sort by position descending
    .forEach(([endIndex, groupCitations]) => {
      // Get unique citation numbers for this group
      const citationNumbers = [...new Set(
        groupCitations.map(c => urlToIndex.get(c.url))
      )].sort((a, b) => a - b);

      // Build citation links
      const citationLinks = citationNumbers.map(num =>
        `<a class="inline-citation" href="#source${messageId}:${num}">${num}</a>`
      ).join(', ');

      // Insert citation marker at end_index
      const citationMarker = `<sup><span>${citationLinks}</span></sup>`;
      modifiedText = modifiedText.slice(0, endIndex) + citationMarker + modifiedText.slice(endIndex);
    });

  return modifiedText;
}

function formatMessage(rawContent, groundingMetadata = '') {
  // Early exit for empty content
  if (!rawContent || rawContent.trim() === '') {
    return '';
  }

  try {
    // Process citations and preserve HTML elements in one step
    const contentToProcess = formatGoogleCitations(rawContent, groundingMetadata);

    // Process content with placeholders for math and think blocks
    const { processedContent, mathReplacements, thinkReplacements } = preprocessContent(contentToProcess);

    // Apply markdown rendering
    const markdownProcessed = md.render(processedContent);

    // Restore math and think block content
    let finalContent = postprocessContent(markdownProcessed, mathReplacements, thinkReplacements);

    // Crucial: Restore preserved HTML elements before manipulating links!
    finalContent = restoreGoogleCitations(finalContent);

    // Convert bare URLs to <a> where appropriate
    finalContent = convertHyperlinksToLinks(finalContent);

    return finalContent;
  } catch (error) {
    console.error('Error in formatMessage:', error);
    // Fallback to basic escaping if something goes wrong
    return escapeHTML(rawContent);
  }
}

/**
 * A code box longer than this is minimized when the message is rendered
 * complete, so a long listing does not push the answer off the screen. Not
 * while the answer streams - that is the one moment the code is worth watching.
 */
const AUTO_MINIMIZE_LINES = 20;

/**
 * @param {object} options
 * @param {boolean} options.collapseLongCode  fold boxes longer than AUTO_MINIMIZE_LINES
 * @param {boolean} options.renderDiagrams    draw mermaid diagrams (needs the library, async);
 *                                            off while streaming, when the code is not finished
 */
function formatHljs(messageElement, { collapseLongCode = true, renderDiagrams = true } = {}) {
  registerCodeLanguageAliases();

  // Position among the message's draw.io blocks: what a saved edit is keyed by.
  let drawioIndex = 0;

  messageElement.querySelectorAll('pre code').forEach((block) => {
    if (block.dataset.highlighted != 'true') {
      hljs.highlightElement(block);
    }
    const language = block.result?.language || block.className.match(/language-(\w+)/)?.[1];
    if (!language) {
      return;
    }

    buildCodeBox(block.parentElement, block, language);

    if (language === 'output') {
      foldOutputIntoPreviousCodeBox(block);
      return;
    }

    const wrapper = block.closest('.code-block-wrapper');
    const kind = diagramKind(block, language);

    const context = kind === 'drawio' ? { messageElement, block: drawioIndex++ } : {};

    if (kind === 'svg' || (kind !== null && renderDiagrams)) {
      buildDiagramView(block, kind, context);
    }

    // A box that holds a picture is not folded: the picture stands in for the
    // code - and it may still be on its way, mermaid and draw.io draw
    // asynchronously, so the kind decides, not the class.
    if (collapseLongCode && kind === null && codeLineCount(block) > AUTO_MINIMIZE_LINES) {
      setCodeBoxMinimized(wrapper, true, true);
    }
  });

  syncInlinePlots(messageElement);
}

function codeLineCount(block) {
  const text = (block.textContent || '').replace(/\n$/, '');
  return text === '' ? 0 : text.split('\n').length;
}

/**
 * Folds or unfolds a code box and keeps its button in step. `auto` marks a box
 * folded for its length alone: the run output of such a box stays visible - the
 * result is what the reader wants, only the listing is long. A click on the
 * button is the user's decision and drops the mark.
 */
function setCodeBoxMinimized(wrapper, minimized, auto = false) {
  if (!wrapper) {
    return;
  }

  wrapper.classList.toggle('minimized', minimized);
  wrapper.classList.toggle('auto-minimized', minimized && auto);

  const minimizeBtn = wrapper.querySelector(':scope > .code-actions .editor-minimize-btn');
  if (minimizeBtn) {
    minimizeBtn.innerHTML = minimized ? MAXIMIZE_ICON : MINIMIZE_ICON;
    minimizeBtn.title = minimized
      ? (translation?.Maximize || 'Maximize')
      : (translation?.Minimize || 'Minimize');
  }
}

/**
 * What a code box holds when it holds a picture rather than a program:
 * 'svg' for a complete <svg> drawing (declared as svg, or an xml/html block
 * that is one), 'mermaid' for a mermaid diagram, null for code. While an SVG
 * block still streams in, the closing tag is missing and it is code for now.
 */
function diagramKind(block, language) {
  const lang = String(language).toLowerCase();

  if (lang === 'mermaid') {
    return 'mermaid';
  }

  if (['drawio', 'xml'].includes(lang) && typeof isCompleteDrawio === 'function' && isCompleteDrawio(block.textContent)) {
    return 'drawio';
  }

  if (['svg', 'xml', 'html'].includes(lang) && isCompleteSvg(block.textContent)) {
    return 'svg';
  }

  return null;
}

// highlight.js knows no 'drawio'; the block is XML and is coloured as such.
// Lazily, because hljs arrives with the module bundle after this script.
let drawioAliasRegistered = false;

function registerCodeLanguageAliases() {
  if (drawioAliasRegistered || typeof hljs === 'undefined' || typeof hljs.registerAliases !== 'function') {
    return;
  }
  if (hljs.getLanguage('xml')) {
    hljs.registerAliases(['drawio'], { languageName: 'xml' });
  }
  drawioAliasRegistered = true;
}

const COMPLETE_SVG_REGEX = /^\s*(?:<\?xml[^>]*\?>\s*)?(?:<!DOCTYPE\s+svg[^>]*>\s*)?<svg\b[\s\S]*<\/svg>\s*$/i;

function isCompleteSvg(text) {
  return COMPLETE_SVG_REGEX.test(text || '');
}

const CODE_VIEW_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 16 4-4-4-4"/><path d="m6 8-4 4 4 4"/><path d="m14.5 4-5 16"/></svg>';
const DIAGRAM_VIEW_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h20"/><path d="M21 3v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V3"/><path d="m7 21 5-5 5 5"/></svg>';

/**
 * A code box whose code is a picture shows the picture, the way the create
 * mode editor shows a mermaid diagram: the drawing stands in for the code, and
 * a toggle in the box's actions brings the code back. A model without an image
 * tool draws its picture as SVG markup - the code alone is no picture.
 *
 * An SVG is drawn as an <img> with a data URI: an image element never runs the
 * scripts or event handlers an SVG might carry, so nothing has to be sanitized
 * here. A mermaid diagram is rendered by the library, asynchronously; the code
 * stays visible until the drawing is there, and stays if the diagram is invalid.
 */
function buildDiagramView(block, kind, context = {}) {
  const wrapper = block.closest('.code-block-wrapper');
  if (!wrapper) {
    return;
  }
  wrapper.classList.remove('diagram-edited');

  // The message is re-rendered on every chunk; never leave two previews behind.
  wrapper.querySelectorAll(':scope > .diagram-preview').forEach((stale) => stale.remove());
  wrapper.querySelectorAll(':scope > .code-actions .editor-toggle-btn').forEach((stale) => stale.remove());

  const preview = document.createElement('div');
  preview.classList.add('diagram-preview');
  preview.dataset.label = kind === 'drawio' ? 'draw.io' : kind;

  const pre = wrapper.querySelector(':scope > pre');
  (pre || wrapper).after(preview);

  const actions = wrapper.querySelector(':scope > .code-actions');
  const toggle = buildDiagramToggle(wrapper);
  if (actions) {
    actions.prepend(toggle);
  }

  if (kind === 'svg') {
    const img = document.createElement('img');
    img.setAttribute('src', svgDataUri(block.textContent));
    img.setAttribute('alt', 'SVG');
    preview.appendChild(img);

    addDiagramDownloadButton(preview);
    setDiagramMode(wrapper, true);
    return;
  }

  let render;

  if (kind === 'drawio') {
    // The version to show: an edit the user saved on the message, if there is
    // one for this block, else the model's original.
    const saved = savedDiagramFor(context);
    const source = saved
      ? fetchSavedDiagram(saved).then((xml) => xml || block.textContent)
      : Promise.resolve(block.textContent);

    render = source.then((xml) => {
      const edited = saved !== null && xml !== block.textContent;
      // The source is what the download saves: a .drawio file opens in the editor.
      preview.dataset.source = String(xml).trim();
      preview.dataset.downloadName = 'diagram.drawio';
      preview.dataset.downloadType = 'application/vnd.jgraph.mxfile';
      preview.dataset.label = edited ? 'draw.io \u00b7 ' + (translation?.DiagramEdited || 'edited') : 'draw.io';
      wrapper.classList.toggle('diagram-edited', edited);
      return renderDrawioInto(preview, xml);
    });
  } else {
    render = renderMermaidInto(preview, block.textContent);
  }

  render.then((ok) => {
    if (!wrapper.isConnected && !document.contains(wrapper)) {
      return;
    }
    if (ok) {
      addDiagramDownloadButton(preview);
      if (kind === 'drawio' && actions) {
        actions.prepend(buildDiagramEditButton(wrapper, block, preview, context));
        if (typeof drawioZoomButtons === 'function') {
          actions.prepend(drawioZoomButtons(preview));
        }
      }
      setDiagramMode(wrapper, true);
    } else {
      // Not a diagram the library can draw: it stays code, without a toggle
      // that would lead to an empty frame.
      toggle.remove();
      preview.remove();
    }
  });
}

/**
 * The download button of a picture box sits in the box's lower right corner,
 * not on the picture: the picture is centred and often narrower than the box.
 * The chat's button, from message_functions; the create mode editor has none.
 */
function addDiagramDownloadButton(preview) {
  if (typeof addImageDownloadButton === 'function') {
    addImageDownloadButton(preview);
  }
}

/**
 * Opens the diagram in HAWKI's own draw.io editor. The result comes back as a
 * download or as an attachment to the next message, never into this message.
 */
function buildDiagramEditButton(wrapper, block, preview, context = {}) {
  wrapper.querySelectorAll(':scope > .code-actions .editor-edit-btn').forEach((stale) => stale.remove());

  const button = document.createElement('button');
  button.type = 'button';
  button.classList.add('editor-edit-btn');
  button.innerHTML = EDIT_ICON + '<span>' + (translation?.EditDiagram || 'Edit') + '</span>';

  button.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    if (typeof openDrawioEditor !== 'function') {
      return;
    }
    // The editor starts from what the box shows - a saved edit, or the original.
    openDrawioEditor(preview.dataset.source || block.textContent, {
      anchor: wrapper,
      save: savedDiagramTarget(context),
      onSaved: (fileData) => {
        if (context.messageElement && typeof rememberSavedDiagram === 'function') {
          rememberSavedDiagram(context.messageElement, fileData);
        }
        buildDiagramView(block, 'drawio', context);
      },
    });
  });

  return button;
}

// The saved edit for a draw.io block, or null.
function savedDiagramFor(context) {
  if (!context.messageElement || typeof savedDiagramsOf !== 'function') {
    return null;
  }
  return savedDiagramsOf(context.messageElement)[context.block] ?? null;
}

async function fetchSavedDiagram(saved) {
  try {
    const response = await fetch(saved.url, { credentials: 'same-origin' });
    if (!response.ok) {
      throw new Error('status ' + response.status);
    }
    const xml = await response.text();
    return typeof isCompleteDrawio === 'function' && isCompleteDrawio(xml) ? xml : '';
  } catch (error) {
    console.warn('[CODE BOX] The saved diagram could not be loaded, showing the original:', error?.message || error);
    return '';
  }
}

/**
 * Where an edit of this block can be saved: the message's own conversation.
 * Only for a message of a private chat that is already saved; null means the
 * editor offers download and attach only.
 */
function savedDiagramTarget(context) {
  const message = context.messageElement;
  const slug = typeof activeConv !== 'undefined' && activeConv ? activeConv.slug : null;
  if (!message || !slug || !message.id || !message.classList.contains('AI') || message.closest('.room-chatlog, #groupchat')) {
    return null;
  }
  return { slug, messageId: message.id, block: context.block };
}

const EDIT_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';

function buildDiagramToggle(wrapper) {
  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.classList.add('editor-toggle-btn');

  toggle.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    setDiagramMode(wrapper, !wrapper.classList.contains('diagram-active'));
  });

  return toggle;
}

/**
 * Shows the picture or the code, and labels the toggle with what a click would
 * bring: "Code" while the picture shows, "Diagram" while the code does.
 */
function setDiagramMode(wrapper, showDiagram) {
  wrapper.classList.toggle('diagram-active', showDiagram);

  const toggle = wrapper.querySelector(':scope > .code-actions .editor-toggle-btn');
  if (toggle) {
    toggle.innerHTML = showDiagram
      ? CODE_VIEW_ICON + '<span>' + (translation?.CodeView || 'Code') + '</span>'
      : DIAGRAM_VIEW_ICON + '<span>' + (translation?.DiagramView || 'Diagram') + '</span>';
  }
}

/**
 * Draws a mermaid diagram into the preview. Resolves to whether it worked.
 * The library is loaded on first use, from the same CDN and at the same version
 * the create mode editor uses; strict security, because the text is a model's.
 */
async function renderMermaidInto(preview, code) {
  try {
    const mermaid = await loadMermaid();
    const id = 'chat-mermaid-' + Math.random().toString(36).slice(2, 9);
    const { svg } = await mermaid.render(id, String(code).trim());
    preview.innerHTML = svg;
    return true;
  } catch (error) {
    console.warn('[CODE BOX] The mermaid diagram could not be drawn:', error?.message || error);
    // mermaid leaves the element it drew into behind when the syntax is invalid.
    document.querySelectorAll('[id^="dchat-mermaid-"]').forEach((leftover) => leftover.remove());
    return false;
  }
}

let mermaidLoading = null;

function loadMermaid() {
  if (window.mermaid) {
    return Promise.resolve(window.mermaid);
  }
  if (mermaidLoading) {
    return mermaidLoading;
  }

  mermaidLoading = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js';
    script.onload = () => {
      let dark = false;
      try {
        dark = localStorage.getItem('darkMode') === 'enabled';
      } catch (error) {
        dark = false;
      }
      window.mermaid.initialize({ startOnLoad: false, theme: dark ? 'dark' : 'default', securityLevel: 'strict' });
      resolve(window.mermaid);
    };
    script.onerror = (error) => {
      mermaidLoading = null;
      reject(error);
    };
    document.head.appendChild(script);
  });

  return mermaidLoading;
}

// SVG markup as an image source. Base64 rather than percent-encoding, so a '#'
// in a colour or a '%' in a width cannot cut the URI short.
function svgDataUri(markup) {
  return 'data:image/svg+xml;base64,' + base64Utf8(giveSvgIntrinsicSize(declareSvgNamespaces(String(markup).trim())));
}

// A viewBox without width and height is a shape without a size: fine in a
// block, 0x0 inside the shrink-to-fit frame that carries the download button.
// Same repair as SvgSanitizer::giveIntrinsicSize on the server.
function giveSvgIntrinsicSize(markup) {
  return markup.replace(/<svg\b[^>]*>/i, (tag) => {
    if (/\s(?:width|height)\s*=/i.test(tag)) {
      return tag;
    }
    const viewBox = tag.match(/\sviewBox\s*=\s*["']\s*([-\d.]+)[\s,]+([-\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)\s*["']/i);
    if (!viewBox || !(parseFloat(viewBox[3]) > 0) || !(parseFloat(viewBox[4]) > 0)) {
      return tag;
    }
    return tag.replace(/^<svg\b/i, `<svg width="${parseFloat(viewBox[3])}" height="${parseFloat(viewBox[4])}"`);
  });
}

// An <svg> without xmlns is, to an XML parser, no SVG at all and draws nothing.
// Models leave it out often enough; same repair as SvgSanitizer on the server.
function declareSvgNamespaces(markup) {
  return markup.replace(/<svg\b[^>]*>/i, (tag) => {
    let fixed = tag;
    if (!/\sxmlns\s*=/i.test(tag)) {
      fixed = fixed.replace(/^<svg\b/i, '<svg xmlns="http://www.w3.org/2000/svg"');
    }
    if (markup.includes('xlink:') && !/\sxmlns:xlink\s*=/i.test(tag)) {
      fixed = fixed.replace(/^<svg\b/i, '<svg xmlns:xlink="http://www.w3.org/1999/xlink"');
    }
    return fixed;
  });
}

function base64Utf8(text) {
  const bytes = new TextEncoder().encode(text);
  let binary = '';
  for (let i = 0; i < bytes.length; i += 0x8000) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
  }
  return btoa(binary);
}

/**
 * Keeps the stored url of a code interpreter plot on the message, in the order
 * the plots were made and with the output_index of the call that drew them. The
 * message markup is rebuilt on every chunk, so this lives on the element and
 * not in it.
 */
function rememberInlinePlot(messageElement, url, outputIndex = null) {
  if (!messageElement || !url) {
    return;
  }

  const plots = inlinePlotsOf(messageElement);

  if (!plots.some((plot) => plot.url === url)) {
    plots.push({ url, outputIndex: outputIndex ?? null });
    messageElement.dataset.inlinePlots = JSON.stringify(plots);
  }
}

/**
 * Keeps the stored url of a file the code interpreter wrote, by its name in the
 * container, so the model's `sandbox:/mnt/data/<name>` link can be pointed at it.
 */
function rememberContainerFile(messageElement, filename, url) {
  if (!messageElement || !filename || !url) {
    return;
  }

  const files = containerFilesOf(messageElement);
  files[filename] = url;
  messageElement.dataset.containerFiles = JSON.stringify(files);
}

function containerFilesOf(messageElement) {
  try {
    const files = JSON.parse(messageElement.dataset.containerFiles || '{}');
    return files && typeof files === 'object' ? files : {};
  } catch (error) {
    return {};
  }
}

// 'sandbox:/mnt/data/report.csv' -> 'report.csv'
function sandboxFileName(reference) {
  const path = String(reference).trim().replace(/^sandbox:/, '').split(/[?#]/)[0];
  try {
    return decodeURIComponent(path.split('/').pop() || '');
  } catch (error) {
    return path.split('/').pop() || '';
  }
}

function isImageFileName(nameOrPath) {
  return /\.(png|jpe?g|gif|webp|svg)(\?.*)?$/i.test(String(nameOrPath).trim());
}

function inlinePlotsOf(messageElement) {
  try {
    const plots = JSON.parse(messageElement.dataset.inlinePlots || '[]');
    // Older entries were bare urls.
    return plots.map((plot) => (typeof plot === 'string' ? { url: plot, outputIndex: null } : plot));
  } catch (error) {
    return [];
  }
}

/**
 * Puts every code interpreter plot of the message into the text exactly once.
 * The server stores the plot and announces it (rememberInlinePlot), but does not
 * write it into the text, because OpenAI's models place the picture themselves,
 * as a `sandbox:/mnt/data/…` reference in their answer - a path only the
 * container knows.
 *
 * - a sandbox reference whose file name HAWKI fetched out of the container (see
 *   rememberContainerFile) is pointed at that file - exact, by name;
 * - any other sandbox image is pointed at the stored plots, in order; a link
 *   around it is dropped; one that would show a plot a second time, or for which
 *   there is no plot, is removed rather than left broken;
 * - a bare sandbox link to an image file is pointed at the first stored plot. Any
 *   other sandbox link - a CSV, a PDF, an image when no plot was stored - is reduced
 *   to its text: HAWKI does not fetch files out of the container, so there is
 *   nothing to link to and a link to the chart would mislead;
 * - a plot the text does not show after that is drawn under the code box of the
 *   call that made it, or at the end of the message.
 *
 * Runs after every render, and is stateless apart from the remembered plots, so
 * the picture moves to the model's reference as soon as that streams in.
 */
function syncInlinePlots(messageElement) {
  const text = messageElement?.querySelector('.message-text');
  if (!text) {
    return;
  }

  const plots = inlinePlotsOf(messageElement);
  const files = containerFilesOf(messageElement);
  const isSandbox = (value) => typeof value === 'string' && value.trim().startsWith('sandbox:');
  const shown = () => Array.from(text.querySelectorAll('img')).map((img) => img.getAttribute('src'));

  // Exact first: references to files fetched out of the container, by name.
  text.querySelectorAll('img').forEach((img) => {
    const src = img.getAttribute('src');
    const url = isSandbox(src) ? files[sandboxFileName(src)] : undefined;
    if (!url) {
      return;
    }

    const link = img.closest('a');
    const wrappedInSandboxLink = link && text.contains(link) && isSandbox(link.getAttribute('href'));

    if (shown().includes(url)) {
      (wrappedInSandboxLink ? link : img).remove();
      return;
    }

    img.setAttribute('src', url);
    if (wrappedInSandboxLink) {
      link.replaceWith(...link.childNodes);
    }
  });

  text.querySelectorAll('a').forEach((link) => {
    const href = link.getAttribute('href');
    const name = isSandbox(href) ? sandboxFileName(href) : '';
    const url = name ? files[name] : undefined;
    if (!url) {
      return;
    }

    link.setAttribute('href', url);
    link.setAttribute('download', name);
    link.setAttribute('target', '_blank');
    link.setAttribute('rel', 'noopener');

    // A picture the model only offered for download - "Download the PNG" - is
    // shown as well: the file is there, and a chat shows its pictures.
    if (isImageFileName(name) && !shown().includes(url) && !link.querySelector('img')) {
      const paragraph = document.createElement('p');
      const img = document.createElement('img');
      img.setAttribute('src', url);
      img.setAttribute('alt', name);
      paragraph.appendChild(img);

      const blockParent = link.closest('p, li, td, th, h1, h2, h3, h4, h5, h6, blockquote') ?? link;
      blockParent.after(paragraph);
    }
  });

  let next = 0;
  text.querySelectorAll('img').forEach((img) => {
    if (!isSandbox(img.getAttribute('src'))) {
      return;
    }

    const link = img.closest('a');
    const wrappedInSandboxLink = link && text.contains(link) && isSandbox(link.getAttribute('href'));
    const target = wrappedInSandboxLink ? link : img;
    const url = plots[next++]?.url;

    if (!url || shown().includes(url)) {
      if (plots.length > 0) {
        target.remove();
      }
      return;
    }

    img.setAttribute('src', url);

    if (wrappedInSandboxLink) {
      link.replaceWith(...link.childNodes);
    }
  });

  text.querySelectorAll('a').forEach((link) => {
    const href = link.getAttribute('href');
    if (!isSandbox(href)) {
      return;
    }

    const isImageFile = isImageFileName(href);

    if (isImageFile && plots.length > 0) {
      link.setAttribute('href', plots[0].url);
      link.setAttribute('target', '_blank');
      link.setAttribute('rel', 'noopener');
      return;
    }

    link.replaceWith(document.createTextNode(link.textContent));
  });

  // The fallback: whatever the model did not place goes under its code box. The
  // n-th code box that ran is the n-th distinct output_index among the plots.
  const codeBoxes = Array.from(text.querySelectorAll('.code-block-wrapper'))
    .filter((box) => box.querySelector(':scope > pre > code'));
  const callOrder = [];
  plots.forEach((plot) => {
    if (plot.outputIndex !== null && !callOrder.includes(plot.outputIndex)) {
      callOrder.push(plot.outputIndex);
    }
  });

  plots.forEach((plot) => {
    if (shown().includes(plot.url)) {
      return;
    }

    const paragraph = document.createElement('p');
    const img = document.createElement('img');
    img.setAttribute('src', plot.url);
    img.setAttribute('alt', 'Plot');
    paragraph.appendChild(img);

    const box = codeBoxes[callOrder.indexOf(plot.outputIndex)] ?? null;
    if (box) {
      // After this call's box and after any plot already placed under it.
      let anchor = box;
      while (anchor.nextElementSibling?.matches('p') && anchor.nextElementSibling.querySelector('img')
        && plots.some((p) => p.url === anchor.nextElementSibling.querySelector('img').getAttribute('src'))) {
        anchor = anchor.nextElementSibling;
      }
      anchor.after(paragraph);
    } else {
      text.appendChild(paragraph);
    }
  });
}

/**
 * The code interpreter writes what the sandbox printed as a fenced ```output
 * block under the code it ran. On its own that renders as a second code box,
 * which is not what it is: it is the output of the box above it. So its content
 * is moved into that box's own output panel - the very same panel the run button
 * fills - and the extra box is dropped.
 *
 * Done here, on the output block rather than on the code block, because
 * formatHljs() walks the blocks in document order: by the time this one is
 * reached the code block above has already been wrapped, so it can be found.
 * Rendering the text through renderCodeOutput() also means any base64 image that
 * survived in the output still becomes a picture.
 */
function foldOutputIntoPreviousCodeBox(block) {
  const wrapper = block.closest('.code-block-wrapper');
  const target = wrapper?.previousElementSibling;

  if (!target || !target.classList.contains('code-block-wrapper')) {
    return;
  }

  // Only ever fold into a box that holds code, never into another output panel.
  if (!target.querySelector(':scope > pre > code')) {
    return;
  }

  const text = block.textContent;

  // A failed run is written into the same block, so it gets the panel's error
  // styling rather than being presented as a normal result.
  const failed = /^\s*(?:Error:|Traceback \(most recent call last\))/.test(text)
    || text.includes('[the code was stopped because it ran too long');

  const output = ensureCodeOutput(target);
  output.classList.remove('hidden');
  renderCodeOutput(output.querySelector('.editor-code-output-content'), text, failed);

  wrapper.remove();
}

/**
 * The chat code box, built to match the one the create mode editor renders:
 * a wrapper holding the <pre>, whose header and language label come from the
 * stylesheet (pre::before), and the actions floating in the top right corner.
 *
 * Rebuilt from scratch on every call rather than skipped when parts exist -
 * streaming re-renders the message on each chunk and the final render replaces
 * it once more, so leftovers would otherwise pile up.
 */
function buildCodeBox(pre, block, language) {
  let wrapper = pre.parentElement;
  if (!wrapper || !wrapper.classList.contains('code-block-wrapper')) {
    wrapper = document.createElement('div');
    wrapper.classList.add('code-block-wrapper');
    pre.replaceWith(wrapper);
    wrapper.appendChild(pre);
  }

  // The language label is the stylesheet's own pre::before header - adding an
  // element for it here is what put two labels on every box.
  wrapper.querySelectorAll('.hljs-code-header, .code-actions').forEach((stale) => stale.remove());

  wrapper.appendChild(buildCodeActions(block, language));
}

/**
 * Copy, minimize and - for Python - run. Same classes and glyphs as the create
 * mode editor, so the two code boxes look and behave alike.
 */
function buildCodeActions(block, language) {
  const actions = document.createElement('div');
  actions.classList.add('code-actions');

  if (language === 'python' || language === 'py') {
    actions.appendChild(buildRunButton(block));
  }

  actions.appendChild(buildCopyButton(block));
  actions.appendChild(buildMinimizeButton());

  return actions;
}

function buildCopyButton(block) {
  const copyBtn = document.createElement('button');
  copyBtn.type = 'button';
  // .copy-btn as well, so activateMessageControls() does not add a second one.
  copyBtn.classList.add('editor-copy-btn', 'copy-btn');
  copyBtn.title = translation?.Copy || 'Copy';
  copyBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg><div class="reaction">${translation?.Copied || 'Copied'}</div>`;

  copyBtn.addEventListener('click', async (event) => {
    event.preventDefault();
    event.stopPropagation();

    try {
      await navigator.clipboard.writeText(block.textContent);
      const bubble = copyBtn.querySelector('.reaction');
      if (bubble) {
        bubble.style.display = 'block';
        bubble.style.opacity = '1';
        setTimeout(() => {
          bubble.style.opacity = '0';
          setTimeout(() => { bubble.style.display = 'none'; }, 200);
        }, 1200);
      }
    } catch (error) {
      console.error('Could not copy the code block:', error);
    }
  });

  return copyBtn;
}

const MINIMIZE_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>';
const MAXIMIZE_ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';

function buildMinimizeButton() {
  const minimizeBtn = document.createElement('button');
  minimizeBtn.type = 'button';
  minimizeBtn.classList.add('editor-minimize-btn');
  minimizeBtn.innerHTML = MINIMIZE_ICON;
  minimizeBtn.title = translation?.Minimize || 'Minimize';

  minimizeBtn.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();

    const wrapper = minimizeBtn.closest('.code-block-wrapper');
    if (!wrapper) {
      return;
    }

    setCodeBoxMinimized(wrapper, !wrapper.classList.contains('minimized'));
  });

  return minimizeBtn;
}

function buildRunButton(block) {
  const runBtn = document.createElement('button');
  runBtn.type = 'button';
  runBtn.classList.add('editor-run-code-btn');
  const label = translation?.RunCode || 'Run code';
  runBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg><span>${label}</span>`;

  runBtn.addEventListener('click', async (event) => {
    event.preventDefault();
    event.stopPropagation();

    const output = ensureCodeOutput(runBtn.closest('.code-block-wrapper'));
    const content = output.querySelector('.editor-code-output-content');

    runBtn.disabled = true;
    output.classList.remove('hidden');
    content.classList.remove('error');
    content.textContent = translation?.RunningCode || 'Running...';

    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
      const response = await fetch('/req/conv/executeCode', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({ code: block.textContent }),
      });

      const data = await response.json();
      renderCodeOutput(content, data.output || '', data.success !== true, data.images || []);
    } catch (error) {
      renderCodeOutput(content, String(error), true);
    } finally {
      runBtn.disabled = false;
    }
  });

  return runBtn;
}

/**
 * The output panel lives in the wrapper, below the code, exactly where the
 * editor puts it.
 */
function ensureCodeOutput(wrapper) {
  let output = wrapper.querySelector(':scope > .editor-code-output-container');
  if (output) {
    return output;
  }

  output = document.createElement('div');
  output.classList.add('editor-code-output-container', 'hidden');

  // Built element by element rather than from a template literal: the newlines
  // and indentation of a template become text nodes inside the panel, and the
  // leading one renders as an empty line above the header.
  const outputHeader = document.createElement('div');
  outputHeader.classList.add('editor-code-output-header');

  const outputTitle = document.createElement('span');
  outputTitle.textContent = translation?.Output || 'Output';
  outputHeader.appendChild(outputTitle);

  const outputContent = document.createElement('div');
  outputContent.classList.add('editor-code-output-content');

  output.appendChild(outputHeader);
  output.appendChild(outputContent);
  wrapper.appendChild(output);

  return output;
}

/**
 * Matplotlib plots reach this in one of two shapes, and both are rendered:
 *
 * - as URLs in `imageUrls`, which is what the chat code box gets. The server
 *   stores the plot as an attachment and sends the link, so no base64 travels
 *   through the response;
 * - as base64 inline in the text, which is what the create mode editor's own
 *   execution endpoint still returns. Those are lifted out of the text.
 */
const PNG_OUTPUT_REGEX = /(?:data:image\/png;base64,)?(iVBORw0KGgoAAAANSUhEUg[A-Za-z0-9+\/=]+)/g;

// The three shapes a program prints an SVG in: a base64 data URI, a plain data
// URI with the markup behind the comma, and the bare markup. Same order as the
// server side (SandboxImages), for the same reason: the plain data URI holds
// markup the bare pattern would otherwise catch, prefix left standing.
const SVG_BASE64_OUTPUT_REGEX = /data:image\/svg\+xml(?:;charset=[\w-]+)?;base64,([A-Za-z0-9+\/=]+)/gi;
const SVG_DATA_URI_OUTPUT_REGEX = /data:image\/svg\+xml(?:;charset=[\w-]+)?(?:;utf8)?,(%3C(?:svg|%3Fxml)[^\s"'`]*|(?:<\?xml[^>]*\?>\s*)?<svg\b[\s\S]*?<\/svg>)/gi;
const SVG_MARKUP_OUTPUT_REGEX = /(?:<\?xml[^>]*\?>\s*)?(?:<!DOCTYPE\s+svg[^>]*>\s*)?<svg\b[^>]*>[\s\S]*?<\/svg>/gi;

function liftSvgsOutOfOutput(text, images) {
  if (!/<svg|image\/svg\+xml/i.test(text)) {
    return text;
  }

  return text
    .replace(SVG_BASE64_OUTPUT_REGEX, (match, base64) => {
      images.push(`data:image/svg+xml;base64,${base64.replace(/\s/g, '')}`);
      return '';
    })
    .replace(SVG_DATA_URI_OUTPUT_REGEX, (match, markup) => {
      let decoded = markup;
      if (markup.startsWith('%')) {
        try {
          decoded = decodeURIComponent(markup);
        } catch (error) {
          return match;
        }
      }
      images.push(svgDataUri(decoded));
      return '';
    })
    .replace(SVG_MARKUP_OUTPUT_REGEX, (match) => {
      images.push(svgDataUri(match));
      return '';
    });
}

function renderCodeOutput(content, text, isError, imageUrls = []) {
  content.innerHTML = '';
  content.classList.toggle('error', !!isError);

  const inlineImages = [];
  const textOutput = liftSvgsOutOfOutput(String(text), inlineImages).replace(PNG_OUTPUT_REGEX, (match, base64) => {
    inlineImages.push(`data:image/png;base64,${base64.replace(/[\s\n\r]/g, '')}`);
    return '';
  });

  const sources = [...(Array.isArray(imageUrls) ? imageUrls : []), ...inlineImages];

  if (textOutput.trim()) {
    content.appendChild(document.createTextNode(textOutput.trim()));
  }

  sources.forEach((src) => {
    const img = document.createElement('img');
    img.src = src;
    content.appendChild(img);

    // The chat's download button; the create mode editor has no message_functions.
    if (typeof frameImageForDownload === 'function') {
      frameImageForDownload(img);
    }
  });

  if (!textOutput.trim() && sources.length === 0) {
    content.textContent = translation?.CodeNoOutput || 'Ran without output.';
  }
}

// Efficiently preprocess content: Handle math formulas, think blocks, and preserve HTML elements
function preprocessContent(content) {
  if (!content) return { processedContent: '', mathReplacements: [], thinkReplacements: [] };

  // RegEx patterns
  // Inline `$...$` follows the pandoc rule: no space right after the opening
  // `$`, no space right before the closing `$`, and no digit right after the
  // closing `$`. This keeps prices like "$5 and $10" out of math without
  // rejecting formulas that start with a digit, such as `$2 + 2 = 5$`.
  const mathRegex = /(\$\$[\s\S]+?\$\$|\$(?=\S)(?:\\.|[^$\\\n])+?(?<=\S)\$(?!\d)|\\\([\s\S]*?\\\)|\\\[[\s\S]*?\\\])/g;
  const thinkRegex = /<think>[\s\S]*?<\/think>/g;
  const codeBlockStartRegex = /^```/;

  const mathReplacements = [];
  const thinkReplacements = [];
  const result = [];

  let inCodeBlock = false;
  let currentSegment = '';

  // Process content by lines for better code block detection
  const lines = content.split('\n');

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const trimmedLine = line.trim();

    // Detect code block boundaries
    if (codeBlockStartRegex.test(trimmedLine)) {
      // Process current segment before entering/exiting code block
      if (!inCodeBlock && currentSegment) {
        result.push(processNonCodeSegment(currentSegment, mathRegex, thinkRegex, mathReplacements, thinkReplacements));
        currentSegment = '';
      } else if (inCodeBlock && currentSegment) {
        // For code blocks, just add as-is
        result.push(currentSegment);
        currentSegment = '';
      }

      // Add the code block marker
      result.push(line);
      inCodeBlock = !inCodeBlock;
      continue;
    }

    // Append line to current segment
    currentSegment += line + '\n';

    // Process at the end of the content
    if (i === lines.length - 1) {
      if (inCodeBlock) {
        // For code blocks, just add as-is
        result.push(currentSegment);
      } else {
        // For non-code, process with replacements
        result.push(processNonCodeSegment(currentSegment, mathRegex, thinkRegex, mathReplacements, thinkReplacements));
      }
    }
  }

  return {
    processedContent: result.join('\n'),
    mathReplacements,
    thinkReplacements,
  };
}

/**
 * SVG markup written straight into the answer. Markdown-it runs with html off, so
 * it would show as escaped source text; as a fenced svg block it gets the code
 * box and the picture under it. Only a drawing that stands on its own lines is
 * taken - an <svg> mentioned in prose is left alone - and only once it is
 * complete, so a streaming answer shows the text until the closing tag arrives.
 */
const RAW_SVG_REGEX = /^[ \t]*(?:<\?xml[^>]*\?>[ \t]*\r?\n?)?[ \t]*(?:<!DOCTYPE\s+svg[^>]*>[ \t]*\r?\n?)?[ \t]*<svg\b[\s\S]*?<\/svg>[ \t]*$/gim;

// The same for a draw.io document written straight into the answer.
const RAW_DRAWIO_REGEX = /^[ \t]*(?:<\?xml[^>]*\?>[ \t]*\r?\n?)?[ \t]*<(mxfile|mxGraphModel)\b[\s\S]*?<\/\1>[ \t]*$/gim;

function fenceRawSvg(segment) {
  let fenced = segment;

  if (/<svg/i.test(fenced)) {
    fenced = fenced.replace(RAW_SVG_REGEX, (match) => '\n```svg\n' + match.trim() + '\n```\n');
  }

  if (/<mx(?:file|GraphModel)\b/i.test(fenced)) {
    fenced = fenced.replace(RAW_DRAWIO_REGEX, (match) => '\n```drawio\n' + match.trim() + '\n```\n');
  }

  return fenced;
}

// Helper function to process non-code segments
function processNonCodeSegment(segment, mathRegex, thinkRegex, mathReplacements, thinkReplacements) {
  // Process math formulas first
  let processed = fenceRawSvg(segment).replace(mathRegex, (mathMatch) => {
    mathReplacements.push(mathMatch);
    return `%%%MATH${mathReplacements.length - 1}%%%`;
  });

  // Then process think blocks
  processed = processed.replace(thinkRegex, (thinkMatch) => {
      thinkReplacements.push(thinkMatch);
    return `%%%THINK${thinkReplacements.length - 1}%%%`;
  });

  return processed;
}

// Improved post-processing of content after Markdown rendering
function postprocessContent(content, mathReplacements, thinkReplacements) {
  if (!content) return '';

  try {
    // Replace math placeholders
    let processed = content.replace(/%%%MATH(\d+)%%%/g, (_, index) => {
      const idx = parseInt(index, 10);
      if (isNaN(idx) || idx >= mathReplacements.length) {
        console.warn(`Invalid math replacement index: ${index}`);
        return ''; // Return empty string for invalid indices
      }

      const rawMath = mathReplacements[idx];
      const isComplexFormula = rawMath.length > 10;

      if (isComplexFormula) {
        return `<div class="math" data-rawMath="${escapeHTML(rawMath)}" data-index="${idx}">${rawMath}</div>`;
      } else {
        return rawMath;
      }
    });

    // Replace think placeholders
    processed = processed.replace(/%%%THINK(\d+)%%%/g, (_, index) => {
      const idx = parseInt(index, 10);
      if (isNaN(idx) || idx >= thinkReplacements.length) {
        console.warn(`Invalid think replacement index: ${index}`);
        return ''; // Return empty string for invalid indices
      }

      try {
        const rawThinkContent = thinkReplacements[idx];
        // Remove <think> and </think>
        const thinkContent = rawThinkContent.slice(7, -8);

        const thinkTemp = document.getElementById('think-block-template');
        if (!thinkTemp) {
          console.error('Think block template not found');
          return `<div class="think"><div class="content">${escapeHTML(thinkContent.trim())}</div></div>`;
        }

        const thinkClone = thinkTemp.content.cloneNode(true);
        const thinkElement = thinkClone.querySelector('.think');
        thinkElement.querySelector('.content').innerText = thinkContent.trim();

        const tempContainer = document.createElement('div');
        tempContainer.appendChild(thinkElement);
        return tempContainer.innerHTML;
      } catch (error) {
        console.error('Error processing think block:', error);
        return ''; // Return empty string on error
      }
    });

    return processed;
  } catch (error) {
    console.error('Error in postprocessContent:', error);
    return content; // Return original content on error
  }
}

// Efficiently convert URLs to links while respecting excluded areas
function convertHyperlinksToLinks(text) {
  const container = document.createElement('div');
  container.innerHTML = text;

  const EXCLUDED_TAGS = ['a', 'pre', 'code'];
  const URL_REGEX = /https?:\/\/[^\s<>"'`]+/g;
  const PLACEHOLDER_REGEX = /%%HTML_PRESERVED_\d+%%/;

  // Process DOM tree to find and convert URLs to links
  function processTextNodes(node) {
    if (!node || !node.childNodes) return;

    // Use a separate array to avoid live collection issues during DOM modification
    const childNodes = Array.from(node.childNodes);

    for (const child of childNodes) {
      // Skip processing in excluded tags
      if (child.nodeType === Node.ELEMENT_NODE) {
        const tagName = child.nodeName.toLowerCase();
        if (!EXCLUDED_TAGS.includes(tagName)) {
          processTextNodes(child);
        }
        continue;
      }

      // Process text nodes containing URLs
      if (child.nodeType === Node.TEXT_NODE && child.nodeValue && child.nodeValue.match(URL_REGEX)) {
        // Skip text nodes containing preserved HTML
        if (PLACEHOLDER_REGEX.test(child.nodeValue)) continue;

        const fragment = document.createDocumentFragment();
        let lastIndex = 0;
        let match;

        // Create a new regex instance for each execution to avoid lastIndex issues
        const regex = new RegExp(URL_REGEX);

        while ((match = regex.exec(child.nodeValue)) !== null) {
          const url = match[0];
          const index = match.index;

          // Add text before the URL
          if (index > lastIndex) {
            fragment.appendChild(document.createTextNode(
              child.nodeValue.substring(lastIndex, index)
            ));
          }

          // Create link element
          const link = document.createElement('a');
          link.href = url;
          link.target = '_blank';
          link.rel = 'noopener noreferrer';
          link.textContent = url;
          fragment.appendChild(link);

          lastIndex = index + url.length;
        }

        // Add any remaining text
        if (lastIndex < child.nodeValue.length) {
          fragment.appendChild(document.createTextNode(
            child.nodeValue.substring(lastIndex)
          ));
        }

        // Replace the original text node with our processed fragment
        node.replaceChild(fragment, child);
      }
    }
  }

  processTextNodes(container);
  return container.innerHTML;
}

function escapeRegExp(string) {
  return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function formatMathFormulas(element) {
  renderMathInElement(element, {
    delimiters: [
      { left: '$$', right: '$$', display: true },
      { left: '$', right: '$', display: false },
      { left: '\\(', right: '\\)', display: false },
      { left: '\\[', right: '\\]', display: true },
    ],
    displayMode: true, // sets a global setting for display mode; use delimiters for specific mode handling
    ignoredClasses: ['ignore_Format'],
    throwOnError: true,
  });
}

function addGoogleRenderedContent(messageElement, groundingMetadata) {
  // Handle search suggestions/rendered content
  if (
    groundingMetadata &&
    typeof groundingMetadata === 'object' &&
    groundingMetadata.searchEntryPoint &&
    groundingMetadata.searchEntryPoint.renderedContent
  ) {
    const render = groundingMetadata.searchEntryPoint.renderedContent;
    // Extract the HTML Tag (Styles already defined in CSS file)
    const parser = new DOMParser();
    const doc = parser.parseFromString(render, 'text/html');
    const divElement = doc.querySelector('.container');

    if (divElement) {
      const chips = divElement.querySelectorAll('a');

      // Add unique IDs to each chip for citation linking
      const messageId = citationScopeOf(messageElement);
      chips.forEach((chip, index) => {
        chip.setAttribute('target', '_blank');
        const citationNum = index + 1;
        chip.id = `source${messageId}:${citationNum}`;
        // Don't modify chip classes - they are Google's own styling
      });

      // Create a new div wrapper with web-sources class for consistent styling
      let googleWrapper;
      if (!messageElement.querySelector('.google-search')) {
        googleWrapper = document.createElement('div');
        googleWrapper.classList.add('google-search', 'web-sources');

        // Add title as h3 using translation
        const title = document.createElement('h3');
        title.classList.add('sources-title');
        title.textContent = translation?.SearchSources || 'Quellen:';
        googleWrapper.appendChild(title);
      } else {
        googleWrapper = messageElement.querySelector('.google-search');
        // Clear existing content but keep the title
        const existingTitle = googleWrapper.querySelector('.sources-title');
        googleWrapper.innerHTML = '';
        if (existingTitle) {
          googleWrapper.appendChild(existingTitle);
        }
      }

      googleWrapper.appendChild(divElement);
      // Append the wrapper to the target element
      messageElement.querySelector('.message-content').appendChild(googleWrapper);

      // Initialize click handlers for inline citations (if any exist in the message)
      initializeInlineCitationHandlers(messageElement);
    }
  }
}

/**
 * Initialize click handlers for all inline-citation links in a message
 * This handles both Google and OpenAI/Anthropic citations
 * @param {HTMLElement} messageElement - The message element containing citations
 */
function initializeInlineCitationHandlers(messageElement) {
  const citationLinks = messageElement.querySelectorAll('.inline-citation');

  citationLinks.forEach(citationLink => {
    // Remove existing click handler if any (to avoid duplicates)
    const newCitationLink = citationLink.cloneNode(true);
    citationLink.parentNode.replaceChild(newCitationLink, citationLink);

    // Add click handler to highlight target source
    newCitationLink.addEventListener('click', (e) => {
      e.preventDefault();
      const targetId = newCitationLink.getAttribute('href').substring(1); // Remove #
      const targetElement = document.getElementById(targetId);

      if (targetElement) {
        // Remove existing highlights from all source links
        document.querySelectorAll('.source-link.highlighted').forEach(el => {
          el.classList.remove('highlighted');
        });

        // Determine which element to highlight - always the .source-link <a> element
        let elementToHighlight = targetElement;

        // If target is a .source-item (OpenAI), find the .source-link inside it
        if (targetElement.classList.contains('source-item')) {
          const sourceLink = targetElement.querySelector('.source-link');
          if (sourceLink) {
            elementToHighlight = sourceLink;
          }
        }

        // Scroll to the target (scroll to the li or a, doesn't matter)
        targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });

        // Highlight the source link
        elementToHighlight.classList.add('highlighted');

        // Remove highlight after animation
        setTimeout(() => {
          elementToHighlight.classList.remove('highlighted');
        }, 2000);
      }
    });
  });
}

/**
 * Add Responses API (OpenAI) web search citations to a message element as Search Sources
 * @param {HTMLElement} messageElement - The message element to add sources to
 * @param {Array} auxiliaries - Array of auxiliary data including citations
 */
function addResponsesCitations(messageElement, auxiliaries) {
  if (!auxiliaries || !Array.isArray(auxiliaries)) {
    return;
  }

  // Find responsesCitations auxiliary
  const citationsAux = auxiliaries.find(aux => aux.type === 'responsesCitations');

  if (!citationsAux || !citationsAux.content) {
    return;
  }

  try {
    const citationsData = JSON.parse(citationsAux.content);
    const citations = citationsData.citations;

    if (!citations || !Array.isArray(citations) || citations.length === 0) {
      return;
    }

    // Deduplicate citations by URL while maintaining index mapping
    const uniqueCitations = [];
    const indexMapping = {}; // Maps original index → new display position
    const seenUrls = new Map(); // Maps URL → display position

    citations.forEach((citation, originalIndex) => {
      // Validate citation object
      if (!citation || typeof citation !== 'object') {
        console.warn(`[RESPONSES CITATIONS] Invalid citation at index ${originalIndex}:`, citation);
        return;
      }

      // Ensure URL is a string
      const url = typeof citation.url === 'string' ? citation.url : String(citation.url || '');

      if (!url) {
        console.warn(`[RESPONSES CITATIONS] Citation without URL at index ${originalIndex}:`, citation);
        return;
      }

      // Check if URL already exists
      if (seenUrls.has(url)) {
        // Map this original index to the existing position
        const existingPosition = seenUrls.get(url);
        indexMapping[originalIndex] = existingPosition;
      } else {
        // New unique citation - add to list
        const newPosition = uniqueCitations.length;
        uniqueCitations.push({
          url: url,
          title: typeof citation.title === 'string' ? citation.title : String(citation.title || url),
          originalIndices: [originalIndex] // Track all original indices that map here
        });
        seenUrls.set(url, newPosition);
        indexMapping[originalIndex] = newPosition;
      }
    });


    // Remove any existing responses-sources container first
    const existingSources = messageElement.querySelector('.responses-sources');
    if (existingSources) {
      existingSources.remove();
    }

    // Create new sources container
    const sourcesContainer = document.createElement('div');
    sourcesContainer.classList.add('responses-sources', 'web-sources');

    // Add title as h3
    const title = document.createElement('h3');
    title.classList.add('sources-title');
    title.textContent = translation?.SearchSources || 'Quellen:';
    sourcesContainer.appendChild(title);

    // Add source list as ordered list
    const sourcesList = document.createElement('ol');
    sourcesList.classList.add('sources-list');

    uniqueCitations.forEach((citation, displayIndex) => {
      const listItem = document.createElement('li');
      listItem.classList.add('source-item');
      listItem.setAttribute('data-display-index', displayIndex); // New display position (0-based)

      // Remove UTM parameters from URL
      const cleanUrl = citation.url.replace(/[?&]utm_[^&]+/g, '').replace(/[?&]$/, '');

      const link = document.createElement('a');
      link.classList.add('source-link');
      link.href = cleanUrl; // Use cleaned URL for actual link
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.title = citation.title; // Keep title as tooltip

      // Create bold element for the URL (without https:// and UTM parameters)
      const boldUrl = document.createElement('strong');
      const displayUrl = cleanUrl.replace(/^https?:\/\//, '');
      boldUrl.textContent = displayUrl;
      link.appendChild(boldUrl);

      listItem.appendChild(link);
      sourcesList.appendChild(listItem);
    });

    sourcesContainer.appendChild(sourcesList);

    // Append to message content
    const messageContent = messageElement.querySelector('.message-content');
    if (messageContent) {
      // Add source anchors with IDs for inline citations to link to
      sourcesList.querySelectorAll('.source-item').forEach((item, index) => {
        const citationNum = index + 1;
        item.id = `source${citationScopeOf(messageElement)}:${citationNum}`;
      });

      messageContent.appendChild(sourcesContainer);

      // Replace HTML links with inline citations using the index mapping
      const msgTextElement = messageContent.querySelector('.message-text');
      if (msgTextElement) {
        const messageId = citationScopeOf(messageElement);

        // Replace all <a href> links with citation indices (using mapped indices)
        replaceHtmlLinksWithCitations(msgTextElement, citations, messageId, indexMapping);

        // Initialize click handlers for inline citations
        initializeInlineCitationHandlers(messageElement);
      }

    } else {
      console.error('[RESPONSES CITATIONS] No .message-content found in messageElement');
    }
  } catch (error) {
    console.error('Error parsing Responses citations:', error);
  }
}

/**
 * Add Anthropic web search sources to a message element
 * @param {HTMLElement} messageElement - The message element to add sources to
 * @param {Array} auxiliaries - Array of auxiliary data including citations
 */
function addAnthropicCitations(messageElement, auxiliaries) {
  if (!auxiliaries || !Array.isArray(auxiliaries)) {
    return;
  }

  // Find anthropicCitations auxiliary
  const citationsAux = auxiliaries.find(aux => aux.type === 'anthropicCitations');
  if (!citationsAux || !citationsAux.content) {
    return;
  }

  try {
    const citationsData = JSON.parse(citationsAux.content);
    const citations = citationsData.citations;

    if (!citations || !Array.isArray(citations) || citations.length === 0) {
      return;
    }

    // Create or get sources container
    let sourcesContainer;
    if (!messageElement.querySelector('.anthropic-sources')) {
      sourcesContainer = document.createElement('div');
      sourcesContainer.classList.add('anthropic-sources', 'web-sources');
    } else {
      sourcesContainer = messageElement.querySelector('.anthropic-sources');
      sourcesContainer.innerHTML = ''; // Clear existing
    }

    // Add title
    const title = document.createElement('div');
    title.classList.add('sources-title');
    title.textContent = 'Quellen:';
    sourcesContainer.appendChild(title);

    // Add source chips
    const chipsContainer = document.createElement('div');
    chipsContainer.classList.add('sources-chips');

    citations.forEach((citation, index) => {
      const chip = document.createElement('a');
      chip.classList.add('source-chip');
      chip.href = citation.url;
      chip.target = '_blank';
      chip.rel = 'noopener noreferrer';
      chip.title = citation.title;

      // Add chip number
      const number = document.createElement('span');
      number.classList.add('chip-number');
      number.textContent = index + 1;
      chip.appendChild(number);

      // Add chip title
      const titleSpan = document.createElement('span');
      titleSpan.classList.add('chip-title');
      titleSpan.textContent = citation.title;
      chip.appendChild(titleSpan);

      // Add page age if available
      if (citation.page_age) {
        const ageSpan = document.createElement('span');
        ageSpan.classList.add('chip-age');
        ageSpan.textContent = citation.page_age;
        chip.appendChild(ageSpan);
      }

      chipsContainer.appendChild(chip);
    });

    sourcesContainer.appendChild(chipsContainer);

    // Append to message content
    messageElement.querySelector('.message-content').appendChild(sourcesContainer);
  } catch (error) {
    console.error('Error parsing Anthropic citations:', error);
  }
}

/**
 * Add HAWKI tools web search sources to a message element.
 *
 * HAWKI runs the search itself for providers without a native one, so there are
 * no provider annotations to place the markers from. The model is asked to link
 * every borrowed statement instead, and those links become the inline markers
 * here: numbered superscripts like Google's grounding, pointing at a source
 * list drawn as numbered chips like Anthropic's.
 *
 * @param {HTMLElement} messageElement - The message element to add sources to
 * @param {Array} auxiliaries - Array of auxiliary data including citations
 */
function addHawkiToolsCitations(messageElement, auxiliaries) {
  if (!auxiliaries || !Array.isArray(auxiliaries)) {
    return;
  }

  const citationsAux = auxiliaries.find(aux => aux.type === 'hawkiToolsCitations');
  if (!citationsAux || !citationsAux.content) {
    return;
  }

  try {
    const citationsData = JSON.parse(citationsAux.content);
    const citations = citationsData.citations;

    if (!citations || !Array.isArray(citations) || citations.length === 0) {
      return;
    }

    // A page read in several rounds arrives once per round; the list numbers
    // each page once, and every original index maps onto that one position.
    const uniqueCitations = [];
    const indexMapping = {};
    const seenUrls = new Map();

    citations.forEach((citation, originalIndex) => {
      if (!citation || typeof citation !== 'object') {
        return;
      }

      const url = typeof citation.url === 'string' ? citation.url : String(citation.url || '');
      if (!url) {
        return;
      }

      if (seenUrls.has(url)) {
        indexMapping[originalIndex] = seenUrls.get(url);
        return;
      }

      const position = uniqueCitations.length;
      uniqueCitations.push({
        url: url,
        title: typeof citation.title === 'string' && citation.title ? citation.title : url
      });
      seenUrls.set(url, position);
      indexMapping[originalIndex] = position;
    });

    if (uniqueCitations.length === 0) {
      return;
    }

    const messageContent = messageElement.querySelector('.message-content');
    if (!messageContent) {
      console.error('[HAWKI TOOLS CITATIONS] No .message-content found in messageElement');
      return;
    }

    // Rebuilt rather than appended to: a re-render of the same message would
    // otherwise stack a second list under the first.
    const existing = messageContent.querySelector('.hawki-sources');
    if (existing) {
      existing.remove();
    }

    const sourcesContainer = document.createElement('div');
    sourcesContainer.classList.add('hawki-sources', 'web-sources');

    const chipsContainer = document.createElement('div');
    chipsContainer.classList.add('sources-chips');

    const messageId = citationScopeOf(messageElement);

    uniqueCitations.forEach((citation, index) => {
      const cleanUrl = citation.url.replace(/[?&]utm_[^&]+/g, '').replace(/[?&]$/, '');

      // Also a .source-link, which is what the inline marker's click handler
      // highlights and clears.
      const chip = document.createElement('a');
      chip.classList.add('source-chip', 'source-link');
      chip.id = `source${messageId}:${index + 1}`;
      chip.href = cleanUrl;
      chip.target = '_blank';
      chip.rel = 'noopener noreferrer';
      chip.title = citation.title;

      const number = document.createElement('span');
      number.classList.add('chip-number');
      number.textContent = index + 1;
      chip.appendChild(number);

      const chipTitle = document.createElement('span');
      chipTitle.classList.add('chip-title');
      chipTitle.textContent = citation.title;
      chip.appendChild(chipTitle);

      chipsContainer.appendChild(chip);
    });

    sourcesContainer.appendChild(chipsContainer);
    messageContent.appendChild(sourcesContainer);

    // Turn the links the model wrote into the numbered superscripts. Done after
    // the chips exist, so every marker has an anchor to scroll to.
    const msgTextElement = messageContent.querySelector('.message-text');
    if (msgTextElement) {
      replaceHtmlLinksWithCitations(msgTextElement, citations, messageId, indexMapping);
      stripModelWrittenMarkers(msgTextElement, uniqueCitations.length);
      stripModelWrittenSourceList(msgTextElement);
      initializeInlineCitationHandlers(messageElement);
    }
  } catch (error) {
    console.error('Error parsing HAWKI tools citations:', error);
  }
}

// Temporary storage for HTML elements to preserve
const preservedHTML = [];
function formatGoogleCitations(content, groundingMetadata = '') {
  preservedHTML.length = 0;

  // Split the content on triple backtick code blocks
  const codeBlockRegex = /```[\s\S]*?```/g;
  let segments = [];
  let lastIndex = 0;
  let match;

  while ((match = codeBlockRegex.exec(content)) !== null) {
    // Text before the code block
    if (match.index > lastIndex) {
      segments.push({ type: 'text', value: content.slice(lastIndex, match.index) });
    }
    // The code block itself
    segments.push({ type: 'code', value: match[0] });
    lastIndex = codeBlockRegex.lastIndex;
  }

  // Remaining content after the last code block
  if (lastIndex < content.length) {
    segments.push({ type: 'text', value: content.slice(lastIndex) });
  }

  // Process text segments only
  segments = segments.map((segment) => {
    if (segment.type === 'code') {
      return segment.value; // skip processing inside code block
    }

    let text = segment.value;

    randomId = "";
    randomId = Math.random().toString(36).substring(2, 15);

    // Insert footnotes
    if (groundingMetadata?.groundingSupports?.length) {
      groundingMetadata.groundingSupports.forEach((support) => {
        const segmentText = support.segment?.text || '';
        const indices = support.groundingChunkIndices;

        if (segmentText && Array.isArray(indices) && indices.length) {
          // Create footnote reference HTML
          const footnotesRef =
            `<sup><span>` +
            indices
              .map(
                (idx) =>
                  `<a class="inline-citation" href="#source${randomId}:${idx + 1}">${idx + 1}</a>`
              )
              .join(', ') +
            `</span></sup>\n`;

          // Store the HTML in our preservation array
          const id = preservedHTML.length;
          preservedHTML.push(footnotesRef);

          // Replace text with placeholder.
          //
          // A grounded segment stops before the full stop that closes it, so
          // the marker would land between the last word and the '.'. Taking any
          // punctuation that follows into the match puts it after, the same
          // place the HAWKI tools path puts it.
          const escapedText = escapeRegExp(segmentText);
          const trailingPunctuation =
            '(?:[)\\]"\'\u201c\u201d\u2018\u2019]+[.,;:!?\u2026]*|[.,;:!?\u2026]+[)\\]"\'\u201c\u201d\u2018\u2019]*)?';

          text = text.replace(new RegExp(escapedText + trailingPunctuation, 'g'), (match) =>
            match + `%%HTML_PRESERVED_${id}%%`
          );
        }
      });
    }

    // Additional HTML preservation for any other HTML that might be in the text
    const htmlPattern = /<sup>.*?<\/sup>|<a\s+.*?<\/a>/g;
    text = text.replace(htmlPattern, (match) => {
      const id = preservedHTML.length;
      preservedHTML.push(match);
      return `%%HTML_PRESERVED_${id}%%`;
    });

    return text;
  });

  let processedContent = segments.join('');

  // Add sources if available
  if (groundingMetadata?.groundingChunks?.length) {
    const sourcesTitle = translation?.SearchSources || 'Quellen:';
    let sourcesMarkdown = `\n\n### ${sourcesTitle}\n`;
    const initialMarkdown = sourcesMarkdown;

    groundingMetadata.groundingChunks.forEach((chunk, index) => {
      if (chunk.web?.uri && chunk.web?.title) {
        const sourceLink = `${index + 1}. <a id="source${randomId}:${index + 1}" href="${chunk.web.uri}" target="_blank" class="source-link"><b>${chunk.web.title}</b></a>\n`;
        const id = preservedHTML.length;
        preservedHTML.push(sourceLink);
        sourcesMarkdown += `%%HTML_PRESERVED_${id}%%`;
      }
    });

    if (sourcesMarkdown !== initialMarkdown) {
      processedContent += sourcesMarkdown;
    }
  }

  return processedContent;
}

// Restore the preserved HTML after markdown processing
function restoreGoogleCitations(content) {
  let result = content;
  for (let i = 0; i < preservedHTML.length; i++) {
    const placeholder = new RegExp(`%%HTML_PRESERVED_${i}%%`, 'g');
    result = result.replace(placeholder, preservedHTML[i]);
  }
  return result;
}

/**
 * Insert a status item in the correct sorted order
 * Persistent items (reasoning summaries, web search queries) are sorted by sort-index
 * Temporary items (reasoning in-progress, web_search in-progress) go to the end
 * @param {HTMLElement} statusIndicator - The status indicator container
 * @param {HTMLElement} newItem - The new item to insert
 */
function insertStatusItemInOrder(statusIndicator, newItem) {
  const isPersistent = newItem.getAttribute('data-persistent') === 'true';
  const sortIndex = parseInt(newItem.getAttribute('data-sort-index'), 10);

  if (!isPersistent) {
    // Temporary items go to the end
    statusIndicator.appendChild(newItem);
    return;
  }

  // Get all persistent items
  const persistentItems = Array.from(statusIndicator.querySelectorAll('[data-persistent="true"]'));

  // Find the correct position to insert
  let inserted = false;
  for (const item of persistentItems) {
    const itemSortIndex = parseInt(item.getAttribute('data-sort-index'), 10);
    if (sortIndex < itemSortIndex) {
      statusIndicator.insertBefore(newItem, item);
      inserted = true;
      break;
    }
  }

  if (!inserted) {
    // Insert before the first temporary item, or at the end if no temporary items
    const firstTempItem = statusIndicator.querySelector('[data-persistent]:not([data-persistent="true"])');
    if (firstTempItem) {
      statusIndicator.insertBefore(newItem, firstTempItem);
    } else {
      statusIndicator.appendChild(newItem);
    }
  }
}

/**
 * Update AI status indicator for streaming responses (NEW SYSTEM)
 * Shows collapsible status log with current status always visible
 * @param {HTMLElement} messageElement - The message element to add status to
 * @param {Array} auxiliaries - Array of auxiliary data including status updates
 * @param {boolean} isDone - Whether the stream is complete
 */
function updateAiStatusIndicator(messageElement, auxiliaries, isDone = false) {
  // First, try to restore status log from auxiliaries (ONLY for messages loaded from DB)
  // During streaming, we build the log incrementally via status auxiliaries
  const hasEmptyStatusLog = !messageElement.dataset.statusLog || messageElement.dataset.statusLog === '{"steps":[],"currentStep":0}';
  const statusLogAux = auxiliaries?.find(aux => aux.type === 'status_log');
  
  // Only restore from status_log if we have NO existing log (DB load case)
  if (hasEmptyStatusLog && statusLogAux && statusLogAux.content) {
    try {
      const logData = JSON.parse(statusLogAux.content);
      
      // Reconstruct status log from persisted data
      const statusLog = {
        steps: [],
        currentStep: 0
      };
      
      // Convert persisted log entries to status log steps
      if (logData.log && Array.isArray(logData.log)) {
        logData.log.forEach((entry, index) => {
          const step = {
            step: index + 1,
            output_index: entry.output_index ?? null,
            status: entry.status,
            type: entry.type,
            // Derive label if message is null/empty, otherwise use message
            label: entry.message || getStatusLabel(entry.status, entry.type, null, null),
            icon: getStatusIcon(entry.status, entry.type), // Pass type for correct icon
            timestamp: entry.timestamp
          };
          
          // Add reasoning summary details if available
          if (entry.summary) {
            step.details = {
              content: entry.summary
            };
          }
          
          statusLog.steps.push(step);
        });
        
        // Check if log ended with error/cancellation
        const hasErrorOrCancelled = statusLog.steps.some(s => 
          s.status === 'error' || s.status === 'cancelled'
        );
        
        // If error/cancelled, mark all in_progress steps as incomplete
        if (hasErrorOrCancelled) {
          statusLog.steps.forEach(step => {
            if (step.status === 'in_progress') {
              step.status = 'incomplete';
            }
          });
        }
        
        statusLog.currentStep = statusLog.steps.length;
        messageElement.dataset.statusLog = JSON.stringify(statusLog);
        
        // Render the restored status indicator
        if (statusLog.steps.length > 0) {
          renderStatusIndicator(messageElement);
        }
        }
      } catch (error) {
        console.error('[STATUS LOG] Error restoring from auxiliaries:', error);
      }
    }

  // Process auxiliaries BEFORE isDone logic (so final status auxiliary is processed)
  if (auxiliaries && Array.isArray(auxiliaries)) {

    // Check if we have a persisted status_log (from DB load)
    const hasPersistedLog = auxiliaries.some(aux => aux.type === 'status_log');

    // Process status auxiliary for generic status updates
    // SKIP if we already have a persisted log (avoid duplicates)
    if (!hasPersistedLog) {
      const statusAux = auxiliaries.find(aux => aux.type === 'status');
      if (statusAux && statusAux.content) {
        try {
          const statusData = JSON.parse(statusAux.content);
          const { status, type: backendType, message, query, output_index } = statusData;
          
          
          // Use type from backend if provided, otherwise derive from status
          // Backend now sends explicit type for disambiguation (e.g., "completed" + "reasoning")
          const type = backendType || getStatusType(status);
          const normalizedStatus = status.includes('complete') ? 'completed' : 'in_progress';
          
          // Check if this exact status update already exists in the log
          // This prevents duplicates when auxiliary is processed multiple times during streaming
          const statusLog = JSON.parse(messageElement.dataset.statusLog || '{"steps":[],"currentStep":0}');
          const alreadyExists = statusLog.steps.some(step =>
            step.type === type &&
            step.status === normalizedStatus &&
            step.output_index === (output_index ?? null)
          );
          
          if (!alreadyExists) {
            const statusUpdate = {
              output_index: output_index ?? null,
              status: normalizedStatus,
              type: type,
              label: getStatusLabel(normalizedStatus, type, message, query),
              icon: getStatusIcon(normalizedStatus, type), // Pass type for correct icon
              timestamp: Date.now()
            };
            
            updateStatusLog(messageElement, statusUpdate);
          }
        } catch (error) {
          console.error('[STATUS] Error parsing status:', error);
        }
      } else {
      }
    } else {
    }
  }

  // If stream is done, finalize the log
  if (isDone) {
    const statusLog = JSON.parse(messageElement.dataset.statusLog || '{"steps":[],"currentStep":0}');


    if (statusLog.steps.length > 0) {
      // Check if stream ended with error or cancellation
      const hasErrorOrCancelled = statusLog.steps.some(s =>
        s.status === 'error' || s.status === 'cancelled'
      );

      // If error/cancelled, mark all in_progress steps as incomplete
      if (hasErrorOrCancelled) {
        statusLog.steps.forEach(step => {
          if (step.status === 'in_progress') {
            step.status = 'incomplete';
          }
        });
        // Save updated log
        messageElement.dataset.statusLog = JSON.stringify(statusLog);
      }

      // Check if we already have a "processing completed" step (from status auxiliary)
      const hasCompletedStep = statusLog.steps.some(s =>
        s.type === 'processing' && s.status === 'completed'
      );


      // Only add "processing completed" if no error/cancellation AND not already present
      if (!hasErrorOrCancelled && !hasCompletedStep) {
        updateStatusLog(messageElement, {
          output_index: null,
          status: 'completed',
          type: 'processing',
          label: translation?.Status_Completed || 'Processing completed',
          icon: 'check2-circle',
          timestamp: Date.now()
        });
      } else {
        // Re-render to update UI with incomplete steps
        renderStatusIndicator(messageElement);
      }

    }
    return;
  }

  // Process reasoning summary items - add as details to completed reasoning steps
  // ONLY for streaming (non-streaming already has summaries in status_log)
  const hasPersistedLog = auxiliaries.some(aux => aux.type === 'status_log');
  const reasoningSummaryItems = auxiliaries.filter(aux => aux.type === 'reasoning_summary_item');

  if (reasoningSummaryItems.length > 0 && !hasPersistedLog) {
    // Only process reasoning_summary_item for streaming (no persisted log)
    reasoningSummaryItems.forEach(summaryAux => {
      try {
        const summaryData = JSON.parse(summaryAux.content);
        const { index, title, summary, output_index } = summaryData;


        // Find and update the reasoning step with this output_index
        const statusLog = JSON.parse(messageElement.dataset.statusLog || '{"steps":[],"currentStep":0}');
        const reasoningStep = statusLog.steps.find(s =>
          s.type === 'reasoning' && s.output_index === output_index
        );

        if (reasoningStep) {
          // Update existing reasoning step with summary details
          reasoningStep.status = 'completed';
          reasoningStep.label = title; // Use title directly, not fallback text
          reasoningStep.icon = 'reasoning'; // Keep reasoning icon (CPU)
          reasoningStep.details = {
            content: summary
          };

          messageElement.dataset.statusLog = JSON.stringify(statusLog);

          // Only re-render if this is NOT the current active step with spinner
          // to avoid spinner flickering during updates
          const currentStepIndex = statusLog.currentStep - 1;
          const isCurrentStep = statusLog.steps[currentStepIndex] === reasoningStep;

          if (!isCurrentStep) {
            // Not the current active step, safe to re-render
            renderStatusIndicator(messageElement);
          } else {
            // This is the current step - just update the details without full re-render
            const container = messageElement.querySelector('.ai-status-indicator');
            if (container) {
              // Update the log item details without re-rendering the whole indicator
              const logItem = container.querySelector(`.status-log-item[data-step="${reasoningStep.step}"]`);
              if (logItem && logItem.tagName === 'DETAILS') {
                const contentDiv = logItem.querySelector('.reasoning-summary-content');
                if (contentDiv) {
                  contentDiv.textContent = summary;
                }
              }
            }
          }

        } else {
          // Create new reasoning completed step if no in-progress step exists
          updateStatusLog(messageElement, {
            output_index: output_index,
            status: 'completed',
            type: 'reasoning',
            label: title, // Use title directly
            icon: 'reasoning', // Keep reasoning icon (CPU)
            details: {
              content: summary
            },
            timestamp: Date.now()
          });

        }
      } catch (error) {
        console.error('[REASONING SUMMARY] Error parsing summary item:', error);
      }
    });
  }

  // Process web search query items - add as completed web_search steps
  const webSearchQueryItems = auxiliaries.filter(aux => aux.type === 'web_search_query');
  if (webSearchQueryItems.length > 0) {

    webSearchQueryItems.forEach(searchAux => {
      try {
        const searchData = JSON.parse(searchAux.content);
        const { index, query, output_index } = searchData;

        // Ensure query is a string
        const queryString = typeof query === 'string' ? query : (query?.query || JSON.stringify(query));


        // Find and update the web_search step with this output_index
        const statusLog = JSON.parse(messageElement.dataset.statusLog || '{"steps":[],"currentStep":0}');
        const webSearchStep = statusLog.steps.find(s =>
          s.type === 'web_search' && s.output_index === output_index
        );

        if (webSearchStep) {
          // Update existing web_search step with query
          webSearchStep.status = 'completed';
          webSearchStep.label = (translation?.Status_WebSearchComplete || 'Searched for: {query}').replace('{query}', queryString);
          webSearchStep.icon = 'search'; // Keep globe icon for web search

          messageElement.dataset.statusLog = JSON.stringify(statusLog);
          renderStatusIndicator(messageElement);

        } else {
          // Create new web_search completed step
          updateStatusLog(messageElement, {
            output_index: output_index,
            status: 'completed',
            type: 'web_search',
            label: (translation?.Status_WebSearchComplete || 'Searched for: {query}').replace('{query}', queryString),
            icon: 'search', // Globe icon for web search
            timestamp: Date.now()
          });

        }
      } catch (error) {
        console.error('[WEB SEARCH] Error parsing query item:', error);
      }
    });
  }

  // Ignore preview events entirely: the stream should only ever render filesystem URLs.

  // Process generated image items - replace previews with final image
  const generatedImageItems = auxiliaries.filter(aux => aux.type === 'generated_image');
  if (generatedImageItems.length > 0) {
    generatedImageItems.forEach(imageAux => {
      try {
        const imageData = JSON.parse(imageAux.content);
        const { output_index, url, uuid, prompt, mime, name, inline } = imageData;

        // A code interpreter plot is already in the message text, as markdown at
        // the point the code ran. The container below is inserted before
        // .message-content, so building one as well would put a second copy of the
        // picture above the code that drew it. The auxiliary is still needed - it
        // is what links the stored file to the message.
        if (inline === true) {
          rememberInlinePlot(messageElement, url, output_index);
          return;
        }

        // Find image generation container for this output_index
        let imageContainer = messageElement.querySelector(`.image-generation-container[data-output-index="${output_index}"]`);

        if (!imageContainer) {
          // Create new container if previews weren't shown
          imageContainer = document.createElement('div');
          imageContainer.classList.add('image-generation-container');
          imageContainer.setAttribute('data-output-index', output_index);

          // Insert before the message content or at the end
          const contentArea = messageElement.querySelector('.message-content, .ai-response-content');
          if (contentArea) {
            contentArea.parentNode.insertBefore(imageContainer, contentArea);
          } else {
            messageElement.appendChild(imageContainer);
          }
        }

        // Replace preview grid with final image. The frame hugs the picture so the
        // download button lands on it instead of next to it.
        imageContainer.innerHTML = `
          <div class="generated-image-wrapper">
            <div class="generated-image-frame">
              <img src="${url}" alt="${prompt}" class="generated-image" data-uuid="${uuid}"
                   data-mime="${mime || 'image/png'}" data-name="${name || 'generated_image.png'}">
            </div>
            ${prompt ? `<div class="image-prompt">${prompt}</div>` : ''}
          </div>
        `;

        addImageDownloadButton(imageContainer.querySelector('.generated-image-frame'));

        // The newest generated image is offered as a preselected attachment for
        // the next message. Nothing is sent as context automatically any more.
        preselectGeneratedImage(imageContainer.querySelector('img.generated-image'));

        // Store UUID for potential attachment linking
        imageContainer.setAttribute('data-image-uuid', uuid);

      } catch (error) {
        console.error('[GENERATED IMAGE] Error processing image:', error);
      }
    });

    syncInlinePlots(messageElement);
  }

  // Files the code interpreter wrote and HAWKI fetched out of the container. The
  // model links them as sandbox:/mnt/data/<filename>; the link is resolved by name.
  const containerFileItems = auxiliaries.filter(aux => aux.type === 'container_file');
  if (containerFileItems.length > 0) {
    containerFileItems.forEach(fileAux => {
      try {
        const { filename, url, name } = JSON.parse(fileAux.content);
        rememberContainerFile(messageElement, filename || name, url);
      } catch (error) {
        console.error('[CONTAINER FILE] Error processing file auxiliary:', error);
      }
    });

    syncInlinePlots(messageElement);
  }

  // Legacy: Handle old combined reasoning summary format (for backwards compatibility)
  const reasoningSummaryAux = auxiliaries.find(aux => aux.type === 'reasoning_summary');
  if (reasoningSummaryAux && reasoningSummaryAux.content) {
    try {
      const summaryData = JSON.parse(reasoningSummaryAux.content);
      const summary = summaryData.summary;
      const outputIndex = summaryData.output_index ?? null;  // Extract output_index from auxiliary
      
      if (summary) {
        // Check if we already added this reasoning summary to status log
        // This prevents duplicates when auxiliary is processed multiple times
        const statusLog = JSON.parse(messageElement.dataset.statusLog || '{"steps":[],"currentStep":0}');
        const alreadyExists = statusLog.steps.some(step => 
          step.type === 'reasoning' && 
          step.status === 'completed' &&
          step.output_index === outputIndex &&  // Match by output_index for multi-output support
          step.details?.content === summary
        );
        
        if (!alreadyExists) {
          // Add as generic reasoning completed step
          updateStatusLog(messageElement, {
            output_index: outputIndex,  // Use output_index from auxiliary
            status: 'completed',
            type: 'reasoning',
            label: translation?.Status_ReasoningComplete || 'Reasoning completed',
            icon: getStatusIcon('completed', 'reasoning'),  // Use getStatusIcon for correct icon (CPU/reasoning)
            details: {
              title: 'Reasoning Summary',
              content: summary
            },
            timestamp: Date.now()
          });
        }
      }
    } catch (error) {
      console.error('[REASONING SUMMARY LEGACY] Error parsing summary:', error);
    }
  }
}

/**
 * Update the global Response status (in_progress / completed)
 * This status shows the overall processing state of the response
 */
function updateResponseStatus(statusIndicator, status, message) {
  const statusType = 'response';

  // Create localized status content
  let displayMessage = message;
  if (status === 'in_progress') {
    displayMessage = translation?.Status_Processing || 'Processing...';
  } else if (status === 'completed') {
    displayMessage = translation?.Status_Completed || 'Processing completed';
  }

  // Get icon for status
  let icon = '';
  let isComplete = false;
  if (status === 'in_progress') {
    icon = '<svg class="status-icon loading-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10" stroke-width="2"/></svg>';
  } else if (status === 'completed') {
    icon = '<svg class="status-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    isComplete = true;
  }

  // Check if we already have a response status item
  let statusItem = statusIndicator.querySelector(`[data-status-category="response"]`);

  if (!statusItem) {
    // Create new response status item
    statusItem = document.createElement('div');
    statusItem.classList.add('ai-status-item');
    statusItem.setAttribute('data-status-category', 'response');
    statusItem.setAttribute('data-status-type', statusType);

    // Response status goes at the end (after all persistent items)
    statusIndicator.appendChild(statusItem);
  } else {
    // If status is 'completed', move it to the end (after all other items)
    if (status === 'completed') {
      statusIndicator.appendChild(statusItem);
    }
    // Otherwise keep it at its current position
  }

  // Update status item classes
  statusItem.className = 'ai-status-item';
  statusItem.classList.add(`status-${status}`);
  if (isComplete) {
    statusItem.classList.add('status-complete');
  }
  statusItem.setAttribute('data-status-category', 'response');
  statusItem.setAttribute('data-status-type', statusType);

  // Update status item content
  statusItem.innerHTML = `${icon}<span class="status-text">${displayMessage}</span>`;
}

/**
 * Update Model status (reasoning / web_search / reasoning_complete / web_search_complete)
 * These are temporary status indicators that show current model activity
 * Each reasoning/web_search item is identified by its output_index to allow multiple concurrent items
 */
function updateModelStatus(statusIndicator, status, message, query, outputIndex) {
  // Determine base status type (reasoning or web_search)
  const baseStatus = status.replace('_complete', '');
  const isComplete = status.includes('_complete');

  // Determine the correct category for this status
  const statusCategory = (baseStatus === 'web_search' || baseStatus === 'web_search_complete') ? 'web_search' : 'reasoning';

  // Build unique selector using output_index and correct category
  const selector = outputIndex !== undefined
    ? `[data-status-category="${statusCategory}"][data-status-type="${baseStatus}"][data-output-index="${outputIndex}"]`
    : `[data-status-category="${statusCategory}"][data-status-type="${baseStatus}"]`;

  // Find existing status item
  let statusItem = statusIndicator.querySelector(selector);


  // If this is a complete event
  if (isComplete) {
    if (statusItem) {
      // Special handling for reasoning_complete and web_search_complete without query/summary
      // These temporary items should be removed if no persistent content will replace them
      if (status === 'web_search_complete' && !query) {
        statusItem.remove();
        return;
      }

      if (status === 'reasoning_complete') {
        // For reasoning, we always remove the temporary item
        // It will be replaced by a summary (if available) or just disappear
        statusItem.remove();
        return;
      }

      // For other complete events, mark as complete with checkmark

      // Update to completed state
      statusItem.classList.remove('status-reasoning', 'status-web_search');
      statusItem.classList.add(`status-${status}`, 'status-complete');

      // Update icon to checkmark
      const icon = '<svg class="status-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

      // Update message
      let completedMessage = message;
      if (query) {
        const template = translation?.Status_WebSearchComplete || 'Searched for: {query}';
        completedMessage = template.replace('{query}', query);
      }

      statusItem.innerHTML = `${icon}<span class="status-text">${completedMessage}</span>`;
    } else {
    }
    return;
  }

  // Create localized status content for in-progress status
  let displayMessage = message;
  if (status === 'reasoning') {
    displayMessage = translation?.Status_Reasoning || 'Model is reasoning...';
  } else if (status === 'web_search') {
    displayMessage = translation?.Status_WebSearch || 'Searching the web...';
  }

  // Get icon for in-progress status
  let icon = '';
  if (baseStatus === 'reasoning') {
    icon = '<svg class="status-icon loading-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg>';
  } else if (baseStatus === 'web_search') {
    icon = '<svg class="status-icon loading-sm" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="11" cy="11" r="8" stroke-width="2"/><path d="M21 21l-4.35-4.35" stroke-width="2" stroke-linecap="round"/></svg>';
  }

  // Check if we already have a status item for this specific output_index
  if (!statusItem) {
    // Create new status item
    statusItem = document.createElement('div');
    statusItem.classList.add('ai-status-item');
    statusItem.setAttribute('data-status-category', statusCategory);
    statusItem.setAttribute('data-status-type', baseStatus);
    if (outputIndex !== undefined) {
      statusItem.setAttribute('data-output-index', outputIndex);
    }

    // Status items are persistent like a log - add in order by output_index
    // Find correct position based on output_index to maintain chronological order
    if (outputIndex !== undefined) {
      let inserted = false;
      const allItems = statusIndicator.querySelectorAll('[data-output-index], [data-sort-index]');

      for (const existingItem of allItems) {
        const existingOutputIndex = parseInt(existingItem.getAttribute('data-output-index')) ||
                                    parseInt(existingItem.getAttribute('data-sort-index')) ||
                                    999;

        if (outputIndex < existingOutputIndex) {
          statusIndicator.insertBefore(statusItem, existingItem);
          inserted = true;
          break;
        }
      }

      if (!inserted) {
        statusIndicator.appendChild(statusItem);
      }
    } else {
      statusIndicator.appendChild(statusItem);
    }

  } else {
    // Item exists - DO NOT MOVE IT! Just update content
    // Status items are like a log - they stay in their position
  }

  // Update status item classes (but don't change position)
  statusItem.className = 'ai-status-item';
  statusItem.classList.add(`status-${status}`);
  statusItem.setAttribute('data-status-category', statusCategory);
  statusItem.setAttribute('data-status-type', baseStatus);
  if (outputIndex !== undefined) {
    statusItem.setAttribute('data-output-index', outputIndex);
  }

  // Update status item content
  statusItem.innerHTML = `${icon}<span class="status-text">${displayMessage}</span>`;
}

/**
 * ===== NEW STATUS LOG SYSTEM =====
 * Update status log - add or update a step in the collapsible log
 * @param {HTMLElement} messageElement - The message element
 * @param {Object} statusUpdate - Status update object with step info
 */
function updateStatusLog(messageElement, statusUpdate) {
  // Get or create status log
  let statusLog = JSON.parse(messageElement.dataset.statusLog || '{"steps":[],"currentStep":0}');

  // Special handling for "processing" type:
  // - "processing" with "in_progress" is the start (only add once!)
  // - "processing" with "completed" is the end (add as new step)
  // For other types (reasoning, web_search), update existing in_progress steps

  let existingStep = null;

  if (statusUpdate.type === 'processing') {
    if (statusUpdate.status === 'in_progress') {
      // Check if we already have a "processing in_progress" step
      existingStep = statusLog.steps.find(s =>
        s.type === 'processing' && s.status === 'in_progress'
      );
      // If found, don't add duplicate - just skip
      if (existingStep) {
        return; // Don't add duplicate
      }
    }
    // For "processing completed", always add new step
    existingStep = null; // Force new step
  } else if (statusUpdate.type === 'completed') {
    // For generic "completed" type, always add new step
    existingStep = null; // Force new step
  } else {
    // For tool activities (reasoning, web_search): Update existing step
    // Find by output_index (for completed steps) OR by type + in_progress status
    existingStep = statusLog.steps.find(s => {
      // Match by output_index if available
      if (statusUpdate.output_index !== null && s.output_index === statusUpdate.output_index && s.type === statusUpdate.type) {
        return true;
      }
      // Match by type + in_progress status as fallback
      if (statusUpdate.output_index === null && s.type === statusUpdate.type && s.status === 'in_progress') {
        return true;
      }
      return false;
    });
  }

  if (existingStep) {
    // Update existing step (only for tool activities)
    Object.assign(existingStep, statusUpdate);
  } else {
    // Add new step
    statusUpdate.step = statusLog.steps.length + 1;
    statusUpdate.timestamp = Date.now();
    statusLog.steps.push(statusUpdate);
    statusLog.currentStep = statusUpdate.step;
  }

  // Save back to dataset
  messageElement.dataset.statusLog = JSON.stringify(statusLog);

  // Re-render status indicator
  renderStatusIndicator(messageElement);
}

/**
 * Render status indicator (current status + collapsible log)
 * @param {HTMLElement} messageElement - The message element
 */
function renderStatusIndicator(messageElement) {
  const statusLog = JSON.parse(messageElement.dataset.statusLog || '{"steps":[],"currentStep":0}');

  if (statusLog.steps.length === 0) return;

  // Get or create container
  let container = messageElement.querySelector('.ai-status-indicator');
  if (!container) {
    container = document.createElement('div');
    container.classList.add('ai-status-indicator');
    container.dataset.expanded = 'false';

    const messageWrapper = messageElement.querySelector('.message-wrapper');
    const messageHeader = messageWrapper?.querySelector('.message-header');
    if (messageHeader?.nextSibling) {
      messageWrapper.insertBefore(container, messageHeader.nextSibling);
    } else if (messageWrapper) {
      messageWrapper.appendChild(container);
    }
  }

  // Get current step
  const currentStep = statusLog.steps[statusLog.currentStep - 1];
  if (!currentStep) return;

  // Render current status (or reuse existing)
  let currentStatus = container.querySelector('.status-current');
  if (!currentStatus) {
    currentStatus = document.createElement('div');
    currentStatus.className = 'status-current';
    currentStatus.onclick = () => toggleStatusLog(messageElement);
  }

  // Add spinner ONLY for tool activities (reasoning, web_search) that are still in progress
  const showSpinner = currentStep.status === 'in_progress' &&
                      (currentStep.type === 'reasoning' || currentStep.type === 'web_search');
  const spinnerHtml = showSpinner
    ? '<svg class="status-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2 A10 10 0 0 1 22 12" stroke-linecap="round"/></svg>'
    : '';

  currentStatus.innerHTML = `
    ${spinnerHtml}
    ${getIconSvg(currentStep.icon, false)}
    <span class="status-text">${currentStep.label}</span>
    <svg class="status-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <polyline points="18 15 12 9 6 15"></polyline>
    </svg>
  `;

  // Render full log (or reuse existing)
  let statusLogDiv = container.querySelector('.status-log');
  if (!statusLogDiv) {
    statusLogDiv = document.createElement('div');
    statusLogDiv.className = 'status-log';
  }

  // Update visibility based on expanded state
  const isExpanded = container.dataset.expanded === 'true';
  statusLogDiv.style.display = isExpanded ? 'block' : 'none';

  // Build log items HTML
  statusLogDiv.innerHTML = statusLog.steps.map(step => {
  // Add spinner ONLY for tool activities (reasoning, web_search) that are still in progress
  const showSpinner = step.status === 'in_progress' &&
                      (step.type === 'reasoning' || step.type === 'web_search' || step.type === 'code_interpreter');
  const spinnerHtml = showSpinner
    ? '<svg class="status-spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10" stroke-opacity="0.25"/><path d="M12 2 A10 10 0 0 1 22 12" stroke-linecap="round"/></svg>'
    : '';    // Build HTML based on whether step has details (reasoning summary)
    if (step.details) {
      // For steps with details: use <details> element with clickable summary
      return `
        <div class="status-log-item" data-step="${step.step}" data-status="${step.status}" data-type="${step.type}">
          ${spinnerHtml}
          ${getIconSvg(step.icon, false)}
          <details class="status-details">
            <summary class="status-label-clickable">
              ${step.label}
              <svg class="status-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"></polyline>
              </svg>
            </summary>
            <div class="status-content">${step.details.content}</div>
          </details>
        </div>
      `;
    } else {
      // For regular steps: simple label
      return `
        <div class="status-log-item" data-step="${step.step}" data-status="${step.status}" data-type="${step.type}">
          ${spinnerHtml}
          ${getIconSvg(step.icon, false)}
          <div class="status-label">
            ${step.label}
          </div>
        </div>
      `;
    }
  }).join('');

  // Update DOM
  if (!container.querySelector('.status-current')) {
    container.appendChild(currentStatus);
  }
  if (!container.querySelector('.status-log')) {
    container.appendChild(statusLogDiv);
  }

}

/**
 * Toggle status log visibility
 * @param {HTMLElement} messageElement - The message element
 */
function toggleStatusLog(messageElement) {
  const container = messageElement.querySelector('.ai-status-indicator');
  if (!container) return;

  const isExpanded = container.dataset.expanded === 'true';
  container.dataset.expanded = isExpanded ? 'false' : 'true';

  const statusLog = container.querySelector('.status-log');
  if (statusLog) {
    statusLog.style.display = isExpanded ? 'none' : 'block';
  }

}

/**
 * Get icon SVG by type and loading state
 * @param {string} iconType - Icon type (checkmark, loading, search, processing, reasoning)
 * @param {boolean} isLoading - Not used anymore, kept for compatibility
 * @returns {string} SVG HTML string
 */
function getIconSvg(iconType, isLoading = false) {
  // Icons are now static, spinner is added separately

  const icons = {
    // Generic checkmark for completed status
    'checkmark': '<svg class="status-icon status-icon-stroke" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',

    // Check2-circle icon for processing completed (Bootstrap Icons bi-check2-circle)
    'check2-circle': '<svg class="status-icon status-icon-fill" viewBox="0 0 16 16" fill="currentColor"><path d="M2.5 8a5.5 5.5 0 0 1 8.25-4.764.5.5 0 0 0 .5-.866A6.5 6.5 0 1 0 14.5 8a.5.5 0 0 0-1 0 5.5 5.5 0 1 1-11 0"/><path d="M15.354 3.354a.5.5 0 0 0-.708-.708L8 9.293 5.354 6.646a.5.5 0 1 0-.708.708l3 3a.5.5 0 0 0 .708 0z"/></svg>',

    // Checkbox checked icon (legacy, kept for compatibility)
    'checkbox': '<svg class="status-icon status-icon-stroke" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><polyline points="9 11 12 14 15 10"/></svg>',

    // Error icon - Alert triangle
    'error': '<svg class="status-icon status-icon-stroke status-icon-error" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><circle cx="12" cy="17" r="0.5" fill="currentColor"/></svg>',

    // Cloud Upload icon - Bootstrap Icons bi-cloud-upload (for initial request received)
    'send': '<svg class="status-icon status-icon-fill" viewBox="0 0 16 16" fill="currentColor"><path fill-rule="evenodd" d="M4.406 1.342A5.53 5.53 0 0 1 8 0c2.69 0 4.923 2 5.166 4.579C14.758 4.804 16 6.137 16 7.773 16 9.569 14.502 11 12.687 11H10a.5.5 0 0 1 0-1h2.688C13.979 10 15 8.988 15 7.773c0-1.216-1.02-2.228-2.313-2.228h-.5v-.5C12.188 2.825 10.328 1 8 1a4.53 4.53 0 0 0-2.941 1.1c-.757.652-1.153 1.438-1.153 2.055v.448l-.445.049C2.064 4.805 1 5.952 1 7.318 1 8.785 2.23 10 3.781 10H6a.5.5 0 0 1 0 1H3.781C1.708 11 0 9.366 0 7.318c0-1.763 1.266-3.223 2.942-3.593.143-.863.698-1.723 1.464-2.383"/><path fill-rule="evenodd" d="M7.646 4.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 5.707V14.5a.5.5 0 0 1-1 0V5.707L5.354 7.854a.5.5 0 1 1-.708-.708z"/></svg>',

    // Generic loading spinner icon (not animated itself)
    'loading': '<svg class="status-icon status-icon-stroke" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>',

    // Web Search icon - World icon (same as input field) - uses stroke
    'search': '<svg class="status-icon status-icon-stroke" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.6 9h16.8"/><path d="M3.6 15h16.8"/><path d="M11.5 3a17 17 0 0 0 0 18"/><path d="M12.5 3a17 17 0 0 1 0 18"/></svg>',

    // Processing icon - Bootstrap Terminal (bi-terminal) - uses fill
    'processing': '<svg class="status-icon status-icon-fill" viewBox="0 0 16 16" fill="currentColor"><path d="M6 9a.5.5 0 0 1 .5-.5h3a.5.5 0 0 1 0 1h-3A.5.5 0 0 1 6 9zM3.854 4.146a.5.5 0 1 0-.708.708L4.793 6.5 3.146 8.146a.5.5 0 1 0 .708.708l2-2a.5.5 0 0 0 0-.708l-2-2z"/><path d="M2 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2H2zm12 1a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h12z"/></svg>',

    // Code interpreter icon - square terminal, the same glyph the model capability uses
    'code': '<svg class="status-icon status-icon-stroke" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 11 2-2-2-2"/><path d="M11 13h4"/><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/></svg>',

    // Reasoning icon - CPU/Chip (custom) - uses stroke
    'reasoning': '<svg class="status-icon status-icon-stroke" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/></svg>',

    // Image icon - Picture frame
    'image': '<svg class="status-icon status-icon-stroke" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>'
  };

  return icons[iconType] || icons.checkmark;
}

/**
 * Get status type from status string
 * @param {string} status - Status string (e.g., 'reasoning', 'web_search_complete')
 * @returns {string} Type (processing, reasoning, web_search, image_generation, completed)
 */
function getStatusType(status) {
  if (status === 'in_progress') return 'processing';
  if (status === 'completed') return 'processing'; // Final "Processing completed" is type 'processing'
  if (status === 'processing_completed') return 'processing'; // Chat Completions final status
  if (status.includes('reasoning')) return 'reasoning';
  if (status.includes('code_interpreter')) return 'code_interpreter';
  if (status.includes('web_search')) return 'web_search';
  if (status.includes('image_generation')) return 'image_generation';
  return 'processing';
}

/**
 * Get status label with proper translation
 * @param {string} status - Status string
 * @param {string} type - Status type (processing, reasoning, web_search)
 * @param {string} message - Optional custom message (for Reasoning Summary Titles)
 * @param {string} query - Optional search query
 * @returns {string} Localized label
 */
function getStatusLabel(status, type, message, query) {
  // 1. Custom message has PRIORITY (Reasoning Summary Title)
  if (message) return message;
  
  // 2. Web Search with query (check type + completed status OR original status)
  if ((type === 'web_search' && status === 'completed') || status === 'web_search_complete') {
    if (query) {
      return (translation?.Status_WebSearchComplete || 'Searched for: {query}').replace('{query}', query);
    }
  }

  // 3. Derive label from status + type (NO MESSAGE from backend!)
  const labelKey = `${type}_${status}`;
  const labels = {
    // Processing states
    'processing_in_progress': translation?.Status_Processing || 'Processing...',
    'processing_completed': translation?.Status_Completed || 'Processing completed',
    'processing_incomplete': translation?.Status_Incomplete || 'Incomplete',
    'processing_cancelled': translation?.Status_Cancelled || 'Response cancelled by user',
    'processing_error': translation?.Status_ServerError || 'Server connection lost',

    // Reasoning states
    'reasoning_reasoning': translation?.Status_Reasoning || 'Model is reasoning...',
    'reasoning_in_progress': translation?.Status_Reasoning || 'Model is reasoning...',
    'reasoning_completed': translation?.Status_ReasoningComplete || 'Reasoning completed',
    'reasoning_incomplete': translation?.Status_Incomplete || 'Incomplete',

    // Web Search states
    'web_search_web_search_initiated': translation?.Status_WebSearchInitiated || 'Web search initiated',
    'web_search_initiated': translation?.Status_WebSearchInitiated || 'Web search initiated',
    'web_search_web_search': translation?.Status_WebSearch || 'Searching the web...',
    'web_search_in_progress': translation?.Status_WebSearch || 'Searching the web...',
    'web_search_web_search_success': translation?.Status_WebSearchSuccess || 'Web search successful',
    'web_search_success': translation?.Status_WebSearchSuccess || 'Web search successful',
    'web_search_web_search_complete': translation?.Status_WebSearchNoQuery || 'Web search completed',
    'web_search_completed': translation?.Status_WebSearchNoQuery || 'Web search completed',
    'web_search_incomplete': translation?.Status_Incomplete || 'Incomplete',

    // Code interpreter states
    'code_interpreter_in_progress': translation?.Status_CodeInterpreter || 'Running code...',
    'code_interpreter_completed': translation?.Status_CodeInterpreterComplete || 'Code executed',
    'code_interpreter_incomplete': translation?.Status_Incomplete || 'Incomplete',

    // Image Generation states
    'image_generation_image_generation_initiated': translation?.Status_ImageGenerationInitiated || 'Image generation initiated',
    'image_generation_initiated': translation?.Status_ImageGenerationInitiated || 'Image generation initiated',
    'image_generation_image_generation': translation?.Status_ImageGeneration || 'Generating image...',
    'image_generation_in_progress': translation?.Status_ImageGeneration || 'Generating image...',
    'image_generation_completed': translation?.Status_ImageGenerationComplete || 'Image generated',
    'image_generation_incomplete': translation?.Status_Incomplete || 'Incomplete'
  };

  // Try composite key first, then fall back to status-only
  return labels[labelKey] || labels[status] || status;
}

/**
 * Get status icon type
 * @param {string} status - Status string
 * @param {string} type - Optional type string (processing, reasoning, web_search, image_generation)
 * @returns {string} Icon type
 */
function getStatusIcon(status, type = null) {
  // For in_progress status, use type to determine icon
  if (status === 'in_progress') {
    if (type === 'code_interpreter') return 'code';
    if (type === 'web_search') return 'search';
    if (type === 'reasoning') return 'reasoning';
    if (type === 'image_generation') return 'image';
    if (type === 'processing') return 'send'; // Send icon for initial "Processing..."
    return 'processing';
  }

  // For incomplete status (aborted steps)
  if (status === 'incomplete') {
    if (type === 'code_interpreter') return 'code';
    if (type === 'web_search') return 'search';
    if (type === 'reasoning') return 'reasoning';
    if (type === 'image_generation') return 'image';
    if (type === 'processing') return 'send';
    return 'processing';
  }

  // For completed status, use type to determine icon
  if (status === 'completed') {
    if (type === 'code_interpreter') return 'code'; // Terminal for executed code
    if (type === 'web_search') return 'search'; // Globe for completed web search
    if (type === 'reasoning') return 'reasoning'; // CPU for completed reasoning
    if (type === 'image_generation') return 'image'; // Image icon for completed image generation
    if (type === 'processing') return 'check2-circle'; // Check2-circle for completed processing
    return 'check2-circle'; // Default to check2-circle
  }

  // Error and cancelled always use error icon
  if (status === 'error' || status === 'cancelled') return 'error'; // Alert triangle for errors

  // Legacy status strings (for backward compatibility)
  if (status === 'reasoning' || status === 'reasoning_complete') return 'reasoning'; // CPU icon
  if (status === 'web_search_initiated' || status === 'web_search' || status === 'web_search_success' || status === 'web_search_complete') return 'search'; // Globe icon
  if (status === 'image_generation_initiated' || status === 'image_generation' || status === 'image_generation_complete') return 'image'; // Image icon

  return 'check2-circle'; // Default to check2-circle for completed states
}
