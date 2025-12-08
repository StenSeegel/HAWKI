<?php

namespace Database\Factories;

use App\Models\AiModel;
use App\Models\AiModelInfo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiModelInfo>
 */
class AiModelInfoFactory extends Factory
{
    protected $model = AiModelInfo::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_model_id' => AiModel::factory(),
            'model_info_id' => fake()->randomElement(['gpt-4', 'gpt-3.5-turbo', 'claude-3-opus', 'gemini-pro']),
            'matched_provider_id' => fake()->randomElement(['openai', 'anthropic', 'google', null]),
            'match_type' => fake()->randomElement(['exact', 'base_model', 'none']),
            'aliases' => fake()->randomElement([
                ['gpt-4', 'openai/gpt-4'],
                ['claude-3-opus', 'anthropic/claude-3-opus'],
                null,
            ]),
            'description_en' => fake()->sentence(20),
            'description_de' => fake()->sentence(20),
            'knowledge_cutoff' => fake()->date(),
            'reasoning' => fake()->boolean(30),
            'tool_calling' => fake()->boolean(50),
            'open_weights' => fake()->boolean(20),
            'input_types' => fake()->randomElement([
                ['text'],
                ['text', 'image'],
                ['text', 'image', 'audio'],
                ['text', 'image', 'video', 'file'],
            ]),
            'output_types' => fake()->randomElement([
                ['text'],
                ['text', 'image'],
            ]),
            'parameters' => fake()->randomElement([
                ['temperature', 'max_tokens', 'top_p'],
                ['temperature', 'tools', 'response_format'],
                null,
            ]),
            'default_parameters' => fake()->randomElement([
                ['temperature' => 0.7],
                null,
            ]),
            'context_length' => fake()->randomElement([4096, 8192, 32000, 128000, 200000, null]),
            'output_limit' => fake()->randomElement([4096, 8192, 16384, null]),
            'price_input_usd' => fake()->randomFloat(6, 0, 10),
            'price_output_usd' => fake()->randomFloat(6, 0, 30),
            'price_input_eur' => fake()->randomFloat(6, 0, 10),
            'price_output_eur' => fake()->randomFloat(6, 0, 30),
            'deprecated' => fake()->boolean(10),
            'last_imported_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'last_matched_at' => fake()->dateTimeBetween('-1 month', 'now'),
            'overwritten_fields' => fake()->randomElement([
                null,
                ['description_de' => true],
                ['price_input_eur' => true, 'price_output_eur' => true],
            ]),
        ];
    }

    /**
     * Indicate that the model has an exact match.
     */
    public function exactMatch(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_type' => 'exact',
            'matched_provider_id' => fake()->randomElement(['openai', 'anthropic', 'google']),
        ]);
    }

    /**
     * Indicate that the model has only a base model match.
     */
    public function baseModelMatch(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_type' => 'base_model',
            'matched_provider_id' => null,
            'context_length' => null,
            'output_limit' => null,
            'price_input_usd' => null,
            'price_output_usd' => null,
            'price_input_eur' => null,
            'price_output_eur' => null,
        ]);
    }

    /**
     * Indicate that the model has no match (manual setup).
     */
    public function noMatch(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_type' => 'none',
            'model_info_id' => null,
            'matched_provider_id' => null,
            'aliases' => null,
            'description_en' => null,
            'description_de' => null,
            'parameters' => null,
        ]);
    }

    /**
     * Indicate that fields are manually overwritten.
     */
    public function withLockedFields(array $fields = []): static
    {
        $lockedFields = empty($fields)
            ? ['description_de' => true, 'price_input_eur' => true, 'price_output_eur' => true]
            : array_fill_keys($fields, true);

        return $this->state(fn (array $attributes) => [
            'overwritten_fields' => $lockedFields,
        ]);
    }

    /**
     * Indicate that the model is deprecated.
     */
    public function deprecated(): static
    {
        return $this->state(fn (array $attributes) => [
            'deprecated' => true,
        ]);
    }
}
