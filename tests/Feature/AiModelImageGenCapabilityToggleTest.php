<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\ApiProvider;
use App\Models\User;
use App\Orchid\Layouts\ModelSettings\AiModelListLayout;
use App\Orchid\Screens\ModelSettings\AiModelListScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Orchid\Screen\Repository;
use Tests\TestCase;

class AiModelImageGenCapabilityToggleTest extends TestCase
{
    use RefreshDatabase;

    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $provider = ApiProvider::create([
            'unique_name' => 'test-provider',
            'provider_name' => 'Test Provider',
            'api_key' => 'key',
            'base_url' => 'https://example.invalid',
            'is_active' => true,
            'additional_settings' => [],
        ]);

        $this->model = AiModel::create([
            'system_id' => (string) \Illuminate\Support\Str::uuid(),
            'model_id' => 'gpt-5',
            'label' => 'GPT-5',
            'provider_id' => $provider->id,
            'is_active' => true,
            'is_visible' => true,
            'display_order' => 0,
            'information' => ['input' => ['text', 'image'], 'output' => ['text']],
            'settings' => ['tools' => ['vision' => true]],
        ]);
    }

    private function toggle(string $capability): void
    {
        (new AiModelListScreen())->toggleCapability(
            new Request(['id' => $this->model->id, 'capability' => $capability])
        );

        $this->model->refresh();
    }

    public function test_the_list_renders_an_image_gen_toggle(): void
    {
        $html = (string) (new AiModelListLayout())->build(new Repository([
            'models' => AiModel::paginate(),
        ]));

        // The toggle posts the capability key back to toggleCapability().
        $this->assertStringContainsString('capability=image_gen', $html);
        $this->assertStringContainsString('title="Image Generation"', $html);

        // Rendered next to the capabilities that were already there.
        foreach (['file_upload', 'vision', 'web_search', 'reasoning'] as $existing) {
            $this->assertStringContainsString('capability=' . $existing, $html);
        }
    }

    public function test_toggling_image_gen_switches_it_on_and_off(): void
    {
        $this->assertArrayNotHasKey('image_gen', $this->model->settings['tools']);

        $this->toggle('image_gen');
        $this->assertTrue($this->model->settings['tools']['image_gen']);

        $this->toggle('image_gen');
        $this->assertFalse($this->model->settings['tools']['image_gen']);
    }

    public function test_toggling_image_gen_leaves_the_other_capabilities_alone(): void
    {
        $this->toggle('image_gen');

        $this->assertTrue($this->model->settings['tools']['vision']);
    }

    public function test_an_unknown_capability_is_still_rejected(): void
    {
        $this->toggle('teleportation');

        $this->assertArrayNotHasKey('teleportation', $this->model->settings['tools']);
    }
}
