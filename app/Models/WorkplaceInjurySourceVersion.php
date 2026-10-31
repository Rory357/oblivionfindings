<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable private source facts. A saved version is not fitness or restriction approval. */
final class WorkplaceInjurySourceVersion extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['aggregate_revision' => 'integer', 'worker_id' => 'integer', 'site_id' => 'integer',
        'source_schema_version' => 'integer', 'content' => 'array', 'factual_content' => 'array', 'recorder_snapshot' => 'array',
        'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Injury source versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Injury source history must be retained.'));
    }

    public function injury(): BelongsTo
    {
        return $this->belongsTo(WorkplaceInjury::class, 'workplace_injury_id');
    }
}
