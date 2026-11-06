<?php

namespace App\Media\Processors;

use App\Enums\AssetRole;
use App\Enums\UploadKind;
use App\Media\AssetDraft;
use App\Media\Ffmpeg;
use App\Media\FileSignature;
use App\Media\MediaProcessingFailed;
use App\Models\Submission;

/** Video: original, H.264/AAC MP4 transcode up to 1080p, and a cover (uploaded, else a poster frame) + thumbnails. */
class VideoProcessor implements MediaProcessor
{
    public const TRANSCODE_TIMEOUT = 3600;

    private const SCALE = "scale='min(1920,iw)':'min(1080,ih)':force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2";

    public function __construct(private ImageProcessor $images) {}

    public function process(Submission $s, ?string $originalPath, ?string $coverPath): array
    {
        if ($originalPath === null) {
            throw new MediaProcessingFailed('A video submission needs an upload.', permanent: true);
        }
        $mime = FileSignature::canonicalMime($originalPath, UploadKind::Video)
            ?? throw MediaProcessingFailed::typeMismatch('video', FileSignature::detect($originalPath));
        $dir = dirname($originalPath);

        $probe = Ffmpeg::probe($originalPath);
        if (! $probe['has_video']) {
            throw new MediaProcessingFailed('The video has no video stream.', permanent: true);
        }

        $transcode = "{$dir}/transcode.mp4";
        Ffmpeg::run([
            '-max_error_rate', '0.1', '-i', $originalPath,
            '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', self::SCALE,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-c:a', 'aac', '-b:a', '160k',
            '-map_metadata', '-1', '-movflags', '+faststart', $transcode,
        ], self::TRANSCODE_TIMEOUT);
        $out = Ffmpeg::probe($transcode);

        $drafts = [
            new AssetDraft(AssetRole::Original, $originalPath, $mime, $probe['width'], $probe['height'], $probe['duration_ms']),
            new AssetDraft(AssetRole::Transcode, $transcode, 'video/mp4', $out['width'], $out['height'], $out['duration_ms']),
        ];

        return [...$drafts, ...$this->images->coverDrafts($coverPath ?? $this->poster($transcode, $out['duration_ms'], $dir))];
    }

    /** A frame at 10% of the running time (falling back to the first frame). */
    private function poster(string $video, int $durationMs, string $dir): string
    {
        $path = "{$dir}/poster.png";
        foreach ([intdiv($durationMs, 10), 0] as $offsetMs) {
            Ffmpeg::run([
                '-ss', sprintf('%.3f', $offsetMs / 1000), '-i', $video, '-map', '0:v:0', '-frames:v', '1', $path,
            ], Ffmpeg::PROBE_TIMEOUT);
            if (is_file($path) && filesize($path) > 0) {
                return $path;
            }
        }

        throw new MediaProcessingFailed('Could not extract a poster frame.');
    }
}
