<?php

use App\Enums\AssetRole;
use App\Enums\SubmissionStatus;
use App\Media\MediaProcessingFailed;
use App\Models\User;
use Symfony\Component\Process\Process;

it('image: produces thumb_sm/md/lg and preview as webp with longest edges 200/400/800/≤2000', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'image');
    $session = stagedSession($u, 'image', 'image.png'); // 2400x1800

    expect(runProcessing($s, $session))->toBeNull();

    $s->refresh();
    expect($s->status)->toBe(SubmissionStatus::Published);
    $id = $s->public_id;

    foreach ([[AssetRole::ThumbSm, 200], [AssetRole::ThumbMd, 400], [AssetRole::ThumbLg, 800], [AssetRole::Preview, 2000]] as [$role, $edge]) {
        $a = $s->asset($role);
        expect($a)->not->toBeNull()
            ->and($a->mime)->toBe('image/webp')
            ->and($a->disk)->toBe('s3')
            ->and($a->path)->toBe("media/{$id}/{$role->value}.webp")
            ->and(max($a->width, $a->height))->toBe($edge)
            ->and($a->checksum_sha256)->toBe(hash_file('sha256', storedObject($a->path)))
            ->and($a->size_bytes)->toBe(filesize(storedObject($a->path)));
        $im = new Imagick(storedObject($a->path));
        expect($im->getImageFormat())->toBe('WEBP')
            ->and(max($im->getImageWidth(), $im->getImageHeight()))->toBe($edge);
    }

    $orig = $s->asset(AssetRole::Original);
    expect($orig->path)->toBe("media/{$id}/original.png")
        ->and($orig->mime)->toBe('image/png')
        ->and([$orig->width, $orig->height])->toBe([2400, 1800])
        ->and($this->storage->exists($orig->path))->toBeTrue();
});

it('image: does not upscale thumbnails of small images', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'image');

    runProcessing($s, stagedSession($u, 'image', 'image.jpg')); // 640x480

    expect($s->asset(AssetRole::ThumbLg)->width)->toBe(640)
        ->and($s->asset(AssetRole::ThumbSm)->width)->toBe(200)
        ->and($s->asset(AssetRole::Preview)->width)->toBe(640);
});

it('image: stored original and preview have no EXIF GPS', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'image');
    expect((new Imagick(mediaFixture('gps.jpg')))->getImageProperties('exif:GPS*'))->not->toBeEmpty();

    expect(runProcessing($s, stagedSession($u, 'image', 'gps.jpg')))->toBeNull();

    $orig = $s->asset(AssetRole::Original);
    expect($orig->path)->toBe("media/{$s->public_id}/original.jpg")->and($orig->mime)->toBe('image/jpeg');
    foreach ([AssetRole::Original, AssetRole::Preview, AssetRole::ThumbLg] as $role) {
        $im = new Imagick(storedObject($s->asset($role)->path));
        expect($im->getImageProperties('exif:GPS*'))->toBeEmpty();
    }
});

it('image: animated gif preview keeps multiple frames', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'image');

    expect(runProcessing($s, stagedSession($u, 'image', 'anim.gif')))->toBeNull();

    $preview = new Imagick(storedObject($s->asset(AssetRole::Preview)->path));
    expect($preview->getImageFormat())->toBe('WEBP')->and($preview->getNumberImages())->toBeGreaterThan(1);
    $thumb = new Imagick(storedObject($s->asset(AssetRole::ThumbSm)->path));
    expect($thumb->getNumberImages())->toBe(1);
    $orig = new Imagick(storedObject($s->asset(AssetRole::Original)->path));
    expect($orig->getImageFormat())->toBe('GIF')->and($orig->getNumberImages())->toBeGreaterThan(1);
});

it('image: rejects images larger than 16384px on a side as a processing failure', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'image');
    $big = sys_get_temp_dir().'/opaws-wide-'.uniqid().'.png';
    // Built with the CLI: this PHP process may already carry the processor's Imagick limits.
    (new Process(['magick', '-size', '16385x1', 'xc:white', $big]))->mustRun();
    $session = makeUploadSession($u, 'image', 'consumed');
    $this->storage->simulateUpload($session->path, $big);
    unlink($big);

    expect(runProcessing($s, $session))->toBeInstanceOf(MediaProcessingFailed::class);
    expect($s->fresh()->status)->toBe(SubmissionStatus::Failed)->and($s->assets()->count())->toBe(0);
});
