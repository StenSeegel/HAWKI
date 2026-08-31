//#region Requests And Redirections

function initializeGUI(){

    //prepare text areas
    const textareas = document.querySelectorAll('.singleLineTextarea');
    textareas.forEach(textarea => {
        textarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
            e.preventDefault(); // Prevent the default behavior, which is to insert a newline
            }
        });
    });
    const root = document.querySelector(':root');
    root.style.setProperty('--transition-medium', '0');
}



function onSidebarButtonDown(pageID){
    if(pageID === activeModule){
        if(document.getElementById(`${pageID}-sidebar`) != null){
            togglePanelClass(`${pageID}-sidebar`, 'expanded');

            document.querySelector('.dy-main-content').classList.toggle('expanded');

            const sidebar = document.getElementById(`${pageID}-sidebar`);
            const manualExpanded = sidebar.classList.contains('expanded');
            sidebar.dataset.manualExpanded = manualExpanded;
        }
    }
    else{
        redirectToModule(pageID);
    }
}

function redirectToModule(pageID){
    window.location.href = `/${pageID}`;
}

function setActiveSidebarButton(activeModule){

    const sidebarButtons = document.querySelectorAll('.sidebar-btn');
    const targetId = `${activeModule}-sb-btn`;
		// console.log(targetId);

    sidebarButtons.forEach(sbb => {
        if(sbb.classList.contains('active')){
            sbb.classList.remove('active');
        }
    });

    document.getElementById(targetId).classList.add('active');
}





//#endregion



// //#region Modals
function modalClick(button){
    const modal = button.closest('.modal');
    localStorage.setItem(modal.id, "true")
    modal.remove();
}

function CheckModals(){
    const modals = document.querySelectorAll('.modal');
    for(let i = 0; i < modals.length; i++){
        const modal = modals[i];
        if(localStorage.getItem(modal.id) === 'true'){
            modal.remove();
        }
    }
}
// //#endregion


//#region Panel Controls
function togglePanelClass(targetID, className){
    const panel = document.getElementById(targetID);
    panel.classList.toggle(className);
}

function toggleRelativePanelClass(targetID, sender, className, activation = null) {
    let currentElement = sender;

    while (currentElement) {
        if (currentElement.id === targetID) {
            currentElement.classList.toggle(className);
            return;
        }

        let parentElement = currentElement.parentElement;
        if (parentElement) {
            let siblings = parentElement.children;
            for (let sibling of siblings) {
                if (sibling.id === targetID) {
                    switch(activation){
                        case true:
                            sibling.classList.add(className);
                        break;
                        case false:
                            sibling.classList.remove(className);
                        break;
                        case null:
                            sibling.classList.toggle(className);
                        break;
                    }
                    return;
                }
            }
        }
        currentElement = parentElement;
    }
}
//#endregion


//#region Burgers & Dropdown Click Events

document.addEventListener('click', function(event) {
    let clickedElement = event.target;
    let detectedInputPanel;
    let clickedBurgerMenu = null;
    //interate back until we find the input-container
    while (clickedElement) {

        if(clickedElement.classList.contains('burger-btn') ||
            clickedElement.classList.contains('burger-item') ){
            return;
        }

        if (clickedElement.id === 'quick-actions' || clickedElement.id === 'quick-actions') {
            //if a input panel is clicked
            clickedBurgerMenu = clickedElement;
        }


        if (clickedElement.id === 'input-container') {
            //if a input panel is clicked
            detectedInputPanel = clickedElement;
        }
        clickedElement = clickedElement.parentElement;
    }

    closeBurgerMenus(clickedBurgerMenu);
    toggleOffInputControls(detectedInputPanel);
});



function openBurgerMenu(id, sender = null, alignToElement = false, isRelativeToElement = false, toggleOnSenderClick = false){
    let menu;
    if(isRelativeToElement){
        menu = sender.parentElement.querySelector(`#${id}`)
    }
    else{
        menu = document.getElementById(`${id}`);
    }
    //close all other menus
    closeBurgerMenus(menu);

    //reset style to fit content
    menu.style.width = 'fit-content';

    if(alignToElement){
        const btnRect = sender.getBoundingClientRect();
        menu.style.top = `${btnRect.bottom}px`;
        menu.style.left = `${btnRect.left}px`;
    }

    if(sender && sender.querySelector('.icon')){
        sender.querySelector('.icon').classList.add('active');
    }
    sender.classList.add('active');

    if(toggleOnSenderClick && menu.style.display !== 'none'){
        closeBurgerMenus(null);
    }
    else{
        menu.style.display = `block`;
        setTimeout(() => {
            //add some buffer to the width
            //without buffer bold text on hover changes menu width
            const menuWidth = menu.getBoundingClientRect().width;
            menu.style.width = `${menuWidth + 10}px`;

            menu.style.opacity = `1`;
        }, 50);
    }
}


