{{-- Voice input for the chat: the microphone is streamed to the realtime
     transcription. Only roles with transcription access get the control - the
     signaling routes sit behind the same permission (transcriptionAccess
     middleware), so for anyone else the button could only end in a 403.
     No ids: the thread template includes the input field too, so every
     message adds another copy of this component - the JS works relative to
     the clicked button. --}}
@if(Auth::user()?->hasAccess('transcription.access'))
<div class="realtime-transcription-outer tooltip-parent">
    <div class="label tooltip tt-abs-up">Spracheingabe</div>
    <div class="realtime-transcription-group">
        <div class="realtime-transcription-btn" onclick="toggleRealtimeTranscription(this)">
            <span class="rt-icon-mic"><x-icon name="microphone"/></span>
            <span class="rt-icon-connecting loading">
                <x-icon name="loading"/>
            </span>
        </div>
        <div class="realtime-btn-separator"></div>
        <button class="realtime-device-toggle btn-xs tooltip-parent" onclick="event.stopPropagation(); window.toggleRealtimeDeviceDropdown(this)">
            <x-icon name="chevron-up"/>
            <div class="label tooltip tt-abs-up">Mikrofon auswählen</div>
        </button>
    </div>
    <div class="realtime-device-dropdown" style="display:none;">
        <select class="realtime-device-select">
            <option value="">Standardmikrofon</option>
        </select>
    </div>
</div>
@endif
