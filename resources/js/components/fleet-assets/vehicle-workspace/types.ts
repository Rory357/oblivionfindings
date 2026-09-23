/** Server DTOs for the vehicle workspace (VehicleWorkspacePresenter). */

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
    unknown: 'Unknown',
    applicable: 'Applicable',
    not_applicable: 'Not applicable',
};
export const outcomeNames: Record<ComplianceOutcome, string> = {
    needs_assessment: 'Needs assessment',
    recorded: 'Recorded',
    passed: 'Passed',
    failed: 'Failed',
};

export type Person = { id: number; name: string };

export type DocumentFile = {
    id: number;
    name: string;
    size_bytes: number | null;
    mime: string | null;
    state: string | null;
    revision: number;
    current: boolean;
    archived_at: string | null;
    archive_reason: string | null;
    uploaded_at: string | null;
    uploaded_by: string | null;
    url: string | null;
};

export type ComplianceVersion = {
    id: number;
    version: number;
    applicability: Applicability;
    applicability_basis: string | null;
    outcome: ComplianceOutcome;
    evidence_reference: string | null;
    source_reference: string | null;
    effective_on: string | null;
    expires_on: string | null;
    ruc_start_km: number | null;
    ruc_end_km: number | null;
    legacy: boolean;
    reason: string | null;
    recorded_by: string | null;
    created_at: string | null;
    files: DocumentFile[];
};

export type ComplianceRecord = {
    kind: ComplianceKind;
    label: string;
    record_id: number | null;
    current: ComplianceVersion | null;
    history: ComplianceVersion[];
    history_count: number;
};

export type ReadinessReason = {
    code: string;
    message: string;
    scope: string;
    kind: ComplianceKind | null;
    source_id: number | null;
    version_id: number | null;
    blocks_decision: boolean;
};

