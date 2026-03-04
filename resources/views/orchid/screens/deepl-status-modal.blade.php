{{-- DeepL API Status Modal Content --}}
<div class="p-4">

    @if(!empty($deeplStatus['error']))
        <div class="alert alert-danger">
            <i class="ph ph-warning-circle me-2"></i>{{ $deeplStatus['error'] }}
        </div>
    @else
    <div class="d-flex align-items-center gap-3 mb-4">
        <span class="badge bg-success fs-6 px-3 py-2">
            <i class="ph ph-check-circle me-1"></i> API reachable
        </span>
    </div>

    {{-- Billing Period --}}
    <div class="mb-4">
        <small class="text-muted text-uppercase fw-bold d-block mb-1">Billing Period</small>
        <div class="bg-light rounded px-3 py-2 font-monospace">
            {{ $deeplStatus['billing_start'] ?? '—' }} → {{ $deeplStatus['billing_end'] ?? '—' }}
        </div>
    </div>

    {{-- Usage per product --}}
    @foreach($deeplStatus['products'] ?? [] as $product)
        @php
            $type = $product['product_type'] ?? '';

            // Counts come from the product entry itself
            $used = $product['character_count'] ?? $product['api_key_character_count'] ?? 0;

            // Limits live at the top level of the response, not inside each product
            $limit = match($type) {
                'translate'    => $deeplStatus['character_limit'] ?? 0,
                'write'        => $deeplStatus['character_limit'] ?? 0,
                'speechToText' => $deeplStatus['stt_minutes_limit'] ?? 0,
                default        => 0,
            };

            // For STT, use the minutes count instead of character count
            if ($type === 'speechToText') {
                $used = $deeplStatus['stt_minutes_count'] ?? 0;
            }

            $pct      = ($limit > 0) ? round(($used / $limit) * 100, 1) : 0;
            $barClass = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
            $unit     = match($type) {
                'speechToText' => 'minutes',
                default        => 'characters',
            };
            $typeLabel = match($type) {
                'translate'    => 'Translate',
                'write'        => 'Write (Rephrase)',
                'speechToText' => 'Speech to Text',
                default        => ucfirst($type),
            };
        @endphp
        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-baseline mb-1">
                <small class="text-muted text-uppercase fw-bold">{{ $typeLabel }}</small>
                <small class="text-muted font-monospace">
                    {{ number_format($used) }} / {{ $limit > 0 ? number_format($limit) : '∞' }} {{ $unit }}
                    @if($limit > 0)
                        <span class="ms-1">({{ $pct }}%)</span>
                    @endif
                </small>
            </div>
            @if($limit > 0)
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar {{ $barClass }}"
                         role="progressbar"
                         style="width: {{ min($pct, 100) }}%"
                         aria-valuenow="{{ $pct }}"
                         aria-valuemin="0"
                         aria-valuemax="100">
                    </div>
                </div>
            @else
                <div class="text-muted small">No limit</div>
            @endif
        </div>
    @endforeach
    @endif

</div>
