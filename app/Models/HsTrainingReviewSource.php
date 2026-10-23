<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;

/** Additive lineage only; historical HSR-TRAINING obligations are never adopted. */
final class HsTrainingReviewSource extends Model
{
    use AuditableChanges;

    protected $fillable = ['case_id', 'compliance_obligation_id', 'hs_representative_id', 'site_id',
        'root_obligation_id', 'anniversary_date', 'period_start', 'period_end',
        'reviewed_on', 'reviewed_by', 'attestation_evidence_id'];

    protected $casts = ['anniversary_date' => 'date', 'period_start' => 'date',
        'period_end' => 'date', 'reviewed_on' => 'date'];

    protected static function booted(): void
    {
        static::updating(function (self $source): void {
            foreach (['case_id', 'compliance_obligation_id', 'hs_representative_id', 'site_id', 'root_obligation_id',
                'anniversary_date', 'period_start', 'period_end'] as $field) {
                if ($source->isDirty($field)) { throw new \LogicException('Annual review lineage is immutable.'); }
            }
            if ($source->getRawOriginal('attestation_evidence_id') !== null
                && $source->isDirty(['reviewed_on', 'reviewed_by', 'attestation_evidence_id'])) {
                throw new \LogicException('Recorded review attestation is immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Annual review history cannot be deleted.'));
    }
}
