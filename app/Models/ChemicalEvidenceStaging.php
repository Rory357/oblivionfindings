<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ChemicalEvidenceStaging extends Model
{
    protected $table = 'chemical_evidence_staging';
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['intent_id' => 'integer', 'parent_id' => 'integer', 'actor_id' => 'integer', 'size' => 'integer',
        'receipt_id' => 'integer', 'source_id' => 'integer', 'source_version_id' => 'integer', 'attempts' => 'integer', 'command_values' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if ($row->isDirty(['intent_id', 'parent_id', 'actor_id', 'idempotency_key', 'disk', 'path', 'sha256', 'size', 'mime', 'original_name', 'command_values'])) {
                throw new \LogicException('Chemical physical reservation identity is immutable.');
            }
            if ($row->getOriginal('state') === 'accepted') { throw new \LogicException('Accepted chemical physical binding is retained.'); }
        });
        static::deleting(fn () => throw new \LogicException('Chemical physical reservation history is retained.'));
    }
}
