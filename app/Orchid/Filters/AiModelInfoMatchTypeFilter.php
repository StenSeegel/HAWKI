<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

class AiModelInfoMatchTypeFilter extends Filter
{
    /**
     * The displayable name of the filter.
     */
    public function name(): string
    {
        return 'Match Type';
    }

    /**
     * The array of matched parameters.
     */
    public function parameters(): ?array
    {
        return ['match_type_filter'];
    }

    /**
     * Apply to a given Eloquent query builder.
     */
    public function run(Builder $builder): Builder
    {
        $matchType = $this->request->get('match_type_filter');

        if (empty($matchType)) {
            return $builder;
        }

        return $builder->where('match_type', $matchType);
    }

    /**
     * Get the display fields.
     */
    public function display(): iterable
    {
        return [
            Select::make('match_type_filter')
                ->options([
                    'exact' => 'Exact Match',
                    'base_model' => 'Base Model',
                    'none' => 'No Match',
                ])
                ->value($this->request->get('match_type_filter'))
                ->title('Match Type')
                ->empty('All Types'),
        ];
    }

    /**
     * Get the value to display for this filter.
     */
    public function value(): string
    {
        $matchType = $this->request->get('match_type_filter');

        $labels = [
            'exact' => 'Exact Match',
            'base_model' => 'Base Model',
            'none' => 'No Match',
        ];

        return $labels[$matchType] ?? '';
    }
}
