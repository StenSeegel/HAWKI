<?php

namespace App\Orchid\Screens\ModelSettings;

use App\Models\ApiMcp;
use App\Orchid\Layouts\ModelSettings\McpServerConfigurationLayout;
use App\Orchid\Layouts\ModelSettings\McpServerTabMenu;
use Illuminate\Http\Request;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Toast;

class McpServerConfigurationScreen extends Screen
{
    /**
     * @var ApiMcp
     */
    protected $server;

    /**
     * Define which properties should be serialized.
     */
    public function __sleep(): array
    {
        return ['server'];
    }

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(ApiMcp $server): iterable
    {
        $this->server = $server;

        if ($server->exists) {
            $server->prepareFieldsForScreen();
        }

        return [
            'server' => $server,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return $this->server->exists ? "Configuration: {$this->server->label}" : 'Create MCP Server';
    }

    /**
     * The screen's action buttons.
     *
     * @return \Orchid\Screen\Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make('Back')
                ->icon('bs.arrow-left')
                ->route('platform.models.mcp'),

            Button::make('Save')
                ->icon('bs.check-circle')
                ->method('save'),

            Button::make('Delete')
                ->icon('bs.trash')
                ->method('remove')
                ->canSee($this->server->exists)
                ->confirm('Are you sure you want to delete this MCP server?'),
        ];
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return [
            McpServerTabMenu::class,
            McpServerConfigurationLayout::class,
        ];
    }

    /**
     * Save the server.
     */
    public function save(ApiMcp $server, Request $request)
    {
        $request->validate([
            'server.name' => 'required|string|max:255',
            'server.label' => 'required|string|max:255',
            'server.type' => 'required|in:http,stdio',
        ]);

        $data = $request->get('server');

        // Handle JSON fields
        if (isset($data['args']) && is_string($data['args'])) {
            $data['args'] = json_decode($data['args'], true);
        }
        if (isset($data['headers']) && is_string($data['headers'])) {
            $data['headers'] = json_decode($data['headers'], true);
        }

        // Ensure is_active is boolean
        $data['is_active'] = $request->boolean('server.is_active');

        $server->fill($data)->save();

        Toast::info('MCP Server configuration saved.');

        return redirect()->route('platform.models.mcp.edit', $server->id);
    }

    /**
     * Remove the server.
     */
    public function remove(ApiMcp $server)
    {
        $server->delete();

        Toast::info('MCP Server removed.');

        return redirect()->route('platform.models.mcp');
    }
}
