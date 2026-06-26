<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use App\Models\Transcription\SummaryTemplate;
use Illuminate\Support\Facades\Auth;

class SummaryTemplateRegistry
{
    /**
     * Resolve a template_id (stable slug) to a SummaryTemplate model.
     * Checks built-in templates, user-independent templates, and user templates owned by the current authenticated user.
     * If no template_id is provided or it doesn't exist, it defaults to the 'legacy' template.
     */
    public function resolve(?string $templateId): SummaryTemplate
    {
        $templateId = $templateId ?? 'legacy';

        $template = SummaryTemplate::where('id', $templateId)
            ->where(function ($query) {
                $query->where('is_builtin', true)
                    ->orWhereNull('user_id')
                    ->orWhere('user_id', Auth::id());
            })
            ->first();

        if ($template) {
            return $template;
        }

        // Fallback to legacy if the requested template is not found
        return SummaryTemplate::where('id', 'legacy')->firstOrFail();
    }

    /**
     * List all templates available to the current user (built-in templates + user's own templates).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SummaryTemplate>
     */
    public function listAll(): \Illuminate\Database\Eloquent\Collection
    {
        return SummaryTemplate::where('is_builtin', true)
            ->orWhereNull('user_id')
            ->orWhere('user_id', Auth::id())
            ->orderBy('is_builtin', 'desc')
            ->orderBy('name', 'asc')
            ->get();
    }
}
