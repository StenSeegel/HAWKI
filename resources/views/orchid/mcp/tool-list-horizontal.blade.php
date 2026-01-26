@php
    $selectedToolName = $selectedTool ?? null;
@endphp

<div class="bg-white rounded shadow-sm p-3 mb-4">
    <h6 class="mb-3">Available Tools <span class="badge bg-primary">{{ count($tools) }}</span></h6>
    
    <div class="d-flex flex-wrap gap-2">
        @foreach($tools as $tool)
            @php
                $toolName = $tool->getName();
                $serverName = $serverName ?? '';
                $currentRequestTool = request()->get('tool');
                $isCurrent = ($currentRequestTool === $toolName || $currentRequestTool === ($serverName . '-' . $toolName));
            @endphp
            <a href="{{ route('platform.models.mcp.edit', ['server' => $serverId, 'tool' => $toolName]) }}" 
               class="btn {{ $isCurrent ? 'btn-primary' : 'btn-outline-secondary' }} btn-sm d-flex align-items-center gap-1">
                @if($isCurrent)
                    <i class="bi bi-play-fill text-white"></i>
                @endif
                {{ $toolName }}
            </a>
        @endforeach
    </div>
</div>
