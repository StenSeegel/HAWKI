<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use App\Models\AiModelInfo;
use Orchid\Screen\Fields\Group;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Label;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class AiModelInformationLayout extends Rows
{
    /**
     * Simplified Model Information Layout - shows only pricing and technical specs.
     * Other fields (capabilities, descriptions, etc.) moved to BasicInfo layout.
     */
    public function fields(): array
    {
        $model = $this->query->get('model');
        $modelInfo = $model->modelInfo ?? null;

        $fields = [];

        // Model Info ID Selection
        $fields[] = Select::make('model_info.ai_model_info_id')
            ->title('Linked Model Info')
            ->options($this->getAvailableModelInfoOptions($model))
            ->value($model->ai_model_info_id)
            ->help('Select model information from models.hawki.info database')
            ->empty('No Match', '');

        if ($modelInfo) {
            // Technical Specifications with lock status
            $contextLength = $modelInfo->context_length;
            $outputLimit = $modelInfo->output_limit;
            
            if ($contextLength || $outputLimit) {
                $techFields = [];

                if ($contextLength) {
                    $techFields[] = Input::make('context_length_display')
                        ->title('Context Length')
                        ->value(number_format($contextLength) . ' tokens')
                        ->help($modelInfo->isFieldLocked('context_length') ? '🔒 Custom value' : '🔓 From models.hawki.info')
                        ->readonly();
                }

                if ($outputLimit) {
                    $techFields[] = Input::make('output_limit_display')
                        ->title('Output Limit')
                        ->value(number_format($outputLimit) . ' tokens')
                        ->help($modelInfo->isFieldLocked('output_limit') ? '🔒 Custom value' : '🔓 From models.hawki.info')
                        ->readonly();
                }

                $fields[] = Group::make($techFields);
            }

            // Pricing Information with lock status
            if ($modelInfo->price_input_usd || $modelInfo->price_output_usd || 
                $modelInfo->price_input_eur || $modelInfo->price_output_eur) {
                
                $pricingFields = [];

                if ($modelInfo->price_input_usd || $modelInfo->price_output_usd) {
                    $isLocked = $modelInfo->isFieldLocked('price_input_usd') || $modelInfo->isFieldLocked('price_output_usd');
                    $pricingFields[] = Input::make('pricing_usd_display')
                        ->title('Pricing USD (per 1M tokens)')
                        ->value($this->formatPricing($modelInfo->price_input_usd, $modelInfo->price_output_usd, '$'))
                        ->help($isLocked ? '🔒 Custom value' : '🔓 From models.hawki.info')
                        ->readonly();
                }

                if ($modelInfo->price_input_eur || $modelInfo->price_output_eur) {
                    $isLocked = $modelInfo->isFieldLocked('price_input_eur') || $modelInfo->isFieldLocked('price_output_eur');
                    $pricingFields[] = Input::make('pricing_eur_display')
                        ->title('Pricing EUR (per 1M tokens)')
                        ->value($this->formatPricing($modelInfo->price_input_eur, $modelInfo->price_output_eur, '€'))
                        ->help($isLocked ? '🔒 Custom value' : '🔓 From models.hawki.info')
                        ->readonly();
                }

                $fields[] = Group::make($pricingFields);
            }
        } else {
            $fields[] = Input::make('no_model_info')
                ->title('Status')
                ->value('No model information linked')
                ->readonly()
                ->help('Run "Import & Match Models" or select a model info entry above');
        }

        return $fields;
    }

    /**
     * Get available model info options for the dropdown.
     */
    protected function getAvailableModelInfoOptions($model): array
    {
        $options = [];
        
        $candidates = $model->matching_candidates ?? [];
        
        if (!empty($candidates)) {
            foreach ($candidates as $candidate) {
                $label = $candidate['model_info_id'] ?? 'Unknown';
                $matchType = $candidate['match_type'] ?? 'fuzzy';
                $score = $candidate['score'] ?? 0;
                
                $matchInfo = ' | ' . ucfirst($matchType);
                if ($score > 0) {
                    $matchInfo .= ' (' . round($score * 100) . '%)';
                }
                
                $options[$candidate['ai_model_info_id']] = $label . $matchInfo;
            }
        } else {
            $searchTerm = $model->model_id;
            
            $exactMatch = AiModelInfo::where('base_model_id', $searchTerm)->first();
            if ($exactMatch) {
                $label = $exactMatch->model_info_id;
                if ($exactMatch->name) {
                    $label .= ' - ' . $exactMatch->name;
                }
                $options[$exactMatch->id] = $label . ' | Exact';
            }
            
            $similarModels = AiModelInfo::where('model_info_id', 'like', "%{$searchTerm}%")
                ->where('id', '!=', $exactMatch->id ?? null)
                ->limit(10)
                ->get();
            
            foreach ($similarModels as $modelInfo) {
                $label = $modelInfo->model_info_id;
                if ($modelInfo->name) {
                    $label .= ' - ' . $modelInfo->name;
                }
                $options[$modelInfo->id] = $label . ' | Similar';
            }
        }
        
        return $options;
    }

    /**
     * Format pricing information.
     */
    protected function formatPricing($inputPrice, $outputPrice, string $symbol): string
    {
        $parts = [];

        if ($inputPrice) {
            $parts[] = 'In: ' . $symbol . number_format((float)$inputPrice, 4);
        }

        if ($outputPrice) {
            $parts[] = 'Out: ' . $symbol . number_format((float)$outputPrice, 4);
        }

        return empty($parts) ? 'N/A' : implode(' | ', $parts);
    }
}
