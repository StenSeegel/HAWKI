
<!DOCTYPE html>
<html class="lightMode">
<head>


	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, user-scalable=no">
	<meta name="csrf-token" content="{{ csrf_token() }}">


    <title>{{ config('app.name') }}</title>

	<link rel="icon" href="{{ route('system.image', 'favicon') }}">


{{--    <link rel="stylesheet" href="{{ asset('css_v2.1.0/gfont-firesans/firesans.css') }}">--}}
    <link rel="stylesheet" href="{{ route('css.get', 'style') }}">
    <link rel="stylesheet" href="{{ route('css.get', 'custom-styles') }}">
    <link rel="stylesheet" href="{{ route('css.get', 'chat_modules') }}">
    <link rel="stylesheet" href="{{ route('css.get', 'home-style') }}">
    <link rel="stylesheet" href="{{ route('css.get', 'settings_style') }}">
    <link rel="stylesheet" href="{{ route('css.get', 'hljs_custom') }}">

    @vite('resources/js/app.js')
    @vite('resources/css/app.css')

    <script src="{{ asset('js/functions.js') }}"></script>
    <script src="{{ asset('js/home_functions.js') }}"></script>
    <script src="{{ asset('js/stream_functions.js') }}"></script>
    <script src="{{ asset('js/ai_chat_functions.js') }}"></script>
    <script src="{{ asset('js/chatlog_functions.js') }}"></script>
    <script src="{{ asset('js/inputfield_functions.js') }}"></script>
    <script src="{{ asset('js/message_functions.js') }}"></script>
    <script src="{{ asset('js/groupchat_functions.js') }}"></script>
    <script src="{{ asset('js/syntax_modifier.js') }}"></script>
    <script src="{{ asset('js/settings_functions.js') }}"></script>
    <script src="{{ asset('js/encryption.js') }}"></script>
    <script src="{{ asset('js/image-selector.js') }}"></script>
    <script src="{{ asset('js/export.js') }}"></script>
    <script src="{{ asset('js/user_profile.js') }}"></script>
    <script src="{{ asset('js/file_manager.js') }}"></script>
    <script src="{{ asset('js/attachment_handler.js') }}"></script>
    <script src="{{ asset('js/model_list_filtering.js') }}"></script>
    <script src="{{ asset('js/announcements.js') }}"></script>

	@if(config('sanctum.allow_external_communication'))
        <script src="{{ asset('js/sanctum_functions.js') }}"></script>
    @endif


	{!! $settingsPanel !!}
    <script>
		SwitchDarkMode(false);
		UpdateSettingsLanguage('{{ Session::get("language")['id'] }}');
	</script>

</head>
<body>


	<div class="wrapper">

		@include('partials.home.sidebar')
		<div class="main">
			@yield('content')
		</div>
	</div>

	@include('partials.home.modals.guidelines-modal')
	@include('partials.home.modals.add-member-modal')
	@include('partials.home.modals.room-invitation-modal')
	@include('partials.home.modals.session-expiry-modal')
	@include('partials.home.modals.file-viewer-modal')
	@include('partials.home.modals.announcements-modal')

	@include('partials.overlay')

    @php
        $templates = collect(File::files(resource_path('views/partials/home/templates')))
            ->sortBy(fn($file) => $file->getFilename())
            ->values();
    @endphp
    @foreach ($templates as $temp)
        @include('partials.home.templates.' . $viewName = str_replace('.blade', '',  $temp->getFilenameWithoutExtension()))
    @endforeach
    @include('partials.home.modals.confirm-modal')

</body>
</html>

