<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MedicationOrderRevision extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'base_version' => 'integer', 'checked_at' => 'datetime', 'second_due_at' => 'datetime',
        'second_checked_at' => 'datetime', 'written_due_at' => 'datetime',
        'written_confirmation' => 'array', 'allergy_confirmation' => 'array',
    ];

    public function scopeCanonicalVersion(Builder $query): Builder
    {
        return $query->whereHas('version', fn (Builder $version) => $version
            ->whereColumn('medication_order_versions.client_id', 'medication_order_revisions.client_id')
            ->whereColumn('medication_order_versions.client_medication_id', 'medication_order_revisions.client_medication_id'));
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(MedicationOrderVersion::class, 'medication_order_version_id');
    }

    public function enterer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function witness(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_back_witness_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(MedicationOrderFile::class);
    }
}
