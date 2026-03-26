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
        'checklist_items',
        'safety_concerns',
        'medication_errors_count',
        'pending_gp_followups',
        'clients_requiring_attention',
        'previous_shift_notes_read',
        'stock_issues_identified',
        'prescriber_changes_summary',
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
        'checklist_items' => 'array',
        'clients_requiring_attention' => 'array',
        'previous_shift_notes_read' => 'boolean',
        'medication_errors_count' => 'integer',
        'pending_gp_followups' => 'integer',
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
