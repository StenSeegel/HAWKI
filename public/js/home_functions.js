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

// also close info card when the user clicks anywhere else
document.addEventListener('click', function(e) {
    if (!e.target.closest('#model-info-card')) {
        hideModelInfoCard();
    }
});

function hideModelInfoCard() {
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

function resolveModelProviderKey(modelData, providerName = '') {
    const providerCandidates = [
        modelData?.provider?.id,
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
        
        // Ensure it's shown as block/flex so we can calculate dimensions
        card.style.display = 'flex';
        card.style.opacity = '0';
        
        // Populate data
        document.getElementById('mic-model-name').textContent = modelData.label || modelData.name || 'Unknown Model';
        
        let providerName = 'Unknown Provider';
        if (modelData.provider && modelData.provider.name) providerName = modelData.provider.name;
        else if (modelData.provider_name) providerName = modelData.provider_name;
        document.getElementById('mic-provider-name').textContent = providerName;
        setModelInfoCardProviderLogo(modelData, providerName);
        
        const info = modelData.information || {};
        const settings = modelData.settings || {};
        const mdi = info.model_display_info || {};
        
        document.getElementById('mic-description').textContent = settings.description || mdi.description || info.description || 'Keine Beschreibung verfügbar.';
        
        let ctxVal = settings.context_size || mdi.context || info.context_size || info.context || '?';
        if(typeof ctxVal === 'number') {
            ctxVal = ctxVal.toLocaleString('de-DE');
        }
        document.getElementById('mic-context').textContent = ctxVal + ' Tokens';
        
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
        costContainer.innerHTML = `<span class="mic-cost-active">${costActiveStr}</span><span class="mic-cost-inactive">${costInactiveStr}</span>`;
        
        // Capabilities block
        const capContainer = document.getElementById('mic-capabilities');
        capContainer.innerHTML = '';
        let capabilities = settings.capabilities || mdi.capabilities || info.capabilities || [];
        if(typeof capabilities === 'string') {
            capabilities = capabilities.split(',').map(s=>s.trim()).filter(s=>s.length > 0);
        }
        if(!Array.isArray(capabilities) || capabilities.length === 0) {
            capabilities = ['Standard'];
        }
        capabilities.forEach(cap => {
            const span = document.createElement('span');
            span.className = 'mic-capability-tag';
            span.textContent = cap;
            capContainer.appendChild(span);
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
        
        // Positioning
        const menuContainer = btn.closest('#model-selector-burger');
        if(menuContainer) {
            const menuRect = menuContainer.getBoundingClientRect();
            
            let leftPos = menuRect.left - card.offsetWidth - 20;
            // if no space on the left, put it on the right side of the menu
            if(leftPos < 20) {
                leftPos = menuRect.right + 20;
            }
            card.style.left = `${Math.round(leftPos)}px`;
            
            const btnRect = btn.getBoundingClientRect();
            let topPos = btnRect.top + (btnRect.height / 2) - (card.offsetHeight / 2);

            const maxTop = window.innerHeight - card.offsetHeight - 10;
            const minTop = 10;
            if(topPos > maxTop) topPos = maxTop;
            if(topPos < minTop) topPos = minTop;

            // Avoid sub-pixel transforms that can blur SVG/text rendering.
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