function closeBurgerMenus(clickedBurgerMenu){
    const menus = document.querySelectorAll('.burger-dropdown');

    menus.forEach(menu => {
        if(clickedBurgerMenu && menu.id === clickedBurgerMenu.id){
            return;
        }
        else if(menu.style.opacity !== '0'){
            const icon = menu.parentElement.querySelector('.icon');
            if(icon && icon.classList.contains('active')){
                icon.classList.remove('active')
            }

            menu.style.opacity = "0";
            document.querySelectorAll('.burger-btn').forEach(btn => {
                btn.classList.remove('active');
            })

            setTimeout(() => {
                menu.style.display = "none";
            }, 300);
        }
    });
}



//#endregion



function closeModal(closeBtn){
    const modal = closeBtn.closest('.modal');
    modal.style.display = 'none';

}


async function smoothDeleteWords(element, totalTime) {
    // Get the content based on the element type
    let content = element.tagName === 'TEXTAREA' ? element.value : element.innerText;
    let words = content.trim().split(/\s+/);

    // Calculate interval for each word deletion based on totalTime
    let interval = totalTime / words.length;

    // Helper function to pause for a specified time
    function delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    // Delete words one by one asynchronously
    while (words.length > 0) {
        words.pop();  // Remove the last word
        let newContent = words.join(' ');

        // Update the content based on the element type
        if (element.tagName === 'TEXTAREA') {
            element.value = newContent;
        } else {
            element.innerText = newContent;
        }

        await delay(interval);  // Wait for the specified interval before the next deletion
    }

    // Clear the content completely at the end
    if (element.tagName === 'TEXTAREA') {
        element.value = '';
    } else {
        element.innerText = '';
    }
}


function playSound(type){

    let audioFile;
    let vol = 1.0;
    switch (type) {
        case 'in':
            vol = 0.5;
            audioFile = '../audio/click.mp3';
            break;
        case 'out':
            audioFile = '../audio/notification1.mp3';
            break;
        case 'alert':
            audioFile = '../audio/notification1.mp3';
            break;
        default:
            console.error('Unknown notification type:', type);
            return;
    }

    const audio = new Audio(audioFile);
    audio.volume = vol;

    audio.play().catch((error) => {
        console.error('Error playing sound:', error);
    });


}


// #region reaction Buttons

// Function to handle button scaling and reaction display

function reactionMouseDown(button){
    button.style.transform = 'scale(1.1)';
}

function reactionMouseUp(button){
    // Reset scale on mouse up
    button.style.transform = 'scale(1.0)';

    // Handle reaction display
    const reaction = button.querySelector('.reaction');
    if (reaction) {
        reaction.style.display = 'block';
        setTimeout(() => {
            reaction.style.opacity = '1';
        }, 50);

        // Hide the reaction after 3 seconds
        setTimeout(() => {
            reaction.style.opacity = '0';

            // Set display to none after the fade-out transition
            setTimeout(() => {
            reaction.style.display = 'none';
            }, 500); // Match transition duration
        }, 3000); // Time before fading starts
    }
}

//#endregion



function checkWindowSize(thresholdWidth, thresholdHeight) {
    // Function to check if window size is smaller than the threshold
    function onResize() {
        const currentWidth = window.innerWidth;
        const currentHeight = window.innerHeight;
        const sidebar = document.getElementById(`${activeModule}-sidebar`) ? document.getElementById(`${activeModule}-sidebar`) : null;
        if (currentWidth < thresholdWidth || currentHeight < thresholdHeight) {
            if(sidebar){
                if(!sidebar.dataset.manualExpanded){
                    document.getElementById(`${activeModule}-sidebar`).classList.remove('expanded');
                    document.querySelector('.dy-main-content').classList.remove('expanded');
                }
            }
        } else {

            if(sidebar){
                if(!sidebar.dataset.manualExpanded){
                    document.getElementById(`${activeModule}-sidebar`).classList.add('expanded');
                    document.querySelector('.dy-main-content').classList.add('expanded');

                }
            }
        }
    }

    // Add event listener for the 'resize' event
    window.addEventListener('resize', onResize);

    onResize();
    setTimeout(() => {
        const root = document.querySelector(':root');
        root.style.setProperty('--transition-medium', '500ms');
    }, 100);

}

