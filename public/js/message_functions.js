
function addMessageToChatlog(messageObj, isFromServer = false){

    // Remove empty room placeholder when first message is added
    removeEmptyRoomPlaceholder();

    const {messageText, groundingMetadata, auxiliaries} = deconstContent(messageObj.content.text);
    
    // Override auxiliaries with content.auxiliaries if present (for group chat)
    const syncedGeneratedImageContent = syncGeneratedImageContent(
        messageText,
        messageObj.content.auxiliaries || auxiliaries,
        messageObj.content.attachments || []
    );
    const finalAuxiliaries = sanitizeAuxiliariesForChatlog(syncedGeneratedImageContent.auxiliaries);
    const finalMessageText = syncedGeneratedImageContent.messageText;
    const generatedImageAttachmentUuids = extractGeneratedImageUuids(finalAuxiliaries);

    /// CLONE
    // clone message element
    const messageTemp = document.getElementById('message-template')
    const messageClone = messageTemp.content.cloneNode(true);

    //Get messageElement
    const messageElement = messageClone.querySelector(".message");

    /// DATASET & ID
    // set dataset attributes
    messageElement.dataset.role = messageObj.message_role;
    messageElement.dataset.rawMsg = finalMessageText;
    // messageElement.dataset.groundingMetadata = JSON.stringify(groundingMetadata);

    //if date and time is confirmed from the server add them
    if(messageObj.created_at) messageElement.dataset.created_at = messageObj.created_at;

    // set id (whole . deci format)
    if(messageObj.message_id){
        messageElement.id = messageObj.message_id;
    }

    /// CLASSES & AVATARS
    // add classes AI ME MEMBER to the element
    if(messageObj.message_role === "assistant"){
        messageElement.classList.add('AI');
        messageElement.querySelector('.user-inits').remove();
        messageElement.querySelector('.icon-img').src = hawkiAvatarUrl;
    }
    else{
        if(messageObj.author.name && messageObj.author.username === userInfo.username){
            messageElement.classList.add('me');
            if(userAvatarUrl){
                messageElement.querySelector('.user-inits').style.display = "none";
                messageElement.querySelector('.icon-img').style.display = "block";
                messageElement.querySelector('.icon-img').src = userAvatarUrl;
            }
            else{
                messageElement.querySelector('.icon-img').style.display = "none";
                messageElement.querySelector('.user-inits').style.display = "block";
                const userInitials =  messageObj.author.name.slice(0, 1).toUpperCase();
                messageElement.querySelector('.user-inits').innerText = userInitials
            }
        }else{
            messageElement.classList.add('member');
            const hasAvatar = !!messageObj.author.avatar_url;
            messageElement.querySelector('.icon-img').style.display = hasAvatar ? "block" : "none";
            messageElement.querySelector('.user-inits').style.display = hasAvatar ? "none" : "block";

            // assign icon to message.
            if(!hasAvatar){
                messageElement.querySelector('.icon-img').style.display = "none";
                messageElement.querySelector('.user-inits').style.display = "block";
                const userInitials =  messageObj.author.name.slice(0, 1).toUpperCase();
                messageElement.querySelector('.user-inits').innerText = userInitials
            }
            else{
                messageElement.querySelector('.icon-img').style.display = "block";
                messageElement.querySelector('.user-inits').style.display = "none";
                messageElement.querySelector('.icon-img').src = messageObj.author.avatar_url;
            }
        }
    }

    /// Set Author Name
    if(messageObj.model && messageObj.message_role === 'assistant'){
        model = modelsList.find(m => m.id === messageObj.model);
        messageElement.querySelector('.message-author').innerHTML =
            model ?
            `<span>${messageObj.author.username} </span><span class="message-author-model">(${model.label})</span>`:
            `<span>${messageObj.author.username} </span><span class="message-author-model">(${messageObj.model}) !!! Obsolete !!!</span>`;

        messageElement.dataset.model = messageObj.model;
        messageElement.dataset.author = messageObj.author.username;
    }
    else{

        let header;
        if(!messageObj.author.isRemoved || messageObj.author.isRemoved === 0){
            header = messageObj.author.name
        }
        else{
            header = `<span>${messageObj.author.name}</span> <span class="message-author-model">(${translation.RemovedMember})</span>`
        }

        messageElement.querySelector('.message-author').innerHTML = header;
        messageElement.dataset.author = messageObj.author.name;
    }

    /// INDEXING & THREAD
    // if message is from the user, it still doesn't have an assigned ID from the server.
    if(isFromServer){
        // deconstruct message id
        let [msgWholeNum, msgDecimalNum] = messageObj.message_id.split('.').map(Number);

        // if decimal is 0 the message belongs to trunk
        if (msgDecimalNum === 0) {
            threadIndex = 0;
        } else {
            threadIndex = msgWholeNum;
        }
    }
    else{
        threadIndex = activeThreadIndex;
    }

    let activeThread = findThreadWithID(threadIndex);


    /// DATE & TIME
    // if message has a date it's already submitted and comes from the server.
    // if not, it has been created by user and does not have a date stamp -> today is the date
    let msgDate;
    if(messageObj.created_at){
        msgDate = messageObj.created_at.split('+')[0];
    }
    else{
        todayDate = new Date();
        msgDate = `${todayDate.getFullYear()}-${(todayDate.getMonth() + 1).toString().padStart(2, '0')}-${todayDate.getDate().toString().padStart(2, '0')}`;
    }
    setDateSpan(activeThread, msgDate);


    ///ATTACHMENTS
    rememberSavedDiagrams(messageElement, messageObj.content.attachments || []);

    if(messageObj.content.attachments && messageObj.content.attachments.length != 0){

        const attachmentContainer = messageElement.querySelector('.attachments');

        messageObj.content.attachments
            .filter(attachment => {
                const uuid = attachment?.fileData?.uuid;
                return !uuid || !generatedImageAttachmentUuids.has(uuid);
            })
            // A diagram saved from the editor is shown in its code box, not as a file.
            .filter(attachment => savedDiagramBlock(attachment?.fileData?.name) === null)
            .forEach(attachment => {

                const thumbnail = createAttachmentThumbnail(attachment.fileData, 'message');
                // Add to file preview container
                attachmentContainer.appendChild(thumbnail);
            });
    }

    /// CONTENT
    // Setup Message Content
    const msgTxtElement = messageElement.querySelector(".message-text");

    if(!messageElement.classList.contains('AI')){
        let processedContent = detectMentioning(finalMessageText).modifiedText;
        processedContent = convertHyperlinksToLinks(processedContent);
        msgTxtElement.innerHTML = processedContent;
    }
    else{
        let markdownProcessed = formatMessage(finalMessageText, groundingMetadata);
        msgTxtElement.innerHTML = markdownProcessed;
        formatMathFormulas(msgTxtElement);

        if (groundingMetadata &&
            groundingMetadata != '' &&
            groundingMetadata.searchEntryPoint &&
            groundingMetadata.searchEntryPoint.renderedContent) {

            addGoogleRenderedContent(messageElement, groundingMetadata);
        }
        else{
            if(messageElement.querySelector('.google-search')){
                messageElement.querySelector('.google-search').remove();
            }
        }
        // Handle Anthropic citations and status indicator
        if (finalAuxiliaries && Array.isArray(finalAuxiliaries) && finalAuxiliaries.length > 0) {
            addAnthropicCitations(messageElement, finalAuxiliaries);
            addResponsesCitations(messageElement, finalAuxiliaries); // OpenAI Responses API citations
            addHawkiToolsCitations(messageElement, finalAuxiliaries); // HAWKI run web search citations
            // Update AI status indicator (thinking, reasoning, web search)
            updateAiStatusIndicator(messageElement, finalAuxiliaries, false);
        }
    }


    /// check for completion status. ONLY FOR CONV MESSAGES FROM AI.
    if (messageObj.hasOwnProperty('completion')){
        if (messageObj.completion === 0 && messageElement.querySelector('#incomplete-msg-icon')) {
            messageElement.querySelector('#incomplete-msg-icon').style.display = 'flex';
        }else{
            messageElement.querySelector('#incomplete-msg-icon').style.display = 'none';
        }
    }
        /// READ STATUS
    // if the read status exists in the data
    if(messageElement.classList.contains('me') && messageElement.querySelector('#unread-message-icon')){
        messageElement.querySelector('#unread-message-icon').style.display = "none";
    }
    else if ('read_status' in messageObj) {
        messageElement.dataset.read_stat = messageObj.read_status;

        if(messageObj.read_status){
            setMessageStatusAsRead(messageElement);
        }
    }


    /// INSERT IN CHATLOG
    // insert into target thread
    if(threadIndex === 0){
        // if message is a main message then it needs a thread inside
        // clone and insert thread template in message.
        const threadTemplate = document.getElementById('thread-template');
        const threadElement = threadTemplate.content.cloneNode(true);
        threadDiv = threadElement.querySelector('.thread');
        threadDiv.classList.add('branch');
        if(activeModel){
            threadDiv.querySelector('.model-selector-label').innerHTML = activeModel.label;
        }

        if(messageObj.message_id){
            threadDiv.id = messageObj.message_id.split('.')[0];
            threadDiv.querySelector('.input').id = threadDiv.id;
        }

        const input = threadDiv.querySelector('.input-container');

        messageElement.appendChild(threadDiv);
        activeThread.appendChild(messageElement);
	    initFileUploader(input);

    }
    else{
        const branchInput = activeThread.querySelector('.input-container');
        messageElement.querySelector('#thread-btn').remove();
        const messageChildrenCount = Array.from(activeThread.children).filter(child => child.classList.contains('message')).length + 1;
        const cmtCount = activeThread.closest('.message').querySelector('#comment-count');
        cmtCount.style.display = 'block';
        cmtCount.innerHTML = messageChildrenCount;

        activeThread.insertBefore(messageElement, branchInput);
    }

    formatHljs(messageElement);
    return  messageElement;
}

