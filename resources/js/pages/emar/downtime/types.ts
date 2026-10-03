export type Site = { id: number; name: string };
export type Downtime = {
    id: number;
    site: string;
    description: string;
    started_at: string;
    ended_at: string;
    finished_at: string | null;
};
export type Snapshot = {
    person: string;
    medicine: string;
    dosage: string;
    route: string | null;
    controlled: boolean;
    second_person_required: boolean;
    observation_keys: string[];
};
export type Dose = {
    id: number;
    client_medication_id: number;
    scheduled_for: string;
    snapshot: Snapshot;
    entry_id: number | null;
    resolution: { reason: string; resolved_by: string } | null;
    resolution_choices: {
        kind: 'clinical' | 'paper';
        id: number;
        label: string;
        href: string | null;
        actual_at: string | null;
    }[];
};
export type PrnOrder = {
    id: number;
    person: string;
    medicine: string;
    dosage: string;
    second_person_required?: boolean;
    observation_keys?: string[];
};
export type Staff = { id: number; name: string };
export type Conflict = { kind: string; message: string };
export type Reconciliation = {
    state:
        | 'giver_to_confirm'
        | 'witness_to_confirm'
        | 'ready_to_reconcile'
        | 'entered_from_paper';
    conflicts: Conflict[];
    unavailable: string | null;
    can_reconcile: boolean;
    preview_token: string;
};
export type PaperEntry = {
    id: number;
    snapshot: Snapshot;
    outcome: string;
    given_at: string;
    entered_at: string;
    given_by: string;
    entered_by: string;
    witness: string | null;
    dose_on_paper: string | null;
    notes: string | null;
    can_confirm_giver: boolean;
    can_confirm_witness: boolean;
    reconciliation: Reconciliation;
};
export type CollectionPreview = {
    preview_token: string;
    conflicts: Conflict[];
    errors: string[];
    can_submit: boolean;
    giver_confirmation_needed: boolean;
    second_person_required: boolean;
    notice: string;
};
export type PackPreview = {
    site: Site;
    nz_date: string;
    purpose: string;
    controlled_notice: string | null;
    first_page: {
        name: string;
        scheduled: {
            id: number;
            ordered_time: string;
            medicine: string;
            dosage: string;
            second_person_required: boolean;
            readings: string[];
        }[];
    } | null;
};
