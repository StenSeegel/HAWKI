<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\User;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Upload;
use Orchid\Screen\Layouts\Rows;

class SystemUserAvatarLayout extends Rows
{
    /**
     * The screen's layout elements.
     *
     * @return Field[]
     */
    public function fields(): array
    {
        /** @var \App\Models\User $user */
        $user = $this->query->get('user');

        // Get current avatar URL if exists
        $currentAttachment = null;
        if (!empty($user->avatar_id)) {
            try {
                $avatarStorage = app(\App\Services\Storage\AvatarStorageService::class);
                $avatarUrl = $avatarStorage->getUrl($user->avatar_id, 'profile_avatars');
                
                // Create attachment array for Upload field
                $currentAttachment = [
                    [
                        'name' => 'current-avatar.jpg',
                        'url' => $avatarUrl,
                        'original_name' => 'Avatar',
                    ]
                ];
            } catch (\Exception $e) {
                // Avatar not found, will use default
            }
        }

        return [
            Upload::make('user.avatar')
                ->title('AI Assistant Avatar')
                ->maxFiles(1)
                ->acceptedFiles('image/*')
                //->maxFileSize(10)
                ->value($currentAttachment)
                ->help('Upload a profile picture for the AI assistant (max 10MB). Supported formats: JPG, PNG, GIF, WebP.')
                ->storage('public'),
        ];
    }
}
