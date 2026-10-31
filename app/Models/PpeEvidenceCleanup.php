<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Mutable delivery state; its removal/byte/source identity is retained. */
final class PpeEvidenceCleanup extends Model
{
    protected $guarded = ['id'];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['receipt_id' => 'integer', 'parent_id' => 'integer', 'attachment_id' => 'integer',
        'user_id' => 'integer', 'file_size' => 'integer', 'attempts' => 'integer', 'completed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if (array_diff(array_keys($row->getDirty()), ['state', 'attempts', 'completed_at', 'updated_at'])) {
                throw new LogicException('PPE cleanup source identity is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('PPE cleanup evidence must be retained.'));
    }
}
