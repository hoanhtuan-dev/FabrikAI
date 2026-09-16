<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Dự án '.fake()->words(3, true),
            'base_concept' => fake()->optional()->sentence(),
            'status' => Project::STATUS_DRAFT,
            'brief' => fake()->optional()->paragraph(),
            'deadline' => fake()->optional()->dateTimeBetween('now', '+30 days'),
            'thumbnail_url' => null,
            'tags' => [],
            'color' => null,
            'sort' => 0,
            'archived' => false,
            'settings' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }
}