function extractGeneratedImageUuids(auxiliaries) {
    if (!Array.isArray(auxiliaries) || auxiliaries.length === 0) {
        return new Set();
    }

    const uuids = new Set();
    auxiliaries
        .filter(aux => aux?.type === 'generated_image' && typeof aux.content === 'string')
        .forEach(aux => {
            try {
                const imageData = JSON.parse(aux.content);
                if (imageData?.uuid) {
                    uuids.add(imageData.uuid);
                }
            } catch (error) {
                console.error('[GENERATED IMAGE] Could not parse generated_image auxiliary:', error);
            }
        });

    return uuids;
}


function updateMessageElement(messageElement, messageObj, updateContent = false){

    messageElement.id = messageObj.message_id;
    if(messageElement.querySelector('.thread')){
        messageElement.querySelector('.thread').id = messageObj.message_id.split('.')[0];
        messageElement.querySelector('.input').id = messageObj.message_id.split('.')[0]

    }

    if(messageElement.classList.contains('me')){
        messageElement.querySelector('#sent-status-icon').style.display = 'flex';
    }

    if (messageObj.hasOwnProperty('completion')){
        if ((messageObj.completion === 0 || messageObj.completion === false) && messageElement.querySelector('#incomplete-msg-icon')) {
            messageElement.querySelector('#incomplete-msg-icon').style.display = 'flex';
        }else{
            messageElement.querySelector('#incomplete-msg-icon').style.display = 'none';
        }
    }

    messageElement.dataset.role = messageObj.message_role;
    const msgTxtElement = messageElement.querySelector(".message-text");

    if (messageElement.classList.contains('AI')) {
        const username = messageElement.dataset.author;
        const model = modelsList.find(m => m.id === messageObj.model);
        messageElement.querySelector('.message-author').innerHTML =
            model ?
                `<span>${username} </span><span class="message-author-model">(${model.label})</span>` :
                `<span>${username} </span><span class="message-author-model">(${messageObj.model}) !!! Obsolete !!!</span>`;
        messageElement.dataset.model = messageObj.model;
    }

    if(updateContent){
        const {messageText, groundingMetadata, auxiliaries} = deconstContent(messageObj.content.text);

        rememberSavedDiagrams(messageElement, messageObj.content.attachments || []);

        // Override auxiliaries with content.auxiliaries if present (for group chat)
        const syncedGeneratedImageContent = syncGeneratedImageContent(
            messageText,
            messageObj.content.auxiliaries || auxiliaries,
            messageObj.content.attachments || []
        );
        const finalAuxiliaries = sanitizeAuxiliariesForChatlog(syncedGeneratedImageContent.auxiliaries);
        const finalMessageText = syncedGeneratedImageContent.messageText;

        messageElement.dataset.rawMsg = finalMessageText;

        // Store raw content with auxiliaries for multi-turn conversations
        messageElement.dataset.rawContent = rebuildRawContent(
            messageObj.content.text,
            finalMessageText,
            groundingMetadata,
            finalAuxiliaries
        );

        // Store auxiliaries separately as JSON for persistence
        if (finalAuxiliaries && finalAuxiliaries.length > 0) {
            messageElement.dataset.auxiliaries = JSON.stringify(finalAuxiliaries);
        }

        if(messageObj.message_role === "user"){
            const filteredContent = detectMentioning(finalMessageText);
            msgTxtElement.innerHTML = filteredContent.modifiedText;
        }
        else{

            let markdownProcessed = formatMessage(finalMessageText, groundingMetadata);
            msgTxtElement.innerHTML = markdownProcessed;
            formatMathFormulas(msgTxtElement);
            // Replacing the markup drops the code box header with it, so it has
            // to be rebuilt - otherwise a streamed answer loses its run button
            // the moment the stream finishes.
            formatHljs(messageElement);
            if (groundingMetadata &&
                groundingMetadata != '' &&
                groundingMetadata.searchEntryPoint &&
                groundingMetadata.searchEntryPoint.renderedContent) {

                addGoogleRenderedContent(messageElement, groundingMetadata);
            }
            else{
                if(messageElement.querySelector('.google-search')){
                    messageElement.querySelector('.google-search').remove();
                }
            }

            // Handle Anthropic citations
            if (finalAuxiliaries && Array.isArray(finalAuxiliaries) && finalAuxiliaries.length > 0) {

                addAnthropicCitations(messageElement, finalAuxiliaries);
                addResponsesCitations(messageElement, finalAuxiliaries); // OpenAI Responses API citations
                addHawkiToolsCitations(messageElement, finalAuxiliaries); // HAWKI run web search citations
                // Update AI status indicator (thinking, reasoning, web search)
                // Pass isDone=false to keep reasoning summaries visible
                updateAiStatusIndicator(messageElement, finalAuxiliaries, false);
            } else {
                // Remove existing Anthropic sources if no auxiliaries
                if (messageElement.querySelector('.anthropic-sources')) {
                    messageElement.querySelector('.anthropic-sources').remove();
                }
                if (messageElement.querySelector('.responses-sources')) {
                    messageElement.querySelector('.responses-sources').remove();
                }
                if (messageElement.querySelector('.hawki-sources')) {
                    messageElement.querySelector('.hawki-sources').remove();
                }
                // DON'T remove AI status indicator during streaming!
            }
        }

        // if the read status exists in the data
        if(messageElement.classList.contains('me') && messageElement.querySelector('#unread-message-icon')){
            messageElement.querySelector('#unread-message-icon').style.display = "none";
        }
        else if ('read_status' in messageObj) {
            messageElement.dataset.read_stat = messageObj.read_status;

            if(messageObj.read_status){
                setMessageStatusAsRead(messageElement);
            }
        }

    }

    //SET MESSAGE TIME AND EDIT FLAG
    const time = messageObj.created_at.split('+')[1];
    const timeStamp = messageObj.created_at !== messageObj.updated_at ? `edited: ${time}` : `${time}`;
    messageElement.querySelector('#msg-timestamp').innerText = timeStamp;

    activateMessageControls(messageElement);
}




