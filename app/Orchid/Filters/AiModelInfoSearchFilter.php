<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Input;

class AiModelInfoSearchFilter extends Filter
{
    /**
     * The displayable name of the filter.
     */
    public function name(): string
    {
        return 'Search Model Info';
    }

    /**
     * The array of matched parameters.
     */
    public function parameters(): ?array
    {
        return ['modelinfo_search'];
    }

    /**
     * Apply to a given Eloquent query builder.
     */
    public function run(Builder $builder): Builder
    {
        $search = $this->request->get('modelinfo_search');

        if (empty($search)) {
            return $builder;
        }

        return $builder->where(function (Builder $query) use ($search) {
            $query->where('model_info_id', 'LIKE', "%{$search}%")
                ->orWhere('name', 'LIKE', "%{$search}%")
                ->orWhere('base_model_id', 'LIKE', "%{$search}%")
                ->orWhereHas('aiModel', function (Builder $subQuery) use ($search) {
                    $subQuery->where('label', 'LIKE', "%{$search}%")
                        ->orWhere('model_id', 'LIKE', "%{$search}%");
                });
        });
    }

    /**
     * Get the display fields.
     */
    public function display(): iterable
    {
        return [
            Input::make('modelinfo_search')
                ->type('search')
                ->value($this->request->get('modelinfo_search'))
                ->placeholder('Search by model name, JSON ID, or provider...')
                ->title('Search'),
        ];
    }
}
