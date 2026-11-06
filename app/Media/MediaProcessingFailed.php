<?php

namespace App\Media;

use RuntimeException;
use Throwable;

/**
 * A media file could not be processed. Permanent failures (the file is not what it
 * claims to be, or is too large to decode) are not worth retrying.
 */
class MediaProcessingFailed extends RuntimeException
{
    public function __construct(string $message, public readonly bool $permanent = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function typeMismatch(string $what, string $detected): self
    {
        return new self("The {$what} is not an accepted file type (detected {$detected}).", permanent: true);
    }
}
