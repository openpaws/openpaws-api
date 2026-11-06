<?php

namespace App\Media;

use App\Enums\UploadKind;
use finfo;
use Throwable;
use ZipArchive;

/** Identifies files by their real bytes rather than by name or the browser's claimed type. */
final class FileSignature
{
    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const ODT = 'application/vnd.oasis.opendocument.text';

    /** Detected mimes that are aliases of an allowed canonical mime. */
    private const ALIASES = [
        'audio/x-wav' => 'audio/wav',
        'audio/x-flac' => 'audio/flac',
        'audio/x-m4a' => 'audio/mp4',
    ];

    /** File extension for every canonical mime the pipeline stores. */
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'audio/mpeg' => 'mp3',
        'audio/wav' => 'wav',
        'audio/flac' => 'flac',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'text/plain' => 'txt',
        self::DOCX => 'docx',
        self::ODT => 'odt',
        'application/pdf' => 'pdf',
        'application/json' => 'json',
    ];

    public static function detect(string $localPath): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($localPath);

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    public static function matches(string $localPath, UploadKind $kind): bool
    {
        return self::canonicalMime($localPath, $kind) !== null;
    }

    /**
     * The canonical mime of the file when its real type is accepted for $kind, otherwise null.
     * M4A can be reported as video/mp4; it is accepted as audio when it has audio and no video.
     * DOCX/ODT can be reported as plain zip; they are accepted only with their main XML part.
     */
    public static function canonicalMime(string $localPath, UploadKind $kind): ?string
    {
        $detected = self::detect($localPath);
        $mime = self::ALIASES[$detected] ?? $detected;

        if ($kind === UploadKind::Audio && $mime === 'video/mp4') {
            return self::isAudioOnly($localPath) ? 'audio/mp4' : null;
        }

        if ($kind === UploadKind::Document && in_array($mime, ['application/zip', self::DOCX, self::ODT], true)) {
            return self::officeDocumentMime($localPath);
        }

        return in_array($mime, $kind->allowedMimes(), true) ? $mime : null;
    }

    public static function extension(string $mime): string
    {
        return self::EXTENSIONS[$mime] ?? 'bin';
    }

    private static function isAudioOnly(string $path): bool
    {
        try {
            $probe = Ffmpeg::probe($path);
        } catch (MediaProcessingFailed) {
            return false;
        }

        return $probe['has_audio'] && ! $probe['has_video'];
    }

    private static function officeDocumentMime(string $path): ?string
    {
        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        try {
            return match (true) {
                $zip->locateName('word/document.xml') !== false => self::DOCX,
                $zip->locateName('content.xml') !== false => self::ODT,
                default => null,
            };
        } finally {
            $zip->close();
        }
    }
}
