<?php

namespace App\Uploads;

use App\Enums\UploadKind;
use App\Enums\UploadStatus;
use App\Exceptions\DomainError;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

trait LocksUploadSession
{
    /**
     * Locks the caller's upload session row and checks it can back a new submission or profile image.
     * Throws (rolling back the transaction) when it is missing, foreign, the wrong kind or not usable.
     */
    private function lockSession(User $user, string $field, string $id, UploadKind $kind): UploadSession
    {
        $session = UploadSession::whereKey($id)->where('user_id', $user->id)->lockForUpdate()->first();
        if ($session === null) {
            throw ValidationException::withMessages([$field => ['The upload was not found.']]);
        }
        if ($session->kind !== $kind) {
            throw ValidationException::withMessages([$field => ["This upload must be of kind {$kind->value}."]]);
        }

        return match ($session->status) {
            UploadStatus::Uploaded => $session,
            UploadStatus::Pending => throw new DomainError('upload_not_complete', 'The upload has not been completed.'),
            UploadStatus::Consumed, UploadStatus::Expired => throw new DomainError('upload_already_used', 'This upload can no longer be used.'),
        };
    }
}
