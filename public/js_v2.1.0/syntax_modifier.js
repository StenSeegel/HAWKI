
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
    console.log('[CITATIONS] Using index mapping:', indexMapping);
    console.log('[CITATIONS] URL to display index map:', Array.from(urlToDisplayIndex.entries()));
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
      
      // Add click handler to highlight target source
      citationLink.addEventListener('click', (e) => {
        e.preventDefault();
        const targetId = citationLink.getAttribute('href').substring(1); // Remove #
        const targetElement = document.getElementById(targetId);
        
        if (targetElement) {
          // Remove existing highlights
          document.querySelectorAll('.source-item.highlighted').forEach(el => {
            el.classList.remove('highlighted');
          });
          
          // Scroll to and highlight target (with offset to avoid input field)
          targetElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
          targetElement.classList.add('highlighted');
          
          // Remove highlight after animation
          setTimeout(() => {
            targetElement.classList.remove('highlighted');
          }, 2000);
        }
      });
      
      span.appendChild(citationLink);
      citationMarker.appendChild(span);
      
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
      
      // Replace the link element with the citation marker
      link.parentNode.replaceChild(citationMarker, link);
      
      console.log('[CITATIONS] Replaced link to', url, 'with citation', citationIndex);
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

function formatHljs(messageElement) {
  messageElement.querySelectorAll('pre code').forEach((block) => {
    if (block.dataset.highlighted != 'true') {
      hljs.highlightElement(block);
    }
    const language = block.result?.language || block.className.match(/language-(\w+)/)?.[1];
    if (language) {
      if (!block.parentElement.querySelector('.hljs-code-header')) {
        const header = document.createElement('div');
        header.classList.add('hljs-code-header');
        header.textContent = language;
        block.parentElement.insertBefore(header, block);
      }
    }
  });
}

