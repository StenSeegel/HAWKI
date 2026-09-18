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
        'needs_admin_approval',
        'priority',
        'is_active',
        'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'needs_admin_approval' => 'boolean',
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
        'needs_admin_approval',
        'is_active',
        'created_at',
        'updated_at',
    ];

    protected static function booted(): void
    {
        // Keep the employeetype mapping in step with the rule. The rule stores the role
        // a matching registration receives, and the admin expects to find that same
        // pairing on the role assignment screen instead of having to repeat it there.
        static::saved(function (self $rule) {
            $rule->ensureRoleAssignment();
        });
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Create the employeetype and its primary role assignment that this rule implies.
     *
     * A self-registering user matching the rule is stored with the role slug as their
     * employeetype, so the pairing has to exist for the admin screens to show it and
     * for the external auth path to resolve the same value.
     */
    public function ensureRoleAssignment(): ?EmployeetypeRole
    {
        // Read the role by the id the rule now carries. Going through the relation
        // would hand back the value cached before the role was changed.
        $role = Role::find($this->role_id);

        if (! $role) {
            return null;
        }

        $employeetype = Employeetype::firstOrCreate(
            [
                'raw_value' => $role->slug,
                'auth_method' => 'local',
            ],
            [
                'display_name' => $role->name,
                'is_active' => true,
                'description' => 'Created automatically for the e-mail domain rule "' . $this->pattern . '".',
            ]
        );

        return EmployeetypeRole::assignRole($employeetype->id, $role->id, true);
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
