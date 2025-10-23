<?php

declare(strict_types=1);

namespace App\Orchid\Screens\GroupChat;

use App\Models\Room;
use App\Orchid\Layouts\GroupChat\RoomListLayout;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Toast;

class RoomManagementScreen extends Screen
{
    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(): iterable
    {
        return [
            'rooms' => Room::with(['members.user'])
                ->withCount('members')
                ->orderBy('is_public', 'desc')
                ->orderBy('created_at', 'desc')
                ->paginate(20),
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return 'Group Chat Room Management';
    }

    /**
     * Display header description.
     */
    public function description(): ?string
    {
        return 'Manage group chat rooms, set public/private visibility, and invite members.';
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
     *
     * @return \Orchid\Screen\Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make('Create Room')
                ->icon('bs.plus-circle')
                ->route('platform.groupchat.rooms.create'),
        ];
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            RoomListLayout::class,
        ];
    }

    /**
     * Toggle the public visibility of a room.
     */
    public function togglePublic(Request $request)
    {
        $room = Room::findOrFail($request->input('id'));
        
        $room->update([
            'is_public' => !$room->is_public,
        ]);

        $visibility = $room->is_public ? 'Public' : 'Private';
        Toast::info("Room visibility changed to {$visibility}");
    }

    /**
     * Delete a room.
     */
    public function deleteRoom(Request $request)
    {
        $room = Room::findOrFail($request->input('id'));
        $roomName = $room->room_name;
        
        $room->deleteRoom();

        Toast::info("Room '{$roomName}' has been deleted");
    }
}
