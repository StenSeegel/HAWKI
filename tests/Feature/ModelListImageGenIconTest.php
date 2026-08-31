<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ModelListImageGenIconTest extends TestCase
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
                        'id' => 'gpt-5',
                        'label' => 'GPT-5',
                        'status' => 'online',
                        'visible' => true,
                        'tools' => $tools,
                        'provider_name' => 'OpenAI',
                    ]],
                ],
            ]
        );
    }

    public function test_the_icon_is_rendered_for_a_model_that_can_generate_images(): void
    {
        $html = $this->renderModelsList(['image_gen' => true]);

        $this->assertStringContainsString('image-generation-icon', $html);
        $this->assertStringContainsString('Supports image generation', $html);

        // The glyph of resources/icons/image.svg, the one the input button uses.
        $this->assertStringContainsString('polyline points="21 15 16 10 5 21"', $html);
    }

    public function test_no_icon_without_the_capability(): void
    {
        $html = $this->renderModelsList(['vision' => true]);

        $this->assertStringNotContainsString('image-generation-icon', $html);
        $this->assertStringNotContainsString('Supports image generation', $html);
    }

    public function test_the_card_and_the_library_use_the_same_glyph_as_the_input_button(): void
    {
        $glyph = 'polyline points="21 15 16 10 5 21"';

        foreach ([
            'views/partials/home/input-field.blade.php',
            'views/partials/home/components/model-info-card.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path($view));
            $this->assertStringContainsString(
                'image-generation-icon',
                $source,
                "$view should mark its image generation icon"
            );
        }

        // The library picks the icon through $capabilityMeta, so assert on the name.
        $library = file_get_contents(resource_path('views/modules/model-library.blade.php'));
        $this->assertStringContainsString("'icon' => 'image'", $library);
        $this->assertStringContainsString("'class' => 'image-generation-icon'", $library);

        // And nothing still points at the icon that was used before.
        foreach ([
            'views/partials/home/components/model-info-card.blade.php',
            'views/modules/model-library.blade.php',
        ] as $view) {
            $this->assertStringNotContainsString('stars', file_get_contents(resource_path($view)));
        }

        $this->assertStringContainsString(
            $glyph,
            file_get_contents(resource_path('../resources/icons/image.svg'))
        );
    }
}