// Efficiently preprocess content: Handle math formulas, think blocks, and preserve HTML elements
function preprocessContent(content) {
  if (!content) return { processedContent: '', mathReplacements: [], thinkReplacements: [] };

  // RegEx patterns
  const mathRegex = /(\$\$[^0-9].*?\$\$|\$[^0-9].*?\$|\\\(.*?\\\)|\\\[.*?\\\])/gs;
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

    // console.log('inCodeBlock', inCodeBlock);
    // console.log('currentSegment', currentSegment);
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

// Helper function to process non-code segments
function processNonCodeSegment(segment, mathRegex, thinkRegex, mathReplacements, thinkReplacements) {
  // Process math formulas first
  let processed = segment.replace(mathRegex, (mathMatch) => {
    // Skip dollar signs followed by numbers (likely currency)
    if (/^\$\d+/.test(mathMatch)) return mathMatch;

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
      chips.forEach((chip) => {
        chip.setAttribute('target', '_blank');
      });

      // Create a new span to hold the content
      let googleSpan;
      if (!messageElement.querySelector('.google-search')) {
        googleSpan = document.createElement('span');
        googleSpan.classList.add('google-search');
      } else {
        googleSpan = messageElement.querySelector('.google-search');
      }

      googleSpan.innerHTML = divElement.outerHTML;
      // Append the new span to the target element
      messageElement.querySelector('.message-content').appendChild(googleSpan);
    }
  }
}

/**
 * Add Responses API (OpenAI) web search citations to a message element as Search Sources
 * @param {HTMLElement} messageElement - The message element to add sources to
 * @param {Array} auxiliaries - Array of auxiliary data including citations
 */
function addResponsesCitations(messageElement, auxiliaries) {
  console.log('[RESPONSES CITATIONS] Function called', {
    hasAuxiliaries: !!auxiliaries,
    auxiliariesCount: auxiliaries?.length,
    auxiliaryTypes: auxiliaries?.map(aux => aux.type).join(', ')
  });
  
  if (!auxiliaries || !Array.isArray(auxiliaries)) {
    console.log('[RESPONSES CITATIONS] No auxiliaries array');
    return;
  }

  // Find responsesCitations auxiliary
  const citationsAux = auxiliaries.find(aux => aux.type === 'responsesCitations');
  console.log('[RESPONSES CITATIONS] Found citationsAux:', !!citationsAux);
  
  if (!citationsAux || !citationsAux.content) {
    console.log('[RESPONSES CITATIONS] No responsesCitations auxiliary found');
    return;
  }

  try {
    const citationsData = JSON.parse(citationsAux.content);
    const citations = citationsData.citations;

    console.log('[RESPONSES CITATIONS] Parsed citations:', citations?.length);
    
    // Log all citations with their original indices
    console.log('[RESPONSES CITATIONS] All citations:', citations.map((c, i) => ({
      index: i,
      url: c?.url,
      title: c?.title
    })));

    if (!citations || !Array.isArray(citations) || citations.length === 0) {
      console.log('[RESPONSES CITATIONS] No citations in data');
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
        console.log(`[RESPONSES CITATIONS] Duplicate URL at index ${originalIndex} → maps to position ${existingPosition}`);
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

    console.log('[RESPONSES CITATIONS] Unique citations for display:', uniqueCitations.length);
    console.log('[RESPONSES CITATIONS] Index mapping (original → display):', indexMapping);
    console.log('[RESPONSES CITATIONS] Citation list:', uniqueCitations.map((c, i) => `[${i}] ${c.url} (from indices: ${c.originalIndices.join(', ')})`));

    // Remove any existing responses-sources container first
    const existingSources = messageElement.querySelector('.responses-sources');
    if (existingSources) {
      console.log('[RESPONSES CITATIONS] Removing existing sources container');
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
        item.id = `source${messageElement.dataset.messageId || 'msg'}:${citationNum}`;
      });
      
      messageContent.appendChild(sourcesContainer);
      
      // Replace HTML links with inline citations using the index mapping
      const msgTextElement = messageContent.querySelector('.message-text');
      if (msgTextElement) {
        const messageId = messageElement.dataset.messageId || 'msg';
        
        // Replace all <a href> links with citation indices (using mapped indices)
        replaceHtmlLinksWithCitations(msgTextElement, citations, messageId, indexMapping);
        
        console.log('[RESPONSES CITATIONS] Replaced HTML links with inline citations using index mapping');
      }
      
      console.log('[RESPONSES CITATIONS] Added', uniqueCitations.length, 'unique sources to message');
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

          // Replace text with placeholder
          const escapedText = escapeRegExp(segmentText);
          text = text.replace(new RegExp(escapedText, 'g'), (match) =>
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
    let sourcesMarkdown = `\n\n### Search Sources:\n`;

    groundingMetadata.groundingChunks.forEach((chunk, index) => {
      if (chunk.web?.uri && chunk.web?.title) {
        const sourceLink = `${index + 1}. <a id="source${randomId}:${index + 1}" href="${chunk.web.uri}" target="_blank" class="source-link"><b>${chunk.web.title}</b></a>\n`;
        const id = preservedHTML.length;
        preservedHTML.push(sourceLink);
        sourcesMarkdown += `%%HTML_PRESERVED_${id}%%`;
      }
    });

    if (sourcesMarkdown !== '\n\n### Search Sources:\n') {
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
 * Update AI status indicator for streaming responses
 * Shows status like "thinking", "reasoning", "web search in progress" as a list
 * @param {HTMLElement} messageElement - The message element to add status to
 * @param {Array} auxiliaries - Array of auxiliary data including status updates
 * @param {boolean} isDone - Whether the stream is complete
 */
function updateAiStatusIndicator(messageElement, auxiliaries, isDone = false) {
  // If stream is done, cleanup and add final summary
  if (isDone) {
    const existingIndicator = messageElement.querySelector('.ai-status-indicator');
    if (existingIndicator) {
      // Count reasoning and web search activities
      const hadReasoning = existingIndicator.querySelector('[data-item-type="reasoning"]') !== null;
      const hadWebSearch = existingIndicator.querySelector('[data-item-type="web-search"]') !== null;
      
      // Remove temporary reasoning status items (only reasoning, NOT web_search)
      // Keep: response status (in_progress/completed), persistent items (reasoning_summary, web_search_query), and web_search items
      const tempReasoningItems = existingIndicator.querySelectorAll('[data-status-category="reasoning"]:not([data-persistent="true"])');
      tempReasoningItems.forEach(item => {
        console.log('[CLEANUP] Removing temporary reasoning status:', item.getAttribute('data-status-type'));
        item.remove();
      });
      
      // Also remove temporary web_search items (in_progress) but keep persistent web_search_query items
      const tempWebSearchItems = existingIndicator.querySelectorAll('[data-status-category="web_search"]:not([data-persistent="true"])');
      tempWebSearchItems.forEach(item => {
        console.log('[CLEANUP] Removing temporary web_search status:', item.getAttribute('data-status-type'));
        item.remove();
      });
      
      // Add final summary messages if activities occurred but no specific items exist
      // This ensures users see what happened even if no summaries/queries were generated
      const icon = '<svg class="status-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      
      // Check if we already have persistent items
      const hasReasoningSummaries = existingIndicator.querySelector('[data-status-type="reasoning-summary"]') !== null;
      const hasWebSearchQueries = existingIndicator.querySelector('[data-status-type="web-search-query"]') !== null;
      
      // Add generic "Reasoning completed" if reasoning occurred but no summaries exist
      if (hadReasoning && !hasReasoningSummaries) {
        const reasoningCompleteItem = document.createElement('div');
        reasoningCompleteItem.classList.add('ai-status-item', 'status-complete');
        reasoningCompleteItem.setAttribute('data-status-type', 'reasoning-complete-final');
        reasoningCompleteItem.setAttribute('data-persistent', 'true');
        reasoningCompleteItem.setAttribute('data-sort-index', '9998'); // Near end, before response completed
        const text = translation?.Status_ReasoningComplete || 'Reasoning completed';
        reasoningCompleteItem.innerHTML = `${icon}<span class="status-text">${text}</span>`;
        existingIndicator.appendChild(reasoningCompleteItem);
        console.log('[CLEANUP] Added final reasoning complete message');
      }
      
      // Add generic "Web search completed" if searches occurred but no queries exist
      if (hadWebSearch && !hasWebSearchQueries) {
        const webSearchCompleteItem = document.createElement('div');
        webSearchCompleteItem.classList.add('ai-status-item', 'status-complete');
        webSearchCompleteItem.setAttribute('data-status-type', 'web-search-complete-final');
        webSearchCompleteItem.setAttribute('data-persistent', 'true');
        webSearchCompleteItem.setAttribute('data-sort-index', '9999'); // Near end, before response completed
        const text = translation?.Status_WebSearchComplete?.replace('{query}', '') || 'Web search completed';
        webSearchCompleteItem.innerHTML = `${icon}<span class="status-text">${text}</span>`;
        existingIndicator.appendChild(webSearchCompleteItem);
        console.log('[CLEANUP] Added final web search complete message');
      }
      
      // Remove the entire indicator container only if no items remain
      if (existingIndicator.children.length === 0) {
        existingIndicator.remove();
      } else if (existingIndicator.children.length > 5) {
        // If more than 5 items, wrap in collapsible details element
        console.log('[CLEANUP] Status log has', existingIndicator.children.length, 'items - making it collapsible');
        
        // Check if already wrapped
        if (!existingIndicator.classList.contains('status-log-collapsed')) {
          existingIndicator.classList.add('status-log-collapsed');
          
          // Create details wrapper
          const details = document.createElement('details');
          details.classList.add('status-log-details');
          
          const summary = document.createElement('summary');
          summary.classList.add('status-log-summary');
          summary.innerHTML = `
            <svg class="status-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
              <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="status-text">${existingIndicator.children.length} Aktivitäten</span>
            <svg class="status-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="6 9 12 15 18 9"></polyline>
            </svg>
          `;
          
          // Move all items into details
          details.appendChild(summary);
          const itemsContainer = document.createElement('div');
          itemsContainer.classList.add('status-log-items');
          while (existingIndicator.firstChild) {
            itemsContainer.appendChild(existingIndicator.firstChild);
          }
          details.appendChild(itemsContainer);
          existingIndicator.appendChild(details);
        }
      }
    }
    return;
  }

  if (!auxiliaries || !Array.isArray(auxiliaries)) {
    // No auxiliaries but not done - keep existing status visible
    return;
  }

  // Handle reasoning summary items (Responses API) - individual summaries
  const reasoningSummaryItems = auxiliaries.filter(aux => aux.type === 'reasoning_summary_item');
  if (reasoningSummaryItems.length > 0) {
    console.log('[REASONING SUMMARY] Processing', reasoningSummaryItems.length, 'summary items (isDone:', isDone, ')');
    
    // Create or get status indicator container
    let statusIndicator = messageElement.querySelector('.ai-status-indicator');
    if (!statusIndicator) {
      statusIndicator = document.createElement('div');
      statusIndicator.classList.add('ai-status-indicator');
      const messageWrapper = messageElement.querySelector('.message-wrapper');
      const messageHeader = messageWrapper?.querySelector('.message-header');
      if (messageHeader && messageHeader.nextSibling) {
        messageWrapper.insertBefore(statusIndicator, messageHeader.nextSibling);
      } else if (messageWrapper) {
        messageWrapper.appendChild(statusIndicator);
      }
    }

    // Process each summary item
    reasoningSummaryItems.forEach(summaryAux => {
      try {
        const summaryData = JSON.parse(summaryAux.content);
        const { index, title, summary, output_index } = summaryData;
        
        console.log('[REASONING SUMMARY] Processing item', index, 'with title:', title, 'output_index:', output_index);
        
        // IMPORTANT: Try to find the existing temporary reasoning status item with the same output_index
        // This item was created by response.output_item.added (reasoning) and should be replaced
        let summaryItem = null;
        
        // First, try to find by summary index (if already converted to summary)
        summaryItem = statusIndicator.querySelector(`[data-summary-index="${index}"]`);
        
        // If not found, try to find the temporary reasoning status with the same output_index
        if (!summaryItem && output_index !== undefined) {
          summaryItem = statusIndicator.querySelector(`[data-status-category="reasoning"][data-output-index="${output_index}"]`);
          if (summaryItem) {
            console.log('[REASONING SUMMARY] Found temporary reasoning status to replace, output_index:', output_index);
          }
        }
        
        if (!summaryItem) {
          // Create new summary status item
          summaryItem = document.createElement('div');
          summaryItem.classList.add('ai-status-item', 'status-reasoning-summary', 'status-complete');
          summaryItem.setAttribute('data-status-type', 'reasoning-summary');
          summaryItem.setAttribute('data-summary-index', index);
          summaryItem.setAttribute('data-persistent', 'true');  // Mark as persistent
          summaryItem.setAttribute('data-item-type', 'reasoning');  // For mixed sorting
          summaryItem.setAttribute('data-sort-index', output_index !== undefined ? output_index : index);  // Use output_index for global sort
          if (output_index !== undefined) {
            summaryItem.setAttribute('data-output-index', output_index);  // Keep output_index
          }
          
          // Icon (checkmark for completed)
          const icon = '<svg class="status-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
          
          // Create with expandable details
          summaryItem.innerHTML = `
            ${icon}
            <span class="status-text">
              <details class="status-details">
                <summary class="status-summary">
                  ${title}
                  <svg class="status-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"></polyline>
                  </svg>
                </summary>
                <div class="status-content">${summary}</div>
              </details>
            </span>
          `;
          
          // Insert in sorted order (by sort-index across all persistent items)
          insertStatusItemInOrder(statusIndicator, summaryItem);
          
          console.log('[REASONING SUMMARY] Created summary item', index, 'with sort-index:', output_index !== undefined ? output_index : index);
        } else {
          // Update existing item (convert temporary reasoning to summary)
          console.log('[REASONING SUMMARY] Updating existing item to summary, index:', index, 'output_index:', output_index);
          
          // Update attributes
          summaryItem.className = 'ai-status-item status-reasoning-summary status-complete';
          summaryItem.setAttribute('data-status-type', 'reasoning-summary');
          summaryItem.setAttribute('data-summary-index', index);
          summaryItem.setAttribute('data-persistent', 'true');
          summaryItem.setAttribute('data-item-type', 'reasoning');
          summaryItem.setAttribute('data-sort-index', output_index !== undefined ? output_index : index);
          if (output_index !== undefined) {
            summaryItem.setAttribute('data-output-index', output_index);
          }
          // Remove temporary reasoning attributes
          summaryItem.removeAttribute('data-status-category');
          
          // Icon (checkmark for completed)
          const icon = '<svg class="status-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
          
          // Update content with expandable details
          summaryItem.innerHTML = `
            ${icon}
            <span class="status-text">
              <details class="status-details">
                <summary class="status-summary">
                  ${title}
                  <svg class="status-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"></polyline>
                  </svg>
                </summary>
                <div class="status-content">${summary}</div>
              </details>
            </span>
          `;
        }
      } catch (error) {
        console.error('Error parsing reasoning summary item:', error);
      }
    });
  }

  // Handle web search query items (Responses API) - persistent search queries
  const webSearchQueryItems = auxiliaries.filter(aux => aux.type === 'web_search_query');
  if (webSearchQueryItems.length > 0) {
    console.log('[WEB SEARCH] Processing', webSearchQueryItems.length, 'search query items (isDone:', isDone, ')');
    
    // Create or get status indicator container
    let statusIndicator = messageElement.querySelector('.ai-status-indicator');
    if (!statusIndicator) {
      statusIndicator = document.createElement('div');
      statusIndicator.classList.add('ai-status-indicator');
      const messageWrapper = messageElement.querySelector('.message-wrapper');
      const messageHeader = messageWrapper?.querySelector('.message-header');
      if (messageHeader && messageHeader.nextSibling) {
        messageWrapper.insertBefore(statusIndicator, messageHeader.nextSibling);
      } else if (messageWrapper) {
        messageWrapper.appendChild(statusIndicator);
      }
    }

    // Process each search query item
    webSearchQueryItems.forEach(searchAux => {
      try {
        const searchData = JSON.parse(searchAux.content);
        const { index, query, output_index } = searchData;
        
        // Ensure query is a string
        const queryString = typeof query === 'string' ? query : (query?.query || JSON.stringify(query));
        
        console.log('[WEB SEARCH] Processing query item', index, 'with query:', queryString, 'output_index:', output_index);
        
        // Check if this query item already exists (by index)
        let queryItem = statusIndicator.querySelector(`[data-web-search-index="${index}"]`);
        
        // Also check if there's a temporary web_search status with the same query text
        // If so, replace it instead of creating a duplicate
        if (!queryItem) {
          const tempWebSearchItems = statusIndicator.querySelectorAll('[data-status-type="web_search"]');
          tempWebSearchItems.forEach(tempItem => {
            const tempText = tempItem.querySelector('.status-text')?.textContent || '';
            // Check if the temporary item contains this query
            if (tempText.includes(queryString)) {
              console.log('[WEB SEARCH] Found temporary web_search item with same query, removing it');
              tempItem.remove();
            }
          });
        }
        
        if (!queryItem) {
          // Create new web search query status item
          queryItem = document.createElement('div');
          queryItem.classList.add('ai-status-item', 'status-web-search-query', 'status-complete');
          queryItem.setAttribute('data-status-type', 'web-search-query');
          queryItem.setAttribute('data-web-search-index', index);
          queryItem.setAttribute('data-persistent', 'true');  // Mark as persistent
          queryItem.setAttribute('data-item-type', 'web-search');  // For mixed sorting
          queryItem.setAttribute('data-sort-index', output_index !== undefined ? output_index : (1000 + index));  // Use output_index, fallback to high number + index
          
          // Icon (checkmark for completed)
          const icon = '<svg class="status-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
          
          // Use localized text with query
          const label = translation?.Status_WebSearchComplete || 'Searched for: {query}';
          const labelText = label.replace('{query}', queryString);
          
          // Simple item without dropdown (just display the query)
          queryItem.innerHTML = `${icon}<span class="status-text">${labelText}</span>`;
          
          // Insert in sorted order (by sort-index across all persistent items)
          insertStatusItemInOrder(statusIndicator, queryItem);
          
          console.log('[WEB SEARCH] Created query item', index, 'with sort-index:', output_index !== undefined ? output_index : (1000 + index));
        } else {
          console.log('[WEB SEARCH] Query item', index, 'already exists, skipping');
        }
      } catch (error) {
        console.error('Error parsing web search query item:', error);
      }
    });
  }

  // Legacy: Handle old combined reasoning summary format (for backwards compatibility)
  const reasoningSummaryAux = auxiliaries.find(aux => aux.type === 'reasoning_summary');
  if (reasoningSummaryAux && reasoningSummaryAux.content) {
    try {
      const summaryData = JSON.parse(reasoningSummaryAux.content);
      const summary = summaryData.summary;
      
      if (summary) {
        console.log('[REASONING SUMMARY] Received summary:', summary.substring(0, 100) + '...');
        
        // Create or get status indicator container
        let statusIndicator = messageElement.querySelector('.ai-status-indicator');
        if (!statusIndicator) {
          statusIndicator = document.createElement('div');
          statusIndicator.classList.add('ai-status-indicator');
          const messageWrapper = messageElement.querySelector('.message-wrapper');
          const messageHeader = messageWrapper.querySelector('.message-header');
          if (messageHeader && messageHeader.nextSibling) {
            messageWrapper.insertBefore(statusIndicator, messageHeader.nextSibling);
          } else if (messageWrapper) {
            messageWrapper.appendChild(statusIndicator);
          }
        }

        // Find or create the reasoning_complete status item
        let reasoningCompleteItem = statusIndicator.querySelector('[data-status-type="reasoning"]');
        
        if (!reasoningCompleteItem) {
          // Create new reasoning status item if it doesn't exist
          console.log('[REASONING SUMMARY] Creating new reasoning status item');
          reasoningCompleteItem = document.createElement('div');
          reasoningCompleteItem.classList.add('ai-status-item', 'status-reasoning_complete', 'status-complete');
          reasoningCompleteItem.setAttribute('data-status-type', 'reasoning');
          
          // Icon (checkmark for completed)
          const icon = '<svg class="status-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
          const label = translation?.Status_ReasoningComplete || 'Processing completed';
          
          reasoningCompleteItem.innerHTML = `${icon}<span class="status-text">${label}</span>`;
          statusIndicator.appendChild(reasoningCompleteItem);
        }
        
        // Add dropdown to the reasoning item (whether existing or newly created)
        const existingDetails = reasoningCompleteItem.querySelector('.status-details');
        if (!existingDetails) {
          console.log('[REASONING SUMMARY] Adding dropdown to reasoning item');
          const statusText = reasoningCompleteItem.querySelector('.status-text');
          if (statusText) {
            const labelText = statusText.textContent;
            // Add chevron icon to indicate expandable content
            statusText.innerHTML = `
              <details class="status-details">
                <summary class="status-summary">
                  ${labelText}
                  <svg class="status-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"></polyline>
                  </svg>
                </summary>
                <div class="status-content">${summary}</div>
              </details>
            `;
            console.log('[REASONING SUMMARY] Dropdown added successfully');
          }
        } else {
          console.log('[REASONING SUMMARY] Dropdown already exists, updating content');
          const contentDiv = existingDetails.querySelector('.status-content');
          if (contentDiv) {
            contentDiv.textContent = summary;
          }
        }
      }
    } catch (error) {
      console.error('Error parsing reasoning summary:', error);
    }
  }

  // Find status auxiliary
  const statusAux = auxiliaries.find(aux => aux.type === 'status');
  if (!statusAux || !statusAux.content) {
    // No new status update - keep existing status visible (don't remove)
    return;
  }

  try {
    const statusData = JSON.parse(statusAux.content);
    const status = statusData.status;
    const message = statusData.message;
    const query = statusData.query; // Extract web search query if present
    const outputIndex = statusData.output_index; // Extract output_index for reasoning/web_search

    // Distinguish between Response Status and Reasoning/Tool Status
    const isResponseStatus = ['in_progress', 'completed'].includes(status);
    const isReasoningStatus = ['reasoning', 'web_search', 'reasoning_complete', 'web_search_complete'].includes(status);
    
    // Only handle known status types
    if (!isResponseStatus && !isReasoningStatus) {
      console.log('[STATUS] Unknown status type:', status);
      return;
    }

    // Create or get status indicator container
    let statusIndicator = messageElement.querySelector('.ai-status-indicator');
    if (!statusIndicator) {
      statusIndicator = document.createElement('div');
      statusIndicator.classList.add('ai-status-indicator');
      // Insert right after .message-header (as 2nd child of .message-wrapper)
      const messageWrapper = messageElement.querySelector('.message-wrapper');
      const messageHeader = messageWrapper.querySelector('.message-header');
      // Insert after header
      if (messageHeader.nextSibling) {
        messageWrapper.insertBefore(statusIndicator, messageHeader.nextSibling);
      } else {
        messageWrapper.appendChild(statusIndicator);
      }
    }

    // Handle Response Status (in_progress / completed)
    if (isResponseStatus) {
      console.log('[RESPONSE STATUS]', status);
      updateResponseStatus(statusIndicator, status, message);
      return;
    }
    
    // Handle Reasoning/Tool Status (reasoning / web_search / reasoning_complete / web_search_complete)
    if (isReasoningStatus) {
      console.log('[MODEL STATUS]', status, 'output_index:', outputIndex);
      updateModelStatus(statusIndicator, status, message, query, outputIndex);
      return;
    }

  } catch (error) {
    console.error('Error parsing AI status:', error);
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
      console.log('[RESPONSE STATUS] Moved completed status to end');
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
  
  console.log('[MODEL STATUS] Searching for item with selector:', selector, 'found:', !!statusItem);
  
  // If this is a complete event
  if (isComplete) {
    if (statusItem) {
      // Special handling for reasoning_complete and web_search_complete without query/summary
      // These temporary items should be removed if no persistent content will replace them
      if (status === 'web_search_complete' && !query) {
        console.log('[MODEL STATUS] Removing web_search status (no query available), output_index:', outputIndex);
        statusItem.remove();
        return;
      }
      
      if (status === 'reasoning_complete') {
        // For reasoning, we always remove the temporary item
        // It will be replaced by a summary (if available) or just disappear
        console.log('[MODEL STATUS] Removing temporary reasoning status, output_index:', outputIndex);
        statusItem.remove();
        return;
      }
      
      // For other complete events, mark as complete with checkmark
      console.log('[MODEL STATUS] Marking status as complete:', baseStatus, 'output_index:', outputIndex);
      
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
      console.log('[MODEL STATUS] No status item to complete:', baseStatus, 'output_index:', outputIndex);
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
    
    console.log('[MODEL STATUS] Created new status item:', baseStatus, 'category:', statusCategory, 'output_index:', outputIndex);
  } else {
    // Item exists - DO NOT MOVE IT! Just update content
    // Status items are like a log - they stay in their position
    console.log('[MODEL STATUS] Updating existing status item (no move):', baseStatus, 'category:', statusCategory, 'output_index:', outputIndex);
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
