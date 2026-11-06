<?php

use App\Enums\AssetRole;
use App\Enums\SubmissionStatus;
use App\Jobs\ProcessSubmission;
use App\Media\Ffmpeg;
use App\Models\User;

it('video: produces mp4 transcode ≤1080p and poster cover plus thumbnails', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'video');

    expect(runProcessing($s, stagedSession($u, 'video', 'video.mp4')))->toBeNull();

    $s->refresh();
    expect($s->status)->toBe(SubmissionStatus::Published);
    $id = $s->public_id;

    $t = $s->asset(AssetRole::Transcode);
    expect($t->path)->toMatch(mediaPathPattern($id, 'transcode', 'mp4'))->and($t->mime)->toBe('video/mp4')
        ->and($t->height)->toBeLessThanOrEqual(1080)->and($t->width)->toBeLessThanOrEqual(1920)
        ->and([$t->width, $t->height])->toBe([320, 240])
        ->and($t->duration_ms)->toBeGreaterThan(1500);
    $probe = Ffmpeg::probe(storedObject($t->path));
    expect($probe['has_video'])->toBeTrue()->and($probe['has_audio'])->toBeTrue();

    $cover = $s->asset(AssetRole::Cover);
    expect($cover->path)->toMatch(mediaPathPattern($id, 'cover', 'webp'))->and([$cover->width, $cover->height])->toBe([320, 240]);
    expect($s->asset(AssetRole::ThumbSm)->width)->toBe(200)
        ->and($s->asset(AssetRole::ThumbLg)->width)->toBe(320);
    expect($s->asset(AssetRole::Original)->path)->toMatch(mediaPathPattern($id, 'original', 'mp4'));
});

it('video: an uploaded cover replaces the poster frame', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'video');

    runProcessing($s, stagedSession($u, 'video', 'video.mp4'), stagedSession($u, 'cover', 'image.jpg'));

    expect([$s->asset(AssetRole::Cover)->width, $s->asset(AssetRole::Cover)->height])->toBe([640, 480]);
});

it('runs on the video queue for video and the media queue otherwise', function () {
    $u = User::factory()->create();

    expect((new ProcessSubmission(processingSubmission($u, 'video')))->queue)->toBe('video')
        ->and((new ProcessSubmission(processingSubmission($u, 'image')))->queue)->toBe('media')
        ->and((new ProcessSubmission(processingSubmission($u, 'audio')))->queue)->toBe('media');
    $job = new ProcessSubmission(processingSubmission($u, 'writing'));
    expect($job->queue)->toBe('media')->and($job->tries)->toBe(3)->and($job->backoff)->toBe([10, 60, 300])
        ->and($job->deleteWhenMissingModels)->toBeTrue();
});
