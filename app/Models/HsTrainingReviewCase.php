<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;

/** Original pending review identity; completion remains Governance-owned. */
final class HsTrainingReviewCase extends Model
{
    use AuditableChanges;

    protected $fillable = ['hs_representative_id', 'site_id', 'initial_due_date', 'anniversary_date',
        'creation_origin', 'created_by', 'root_obligation_id'];

    protected $casts = ['initial_due_date' => 'date', 'anniversary_date' => 'date'];

    protected static function booted(): void
    {
        static::updating(function (self $case): void {
            foreach (['hs_representative_id', 'site_id', 'initial_due_date', 'anniversary_date', 'creation_origin', 'created_by'] as $field) {
                if ($case->isDirty($field)) { throw new \LogicException('Original annual review case facts are immutable.'); }
            }
            if ($case->getRawOriginal('root_obligation_id') !== null && $case->isDirty('root_obligation_id')) {
                throw new \LogicException('An activated review case cannot be reparented or detached.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Annual review case history cannot be deleted.'));
    }
}
