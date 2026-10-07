import type { Prescription } from '../orders/_types';
export type Clinician = {
    id: number;
    user_id: number;
    name: string;
    email: string;
    provider_name: string;
    registration_authority: string;
    registration_number: string;
    expires_at: string;
    revoked_at: string | null;
    identity_verified_at: string;
};
export type Grant = {
    active: boolean;
    availability: string;
    id: number;
    clinician_id: number;
    clinician_name: string;
    client_id: number;
    site_id: number;
    purpose: string;
    can_propose: boolean;
    include_controlled: boolean;
    expires_at: string;
    revoked_at: string | null;
};
export type Proposal = {
    id: number;
    client_id: number;
    client_name: string;
    clinician_name: string;
    kind: 'start' | 'change' | 'stop';
    medication_id: number | null;
    expected_version: number | null;
    prescription: Prescription | null;
    reason: string;
    status: string;
    submitted_at: string;
    revision_id: number | null;
    decision_note: string | null;
    has_source_file: boolean;
};
export type Transfer = {
    id: number;
    direction: 'outgoing' | 'incoming';
    client_id: number;
    client_name: string;
    provider_name: string;
    recipient_name: string;
    purpose: string;
    disclosure_basis: string;
    status: string;
    allowed_actions: string[];
    version: number;
    identity_evidence: string;
    reviewed_at: string | null;
    received_at: string | null;
    reconciliation_id: number | null;
    snapshot_sha256: string;
    snapshot: {
        captured_at?: string;
        person?: { name: string; date_of_birth: string; nhi_number?: string };
        medications?: {
            id?: number;
            version?: number;
            state?: string;
            approval_status?: string;
            prescription: Prescription;
            last_dose?: {
                given_at: string | null;
                dose_given: string | null;
                source: string;
            } | null;
            next_due_at?: string | null;
        }[];
        allergies?: {
            allergen: string;
            reaction: string;
            severity: string;
            notes?: string;
        }[];
        limitations?: string[];
    };
};
export type ConnectedProps = {
    pagination?: Record<
        string,
        { current_page: number; last_page: number; total: number }
    >;
    clients: { id: number; name: string }[];
    selected_client: { id: number; name: string; date_of_birth: string } | null;
    clinicians: Clinician[];
    grants: Grant[];
    proposals: Proposal[];
    transfers: Transfer[];
    can: {
        manage_access: boolean;
        revoke_identity?: boolean;
        manage_orders: boolean;
        transfer: boolean;
        export: boolean;
    };
    witnesses: { id: number; name: string }[];
};
