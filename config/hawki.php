<?php

return [
    /*
    |--------------------------------------------------------------------------
    | HAWKI Configuration Attributes
    |--------------------------------------------------------------------------
    |
    | HAWKI Attributes can be set in the .env file.
    |
    |
    |
    | !!! YOU CAN NOT CHANGE THE MIGRATION ATTRIBUTES AFTER MIGRATING THE DATABASE !!!
    */

    'migration' => [
        'name' => env('HAWKI_NAME', 'HAWKI'),
        'username' => env('HAWKI_USERNAME', 'HAWKI'),
        'email' => 'HAWKI@hawk.de',
        'employeetype' => 'system',
        'avatar_id' => env('HAWKI_AVATAR', 'hawkiAvatar.jpg'),
    ],

    'aiHandle' => '@'.ltrim(env('AI_MENTION_HANDLE', 'hawki'), '@ '),

    // use false (JSON files) or true (database)
    'groupchat_active' => true,

    /*
    |--------------------------------------------------------------------------
    | News Page
    |--------------------------------------------------------------------------
    |
    | Enable or disable the news page functionality in the application.
    |
    */
    'news_active' => env('HAWKI_NEWS_ACTIVE', true),

    /*
    |--------------------------------------------------------------------------
    | File Upload
    |--------------------------------------------------------------------------
    |
    | Enable or disable file upload functionality in the application.
    |
    */
    'file_upload' => env('HAWKI_FILE_UPLOAD', true),

    /*
    |--------------------------------------------------------------------------
    | Text Services
    |--------------------------------------------------------------------------
    |
    | Enable or disable text services functionality (Improve, Translate) in the application.
    |
    */
    'text_active' => env('HAWKI_TEXT_ACTIVE', true),

    /*
    |--------------------------------------------------------------------------
    | Web Search
    |--------------------------------------------------------------------------
    |
    | Enable or disable web search functionality in the application.
    |
    */
    'websearch' => env('HAWKI_WEBSEARCH', true),

    /*
    |--------------------------------------------------------------------------
    | Web Search Auto Enable
    |--------------------------------------------------------------------------
    |
    | Web search is opt-out: whenever a model supporting web search is selected,
    | the web search button is switched on automatically. Users can turn it off
    | per chat, and their choice is kept until they change it again.
    | Set this to false to make web search opt-in instead.
    |
    */
    'websearch_auto_enable' => env('HAWKI_WEBSEARCH_AUTO_ENABLE', true),

    /*
    |--------------------------------------------------------------------------
    | Imprint Location
    |--------------------------------------------------------------------------
    |
    | URL to the imprint page (Impressum). This will be displayed in the
    | footer of the login page.
    |
    */
    'imprint_location' => env('IMPRINT_LOCATION', '/imprint'),

    /*
    |--------------------------------------------------------------------------
    | Image Generation
    |--------------------------------------------------------------------------
    |
    | Enable or disable image generation functionality in the application.
    | Only models with 'image' in their output array will show this option.
    |
    */
    'image_generation' => env('HAWKI_IMAGE_GENERATION', true),

    /*
    |--------------------------------------------------------------------------
    | Data Protection Location
    |--------------------------------------------------------------------------
    |
    | URL to the data protection page (Datenschutz). This will be displayed
    | in the footer of the login page. Supports both internal routes and
    | external URLs.
    |
    */
    'dataprotection_location' => env('DATAPROTECTION_LOCATION', '/dataprotection'),

    /*
    |--------------------------------------------------------------------------
    | Accessibility Location
    |--------------------------------------------------------------------------
    |
    | URL to the accessibility statement page (Barrierefreiheit). This will be
    | displayed in the footer of the login page.
    |
    */
    'accessibility_location' => env('ACCESSIBILITY_LOCATION', '/accessibility'),

    /*
    |--------------------------------------------------------------------------
    | AI System
    | This setting gets overwritten by the SettingsService with a value from the db
    |--------------------------------------------------------------------------
    */

    'ai_config_system' => false, // false = config files, true = database

    /*
    |--------------------------------------------------------------------------
    | Style System
    | This setting gets overwritten by the SettingsService with a value from the db
    |--------------------------------------------------------------------------
    */
    'style_config_system' => false,

    /*
    |--------------------------------------------------------------------------
    | Language Controller System
    | This setting gets overwritten by the SettingsService with a value from the db
    |--------------------------------------------------------------------------
    |
    | Controls how the LanguageController loads translations and AI prompts:
    |
    | false (default) = Load from JSON files + Database prompts
    |                  - System texts from resources/language/*.json
    |                  - Localization texts from resources/language/*.html
    |                  - AI prompts from ai_assistants_prompts table (fallback)
    |                  - Better separation between HAWKI and Orchid
    |
    | true           = Load from Database only
    |                  - System texts from app_system_texts table
    |                  - Localization texts from app_localized_texts table
    |                  - AI prompts from ai_assistants_prompts table
    |                  - Full Orchid Admin Panel integration
    |
    | AI Prompts are available as translation.Default_Prompt, translation.Name_Prompt, etc.
    | in JavaScript regardless of the mode selected.
    |
    */
    'language_controller_system' => false,

    /*
    |--------------------------------------------------------------------------
    | Send Registration Mails
    |--------------------------------------------------------------------------
    |
    | Enable or disable sending registration and approval emails to new users.
    | When enabled, users will receive welcome emails and approval notifications.
    |
    */
    'send_registration_mails' => true,

    /*
    |--------------------------------------------------------------------------
    | Send Group Chat Invitation Mails
    |--------------------------------------------------------------------------
    |
    | Enable or disable sending email notifications for group chat invitations.
    | When enabled, users will receive emails when invited to group chats.
    |
    */
    'send_groupchat_invitation_mails' => true,

    /*
    |--------------------------------------------------------------------------
    | Maximum Attachment Size
    |--------------------------------------------------------------------------
    |
    | The largest chat upload, in megabytes. One value for the browser and the
    | server: the input field refuses a bigger file before it is sent, the
    | upload routes validate against the same number.
    |
    | This is a ceiling, not the effective limit: PHP's upload_max_filesize and
    | post_max_size cap it, and a file over those never reaches Laravel at all.
    | SupportedFormats::maxUploadMb() takes the smallest of the three, and that
    | is what the input field shows and the upload routes validate against - so
    | raising this beyond php.ini changes nothing rather than breaking uploads.
    |
    */
    'attachment_max_mb' => (int) env('HAWKI_ATTACHMENT_MAX_MB', 256),

    /*
    |--------------------------------------------------------------------------
    | Voice Chat Prompt
    |--------------------------------------------------------------------------
    |
    | Appended to the system prompt of a chat or group chat request sent with
    | the open voice input while "Antworten vorlesen" is on (payload flag
    | voice_mode, see StreamController and App\Services\AI\VoiceChatPrompt).
    | The answer is read aloud by the browser's speech synthesis, which reads
    | Markdown syntax and emojis literally. Only that request is affected; the
    | conversation's own system prompt stays as the user wrote it.
    |
    */
    'voice_chat_prompt' => implode("\n", [
        'VOICE CONVERSATION',
        'The user is talking to you by voice, and your answer is read aloud to them by a speech synthesizer.',
        'Answer the way you would speak in a conversation: in natural, flowing sentences, in the language the user speaks.',
        '- No bullet points, numbered lists, tables, headings or other Markdown formatting. If there are several points, connect them in sentences ("first ..., then ...").',
        '- No emojis, emoticons or decorative symbols: they would be read out literally.',
        '- No code blocks, URLs or formulas unless the user explicitly asks for them; describe them in words instead.',
        '- Keep it as short as a spoken reply. Offer to go into more detail instead of covering everything at once.',
    ]),
];