//#region Notification

function setSessionCheckerTimer(time){
    setTimeout(() => {
        fetch('/check-session')
        .then(response => response.json())
        .then(data => {

            if (data.expired || data.remaining === 0) {
                const expModal = document.getElementById('session-expiry-modal');
                expModal.style.display = 'flex';
            }
            else{
                setSessionCheckerTimer(data.remaining);
            }
        });
    }, time * 1000);
}


//#endregion

//#region Model Info Card
let modelInfoCardTimeout;
let modelInfoCardHideTimeout;
// The model-selector button the card is currently describing, so clicking the
// card can pick that same model.
let modelInfoCardSourceBtn = null;

document.addEventListener('mouseover', function(e) {
    const item = e.target.closest('.model-selector.burger-item');
    if (item) {
        clearTimeout(modelInfoCardHideTimeout);
        modelInfoCardTimeout = setTimeout(() => {
            showModelInfoCard(item);
        }, 500);
    }
    
    // Hovering inside the card itself keeps it open
    if (e.target.closest('#model-info-card')) {
        clearTimeout(modelInfoCardHideTimeout);
    }
});

document.addEventListener('mouseout', function(e) {
    const item = e.target.closest('.model-selector.burger-item');
    if (item) {
        clearTimeout(modelInfoCardTimeout);
        modelInfoCardHideTimeout = setTimeout(() => {
            hideModelInfoCard();
        }, 300);
    }
    
    // Leaving the card itself
    if (e.target.closest('#model-info-card')) {
        modelInfoCardHideTimeout = setTimeout(() => {
            hideModelInfoCard();
        }, 300);
    }
});

document.addEventListener('click', function(e) {
    const card = e.target.closest('#model-info-card');

    // Clicking anywhere outside just closes the card.
    if (!card) {
        hideModelInfoCard();

        return;
    }

    // Links inside the card (the documentation link) keep their own behaviour.
    if (e.target.closest('a')) {
        return;
    }

    // Clicking the card selects the model it describes, matching the library card.
    if (modelInfoCardSourceBtn && typeof selectModel === 'function') {
        selectModel(modelInfoCardSourceBtn);

        if (typeof closeBurgerMenus === 'function') {
            closeBurgerMenus();
        }
    }

    hideModelInfoCard();
});

function hideModelInfoCard() {
    modelInfoCardSourceBtn = null;

    const card = document.getElementById('model-info-card');
    if (card && card.style.opacity !== '0') {
        card.style.opacity = '0';
        card.style.pointerEvents = 'none';
        setTimeout(() => {
            if(card.style.opacity === '0') card.style.display = 'none';
        }, 200);
    }
}

function normalizeProviderToken(value) {
    return String(value || '')
        .toLowerCase()
        .replace(/[\s_-]+/g, '');
}

function sanitizeProviderLogoSvg(svgMarkup) {
    if (typeof svgMarkup !== 'string') {
        return '';
    }

    const trimmed = svgMarkup.trim();
    if (!trimmed || !/<svg[\s>]/i.test(trimmed)) {
        return '';
    }

    try {
        const parser = new DOMParser();
        const doc = parser.parseFromString(trimmed, 'image/svg+xml');
        const parseError = doc.querySelector('parsererror');
        const svg = doc.documentElement;

        if (parseError || !svg || svg.nodeName.toLowerCase() !== 'svg') {
            return '';
        }

        const blockedTags = ['script', 'foreignObject', 'iframe', 'object', 'embed'];
        blockedTags.forEach((tagName) => {
            svg.querySelectorAll(tagName).forEach((node) => node.remove());
        });

        const elements = [svg, ...svg.querySelectorAll('*')];
        elements.forEach((el) => {
            [...el.attributes].forEach((attr) => {
                const attrName = attr.name.toLowerCase();
                const attrValue = String(attr.value || '').trim().toLowerCase();

                if (attrName.startsWith('on')) {
                    el.removeAttribute(attr.name);
                    return;
                }

                const isUnsafeHref = (attrName === 'href' || attrName === 'xlink:href')
                    && (attrValue.startsWith('javascript:') || attrValue.startsWith('data:'));
                if (isUnsafeHref) {
                    el.removeAttribute(attr.name);
                }
            });
        });

        return new XMLSerializer().serializeToString(svg);
    } catch (error) {
        return '';
    }
}

