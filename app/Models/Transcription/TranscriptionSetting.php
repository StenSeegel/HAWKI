<?php

declare(strict_types=1);

namespace App\Models\Transcription;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Orchid\Screen\AsSource;

class TranscriptionSetting extends Model
{
    use AsSource;

    protected $table = 'transcription_settings';

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'is_private',
    ];

    /**
     * Get the value, decrypting it when the setting is private.
     */
    public function getValueAttribute(mixed $raw): mixed
    {
        if (! $this->is_private || $raw === null || $raw === '') {
            return $raw;
        }

        try {
            return Crypt::decryptString((string) $raw);
        } catch (\Exception) {
            // Value was stored unencrypted (e.g. before encryption was introduced)
            return $raw;
        }
    }

    /**
     * Get the properly typed value.
     */
    public function getTypedValueAttribute(): mixed
    {
        return match ($this->type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $this->value,
            default => $this->value,
        };
    }

    /**
     * Set value with proper type handling, encrypting private settings.
     */
    public function setValueAttribute(mixed $value): void
    {
        if ($this->is_private && $value !== null && $value !== '') {
            $this->attributes['value'] = Crypt::encryptString((string) $value);
        } else {
            $this->attributes['value'] = $value;
        }
    }
}
