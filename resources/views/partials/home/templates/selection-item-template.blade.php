<template id="selection-item-template">
	@if($activeModule === 'chat')
		<div class="selection-item" slug="" onclick="loadConv(this, null)">
	@elseif($activeModule === 'groupchat')
		<div class="selection-item" slug="" onclick="loadRoom(this, null)">
			<div class="dot-lg" id="unread-msg-flag"></div>
			<img class="room-icon" id="room-icon" alt="" style="display: none; width: 32px; height: 32px; border-radius: 50%; object-fit: cover; margin-right: 0.5rem; flex-shrink: 0;">
			<div class="room-initials" id="room-initials" style="display: none; width: 32px; height: 32px; border-radius: 50%; margin-right: 0.5rem; flex-shrink: 0; align-items: center; justify-content: center; background-color: var(--primary-color, #007bff); color: white; font-weight: bold; font-size: 0.85rem;"></div>
	@endif
			<div class="label singleLineTextarea"></div>
			<div class="btn-xs options burger-btn" onclick="openBurgerMenu('quick-actions', this, true)">
				<x-icon name="more-horizontal"/>
			</div>
		</div>
</template>
