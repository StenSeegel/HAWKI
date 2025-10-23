<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\GroupChat;

use App\Models\Room;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\DropDown;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Actions\ModalToggle;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class RoomListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'rooms';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('room_name', __('Room Name'))
                ->sort()
                ->cantHide()
                ->render(function (Room $room) {
                    $icon = $room->is_public ? '🌐 ' : '🔒 ';
                    return $icon . e($room->room_name);
                }),

            TD::make('slug', __('Slug'))
                ->sort()
                ->render(fn (Room $room) => "<code>{$room->slug}</code>"),

            TD::make('is_public', __('Visibility'))
                ->sort()
                ->render(function (Room $room) {
                    if ($room->is_public) {
                        return '<span class="badge bg-success border-0">Public</span>';
                    }
                    return '<span class="badge bg-secondary border-0">Private</span>';
                }),

            TD::make('members_count', __('Members'))
                ->sort()
                ->align(TD::ALIGN_CENTER)
                ->render(fn (Room $room) => $room->members_count ?? 0),

            TD::make('created_at', __('Created'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),

            TD::make(__('Actions'))
                ->align(TD::ALIGN_CENTER)
                ->width('100px')
                ->render(fn (Room $room) => DropDown::make()
                    ->icon('bs.three-dots-vertical')
                    ->list([
                        Link::make(__('View'))
                            ->icon('bs.eye')
                            ->href(url('/groupchat/' . $room->slug))
                            ->target('_blank'),

                        Button::make(__('Toggle Public'))
                            ->icon('bs.globe')
                            ->method('togglePublic', [
                                'id' => $room->id,
                            ])
                            ->confirm('Are you sure you want to change the visibility of this room?'),

                        Button::make(__('Delete'))
                            ->icon('bs.trash3')
                            ->confirm(__('Once the room is deleted, all of its messages and data will be permanently deleted.'))
                            ->method('deleteRoom', [
                                'id' => $room->id,
                            ]),
                    ])),
        ];
    }
}
