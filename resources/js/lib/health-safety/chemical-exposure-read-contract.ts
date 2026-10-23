/**
 * Saved private read subset from ChemicalExposureWorkspaceService's actual DTO.
 * Source-only frontend preparation; no route mounting or runtime acceptance.
 * Capture authority is separate and is not inferred from these saved-read flags.
 */
export type ExposureAttribution = 'recorded' | 'legacy_unknown' | 'repair_required';
export interface ExposureIdentity { id: number; name: string }
export type ExposureReportedFact = 'medical_attention_sought' | 'medical_treatment' | 'incident_reported';
export interface ExposureFactState {
    state: 'reported' | 'reported_unknown' | 'unanswered' | 'legacy_unconfirmed' | 'prior_context';
    recorded_value: boolean | string | null;
    reported_at: string | null;
}
export interface ExposureReportedResponse {
    reported_attention: 'yes' | 'no' | 'unknown';
    recorded_category: string | null;
    category_state: ExposureFactState['state'];
    response_floor: 'low' | 'medium' | 'high' | 'critical';
    basis: 'reported_category' | 'reported_attention' | 'operational_triage';
    care_review_needed: boolean;
    harm_assessment: 'unassessed';
}
export interface ExposureIncidentConnection {
    state: 'recorded' | 'absent' | 'unknown';
    id: number | null;
    href: string | null;
    reason: string | null;
}
export interface ExposureRecord {
    id: number;
    substance: ExposureIdentity;
    worker: ExposureIdentity;
    site: ExposureIdentity;
    exposed_at: string | null;
    exposure_type: string | null;
    exposure_duration: string | number | null;
    circumstances: string | null;
    symptoms: string | null;
    first_aid_given: string | null;
    medical_attention_sought: boolean | null;
    medical_treatment: string | null;
    medical_outcome: string | null;
    incident_reported: boolean | null;
    /** Absent on pre-contract projections; absence cannot certify an explicit answer. */
    fact_states?: Record<ExposureReportedFact, ExposureFactState>;
    response?: ExposureReportedResponse;
    incident_connection: ExposureIncidentConnection;
    recorded_by: ExposureIdentity | null;
    updated_by: ExposureIdentity | null;
    created_at: string | null;
    updated_at: string | null;
    attribution_state: ExposureAttribution;
    href: string | null;
}
export interface ExposureRecordEnvelope {
    exposure_access: 'readable';
    id: number;
    record: ExposureRecord | null;
    source_integrity: ExposureAttribution;
    command_context: { root_type: 'exposure'; root_id: number; expected_version: number; source_version_id: number | null } | null;
    can: { correct: boolean; review: boolean; assign_followup: boolean; upload_evidence: boolean };
}

export type ExposureIndexRecord = Omit<ExposureRecord, 'incident_connection'>;
export interface ExposurePage {
    current_page: number;
    last_page: number | null;
    total: number | null;
    per_page: number;
    has_more: boolean | null;
}
export interface ExposureCollection<T> {
    items: T[] | null;
    page: ExposurePage | null;
    source_integrity: 'admitted' | ExposureAttribution;
}
export interface ExposureRepair {
    id: number;
    attribution_state: 'repair_required';
    record: null;
    available: false;
    href: null;
    can_complete: false;
    can_reassign: false;
    can_remove: false;
}
export interface ExposureReview {
    id: number;
    reviewed_at: string | null;
    outcome: string;
    notes: string | null;
    recorded_by: ExposureIdentity | null;
    attribution_state: 'recorded' | 'legacy_unknown';
}
export interface ExposureFollowup {
    id: number;
    assigned_to: ExposureIdentity | null;
    instruction: string;
    due_at: string | null;
    completed_at: string | null;
    completion_notes: string | null;
    recorded_by: ExposureIdentity | null;
    completed_by: ExposureIdentity | null;
    /** Present-day completion does not certify an earlier legacy assignment. */
    assignment_attribution_state?: 'recorded' | 'legacy_unknown';
    completion_attribution_state?: 'recorded' | 'legacy_unknown' | null;
    attribution_state: 'recorded' | 'legacy_unknown';
    can_complete: boolean;
    can_reassign: boolean;
}
export interface ExposureEvidence {
    id: number;
    attribution_state: 'recorded' | 'legacy_unknown';
    available: boolean;
    original_name: string | null;
    description: string | null;
    href: string | null;
    can_remove: boolean;
    mime?: string;
    size?: number;
    uploaded_by?: ExposureIdentity | null;
    source_version_id?: number;
    source_revision?: number;
}
export type ExposureHistoryFacts = Partial<Record<'user_id' | 'site_id' | 'exposed_at' | 'exposure_type' | 'exposure_duration'
    | 'circumstances' | 'symptoms' | 'first_aid_given' | 'medical_attention_sought' | 'medical_treatment'
    | 'medical_outcome' | 'incident_reported' | 'related_incident_id', string | number | boolean | null>>;
export interface ExposureHistoryItem {
    id: number;
    revision: number;
    source_type: string;
    source_id: number;
    command: string;
    recorded_at: string;
    recorded_by: ExposureIdentity;
    reason: string | null;
    before: ExposureHistoryFacts | null;
    after: ExposureHistoryFacts;
    attribution_state: 'recorded';
}
export interface ExposureWorkspaceDetail extends ExposureRecordEnvelope {
    /** An admitted child on the returned page; a URL hint alone cannot supply this identity. */
    focused_followup_id?: number | null;
    followup_focus_state?: 'not_requested' | 'unavailable' | 'available';
    reviews: ExposureCollection<ExposureReview | ExposureRepair>;
    followups: ExposureCollection<ExposureFollowup | ExposureRepair>;
    attachments: ExposureCollection<ExposureEvidence | ExposureRepair>;
    history: ExposureCollection<ExposureHistoryItem>;
    delivery: { state: 'unconfirmed' | 'repair_required'; attempts: null };
}
export interface ExposureStaffChoices { items: ExposureIdentity[]; next_cursor: number | null }
export interface ExposureIncidentChoices {
    access: 'not_selected' | 'restricted' | 'readable';
    items: { id: number; reference: string; occurred_at: string | null; client: ExposureIdentity }[] | null;
    page: ExposurePage | null;
}
export interface ExposureCapture {
    substance: ExposureIdentity;
    sites: ExposureIdentity[];
    staff: ExposureStaffChoices;
    incidents: ExposureIncidentChoices;
    options: { exposure_types: string[]; medical_treatments: string[] };
    can: { create: boolean; read_saved: boolean };
}
export interface ExposureCommandChoices {
    sites: ExposureIdentity[];
    staff: ExposureStaffChoices;
    incidents: ExposureIncidentChoices;
    receivers: ExposureStaffChoices;
}
export interface ExposureIndexProps {
    exposure_access: 'readable' | 'restricted' | 'unavailable';
    sites: ExposureIdentity[] | null;
    records: (ExposureIndexRecord | { id: number; attribution_state: 'repair_required'; record: null; href: string | null })[] | null;
    counts: { exposures: number | null; awaiting_medical_outcome: number | null } | null;
    count_integrity?: { awaiting_medical_outcome: 'confirmed' | 'reported_answer_unknown' | 'repair_required' };
    source_integrity?: 'admitted' | 'repair_required' | 'restricted' | 'association_unconfirmed' | 'original_context_restricted';
    page: ExposurePage | null;
    filters: { site_id: number | null; worker_id: number | null; substance_id: number | null; from: string | null; to: string | null; page: number } | null;
    can: { capture: boolean };
    chemical_exposure_confirmation: null;
}
