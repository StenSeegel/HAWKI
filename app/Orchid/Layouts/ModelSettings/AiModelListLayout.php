<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use App\Models\AiModel;
use App\Orchid\Traits\ApiFormatColorTrait;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\DropDown;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class AiModelListLayout extends Table
{
    use ApiFormatColorTrait;

    /**
     * @var string
     */
    public $target = 'models';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('label', 'Name')
                ->sort()
                ->render(function (AiModel $model) {
                    $queryParams = request()->only(['provider_filter', 'active_status', 'visible_status', 'search', 'date_range']);
                    $url = route('platform.models.language.edit', $model->id);
                    
                    if (!empty($queryParams)) {
                        $url .= '?' . http_build_query($queryParams);
                    }
                    
                    return Link::make($model->label)->href($url);
                }),

            TD::make('provider_name', 'Provider')
                ->sort()
                ->render(function (AiModel $model) {
                    $providerName = $model->provider->provider_name ?? 'Unknown Provider';
                    $apiFormat = $model->provider->apiFormat ?? null;

                    // Use trait method for consistent styling with rounded pills and larger text
                    if ($apiFormat) {
                        $badgeColor = $this->getApiFormatBadgeColor($apiFormat->id);

                        return $this->getProviderBadge($providerName, $badgeColor);
                    }

                    return $this->getProviderBadge($providerName, 'secondary');
                }),

            TD::make('has_model_info', 'Info')
                ->sort()
                ->align(TD::ALIGN_CENTER)
                ->width('60px')
                ->render(function (AiModel $model) {
                    // Build edit URL with query params
                    $queryParams = request()->only(['provider_filter', 'active_status', 'visible_status', 'search', 'date_range']);
                    $url = route('platform.models.language.edit', $model->id);
                    if (!empty($queryParams)) {
                        $url .= '?' . http_build_query($queryParams);
                    }
                    
                    // Check if model has linked model info
                    $hasInfo = $model->ai_model_info_id !== null;
                    $matchType = $model->match_type ?? 'none';
                    
                    if ($hasInfo) {
                        // Icon mapping based on match_type
                        switch ($matchType) {
                            case 'exact':
                                // Exact match - Link icon (primary/blue)
                                $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-primary" title="Exact match - Model info linked"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>';
                                break;
                            
                            case 'fuzzy':
                                // Fuzzy match - Edit icon (warning/orange)
                                $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-warning" title="Fuzzy match - Review recommended"><path d="M12 20h9"></path><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path></svg>';
                                break;
                            
                            case 'manual':
                                // Manual match - Check icon (success/green)
                                $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-success" title="Manually linked"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>';
                                break;
                            
                            default:
                                // Linked but unknown type
                                $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-info" title="Model info linked"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
                        }
                    } else {
                        // No model info - Flag icon (danger/red)
                        $icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-danger" title="No model info - Needs linking"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><line x1="4" y1="22" x2="4" y2="15"></line></svg>';
                    }
                    
                    return '<a href="' . e($url) . '" style="text-decoration: none;">' . $icon . '</a>';
                }),

            TD::make('capabilities', 'Capabilities')
                ->render(function (AiModel $model) {
                    $tools = $model->settings['tools'] ?? [];
                    $html = '<div class="d-flex align-items-center gap-1">';

                    // Define all capabilities with their icons and colors (using Feather SVG icons)
                    $capabilities = [
                        'file_upload' => [
                            'title' => 'File Upload',
                            'color' => 'primary',
                            'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path></svg>'
                        ],
                        'vision' => [
                            'title' => 'Vision/Image Analysis',
                            'color' => 'warning',
                            'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'
                        ],
                        'web_search' => [
                            'title' => 'Web Search',
                            'color' => 'success',
                            'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M3.6 9h16.8"></path><path d="M3.6 15h16.8"></path><path d="M11.5 3a17 17 0 0 0 0 18"></path><path d="M12.5 3a17 17 0 0 1 0 18"></path></svg>'
                        ],
                        'reasoning' => [
                            'title' => 'Reasoning',
                            'color' => 'info',
                            'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect><rect x="9" y="9" width="6" height="6"></rect><line x1="9" y1="1" x2="9" y2="4"></line><line x1="15" y1="1" x2="15" y2="4"></line><line x1="9" y1="20" x2="9" y2="23"></line><line x1="15" y1="20" x2="15" y2="23"></line><line x1="20" y1="9" x2="23" y2="9"></line><line x1="20" y1="14" x2="23" y2="14"></line><line x1="1" y1="9" x2="4" y2="9"></line><line x1="1" y1="14" x2="4" y2="14"></line></svg>'
                        ],
                    ];

                    // Render all capability icons as clickable badges
                    foreach ($capabilities as $capabilityKey => $capability) {
                        $isActive = !empty($tools[$capabilityKey]);
                        $badgeClass = $isActive ? "bg-{$capability['color']}" : 'bg-secondary';
                        $opacity = $isActive ? '0.9' : '0.4';
                        
                        // Create Orchid button with raw SVG content
                        $button = Button::make('')
                            ->method('toggleCapability')
                            ->parameters([
                                'id' => $model->id,
                                'capability' => $capabilityKey,
                            ])
                            ->class("{$badgeClass} border-0 capability-toggle")
                            ->style("width: 26px; height: 26px; opacity: {$opacity}; cursor: pointer; transition: opacity 0.2s ease; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; padding: 0;");
                        
                        // Render button and inject SVG into it
                        $buttonHtml = (string) $button->render();
                        $buttonHtml = str_replace('</button>', $capability['svg'] . '</button>', $buttonHtml);
                        // Add tooltip title attribute to the button
                        $buttonHtml = str_replace('<button', '<button title="' . htmlspecialchars($capability['title']) . '"', $buttonHtml);
                        
                        $html .= $buttonHtml;
                    }

                    $html .= '</div>';
                    
                    // Add CSS for hover effect and loading state (only add once)
                    if (!isset($GLOBALS['capability_toggle_css'])) {
                        $GLOBALS['capability_toggle_css'] = true;
                        $html .= '<style>
                            /* Capability icons - always round */
                            .capability-toggle, .capability-toggle * { border-radius: 50% !important; }
                            .capability-toggle:hover { opacity: 1 !important; }
                            .capability-toggle.disabled, .capability-toggle:disabled, .capability-toggle[disabled], .capability-toggle.btn-loading { width: 26px !important; height: 26px !important; min-width: 26px !important; min-height: 26px !important; max-width: 26px !important; max-height: 26px !important; padding: 0 !important; }
                            .capability-toggle .spinner-border, .capability-toggle .spinner-border-sm { width: 14px !important; height: 14px !important; min-width: 14px !important; min-height: 14px !important; max-width: 14px !important; max-height: 14px !important; border-width: 2px !important; border-color: white !important; border-right-color: transparent !important; }
                            .capability-toggle svg { stroke: white !important; }
                            .capability-toggle svg path, .capability-toggle svg circle, .capability-toggle svg polygon, .capability-toggle svg polyline { stroke: white !important; }
                            
                            /* Visibility toggle icons - always round (same as capability icons) */
                            .visibility-toggle, .visibility-toggle * { border-radius: 50% !important; }
                            .visibility-toggle:hover { opacity: 1 !important; }
                            .visibility-toggle.disabled, .visibility-toggle:disabled, .visibility-toggle[disabled], .visibility-toggle.btn-loading { width: 26px !important; height: 26px !important; min-width: 26px !important; min-height: 26px !important; max-width: 26px !important; max-height: 26px !important; padding: 0 !important; }
                            .visibility-toggle .spinner-border, .visibility-toggle .spinner-border-sm { width: 14px !important; height: 14px !important; min-width: 14px !important; min-height: 14px !important; max-width: 14px !important; max-height: 14px !important; border-width: 2px !important; border-color: white !important; border-right-color: transparent !important; }
                            .visibility-toggle svg { stroke: white !important; }
                            .visibility-toggle svg path, .visibility-toggle svg circle, .visibility-toggle svg polygon, .visibility-toggle svg polyline, .visibility-toggle svg line { stroke: white !important; }
                            
                            /* Active toggle icons - always round (same as capability icons) */
                            .active-toggle, .active-toggle * { border-radius: 50% !important; }
                            .active-toggle:hover { opacity: 1 !important; }
                            .active-toggle.disabled, .active-toggle:disabled, .active-toggle[disabled], .active-toggle.btn-loading { width: 26px !important; height: 26px !important; min-width: 26px !important; min-height: 26px !important; max-width: 26px !important; max-height: 26px !important; padding: 0 !important; }
                            .active-toggle .spinner-border, .active-toggle .spinner-border-sm { width: 14px !important; height: 14px !important; min-width: 14px !important; min-height: 14px !important; max-width: 14px !important; max-height: 14px !important; border-width: 2px !important; border-color: white !important; border-right-color: transparent !important; }
                            .active-toggle svg { stroke: white !important; }
                            .active-toggle svg path, .active-toggle svg circle, .active-toggle svg polygon, .active-toggle svg polyline, .active-toggle svg line { stroke: white !important; }
                            
                            /* Badge buttons - always pill-shaped */
                            .badge.rounded-pill, .badge.rounded-pill *, button.badge.rounded-pill, button.badge.rounded-pill * { border-radius: 50rem !important; }
                            
                            /* Remove wait cursor on all buttons */
                            button.disabled, button:disabled, button[disabled], button.btn-loading, .badge.disabled, .badge:disabled, .badge[disabled], .badge.btn-loading, .capability-toggle.disabled *, .capability-toggle:disabled *, .capability-toggle[disabled] *, .capability-toggle.btn-loading *, .visibility-toggle.disabled *, .visibility-toggle:disabled *, .visibility-toggle[disabled] *, .visibility-toggle.btn-loading *, .active-toggle.disabled *, .active-toggle:disabled *, .active-toggle[disabled] *, .active-toggle.btn-loading *, .badge.rounded-pill.disabled *, .badge.rounded-pill:disabled *, .badge.rounded-pill[disabled] *, .badge.rounded-pill.btn-loading * { cursor: pointer !important; }
                        </style>';
                    }
                    
                    return $html;
                }),

            TD::make('context_length', 'Context')
                ->align(TD::ALIGN_RIGHT)
                ->width('100px')
                ->render(function (AiModel $model) {
                    // Get context length from linked model info
                    $contextLength = $model->modelInfo->context_length ?? null;
                    
                    if ($contextLength) {
                        // Format large numbers with K/M suffix
                        if ($contextLength >= 1000000) {
                            $formatted = number_format($contextLength / 1000000, 1) . 'M';
                        } elseif ($contextLength >= 1000) {
                            $formatted = number_format($contextLength / 1000, 0) . 'K';
                        } else {
                            $formatted = number_format($contextLength);
                        }
                        
                        return '<span class="badge rounded-pill bg-info text-white" title="' . number_format($contextLength) . ' tokens">' . $formatted . '</span>';
                    }
                    
                    return '<span class="text-muted">-</span>';
                }),
            
            TD::make('status', 'Status')
                ->align(TD::ALIGN_CENTER)
                ->width('80px')
                ->render(function (AiModel $model) {
                    $isActive = $model->is_active;
                    $isVisible = $model->is_visible;
                    
                    $html = '<div class="d-flex align-items-center justify-content-center gap-1">';
                    
                    // Active/Inactive Power Icon
                    $activeBadgeClass = $isActive ? 'bg-success' : 'bg-secondary';
                    $activeOpacity = $isActive ? '0.9' : '0.4';
                    $powerIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"></path><line x1="12" y1="2" x2="12" y2="12"></line></svg>';
                    $activeTitle = $isActive ? 'Active' : 'Inactive';
                    
                    $activeButton = Button::make('')
                        ->method('toggleActive')
                        ->parameters(['id' => $model->id])
                        ->class("{$activeBadgeClass} border-0 active-toggle")
                        ->style("width: 26px; height: 26px; opacity: {$activeOpacity}; cursor: pointer; transition: opacity 0.2s ease; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; padding: 0;");
                    
                    $activeButtonHtml = (string) $activeButton->render();
                    $activeButtonHtml = str_replace('</button>', $powerIcon . '</button>', $activeButtonHtml);
                    $activeButtonHtml = str_replace('<button', '<button title="' . htmlspecialchars($activeTitle) . '"', $activeButtonHtml);
                    
                    $html .= $activeButtonHtml;
                    
                    // Visible/Hidden Eye Icon
                    $visibleBadgeClass = ($isVisible && $isActive) ? 'bg-success' : 'bg-secondary';
                    $visibleOpacity = $isVisible ? '0.9' : '0.4';
                    $eyeIcon = $isVisible 
                        ? '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>'
                        : '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
                    $visibleTitle = $isVisible ? 'Visible' : 'Hidden';
                    
                    $visibleButton = Button::make('')
                        ->method('toggleVisible')
                        ->parameters(['id' => $model->id])
                        ->class("{$visibleBadgeClass} border-0 visibility-toggle")
                        ->style("width: 26px; height: 26px; opacity: {$visibleOpacity}; cursor: pointer; transition: opacity 0.2s ease; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; padding: 0;");
                    
                    $visibleButtonHtml = (string) $visibleButton->render();
                    $visibleButtonHtml = str_replace('</button>', $eyeIcon . '</button>', $visibleButtonHtml);
                    $visibleButtonHtml = str_replace('<button', '<button title="' . htmlspecialchars($visibleTitle) . '"', $visibleButtonHtml);
                    
                    $html .= $visibleButtonHtml;
                    $html .= '</div>';
                    
                    return $html;
                }),

            TD::make('created_at', 'Created')
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->defaultHidden()
                ->sort(),

            TD::make('updated_at', 'Last Updated')
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make('Actions')
                ->align(TD::ALIGN_CENTER)
                ->width('120px')
                ->render(fn (AiModel $model) => DropDown::make()
                    ->icon('bs.three-dots-vertical')
                    ->list([
                        Link::make('Edit Model')
                            ->href(route('platform.models.language.edit', $model->id) . 
                                (!empty(request()->only(['provider_filter', 'active_status', 'visible_status', 'search', 'date_range'])) 
                                    ? '?' . http_build_query(request()->only(['provider_filter', 'active_status', 'visible_status', 'search', 'date_range'])) 
                                    : ''))
                            ->icon('bs.pencil'),

                        Button::make('Make Default')
                            ->icon('bs.stars')
                            ->confirm("Are you sure you want to set '{$model->label}' as the default model for ALL AI assistants? This will update all existing assistants.")
                            ->method('setAsDefaultForAssistants', [
                                'id' => $model->id,
                            ])
                            ->canSee($model->is_active),

                        Button::make('Delete')
                            ->icon('bs.trash3')
                            ->confirm("Are you sure you want to delete model '{$model->label}'?")
                            ->method('deleteModel', [
                                'id' => $model->id,
                            ]),
                    ])),
        ];
    }
}
