<?php

namespace App\Media;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Thin wrapper around the ffmpeg/ffprobe binaries. Arguments are always passed as arrays (no shell). */
final class Ffmpeg
{
    public const PROBE_TIMEOUT = 30;

    /** Flags every ffmpeg run starts with. */
    private const BASE_FLAGS = ['-nostdin', '-hide_banner', '-loglevel', 'error', '-y'];

    /**
     * Streams that are cover art (attached_pic) do not count as video.
     *
     * @return array{duration_ms:int, has_video:bool, has_audio:bool, width:?int, height:?int}
     */
    public static function probe(string $path): array
    {
        $out = self::exec([
            'ffprobe', '-v', 'error', '-show_entries',
            'format=duration:stream=codec_type,width,height,duration:stream_disposition=attached_pic',
            '-of', 'json', $path,
        ], self::PROBE_TIMEOUT);

        $data = json_decode($out, true);
        if (! is_array($data) || ! isset($data['format'])) {
            throw new MediaProcessingFailed('ffprobe returned no format information.');
        }

        $video = null;
        $hasAudio = false;
        $streamDuration = 0.0;
        foreach ($data['streams'] ?? [] as $stream) {
            $type = $stream['codec_type'] ?? null;
            if ($type === 'audio') {
                $hasAudio = true;
            } elseif ($type === 'video' && ($stream['disposition']['attached_pic'] ?? 0) !== 1 && $video === null) {
                $video = $stream;
            } else {
                continue;
            }
            $streamDuration = max($streamDuration, (float) ($stream['duration'] ?? 0));
        }

        $duration = (float) ($data['format']['duration'] ?? 0) ?: $streamDuration;

        return [
            'duration_ms' => (int) round($duration * 1000),
            'has_video' => $video !== null,
            'has_audio' => $hasAudio,
            'width' => isset($video['width']) ? (int) $video['width'] : null,
            'height' => isset($video['height']) ? (int) $video['height'] : null,
        ];
    }

    /**
     * Runs ffmpeg with the standard flags followed by $args.
     *
     * @param  list<string>  $args
     */
    public static function run(array $args, int $timeout): void
    {
        self::exec(['ffmpeg', ...self::BASE_FLAGS, ...$args], $timeout);
    }

    /** @param list<string> $command */
    private static function exec(array $command, int $timeout): string
    {
        $process = new Process($command, timeout: $timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new MediaProcessingFailed("{$command[0]} timed out after {$timeout}s.", previous: $e);
        }

        if (! $process->isSuccessful()) {
            $err = trim(mb_substr($process->getErrorOutput(), -2000));
            throw new MediaProcessingFailed("{$command[0]} failed (exit {$process->getExitCode()}): {$err}");
        }

        return $process->getOutput();
    }
}
