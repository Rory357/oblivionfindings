<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorRenewalFollowup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'reviewed_at' => 'datetime'];
    }

    public function agreement(): BelongsTo { return $this->belongsTo(VendorAgreement::class, 'agreement_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_user_id'); }
}
