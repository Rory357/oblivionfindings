<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicationHandover extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'site_id',
        'service_context_id',
        'shift_id',
        'outgoing_user_id',
        'incoming_user_id',
        'handover_at',
        'controlled_drug_counts',
        'controlled_drugs_verified',
        'outstanding_medications',
        'new_prescriptions',
        'ceased_medications',
        'incidents',
        'prn_given',
        'flagged_clients',
        'general_notes',
        'acknowledged',
        'acknowledged_at',
    ];

    protected $casts = [
        'handover_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'controlled_drug_counts' => 'array',
        'controlled_drugs_verified' => 'boolean',
        'outstanding_medications' => 'array',
        'new_prescriptions' => 'array',
        'ceased_medications' => 'array',
        'incidents' => 'array',
        'prn_given' => 'array',
        'flagged_clients' => 'array',
        'acknowledged' => 'boolean',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function outgoingUser()
    {
        return $this->belongsTo(User::class, 'outgoing_user_id');
    }

    public function incomingUser()
    {
        return $this->belongsTo(User::class, 'incoming_user_id');
    }

    public function hasDiscrepancies(): bool
    {
        if (!$this->controlled_drug_counts) return false;
        return collect($this->controlled_drug_counts)
            ->contains(fn ($count) => ($count['discrepancy'] ?? 0) !== 0);
    }
}
