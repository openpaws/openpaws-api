<?php

namespace App\Media\Processors;

use App\Enums\AssetRole;
use App\Enums\UploadKind;
use App\Media\AssetDraft;
use App\Media\FileSignature;
use App\Media\MediaProcessingFailed;
use App\Models\Submission;

/**
 * Writing: the body needs no file processing (it is sanitised when rendered). An optional
 * document is stored as `document` (no separate `original`); a cover yields cover + thumbnails.
 */
class WritingProcessor implements MediaProcessor
{
    public function __construct(private ImageProcessor $images) {}

    public function process(Submission $s, ?string $originalPath, ?string $coverPath): array
    {
        $drafts = [];
        if ($originalPath !== null) {
            $mime = FileSignature::canonicalMime($originalPath, UploadKind::Document)
                ?? throw MediaProcessingFailed::typeMismatch('document', FileSignature::detect($originalPath));
            $drafts[] = new AssetDraft(AssetRole::Document, $originalPath, $mime);
        }

        return $coverPath === null ? $drafts : [...$drafts, ...$this->images->coverDrafts($coverPath)];
    }
}
