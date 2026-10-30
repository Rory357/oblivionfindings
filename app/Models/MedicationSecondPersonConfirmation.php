<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One immutable nomination and one terminal answer per dose (PIN-2). */
class MedicationSecondPersonConfirmation extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const DISPUTED = 'disputed';

    public const EXPIRED = 'expired';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'eligibility_evidence' => 'array',
        ];
    }

    public function administration(): BelongsTo
    {
        return $this->belongsTo(ClientMedicationAdministration::class, 'administration_id');
    }

    public function nominatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'nominated_user_id');
    }
}
