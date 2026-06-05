/**
 * Shape of the single Respite workspace payload (see RespiteWorkspaceController).
 * Records are pre-flattened server-side so panes never touch DB column names.
 */

export type Urgency = 'planned' | 'urgent' | 'crisis';

export interface RespiteHome {
    id: number;
    name: string;
    capacity: number;
}

export interface RespiteReferralRow {
    id: number;
    ref: string;
    client: string;
    clientId: number | null;
    age: number | null;
    referrer: string | null;
    referrerType: string | null;
    contact: string | null;
    urgency: Urgency;
    status: string;
    received: string | null;
    reason: string | null;
    riskLevel: string | null;
    funding: string | null;
    site: string | null;
    triageNotes: string | null;
}

export interface RespiteRequestRow {
    id: number;
    ref: string;
    client: string;
    clientId: number | null;
    referralId: number | null;
    referralRef: string | null;
    status: string;
    start: string | null;
    end: string | null;
    nights: number | null;
    funding: string | null;
    site: string | null;
    serviceContext: string | null;
    reviewer: string | null;
    submitted: string | null;
    note: string | null;
    hasBooking: boolean;
    bookingId: number | null;
    onboarded: boolean;
}

export interface RespiteBookingRow {
    id: number;
    ref: string;
    client: string;
    clientId: number | null;
    requestId: number | null;
    status: string;
    start: string | null;
    end: string | null;
    nights: number | null;
    site: string | null;
    coordinator: string | null;
    readiness: number;
    hasStay: boolean;
}

export interface RespiteStayRow {
    id: number;
    ref: string;
    client: string;
    clientId: number | null;
    bookingId: number | null;
    status: string;
    live: boolean;
    site: string | null;
    actualStart: string | null;
    actualEnd: string | null;
    plannedEnd: string | null;
}

export interface RespiteTaskRow {
    id: number;
    title: string;
    description: string | null;
    type: string;
    status: string;
    priority: string;
    assignee: string | null;
    dueAt: string | null;
    overdue: boolean;
    stopGate: boolean;
    requiresApproval: boolean;
}

export interface RespiteStats {
    newReferrals: number;
    toTriage: number;
    crisisOpen: number;
    awaitingReview: number;
    confirmedUpcoming: number;
    inHouse: number;
    bedsTotal: number;
    bedsOccupied: number;
}

export interface ClientOption {
    id: number;
    first_name: string;
    last_name: string;
}

export interface RespiteWorkspaceData {
    referrals: RespiteReferralRow[];
    requests: RespiteRequestRow[];
    bookings: RespiteBookingRow[];
    stays: RespiteStayRow[];
    tasks: RespiteTaskRow[];
    homes: RespiteHome[];
    stats: RespiteStats;
    clients: ClientOption[];
    serviceContexts: { id: number; name: string }[];
    fundingSources: FundingOption[];
}

export interface FundingOption {
    value: string;
    label: string;
}

export interface RespiteCan {
    viewAny?: boolean;
    create?: boolean;
    update?: boolean;
    bookingsManage?: boolean;
    staysManage?: boolean;
    resourcesManage?: boolean;
    proceduresManage?: boolean;
    calendarView?: boolean;
    evidenceView?: boolean;
    tasksView?: boolean;
    tasksManage?: boolean;
}

export type DetailKind = 'referral' | 'request' | 'booking' | 'stay';

export type RespiteTab =
    | 'overview'
    | 'referrals'
    | 'requests'
    | 'bookings'
    | 'calendar'
    | 'stays'
    | 'tasks';

export const RESPITE_TABS: RespiteTab[] = [
    'overview',
    'referrals',
    'requests',
    'bookings',
    'calendar',
    'stays',
    'tasks',
];
