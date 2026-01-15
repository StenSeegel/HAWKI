<?php

namespace App\Policies;

use App\Models\AiAssistant;
use App\Models\User;

class AiAssistantPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     * User can view if:
     * - Assistant is public and active
     * - User is owner
     * - User has required_role for group visibility
     */
    public function view(User $user, AiAssistant $aiAssistant): bool
    {
        if ($aiAssistant->owner_id === $user->id) {
            return true;
        }

        if ($aiAssistant->status !== 'active') {
            return false;
        }

        return match ($aiAssistant->visibility) {
            'public' => true,
            'group' => $user->roles->pluck('slug')->contains($aiAssistant->required_role),
            'private' => false,
            default => false,
        };
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     * User can update if owner, unless it's a system assistant.
     */
    public function update(User $user, AiAssistant $aiAssistant): bool
    {
        // System assistants can only be edited by admins
        if ($aiAssistant->owner_id === 1) {
            return $user->hasAccess('platform.modelsettings.assistants');
        }

        return $aiAssistant->owner_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     * System assistants cannot be deleted.
     */
    public function delete(User $user, AiAssistant $aiAssistant): bool
    {
        if ($aiAssistant->owner_id === 1) {
            return false; // System assistants never deletable
        }

        return $aiAssistant->owner_id === $user->id;
    }
}
