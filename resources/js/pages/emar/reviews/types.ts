import type { StatusVariant } from '@/components/ui/status-badge';

export type ReviewKind = 'regular' | 'triggered';
export type ReviewView = 'due' | 'booked' | 'recorded' | 'closed' | 'changes';
export type ReviewLocation = 'house' | 'practice' | 'phone' | 'video';
export type ClinicianRole =
    | 'GP'
    | 'Pharmacist'
    | 'Nurse practitioner'
    | 'Specialist';
export type ReviewOutcome =
    | 'pending_controlled'
    | 'continue'
    | 'change'
    | 'stop'
    | 'start'
    | 'swap'
    | 'watch';
export type DecisionState = 'waiting' | 'agreed' | 'not_agreed';
export type DecisionMethod = 'writing' | 'phone' | 'review' | 'person';
export type Cadence = { months: number; own: boolean; reviewed: boolean };
export type SourceFile = {
    name: string;
    view_url: string | null;
    download_url: string | null;
    mime: string | null;
    size: number | null;
};
export type ReviewOrder = {
    id: number;
    name: string;
    dosage: string | null;
    frequency: string | null;
    controlled_hidden: boolean;
};
export type ReviewItem = {
    id: number;
    client_medication_id: number | null;
    name: string;
    controlled_hidden: boolean;
    classification_pending?: boolean;
    outcome: ReviewOutcome;
    recommendation: string | null;
    watch_until: string | null;
    watch_text: string | null;
    decision: DecisionState | null;
    prescriber_name: string | null;
    decision_date: string | null;
    decision_time?: string | null;
    decision_at?: string | null;
    decision_method: DecisionMethod | null;
    decision_note: string | null;
    decision_source?: SourceFile | null;
    order_url: string | null;
    linked_order_version_id: number | null;
    followup_url: string | null;
    watch_completed?: boolean;
};
export type ReviewEvent = {
    id: number;
    event: string;
    actor_name: string | null;
    created_at: string;
    details: {
        from_date?: string;
        to_date?: string;
        reason_code?: string;
        reason?: string;
        [key: string]: unknown;
    };
};
export type Review = {
    id: number;
    client_id: number;
    client_name: string;
    site_id: number | null;
    site_name: string | null;
    review_type: ReviewKind;
    status: 'scheduled' | 'completed' | 'cancelled' | 'closed';
    scheduled_date: string;
    completed_date: string | null;
    completed_time?: string | null;
    happened_at?: string | null;
    reviewer_registration_number?: string | null;
    owner_id: number | null;
    owner_name: string | null;
    trigger_code: string | null;
    trigger_reason: string | null;
    reviewer_name: string | null;
    reviewer_role: ClinicianRole | null;
    clinician_practice?: string | null;
    appointment_date: string | null;
    appointment_time: string | null;
    appointment_location: ReviewLocation | null;
    review_location?: ReviewLocation | null;
    clinical_summary: string | null;
    participants: {
        person: 'took' | 'not';
        person_reason?: string;
        whanau: 'took' | 'told' | 'none';
        whanau_detail?: string;
    } | null;
    drug_burden_index: number | string | null;
    falls_last_quarter: number | null;
    next_review_date: string | null;
    next_regular_review_date?: string | null;
    revision: number;
    items: ReviewItem[];
    history?: ReviewEvent[];
    source: SourceFile | null;
    legacy_outcomes?:
        | {
              drug: string;
              action: string;
              rationale?: string | null;
              gp_status?: string;
              stage?: string;
          }[]
        | null;
    legacy_recommendations?: string | null;
    legacy_medications_reviewed?: string[] | null;
    legacy_whanau?: { involved: boolean; notes: string | null } | null;
    current_orders?: ReviewOrder[];
    cadence?: Cadence;
    away?: string | null;
};
export type PickerOption = {
    value: string;
    label: string;
    description?: string;
};
export type ReviewPermissions = {
    manage: boolean;
    orders: boolean;
    controlled: boolean;
    summary: boolean;
};
export type ReviewMeters = {
    overdue: number;
    due_30: number;
    booked: number;
    recorded: number;
    changes: number;
    pending_outcomes: number;
    waiting_prescriber: number;
    changes_to_make: number;
};
export type ReviewPageProps = {
    reviews: {
        data: Review[];
        links: { url: string | null; label: string; active: boolean }[];
        total: number;
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
    };
    meters: ReviewMeters;
    filters: {
        view: ReviewView;
        search?: string;
        site_id?: number | null;
        client_id?: number | null;
        kind?: ReviewKind | null;
        return_to?: string | null;
    };
    sites: { id: number; name: string }[];
    can: ReviewPermissions;
    selected: Review | null;
    selected_item_id?: number | null;
    person: {
        id: number;
        name: string;
        cadence: Cadence;
        next_review_date: string | null;
    } | null;
    default_interval: { months: number; reviewed: boolean };
    today: string;
    as_at: string;
};
export type ReviewAction =
    | { type: 'book'; clientId?: number; clientName?: string }
    | { type: 'record' | 'move' | 'cancel' | 'appointment'; review: Review }
    | { type: 'detail'; review: Review }
    | {
          type: 'change' | 'decision' | 'outcome';
          review: Review;
          item: ReviewItem;
      }
    | {
          type: 'interval';
          clientId: number;
          clientName: string;
          cadence: Cadence;
      };
export type OutcomeDraft = {
    client_medication_id: number;
    outcome: ReviewOutcome | '';
    recommendation: string;
    watch_text: string;
    watch_until: string;
};
export type StatusLabel = { label: string; variant: StatusVariant };