function setDateSpan(activeThread, msgDate, formatDay = true){

    // Determine if msgDate is today or yesterday
    const msgDateObj = new Date(msgDate);
    let dateText;

    if(formatDay){
        const today = new Date();
        const yesterday = new Date();
        yesterday.setDate(today.getDate() - 1);
        if (msgDateObj.toDateString() === today.toDateString()) {
            dateText = translation.Today || 'Today';
        } else if (msgDateObj.toDateString() === yesterday.toDateString()) {
            dateText = translation.Yesterday || 'Yesterday';
        } else {
            const formattedDate = `${msgDateObj.getDate()}.${msgDateObj.getMonth()+1}.${msgDateObj.getFullYear()}`
            dateText = formattedDate;
        }
    }
    else{
        const formattedDate = `${msgDateObj.getDate()}.${msgDateObj.getMonth()+1}.${msgDateObj.getFullYear()}`
        dateText = formattedDate;
    }

    // Find the last date span in the thread
    const lastThreadDateSpan = activeThread.querySelector('span.date_span:last-of-type');
    const lastDate = lastThreadDateSpan ? lastThreadDateSpan.getAttribute('data-date') : null;

    // Initialize variable to keep track of the last found date_span
    let lastTrunkDate = null;
    //if in a banch then find out the last time span in the main thread
    if (activeThread.classList.contains('branch')) {
        const parentMsg = activeThread.closest('.message');
        // Traverse previous siblings
        let prevSibling = parentMsg.previousElementSibling;
        while (!lastTrunkDate) {
            // Check if the previous sibling contains a .date_span element
            if (prevSibling.classList.contains('date_span')) {
                lastTrunkDate = prevSibling.dataset.date; // Update the last found .date_span
            }
            prevSibling = prevSibling.previousElementSibling; // Move to the next previous sibling
        }
    }

    // If the date is different, create a new date span
    if (!lastDate || lastDate !== msgDate) {
        // if the date is also different than the last date span in the main thread.
        if(lastTrunkDate != msgDate){
            const dateSpan = document.createElement('span');
            dateSpan.className = 'date_span';
            dateSpan.textContent = dateText; // Use formatted text
            dateSpan.setAttribute('data-date', msgDate);

            if(activeThread.id === "0"){
                activeThread.appendChild(dateSpan);
            }
            else{
                const branchInput = activeThread.querySelector('.input-container');
                activeThread.insertBefore(dateSpan, branchInput);
            }
        }
    }
}



function deconstContent(inputContent){

    let messageText = '';
    let groundingMetadata = '';
    let auxiliaries = [];

    if(isValidJson(inputContent)){
        const json = JSON.parse(inputContent);
        if(json.hasOwnProperty('groundingMetadata')){
            groundingMetadata = json.groundingMetadata
        }
        if(json.hasOwnProperty('auxiliaries')){
            auxiliaries = json.auxiliaries
        }
        if(json.hasOwnProperty('text')){
            messageText = json.text;
        }
        else{
            messageText = inputContent;
        }
    }
    else{
        messageText = inputContent;
    }

    return {
        messageText: messageText,
        groundingMetadata: groundingMetadata,
        auxiliaries: auxiliaries
    }

}

/**
 * A diagram edited in HAWKI and saved on its message is a file named after
 * the code block it belongs to: drawio-block-<n>.drawio, n being the block's
 * position among the message's draw.io blocks. The code box shows this file in
 * place of the model's original.
 */
const SAVED_DIAGRAM_NAME = /^drawio-block-(\d+)\.drawio$/;

function savedDiagramBlock(name) {
    const match = SAVED_DIAGRAM_NAME.exec(String(name || ''));
    return match ? parseInt(match[1], 10) : null;
}

// Kept on the element, keyed by block: the markup is rebuilt on every render.
function rememberSavedDiagrams(messageElement, attachments) {
    if (!messageElement) {
        return;
    }
    const saved = {};
    (Array.isArray(attachments) ? attachments : []).forEach(attachment => {
        const fileData = attachment?.fileData || attachment;
        const block = savedDiagramBlock(fileData?.name);
        if (block !== null && fileData?.url) {
            saved[block] = { uuid: fileData.uuid, url: fileData.url, name: fileData.name };
        }
    });
    if (Object.keys(saved).length > 0) {
        messageElement.dataset.savedDiagrams = JSON.stringify(saved);
    }
}

function rememberSavedDiagram(messageElement, fileData) {
    const saved = savedDiagramsOf(messageElement);
    const block = fileData?.block ?? savedDiagramBlock(fileData?.name);
    if (block === null || block === undefined) {
        return;
    }
    saved[block] = { uuid: fileData.uuid, url: fileData.url, name: fileData.name };
    messageElement.dataset.savedDiagrams = JSON.stringify(saved);
}

function savedDiagramsOf(messageElement) {
    try {
        const saved = JSON.parse(messageElement?.dataset.savedDiagrams || '{}');
        return saved && typeof saved === 'object' ? saved : {};
    } catch (error) {
        return {};
    }
}

