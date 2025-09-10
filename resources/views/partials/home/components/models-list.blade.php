<div class="model-selection-panel">
    @if(isset($models['models']) && count($models['models']) > 0)
        @php
            // Group models by provider_name and sort providers by display_order (fallback to alphabetical)
            $groupedModels = collect($models['models'])
                ->groupBy('provider_name')
                ->sortBy(function ($providerModels, $providerName) {
                    // Get the display_order from the first model of this provider
                    $firstModel = $providerModels->first();
                    $displayOrder = $firstModel['provider_display_order'] ?? null;
                    
                    // Create composite sort key: display_order (nulls last) + provider_name for secondary sort
                    if ($displayOrder !== null) {
                        // Pad with zeros for consistent sorting + provider name for tie-breaking
                        return sprintf('%04d_%s', $displayOrder, $providerName);
                    }
                    
                    // For null values, sort alphabetically at the end (9999 ensures they come last)
                    return sprintf('9999_%s', $providerName);
                });
        @endphp
        
        @foreach($groupedModels as $providerName => $providerModels)
            <div class="provider-group">
                <div class="provider-header">
                    <span class="provider-name">{{ $providerName }}</span>
                </div>
                
                @foreach($providerModels as $model)
                    <button class="model-selector burger-item" onclick="selectModel(this); closeBurgerMenus()" value="{{ json_encode($model) }}">
                        
                        @if(array_key_exists('status',$model))
                            @switch($model['status'])
                                @case('ready')
                                    <span class="dot grn-c"></span> 
                                    @break
                                @case('loading')
                                    <span class="dot org-c"></span> 
                                    @break
                                @case('unavailable')
                                    <span class="dot red-c"></span> 
                                    @break
                                @case('testing')
                                    <span class="dot ai-c"></span> 
                                    @break
                                @default
                                    <span class="dot org-c"></span> 
                            @endswitch
                        @else
                        <span class="dot grn-c"></span> 
                        @endif
                        <span>{{ $model['label'] }}</span>

                    </button>
                @endforeach
            </div>
        @endforeach
    @else
        <button class="model-selector burger-item">
            <span class="dot red-c"></span>
            <span>No Models loaded.</span>
        </button>
    @endif
</div>