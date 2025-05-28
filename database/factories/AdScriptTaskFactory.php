<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TaskStatus;
use App\Models\AdScriptTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AdScriptTask>
 */
class AdScriptTaskFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     */
    protected $model = AdScriptTask::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'reference_script' => $this->faker->text(500),
            'outcome_description' => $this->faker->sentence(10),
            'new_script' => null,
            'analysis' => null,
            'status' => TaskStatus::PENDING,
            'error_details' => null,
        ];
    }

    /**
     * Indicate that the task is processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::PROCESSING,
        ]);
    }

    /**
     * Indicate that the task is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::COMPLETED,
            'new_script' => $this->faker->text(600),
            'analysis' => [
                'improvements' => $this->faker->sentence(),
                'changes_made' => $this->faker->words(5),
            ],
            'error_details' => null,
        ]);
    }

    /**
     * Indicate that the task has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::FAILED,
            'new_script' => null,
            'analysis' => null,
            'error_details' => $this->faker->sentence(),
        ]);
    }
}
