<?php

namespace App\Models;

use App\Enums\UploadKind;
use App\Enums\UploadStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadSession extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => UploadKind::class,
            'status' => UploadStatus::class,
            'multipart' => 'boolean',
            'size' => 'integer',
            'part_count' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Callers are responsible for locking. */
    public function consume(): void
    {
        $this->status = UploadStatus::Consumed;
        $this->save();
    }
}
