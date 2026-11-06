<?php

namespace App\Uploads;

use App\Exceptions\DomainError;
use App\Models\UploadSession;
use App\Models\User;

class UploadQuota
{
    public static function assertCanUpload(User $user, int $bytes): void
    {
        $q = UploadSession::where('user_id', $user->id)->where('created_at', '>=', now()->subDay());

        if ($q->count() >= (int) config('openpaws.quota.daily_count')) {
            throw new DomainError('quota_exceeded', 'Daily upload count limit reached.');
        }
        if ((int) $q->sum('size') + $bytes > (int) config('openpaws.quota.daily_bytes')) {
            throw new DomainError('quota_exceeded', 'Daily upload size limit reached.');
        }
    }
}
