import type { ApprovalSnapshot } from '@/components/health-safety/settings/approval-policy';

export type SourcePerson = {
    id: number | null;
    name: string | null;
    visibility?: 'current' | 'identity_retained' | 'recorded_external_decision';
    recorded_name?: string;
    role?: string | null;
    organisation?: string | null;
    decision_reference?: string | null;
    decision_date?: string | null;
};
export type SourceLink = { id: number; href: string };
export type SourceFact = {
    id: number;
    source_revision: number;
    supersedes_id: number | null;
    occurred_at: string | null;
    created_at: string | null;
    evidence_ids: number[];
    recorder: SourcePerson | null;
    actual_actor: SourcePerson | null;
    facts: Record<
        string,
        | string
        | number
        | boolean
        | null
        | string[]
        | SourcePerson
        | Record<string, unknown>
    >;
};
export type RestraintFollowup = {
    id: number;
    notes: string;
    assignment_revision: number;
    due_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    cancellation_reason: string | null;
    completion_outcome: { outcome: string; notes: string | null } | null;
    completion_evidence_ids: number[];
    creator: SourcePerson | null;
    assignee: SourcePerson | null;
    completed_by: SourcePerson | null;
    cancelled_by: SourcePerson | null;
    history: SourceFact[];
    can: {
        complete: boolean;
        reassign: boolean;
        cancel: boolean;
        correct: boolean;
    };
};
export type RestraintAttachment = {
    id: number;
    original_name: string;
    mime: string | null;
    mime_type: string | null;
    size: number | null;
    category: string | null;
    notes: string | null;
    created_at: string | null;
    removed_at: string | null;
    removal_reason: string | null;
    uploader: SourcePerson | null;
    removed_by: SourcePerson | null;
    availability: 'available' | 'unavailable' | 'removed';
    download_url: string | null;
};
export type RestraintEventRecord = {
    id: number;
    reference_number: string | null;
    client_id: number;
    site_id: number | null;
    client: { id: number; name: string };
    site: { id: number; name: string } | null;
    lock_version: number;
    source_revision: number;
    started_at: string;
    ended_at: string | null;
    duration_minutes: number | null;
    restraint_type: string;
    severity: string;
    trigger_description: string | null;
    de_escalation_attempted: string | null;
    restraint_description: string | null;
    person_response: string | null;
    post_incident_support: string | null;
    injury_occurred: boolean;
    injury_details: string | null;
    within_support_plan: boolean;
    deviation_reason: string | null;
    reviewed_at: string | null;
    review_notes: string | null;
    lessons_learned: string | null;
    review_due_at: string | null;
    review_assignment_revision: number;
    reviewed_source_revision: number | null;
    needs_review: boolean;
    source_provenance: 'recorded' | 'legacy_uncertainty';
    plan_authorization: { state: string; explanation: string | null };
    staff_involved: (
        | SourcePerson
        | { kind: 'historical_text'; text: string }
    )[];
    authorised_by: SourcePerson | null;
    reviewed_by: SourcePerson | null;
    review_owner_id: SourcePerson | null;
    created_by: SourcePerson | null;
    updated_by: SourcePerson | null;
    reviews: SourceFact[];
    observations: SourceFact[];
    debriefs: SourceFact[];
    followups: RestraintFollowup[];
    attachments: RestraintAttachment[];
    deliveries: {
        purpose: string;
        status: string;
        failure_code: string | null;
        attempts: number;
        next_attempt_at: string | null;
        delivered_at: string | null;
        created_at: string | null;
    }[];
};
export type RestraintEventDetail = {
    event: RestraintEventRecord;
    connections: Record<
        | 'client'
        | 'incident'
        | 'plan'
        | 'plan_version'
        | 'plan_approval'
        | 'stay'
        | 'health_safety'
        | 'control_room',
        SourceLink | null
    >;
    can: { review: boolean; manage: boolean; capture: boolean };
};
export type RestraintPickers = {
    clients: { id: number; name: string; site_id: number | null }[];
    sites: { id: number; name: string }[];
    staff: { id: number; name: string }[];
    reviewers: { id: number; name: string }[];
    followupOwners: { id: number; name: string }[];
    incidents: {
        id: number;
        client_id: number;
        reference: string;
        label: string;
    }[];
    plansForPicker: {
        id: number;
        client_id: number;
        title: string;
        reference: string;
        status: string;
        restrictive_practice_type: string | null;
        version_id?: number | null;
    }[];
};
export const EMPTY_RESTRAINT_PICKERS: RestraintPickers = {
    clients: [],
    sites: [],
    staff: [],
    reviewers: [],
    followupOwners: [],
    incidents: [],
    plansForPicker: [],
};
export type PlanContent = {
    title: string;
    triggers: string | null;
    de_escalation_strategies: string | null;
    approved_interventions: string | null;
    prohibited_interventions: string | null;
    restrictive_practice_type: string | null;
    developed_by: number | null;
    developed_at: string | null;
    review_date: string | null;
    notes: string | null;
};
export type PlanVersion = {
    id: number;
    sequence: number;
    kind: 'candidate' | 'published';
    content_digest: string;
    content: PlanContent;
    review_date: string | null;
    recorded_at: string | null;
    integrity_valid: boolean;
    candidate_version_id?: number;
    approval_id?: number;
    published_at?: string | null;
    effective_from?: string | null;
    effective_to?: string | null;
    applied_policy?: ApprovalSnapshot | null;
};
export type PlanApproval = {
    id: number;
    plan_version_id: number;
    decision: string;
    origin: string;
    approved_at: string | null;
    effective_from: string | null;
    effective_to: string | null;
    applied_policy: ApprovalSnapshot | null;
    approver: SourcePerson | null;
    recorder: SourcePerson | null;
    basis: string | null;
    evidence: {
        id?: number;
        type?: string;
        href?: string | null;
        availability?: string;
        label?: string | null;
    }[];
};
export type PlanReview = {
    id: number;
    reviewed_at: string;
    outcome: string;
    next_review_date: string | null;
    resulting_status: string | null;
    requested_resulting_status: string | null;
    approval_disposition: string | null;
    notes: string | null;
    plan_version_id: number | null;
    approval_id: number | null;
    reviewer: SourcePerson | null;
};
export type PlanCan = Record<
    | 'view'
    | 'review'
    | 'approve'
    | 'edit'
    | 'submit_review'
    | 'activate'
    | 'archive'
    | 'create',
    boolean
