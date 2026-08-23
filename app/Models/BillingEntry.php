<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BillingEntry extends Model
{
    use HasFactory, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'timesheet_id',
        'shift_id',
        'client_id',
        'site_id',
        'staff_id',
        'service_agreement_id',
        'line_item_id',
        'service_date',
        'hours',
        'rate',
        'amount',
        'rate_type',
        'site_name_snapshot',
        'location_snapshot',
        'service_context_name_snapshot',
        'client_name_snapshot',
        'staff_name_snapshot',
        'shift_type_snapshot',
        'pay_type_snapshot',
        'pay_rate_snapshot',
        'payroll_cost',
        'status',
        'billing_period_start',
        'billing_period_end',
        'notes',
    ];

    protected $casts = [
        'service_date' => 'date',
        'hours' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
        'pay_rate_snapshot' => 'decimal:2',
        'payroll_cost' => 'decimal:2',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            $monetisedStatuses = ['claimed', 'invoiced', 'paid'];
            if (
                ! in_array($entry->getOriginal('status'), $monetisedStatuses, true)
                && ! in_array($entry->status, $monetisedStatuses, true)
            ) {
                return;
            }

            if ($entry->isDirty([
                'timesheet_id',
                'shift_id',
                'client_id',
                'site_id',
                'staff_id',
                'service_agreement_id',
                'line_item_id',
                'service_date',
                'hours',
                'rate',
                'amount',
                'rate_type',
            ])) {
                throw new LogicException('Monetised delivered-support provenance is immutable.');
            }
        });

        static::deleting(function (self $entry): void {
            if (
                in_array($entry->status, ['claimed', 'invoiced', 'paid'], true)
                || $entry->fundingClaimItem()->exists()
            ) {
                throw new LogicException('Monetised delivered support cannot be deleted.');
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function serviceAgreement(): BelongsTo
    {
        return $this->belongsTo(ServiceAgreement::class);
    }

    public function lineItem(): BelongsTo
    {
        return $this->belongsTo(ServiceAgreementLineItem::class, 'line_item_id');
    }

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function fundingClaimItem()
    {
        return $this->hasOne(FundingClaimItem::class);
    }
}
