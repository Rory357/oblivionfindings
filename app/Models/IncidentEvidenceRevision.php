<?php

namespace App\Models;

/** Attributed append-only evidence-context, selection and attachment history; later commands remain separately governed. */
final class IncidentEvidenceRevision extends IncidentEvidenceImmutableRecord
{
    protected $casts = ['incident_id'=>'integer','incident_evidence_version'=>'integer','window_revision'=>'integer','actor_user_id'=>'integer',
        'audit_log_id'=>'integer','recorded_at'=>'immutable_datetime','encrypted_reason'=>'encrypted'];
}
