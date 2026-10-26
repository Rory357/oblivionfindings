<?php

namespace App\Models;

use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Retained acceptance anchor; no generic activity/audit emission or public serialization adapter. */
final class HsRiskAssessmentAcceptance extends Model
{
    protected $table = 'hs_risk_assessment_acceptances';
    public $timestamps = false;
    protected $guarded = [];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['assessment_id' => 'integer', 'audit_log_id' => 'integer', 'actor_id' => 'integer',
        'sequence' => 'integer', 'schema_version' => 'integer', 'prior_id' => 'integer', 'before_site_id' => 'integer',
        'after_site_id' => 'integer', 'before_client_id' => 'integer', 'after_client_id' => 'integer', 'has_exposure' => 'boolean',
        'actor_snapshot' => 'array', 'before_binding' => 'array', 'after_binding' => 'array', 'before_business' => 'array', 'business' => 'array',
        'recorded_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Risk acceptance is immutable.'));
        static::deleting(fn () => throw new LogicException('Risk acceptance is retained.'));
    }

    /** Internal writer handle: read it only after original source admission. */
    public function persisted(): self
    {
        $saved = self::query()->whereKey($this->id)->lockForUpdate()->first();
        if (! $saved || ChemicalCommandValues::digest($saved->attributesToArray()) !== ChemicalCommandValues::digest($this->attributesToArray())) {
            throw new LogicException('Risk acceptance was not retained exactly.');
        }
        return $saved;
    }
}
