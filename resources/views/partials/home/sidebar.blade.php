<div class="main-sidebar">
        <div class="sidebar-content">
            <div class="upper-panel">
                @if(Auth::user()->hasAccess('chat.access'))
                <button id="chat-sb-btn" onclick="onSidebarButtonDown('chat')" href="chat" class="btn-sm sidebar-btn tooltip-parent">
                    <x-icon name="chat-icon"/>

                    <div class="label tooltip tt-abs-left">
                        {{ $translation["Chat"] }}
                    </div>
                </button>
                @endif

                @if(Auth::user()->hasAccess('groupchat.access') && config('hawki.groupchat_active', false))
                <button id="groupchat-sb-btn" onclick="onSidebarButtonDown('groupchat')" class="btn-sm sidebar-btn tooltip-parent" style="position: relative;">
                    <x-icon name="assistant-icon"/>
                    <!-- Red badge for new invitations (top-right) -->
                    <div class="notification-badge new-room" id="groupchat-invitation-badge"></div>
                    <!-- Green badge for new messages (bottom-right) -->
                    <div class="notification-badge new-message" id="groupchat-message-badge"></div>

                    <div class="label tooltip tt-abs-left">
                        {{ $translation["Groupchat"] }}
                    </div>
                </button>
                @endif

                <button id="assistants-sb-btn" onclick="onSidebarButtonDown('assistants')" class="btn-sm sidebar-btn tooltip-parent">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2L13.5 7.5L19 9L13.5 10.5L12 16L10.5 10.5L5 9L10.5 7.5L12 2Z" fill="currentColor"/>
                        <path d="M17 13L17.75 15.25L20 16L17.75 16.75L17 19L16.25 16.75L14 16L16.25 15.25L17 13Z" fill="currentColor"/>
                    </svg>

                    <div class="label tooltip tt-abs-left">
                        {{ $translation["Assistants"] ?? "Assistenten" }}
                    </div>
                </button>

                @if(config('hawki.news_active'))
                <button id="news-sb-btn" onclick="onSidebarButtonDown('news')" href="chat" class="btn-sm sidebar-btn tooltip-parent">
                    <x-icon name="send"/>

                    <div class="label tooltip tt-abs-left">
                        {{ $translation["News"] }}
                    </div>
                </button>
                @endif

                <button id="profile-sb-btn" onclick="onSidebarButtonDown('profile')" class="btn-sm sidebar-btn tooltip-parent">
                    <div class="profile-icon round-icon">
                        <span class="user-inits" style="display:none"></span>
                        <img class="icon-img"   alt="">
                    </div>
                    <div class="label tooltip tt-abs-left">
                        {{ $translation["Profile"] }}
                    </div>
                </button>
            </div>



            <div class="lower-panel">
                <button onclick="logout()" class="btn-sm sidebar-btn tooltip-parent" >
                    <x-icon name="logout-icon"/>
                    <div class="label tooltip tt-abs-left">
                        {{ $translation["Logout"] }}
                    </div>
                </button>
                <button class="btn-sm sidebar-btn tooltip-parent" onclick="toggleSettingsPanel(true)">
                    <x-icon name="settings-icon"/>
                    <div class="label tooltip tt-abs-left">
                        {{ $translation["Settings"] }}
                    </div>
                </button>
            </div>
        </div>
        <!-- <div class="logo-panel">
            <img src="{{ asset('img/logo.svg') }}" alt="">
        </div> -->

	</div>