function syncGeneratedImageContent(messageText, auxiliaries, attachments) {
    if (!Array.isArray(auxiliaries) || auxiliaries.length === 0 || !Array.isArray(attachments)) {
        return {
            messageText,
            auxiliaries
        };
    }

    const attachmentFileData = attachments
        .map(attachment => attachment?.fileData)
        .filter(fileData => fileData?.uuid && fileData?.url);

    const imageAttachments = attachmentFileData.filter(fileData =>
        typeof fileData?.mime === 'string' ? fileData.mime.startsWith('image/') : true
    );

    let normalizedAuxiliaries = [...auxiliaries];

    // Backward-compatibility fallback:
    // Older persisted messages may only contain image_preview (base64) entries.
    // In that case, synthesize generated_image auxiliaries from attachment URLs.
    const hasGeneratedImageAux = normalizedAuxiliaries.some(aux => aux?.type === 'generated_image');
    if (!hasGeneratedImageAux && imageAttachments.length > 0) {
        const seenOutputIndices = new Set();
        const previewOutputIndices = normalizedAuxiliaries
            .filter(aux => aux?.type === 'image_preview' && typeof aux.content === 'string')
            .map(aux => {
                try {
                    const d = JSON.parse(aux.content);
                    return Number.isInteger(d?.output_index) ? d.output_index : null;
                } catch (error) {
                    return null;
                }
            })
            .filter(outputIndex => outputIndex !== null && !seenOutputIndices.has(outputIndex) && seenOutputIndices.add(outputIndex));

        const outputIndices = previewOutputIndices.length > 0
            ? previewOutputIndices
            : imageAttachments.map((_, index) => index);

        const synthesizedGeneratedImages = outputIndices
            .slice(0, imageAttachments.length)
            .map((outputIndex, index) => {
                const fileData = imageAttachments[index];
                return {
                    type: 'generated_image',
                    content: JSON.stringify({
                        output_index: outputIndex,
                        url: fileData.url,
                        uuid: fileData.uuid,
                        mime: fileData.mime,
                        name: fileData.name,
                        prompt: 'Generated Image'
                    })
                };
            });

        normalizedAuxiliaries = normalizedAuxiliaries.concat(synthesizedGeneratedImages);
    }

    const attachmentUrlByUuid = new Map(
        attachmentFileData
            .map(fileData => [fileData.uuid, fileData.url])
    );

    let updatedMessageText = replaceBase64ImageUrlsWithAttachmentUrls(
        messageText,
        imageAttachments.map(fileData => fileData.url)
    );

    const syncedAuxiliaries = normalizedAuxiliaries.map(aux => {
        // Container files carry a url the same way and move to persistent storage
        // the same way, so their url is refreshed too.
        if ((aux?.type !== 'generated_image' && aux?.type !== 'container_file') || typeof aux.content !== 'string') {
            return aux;
        }

        try {
            const imageData = JSON.parse(aux.content);
            const persistentUrl = attachmentUrlByUuid.get(imageData.uuid);

            if (!persistentUrl || persistentUrl === imageData.url) {
                return aux;
            }

            if (typeof updatedMessageText === 'string' && imageData.url) {
                updatedMessageText = updatedMessageText.split(imageData.url).join(persistentUrl);
            }

            return {
                ...aux,
                content: JSON.stringify({
                    ...imageData,
                    url: persistentUrl
                })
            };
        } catch (error) {
            console.error('[GENERATED IMAGE] Could not sync image URL:', error);
            return aux;
        }
    });

    return {
        messageText: updatedMessageText,
        auxiliaries: syncedAuxiliaries
    };
}

function replaceBase64ImageUrlsWithAttachmentUrls(messageText, imageUrls) {
    if (typeof messageText !== 'string' || !Array.isArray(imageUrls) || imageUrls.length === 0) {
        return messageText;
    }

    if (!messageText.includes('data:image/')) {
        return messageText;
    }

    let replacementIndex = 0;
    return messageText.replace(
        /data:image\/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+/=]+/g,
        matched => {
            const replacement = imageUrls[replacementIndex];
            if (!replacement) {
                return matched;
            }
            replacementIndex += 1;
            return replacement;
        }
    );
}

function sanitizeAuxiliariesForChatlog(auxiliaries) {
    if (!Array.isArray(auxiliaries) || auxiliaries.length === 0) {
        return [];
    }

    return auxiliaries
        .filter(aux => aux && aux.type !== 'image_preview')
        .map(aux => {
            if (aux?.type !== 'generated_image' || typeof aux.content !== 'string') {
                return aux;
            }

            try {
                const imageData = JSON.parse(aux.content);
                if (imageData && typeof imageData === 'object' && 'preview_data' in imageData) {
                    delete imageData.preview_data;
                    return {
                        ...aux,
                        content: JSON.stringify(imageData)
                    };
                }
            } catch (error) {
                console.error('[MESSAGE] Could not sanitize generated image auxiliary:', error);
            }

            return aux;
        });
}

function rebuildRawContent(inputContent, messageText, groundingMetadata, auxiliaries) {
    if (!isValidJson(inputContent)) {
        return inputContent;
    }

    try {
        const rawContent = JSON.parse(inputContent);
        rawContent.text = messageText;
        rawContent.groundingMetadata = groundingMetadata;
        rawContent.auxiliaries = auxiliaries;

        return JSON.stringify(rawContent);
    } catch (error) {
        console.error('[MESSAGE] Could not rebuild raw content:', error);
        return inputContent;
    }
}


function isValidJson(string) {
    try {
        JSON.parse(string);
        return true;
    } catch (e) {
        return false;
    }
}

// Helper function to escape special characters in regular expressions
function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}


/// Finds out if HAWKI is mentioned in the text.
/// rawText = text from input field or decrypted from server.
function detectMentioning(rawText){
    // aiMentioned: if AI is mentioned
    // filteredText: text without mentioning,
    // modifiedText: text with mentioning (bold),
    // aiMention: the mentioning of ai,
    // userMentions: mentioning members of the room.
    let returnObj = {
        aiMentioned: false,
        filteredText: rawText,
        modifiedText: rawText,
        aiMention: "",
        userMentions: []
    };

    const mentionRegex = /@\w+/g;
    const mentionMatches = rawText.match(mentionRegex);

    if (mentionMatches) {
        let processedText = rawText;

        for (const mention of mentionMatches) {
            if (mention.toLowerCase() === aiHandle.toLowerCase()) {
                returnObj.aiMentioned = true;
                returnObj.aiMention = mention; // Remove the '@' for aiMention
                processedText = processedText.replace(new RegExp(mention, 'i'), '').trim();
            } else {
                returnObj.userMentions.push(mention.substring(1)); // Remove the '@' for other mentions
            }
        }
        returnObj.filteredText = processedText;
        returnObj.modifiedText = rawText.replace(mentionRegex, (match) => `<b>${match.toLowerCase()}</b>`);
    }
    return returnObj;
}


function setMessageStatusAsRead(messageElement){
    messageElement.dataset.read_stat = true;
    messageElement.querySelector('#unread-message-icon').style.display = "none";
}

//#region MSG_CTL: COPY

function activateMessageControls(msgElement){

    if(!msgElement.classList.contains('me') && msgElement.querySelector('#edit-btn')){
        msgElement.querySelector('#edit-btn').remove();
    }
    if(!msgElement.classList.contains('AI') && msgElement.querySelector('#regenerate-btn')){
        msgElement.querySelector('#regenerate-btn').remove();
    }
    const codeBlocks = msgElement.querySelectorAll('pre');
    for (let i = 0; i < codeBlocks.length; i++) {
        const code = codeBlocks[i];
        const header = code.querySelector('.hljs-code-header');

        // The code box builds its own copy button into .code-actions, which
        // carries .copy-btn too - so look for one in the whole box, not just in
        // the header, or chat ends up offering two.
        const box = code.closest('.code-block-wrapper') || code;

        if (header && !box.querySelector('.copy-btn')) {
            const copyBtnTemp = document.getElementById('copy-btn-template');
            const clone = document.importNode(copyBtnTemp.content, true);
            const copyBtn = clone.querySelector('.copy-btn');

            if (copyBtn) {
                copyBtn.addEventListener("click", function() {
                    copyCodeBlock(copyBtn);
                });
                header.appendChild(copyBtn);
            }
        }
    }

    frameMessageImages(msgElement);

    const mathBlocks = msgElement.querySelectorAll('.math');
    for (let i = 0; i < mathBlocks.length; i++) {
        const mathBlock = mathBlocks[i];

        if (!mathBlock.querySelector('.copy-btn')) {
            const copyBtnTemp = document.getElementById('copy-btn-template');
            const clone = document.importNode(copyBtnTemp.content, true);
            const copyBtn = clone.querySelector('.copy-btn');
            copyBtn.classList.add('math-copy-btn');

            copyBtn.addEventListener("click", function() {
                copyMathBlock(mathBlock);
            });
            mathBlock.appendChild(copyBtn);
        }
    }
    const controls = msgElement.querySelector('.message-controls');
    controls.style.display = 'flex';
}

