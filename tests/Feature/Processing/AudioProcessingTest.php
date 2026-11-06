<?php

use App\Enums\AssetRole;
use App\Enums\SubmissionStatus;
use App\Media\Ffmpeg;
use App\Models\User;

it('audio: produces m4a transcode with duration and waveform json of 1000 peaks in 0..1', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'audio');

    expect(runProcessing($s, stagedSession($u, 'audio', 'audio.mp3')))->toBeNull();

    $s->refresh();
    expect($s->status)->toBe(SubmissionStatus::Published);
    $id = $s->public_id;

    $t = $s->asset(AssetRole::Transcode);
    expect($t->path)->toBe("media/{$id}/transcode.m4a")
        ->and($t->mime)->toBe('audio/mp4')
        ->and($t->duration_ms)->toBeGreaterThan(1800)->toBeLessThan(2300);
    $probe = Ffmpeg::probe(storedObject($t->path));
    expect($probe['has_audio'])->toBeTrue()->and($probe['has_video'])->toBeFalse();

    $w = $s->asset(AssetRole::Waveform);
    expect($w->path)->toBe("media/{$id}/waveform.json")->and($w->mime)->toBe('application/json');
    $peaks = json_decode(file_get_contents(storedObject($w->path)), true)['peaks'];
    expect($peaks)->toHaveCount(1000);
    foreach ($peaks as $p) {
        expect($p)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1);
    }
    expect(max($peaks))->toEqual(1);

    $o = $s->asset(AssetRole::Original);
    expect($o->path)->toBe("media/{$id}/original.mp3")->and($o->mime)->toBe('audio/mpeg')
        ->and($o->duration_ms)->toBeGreaterThan(1800);

    // No cover: no thumbnails (the UI shows a placeholder).
    expect($s->asset(AssetRole::Cover))->toBeNull()->and($s->asset(AssetRole::ThumbSm))->toBeNull();
});

it('audio with cover: thumbnails come from cover', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'audio');

    expect(runProcessing($s, stagedSession($u, 'audio', 'audio.mp3'), stagedSession($u, 'cover', 'gps.jpg')))->toBeNull();

    $cover = $s->asset(AssetRole::Cover);
    expect($cover->path)->toBe("media/{$s->public_id}/cover.webp")->and($cover->mime)->toBe('image/webp')
        ->and([$cover->width, $cover->height])->toBe([640, 480]);
    expect((new Imagick(storedObject($cover->path)))->getImageProperties('exif:GPS*'))->toBeEmpty();
    expect($s->asset(AssetRole::ThumbSm)->width)->toBe(200)
        ->and($s->asset(AssetRole::ThumbMd)->width)->toBe(400)
        ->and($s->asset(AssetRole::ThumbLg)->width)->toBe(640);
});

it('audio: accepts an audio-only mp4 container as m4a', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'audio');

    expect(runProcessing($s, stagedSession($u, 'audio', 'audio-only.mp4')))->toBeNull();

    $o = $s->asset(AssetRole::Original);
    expect($o->path)->toBe("media/{$s->public_id}/original.m4a")->and($o->mime)->toBe('audio/mp4');
});
