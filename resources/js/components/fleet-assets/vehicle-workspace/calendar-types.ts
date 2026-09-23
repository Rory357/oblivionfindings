import type { CalendarItem } from '@/lib/calendar/recur';

/** Server DTOs for the vehicle calendar (VehicleCalendarService). */

export type VehicleCalendarKind =
    | 'restriction'
    | 'appointment'
    | 'estimate'
    | 'booking'
    | 'busy'
    | 'unavailable'
    | 'schedule'
    | 'compliance'
    | 'check'
    | 'reminder';

export type VehicleCalendarItem = CalendarItem & {
    kind: VehicleCalendarKind;
    statusLabel: string;
    recordId: number | null;
    workOrderId: number | null;
    desc: string | null;
    /** Service appointments: the planned provider, whether it holds the vehicle, and whether it can still change. */
    meta?: {
        provider?: string | null;
        unavailable?: boolean;
        open?: boolean;
    } | null;
};

export type CalendarPerson = { id: number; name: string };

export type BookingHistoryEntry = {
    label: string;
    reason: string | null;
    actor: string | null;
    at: string;
};

export type CalendarFile = {
    id: number;
    name: string;
    state: string | null;
    url: string | null;
};

export type BookingRow = {
    kind: 'booking';
    id: number;
    reference: string | null;
    purpose: string;
    destination: string | null;
    passengers: number | null;
    notes: string | null;
    starts_at: string;
    ends_at: string;
    status:
        | 'pending'
        | 'approved'
        | 'checked_out'
        | 'returned'
        | 'rejected'
        | 'cancelled';
    status_label: string;
    requester: CalendarPerson | null;
    driver: CalendarPerson | null;
    approval_route: 'required' | 'not_required';
    approval_not_required_reason: string | null;
    approval_not_required_evidence: string | null;
    pickup_arrangement: string | null;
    odometer_out: number | null;
    odometer_in: number | null;
    checkout_condition: string | null;
    checkout_evidence_reference: string | null;
    checkout_notes: string | null;
    condition_on_return: string | null;
    return_evidence_reference: string | null;
    return_notes: string | null;
    rejection_reason: string | null;
    cancellation_reason: string | null;
    lock_version: number;
    history: BookingHistoryEntry[];
    keys: Array<{ action: string; holder: string | null; at: string | null }>;
    files: CalendarFile[];
    can: {
        edit: boolean;
        approve: boolean;
        decline: boolean;
        checkout: boolean;
        return: boolean;
        cancel: boolean;
        upload: boolean;
    };
};

export type UnavailableRow = {
    kind: 'unavailable';
    id: number;
    reference: string | null;
    /** Set when a service appointment holds the vehicle. */
    work_order_id: number | null;
    purpose: string;
    starts_at: string;
    ends_at: string;
    status: 'active' | 'cancelled';
    status_label: string;
    cancellation_reason: string | null;
    lock_version: number;
    history: BookingHistoryEntry[];
    files: CalendarFile[];
    can: { edit: boolean; cancel: boolean; upload: boolean };
};

export type CustodyRow = BookingRow | UnavailableRow;

export type CalendarDriver = CalendarPerson & {
    licence_status: string | null;
    licence_expires_at: string | null;
};

export type CalendarOpenWork = {
    id: number;
    reference: string | null;
    title: string | null;
    status: string;
    version: number;
};

export type VehicleCalendarSummary = {
    asset: {
        id: number;
        name: string;
        asset_tag: string | null;
        registration_number: string | null;
    };
    restriction: {
        id: number;
        started_at: string;
        kind: string;
        work_order_id: number;
        work_reference: string | null;
        work_title: string | null;
    } | null;
    use_problem: string | null;
    readiness_label: string;
    next_appointment: { start: string; title: string; id: string } | null;
    next_due: { start: string; title: string; id: string } | null;
    bookings: CustodyRow[];
    drivers: CalendarDriver[];
    open_work: CalendarOpenWork[];
    can: {
        request: boolean;
        manage: boolean;
        approve: boolean;
        authority: boolean;
        schedule_service: boolean;
        report_work: boolean;
        add_reminder: boolean;
        mark_unavailable: boolean;
        view_maintenance: boolean;
    };
};
