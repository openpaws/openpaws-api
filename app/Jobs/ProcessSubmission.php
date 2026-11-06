<?php

namespace App\Jobs;

use App\Models\Submission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns a submission's consumed upload(s) into media assets and publishes it.
 * Stub: implemented in Task 7.
 */
class ProcessSubmission implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Submission $submission,
        public ?string $uploadSessionId = null,
        public ?string $coverUploadSessionId = null,
    ) {}

    public function handle(): void
    {
        //
    }
}
