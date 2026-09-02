<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AI\Tools\CodeInterpreterTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Code execution is a model capability like vision or web search: it is set per
 * model in the admin and advertised to the user on the model.
 */
class ModelCodeInterpreterCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private function renderModelsList(array $tools): string
    {
        // The capability icons only exist in the database-mode branch.
        config(['hawki.ai_config_system' => true]);

        return Blade::render(
            file_get_contents(resource_path('views/partials/home/components/models-list.blade.php')),
            [
                'translation' => [],
                'models' => [
                    'models' => [[
                        'id' => 'jlu/qwen3.8-27b',
                        'label' => 'Qwen 3.8',
                        'status' => 'online',
                        'visible' => true,
                        'tools' => $tools,
                        'provider_name' => 'ki@JLU',
                    ]],
                ],
            ]
        );
    }

    public function test_the_icon_is_rendered_for_a_model_that_can_run_code(): void
    {
        $html = $this->renderModelsList(['code_interpreter' => true]);

        $this->assertStringContainsString('Supports running code', $html);
        // The glyph of resources/icons/square-terminal.svg.
        $this->assertStringContainsString('M11 13h4', $html);
    }

    public function test_no_icon_without_the_capability(): void
    {
        $html = $this->renderModelsList(['web_search' => true]);

        $this->assertStringNotContainsString('Supports running code', $html);
    }

    public function test_the_capability_is_offered_in_the_model_admin(): void
    {
        $fields = (new \App\Orchid\Layouts\ModelSettings\AiModelToolsLayout())->fields();

        $names = array_map(fn ($field) => $field->get('name'), $fields);

        $this->assertContains('model.settings.tools.code_interpreter', $names);
    }

    public function test_the_capability_can_be_toggled_from_the_model_list(): void
    {
        // The list screen validates against a whitelist before writing.
        $reflection = new \ReflectionMethod(\App\Orchid\Screens\ModelSettings\AiModelListScreen::class, 'toggleCapability');

        $this->assertStringContainsString(
            "'code_interpreter'",
            file_get_contents($reflection->getFileName())
        );
    }

    public function test_the_capability_key_matches_the_tool_the_runtime_resolves(): void
    {
        // A mismatch here is invisible until a model never gets the tool - which
        // is what happened to image generation ('image_gen' vs 'image_generation').
        $this->assertSame('code_interpreter', CodeInterpreterTool::KEY);
        $this->assertArrayHasKey(CodeInterpreterTool::KEY, config('hawki_tools.tools'));
    }

    public function test_both_languages_label_the_capability(): void
    {
        foreach (['en_US', 'de_DE'] as $language) {
            $texts = json_decode(file_get_contents(resource_path("language/{$language}.json")), true);

            $this->assertNotEmpty($texts['ModelCapability_CodeInterpreter'] ?? '', $language);
            $this->assertNotEmpty($texts['ModelCapabilityTag_CodeInterpreter'] ?? '', $language);
        }
    }
}