function copyCodeBlock(btn) {
    const codeBlock = btn.closest('pre').querySelector('code');
    const clone = codeBlock.cloneNode(true);
    const msgTxt = clone.textContent.trim();
    const trimmedMsg = msgTxt.trim();
    navigator.clipboard.writeText(trimmedMsg);
}

function copyMathBlock(block){
    const m = block.dataset.rawmath;
    navigator.clipboard.writeText(m);
}

// Copies content of the message box without the css attributes
async function CopyMessageToClipboard(provider) {
    const messageElement = provider.closest('.message');

    // Get the text content of the modified clone
    const text = (messageElement.dataset.rawMsg || '').trim();

    // Generated images are rendered into .image-generation-container, outside
    // .message-text, and are never mirrored into rawMsg. An image-only reply
    // therefore has an empty rawMsg and would put nothing on the clipboard.
    const images = Array.from(messageElement.querySelectorAll('img.generated-image'));

    try {
        if (images.length === 0) {
            if (text) {
                await navigator.clipboard.writeText(text);
            }
            return;
        }

        const imageUrls = images.map(img => new URL(img.getAttribute('src'), window.location.href).href);
        // Some replies already carry the image as markdown in the text; only
        // append the urls that are not in there yet.
        const missingUrls = imageUrls.filter(url => !text.includes(url));
        const textFallback = [text, ...missingUrls].filter(Boolean).join('\n\n');

        // The clipboard holds a single bitmap, so multiple images are copied as
        // the first image plus every url in the text flavour.
        if (window.ClipboardItem && navigator.clipboard?.write) {
            // The blob is handed over as a promise so the write stays inside
            // the click gesture - Safari rejects a write resolved after it.
            const pngBlob = generatedImageAsPngBlob(images[0]);
            // write() usually surfaces this rejection, but not if it fails first.
            pngBlob.catch(() => {});

            try {
                await navigator.clipboard.write([
                    new ClipboardItem({
                        'image/png': pngBlob,
                        'text/plain': new Blob([textFallback], {type: 'text/plain'})
                    })
                ]);
                return;
            } catch (error) {
                console.error('[GENERATED IMAGE] Could not copy the image itself, falling back to urls:', error);
            }
        }

        await navigator.clipboard.writeText(textFallback);
    } catch (error) {
        console.error('[MESSAGE] Could not copy message to clipboard:', error);
    }
}

// Resolves a rendered generated image to a png blob, the only image type the
// clipboard accepts across browsers.
async function generatedImageAsPngBlob(img) {
    const response = await fetch(img.src, {credentials: 'same-origin'});
    if (!response.ok) {
        throw new Error(`Image request failed with status ${response.status}`);
    }

    const blob = await response.blob();
    if (blob.type === 'image/png') {
        return blob;
    }

    const bitmap = await createImageBitmap(blob);
    try {
        const canvas = document.createElement('canvas');
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        canvas.getContext('2d').drawImage(bitmap, 0, 0);

        return await new Promise((resolve, reject) => {
            canvas.toBlob(
                pngBlob => pngBlob ? resolve(pngBlob) : reject(new Error('Could not encode the image as png')),
                'image/png'
            );
        });
    } finally {
        bitmap.close();
    }
}

function copyCodeBlockToClipboard(provider) {
    const codeBlock = provider.closest('pre').querySelector('code');

    // Get the text content of the modified clone
    const content = codeBlock.innerHTML;

    const trimmedCont = content.trim();
    navigator.clipboard.writeText(trimmedMsg);
}

//#endregion


//#region MSG_CTL: EDIT


function editMessage(provider){
    const msgControls = provider.closest('.message-controls');
    const controls = msgControls.querySelector('.controls');
    const editControls = msgControls.querySelector('.edit-bar');

    controls.style.display = 'none';
    editControls.style.display = 'flex';

    const message = provider.closest('.message');
    const wrapper = message.querySelector('.message-wrapper');
    wrapper.classList.add('edit-mode');

    const content = message.querySelector('.message-content');

    /// PASTE STYLE
    content.addEventListener("paste", function(e) {
        e.preventDefault();

        // Get the plain text from clipboard
        let text = (e.clipboardData || window.clipboardData).getData("text/plain");

        // Get selection and range
        const selection = window.getSelection();
        if (!selection.rangeCount) return;
        const range = selection.getRangeAt(0);

        // Split text by lines
        const lines = text.split(/\r?\n/);
        // Create a DocumentFragment to hold nodes
        const fragment = document.createDocumentFragment();

        for (let i = 0; i < lines.length; i++) {
            if(i > 0) fragment.appendChild(document.createElement("br"));
            fragment.appendChild(document.createTextNode(lines[i]));
        }

        // Insert the fragment at the cursor
        range.deleteContents();
        range.insertNode(fragment);

        // Move cursor to the end of the pasted content
        // Create a new range after the inserted content
        range.collapse(false);
        selection.removeAllRanges();
        selection.addRange(range);
    });


    content.setAttribute('contenteditable', true);
    content.dataset.tempContent = content.innerHTML;
    const rawMsg = content.closest('.message').dataset.rawMsg;
    content.innerHTML = escapeHTML(rawMsg).replace(/\n/g, '<br>');

    content.focus();

    var range,selection;
    if(document.createRange)
    {
        range = document.createRange();
        range.selectNodeContents(content);
        range.collapse(false);
        selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
    }
    else if(document.selection)
    {
        range = document.body.createTextRange();
        range.moveToElementText(content);
        range.collapse(false);
        range.select();
    }
}

function abortEditMessage(provider){
    const msgControls = provider.closest('.message-controls');
    const controls = msgControls.querySelector('.controls');
    const editControls = msgControls.querySelector('.edit-bar');
    controls.style.display = 'flex';
    editControls.style.display = 'none';



    const wrapper = provider.closest('.message-wrapper');
    wrapper.classList.remove('edit-mode');


    // if(wrapper.querySelectorAll('.attachment').length > 0){
    //     const atchs = wrapper.querySelectorAll('.attachment');
    //     atchs.forEach(atch => {
    //         atch.classList.remove('edit-mode');
    //         const rmBtn = atch.querySelector('.remove-btn');
    //         rmBtn.disabled = true;
    //         rmBtn.style.display = 'none';
    //     })
    // };


    const content = wrapper.querySelector('.message-content');
    content.setAttribute('contenteditable', false);
    content.innerHTML = content.dataset.tempContent;
    content.removeAttribute('data-temp-content')
}

