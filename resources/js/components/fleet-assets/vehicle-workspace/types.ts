export type VehicleIdentity = {
    id: number;
    name: string;
    asset_tag: string;
    registration_number: string | null;
    site_name: string | null;
};

export type ComplianceKind = 'registration' | 'wof' | 'cof' | 'ruc';
export type Applicability = 'unknown' | 'applicable' | 'not_applicable';
export type ComplianceOutcome =
    | 'needs_assessment'
    | 'recorded'
    | 'passed'
    | 'failed';

export const requirementNames: Record<ComplianceKind, string> = {
    registration: 'Registration',
    wof: 'WoF',
    cof: 'CoF',
    ruc: 'RUC',
};
export const applicabilityNames: Record<Applicability, string> = {
    unknown: 'Needs assessment',
    applicable: 'Applicable',
    not_applicable: 'Not applicable',
};
export const outcomeNames: Record<ComplianceOutcome, string> = {
    needs_assessment: 'Needs assessment',
    recorded: 'Recorded',
    passed: 'Passed',
    failed: 'Failed',
};

export type ComplianceVersion = {
    id: number;
    record_id: number;
    version: number;
    supersedes_version_id: number | null;
    applicability: Applicability;
    applicability_basis: string | null;
    outcome: ComplianceOutcome;
    effective_on: string | null;
    expires_on: string | null;
    ruc_start_km: number | null;
    ruc_end_km: number | null;
    evidence_reference: string | null;
    asset_document_id: number | null;
    document_trust: string | null;
    source_reference: string | null;
    observed_at: string | null;
    recorded_by_name: string | null;
    created_at: string;
    reason: string | null;
};

export type VehicleReadiness = {
    status: 'ready' | 'blocked';
    can_proceed: boolean;
    assessed_at: string;
    reasons: Array<{
        code: string;
        message: string;
        scope: string;
        kind: ComplianceKind | null;
        source_id: number | null;
        version_id: number | null;
        blocks_decision: boolean;
    }>;
    compliance_version_ids: Partial<Record<ComplianceKind, number>>;
    odometer_observation_id: number | null;
    odometer_km: number | null;
    tracker_estimate: {
        event_id: number;
        value_km: number;
        observed_at: string | null;
        received_at: string | null;
    } | null;
    restriction_ids: number[];
    check_run_ids: number[];
    input_fingerprint: string;
};

export type VehicleCompliance = {
    kind: ComplianceKind;
    current: ComplianceVersion | null;
    history_count: number;
};

export type OdometerObservation = {
    id: number;
    value_km: number;
    observed_at: string;
    created_at: string;
    source_kind:
        | 'dashboard_manual'
        | 'booking_checkout'
        | 'booking_return'
        | 'inspection'
        | 'legacy_unverified';
    source_reference: string | null;
    recorded_by_name: string | null;
    corrects_observation_id: number | null;
    correction_reason: string | null;
    is_current: boolean;
    is_corrected: boolean;
};

export type RecordPage<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
};
