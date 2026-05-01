<?php

namespace App\Models;

use App\Domain\Finance\Models\FinJournal;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FundingClaim extends Model
{
    use AuditableChanges;
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'service_agreement_id',
        'client_id',
        'claim_reference',
        'status',
        'period_start',
        'period_end',
        'total_amount',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'paid_at',
        'rejection_reason',
        'journal_id',
        'gl_posted_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'gl_posted_at' => 'datetime',
    ];

    public function serviceAgreement()
    {
        return $this->belongsTo(ServiceAgreement::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(FundingClaimItem::class);
    }

    public function journal()
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }
}
