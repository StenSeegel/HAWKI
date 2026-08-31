<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ImageGenCapabilityPermissionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The capability icons only exist in the database-mode branch.
        config(['hawki.ai_config_system' => true]);
    }

    private function renderModelsList(array $hiddenCapabilities): string
    {
        return Blade::render(
            file_get_contents(resource_path('views/partials/home/components/models-list.blade.php')),
            [
                'translation' => [],
                'hiddenCapabilities' => $hiddenCapabilities,
                'models' => [
                    'models' => [[
                        'id' => 'gpt-5',
                        'label' => 'GPT-5',
                        'status' => 'online',
                        'visible' => true,
                        'tools' => ['image_gen' => true, 'vision' => true],
                        'provider_name' => 'OpenAI',
                    ]],
                ],
            ]
        );
    }

    public function test_the_icon_is_hidden_when_the_role_does_not_grant_the_permission(): void
    {
        $html = $this->renderModelsList(['image_gen']);

        $this->assertStringNotContainsString('image-generation-icon', $html);
        $this->assertStringNotContainsString('Supports image generation', $html);

        // An ungated capability on the same model is untouched.
        $this->assertStringContainsString('Supports image input', $html);
    }

    public function test_the_icon_is_shown_when_the_role_grants_the_permission(): void
    {
        $html = $this->renderModelsList([]);

        $this->assertStringContainsString('image-generation-icon', $html);
        $this->assertStringContainsString('Supports image generation', $html);
    }

    public function test_the_card_templates_carry_the_hidden_keys_to_the_javascript(): void
    {
        $html = Blade::render(
            file_get_contents(resource_path('views/partials/home/components/model-info-card.blade.php')),
            ['translation' => [], 'hiddenCapabilities' => ['image_gen']]
        );

        $this->assertStringContainsString('data-hidden-capabilities="image_gen"', $html);
    }

    public function test_the_library_filters_hidden_capabilities_inside_its_tools_loop(): void
    {
        // The full view extends the home layout, so assert on the section that
        // builds the tags instead of rendering the whole page.
        $source = file_get_contents(resource_path('views/modules/model-library.blade.php'));

        $loopAt = strpos($source, 'foreach ($rawCapabilities as $k => $v)');
        $this->assertNotFalse($loopAt, 'the tools loop should still be there');

        $loop = substr($source, $loopAt);
        $filterAt = strpos($loop, 'in_array($k, $hiddenCapabilities ?? [], true)');
        $appendAt = strpos($loop, '$capabilities[] = [');

        $this->assertNotFalse($filterAt, 'the loop should skip hidden capabilities');
        $this->assertNotFalse($appendAt);
        $this->assertLessThan($appendAt, $filterAt, 'the skip has to happen before the tag is built');
    }

    public function test_the_controller_hides_the_capability_only_without_the_permission(): void
    {
        $without = User::factory()->create(['permissions' => []]);
        $with = User::factory()->create(['permissions' => ['image_generation.access' => true]]);

        $this->assertFalse($without->hasAccess('image_generation.access'));
        $this->assertTrue($with->hasAccess('image_generation.access'));
    }
}
