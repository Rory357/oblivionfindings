export type FirstAidPerson = { id: number; name: string };
export type FirstAidSection =
    | 'overview'
    | 'injury'
    | 'incident'
    | 'followups'
    | 'evidence'
    | 'history'
    | 'connections';
export type FirstAidCommand =
    | 'edit'
    | 'archive'
    | 'link-incident'
    | 'add-followup'
    | 'edit-followup'
    | 'complete-followup'
    | 'add-evidence'
    | 'remove-evidence';
export type FirstAidLink = {
    id: number;
    href: string;
    reference?: string | null;
    reference_number?: string | null;
    title?: string | null;
    name?: string;
    status?: string | null;
    owner?: FirstAidPerson | null;
};
export type FirstAidAttachment = {
    id: number;
    original_name: string;
    kind: string | null;
    notes: string | null;
    alt_text: string | null;
    mime: string | null;
    size: number | null;
    is_image: boolean;
    uploaded_by: FirstAidPerson | null;
    created_at: string | null;
    availability: 'available' | 'missing' | 'unknown';
    download_href: string | null;
    can: { remove: boolean };
};
export type FirstAidFollowup = {
    id: number;
    notes: string;
    assigned_to: FirstAidPerson | null;
    due_at: string | null;
    completed_at: string | null;
    completed_by: FirstAidPerson | null;
    completion_notes: string | null;
    completion_outcome: {
        kind: string | null;
        result: string | null;
        occurred_at: string | null;
        note?: string | null;
    } | null;
    completion_evidence: FirstAidAttachment[];
    created_by: FirstAidPerson | null;
    created_at: string | null;
    can: { complete: boolean; edit: boolean };
};
export type FirstAidRecord = {
    id: number;
    reference: string | null;
    lock_version: number;
    expected_version: number;
    status: 'recorded' | 'archived';
    archived_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    href: string;
    treated_person_type: string;
    treated_person_name: string;
    treated_person_id: number | null;
    client_id: number | null;
    treated_person: FirstAidPerson | null;
    client: FirstAidPerson | null;
    site_id: number | null;
    site: FirstAidPerson | null;
    treatment_date: string | null;
    injury_illness_type: string;
    injury_illness_description: string | null;
    body_part: string | null;
    treatment_given: string | null;
    treatment_outcome: string;
    ambulance_called: boolean;
    first_aider_id: number | null;
    first_aider: FirstAidPerson | null;
    first_aider_notes: string | null;
    incident_reported: boolean;
    related_incident: FirstAidLink | null;
    incident_link_state?: 'available' | 'unavailable' | 'not_recorded';
    followups: FirstAidFollowup[];
    attachments: FirstAidAttachment[];
    history: {
        id: string;
        action: string;
        title: string;
        actor: FirstAidPerson | null;
        occurred_at: string | null;
        note: string | null;
        snapshot: Record<string, unknown>;
        legacy: boolean;
    }[];
    source_delivery: {
        status: string;
        attempts: number;
        failure_code: string | null;
        next_attempt_at: string | null;
        delivered_at: string | null;
    } | null;
    connections: {
        site: FirstAidLink | null;
        person: FirstAidLink | null;
        equipment: FirstAidLink[];
        incident: FirstAidLink | null;
        safety_reviews: FirstAidLink[];
        corrective_actions: FirstAidLink[];
        control_room: FirstAidLink[];
    };
    can: Record<
        | 'view'
        | 'create'
        | 'edit'
        | 'archive'
        | 'link_incident'
        | 'create_incident'
        | 'add_followup'
        | 'add_evidence'
        | 'export',
        boolean
    >;
};
export type FirstAidPickers = {
    sites: FirstAidPerson[];
    staff: FirstAidPerson[];
    clients: (FirstAidPerson & { site_id: number })[];
    first_aiders: (FirstAidPerson & {
        site_ids: number[];
        eligibility_state: string;
    })[];
    followup_assignees: (FirstAidPerson & { site_ids: number[] })[];
    incidents: (FirstAidLink & {
        reference: string | null;
        title: string | null;
    })[];
    person_types: { value: string; label: string }[];
    injury_types: { value: string; label: string }[];
    outcomes: { value: string; label: string }[];
    attachment_kinds: { value: string; label: string }[];
};
export type FirstAidConfirmation = {
    id: number;
    lock_version: number;
    idempotency_key: string;
    command: string;
    removed: boolean;
};
