<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetVehicleComplianceVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'record_id', 'version', 'supersedes_version_id', 'applicability', 'applicability_basis',
        'source_type', 'source_id', 'source_reference', 'outcome', 'evidence_reference',
        'asset_document_id', 'document_trust', 'effective_on', 'expires_on', 'ruc_start_km',
        'ruc_end_km', 'observed_at', 'recorded_by_user_id', 'reason', 'request_key',
        'request_fingerprint', 'content_sha256', 'created_at',
    ];

    protected $casts = [
        'version' => 'integer', 'effective_on' => 'date', 'expires_on' => 'date',
        'ruc_start_km' => 'decimal:1', 'ruc_end_km' => 'decimal:1', 'observed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Vehicle compliance versions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Vehicle compliance versions are immutable.'));
    }

    public function record(): BelongsTo { return $this->belongsTo(FleetVehicleComplianceRecord::class, 'record_id'); }
    public function supersedes(): BelongsTo { return $this->belongsTo(self::class, 'supersedes_version_id'); }
    public function document(): BelongsTo { return $this->belongsTo(AssetDocument::class, 'asset_document_id'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by_user_id'); }
}
