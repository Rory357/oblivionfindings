/* Injuries & RTW — shared TS types. Mirror ReturnToWorkController::shapeRow()
 * (InjuryRow) and ::buildInjuryDetail() (InjuryDetail) 1:1 — keep in lockstep. */

export type StaffOption = { id: number; name: string };
export type SiteOption = { id: number; name: string };
export type IncidentOption = { id: number; label: string; title: string; occurred_at: string | null };

export type Person = { id: number; name: string } | null;

/** A row in the register table (controller shapeRow). */
export type InjuryRow = {
    id: number;
    reference: string;
    status: string;
    severity: string;
    injury_type: string;
    injury_type_label: string;
    body_part_affected: string | null;
    injury_date: string | null;
    lost_time_days: number;
    worksafe_notifiable: boolean;
    acc_claim_lodged: boolean;
    acc_claim_number: string | null;
    related_incident_id: number | null;
    related_incident_ref: string | null;
    worker: Person;
    site: { id: number; name: string } | null;
    rtw_count: number;
    capacity_count: number;
    attachment_count: number;
};

export type ModifiedDuty = {
    id: number;
    status: string;
    start_date: string | null;
    end_date: string | null;
    modified_duties_description: string;
    restrictions: string | null;
    accommodations: string | null;
    hours_per_day: number | null;
    user: Person;
};

export type RtwStage = {
    name: string;
    start_date?: string | null;
    end_date?: string | null;
    hours_per_week?: number | null;
    duties_description?: string | null;
};

export type RtwPlan = {
    id: number;
    status: string;
    plan_start_date: string | null;
    plan_end_date: string | null;
    goals: string[];
    stages: RtwStage[];
    medical_clearance_notes: string | null;
    medical_clearance_provider: string | null;
    medical_clearance_date: string | null;
    next_review_date: string | null;
    review_notes: string | null;
    worker: Person;
    manager: Person;
    modified_duties: ModifiedDuty[];
};

export type CapacityAssessment = {
    id: number;
    assessment_date: string | null;
    assessor_name: string | null;
    assessor_type: string | null;
    capacity_status: string;
    restrictions: string | null;
    recommendations: string | null;
    next_assessment_date: string | null;
    assessment_summary: string | null;
    assessor: Person;
};

export type InjuryAttachment = {
    id: number;
    original_name: string;
    url: string;
    mime: string | null;
    kind: string | null;
    notes: string | null;
    alt_text: string | null;
    size: number | null;
    is_image: boolean;
    uploaded_by: string | null;
    created_at: string | null;
};

export type AuditEntry = {
    id: number;
    action: string;
    fields: string[];
    actor: string | null;
    at: string | null;
};

/** The full detail payload (controller buildInjuryDetail). */
export type InjuryDetail = {
    id: number;
    reference: string;
    status: string;
    severity: string;
    injury_type: string;
    injury_type_label: string;
    body_part_affected: string | null;
    description: string | null;
    injury_date: string | null;
    immediate_treatment: string | null;
    medical_treatment_type: string | null;
    medical_treatment_label: string | null;
    worksafe_notifiable: boolean;
    acc_claim_lodged: boolean;
    acc_claim_number: string | null;
    lost_time_days: number;
    expected_return_date: string | null;
    actual_return_date: string | null;
    notes: string | null;
    worker: Person;
    site: { id: number; name: string } | null;
    related_incident: { id: number; label: string; title: string } | null;
    rtw_plans: RtwPlan[];
    capacity_assessments: CapacityAssessment[];
    attachments: InjuryAttachment[];
    audits: AuditEntry[];
    created_at: string | null;
    updated_at: string | null;
    can: { manage: boolean };
};

export type InjurySectionKey = 'overview' | 'rtw' | 'capacity' | 'evidence' | 'history';
export type InjuryActionKey = 'start_treatment' | 'begin_rtw' | 'mark_recovered' | 'close' | 'acc' | 'add_rtw' | 'add_capacity' | 'edit';
