<?php

namespace App\Models;

use App\Enums\FolderKind;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Folder extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['kind' => FolderKind::class, 'position' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submissions(): BelongsToMany
    {
        return $this->belongsToMany(Submission::class)->withPivot('position');
    }

    public function isCustom(): bool
    {
        return $this->kind === FolderKind::Custom;
    }
}
