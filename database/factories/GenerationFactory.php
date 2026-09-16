<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => null,
            'prompts_history_id' => null,
            'type' => 'image',
            'status' => 'completed',
            'prompt' => fake()->sentence(),
            'media_url' => '/storage/'.fake()->word().'.png',
            'base_image' => null,
            'mask_image' => null,
            'job_id' => null,
            'error' => null,
            'credits_cost' => 0,
        ];
    }
}