async function confirmEditMessage(provider){
    const msgControls = provider.closest('.message-controls');
    const messageElement = provider.closest('.message');

    if(!messageElement.classList.contains('me')){
        return;
    }

    const controls = msgControls.querySelector('.controls');
    const editControls = msgControls.querySelector('.edit-bar');
    controls.style.display = 'flex';
    editControls.style.display = 'none';

    const wrapper = provider.closest('.message-wrapper');
    wrapper.classList.remove('edit-mode');

    const content = wrapper.querySelector('.message-content');
    content.setAttribute('contenteditable', false);

    const cont = content.innerText;
    messageElement.dataset.rawMsg = cont;

    content.innerHTML = content.dataset.tempContent;
    content.removeAttribute('data-temp-content');

    messageElement.dataset.rawMsg = cont;
    messageElement.querySelector(".message-text").innerHTML = detectMentioning(cont).modifiedText;

    let key;
    let url;

    switch(activeModule){
        case('chat'):
            url = `/req/conv/updateMessage/${activeConv.slug}`
            key = await keychainGet('aiConvKey');
        break;
        case('groupchat'):
            url = `/req/room/updateMessage/${activeRoom.slug}`
            const roomKey = await keychainGet(`${activeRoom.slug}`);

            if(messageElement.dataset.role === 'assistant'){
                const aiCryptoSalt = await fetchServerSalt('AI_CRYPTO_SALT');
                key = await deriveKey(roomKey, activeRoom.slug, aiCryptoSalt);
            }else{
                key = roomKey;
            }
        break;
    }

    const cryptoMsg = await encryptWithSymKey(key, cont, false);
    const messageObj = {
        'content':{
                'text': {
                    'ciphertext': cryptoMsg.ciphertext,
                    'iv': cryptoMsg.iv,
                    'tag': cryptoMsg.tag,
                }
            },
        'isAi': false,
        'model': '',
        'completion': true,
        'message_id': messageElement.id,
    }

    requestMsgUpdate(messageObj, messageElement ,url);
}

//#endregion

//#region MSG_CTL: REGENERATE

async function onRegenerateBtn(btn){
    btn.disabled = true;
    btn.style.opacity = '.2';
    btn.classList.add('regenerating'); // Add animation class
    const messageElement = btn.closest('.message');

    regenerateMessage(messageElement, async(Done)=>{
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.classList.remove('regenerating'); // Remove animation class
    });
}

async function regenerateMessage(messageElement, Done = null){
    if(!messageElement.classList.contains('AI')){
        if(Done) Done(true);
        return;
    }

    // Check if activeModel is set
    if(!activeModel){
        console.error('No active model selected. Cannot regenerate message.');
        alert('Bitte wählen Sie ein Modell aus, bevor Sie eine Nachricht regenerieren.');
        if(Done) Done(true);
        return;
    }

    const threadIndex = messageElement.closest('.thread').id;

    //reset message content
    messageElement.querySelector('.message-text').innerHTML = '';
    messageElement.dataset.rawMsg = '';

    // Remove Google search sources
    if(messageElement.querySelector('.google-search')){
        messageElement.querySelector('.google-search').remove();
    }

    // Remove Anthropic citations
    if(messageElement.querySelector('.anthropic-sources')){
        messageElement.querySelector('.anthropic-sources').remove();
    }

    // Remove Responses API (OpenAI) citations/sources
    if(messageElement.querySelector('.responses-sources')){
        messageElement.querySelector('.responses-sources').remove();
    }

    // Remove the sources of a HAWKI run web search. A regeneration that finds
    // nothing emits no citations auxiliary at all, so the renderer never runs
    // and would leave the previous generation's chips under the new answer.
    if(messageElement.querySelector('.hawki-sources')){
        messageElement.querySelector('.hawki-sources').remove();
    }

    // Remove AI status indicators (Reasoning summaries, Web search queries)
    if(messageElement.querySelector('.ai-status-indicator')){
        messageElement.querySelector('.ai-status-indicator').remove();
    }

    // Remove the images of the previous generation. They live outside
    // .message-text, so clearing the text does not take them with it.
    messageElement.querySelectorAll('.image-generation-container').forEach(container => {
        container.remove();
    });

    // Clear status log data from dataset
    if(messageElement.dataset.statusLog){
        delete messageElement.dataset.statusLog;
    }

    // Drop the auxiliaries of the previous generation, otherwise the old
    // generated_image entries outlive the image they described.
    if(messageElement.dataset.auxiliaries){
        delete messageElement.dataset.auxiliaries;
    }
    if(messageElement.dataset.rawContent){
        delete messageElement.dataset.rawContent;
    }
    // The plots of the previous answer are gone with it; remembered, they would
    // be drawn under the new code as a fallback next to the new plot.
    if(messageElement.dataset.inlinePlots){
        delete messageElement.dataset.inlinePlots;
    }
    if(messageElement.dataset.containerFiles){
        delete messageElement.dataset.containerFiles;
    }

    initializeMessageFormating();

    let inputContainer;
    if(threadIndex == 0){
        inputContainer = document.querySelector(`.input[id="0"]`).closest('.input-container');
    }
    else{
        inputContainer = messageElement.closest('.thread').querySelector('.input-container');
    }

    const webSearchBtn = inputContainer ? inputContainer.querySelector('#websearch-btn') : null;
    const webSearchActive = webSearchBtn ? webSearchBtn.classList.contains('active') : false;
    
    const reasoningBtn = inputContainer ? inputContainer.querySelector('#reasoning-btn') : null;
    const reasoningActive = reasoningBtn ? reasoningBtn.classList.contains('active') : false;
    
    // Get reasoning effort if reasoning is active
    let reasoningEffort = null;
    if (reasoningActive) {
        reasoningEffort = reasoningBtn.dataset.effort || 'medium';
    }

    const imageGenerationBtn = inputContainer ? inputContainer.querySelector('#image-generation-btn') : null;
    const imageGenerationActive = imageGenerationBtn ? imageGenerationBtn.classList.contains('active') : false;
    // Set by the gallery's aspect ratio action for one message.
    const imageGenerationRatio = imageGenerationActive && imageGenerationBtn
        ? (imageGenerationBtn.dataset.ratio || null)
        : null;

    const tools = {
        'web_search': webSearchActive,
        'image_generation': imageGenerationActive
    }

    let msgAttributes = {};
    switch(activeModule){
        case('chat'):
            msgAttributes = {
                'threadIndex': threadIndex,
                'broadcasting': false,
                'slug': '',
                'regenerationElement': messageElement,
                'stream': activeModel.tools?.stream ? true : false,
                'model': activeModel.id,
                'tools': tools
            }
            
            // Add reasoning_effort if set
            if (reasoningEffort !== null) {
                msgAttributes['reasoning_effort'] = reasoningEffort;
            }
            if (imageGenerationRatio !== null) {
                msgAttributes['image_generation_ratio'] = imageGenerationRatio;
            }

            await buildRequestObjectForAiConv(msgAttributes, messageElement, true, async(isDone)=>{
                if(Done){
                    Done(true);
                }
            });
        break;
        case('groupchat'):
            const roomKey = await keychainGet(activeRoom.slug);
            const aiCryptoSalt = await fetchServerSalt('AI_CRYPTO_SALT');
            const aiKey = await deriveKey(roomKey, activeRoom.slug, aiCryptoSalt);
            const aiKeyRaw = await exportSymmetricKey(aiKey);
            const aiKeyBase64 = arrayBufferToBase64(aiKeyRaw);

            msgAttributes = {
                'threadIndex': threadIndex,
                'broadcasting': true,
                'slug': activeRoom.slug,
                'key': aiKeyBase64,
                'regenerationElement': messageElement,
                'stream': false,
                'model': activeModel.id,
                'tools': tools
            }
            if (imageGenerationRatio !== null) {
                msgAttributes['image_generation_ratio'] = imageGenerationRatio;
            }
            buildRequestObject(msgAttributes,  async (updatedText, done) => {
                if(done && Done){
                    Done(true);
                }
            });
        break;
    }
}
//#endregion

