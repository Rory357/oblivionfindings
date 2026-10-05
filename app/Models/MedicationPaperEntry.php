<?php

namespace App\Models;

use App\Models\Concerns\ImmutableMedicationPaperEvidence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MedicationPaperEntry extends Model
{
    use ImmutableMedicationPaperEvidence;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'observations' => 'array', 'clinical_facts' => 'array', 'stock_evidence' => 'array', 'given_at' => 'immutable_datetime', 'scheduled_for' => 'immutable_datetime'];

    public function confirmations(): HasMany
    {
        return $this->hasMany(MedicationPaperConfirmation::class, 'paper_entry_id');
    }

    public function posting(): HasOne
    {
        return $this->hasOne(MedicationPaperPosting::class, 'paper_entry_id');
    }

    public function downtime(): BelongsTo
    {
        return $this->belongsTo(MedicationDowntime::class, 'downtime_id');
    }
}