function resolveModelProviderKey(modelData, providerName = '') {
    const providerCandidates = [
        modelData?.provider?.id,
        modelData?.provider?.provider_name,
        modelData?.provider?.name,
        modelData?.provider_name,
        modelData?.provider_id,
        modelData?.api_provider,
        providerName,
    ].map(normalizeProviderToken).filter(Boolean);

    if (providerCandidates.some(token => token.includes('openai') || token.includes('responses'))) {
        return 'openai';
    }
    if (providerCandidates.some(token => token.includes('google') || token.includes('gemini'))) {
        return 'google';
    }
    if (providerCandidates.some(token => token.includes('anthropic') || token.includes('claude'))) {
        return 'anthropic';
    }
    if (providerCandidates.some(token => token.includes('ollama'))) {
        return 'ollama';
    }

    const modelId = normalizeProviderToken(modelData?.id || modelData?.system_id);
    const modelLabel = normalizeProviderToken(modelData?.label || modelData?.name);

    if (
        modelId.startsWith('gpt') ||
        modelId.startsWith('o1') ||
        modelId.startsWith('o3') ||
        modelId.startsWith('o4') ||
        modelLabel.includes('gpt')
    ) {
        return 'openai';
    }
    if (modelId.includes('gemini') || modelLabel.includes('gemini')) {
        return 'google';
    }
    if (modelId.includes('claude') || modelLabel.includes('claude')) {
        return 'anthropic';
    }
    if (modelId.includes('ollama') || modelLabel.includes('ollama')) {
        return 'ollama';
    }

    return 'default';
}

function setModelInfoCardProviderLogo(modelData, providerName = '') {
    const logoTarget = document.getElementById('mic-provider-logo');
    const logoTemplates = document.getElementById('mic-provider-logo-templates');
    if (!logoTarget || !logoTemplates) return;

    const customLogoSvg = sanitizeProviderLogoSvg(
        modelData?.provider_logo_svg
        || modelData?.provider?.provider_logo_svg
        || modelData?.provider?.logo_svg
        || modelData?.provider?.icon
    );

    if (customLogoSvg) {
        logoTarget.innerHTML = customLogoSvg;
        logoTarget.dataset.providerLogo = 'custom';
        return;
    }

    const logoKey = resolveModelProviderKey(modelData, providerName);
    const template = logoTemplates.querySelector(`[data-logo-key="${logoKey}"]`)
        || logoTemplates.querySelector('[data-logo-key="default"]');

    if (!template) return;

    logoTarget.innerHTML = template.innerHTML;
    logoTarget.dataset.providerLogo = logoKey;
}

