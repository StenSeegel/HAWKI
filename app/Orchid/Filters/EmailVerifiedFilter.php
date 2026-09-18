<?php

declare(strict_types=1);

namespace App\Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Orchid\Filters\Filter;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Select;

class EmailVerifiedFilter extends Filter
{
    /**
     * The displayable name of the filter.
     */
    public function name(): string
    {
        return __('E-mail verification');
    }

    /**
     * The array of matched parameters.
     */
    public function parameters(): array
    {
        return ['email_verified'];
    }

    /**
     * Apply to a given Eloquent query builder.
     */
    public function run(Builder $builder): Builder
    {
        $value = $this->request->get('email_verified');

        if ($value === '1') {
            return $builder->whereNotNull('email_verified_at');
        }

        if ($value === '0') {
            return $builder->whereNull('email_verified_at');
        }

        return $builder;
    }

    /**
     * Get the display fields.
     *
     * @return Field[]
     */
    public function display(): iterable
    {
        return [
            Select::make('email_verified')
                ->options([
                    '1' => __('Verified'),
                    '0' => __('Unverified'),
                ])
                ->empty(__('All Users'))
                ->value($this->request->get('email_verified'))
                ->title($this->name()),
        ];
    }

    /**
     * Value to be displayed
     */
    public function value(): string
    {
        $value = $this->request->get('email_verified');

        if ($value === '1') {
            return $this->name().': '.__('Verified');
        }

        if ($value === '0') {
            return $this->name().': '.__('Unverified');
        }

        return $this->name();
    }
}
