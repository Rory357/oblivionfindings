<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationBackupRecipient extends Model
{
    protected $guarded = [];

    protected $hidden = ['email_sha256'];

    protected function casts(): array
    {
        return ['approved_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
