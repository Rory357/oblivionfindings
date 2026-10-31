import type { ChecklistRecord } from './checklist-types';
import type { DuplicateClosureHistory } from './duplicate-closure';
import type {
    FormQuestion,
    RecordQuestion,
    RecordedAnswer,
} from './form-types';
import type { FundingPathway, ReferralFundingData } from './funding-types';
export type Permissions = Record<string, boolean>;
export type Page<T> = {
    data: T[];
    total: number;
    current_page: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
};
export type ReferralSummary = {
    reference: string;
    /** ENQ- number of a general enquiry (Contact us, general questions); shown instead of the reference. */
    enquiry_reference?: string | null;
    /** A general enquiry, not (yet) a referral: its own queue, left out of referral counts. */
    is_general_enquiry?: boolean;
    version: number;
    name: string;
    preferred_name: string | null;
    site_id: number | null;
    site_name: string | null;
    owner_id: number | null;
    owner_name: string | null;
    team: string;
    stage: string;
    source: string;
    requested_service: string;
    priority: string;
    restricted: boolean;
    general_enquiry: boolean;
    next_action: string;
    next_due_at: string | null;
    created_at: string;
    first_human_contact_at: string | null;
    hold_reason: string | null;
    closure_reason: string | null;
    /** Configured labels from Settings › Referrals (fallback: readable key). */
    hold_reason_label?: string | null;
    closure_reason_label?: string | null;
    priority_label?: string | null;
};
export type WorkItem = {
    id: number;
    reference: string;
    version: number;
    kind: string;
    audience: string;
    status: string;
    owner_id: number;
    owner_name: string | null;
    funding_case_id: number | null;
    is_primary_funding_action: boolean;
    due_at: string;
    ends_at: string | null;
    details: { title?: string; description?: string; outcome_note?: string };
};
export type Activity = {
    reference: string;
    kind: string;
    actor_id: number | null;
    actor_name: string | null;
    audience: string;
    occurred_at: string;
    details: Record<string, unknown>;
};
export type ReferralDetail = ReferralSummary & {
    duplicate_closures?: DuplicateClosureHistory[];
    checklists?: ChecklistRecord[];
    form_fields?: RecordQuestion[];
    form_answers?: RecordedAnswer[];
    workflow_targets?: {
        id: string;
        label: string;
        required_evidence?: string[];
        restricted_requirements?: boolean;
        /** Ready for handover: the server re-checks the handover readiness gate on save. */
        requires_readiness?: boolean;
    }[];
    /** Hold and close reasons and priorities staff may choose now (Settings › Referrals). */
    choices?: {
        hold: { id: string; label: string }[];
        close: { id: string; label: string }[];
        priority?: { id: string; label: string }[];
    };
    /** Present once an approved retention policy has removed the referral's personal details. */
    retention?: { anonymised_at: string | null };
    primary_work_item_id: number | null;
    work_counts: { open: number; overdue: number };
    identity: {
        first_name?: string;
        last_name?: string;
        preferred_name?: string;
        date_of_birth?: string;
    };
    contact: {
        name?: string;
        email?: string;
        phone?: string;
        channel?: string;
        safe_time?: string;
        leave_message?: boolean;
        communication?: string;
    };
    intake: {
        relationship?: string;
        organisation?: string;
        region?: string;
        goals?: string;
        timeframe?: string;
        provenance?: string;
        preparation_reason?: string;
    };
    /** A Contact us message: topic, what they wrote and how they asked us to reply. */
    enquiry?: {
        topic?: string;
        message?: string;
        reply_by?: 'phone' | 'text' | 'email';
    } | null;
    contact_permission: boolean;
    people_contacts?: import('./people-contacts').PeopleContact[];
    can_complete_intake: boolean;
    assessment?: Record<string, unknown>;
    authority?: Record<string, unknown>;
    authority_scopes?: Record<string, boolean>;
    current_sharing?: {
        recipient: string;
        purpose: string;
        information: string;
        expires_at: string;
    }[];
    funding_intake?: {
        funding_situation?: string;
        funding_agency?: string;
        reported_pathway?: {
            id: string;
            label: string;
            personal_income: boolean;
            status: string;
        } | null;
    };
    funding?: ReferralFundingData;
    work: Page<WorkItem>;
    activity: Page<Activity>;
};
export type IntakeConfiguration = {
    pathways?: import('./intake-funding').IntakePathway[];
    fields?: FormQuestion[];
    settings_version_id: number;
    notice_version: string;
    notice: string;
    services: { id: string; label: string; enabled: boolean }[];
};
export type Options = {
    pathways: FundingPathway[];
    services: { id: string; label: string }[];
    sites: { id: number; name: string }[];
    stages: { id: string; label: string }[];
    intake: IntakeConfiguration | null;
};
/** One Overview readiness check, derived on the server from recorded reviews. */
export type ReferralReadinessItem = {
    key:
        | 'identity'
        | 'authority'
        | 'choice'
        | 'suitability'
        | 'capacity'
        | 'safe_start';
    label: string;
    state: 'confirmed' | 'to_review' | 'attention';
    detail: string;
};
/** Proposal cost for one currency/period against the part covered by verified funding. */
export type ReferralFundingTotal = {
    currency: string;
    period: string;
    proposed: string;
    covered: string;
    unknown_lines: number;
};
/** Profile header/Overview summary. Each part is null when its section is not permitted. */
export type ReferralProfileSummary = {
    readiness: ReferralReadinessItem[] | null;
    funding: {
        proposal_version: number | null;
        totals: ReferralFundingTotal[];
        cases: {
            total: number;
            open: number;
            verified: number;
            awaiting: number;
        };
    } | null;
    service_start: {
        status: string;
        agreed_date: string | null;
        commenced: boolean;
    } | null;
};
export type ReferralProfileDetail = ReferralDetail & {
    profile?: ReferralProfileSummary;
};
export const label = (value: string) =>
    value.replace(/_/g, ' ').replace(/^./, (letter) => letter.toUpperCase());
