<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Delivery of the accepted observed public-origin cleanup, never active record removal. */
final class PpeEvidenceRelocationCleanup extends Model
{
    protected $table = 'ppe_evidence_relocation_cleanups';
    protected $guarded = ['id'];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['receipt_id' => 'integer', 'user_id' => 'integer', 'root_id' => 'integer', 'site_id' => 'integer',
        'parent_id' => 'integer', 'attachment_id' => 'integer', 'file_size' => 'integer', 'attempts' => 'integer', 'completed_at' => 'immutable_datetime'];
    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if (array_diff(array_keys($row->getDirty()), ['state', 'attempts', 'completed_at', 'updated_at'])) {
                throw new LogicException('PPE legacy origin cleanup identity is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('PPE legacy origin cleanup evidence must be retained.'));
    }
}