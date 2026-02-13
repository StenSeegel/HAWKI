<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TranslateGlossaryEntry extends Model
{
    use HasFactory;

    protected $table = 'translate_glossary_entries';

    protected $fillable = [
        'glossary_id',
        'source_language',
        'target_language',
        'source_term',
        'target_term',
        'case_sensitive',
    ];

    protected $casts = [
        'glossary_id' => 'integer',
        'case_sensitive' => 'boolean',
    ];

    public function glossary()
    {
        return $this->belongsTo(TranslateGlossary::class, 'glossary_id');
    }
}