export type VehicleReadiness = {
    status: 'ready' | 'blocked';
    can_proceed: boolean;
    assessed_at: string;
    reasons: ReadinessReason[];
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

export type OdometerReading = {
    id: number;
    value_km: number;
    observed_at: string | null;
    source_kind:
        | 'dashboard_manual'
        | 'booking_checkout'
        | 'booking_return'
        | 'inspection'
        | 'legacy_unverified';
    source_reference: string | null;
    recorded_by: string | null;
    corrects_observation_id: number | null;
    correction_reason: string | null;
    notes: string | null;
    is_current: boolean;
    is_corrected: boolean;
    files: DocumentFile[];
};

export type ServiceSchedule = {
    id: number;
    name: string;
    interval_months: number | null;
    interval_days: number | null;
    interval_km: number | null;
    next_due_at: string | null;
    next_due_km: number | null;
    last_completed_at: string | null;
    last_completed_km: number | null;
    owner: Person | null;
    reminder_days_before: number | null;
    reminder_km_before: number | null;
    is_active: boolean;
    lock_version: number;
    overdue: boolean;
    km_remaining: number | null;
    /** Current supporting files kept with the schedule. */
    files: Array<{ id: number; name: string; url: string | null }>;
};

export type ServiceHistoryRow = {
    key: string;
    kind: 'service' | 'work';
    id: number;
    title: string;
    date: string | null;
    odometer_km: number | null;
    status: string;
    provider: string | null;
    evidence_reference: string | null;
    notes: string | null;
    reference: string | null;
    work_order_id: number | null;
    recorded_by: string | null;
    /** Current evidence files kept with the record. */
    files: number;
    /** Completed work whose restriction still waits for an independent release. */
    awaiting_release: boolean;
};

export type ReminderSourceType =
    | 'vehicle'
    | 'document_set'
    | 'service_schedule'
    | 'compliance_record'
    | 'work_order';

export type VehicleReminder = {
    id: number;
    title: string;
    action_text: string | null;
    source: {
        type: ReminderSourceType | null;
        id: number | null;
        label: string;
    };
    due_at: string | null;
    repeat_months: number;
    owner: Person | null;
    backup: Person | null;
    state: 'scheduled' | 'acknowledged' | 'completed' | 'paused';
    lock_version: number;
    events: Array<{
        id: number;
        action: string;
        note: string | null;
        actor: string | null;
        occurred_at: string | null;
    }>;
};

/** The tracker distance feed; null when the viewer can't see tracker data. */
export type MileageFeedState = {
    available: boolean;
    fresh: boolean;
    verified_km: number | null;
    verified_at: string | null;
    estimate_km: number | null;
    raw_tracker_km: number | null;
    tracker_observed_at: string | null;
    difference_km: number | null;
    reconciled: boolean;
    usable: boolean;
    planning_km: number | null;
    /** The reporting tracker's model, when the sample names its device. */
    device_label: string | null;
    automatic: boolean;
    tolerance_km: number;
    reconciled_at: string | null;
    lock_version: number;
    history: Array<{
        id: number;
        action: 'reconciled' | 'paused';
        actor: string | null;
        dashboard_km: number | null;
        tracker_km: number | null;
        automatic: boolean;
        note: string | null;
        at: string | null;
    }>;
    can: { reconcile: boolean; pause: boolean };
};

/** A reminder for the current due point of a service schedule or compliance record. */
export type ObligationReminder = {
    key: string;
    source_type: 'service_schedule' | 'compliance_record';
    source_id: number;
    name: string;
    due_on: string | null;
    due_km: number | null;
    /** How far ahead the owner is told, e.g. "14 days" or "1,000 km". */
    lead: string;
    due_now: boolean;
    owner: Person | null;
    reminder_id: number | null;
    state: 'scheduled' | 'sent' | 'failed' | 'acknowledged';
    status_label: string;
    last_attempt_at: string | null;
    last_error: string | null;
    acknowledged_at: string | null;
    acknowledged_by: string | null;
    events: Array<{
        id: number;
        action: 'sent' | 'failed' | 'acknowledged';
        channel: string | null;
        recipient: string | null;
        actor: string | null;
        note: string | null;
        at: string | null;
    }>;
    can: { acknowledge: boolean; retry: boolean };
};

export type DocumentSet = {
    id: number;
    category: string;
    reference: string | null;
    document_date: string | null;
    expires_on: string | null;
    current_revision: number;
    lock_version: number;
    legacy: boolean;
    source: { type: string; id: number } | null;
    renewal: {
        id: number;
        due_at: string | null;
        state: string;
        owner: string | null;
        owner_user_id: number | null;
        backup_user_id: number | null;
        lock_version: number;
    } | null;
    files: DocumentFile[];
};

/** Files kept with another record of the vehicle, labelled with that record. */
export type LinkedDocumentSet = Omit<DocumentSet, 'source'> & {
    source: { type: string; id: number; label: string };
};

export type VehicleProfile = {
    id: number;
    name: string;
    asset_tag: string | null;
    registration_number: string | null;
    status: string;
    site: { id: number; name: string } | null;
    home_site: { id: number; name: string } | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    fuel_type: string | null;
    seating_capacity: number | null;
    body_type: string | null;
    use_purpose: string | null;
    ownership_arrangement: string | null;
    responsible: Person | null;
    primary_driver: Person | null;
    insurance_provider: string | null;
    insurance_policy_reference: string | null;
    insurance_expires_at: string | null;
    warranty_reference: string | null;
    warranty_expires_at: string | null;
    purchase_date: string | null;
    inspection_due_at: string | null;
    profile_version: number;
    photo_url: string | null;
    accessibility: {
        has_wheelchair_ramp: boolean;
        has_hoist: boolean;
        has_child_seat_anchors: boolean;
        has_medical_storage: boolean;
        accessibility_notes: string | null;
    };
    history: Array<{
        id: number;
        label: string;
        reason: string | null;
        actor: string | null;
        occurred_at: string;
    }>;
};

export type CatalogueKind =
    | 'service_type'
    | 'document_type'
    | 'reminder_title'
    | 'vehicle_body_type'
    | 'vehicle_manufacturer'
    | 'vehicle_model'
    | 'vehicle_use_purpose'
    | 'ownership_arrangement'
    | 'interval_months'
    | 'interval_km'
    | 'reminder_days_before'
    | 'reminder_km_before'
    | 'repeat_months'
    | 'seats';

export type WorkspaceCan = {
    manage: boolean;
    view_documents: boolean;
    manage_documents: boolean;
    manage_schedules: boolean;
    add_catalogue: boolean;
    view_finance: boolean;
    inspect: boolean;
    report_maintenance: boolean;
    view_maintenance: boolean;
    schedule_service: boolean;
    book: boolean;
    view_vehicle_technology: boolean;
};

export type VehicleWorkspace = {
    vehicle: VehicleProfile;
    readiness: VehicleReadiness;
    compliance: ComplianceRecord[];
    odometer: {
        current_id: number | null;
        current_km: number | null;
        tracker_estimate: VehicleReadiness['tracker_estimate'];
        total: number;
        readings: OdometerReading[];
    };
    schedules: ServiceSchedule[];
    service_history: ServiceHistoryRow[];
    reminders: VehicleReminder[];
    obligation_reminders: ObligationReminder[];
    mileage_feed: MileageFeedState | null;
    documents: DocumentSet[];
    linked_documents: LinkedDocumentSet[];
    work: {
        can_view: boolean;
        open_count: number | null;
        open: Array<{
            id: number;
            reference: string | null;
            title: string | null;
            status: string;
            priority: string | null;
            due_at: string | null;
            owner: string | null;
            next_action: string | null;
            source: { label: string; failed_check: boolean };
            restricted: boolean;
            provider_state:
                | 'planned'
                | 'confirmed'
                | 'completed'
                | 'cancelled'
                | null;
        }>;
        active_restrictions: number;
        awaiting_release?: boolean;
        total?: number;
    };
    checks: {
        latest: {
            id: number;
            outcome: string | null;
            template: string | null;
            submitted_at: string;
        } | null;
        next_due_at: string | null;
    };
    catalogues: Record<CatalogueKind, Array<{ id: number; label: string }>>;
    people: Person[];
    as_of: string;
    can: WorkspaceCan;
};

export type RecordPage<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
};
