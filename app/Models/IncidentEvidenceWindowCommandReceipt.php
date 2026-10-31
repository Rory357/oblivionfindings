<?php

namespace App\Models;

/** Immutable exact NEW window-command identity; encrypted input is never a read or replay grant. */
final class IncidentEvidenceWindowCommandReceipt extends IncidentEvidenceImmutableRecord
{
    protected $casts = ['incident_id' => 'integer', 'actor_user_id' => 'integer', 'submitted_evidence_version' => 'integer',
        'submitted_policy_revision' => 'integer', 'confirmed_evidence_version' => 'integer', 'window_revision' => 'integer',
        'audit_log_id' => 'integer', 'encrypted_input' => 'encrypted:array', 'confirmed_result' => 'array', 'confirmed_at' => 'immutable_datetime'];
}