//#region MSG_CTL: TTS

let currentUtterance = null; // Track the current utterance
let previousProvider = null; // Track the previous provider (button)

const readIcon =
`<svg>
    <path d="M8.25 3.75L4.5 6.75H1.5V11.25H4.5L8.25 14.25V3.75Z" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M14.3018 3.69727C15.7078 5.10372 16.4977 7.01103 16.4977 8.99977C16.4977 10.9885 15.7078 12.8958 14.3018 14.3023M11.6543 6.34477C12.3573 7.04799 12.7522 8.00165 12.7522 8.99602C12.7522 9.99038 12.3573 10.944 11.6543 11.6473" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>`
const stopReadIcon =
`<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <circle cx="12" cy="12" r="10"></circle>
    <rect x="9" y="9" width="6" height="6"></rect>
</svg>`

function messageReadAloud(provider) {
    const synth = window.speechSynthesis;

    // Check if the same button was clicked
    if (provider === previousProvider) {
        if (synth.speaking) {
            synth.cancel();
            currentUtterance = null;
            previousProvider = null;
            // Change icon back to "volume"
            provider.innerHTML = readIcon;
        }
        return;
    }

    if (synth.speaking) {
        synth.cancel();
        currentUtterance = null;
        previousProvider.innerHTML = readIcon;
    }
    // Start speaking and change icon to "stop"
    const msgText = provider.closest(".message").dataset.rawMsg;
    const utterance = new SpeechSynthesisUtterance(msgText);

    currentUtterance = utterance;
    previousProvider = provider;
    provider.innerHTML = stopReadIcon;

    synth.speak(utterance);

    // Reset icon when speech ends
    utterance.onend = () => {
        if (provider === previousProvider) {
            previousProvider = null;
            provider.innerHTML = readIcon;
        }
    };
}



//#endregion


//#region GENERATED IMAGE GALLERY

// Delegated, so streamed, reloaded and group chat images are all covered without
// rebinding a handler after every render.
document.addEventListener('click', event => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const image = event.target.closest('.generated-image');
    if (image) {
        openGeneratedImageGallery(image);
        return;
    }

    // The ratio menu closes on any click outside of its own group.
    if (!event.target.closest('.gallery-tool-group')) {
        closeGalleryRatioMenu();
    }

    // Clicking the backdrop closes the gallery, clicking the panel does not.
    const modal = document.getElementById('image-gallery-modal');
    if (modal && event.target === modal) {
        modal.style.display = 'none';
    }
});

document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') {
        return;
    }

    const modal = document.getElementById('image-gallery-modal');
    if (modal && modal.style.display === 'flex') {
        modal.style.display = 'none';
    }
});

// The image the gallery is showing. The edit actions need it to find the thread
// the follow-up prompt has to be sent in.
let galleryImageSource = null;

function openGeneratedImageGallery(image) {
    const modal = document.getElementById('image-gallery-modal');
    if (!modal) {
        return;
    }

    galleryImageSource = image;
    closeGalleryRatioMenu();

    // The prompt is hidden next to the message and only read out here.
    const wrapper = image.closest('.generated-image-wrapper');
    const promptElement = wrapper ? wrapper.querySelector('.image-prompt') : null;
    const prompt = promptElement ? promptElement.textContent.trim() : '';

    const galleryImage = modal.querySelector('#gallery-image');
    galleryImage.src = image.src;
    galleryImage.alt = image.alt || prompt;

    modal.querySelector('#gallery-prompt-text').textContent = prompt;
    // Without a prompt the panel gives the whole width to the image.
    modal.querySelector('.gallery-panel').classList.toggle('no-prompt', prompt === '');

    modal.style.display = 'flex';
}

/**
 * A picture the model wrote into the text as markdown - a code interpreter plot
 * under the code that drew it - gets the same download button as a generated
 * image. Generated images bring their own frame and are left alone.
 */
function frameMessageImages(messageElement) {
    const text = messageElement.querySelector('.message-text');
    if (!text) {
        return;
    }

    text.querySelectorAll('img').forEach(image => {
        // A picture box carries its button in its own corner.
        if (image.closest('.generated-image-frame, .inline-image-frame, .diagram-preview')) {
            return;
        }
        frameImageForDownload(image);
    });
}

/**
 * Wraps a picture in a frame that hugs it, so the download button lands on the
 * picture rather than next to it. Also used by the code box's output panel.
 */
function frameImageForDownload(image) {
    const frame = document.createElement('span');
    frame.classList.add('inline-image-frame');
    image.replaceWith(frame);
    frame.appendChild(image);

    addImageDownloadButton(frame);
    keepSizelessImageVisible(image, frame);

    return frame;
}

/**
 * An SVG with a viewBox but no width and height has no size of its own. In a
 * block it takes the available width; in this shrink-to-fit frame the two sizes
 * depend on each other and the picture measures 0x0 - it was visible while the
 * answer streamed and vanished when the finished message got its buttons. New
 * files get a size when they are stored; this covers the ones that did not.
 */
function keepSizelessImageVisible(image, frame) {
    // Decided from the layout, so it needs one: a frame that is not in the
    // document yet, or sits in a hidden container, cannot be measured. The chat
    // log builds its messages before they are shown, so the decision waits for
    // the moment the frame is visible.
    const decide = () => {
        if (!image.complete || !frame.isConnected || frame.offsetParent === null) {
            return false;
        }
        const box = image.getBoundingClientRect();
        if (box.width === 0 && box.height === 0) {
            frame.style.display = 'block';
        }
        return true;
    };

    // Polled rather than observed: an IntersectionObserver does not report a
    // 0x0 frame that comes into view. Bounded, so a message that never shows
    // does not keep a timer alive.
    const onLoaded = () => {
        if (decide()) {
            return;
        }
        let attempts = 0;
        const tick = () => {
            if (decide() || attempts++ > 40) {
                return;
            }
            setTimeout(tick, 250);
        };
        setTimeout(tick, 250);
    };

    if (image.complete) {
        onLoaded();
    } else {
        image.addEventListener('load', onLoaded, {once: true});
    }
}

// Clones the shared template into a frame, so the chat log and the gallery use
// the same button markup and icon.
function addImageDownloadButton(frame) {
    if (!frame || frame.querySelector('.image-download-btn')) {
        return;
    }

    const template = document.getElementById('image-download-btn-template');
    if (!template) {
        return;
    }

    frame.appendChild(document.importNode(template.content, true));
}

