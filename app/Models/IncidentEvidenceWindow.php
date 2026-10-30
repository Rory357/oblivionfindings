<?php

namespace App\Models;

/** Immutable applied-policy/window/context/source-set fingerprint; never a policy approval or incident read grant. */
final class IncidentEvidenceWindow extends IncidentEvidenceImmutableRecord
{
    protected $casts = ['incident_id'=>'integer','window_revision'=>'integer','introduced_evidence_version'=>'integer',
        'actor_user_id'=>'integer','policy_change_id'=>'integer','rule_revision'=>'integer','effective_site_id'=>'integer','client_id'=>'integer',
        'audit_log_id'=>'integer','recorded_at'=>'immutable_datetime','start_at'=>'immutable_datetime','end_at'=>'immutable_datetime',
        'encrypted_override_reason'=>'encrypted','encrypted_context_anchor'=>'encrypted:array','encrypted_approved_sources'=>'encrypted:array'];
}
