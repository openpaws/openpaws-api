<?php

use App\Enums\AssetRole;
use App\Enums\SubmissionStatus;
use App\Models\User;

function makeDocx(): string
{
    $path = sys_get_temp_dir().'/opaws-'.uniqid().'.docx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
    $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document/>');
    $zip->close();

    return $path;
}

it('writing: publishes with document asset when provided', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'writing');
    $docx = makeDocx();
    $session = makeUploadSession($u, 'document', 'consumed');
    $this->storage->simulateUpload($session->path, $docx);
    unlink($docx);

    expect(runProcessing($s, $session))->toBeNull();

    $s->refresh();
    expect($s->status)->toBe(SubmissionStatus::Published)->and($s->published_at)->not->toBeNull();
    $doc = $s->asset(AssetRole::Document);
    expect($doc->path)->toBe("media/{$s->public_id}/document.docx")
        ->and($doc->mime)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->and($this->storage->exists($doc->path))->toBeTrue();
    expect($s->asset(AssetRole::Original))->toBeNull()
        ->and($this->storage->exists($session->path))->toBeFalse();
});

it('writing: publishes text-only writing with no assets', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'writing');

    expect(runProcessing($s, null))->toBeNull();

    expect($s->fresh()->status)->toBe(SubmissionStatus::Published)->and($s->assets()->count())->toBe(0);
});

it('writing: a cover produces cover and thumbnails', function () {
    $u = User::factory()->create();
    $s = processingSubmission($u, 'writing');

    expect(runProcessing($s, null, stagedSession($u, 'cover', 'image.png')))->toBeNull();

    expect($s->asset(AssetRole::Cover)->width)->toBe(2000)
        ->and($s->asset(AssetRole::ThumbMd)->width)->toBe(400)
        ->and($s->asset(AssetRole::Document))->toBeNull();
});
