<?php

namespace App\Orchid\Screens\ModelSettings;

use App\Models\ApiMcp;
use App\Orchid\Layouts\ModelSettings\McpServerTabMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use NeuronAI\MCP\McpConnector;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Fields\Code;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Fields\TextArea;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class McpServerEditScreen extends Screen
{
    /**
     * @var ApiMcp
     */
    protected $server;

    /**
     * @var string|null
     */
    protected $selectedTool = null;

    /**
     * @var array
     */
    protected $tools = [];

    /**
     * @var mixed
     */
    protected $selectedToolDetails = null;

    /**
     * @var string|null
     */
    protected $toolResult = null;

    /**
     * @var string|null
     */
    protected $toolJson = null;

    /**
     * @var array|null
     */
    protected $toolData = null;

    /**
     * @var float|null
     */
    protected $toolTime = null;

    /**
     * @var string|null
     */
    protected $toolError = null;

    /**
     * Define which properties should be serialized.
     * We exclude 'tools' and 'selectedToolDetails' because they contain
     * large objects with closures that are reconstructed in query().
     */
    public function __sleep(): array
    {
        return ['server', 'selectedTool'];
    }

    /**
     * Fetch data to be displayed on the screen.
     *
     * @return array
     */
    public function query(ApiMcp $server, Request $request): iterable
    {
        $this->server = $server;
        $this->selectedTool = $request->get('tool');
        $sessionId = session()->getId();

        // Load cached results
        $this->toolResult = Cache::pull("mcp_res_{$sessionId}");
        $this->toolJson = Cache::pull("mcp_json_{$sessionId}");
        $this->toolData = Cache::pull("mcp_data_{$sessionId}");
        $this->toolTime = Cache::pull("mcp_time_{$sessionId}");
        $this->toolError = Cache::pull("mcp_err_{$sessionId}");
        $toolInput = Cache::get("mcp_input_{$sessionId}", []);

        if ($server->exists && $server->is_active) {
            try {
                $config = $server->getConfigArray();
                $connector = McpConnector::make($config);
                $this->tools = $connector->tools();

                // Get details for selected tool - support both exact and prefixed names
                if ($this->selectedTool) {
                    foreach ($this->tools as $tool) {
                        $toolName = $tool->getName();
                        $prefixedName = "{$server->name}-{$toolName}";

                        if ($toolName === $this->selectedTool || $prefixedName === $this->selectedTool) {
                            $this->selectedToolDetails = $tool;
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error("Failed to fetch tools for MCP server '{$server->name}': ".$e->getMessage());
            }
        }

        // Pretty print JSON for the Code fields - AFTER fetching tools to avoid type errors
        if ($server->exists) {
            $server->prepareFieldsForScreen();
        } else {
            // Defaults for new servers
            $server->args = json_encode([], JSON_PRETTY_PRINT);
            $server->headers = json_encode([], JSON_PRETTY_PRINT);
            $server->is_active = true;
            $server->display_order = 0;
            $server->type = 'stdio';
        }

        return [
            'server' => $server,
            'tools' => $this->tools,
            'selectedTool' => $this->selectedToolDetails,
            'tool_input' => $toolInput,
            'tool_result' => $this->toolResult,
            'tool_json' => $this->toolJson,
            'tool_data' => $this->toolData,
            'tool_time' => $this->toolTime,
            'tool_error' => $this->toolError,
        ];
    }

    /**
     * The name of the screen displayed in the header.
     */
    public function name(): ?string
    {
        return $this->server->exists ? "MCP Tools: {$this->server->label}" : 'MCP Tools';
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
        ];
    }

    /**
     * The screen's layout elements.
     *
     * @return \Orchid\Screen\Layout[]|string[]
     */
    public function layout(): iterable
    {
        return array_merge([
            McpServerTabMenu::class,
            Layout::view('orchid.mcp.playground-styles'),
        ], $this->mcpToolsLayout());
    }

    /**
     * Build MCP Tools layout with tool list and testing playground
     */
    protected function mcpToolsLayout(): array
    {
        $tools = $this->tools;
        $selectedTool = $this->selectedToolDetails;
        $selectedToolName = $this->selectedTool;

        if (! $this->server->exists || ! $this->server->is_active) {
            return [
                Layout::view('orchid.mcp.tool-inactive'),
            ];
        }

        if (empty($tools)) {
            return [
                Layout::view('orchid.mcp.tool-empty'),
            ];
        }

        // 1. Row: Horizontal Tool List
        // 2. Row: Columns with Input and Result
        return [
            Layout::view('orchid.mcp.tool-list-horizontal', [
                'tools' => $tools,
                'selectedTool' => $selectedToolName,
                'serverId' => $this->server->id,
                'serverName' => $this->server->name,
            ]),

            Layout::wrapper('orchid.mcp.playground-wrapper', [
                'slot' => $selectedTool
                    ? $this->buildToolPlayground($selectedTool)
                    : Layout::view('orchid.mcp.tool-placeholder', ['title' => 'Tool Result']),
            ]),
        ];
    }

    /**
     * Build tool testing playground
     */
    protected function buildToolPlayground($tool): \Orchid\Screen\Layout
    {
        $inputFields = [];

        // Build input fields based on tool properties (which are objects)
        $properties = $tool->getProperties();

        foreach ($properties as $property) {
            $field = $this->createFieldFromProperty($property);
            if ($field) {
                $inputFields[] = $field;
            }
        }

        $inputFields[] = Button::make('Call Tool')
            ->icon('bs.play-circle')
            ->method('testTool')
            ->parameters(['tool' => $tool->getName()])
            ->type(Color::PRIMARY);

        $resultLayout = ! empty($this->toolResult) || ! empty($this->toolError)
            ? Layout::view('orchid.mcp.tool-result', [
                'result' => $this->toolResult,
                'json' => $this->toolJson,
                'data' => $this->toolData,
                'time' => $this->toolTime,
                'error' => $this->toolError,
                'title' => 'Tool Result',
            ])
            : Layout::view('orchid.mcp.tool-placeholder', [
                'title' => 'Tool Result',
            ]);

        return Layout::wrapper('orchid.mcp.playground-split', [
            'left' => Layout::rows($inputFields)->title('Input Parameters'),
            'right' => $resultLayout,
        ]);
    }

    /**
     * Create form field from tool property object
     */
    protected function createFieldFromProperty(\NeuronAI\Tools\ToolPropertyInterface $property)
    {
        $name = $property->getName();
        $type = $property->getType()->value;
        $description = $property->getDescription() ?? '';
        $required = $property->isRequired();

        $field = match ($type) {
            'string' => Input::make("tool_input.{$name}")
                ->title(ucfirst($name))
                ->help($description),
            'number', 'integer' => Input::make("tool_input.{$name}")
                ->type('number')
                ->title(ucfirst($name))
                ->help($description),
            'boolean' => Select::make("tool_input.{$name}")
                ->options([
                    '' => 'Default',
                    'true' => 'True',
                    'false' => 'False',
                ])
                ->title(ucfirst($name))
                ->help($description),
            'array', 'object' => TextArea::make("tool_input.{$name}")
                ->title(ucfirst($name))
                ->rows(5)
                ->help($description.' (JSON format)'),
            default => Input::make("tool_input.{$name}")
                ->title(ucfirst($name))
                ->help($description),
        };

        if ($required) {
            $field->required();
        }

        return $field;
    }

    /**
     * Test tool execution
     */
    public function testTool(ApiMcp $server, Request $request)
    {
        $this->server = $server;
        $toolName = $request->get('tool');
        $inputs = $request->get('tool_input', []);

        try {
            $config = $this->server->getConfigArray();
            $connector = McpConnector::make($config);
            $tools = $connector->tools();

            $tool = collect($tools)->first(fn ($t) => $t->getName() === $toolName);

            if (! $tool) {
                Toast::error("Tool '{$toolName}' not found.");

                return;
            }

            // Sanitize inputs based on tool properties
            $sanitizedInputs = [];
            $properties = $tool->getProperties();
            $sessionId = session()->getId();

            // Store raw inputs for UI persistence
            Cache::put("mcp_input_{$sessionId}", $inputs, 300);

            foreach ($inputs as $key => $value) {
                // Skip empty strings for optional parameters (except boolean false)
                if ($value === '' || $value === null) {
                    continue;
                }

                $property = collect($properties)->first(fn ($p) => $p->getName() === $key);
                if ($property) {
                    $type = $property->getType()->value;
                    if ($type === 'boolean') {
                        // Explicitly handle "true"/"false" strings from Select
                        $sanitizedInputs[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    } elseif (($type === 'integer' || $type === 'number') && ($value !== '')) {
                        $sanitizedInputs[$key] = $type === 'integer' ? (int) $value : (float) $value;
                    } elseif (($type === 'array' || $type === 'object') && is_string($value)) {
                        $decoded = json_decode($value, true);
                        $sanitizedInputs[$key] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $value;
                    } else {
                        $sanitizedInputs[$key] = $value;
                    }
                } else {
                    $sanitizedInputs[$key] = $value;
                }
            }

            Log::info("Executing MCP tool '{$toolName}' with inputs:", $sanitizedInputs);

            // Set inputs and execute
            $startTime = microtime(true);
            $tool->setInputs($sanitizedInputs);
            $tool->execute();
            $result = $tool->getResult();
            $executionTime = round(microtime(true) - $startTime, 3);

            // Aggressive JSON unwrapping
            $data = $result;
            for ($i = 0; $i < 5; $i++) {
                if (! is_string($data)) {
                    break;
                }
                $decoded = json_decode($data, true);
                if (json_last_error() !== JSON_ERROR_NONE || $decoded === $data) {
                    break;
                }
                $data = $decoded;
            }

            // Always format as pretty JSON if it's a structure, otherwise show as string
            if (is_array($data) || is_object($data)) {
                $formattedResult = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $formattedResult = (string) $data;
            }

            // Raw JSON representation of the unwrapped data for the JSON view
            $jsonResult = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $sessionId = session()->getId();
            Cache::put("mcp_res_{$sessionId}", $formattedResult, 300);
            Cache::put("mcp_json_{$sessionId}", $jsonResult, 300);
            Cache::put("mcp_data_{$sessionId}", $data, 300);
            Cache::put("mcp_time_{$sessionId}", $executionTime, 300);

            Toast::success('Tool executed successfully!');

        } catch (\Exception $e) {
            $sessionId = session()->getId();
            Cache::put("mcp_err_{$sessionId}", $e->getMessage(), 300);
            Toast::error('Tool execution failed: '.$e->getMessage());
            Log::error('MCP Tool execution error: '.$e->getMessage());
        }

        return redirect()->route('platform.models.mcp.edit', [
            'server' => $this->server->id,
            'tool' => $toolName,
        ]);
    }
}
