<?php

use App\Enums\UploadKind;
use App\Media\FileSignature;

function zipWith(array $entries): string
{
    $path = sys_get_temp_dir().'/opaws-'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    return $path;
}

it('detects the real mime type from the file bytes', function () {
    expect(FileSignature::detect(mediaFixture('image.png')))->toBe('image/png')
        ->and(FileSignature::detect(mediaFixture('image.jpg')))->toBe('image/jpeg')
        ->and(FileSignature::detect(mediaFixture('fake.png')))->not->toBe('image/png');
});

it('matches files against upload kinds', function (string $fixture, UploadKind $kind, bool $expected) {
    expect(FileSignature::matches(mediaFixture($fixture), $kind))->toBe($expected);
})->with([
    ['image.png', UploadKind::Image, true],
    ['anim.gif', UploadKind::Image, true],
    ['image.jpg', UploadKind::Cover, true],
    ['fake.png', UploadKind::Image, false],
    ['audio.mp3', UploadKind::Image, false],
    ['audio.mp3', UploadKind::Audio, true],
    ['audio.m4a', UploadKind::Audio, true],
    ['audio-only.mp4', UploadKind::Audio, true],
    ['video.mp4', UploadKind::Audio, false],
    ['video.mp4', UploadKind::Video, true],
    ['image.png', UploadKind::Video, false],
    ['fake.png', UploadKind::Document, false],
]);

it('accepts zip documents only when they are DOCX or ODT', function () {
    $docx = zipWith(['word/document.xml' => '<w:document/>', '[Content_Types].xml' => '<Types/>']);
    $odt = zipWith(['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<office:document-content/>']);
    $zip = zipWith(['payload.exe' => 'MZ']);

    expect(FileSignature::matches($docx, UploadKind::Document))->toBeTrue()
        ->and(FileSignature::matches($odt, UploadKind::Document))->toBeTrue()
        ->and(FileSignature::matches($zip, UploadKind::Document))->toBeFalse()
        ->and(FileSignature::canonicalMime($docx, UploadKind::Document))->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->and(FileSignature::canonicalMime($odt, UploadKind::Document))->toBe('application/vnd.oasis.opendocument.text');

    array_map(unlink(...), [$docx, $odt, $zip]);
});

it('accepts plain text and pdf documents', function () {
    $txt = sys_get_temp_dir().'/opaws-'.uniqid().'.txt';
    file_put_contents($txt, "Once upon a time.\n");
    $pdf = sys_get_temp_dir().'/opaws-'.uniqid().'.pdf';
    file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

    expect(FileSignature::canonicalMime($txt, UploadKind::Document))->toBe('text/plain')
        ->and(FileSignature::canonicalMime($pdf, UploadKind::Document))->toBe('application/pdf');

    array_map(unlink(...), [$txt, $pdf]);
});

it('sniffs a very large file without loading it into memory', function () {
    $dir = sys_get_temp_dir().'/opaws-big-'.uniqid();
    mkdir($dir);
    $big = $dir.'/big.mp4';
    try {
        copy(mediaFixture('video.mp4'), $big);
        $out = fopen($big, 'ab');
        $chunk = str_repeat("\0", 1024 * 1024);
        for ($i = 0; $i < 100; $i++) {
            fwrite($out, $chunk);
        }
        fclose($out);

        $code = 'require "'.base_path('vendor/autoload.php').'"; echo App\Media\FileSignature::detect($argv[1]);';
        $process = new Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=16M', '-r', $code, '--', $big]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe('video/mp4');
    } finally {
        @unlink($big);
        @rmdir($dir);
    }
});

function mp3WithId3(int $tagBytes, ?int $declared = null): string
{
    $declared ??= $tagBytes;
    $size = '';
    for ($shift = 21; $shift >= 0; $shift -= 7) {
        $size .= chr(($declared >> $shift) & 0x7F);
    }
    $path = sys_get_temp_dir().'/opaws-id3-'.uniqid().'.mp3';
    $out = fopen($path, 'wb');
    fwrite($out, 'ID3'."\x03\x00\x00".$size);
    $chunk = str_repeat("\0", 1024 * 1024);
    for ($left = $tagBytes; $left > 0; $left -= strlen($chunk)) {
        fwrite($out, substr($chunk, 0, min($left, strlen($chunk))));
    }
    fwrite($out, file_get_contents(mediaFixture('audio.mp3')));
    fclose($out);

    return $path;
}

it('detects an mp3 behind a tag larger than the sniff window', function () {
    $path = mp3WithId3(2 * 1024 * 1024 + 123);
    try {
        expect(FileSignature::detect($path))->toBe('audio/mpeg');
    } finally {
        @unlink($path);
    }
});

it('detects an mp3 behind a large id3 tag with bounded memory', function () {
    $path = mp3WithId3(3 * 1024 * 1024);
    try {
        $code = 'require "'.base_path('vendor/autoload.php').'"; echo App\Media\FileSignature::detect($argv[1]);';
        $process = new Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=16M', '-r', $code, '--', $path]);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toBe('audio/mpeg');
    } finally {
        @unlink($path);
    }
});

it('falls back to the head result when the id3 length is bogus', function () {
    $path = mp3WithId3(1000, 200 * 1024 * 1024);
    try {
        expect(FileSignature::detect($path))->toBeString()->not->toBe('audio/mpeg');
    } finally {
        @unlink($path);
    }
});