function showModelInfoCard(btn) {
    const card = document.getElementById('model-info-card');
    if(!card) return;
    
    try {
        const payload = btn.getAttribute('value');
        if(!payload) return;
        const modelData = JSON.parse(payload);
        
        // Localized strings for the card, provided by the blade partial.
        const micStrings = document.getElementById('mic-strings')?.dataset || {};

        const info = modelData.information || {};
        const settings = modelData.settings || {};
        const mdi = info.model_display_info || {};

        // Admin-entered model text is localized through a `_en` settings variant
        // (see App\Services\AI\Value\LocalizedModelText), not the language files.
        const localeId = (typeof activeLocale !== 'undefined' && activeLocale)
            ? (activeLocale.id || activeLocale)
            : '';
        const textSuffix = localeId === 'en_US' ? '_en' : '';
        const localizedSetting = (key) => {
            if (textSuffix) {
                const localized = settings[key + textSuffix];
                if (typeof localized === 'string' && localized.trim() !== '') return localized;
            }
            const base = settings[key];
            return (typeof base === 'string' && base.trim() !== '') ? base : null;
        };

        // Mirrors LocalizedModelText::description(): a model with no description
        // gets no card at all, so keep it hidden and stop here.
        const text = (value) => (typeof value === 'string' && value.trim() !== '') ? value : null;
        const description = localizedSetting('description')
            // Other language variant, so a card that exists only because the English
            // text was filled in still shows that text.
            || text(settings.description) || text(settings.description_en)
            || text(mdi.description) || text(info.description);

        if (!description) {
            hideModelInfoCard();

            return;
        }

        // Remember which model this card describes, so a click on it selects that
        // model. Set only past the description gate, i.e. once the card is shown.
        modelInfoCardSourceBtn = btn;

        // The shell is a plain block; the .model-library-card inside it owns the
        // layout. Shown before measuring so dimensions can be calculated.
        card.style.display = 'block';
        card.style.opacity = '0';

        document.getElementById('mic-model-name').textContent = modelData.label || modelData.name
            || micStrings.unknownModel || 'Unknown model';

        const providerName = modelData.provider_name
            || modelData?.provider?.provider_name
            || modelData?.provider?.name
            || micStrings.unknownProvider || 'Unknown provider';
        document.getElementById('mic-provider-name').textContent = providerName;
        setModelInfoCardProviderLogo(modelData, providerName);

        document.getElementById('mic-description').textContent = description;
        
        // Mirrors LocalizedModelText::contextSize(): 128000 -> "128K", 1000000 -> "1M".
        const formatContextSize = (value) => {
            if (value === null || value === undefined || value === '') return null;
            const number = Number(value);
            if (!Number.isFinite(number)) return typeof value === 'string' ? value : null;

            let scaled, unit;
            if (number >= 1000000) {
                scaled = number / 1000000; unit = 'M';
            } else if (number >= 1000) {
                scaled = number / 1000; unit = 'K';
            } else {
                return String(Math.trunc(number));
            }

            scaled = Math.round(scaled * 10) / 10;
            // A fraction only earns its place below 10, where it still says something.
            const decimals = (scaled < 10 && scaled % 1 !== 0) ? 1 : 0;
            const separator = localeId === 'en_US' ? '.' : ',';

            return scaled.toFixed(decimals).replace('.', separator) + unit;
        };

        const ctxVal = formatContextSize(settings.context_size
            || mdi.context || info.context_size || info.context) || '?';
        // An empty unit is deliberate (German drops "Tokens"), so treat only a
        // missing attribute as "use the fallback".
        const tokensUnit = micStrings.tokens !== undefined ? micStrings.tokens : 'Tokens';
        document.getElementById('mic-context').textContent = (ctxVal + ' ' + tokensUnit).trim();

        // Knowledge Cutoff Block. The month picker stores an ISO month ("2023-10"),
        // which is rendered as "Oktober 2023" / "October 2023". Older records may
        // still hold a full date or free text.
        const formatCutoff = (value) => {
            if (typeof value !== 'string' || value.trim() === '') return null;
            const raw = value.trim();
            let year, month;

            const ym = raw.match(/^(\d{4})-(\d{1,2})$/);              // 2023-10
            const ymd = raw.match(/^(\d{4})-(\d{1,2})-\d{1,2}$/);     // 2025-10-23
            const dmy = raw.match(/^\d{1,2}\.(\d{1,2})\.(\d{4})$/); // 23.10.2025

            if (ym) {
                [, year, month] = ym;
            } else if (ymd) {
                [, year, month] = ymd;
            } else if (dmy) {
                [, month, year] = dmy;
            } else {
                return raw; // free text such as "Oktober 2023"
            }

            const date = new Date(Number(year), Number(month) - 1, 1);
            if (isNaN(date.getTime())) return raw;

            return date.toLocaleDateString(localeId === 'en_US' ? 'en-US' : 'de-DE',
                {year: 'numeric', month: 'long'});
        };

        const knowledgeVal = formatCutoff(settings.knowledge_cutoff
            || info.knowledge_cutoff || mdi.knowledge_cutoff) || '-';
        document.getElementById('mic-knowledge-cutoff').textContent = knowledgeVal;
        
        // Cost block
        const costContainer = document.getElementById('mic-cost');
        let costActiveStr = '';
        let costInactiveStr = '';
        
        let costVal = settings.cost_indicator || mdi.cost_indicator || info.cost_indicator || mdi.cost || info.costs || settings.costs;
        
        if (costVal !== undefined && costVal !== null) {
            if (typeof costVal === 'string' && costVal.includes('€')) {
                costActiveStr = costVal;
                costInactiveStr = '€€€€'.substring(costActiveStr.length > 4 ? 4 : costActiveStr.length);
            } else if (typeof costVal === 'string' && costVal.length > 5) {
                // If it's a long descriptive text like '$5 / 1M Input...'
                costActiveStr = costVal;
                costInactiveStr = '';
                costContainer.style.fontSize = '0.8rem';
            } else if (!isNaN(parseInt(costVal))) {
                 const lvl = parseInt(costVal);
                 costActiveStr = '€'.repeat(lvl);
                 costInactiveStr = '€'.repeat(Math.max(0, 4 - lvl));
            } else {
                 costActiveStr = costVal;
                 costInactiveStr = '';
            }
        } else {
            costActiveStr = '€';
            costInactiveStr = '€€';
            costContainer.style.fontSize = '';
        }
        costContainer.innerHTML = `<span class="model-library-cost-active">${costActiveStr}</span><span class="model-library-cost-inactive">${costInactiveStr}</span>`;
        
        // Capabilities block. Icon markup and localized labels come from the
        // #mic-capability-templates block rendered by the blade partial. Classes
        // come from the model library, which is the source of truth for card styling.
        const capContainer = document.getElementById('mic-capabilities');
        capContainer.innerHTML = '';

        const tools = settings.tools || info.tools || mdi.tools || {};

        let capabilityKeys = [];
        for (const [key, enabled] of Object.entries(tools)) {
            if (enabled === '1' || enabled === true || enabled === 1) {
                capabilityKeys.push(key);
            }
        }

        // Every model generates text, so say so rather than showing nothing.
        if (capabilityKeys.length === 0) {
            capabilityKeys = ['text_generation'];
        }

        capabilityKeys.forEach(key => {
            const template = document.querySelector(`#mic-capability-templates [data-capability-key="${key}"]`)
                || document.querySelector('#mic-capability-templates [data-capability-key="__fallback"]');

            const tag = document.createElement('span');
            tag.className = 'model-library-capability-tag';

            if (template) {
                const icon = document.createElement('span');
                icon.className = 'model-library-capability-icon-wrapper';
                icon.innerHTML = template.innerHTML;
                tag.appendChild(icon);
                if (template.dataset.title) {
                    tag.title = template.dataset.title;
                }
            }

            const label = document.createElement('span');
            // Unknown keys have no template label, so fall back to the raw key.
            label.textContent = template?.dataset.label || key;
            tag.appendChild(label);

            capContainer.appendChild(tag);
        });
        
        // Doc link
        const docLink = document.getElementById('mic-doc-link');
        const docUrl = settings.documentation_url || info.documentation_url || info.doc_url;
        if(docUrl) {
            docLink.href = docUrl;
            docLink.style.display = 'flex';
        } else {
            docLink.style.display = 'none';
        }
        
        // Positioning: keep the info card strictly within the model selection block.
        const anchorContainer = btn.closest('#model-selector-burger')
            || btn.closest('#models_panel')
            || btn.closest('.model-selection-panel');

        if(anchorContainer) {
            const anchorRect = anchorContainer.getBoundingClientRect();
            const btnRect = btn.getBoundingClientRect();
            const viewportMargin = 10;

            let leftPos = anchorRect.left - card.offsetWidth - 20;
            // If there is no room on the left, place it to the right of the block.
            if(leftPos < viewportMargin) {
                leftPos = anchorRect.right + 20;
            }
            // Keep card inside viewport horizontally.
            const maxLeft = window.innerWidth - card.offsetWidth - viewportMargin;
            leftPos = Math.max(viewportMargin, Math.min(leftPos, maxLeft));
            card.style.left = `${Math.round(leftPos)}px`;

            // Reset dynamic height from previous render first.
            card.style.height = '';
            card.style.maxHeight = '';
            card.style.overflowY = '';
            card.style.overflowX = '';

            const anchorHeight = Math.max(0, Math.floor(anchorRect.height));
            let cardHeight = Math.ceil(card.offsetHeight);

            // If card is taller than the model block, shrink it to the block height.
            if (anchorHeight > 0 && cardHeight > anchorHeight) {
                card.style.height = `${anchorHeight}px`;
                card.style.maxHeight = `${anchorHeight}px`;
                card.style.overflowY = 'auto';
                card.style.overflowX = 'hidden';
                cardHeight = anchorHeight;
            }

            let topPos = btnRect.top + (btnRect.height / 2) - (cardHeight / 2);
            const minTop = anchorRect.top;
            const maxTop = anchorRect.bottom - cardHeight;

            // Clamp strictly to model block boundaries: no protrusion top/bottom.
            if (maxTop >= minTop) {
                if (topPos < minTop) topPos = minTop;
                if (topPos > maxTop) topPos = maxTop;
            } else {
                topPos = minTop;
            }

            card.style.transform = 'none';
            card.style.top = `${Math.round(topPos)}px`;
        }
        
        card.style.opacity = '1';
        card.style.pointerEvents = 'auto';

    } catch(e) {
        console.error('Error parsing model info', e);
    }
}
//#endregion
