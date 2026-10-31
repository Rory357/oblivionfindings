<?php

namespace App\Models;

/** Immutable ORIGINAL attach identity and encrypted reference-only receipt, never footage or current source authority. */
final class IncidentRecordingAttachment extends IncidentEvidenceImmutableRecord
{
    protected $casts = ['incident_id'=>'integer','window_revision'=>'integer','attached_evidence_version'=>'integer','camera_id'=>'integer',
        'recorder_device_id'=>'integer','site_id'=>'integer','actor_user_id'=>'integer','audit_log_id'=>'integer',
        'requested_start_at'=>'immutable_datetime','requested_end_at'=>'immutable_datetime','recorded_at'=>'immutable_datetime',
        'encrypted_source_snapshot'=>'encrypted:array','encrypted_purpose'=>'encrypted'];
}
