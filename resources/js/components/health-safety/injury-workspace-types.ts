import type {
    AuditEntry,
    CapacityAssessment,
    InjuryAttachment,
    InjuryDetail,
    ModifiedDuty,
    RtwPlan,
} from '@/components/health-safety/injury-types';
import type {
    InjuryWorkspaceCommand,
    InjuryWorkspaceSection,
} from '@/lib/health-safety/injury-navigation';

export type InjuryIdentity = { id: number | null; name: string } | null;
export type InjurySourceAccess = 'absent' | 'readable' | 'restricted' | 'unavailable';
export type InjuryConnection = {
    access: InjurySourceAccess;
    id: number | null;
    reference: string | null;
    status: string | null;
    url: string | null;
    title?: string | null;
};
export type InjuryDocument = {
    access: InjurySourceAccess;
    id: number | null;
    title: string | null;
    url: string | null;
    availability: 'available' | 'unavailable' | 'unknown';
};
export type InjuryAttribution = {
    source_version: number;
    source_version_id: number | null;
    recorded_worker: InjuryIdentity;
    recorder: InjuryIdentity;
    attribution_state: 'recorded' | 'legacy_unknown';
};
export type InjuryModifiedDuty = ModifiedDuty & InjuryAttribution & {
    notes: string | null;
    approval_state: 'unknown';
    effectiveness_state: 'pending_workforce_interface';
};
export type InjuryRecoveryPlan = Omit<RtwPlan, 'modified_duties'> & InjuryAttribution & {
    workplace_modifications: string | null;
    worker_agreement_notes: string | null;
    worker_agreed: boolean;
    worker_agreed_at: string | null;
    medical_certification_state: 'unverified';
    approval_state: 'unknown';
    modified_duties: InjuryModifiedDuty[];
};
export type InjuryCapacity = Omit<CapacityAssessment, 'assessor'> & InjuryAttribution & {
    worker: InjuryIdentity;
    assessor: InjuryIdentity;
    assessor_identity_kind: 'unknown' | 'reported_external' | 'recorded_internal';
    medical_certification_state: 'unverified';
    hr_document: InjuryDocument;
};
export type InjuryEvidence = Omit<InjuryAttachment, 'url'> & {
    url: string | null;
    availability: 'available' | 'unavailable';
    source_version_id: number | null;
    source_revision: number | null;
    attribution_state: 'recorded' | 'legacy_unknown';
};
export type InjuryHistory = {
    source_revision: number;
    source_type: string;
    command: string;
    content_scope: 'factual' | 'medical';
    access: 'readable' | 'restricted';
    before_access: 'absent' | 'readable' | 'restricted';
    after_access: 'readable' | 'restricted';
    source_context: { worker_id: number; site_id: number } | null;
    recorded_at: string | null;
    recorder: InjuryIdentity;
    attribution_state: 'recorded' | 'legacy_unknown';
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
};

/** Current scalar projection; null private collections never mean an empty register. */
export type InjuryRecord = Omit<InjuryDetail,
    'rtw_plans' | 'capacity_assessments' | 'attachments' | 'audits' | 'can'> & {
    source_version: number;
    source_integrity: 'valid' | 'unknown' | 'repair_required';
    medical_access: 'readable' | 'restricted';
    recovery_integrity: 'valid' | 'repair_required' | 'unknown';
    history_integrity: 'valid' | 'repair_required' | 'unknown';
    evidence_integrity: 'valid' | 'repair_required' | 'unknown';
    coordination: {
        coordinator: InjuryIdentity;
        attribution_state: 'unassigned' | 'recorded' | 'unknown';
        next_review_date: string | null;
    };
    rtw_plans: InjuryRecoveryPlan[] | null;
    capacity_assessments: InjuryCapacity[] | null;
    attachments: InjuryEvidence[] | null;
    audits: (AuditEntry & { access: 'readable' | 'restricted'; attribution_state: 'recorded' | 'unknown' })[] | null;
    history: InjuryHistory[] | null;
    connections: {
        incident: InjuryConnection;
        health_safety: InjuryConnection;
        control_room: InjuryConnection;
        worksafe: {
            access: InjurySourceAccess;
            decision_state: 'recorded' | 'unknown' | null;
            notification_state: string | null;
            notified_at: string | null;
            acknowledged_at: string | null;
            reference: string | null;
            url: string | null;
            external_receipt_state: 'unconfirmed';
        };
    };
    scheduling_impact: {
        state: 'pending_interface';
        outcome: 'unknown';
        source_revision: number;
        evaluated_at: null;
        evaluated_interval: null;
        affected_duties: null;
        coordinator: null;
        receipt: null;
    };
    claim_delivery: {
        internally_recorded: boolean;
        external_receipt_state: 'unconfirmed';
        lodged_at: null;
        acknowledged_at: null;
    };
    can: {
        manage: boolean;
        create: boolean;
        update: boolean;
        transition: boolean;
        assign: boolean;
        review: boolean;
        recovery: boolean;
        upload_evidence: boolean;
        remove_evidence: boolean;
        read_evidence: boolean;
        record_medical_clearance: boolean;
        approve_restrictions: false;
        declare_fit: false;
    };
    commands: { expected_version: number; transitions: string[] };
};
export type InjuryCaptureOptions = {
    sites: { id: number; name: string }[];
    workers: { id: number; name: string; site_ids: number[] }[];
    worker_picker: InjuryPickerPage;
    incidents: {
        id: number;
        site_id: number;
        label: string;
        title: string;
        occurred_at: string | null;
    }[];
    injury_types: Record<string, string>;
    treatments: Record<string, string>;
    medical_access: 'readable' | 'restricted';
    can: { create: boolean; view_register: boolean; record_reported_details: boolean };
};
export type InjuryRecoveryOptions = {
    injury_id: number;
    expected_version: number;
    assessors: InjuryCaptureOptions['workers'];
    assessor_picker: InjuryPickerPage;
    documents: { access: 'readable' | 'restricted'; items: InjuryDocument[] | null };
};
export type InjuryPickerPage = {
    search: string;
    page: number;
    per_page: 100;
    has_more: boolean;
    next_page: number | null;
};
export type InjuryCommandOptions = {
    injury_id: number;
    canonical_site_id: number;
    site_id: number;
    sites: InjuryCaptureOptions['sites'];
    worker_id: number;
    expected_version: number;
    workers: InjuryCaptureOptions['workers'];
    coordinators: { id: number; name: string }[];
    incidents: InjuryCaptureOptions['incidents'];
    worker_picker: InjuryPickerPage;
    coordinator_picker: InjuryPickerPage;
};
export type InjuryCommand = InjuryWorkspaceCommand;
export type InjurySection = InjuryWorkspaceSection;
