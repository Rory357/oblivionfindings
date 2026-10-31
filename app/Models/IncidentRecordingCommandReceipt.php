<?php

namespace App\Models;

/** Immutable actor/incident/payload confirmation. Reauthorise parent/action/source before recovery or replay. */
final class IncidentRecordingCommandReceipt extends IncidentEvidenceImmutableRecord
{
    protected $casts = ['incident_id'=>'integer','actor_user_id'=>'integer','submitted_evidence_version'=>'integer','window_revision'=>'integer',
        'confirmed_evidence_version'=>'integer','audit_log_id'=>'integer','confirmed_at'=>'immutable_datetime','confirmed_result'=>'array'];
}
