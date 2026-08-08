/**
 * Shape of the single Respite workspace payload (see RespiteWorkspaceController).
 * Records are pre-flattened server-side so panes never touch DB column names.
 */

import type { ClientWizardForm } from '@/components/clients/add-client-dialog';
import type { PlanPickerOption } from '@/components/health-safety/restraint-event-wizard';
import type {
    IncidentOption,
    SiteOption,
    StaffOption,
} from '@/pages/health-safety/restraints/shared';

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
    fundingSource: string | null;
    fundingReference: string | null;
    site: string | null;
    triageNotes: string | null;
    hasRequest: boolean;
    linkedRequestId: number | null;
    isMaori: boolean;
    iwi: string | null;
    hapu: string | null;
    marae: string | null;
    interpreterRequired: boolean;
    interpreterLanguage: string | null;
    interpreterArranged: boolean;
    carerStrainLevel: string | null;
    carerBreakdown: boolean;
    clientProfileComplete: boolean;
    clientProfilePrefill: Partial<ClientWizardForm> | null;
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
    clientProfileComplete: boolean;
    clientProfilePrefill: Partial<ClientWizardForm> | null;
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
    consentAuthorityName: string | null;
    consentAuthorityContact: string | null;
    codeOfRightsProvided: boolean;
    consentToRespite: boolean;
    consentCapacityBasis: string | null;
    advocateOffered: boolean | null;
    rightsFormatProvided: string | null;
    rightsRecordedAt: string | null;
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
    siteId: number | null;
    actualStart: string | null;
    actualEnd: string | null;
    plannedEnd: string | null;
    dischargeReason: string | null;
    bedHoldStatus: string | null;
    bedHoldReason: string | null;
    bedHoldUntil: string | null;
    unreviewedRestraints: number;
    openIncidents: number;
    openComplaints: number;
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
    compliance: {
        notifiablePastDeadline: number;
        notifiableDueSoon: number;
        restraintsAwaitingReview: number;
        bspAwaitingLink: number;
        missingConsentRights: number;
        openComplaints: number;
    };
}

export interface ClientOption {
    id: number;
    first_name: string;
    last_name: string;
    date_of_birth?: string | null;
    nhi_number?: string | null;
    site?: string | null;
}

export interface ClientProfileOptions {
    sites: { id: number; name: string }[];
    serviceContexts: { id: number; type?: string | null; name: string }[];
    keyWorkers: { id: number; name: string }[];
    geofences: { id: number; name: string }[];
    defaultServiceContextId: number | null;
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
    serviceAgreements: (ServiceAgreementSummary & { clientId: number })[];
    fundingSources: FundingOption[];
    clientProfileOptions: ClientProfileOptions;
    /**
     * Lookup data for the shared RestraintEventWizard launched from a live stay.
     * Client is prescoped from the row, so no clients list is needed here.
     */
    restraintPickers: {
        sites: SiteOption[];
        staff: StaffOption[];
        incidents: IncidentOption[];
        plans: PlanPickerOption[];
    };
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
