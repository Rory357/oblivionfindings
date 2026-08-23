<?php

namespace App\Models;

use App\Domain\Finance\Models\FinJournal;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

class FundingClaim extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'service_agreement_id',
        'client_id',
        'site_id',
        'claim_reference',
        'created_by',
        'creation_request_uuid',
        'creation_request_hash',
        'provenance_digest',
        'integrity_state',
        'integrity_message',
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
        'gl_posting_status',
        'reversal_journal_id',
        'gl_reversed_at',
        'gl_reversal_reason',
        'gl_posting_attempts',
        'gl_posting_attempted_at',
        'gl_posting_error',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'gl_posted_at' => 'datetime',
        'gl_posting_attempted_at' => 'datetime',
        'gl_reversed_at' => 'datetime',
        'gl_posting_attempts' => 'integer',
    ];

    protected $hidden = [
        'creation_request_uuid',
        'creation_request_hash',
        'provenance_digest',
        'integrity_message',
        'gl_posting_error',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $claim): void {
            if (! $claim->getOriginal('creation_request_uuid')) {
                return;
            }

            $immutable = [
                'service_agreement_id',
                'client_id',
                'site_id',
                'claim_reference',
                'created_by',
                'creation_request_uuid',
                'creation_request_hash',
                'provenance_digest',
                'integrity_state',
                'integrity_message',
                'period_start',
                'period_end',
                'total_amount',
            ];

            if ($claim->isDirty($immutable)) {
                throw new LogicException('Governed funding-claim provenance is immutable; create a correction workflow instead.');
            }
        });
    }

    public function serviceAgreement()
    {
        return $this->belongsTo(ServiceAgreement::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function reversalJournal()
    {
        return $this->belongsTo(FinJournal::class, 'reversal_journal_id');
    }
}
