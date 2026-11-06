<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bio', 'links', 'species', 'pronouns', 'pinned_submission_ids'])]
class Profile extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['links' => 'array', 'pinned_submission_ids' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
