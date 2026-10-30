<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Mutable pointer/counter only; the future writer locks the incident FIRST and journals each change. */
final class IncidentEvidenceAggregate extends Model
{
    protected $primaryKey = 'incident_id';
    public $incrementing = false;
    protected $guarded = [];
    protected $visible = ['incident_id', 'incident_evidence_version'];
    protected $dateFormat = 'Y-m-d H:i:s.u';
    protected $casts = ['incident_id'=>'integer','incident_evidence_version'=>'integer','active_window_revision'=>'integer',
        'created_by_user_id'=>'integer','updated_by_user_id'=>'integer','created_at'=>'immutable_datetime','updated_at'=>'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $row): void {
            if ($row->isDirty(['incident_id','created_by_user_id','created_at'])) {
                throw new LogicException('The incident evidence aggregate keeps its original incident and creator.');
            }
        });
        static::deleting(fn () => throw new LogicException('The incident evidence aggregate must be retained.'));
    }
}