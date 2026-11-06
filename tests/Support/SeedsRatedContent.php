<?php

use App\Enums\AssetRole;
use App\Enums\Rating;
use App\Media\MediaPaths;
use App\Models\Folder;
use App\Models\Profile;
use App\Models\Submission;
use App\Models\Tag;
use App\Models\User;
use App\Storage\ObjectStorage;
use Illuminate\Support\Facades\DB;

/**
 * Seeds user `artist` with one published public submission per rating, all tagged `leak`,
 * each in the gallery and in a custom folder, pinned on the profile, with stored assets.
 * The mature and adult submissions also carry a tag of their own (`leak_mature`, `leak_adult`),
 * so tag names and counts that exist only on hidden work can be checked too.
 *
 * @return array{artist: User, custom: Folder, subs: array<string, Submission>, paths: array<string, list<string>>}
 */
function seedRatedContent(): array
{
    $artist = User::factory()->create(['username' => 'artist', 'adult_confirmed_at' => now()]);
    $custom = Folder::create(['user_id' => $artist->id, 'name' => 'Custom', 'kind' => 'custom', 'position' => 2]);
    $tag = Tag::firstOrCreate(['slug' => 'leak']);

    $subs = [];
    $paths = [];
    foreach (['general' => Rating::General, 'mature' => Rating::Mature, 'adult' => Rating::Adult] as $name => $rating) {
        $s = Submission::factory()->for($artist)->create(['title' => 'leak '.$name, 'rating' => $rating]);
        $own = $rating === Rating::General ? [] : [Tag::firstOrCreate(['slug' => 'leak_'.$name])->id];
        $s->tags()->sync([$tag->id, ...$own]);
        DB::table('folder_submission')->insert(['folder_id' => $custom->id, 'submission_id' => $s->id, 'position' => 0]);
        foreach ([AssetRole::Original, AssetRole::ThumbSm] as $role) {
            $path = MediaPaths::prefixFor($s).$role->value.'-'.bin2hex(random_bytes(8)).'.bin';
            app(ObjectStorage::class)->simulateUpload($path, mediaFixture('image.jpg'));
            $s->assets()->create([
                'role' => $role, 'disk' => 's3', 'path' => $path, 'mime' => 'image/webp', 'size_bytes' => 123,
            ]);
            $paths[$name][] = $path;
        }
        $s->unsetRelation('tags')->searchable();
        $subs[$name] = $s;
    }
    Tag::recount(Tag::pluck('id')->all());

    Profile::query()->forceCreate([
        'user_id' => $artist->id,
        'pinned_submission_ids' => collect($subs)->pluck('public_id')->values()->all(),
    ]);

    return ['artist' => $artist, 'custom' => $custom, 'subs' => $subs, 'paths' => $paths];
}

/** A viewer by name; null means guest. */
function leakViewer(string $name): ?User
{
    return match ($name) {
        'guest' => null,
        'unconfirmed-forced-adult' => tap(User::factory()->create(['adult_confirmed_at' => null]), function (User $u) {
            DB::table('users')->where('id', $u->id)->update(['max_rating' => 'adult']);
            $u->refresh();
            // The case only means something if the row really says adult while unconfirmed.
            expect($u->max_rating)->toBe(Rating::Adult)
                ->and($u->adult_confirmed_at)->toBeNull()
                ->and($u->effectiveMaxRating())->toBe(Rating::General);
        }),
        'confirmed-general' => makeAdultUser('general'),
        'confirmed-mature' => makeAdultUser('mature'),
        'confirmed-adult' => makeAdultUser('adult'),
    };
}