>;
export type PlanDetail = {
    id: number;
    reference_number: string | null;
    title: string;
    status: string;
    lock_version: number;
    client: { id: number; name: string };
    site: { id: number; name: string } | null;
    stay: { id: number } | null;
    source_provenance: string;
    content: PlanContent;
    candidate: PlanVersion | null;
    published_version: PlanVersion | null;
    selected_version: PlanVersion | null;
    versions: PlanVersion[];
    reviews: PlanReview[];
    approvals: PlanApproval[];
    current_policy: ApprovalSnapshot | null;
    can: PlanCan;
    commands: {
        can: PlanCan;
        endpoints: Record<
            | 'store'
            | 'update'
            | 'submit_review'
            | 'activate'
            | 'archive'
            | 'review'
            | 'decision',
            string | null
        >;
    };
    readable_hrefs: Record<'show' | 'review' | 'approval', string | null>;
    connections: {
        client: SourceLink | null;
        site: SourceLink | null;
        stay: SourceLink | null;
        events: SourceLink[];
    };
    history: {
        id: number;
        command: string;
        version: number;
        status: string | null;
        recorded_at: string;
        actor: SourcePerson | null;
    }[];
    approval_options?: {
        internal_approvers: { id: number; name: string }[];
        evidence: {
            label: string;
            reference: {
                type: 'client_document' | 'event_attachment';
                id: number;
            };
            availability: string;
            selectable: boolean;
            href: string | null;
        }[];
        credentials: {
            id: number;
            approver_id: number;
            label: string;
            issuer: string | null;
            reference: string | null;
            issued_at: string | null;
            expires_at: string | null;
            professional_eligibility_verified: false;
        }[];
    } | null;
    developer?: SourcePerson | null;
    selected_approval?: PlanApproval | null;
    legacy_approval_unknown?: boolean;
    approval_explanation?: string;
};
