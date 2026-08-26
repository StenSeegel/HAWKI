<?php

declare(strict_types=1);

namespace App\Models\Transcription;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SummaryTemplate extends Model
{
    use HasFactory;

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the auto-incrementing ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'user_id',
        'name',
        'description',
        'is_builtin',
        'sections',
        'output_format_hints',
        'version',
    ];

    /**
     * Appends for JSON serialization.
     */
    protected $appends = [
        'structure',
    ];

    /**
     * Get the casts for the model.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_builtin' => 'boolean',
            'sections' => 'array',
            'output_format_hints' => 'array',
            'version' => 'integer',
        ];
    }

    /**
     * Accessor for structure (maps to sections).
     */
    public function getStructureAttribute(): ?array
    {
        return $this->sections;
    }

    /**
     * Mutator for structure (maps to sections).
     */
    public function setStructureAttribute(?array $value): void
    {
        $this->attributes['sections'] = $value !== null ? json_encode($value) : null;
    }

    /**
     * Relationship with User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
