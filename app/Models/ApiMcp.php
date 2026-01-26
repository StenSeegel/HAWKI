<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Orchid\Filters\Filterable;
use Orchid\Screen\AsSource;

class ApiMcp extends Model
{
    use AsSource, Filterable;

    /**
     * The table associated with the model.
     */
    protected $table = 'api_mcp';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'label',
        'type',
        'url',
        'command',
        'args',
        'headers',
        'is_active',
        'display_order',
        'description',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'args' => 'array',
        'headers' => 'array',
        'is_active' => 'boolean',
        'display_order' => 'integer',
    ];

    /**
     * Prepare JSON fields for Orchid Code fields (Pretty Print).
     */
    public function prepareFieldsForScreen(): void
    {
        $this->args = is_array($this->args) ? json_encode($this->args, JSON_PRETTY_PRINT) : $this->args;
        $this->headers = is_array($this->headers) ? json_encode($this->headers, JSON_PRETTY_PRINT) : $this->headers;
    }

    /**
     * Get the configuration array for this MCP server.
     */
    public function getConfigArray(): array
    {
        if ($this->type === 'http') {
            $headers = $this->headers;

            // If headers are still a string (e.g. manually set in screen), decode them
            if (is_string($headers)) {
                $headers = json_decode($headers, true) ?? [];
            }

            return array_filter([
                'url' => $this->url,
                'headers' => $headers,
            ]);
        }

        $args = $this->args;
        if (is_string($args)) {
            $args = json_decode($args, true) ?? [];
        }

        return array_filter([
            'command' => $this->command,
            'args' => $args,
        ]);
    }
}
