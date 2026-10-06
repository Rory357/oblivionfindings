<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Current work state, backed by the review's append-only event history. */
class MedicationReviewItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['controlled_snapshot' => 'boolean', 'classification_pending' => 'boolean', 'watch_until' => 'date', 'decision_date' => 'date', 'decision_at' => 'immutable_datetime'];

    protected $hidden = ['decision_source_path'];

    public function review()
    {
        return $this->belongsTo(MedicationReview::class, 'review_id');
    }

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id')->withTrashed();
    }

    public function linkedVersion()
    {
        return $this->belongsTo(MedicationOrderVersion::class, 'linked_order_version_id');
    }

    public function isChange(): bool
    {
        return in_array($this->outcome, ['change', 'stop', 'start', 'swap'], true);
    }

    public function isRestricted(): bool
    {
        return $this->classification_pending || $this->isControlled();
    }

    public function isControlled(): bool
    {
        return $this->controlled_snapshot || (bool) $this->medication?->controlled_drug;
    }
}
