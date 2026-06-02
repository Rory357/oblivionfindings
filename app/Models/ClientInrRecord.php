<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientInrRecord extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'inr_value',
        'target_range_low',
        'target_range_high',
        'dose_mg',
        'tested_on',
        'next_test_date',
        'recorded_by',
        'disabled_by',
        'disabled_at',
        'notes',
    ];

    protected $casts = [
        'inr_value' => 'decimal:1',
        'target_range_low' => 'decimal:1',
        'target_range_high' => 'decimal:1',
        'dose_mg' => 'decimal:2',
        'tested_on' => 'date',
        'next_test_date' => 'date',
        'disabled_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('disabled_at');
    }

    public function disable(int $userId): void
    {
        $this->forceFill([
            'disabled_by' => $userId,
            'disabled_at' => now(),
        ])->save();
    }
}
