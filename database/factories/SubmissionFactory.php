<?php

namespace Database\Factories;

use App\Actions\PlaceSubmission;
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
    /** Like a real upload, a created submission lands in its owner's gallery. */
    public function configure(): static
    {
        return $this->afterCreating(function (Submission $s) {
            $s->loadMissing('user');
            (new PlaceSubmission)->handle($s->user, $s, 'gallery');
        });
    }

    public function definition(): array
    {
        return [
            // A mature/adult rating requires an 18+-confirmed uploader.
            'user_id' => fn (array $a) => User::factory()->state(
                in_array($a['rating'] instanceof Rating ? $a['rating'] : Rating::from($a['rating']), [Rating::Mature, Rating::Adult], true)
                    ? ['adult_confirmed_at' => now()] : []
            ),
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