export const referralHref = (reference: string) =>
    `/operations/referrals/${reference}`;

/* ---- Workspace (list / funding / follow-ups) payloads — additive types ---- */
export type WorkspaceView =
    | 'referrals'
    | 'enquiries'
    | 'funding'
    | 'follow_ups';
/** The reference to show people: ENQ- for a general enquiry, the referral reference otherwise. */
export const shownReference = (record: {
    reference: string;
    enquiry_reference?: string | null;
}) => record.enquiry_reference || record.reference;
export type WorkspaceReferral = ReferralSummary & {
    /** An overdue next action or accessible open follow-up (the Reports rule). */
    is_overdue: boolean;
    is_due_today: boolean;
};
export type WorkspaceReferralLink = {
    reference: string;
    enquiry_reference?: string | null;
    name: string;
    preferred_name: string | null;
    site_name: string | null;
    stage: string;
    restricted: boolean;
};
export type WorkspaceFundingRow = {
    id: number;
    reference: string;
    pathway: string;
    pathway_label: string;
    status: string;
    personal_income: boolean;
    navigator_id: number | null;
    navigator_name: string | null;
    next_action: string | null;
    next_due_at: string | null;
    next_work_id: number | null;
    is_overdue: boolean;
    referral: WorkspaceReferralLink;
};
export type WorkspaceFollowUpRow = {
    key: string;
    type: 'work' | 'next';
    id: number;
    title: string;
    kind: string;
    audience: string;
    due_at: string | null;
    ends_at: string | null;
    owner_id: number | null;
    owner_name: string | null;
    is_overdue: boolean;
    href: string;
    referral: WorkspaceReferralLink;
};
export type WorkspacePipelineLane = {
    stage: string;
    label: string;
    total: number;
    overdue: number;
    data: WorkspaceReferral[];
};
export type WorkspaceSavedView = { key: string; label: string; count: number };
export type WorkspaceStats = {
    open: number;
    unassigned: number;
    overdue: number;
    ready_for_handover: number;
    awaiting_contact: number;
};
export type WorkspaceFilters = {
    view: WorkspaceView;
    queue: string;
    layout: 'cards' | 'table' | 'pipeline';
    stage?: string;
    site_id?: string | number;
    owner?: string;
    source?: string;
    hold_reason?: string;
    priority?: string;
    received_from?: string;
    received_to?: string;
    pathway?: string;
    funding_status?: string;
    audience?: string;
    q?: string;
    focus?: string;
    act?: 'assign' | 'contact' | 'transition' | 'priority';
    page?: number;
};
export type WorkspaceOptions = Options & {
    owners: { id: number; name: string }[];
    priorities: string[];
    sources: string[];
    hold_reasons: { id: string; label: string }[];
    funding_statuses: string[];
};
