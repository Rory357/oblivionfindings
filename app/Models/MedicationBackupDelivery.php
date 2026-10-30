<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicationBackupDelivery extends Model
{
    protected $guarded = [];

    protected $hidden = ['source_snapshot', 'password', 'artifact_path', 'claim_token', 'recipient_ids', 'recipient_sha256'];

    protected function casts(): array
    {
        return ['source_snapshot' => 'encrypted:array', 'password' => 'encrypted', 'recipient_ids' => 'array', 'claimed_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'purged_at' => 'immutable_datetime', 'version' => 'integer', 'attempt_count' => 'integer', 'schedule_version' => 'integer'];
    }
}
