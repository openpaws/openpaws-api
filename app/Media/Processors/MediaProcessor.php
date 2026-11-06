<?php

namespace App\Media\Processors;

use App\Media\AssetDraft;
use App\Models\Submission;

/**
 * Turns a submission's downloaded upload(s) into asset drafts. Implementations write their
 * output next to the input file (the job's private temp dir) and throw MediaProcessingFailed.
 * $originalPath is null only for writing without a document.
 */
interface MediaProcessor
{
    /** @return list<AssetDraft> */
    public function process(Submission $s, ?string $originalPath, ?string $coverPath): array;
}
