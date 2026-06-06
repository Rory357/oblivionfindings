/**
 * Shape of the single Respite workspace payload (see RespiteWorkspaceController).
 * Records are pre-flattened server-side so panes never touch DB column names.
 */

export type Urgency = 'planned' | 'urgent' | 'crisis';

export interface RespiteHome {
    id: number;
    name: string;
    capacity: number;
    occupied: number;
    available: number | null;
    full: boolean;
}

export type FundingStatus =
    | 'not_required'
    | 'pending_approval'
    | 'approved'
    | 'declined'
    | 'expired';

export interface ServiceAgreementSummary {
    id: number;
    title: string | null;
    referenceNumber: string | null;
    status: string | null;
    endsAt: string | null;
    signedAt: string | null;
    signedDate: string | null;
    reviewDueDate: string | null;
    budgetRemaining: number;
    hoursRemaining: number;
    carerSupportDaysAllocated: number | null;
    carerSupportDaysUsed: number | null;
    carerSupportDaysRemaining: number | null;
    carerSupportEntitlementYear: string | null;
}

export interface ReadinessSegment {
    key: string;
    label: string;
    status: 'complete' | 'pending' | 'attention';
    complete: boolean;
    message: string | null;
}

export interface CriticalAlert {
    type: 'allergy' | 'medication_alert' | 'safeguarding';
    label: string;
    detail: string | null;
    severity: string;
    requiresAcknowledgement: boolean;
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
    isMaori: boolean;
    iwi: string | null;
    hapu: string | null;
    marae: string | null;
    interpreterRequired: boolean;
    interpreterLanguage: string | null;
    interpreterArranged: boolean;
    carerStrainLevel: string | null;
    carerBreakdown: boolean;
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
    fundingSource: string | null;
    fundingReference: string | null;
    fundingStatus: FundingStatus;
    serviceAgreement: ServiceAgreementSummary | null;
    priority: string | null;
    waitlistPosition: number | null;
    expectedAvailabilityDate: string | null;
    isEmergency: boolean;
    fastTracked: boolean;
    seriesId: string | null;
    recurrenceRule: string | null;
    allocatedDays: number | null;
    carer: Record<string, unknown> | null;
    cultural: Record<string, unknown> | null;
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
    funding: string | null;
    fundingSource: string | null;
    fundingReference: string | null;
    fundingStatus: FundingStatus;
    serviceAgreement: ServiceAgreementSummary | null;
    agreementStatus: string | null;
    consentAuthority: string | null;
    culturalSnapshot: Record<string, unknown> | null;
    culturalPlacementCheck: Record<string, unknown> | null;
    settingRestriction: string | null;
    interpreterArranged: boolean;
    copaymentAmount: number | null;
    copaymentBasis: string | null;
    privatePayPortion: number | null;
    copaymentStatus: string | null;
    recurrenceRule: string | null;
    seriesId: string | null;
    criticalAlerts: CriticalAlert[];
    readiness: number;
    readinessSegments: ReadinessSegment[];
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
    dischargeReason: string | null;
    bedHoldStatus: string | null;
    bedHoldReason: string | null;
    bedHoldUntil: string | null;
    unreviewedRestraints: number;
    openIncidents: number;
    criticalAlerts: CriticalAlert[];
    requiresAdmissionMedRec: boolean;
    admissionMedRecStatus: string | null;
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
    carerCrisisAttention: number;
    awaitingReview: number;
    waitlisted: number;
    confirmedUpcoming: number;
    inHouse: number;
    bedsTotal: number;
    bedsOccupied: number;
    fullHomes: number;
    fundingAttention: number;
    complianceAttention: number;
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
