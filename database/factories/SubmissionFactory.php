<?php

namespace Database\Factories;

use App\Enums\Rating;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Enums\Visibility;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to a published, public, general-rated image.
 *
 * @extends Factory<Submission>
 */
class SubmissionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => SubmissionType::Image,
            'title' => fake()->sentence(3),
            'description' => null,
            'body' => null,
            'rating' => Rating::General,
            'status' => SubmissionStatus::Published,
            'visibility' => Visibility::Public,
            'published_at' => now(),
        ];
    }
}
