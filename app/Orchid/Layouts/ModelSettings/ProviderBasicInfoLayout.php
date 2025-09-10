<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\ModelSettings;

use App\Models\ApiFormat;
use App\Orchid\Fields\BadgeField;
use App\Orchid\Traits\ApiFormatColorTrait;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Label;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class ProviderBasicInfoLayout extends Rows
{
    use ApiFormatColorTrait;
    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Field[]
     */
    public function fields(): array
    {
        $provider = $this->query->get('provider');
        $isEditing = isset($provider['id']) && !empty($provider['id']);

        return [
            // Provider Name (Internal) - first, read-only when editing
            $isEditing 
                ? BadgeField::make('provider.provider_name')
                    ->title('Provider Name (Internal)')
                    ->help('Unique internal identifier for this provider (used by AI services)')
                    ->badgeClass($this->getProviderBadgeClass($provider['api_format_id'] ?? null))
                : Input::make('provider.provider_name')
                    ->title('Provider Name (Internal)')
                    ->required()
                    ->help('Unique internal identifier for this provider (used by AI services)')
                    ->placeholder('e.g., Google, OpenAI, GWDG'),

            // Display Name - second, always editable
            Input::make('provider.display_name')
                ->title('Display Name')
                ->required()
                ->help('Human-readable name shown in the interface'),

            Select::make('provider.api_format_id')
                ->title('API Format')
                ->options($this->getApiFormatOptions())
                ->required()
                ->help('The API interface format to use'),
        ];
    }

    /**
     * Get available API format options from database
     *
     * @return array
     */
    private function getApiFormatOptions(): array
    {
        return ApiFormat::all()
            ->pluck('display_name', 'id')
            ->toArray();
    }
}
