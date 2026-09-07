{{-- Voice input for the chat: the microphone is streamed to the realtime
     transcription. Only roles with transcription access get the control - the
     signaling routes sit behind the same permission (transcriptionAccess
     middleware), so for anyone else the button could only end in a 403. --}}
@if(Auth::user()?->hasAccess('transcription.access'))
<div class="realtime-transcription-outer tooltip-parent" id="realtime-transcription-outer">
    <div class="label tooltip tt-abs-up">Spracheingabe</div>
    <div class="realtime-transcription-group" id="realtime-transcription-group">
        <div class="realtime-transcription-btn" id="realtime-mic-btn" onclick="toggleRealtimeTranscription(this)">
            <span class="rt-icon-mic"><x-icon name="microphone"/></span>
            <span class="rt-icon-connecting loading">
                <x-icon name="loading"/>
            </span>
        </div>
        <div class="realtime-btn-separator"></div>
        <button class="realtime-device-toggle btn-xs tooltip-parent" onclick="event.stopPropagation(); window.toggleRealtimeDeviceDropdown()">
            <x-icon name="chevron-up"/>
            <div class="label tooltip tt-abs-up">Mikrofon auswählen</div>
        </button>
    </div>
    <div class="realtime-device-dropdown" id="realtime-device-dropdown" style="display:none;">
        <select id="live-input-device-select" class="realtime-device-select">
            <option value="">Standardmikrofon</option>
        </select>
    </div>
</div>
@endif
