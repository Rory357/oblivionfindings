<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetChecklistRun extends Model
{
    // Preserve the microsecond ordering between an attestation and its new
    // retest. MySQL stores submitted_at at DATETIME(6) precision.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'template_id',
        'asset_id',
        'user_id',
        'responses',
        'passed',
        'notes',
        'completed_at',
        'work_order_id',
        'source_report_id',
        'rule_version_id',
        'rule_snapshot_json',
        'presented_template_json',
        'rule_sha256',
        'outcome',
        'check_kind',
        'corrects_run_id',
        'covered_restriction_ids_json',
        'observed_at',
        'submitted_at',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'responses' => 'array',
        'passed' => 'boolean',
        'completed_at' => 'datetime',
        'rule_snapshot_json' => 'array',
        'presented_template_json' => 'array',
        'covered_restriction_ids_json' => 'array',
        'observed_at' => 'datetime',
        'submitted_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(FleetChecklistTemplate::class, 'template_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
