<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TranslateGlossary extends Model
{
    use HasFactory;

    protected $table = 'translate_glossaries';

    protected $fillable = [
        'unique_name',
        'display_name',
        'domain',
        'description',
        'visibility',
        'editor_role',
        'organization_id',
        'created_by',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function entries()
    {
        return $this->hasMany(TranslateGlossaryEntry::class, 'glossary_id');
    }

    public function assignedEditorRole()
    {
        return $this->belongsTo(\Orchid\Platform\Models\Role::class, 'editor_role', 'slug');
    }

    public function organization()
    {
        return $this->belongsTo(\Orchid\Platform\Models\Role::class, 'organization_id');
    }
}
