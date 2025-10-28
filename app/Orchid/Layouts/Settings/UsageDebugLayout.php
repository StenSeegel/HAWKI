<?php

namespace App\Orchid\Layouts\Settings;

use App\Models\Records\UsageRecord;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class UsageDebugLayout extends Table
{
    /**
     * Data source.
     *
     * @var string
     */
    protected $target = 'usage_records';

    /**
     * Get the table cells to be displayed.
     *
     * @return TD[]
     */
    protected function columns(): iterable
    {
        return [
            TD::make('id', 'ID')
                ->width('80px')
                ->sort()
                ->cantHide()
                ->render(function ($record) {
                    return $record->id;
                }),

            TD::make('user.name', 'User')
                ->sort()
                ->render(function ($record) {
                    if ($record->user) {
                        return Link::make($record->user->name)
                            ->route('platform.systems.users.edit', $record->user->id);
                    }
                    return '<span class="text-muted">N/A</span>';
                }),

            TD::make('room.room_name', 'Room')
                ->sort()
                ->render(function ($record) {
                    return $record->room ? $record->room->room_name : '<span class="text-muted">N/A</span>';
                }),

            TD::make('type', 'Type')
                ->width('100px')
                ->sort()
                ->render(function ($record) {
                    $badges = [
                        'private' => '<span class="badge bg-primary">Private</span>',
                        'group' => '<span class="badge bg-success">Group</span>',
                        'api' => '<span class="badge bg-info">API</span>',
                    ];

                    return $badges[$record->type] ?? $record->type;
                }),

            TD::make('model', 'Model')
                ->sort()
                ->render(function ($record) {
                    return '<code>'.$record->model.'</code>';
                }),

            TD::make('prompt_tokens', 'Prompt Tokens')
                ->width('130px')
                ->sort()
                ->render(function ($record) {
                    return '<span class="text-end d-block">'.number_format($record->prompt_tokens).'</span>';
                }),

            TD::make('completion_tokens', 'Completion Tokens')
                ->width('150px')
                ->sort()
                ->render(function ($record) {
                    return '<span class="text-end d-block">'.number_format($record->completion_tokens).'</span>';
                }),

            TD::make('total_tokens', 'Total Tokens')
                ->width('120px')
                ->render(function ($record) {
                    $total = $record->prompt_tokens + $record->completion_tokens;
                    return '<span class="text-end d-block"><strong>'.number_format($total).'</strong></span>';
                }),

            TD::make('created_at', 'Created At')
                ->width('170px')
                ->sort()
                ->render(function ($record) {
                    return '<small>'.$record->created_at->format('d.m.Y H:i:s').'</small>';
                }),
        ];
    }
}
