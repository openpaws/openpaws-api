<?php

namespace App\Media\Processors;

use App\Enums\AssetRole;
use App\Enums\UploadKind;
use App\Media\AssetDraft;
use App\Media\Ffmpeg;
use App\Media\FileSignature;
use App\Media\MediaProcessingFailed;
use App\Models\Submission;

/** Audio: original, AAC/M4A transcode, waveform JSON, and cover + thumbnails when a cover was uploaded. */
class AudioProcessor implements MediaProcessor
{
    public const TRANSCODE_TIMEOUT = 600;

    public const WAVEFORM_PEAKS = 1000;

    private const WAVEFORM_RATE = 8000;

    public function __construct(private ImageProcessor $images) {}

    public function process(Submission $s, ?string $originalPath, ?string $coverPath): array
    {
        if ($originalPath === null) {
            throw new MediaProcessingFailed('An audio submission needs an upload.', permanent: true);
        }
        $mime = FileSignature::canonicalMime($originalPath, UploadKind::Audio)
            ?? throw MediaProcessingFailed::typeMismatch('audio file', FileSignature::detect($originalPath));
        $dir = dirname($originalPath);

        $probe = Ffmpeg::probe($originalPath);
        if (! $probe['has_audio']) {
            throw new MediaProcessingFailed('The audio file has no audio stream.', permanent: true);
        }

        $transcode = "{$dir}/transcode.m4a";
        Ffmpeg::run([
            '-max_error_rate', '0.1', '-i', $originalPath,
            '-map', '0:a:0', '-vn', '-c:a', 'aac', '-b:a', '256k',
            '-map_metadata', '-1', '-movflags', '+faststart', $transcode,
        ], self::TRANSCODE_TIMEOUT);
        $transcoded = Ffmpeg::probe($transcode);

        $drafts = [
            new AssetDraft(AssetRole::Original, $originalPath, $mime, durationMs: $probe['duration_ms']),
            new AssetDraft(AssetRole::Transcode, $transcode, 'audio/mp4', durationMs: $transcoded['duration_ms']),
            new AssetDraft(AssetRole::Waveform, $this->waveform($transcode, $dir), 'application/json'),
        ];

        return $coverPath === null ? $drafts : [...$drafts, ...$this->images->coverDrafts($coverPath)];
    }

    /**
     * Decodes to mono 8 kHz signed 16-bit PCM and keeps the max absolute sample per bucket
     * over WAVEFORM_PEAKS buckets, normalised to 0..1. Streams the PCM to bound memory.
     */
    private function waveform(string $audioPath, string $dir): string
    {
        $raw = "{$dir}/waveform.pcm";
        Ffmpeg::run([
            '-i', $audioPath, '-map', '0:a:0', '-ac', '1', '-ar', (string) self::WAVEFORM_RATE,
            '-f', 's16le', '-c:a', 'pcm_s16le', $raw,
        ], self::TRANSCODE_TIMEOUT);

        $samples = intdiv((int) filesize($raw), 2);
        $peaks = array_fill(0, self::WAVEFORM_PEAKS, 0);
        $handle = fopen($raw, 'rb');
        $index = 0;
        while ($samples > 0 && ($chunk = fread($handle, 65536)) !== false && $chunk !== '') {
            foreach (unpack('s*', $chunk) as $sample) {
                $bucket = min(self::WAVEFORM_PEAKS - 1, intdiv($index * self::WAVEFORM_PEAKS, $samples));
                $abs = abs($sample);
                if ($abs > $peaks[$bucket]) {
                    $peaks[$bucket] = $abs;
                }
                $index++;
            }
        }
        fclose($handle);
        unlink($raw);

        $max = max($peaks);
        $normalised = array_map(fn (int $p) => $max > 0 ? round($p / $max, 3) : 0, $peaks);

        $path = "{$dir}/waveform.json";
        file_put_contents($path, json_encode(['peaks' => $normalised], JSON_THROW_ON_ERROR));

        return $path;
    }
}
