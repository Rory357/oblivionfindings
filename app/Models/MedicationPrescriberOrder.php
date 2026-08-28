<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

class MedicationPrescriberOrder extends Model
{
    use AuditableChanges, HasFactory;

    public const READ_BACK_VERIFICATION_METHOD_PASSWORD = 'password';

    public const READ_BACK_VERIFICATION_METHODS = [
        self::READ_BACK_VERIFICATION_METHOD_PASSWORD,
    ];

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'controlled_drug_snapshot',
        'order_type',
        'status',
        'prescriber_name',
        'prescriber_registration',
        'prescriber_type',
        'medication_name',
        'dose',
        'route',
        'frequency',
        'instructions',
        'indication',
        'clinical_notes',
        'order_date',
        'effective_date',
        'expiry_date',
        'requires_countersign',
        'read_back_confirmed',
        'read_back_witnessed_by',
        'read_back_verified_at',
        'read_back_verification_method',
        'countersigned_at',
        'countersigned_by',
        'countersign_method',
        'received_by',
        'dispensed_by',
        'dispensed_at',
        'pharmacy_notes',
        'pharmacy_name',
        'batch_number',
        'batch_expiry',
    ];

    protected $casts = [
        'order_date' => 'date',
        'effective_date' => 'date',
        'expiry_date' => 'date',
        'batch_expiry' => 'date',
        'countersigned_at' => 'datetime',
        'dispensed_at' => 'datetime',
        'requires_countersign' => 'boolean',
        'read_back_confirmed' => 'boolean',
        'read_back_verified_at' => 'datetime',
        'controlled_drug_snapshot' => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function medication()
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function receivedByUser()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function countersignedByUser()
    {
        return $this->belongsTo(User::class, 'countersigned_by');
    }

    public function dispensedByUser()
    {
        return $this->belongsTo(User::class, 'dispensed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAwaitingCountersign($query)
    {
        return $query->where('requires_countersign', true)->whereNull('countersigned_at');
    }

    /**
     * Linked orders derive classification from their canonical medication;
     * unlinked orders are visible only when explicitly snapshotted ordinary.
     * A true snapshot remains restrictive even if linked data is inconsistent.
     */
    public function scopeVisibleToOrdinaryReader(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->where(function (Builder $visible) use ($table): void {
            $visible
                ->where(function (Builder $linked) use ($table): void {
                    $linked
                        ->whereNotNull($table.'.client_medication_id')
                        ->where(function (Builder $snapshot) use ($table): void {
                            $snapshot->whereNull($table.'.controlled_drug_snapshot')
                                ->orWhere($table.'.controlled_drug_snapshot', false);
                        })
                        ->whereExists(fn (QueryBuilder $medication) => $medication
                            ->selectRaw('1')
                            ->from('client_medications')
                            ->whereColumn('client_medications.id', $table.'.client_medication_id')
                            ->whereColumn('client_medications.client_id', $table.'.client_id')
                            ->where('client_medications.controlled_drug', false));
                })
                ->orWhere(function (Builder $unlinked) use ($table): void {
                    $unlinked->whereNull($table.'.client_medication_id')
                        ->where($table.'.controlled_drug_snapshot', false);
                });
        });
    }

    public function requiresControlledView(?ClientMedication $canonicalMedication = null): bool
    {
        if ($this->client_medication_id !== null) {
            if ($canonicalMedication === null
                || (int) $canonicalMedication->id !== (int) $this->client_medication_id
                || (int) $canonicalMedication->client_id !== (int) $this->client_id) {
                return true;
            }

            return (bool) $canonicalMedication->controlled_drug
                || $this->controlled_drug_snapshot === true;
        }

        return $this->controlled_drug_snapshot !== false;
    }

    public function isExpired(?CarbonInterface $at = null): bool
    {
        if ($this->expiry_date === null) {
            return false;
        }

        $workerTimezone = config('app.worker_timezone', 'Pacific/Auckland');
        $workerDate = ($at ?? now($workerTimezone))
            ->copy()
            ->timezone($workerTimezone)
            ->toDateString();

        // The expiry date itself remains a valid inclusive working day.
        return $this->expiry_date->toDateString() < $workerDate;
    }

    public function hasVerifiedReadBack(): bool
    {
        return (bool) $this->read_back_confirmed
            && $this->read_back_witnessed_by !== null
            && $this->read_back_verified_at !== null
            && in_array($this->read_back_verification_method, self::READ_BACK_VERIFICATION_METHODS, true);
    }

    public function needsCountersign(): bool
    {
        return $this->requires_countersign && ! $this->countersigned_at;
    }
}
