<?php

use App\Http\Controllers\AiConvController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AuthenticationController;
use App\Http\Controllers\GlossaryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\LocalRegistrationController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\StreamController;
use App\Http\Controllers\TranslateController;
use App\Http\Controllers\TranslationApiController;
use App\Http\Controllers\Transcription\TranscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware('prevent_back')->group(function () {

    Route::get('/', [LoginController::class, 'index']);

    Route::get('/login', [LoginController::class, 'index'])
        ->name('login');

    Route::get('/req/login', [AuthenticationController::class, 'handleLogin'])
        ->name('web.auth.login.get');
    Route::post('/req/login', [AuthenticationController::class, 'handleLogin'])
        ->name('web.auth.login.post');
    Route::post('/req/submit-guest-request', [LocalRegistrationController::class, 'submitGuestRequest']);

    // Dynamic CSS route
    Route::get('/css/{name}', [AssetController::class, 'serveCss'])->name('css.get');
    Route::get('/system-image/{name}', [AssetController::class, 'getSystemImage'])->name('system.image');

    /*
     * Those routes are deprecated and will be removed in future releases.
     * They are merely aliases now and will log a warning when accessed.
     */
    Route::middleware('deprecated:/req/login')->group(function () {
        Route::post('/req/login-ldap', [AuthenticationController::class, 'handleLogin']);
        Route::post('/req/login-shibboleth', [AuthenticationController::class, 'handleLogin']);
        Route::get('/req/login-shibboleth', [AuthenticationController::class, 'handleLogin'])
            ->name('web.auth.shibboleth.login');
        Route::post('/req/login-oidc', [AuthenticationController::class, 'handleLogin']);
        Route::get('/req/login-oidc', [AuthenticationController::class, 'handleLogin']);
        Route::post('/req/login-local', [AuthenticationController::class, 'handleLogin']);
    });

    Route::post('/req/changeLanguage', [LanguageController::class, 'changeLanguage']);

    // WebAuthn passkey management during handshake
    Route::middleware('auth')->post('/req/user/set-webauthn-pk', function (Illuminate\Http\Request $request) {
        $user = $request->user();
        $user->webauthn_pk = $request->input('has_passkey', false);
        $user->save();

        return response()->json(['success' => true, 'webauthn_pk' => $user->webauthn_pk]);
    });

    Route::get('/inv/{tempHash}/{slug}', [InvitationController::class, 'openExternInvitation'])->name('open.invitation')->middleware('signed');

    Route::get('/dataprotection', [HomeController::class, 'dataprotectionIndex']);
    Route::get('/accessibility', [HomeController::class, 'accessibilityIndex']);
    Route::get('/imprint', [HomeController::class, 'imprintIndex']);

    Route::middleware('registrationAccess')->group(function () {

        Route::get('/register', [AuthenticationController::class, 'register']);
        Route::post('/req/profile/validatePasskey', [ProfileController::class, 'validatePasskey']);
        Route::post('/req/profile/backupPassKey', [ProfileController::class, 'backupPassKey']);
        Route::get('/req/crypto/getServerSalt', [ProfileController::class, 'getServerSalt']);
        Route::post('/req/complete_registration', [AuthenticationController::class, 'completeRegistration']);

    });

    Route::get('/check-session', [HomeController::class, 'CheckSessionTimeout']);
    Route::get('/test-download', function() { return response()->download(storage_path('app/public/test.txt'), 'my_test_file_name.txt'); });

    // Translate routes
    Route::middleware(['auth', 'expiry_check', 'textAccess'])->group(function () {
        Route::get('/text', [TranslateController::class, 'index']);
        
        // Document Download & View (No signature check because these are direct browser links)
        Route::get('/req/text/view-document/{downloadId}', [TranslationApiController::class, 'viewDocument']);
        Route::get('/req/text/download-document/{downloadId}', [TranslationApiController::class, 'downloadDocument']);
    });

    // Announcement routes
    Route::get('/req/announcement/render/{id}', [AnnouncementController::class, 'render']);
    Route::post('/req/announcement/seen/{id}', [AnnouncementController::class, 'markSeen']);
    Route::post('/req/announcement/report/{id}', [AnnouncementController::class, 'submitReport']);
    Route::get('/req/announcement/fetchLatestPolicy', [AnnouncementController::class, 'fetchLatestPolicy']);

    // CHECKS USERS AUTH
    Route::middleware(['auth', 'expiry_check'])->group(function () {

        Route::get('/handshake', [AuthenticationController::class, 'handshake']);

        // AI CONVERSATION ROUTES
        Route::middleware('chatAccess')->group(function () {
            Route::get('/chat', [HomeController::class, 'index']);
        });

        Route::middleware('transcriptionAccess')->group(function () {
            Route::get('/transcript', [HomeController::class, 'index']);
            Route::get('/transcript/{slug?}', [HomeController::class, 'index']);
            Route::post('/transcript/upload', [TranscriptionController::class, 'transcribe']);
        });

        Route::middleware('groupChatAccess')->group(function () {
            Route::get('/groupchat', [HomeController::class, 'index']);
        });

        Route::middleware('signature_check')->group(function () {

            // Glossary Management
            Route::middleware('textAccess')->group(function () {
                // Translation API
                Route::post('/req/text/process', [TranslationApiController::class, 'translate'])
                    ->middleware('throttle:60,1');

                // Text Improvement
                Route::post('/req/text/improve', [TranslationApiController::class, 'write'])
                    ->middleware('throttle:60,1');
                Route::get('/req/text/models', [TranslationApiController::class, 'getModels']);
                Route::post('/req/text/detect-language', [TranslationApiController::class, 'detectLanguage'])
                    ->middleware('throttle:60,1');

                // Document Translation
                Route::post('/req/text/translate-document', [TranslationApiController::class, 'translateDocument'])
                    ->middleware('throttle:10,1');
                Route::get('/req/text/document-status/{jobId}', [TranslationApiController::class, 'documentStatus']);
                Route::get('/req/text/translated-documents', [TranslationApiController::class, 'listTranslatedDocuments']);
                Route::delete('/req/text/delete-document/{downloadId}', [TranslationApiController::class, 'deleteDocument']);

                // Glossary Management
                Route::get('/req/glossary', [GlossaryController::class, 'index']);
                Route::post('/req/glossary', [GlossaryController::class, 'store']);
                Route::post('/req/glossary/import', [GlossaryController::class, 'import']);
                Route::put('/req/glossary/{id}', [GlossaryController::class, 'update']);
                Route::get('/req/glossary/{id}', [GlossaryController::class, 'show']);
                Route::delete('/req/glossary/{id}', [GlossaryController::class, 'destroy']);
            });

            Route::middleware('chatAccess')->group(function () {
                Route::get('/chat/{slug?}', [HomeController::class, 'index']);

                Route::get('/req/conv/{slug?}', [AiConvController::class, 'load']);
                Route::post('/req/conv/createChat', [AiConvController::class, 'create']);
                Route::post('/req/conv/loadMore', [AiConvController::class, 'loadMoreConversations']);
                Route::post('/req/conv/sendMessage/{slug}', [AiConvController::class, 'sendMessage']);
                Route::post('/req/conv/updateMessage/{slug}', [AiConvController::class, 'updateMessage']);
                Route::post('/req/conv/updateInfo/{slug}', [AiConvController::class, 'update']);
                Route::post('/req/conv/updateTitle/{slug}', [AiConvController::class, 'updateTitle']);
                Route::delete('/req/conv/removeConv/{slug}', [AiConvController::class, 'delete']);

                Route::delete('/req/conv/message/delete/{slug}', [AiConvController::class, 'deleteMessage']);

                Route::post('/req/conv/attachment/upload', [AiConvController::class, 'storeAttachment']);
                Route::get('/req/conv/attachment/getLink/{uuid}', [AiConvController::class, 'getAttachmentUrl']);

                Route::get('/files/{uuid}/private/{path}', [AiConvController::class, 'downloadAttachment'])
                    ->where([
                        'path' => '.*',
                    ])->name('files.download.private')->middleware('signed');

                Route::delete('/req/conv/attachment/delete', [AiConvController::class, 'deleteAttachment']);
                Route::post('/req/streamAI', [StreamController::class, 'handleAiConnectionRequest']);
            });

            // GROUPCHAT ROUTES
            Route::middleware('groupChatAccess')->group(function () {
                Route::get('/groupchat/{slug?}', [HomeController::class, 'index']);

                Route::get('/req/room/{slug?}', [RoomController::class, 'load']);
                Route::post('/req/room/createRoom', [RoomController::class, 'create']);

                Route::delete('/req/room/leaveRoom/{slug}', [RoomController::class, 'leaveRoom']);
                Route::post('/req/room/readstat/{slug}', [RoomController::class, 'markAsRead']);
                Route::post('/req/room/markAllAsRead/{slug}', [RoomController::class, 'markAllAsRead']);
                Route::get('/req/room/message/get/{slug}/{messageId}', [RoomController::class, 'retrieveMessage']);
                Route::get('/req/room/attachment/getLink/{uuid}', [RoomController::class, 'getAttachmentUrl']);
                Route::get('/files/{uuid}/group/{path}', [RoomController::class, 'downloadAttachment'])
                    ->where([
                        'path' => '.*',
                    ])->name('files.download.group')->middleware('signed');

                Route::middleware('roomEditor')->group(function () {
                    Route::post('/req/room/sendMessage/{slug}', [RoomController::class, 'sendMessage']);
                    Route::post('/req/room/updateMessage/{slug}', [RoomController::class, 'updateMessage']);
                    Route::post('/req/room/streamAI/{slug}', [StreamController::class, 'handleAiConnectionRequest']);

                    Route::post('/req/room/attachment/upload/{slug}', [RoomController::class, 'storeAttachment']);
                });

                Route::middleware('roomAdmin')->group(function () {
                    Route::post('/req/room/updateInfo/{slug}', [RoomController::class, 'update']);
                    Route::post('/req/room/uploadAvatar/{slug}', [RoomController::class, 'uploadAvatar']);
                    Route::post('/req/room/removeAvatar/{slug}', [RoomController::class, 'removeAvatar']);
                    Route::delete('/req/room/removeRoom/{slug}', [RoomController::class, 'delete']);
                    Route::post('/req/room/addMember/{slug}', [RoomController::class, 'addMember']);
                    Route::delete('/req/room/removeMember/{slug}', [RoomController::class, 'kickMember']);
                });
                Route::delete('/req/room/attachment/delete', [RoomController::class, 'deleteAttachment']);

                Route::post('/req/room/search', [RoomController::class, 'searchUser']);
            });

            Route::get('print/{module}/{slug}', [HomeController::class, 'print']);

            // Invitation Handling

            // Route::post('/req/room/requestPublicKeys', [InvitationController::class, 'onRequestPublicKeys']);
            Route::post('/req/inv/store-invitations/{slug}', [InvitationController::class, 'storeInvitations']);
            Route::post('/req/inv/sendExternInvitation', [InvitationController::class, 'sendExternInvitationEmail']);
            Route::post('/req/inv/roomInvitationAccept', [InvitationController::class, 'onAcceptInvitation']);
            Route::post('/req/inv/convertTempHashInvitation', [InvitationController::class, 'convertTempHashInvitation']);
            Route::delete('/req/inv/deleteInvitation/{slug}', [InvitationController::class, 'deleteInvitation']);
            Route::get('/req/inv/requestInvitation/{slug}', [InvitationController::class, 'getInvitationWithSlug']);
            Route::get('/req/inv/requestUserInvitations', [InvitationController::class, 'getUserInvitations']);

            // Token management routes with token_creation middleware
            Route::middleware('token_creation')->group(function () {
                Route::post('/req/profile/create-token', [ProfileController::class, 'requestApiToken']);
                Route::get('/req/profile/fetch-tokens', [ProfileController::class, 'fetchTokenList']);
                Route::post('/req/profile/revoke-token', [ProfileController::class, 'revokeToken']);
            });
        });

        // Profile
        Route::get('/profile', [HomeController::class, 'index']);
        Route::post('/req/profile/update', [ProfileController::class, 'update']);
        Route::post('/req/profile/uploadAvatar', [ProfileController::class, 'uploadAvatar']);
        Route::post('/req/profile/removeAvatar', [ProfileController::class, 'removeAvatar']);
        Route::get('/req/profile/requestPasskeyBackup', [ProfileController::class, 'requestPasskeyBackup']);

        Route::post('/req/profile/reset', [ProfileController::class, 'requestProfileReset']);
        Route::post('/req/backupKeychain', [ProfileController::class, 'backupKeychain']);

        // News
        if (config('hawki.news_active')) {
            Route::get('/news', [HomeController::class, 'index']);
        }

        // AI RELATED ROUTES
        // TRANSCRIPTION ROUTES
        Route::middleware('transcriptionAccess')->group(function () {
            Route::post('/req/transcribe', [TranscriptionController::class, 'transcribe']);
            Route::post('/req/transcription/realtime/signaling', [\App\Http\Controllers\Transcription\RealtimeSignalingController::class, 'handleSignaling']);
            Route::post('/req/transcription/realtime/session', [\App\Http\Controllers\Transcription\RealtimeSignalingController::class, 'createSession']);
            Route::post('/req/transcription/realtime/onprem/signaling', [\App\Http\Controllers\Transcription\RealtimeSignalingController::class, 'createOnPremSignaling']);
            Route::get('/req/transcription/realtime/config', [\App\Http\Controllers\Transcription\RealtimeSignalingController::class, 'getRealtimeConfig']);
            Route::post('/req/transcription/async/session', [TranscriptionController::class, 'createUploadSession']);
            Route::post('/req/transcription/async/dispatch/{jobId}', [TranscriptionController::class, 'dispatchJob']);
            Route::post('/req/transcription/async/analyze/{jobId}', [TranscriptionController::class, 'analyzeJob']);
            Route::get('/req/transcription/async/status/{jobId}', [TranscriptionController::class, 'getAsyncStatus']);
            Route::delete('/req/transcription/async/job/{jobId}', [TranscriptionController::class, 'deleteJob']);
            Route::get('/req/transcription-status/{jobId}', [TranscriptionController::class, 'getStatus']);
            Route::get('/req/transcription-config', [TranscriptionController::class, 'getConfiguration']);
            Route::post('/req/transcription-config', [TranscriptionController::class, 'saveConfiguration']);
            Route::get('/req/transcription-test', [TranscriptionController::class, 'testConnection']);

            // Saved transcriptions CRUD
            Route::post('/req/transcription/save', [TranscriptionController::class, 'save']);
            Route::get('/req/transcriptions', [TranscriptionController::class, 'list']);
            Route::get('/req/transcriptions/jobs/active', [TranscriptionController::class, 'getActiveJobs']);
            Route::get('/req/transcription/audio', [TranscriptionController::class, 'getAudioPresignedUrl']);
            Route::post('/req/transcription/summarize', [TranscriptionController::class, 'summarize']);
            Route::post('/req/transcription/optimize-speakers', [TranscriptionController::class, 'optimizeSpeakers']);

            // Transcription Templates CRUD
            Route::get('/req/transcription/templates', [TranscriptionController::class, 'listTemplates']);
            Route::post('/req/transcription/templates', [TranscriptionController::class, 'saveTemplate']);
            Route::delete('/req/transcription/templates/{id}', [TranscriptionController::class, 'deleteTemplate']);

            // Custom Transcription Formats CRUD
            Route::get('/req/transcription/formats', [TranscriptionController::class, 'listCustomFormats']);
            Route::post('/req/transcription/formats', [TranscriptionController::class, 'saveCustomFormat']);
            Route::delete('/req/transcription/formats/{id}', [TranscriptionController::class, 'deleteCustomFormat']);

            Route::get('/req/transcription/{slug}', [TranscriptionController::class, 'load']);
            Route::delete('/req/transcription/{slug}', [TranscriptionController::class, 'delete']);
            Route::patch('/req/transcription/{slug}/title', [TranscriptionController::class, 'updateTitle']);
            Route::patch('/req/transcription/{slug}/subtitle', [TranscriptionController::class, 'updateSubtitle']);
            Route::patch('/req/transcription/{slug}/segments', [TranscriptionController::class, 'updateSegments']);
        });
    });

    // NAVIGATION ROUTES
    Route::get('/logout', [AuthenticationController::class, 'logout'])->name('logout');

});
