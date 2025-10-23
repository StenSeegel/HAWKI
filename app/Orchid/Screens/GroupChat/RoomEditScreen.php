<?php

declare(strict_types=1);

namespace App\Orchid\Screens\GroupChat;

use App\Models\Room;
use App\Models\Member;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Switcher;
use Orchid\Screen\Fields\TextArea;
use Orchid\Support\Facades\Toast;

class RoomEditScreen extends Screen
{
    /**
     * @var Room
     */
    public $room;

    /**
     * Fetch data to be displayed on the screen.
     */
    public function query(Room $room): iterable
    {
        return [
            'room' => $room,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return $this->room->exists ? 'Edit Room' : 'Create Room';
    }

    /**
     * The permissions required to access this screen.
     */
    public function permission(): ?iterable
    {
        return [
            'platform.groupchat.rooms',
        ];
    }

    /**
     * The screen's action buttons.
     */
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Save'))
                ->icon('bs.check-circle')
                ->method('save'),

            Button::make(__('Remove'))
                ->icon('bs.trash3')
                ->method('remove')
                ->canSee($this->room->exists)
                ->confirm('Once the room is deleted, all of its messages and data will be permanently deleted.'),
        ];
    }

    /**
     * The screen's layout elements.
     */
    public function layout(): iterable
    {
        return [
            Layout::rows([
                Input::make('room.room_name')
                    ->title('Room Name')
                    ->placeholder('Enter room name')
                    ->required()
                    ->help('The name of the group chat room'),

                TextArea::make('room.room_description')
                    ->title('Room Description')
                    ->placeholder('Enter room description (optional)')
                    ->rows(3)
                    ->help('Brief description of the room purpose'),

                Switcher::make('room.is_public')
                    ->title('Public Room')
                    ->placeholder('Make this room visible to all users')
                    ->help('Public rooms are visible to all users. Users can join public rooms by themselves.')
                    ->sendTrueOrFalse(),
            ]),
        ];
    }

    /**
     * Save the room.
     */
    public function save(Room $room, Request $request)
    {
        $request->validate([
            'room.room_name' => 'required|string|max:255',
            'room.room_description' => 'nullable|string',
            'room.is_public' => 'boolean',
        ]);

        $isNew = !$room->exists;

        $room->fill($request->input('room'))->save();

        // Add HAWKI AI as assistant for new rooms
        if ($isNew) {
            $room->addMember(1, Member::ROLE_ASSISTANT);
        }

        Toast::info('Room saved successfully');

        return redirect()->route('platform.groupchat.rooms');
    }

    /**
     * Remove the room.
     */
    public function remove(Room $room)
    {
        $roomName = $room->room_name;
        
        $room->deleteRoom();

        Toast::info("Room '{$roomName}' has been deleted");

        return redirect()->route('platform.groupchat.rooms');
    }
}