<script>

	const userInfo = @json($user);
	const userAvatarUrl = @json($userData['avatar_url']);
	const hawkiAvatarUrl = @json($userData['hawki_avatar_url']);
	const activeModule = @json($activeModule);
    const hawkiUsername = @json($userData['hawki_username'])

    const activeLocale = {!! json_encode(Session::get('language')) !!};
	const translation = @json($translation);

	const modelsList = @json($models).models.filter(model => !model.hasOwnProperty('visible') || model.visible);
	const defaultModels = @json($models).defaultModels;
	const systemModels = @json($models).systemModels;

	const aiHandle = "{{ config('hawki.aiHandle') }}";
	const webSearchAutoEnable = {{ config('hawki.websearch_auto_enable') ? 'true' : 'false' }};
	const forceDefaultModel = {{ config('hawki.force_default_model') ? 'true' : 'false' }};

    const announcementList = @json($announcements);

    const converterActive = @json($converterActive);


    window.addEventListener('DOMContentLoaded', async (event) => {
        setModel();

		const passkey = await getPassKey()
		if(!passkey){
			console.log('passkey not found!');
			window.location.href = '/handshake';
		}

		setSessionCheckerTimer(0);
		CheckModals()

		const tempLink = @json(session('invitation_tempLink'));
	    if (tempLink){
			await handleTempLinkInvitation(tempLink);
		}

		// DO NOT auto-accept invitations - let user click on room to accept
		// handleUserInvitations();

		// Listen for new invitations via WebSocket
		window.Echo.private(`User.${userInfo.username}`)
			.listen('RoomInvitationEvent', async (e) => {
				const invitationData = e.data;
				
				// Initialize rooms array if not exists
				if (typeof rooms === 'undefined') {
					rooms = [];
				}
				
				// Add new room to rooms array
				const roomExists = rooms.find(r => r.slug === invitationData.room.slug);
				if (!roomExists) {
					const newRoom = {
						slug: invitationData.room.slug,
						room_name: invitationData.room.room_name,
						room_icon: invitationData.room.room_icon,
						invited_by: invitationData.room.invited_by,  // Add inviter info
						hasUnreadMessages: false,
						isNewRoom: true  // Mark as new invitation
					};
					rooms.push(newRoom);
					
					// If GroupChat module is active AND initialized, create room item
					if (typeof createRoomItem === 'function' && typeof roomItemTemplate !== 'undefined' && roomItemTemplate) {
						createRoomItem(newRoom);
						if (typeof flagRoomUnreadMessages === 'function') {
							flagRoomUnreadMessages(newRoom.slug, true, true);  // Red badge
						}
						// Don't connect WebSocket for new invitations - user is not a member yet!
					}
				}
				
				// Update sidebar badge (red for new invitation)
				if (typeof checkAndUpdateSidebarBadge === 'function') {
					checkAndUpdateSidebarBadge();
				}
			});

		// Initialize rooms array and check for unread messages (for sidebar badge)
		const roomsData = @json($userData['rooms']);
		
		if (roomsData && roomsData.length > 0) {
			// Set global rooms variable if not already set by GroupChat module
			if (typeof rooms === 'undefined') {
				rooms = roomsData;
			}
			
			// Connect WebSockets only for rooms where user is a member (not for new invitations)
			roomsData.forEach(roomItem => {
				// Skip WebSocket connection for new invitations (user is not a member yet)
				if (roomItem.isNewRoom) {
					return;
				}
				
				if (typeof connectWebSocket === 'function') {
					connectWebSocket(roomItem.slug);
				}
				if (typeof connectWhisperSocket === 'function') {
					connectWhisperSocket(roomItem.slug);
				}
			});
			
			// Update sidebar badge based on initial state
			if (typeof checkAndUpdateSidebarBadge === 'function') {
				checkAndUpdateSidebarBadge();
			}
		}

		//Module Checkup
		setActiveSidebarButton(activeModule);

		const sidebarBtn = document.getElementById('profile-sb-btn');
		if(userAvatarUrl){
			sidebarBtn.querySelector('.user-inits').style.display = 'none';
			sidebarBtn.querySelector('.icon-img').style.display = 'flex';
			sidebarBtn.querySelector('.icon-img').setAttribute('src', userAvatarUrl);
		}
		else{
			sidebarBtn.querySelector('.icon-img').style.display = 'none';
			const userInitials =  userInfo.name.slice(0, 1).toUpperCase();
			sidebarBtn.querySelector('.user-inits').style.display = "flex";
			sidebarBtn.querySelector('.user-inits').innerText = userInitials
		}


		initializeGUI();
		checkWindowSize(800, 200);

        initAnnouncements(announcementList);


		setTimeout(() => {
			if(@json($activeOverlay)){
				setOverlay(false, true)
			}
		}, 100);
    });


</script>
