<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Durable attempt identity exists before bytes; acceptance is separately bound. */
final class PpeEvidenceStaging extends Model
{
    protected $table = 'ppe_evidence_staging';
    protected $guarded = ['id'];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['intent_claim_id' => 'integer', 'user_id' => 'integer', 'root_id' => 'integer', 'source_id' => 'integer', 'site_id' => 'integer',
        'expected_version' => 'integer', 'file_size' => 'integer', 'receipt_id' => 'integer', 'attempts' => 'integer', 'intent' => 'array', 'legacy_origin' => 'array'];

    public function identity(): array
    {
        return array_diff_key($this->attributesToArray(), array_flip(['id', 'reservation_digest', 'state', 'receipt_id', 'attempts', 'created_at', 'updated_at']));
    }
    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if (array_diff(array_keys($row->getDirty()), ['state', 'receipt_id', 'attempts', 'updated_at'])) {
                throw new LogicException('PPE staging attempt identity is immutable.');
            }
            if ($row->getOriginal('receipt_id') !== null && $row->isDirty('receipt_id')) {
                throw new LogicException('PPE staging acceptance binding is immutable.');
            }
            if ($row->getOriginal('receipt_id') !== null && $row->state !== 'accepted') {
                throw new LogicException('Accepted PPE evidence cannot become rejected staging.');
            }
        });
        static::deleting(fn () => throw new LogicException('PPE staging evidence must be retained.'));
    }
}
