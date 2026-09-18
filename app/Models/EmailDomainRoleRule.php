<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Orchid\Filters\Filterable;
use Orchid\Filters\Types\Like;
use Orchid\Filters\Types\Where;
use Orchid\Screen\AsSource;

/**
 * Maps the domain part of an e-mail address to the role a self-registering user receives.
 */
class EmailDomainRoleRule extends Model
{
    use AsSource, Filterable, HasFactory;

    protected $fillable = [
        'pattern',
        'role_id',
        'priority',
        'is_active',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * The attributes for which you can use filters in url.
     *
     * @var array
     */
    protected $allowedFilters = [
        'id' => Where::class,
        'pattern' => Like::class,
        'is_active' => Where::class,
    ];

    /**
     * The attributes for which can use sort in url.
     *
     * @var array
     */
    protected $allowedSorts = [
        'id',
        'pattern',
        'priority',
        'is_active',
        'created_at',
        'updated_at',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Scope to the rules that take part in domain filtering, most specific first.
     */
    public function scopeActiveByPriority(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id');
    }
}
