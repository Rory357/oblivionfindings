import type {
    ProcedureDetail as LegacyDetail,
    ProcedureFormData,
} from '../procedure-detail-dialog';
import type { ProcedureWizardOptions } from '../procedure-wizard-dialog';

export type ProcedurePickers = ProcedureWizardOptions;
export type Actor = { id: number; name: string };
export type ProcedureVersionLink = {
    id: number;
    version: number;
    href: string;
};
export type ProcedureRequest = {
    delivery: {
        status: 'pending' | 'failed' | 'delivered' | 'skipped';
        failure_code:
            | 'queue_unavailable'
            | 'delivery_failed'
            | 'assignment_inactive'
            | 'authority_changed'
            | 'preference_suppressed'
            | null;
        attempts: number;
        delivered_at: string | null;
        next_attempt_at: string | null;
    } | null;
    id: number;
    status: string;
    lock_version: number;
    note: string;
    requested_at: string | null;
    requested_by: Actor | null;
    assigned_to: Actor | null;
    requested_version: ProcedureVersionLink;
    source: {
        id: number;
        reference_number: string | null;
        status: string;
        href: string;
    };
    corrective_action: {
        id: number;
        reference_number: string | null;
        title: string;
        status: string;
        href: string;
    } | null;
    result_version: ProcedureVersionLink | null;
    outcome: 'no_change' | 'revised' | null;
    decided_at: string | null;
    decided_by: Actor | null;
    decision_note: string | null;
    can: {
        start: boolean;
        resolve: boolean;
        decline: boolean;
        cancel: boolean;
        reassign: boolean;
    };
};
export type ProcedureWorkspaceDetail = Omit<
    LegacyDetail,
    | 'can'
    | 'form'
    | 'versions'
    | 'reference_number'
    | 'applies'
    | 'acknowledged_count'
    | 'steps'
> & {
    steps: {
        step_number: number | null;
        description: string;
        safety_notes: string;
    }[];
    reference_number: string | null;
    acknowledged_count: number | null;
    documents_state: 'unrecorded' | 'available' | 'partially_available';
    applies: LegacyDetail['applies'] & {
        roles_scope: 'all' | 'specific' | 'unknown';
        sites_scope: 'all' | 'specific' | 'unknown';
    };
    lock_version: number;
    expected_version: number;
    is_current?: boolean;
    publication: {
        version_id: number;
        version: number;
        approved_by: Actor | null;
        approved_at: string | null;
        review_date: string | null;
        href: string;
        provenance?: string;
        captured_at?: string | null;
        legacy_approved_by?: Actor | null;
        legacy_approved_at?: string | null;
    } | null;
    working_revision: {
        version_id: number | null;
        version: number;
        status: string;
        href: string;
    } | null;
    viewed_revision: {
        version_id: number | null;
        version: number;
        kind: 'published' | 'working' | 'historical';
        legacy: boolean;
        provenance?: string;
        captured_at?: string | null;
    };
    acknowledgement: {
        eligible: boolean;
        version: number | null;
        version_id: number | null;
        provenance: string | null;
        acknowledged: boolean;
        acknowledged_at: string | null;
        current_version_count: number | null;
    };
    form: ProcedureFormData | null;
    versions: (LegacyDetail['versions'][number] & {
        href: string;
        provenance?: string;
    })[];
    history: {
        id: number | string;
        action: string;
        title: string;
        actor: Actor | null;
        occurred_at: string | null;
        note: string | null;
        legacy: boolean;
        snapshot?: Record<string, unknown> | null;
    }[];
    review_requests: ProcedureRequest[];
    can: {
        view: boolean;
        create: boolean;
        manage: boolean;
        edit: boolean;
        submit_review: boolean;
        approve: boolean;
        request_changes: boolean;
        record_review: boolean;
        archive: boolean;
        restore: boolean;
        add_evidence: boolean;
        remove_evidence: boolean;
        acknowledge: boolean;
        manage_review_requests: boolean;
    };
};