async function downloadImage(button) {
    const frame = button.closest('.generated-image-frame, .gallery-image-frame, .inline-image-frame, .diagram-preview');
    const image = frame ? frame.querySelector('img') : null;
    // A mermaid diagram is drawn as inline <svg>, not as an image.
    const drawing = !image && frame ? frame.querySelector(':scope > svg') : null;
    // A draw.io box saves its source, which opens in the editor again.
    const source = frame?.dataset.source;
    if ((!image || !image.getAttribute('src')) && !drawing && !source) {
        return;
    }

    button.disabled = true;

    try {
        let blob;
        let name;

        if (source) {
            blob = new Blob([source], {type: frame.dataset.downloadType || 'text/plain'});
            name = frame.dataset.downloadName || 'file.txt';
        } else if (drawing) {
            blob = new Blob([new XMLSerializer().serializeToString(drawing)], {type: 'image/svg+xml'});
            name = 'diagram.svg';
        } else {
            // Fetched as a blob so the signed url is not handed to the download
            // attribute, which would navigate instead of saving on some browsers.
            const response = await fetch(image.src, {credentials: 'same-origin'});
            if (!response.ok) {
                throw new Error(`Image request failed with status ${response.status}`);
            }
            blob = await response.blob();
            // The stable attachment url carries the uuid, not the file name; the
            // server names the file in the response.
            name = fileNameFromDisposition(response.headers.get('Content-Disposition'))
                || downloadImageFileName(image.src, blob.type);
        }

        const objectUrl = URL.createObjectURL(blob);

        const link = document.createElement('a');
        link.href = objectUrl;
        link.download = name;

        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(objectUrl);
    } catch (error) {
        console.error('[GENERATED IMAGE] Could not download the image:', error);
    } finally {
        button.disabled = false;
    }
}

function toggleGalleryRatioMenu(button) {
    const menu = button.closest('.gallery-tool-group')?.querySelector('.gallery-ratio-menu');
    menu?.classList.toggle('open');
}

function closeGalleryRatioMenu() {
    document.getElementById('gallery-ratio-menu')?.classList.remove('open');
}

/**
 * Attaches the image the gallery is showing and hands the caret to the input, so
 * the user can write their own message about it. Needed for any image that is not
 * the newest one, since only that one is preselected on its own.
 */
function commentOnGalleryImage() {
    const image = galleryImageSource;
    const inputField = inputFieldForMessage(image);
    if (!image || !inputField) {
        console.error('[GENERATED IMAGE] No input field found for the gallery image.');
        return;
    }

    closeGalleryRatioMenu();
    document.getElementById('image-gallery-modal').style.display = 'none';

    removeStoredAttachments(inputField);
    attachStoredFile(inputField, {
        uuid: image.dataset.uuid,
        name: image.dataset.name,
        mime: image.dataset.mime,
        url: image.getAttribute('src'),
    });

    // No prompt and no image generation: the user writes the message themselves.
    selectActiveThread(inputField);
    inputField.focus();
}

function removeGalleryImageBackground() {
    sendGalleryImagePrompt(
        translation?.RemoveBackgroundPrompt
        || 'Remove the background from this image. Keep every subject in the foreground completely unchanged, with clean, smooth edges. Make the background transparent.'
    );
}

function applyGalleryAspectRatio(ratio) {
    const template = translation?.AspectRatioPrompt || 'Set the aspect ratio to {ratio}.';
    sendGalleryImagePrompt(template.replace('{ratio}', ratio), ratio);
}

/**
 * Sends a prepared prompt as a normal message in the thread the gallery image
 * belongs to. The previously generated image travels along as conversation
 * context, so the model has the picture the prompt refers to.
 */
async function sendGalleryImagePrompt(prompt, ratio = null) {
    const image = galleryImageSource;
    const inputField = inputFieldForMessage(image);
    if (!image || !inputField) {
        console.error('[GENERATED IMAGE] No input field found for the gallery image.');
        return;
    }

    closeGalleryRatioMenu();
    document.getElementById('image-gallery-modal').style.display = 'none';

    // The prompt talks about this one image, so exactly this one is attached -
    // any earlier preselection is dropped first.
    removeStoredAttachments(inputField);
    attachStoredFile(inputField, {
        uuid: image.dataset.uuid,
        name: image.dataset.name,
        mime: image.dataset.mime,
        url: image.getAttribute('src'),
    });

    // The answer has to be an image again, so image generation is switched on
    // the same way the input button does it - including the model fallback.
    enableImageGeneration(inputField.closest('.input-container'), ratio);

    inputField.value = prompt;
    resizeInputField(inputField);

    selectActiveThread(inputField);

    try {
        await sendMessageConv(inputField);
    } finally {
        // One shot: the ratio belongs to this message, not to the ones after it.
        delete inputField.closest('.input-container')
            ?.querySelector('#image-generation-btn')?.dataset.ratio;
    }
}

// A message's follow-up goes to its own thread's input, or the main one.
function inputFieldForMessage(element) {
    const thread = element?.closest('.thread');
    const inputContainer = (!thread || thread.id === '0')
        ? document.querySelector('.input[id="0"]')?.closest('.input-container')
        : thread.querySelector('.input-container');

    return inputContainer?.querySelector('.input-field') ?? null;
}

/**
 * Offers a generated image as a preselected attachment for the next message.
 * Replaces an earlier preselection, never a file the user picked themselves.
 */
function preselectGeneratedImage(image) {
    const inputField = inputFieldForMessage(image);
    if (!image?.dataset.uuid || !inputField) {
        return;
    }

    removeStoredAttachments(inputField);
    attachStoredFile(inputField, {
        uuid: image.dataset.uuid,
        name: image.dataset.name,
        mime: image.dataset.mime,
        url: image.getAttribute('src'),
    });
}

function enableImageGeneration(inputContainer, ratio = null) {
    const button = inputContainer?.querySelector('#image-generation-btn');
    const input = inputContainer?.querySelector('.input');
    if (!button || !input) {
        return;
    }

    if (ratio) {
        button.dataset.ratio = ratio;
    }

    button.classList.add('active', 'active-set');
    addInputFilter(input.id, 'image_gen');
}

// The stored file name is the last segment of the signed url.
function downloadImageFileName(src, mime = '') {
    const extension = /svg/i.test(mime) || /^data:image\/svg\+xml/i.test(src) ? 'svg'
        : /jpe?g/i.test(mime) ? 'jpg'
        : /webp/i.test(mime) ? 'webp'
        : /gif/i.test(mime) ? 'gif'
        : 'png';

    // A picture the code box rendered from base64 has no name of its own.
    if (src.startsWith('data:')) {
        return 'image.' + extension;
    }

    try {
        const last = decodeURIComponent(new URL(src, window.location.href).pathname.split('/').pop() || '');
        // A stable attachment url ends in the uuid, which is no file name.
        if (last && /\.[a-z0-9]{2,5}$/i.test(last)) {
            return last;
        }
    } catch (error) {
        // fall through
    }

    return 'generated-image.' + extension;
}

// filename*=UTF-8''… first, then filename="…", from a Content-Disposition header.
function fileNameFromDisposition(header) {
    if (!header) {
        return '';
    }
    const star = header.match(/filename\*=(?:UTF-8'')?([^;]+)/i);
    if (star) {
        try {
            return decodeURIComponent(star[1].trim().replace(/^"|"$/g, ''));
        } catch (error) {
            // fall through to the plain name
        }
    }
    const plain = header.match(/filename="?([^";]+)"?/i);
    return plain ? plain[1].trim() : '';
}

//#endregion
