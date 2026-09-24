import type { StatusVariant } from '@/components/ui/status-badge';
import { WORKER_TIMEZONE } from '@/lib/datetime';
import type {
    CheckSummary,
    ComplianceRecord,
    ReadinessReason,
    ServiceSchedule,
    VehicleWorkspace,
} from './types';

export type MainTab =
    | 'overview'
    | 'service'
    | 'checks'
    | 'maintenance'
    | 'map'
    | 'trips'
    | 'calendar';

export type OverviewView = 'readiness' | 'details' | 'documents' | 'finance';
export type ServiceView =
    | 'evidence'
    | 'schedules'
    | 'reminders'
    | 'history'
    | 'mileage';
export type ChecksView = 'recent' | 'templates';
export type MaintenanceView = 'open' | 'history';
export type MapView = 'location' | 'telemetry' | 'driving' | 'alerts';
export type WorkspaceView =
    | OverviewView
    | ServiceView
    | ChecksView
    | MaintenanceView
    | MapView;

export type WorkspaceLocation = {
    tab: MainTab;
    view?: WorkspaceView;
    /** Calendar or trip history deep link: the day to open (YYYY-MM-DD). */
    date?: string;
};

export const MAIN_TABS: MainTab[] = [
    'overview',
    'service',
    'checks',
    'maintenance',
    'map',
    'trips',
    'calendar',
];
export const OVERVIEW_VIEWS: OverviewView[] = [
    'readiness',
    'details',
    'documents',
    'finance',
];
export const SERVICE_VIEWS: ServiceView[] = [
    'evidence',
    'schedules',
    'reminders',
    'history',
    'mileage',
];
export const CHECKS_VIEWS: ChecksView[] = ['recent', 'templates'];
export const MAINTENANCE_VIEWS: MaintenanceView[] = ['open', 'history'];
export const MAP_VIEWS: MapView[] = [
    'location',
    'telemetry',
    'driving',
    'alerts',
];

/** Sub-views per tab, first entry is the default. Trips and Calendar have none. */
export const TAB_VIEWS: Partial<Record<MainTab, readonly WorkspaceView[]>> = {
    overview: OVERVIEW_VIEWS,
    service: SERVICE_VIEWS,
    checks: CHECKS_VIEWS,
    maintenance: MAINTENANCE_VIEWS,
    map: MAP_VIEWS,
};
/** The Auckland calendar date "today" for date-only comparisons. */
export function todayInAuckland(now = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: WORKER_TIMEZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(now);
}

export function formatKm(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value))
        return 'Not recorded';
    return `${value.toLocaleString('en-NZ', { maximumFractionDigits: 1 })} km`;
}

export type HeaderStatus = {
    label: 'Ready' | 'Not ready' | 'Needs assessment';
    variant: StatusVariant;
    state: 'restricted' | 'review' | 'ready';
};

export function overdueSchedules(
    schedules: ServiceSchedule[],
): ServiceSchedule[] {
    return schedules.filter((schedule) => schedule.overdue);
}

export function checkOverdue(
    workspace: VehicleWorkspace,
    today: string,
): boolean {
    const due = workspace.checks.next_due_at;
    return !!due && due < today;
}

/** The most recent submitted check of any kind, daily checks included. */
export function lastCheck(
    checks: VehicleWorkspace['checks'],
): CheckSummary | null {
    const latest = checks.latest ?? null;
    const daily = checks.latest_daily ?? null;
    if (!latest || !daily) return latest ?? daily;
    const newer =
        daily.submitted_at > latest.submitted_at ||
        (daily.submitted_at === latest.submitted_at && daily.id > latest.id);
    return newer ? daily : latest;
}

/** The latest daily check recorded an issue: shown for review, never a block. */
export function dailyIssue(workspace: VehicleWorkspace): boolean {
    return workspace.checks.latest_daily?.outcome === 'issue_recorded';
}

/**
 * One readiness picture for the chip, the readiness card and the header.
 * Blocking reasons come from the server; overdue service and checks, and an
 * issue from the latest daily check, are shown as needing review without
 * blocking use.
 */
export function headerStatus(
    workspace: VehicleWorkspace,
    today = todayInAuckland(),
): HeaderStatus {
    const { readiness, vehicle } = workspace;
    if (readiness.restriction_ids.length > 0 || vehicle.status !== 'active') {
        return { label: 'Not ready', variant: 'critical', state: 'restricted' };
    }
    const latest = workspace.checks.latest;
    if (
        !readiness.can_proceed ||
        overdueSchedules(workspace.schedules).length > 0 ||
        checkOverdue(workspace, today) ||
        (latest && latest.outcome !== 'passed') ||
        dailyIssue(workspace)
    ) {
        return {
            label: 'Needs assessment',
            variant: 'warning',
            state: 'review',
        };
    }
    return { label: 'Ready', variant: 'success', state: 'ready' };
}

