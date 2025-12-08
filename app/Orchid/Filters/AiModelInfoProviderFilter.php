<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use App\Models\ApiProvider;
use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Fields\Select;

class AiModelInfoProviderFilter extends Filter
{
    /**
     * The displayable name of the filter.
     */
    public function name(): string
    {
        return 'Provider';
    }

    /**
     * The array of matched parameters.
     */
    public function parameters(): ?array
    {
        return ['modelinfo_provider_filter'];
    }

    /**
     * Apply to a given Eloquent query builder.
     */
    public function run(Builder $builder): Builder
    {
        $providerId = $this->request->get('modelinfo_provider_filter');

        if (empty($providerId)) {
            return $builder;
        }

        return $builder->whereHas('aiModel', function (Builder $query) use ($providerId) {
            $query->where('provider_id', $providerId);
        });
    }

    /**
     * Get the display fields.
     */
    public function display(): iterable
    {
        return [
            Select::make('modelinfo_provider_filter')
                ->fromModel(ApiProvider::class, 'provider_name', 'id')
                ->value($this->request->get('modelinfo_provider_filter'))
                ->title('Provider')
                ->empty('All Providers'),
        ];
    }

    /**
     * Get the value to display for this filter.
     */
    public function value(): string
    {
        $providerId = $this->request->get('modelinfo_provider_filter');

        if (empty($providerId)) {
            return '';
        }

        $provider = ApiProvider::find($providerId);

        return $provider ? $provider->provider_name : "Provider #{$providerId}";
    }
}