/** Where the person should go to resolve a reason. */
export function reasonDestination(reason: ReadinessReason): WorkspaceLocation {
    if (
        reason.code.startsWith('compliance.ruc.odometer') ||
        reason.code.startsWith('compliance.ruc.coverage')
    )
        return { tab: 'service', view: 'mileage' };
    if (reason.code.startsWith('compliance.'))
        return { tab: 'service', view: 'evidence' };
    if (reason.code === 'maintenance.unresolved_check')
        return { tab: 'checks', view: 'recent' };
    if (reason.code.startsWith('maintenance.'))
        return { tab: 'maintenance', view: 'open' };
    if (reason.code.startsWith('odometer.'))
        return { tab: 'service', view: 'mileage' };
    return { tab: 'overview', view: 'readiness' };
}

export function blockingReasons(
    workspace: VehicleWorkspace,
): ReadinessReason[] {
    return workspace.readiness.reasons.filter(
        (reason) => reason.blocks_decision,
    );
}

/** Issue with one compliance requirement, mirroring the server's readiness rules. */
export function complianceIssue(
    record: ComplianceRecord,
    reasons: ReadinessReason[],
): ReadinessReason | null {
    return (
        reasons.find(
            (reason) =>
                reason.kind === record.kind &&
                reason.code.startsWith('compliance.'),
        ) ?? null
    );
}

export function complianceTone(
    record: ComplianceRecord,
    reasons: ReadinessReason[],
): 'success' | 'warning' | 'critical' {
    if (
        record.current?.applicability === 'applicable' &&
        record.current.outcome === 'failed'
    )
        return 'critical';
    return complianceIssue(record, reasons) ? 'warning' : 'success';
}

export function locationUrl(
    vehicleId: number,
    location: WorkspaceLocation,
): string {
    const params = new URLSearchParams();
    if (location.tab !== 'overview') params.set('tab', location.tab);
    if (location.view) params.set('view', location.view);
    if (location.date) params.set('date', location.date);
    const query = params.toString();
    return `/fleet-assets/vehicles/${vehicleId}${query ? `?${query}` : ''}`;
}

export function readLocation(search: string): WorkspaceLocation {
    const params = new URLSearchParams(search);
    const requested = params.get('tab');
    // Earlier links opened a separate Technology tab; it now lives under Map.
    if (requested === 'technology') return { tab: 'map', view: 'telemetry' };
    const tab = MAIN_TABS.includes(requested as MainTab)
        ? (requested as MainTab)
        : 'overview';
    const views = TAB_VIEWS[tab];
    const view = params.get('view') as WorkspaceView | null;
    // The calendar opens on a day; trip history filters to one.
    if (tab === 'calendar' || tab === 'trips') {
        const date = params.get('date');
        return date && /^\d{4}-\d{2}-\d{2}$/.test(date)
            ? { tab, date }
            : { tab };
    }
    if (!views) return { tab };
    return { tab, view: view && views.includes(view) ? view : views[0] };
}

export const SOURCE_KIND_LABELS: Record<string, string> = {
    dashboard_manual: 'Dashboard reading',
    booking_checkout: 'Booking checkout',
    booking_return: 'Booking return',
    inspection: 'Inspection',
    legacy_unverified: 'Earlier record (unverified)',
};

export const FILE_STATE_LABELS: Record<
    string,
    { label: string; variant: StatusVariant }
> = {
    available: { label: 'Available', variant: 'success' },
    legacy_unverified: { label: 'Not virus-checked', variant: 'neutral' },
    reserved: { label: 'Uploading', variant: 'info' },
    stored: { label: 'Checking', variant: 'info' },
    scan_unavailable: { label: 'Waiting for virus check', variant: 'warning' },
    storage_failed: { label: 'Upload failed', variant: 'critical' },
    publication_failed: { label: 'Needs retry', variant: 'warning' },
    quarantined: { label: 'Blocked: failed virus check', variant: 'critical' },
};

export function intervalText(schedule: ServiceSchedule): string {
    const parts = [
        schedule.interval_months
            ? `${schedule.interval_months} ${schedule.interval_months === 1 ? 'month' : 'months'}`
            : schedule.interval_days
              ? `${schedule.interval_days} days`
              : null,
        schedule.interval_km
            ? `${schedule.interval_km.toLocaleString('en-NZ')} km`
            : null,
    ].filter(Boolean);
    return parts.length ? parts.join(' or ') : 'Not set';
}
