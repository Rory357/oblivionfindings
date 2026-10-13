import { toDateInput, WORKER_LOCALE, WORKER_TIMEZONE } from '@/lib/datetime';
import { CalendarX, Clock, Layers, RefreshCw } from 'lucide-react';
import {
    TYPE_META,
    type ConflictType,
    type QueueAction,
    type QueueEntity,
    type QueueItem,
    type QueueShift,
} from './types';
export type ShiftRow = {
    id: number;
    finding_id?: string;
    client_id: number | null;
    user_id: number | null;
    site_id: number | null;
    effective_site_id: number;
    client_name: string | null;
    staff_name?: string | null;
    service_context?: string | null;
    status: string;
    shift_type?: string | null;
    location?: string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    shift_series_id?: number | null;
    can: { view_shift: boolean; view_client: boolean; view_roster: boolean };
    urls: {
        shift: string | null;
        client: string | null;
        roster: string | null;
    };
};
export type CategoryAssessment = {
    status: 'assessed' | 'partially_assessed' | 'not_assessed';
    displayed_count: number;
    finding_count: number | null;
    truncated: boolean;
    description?: string;
};
export type ConflictAssessment = {
    scope: 'approved_sites';
    interval_basis: 'worker_local_week';
    publication_assessed: false;
    visible_duty_count: number;
    actionable_duty_count: number;
    description: string;
    categories: Record<string, CategoryAssessment>;
    scan_criteria: {
        worker_timezone: string;
        interval_end_exclusive: boolean;
        turnaround_threshold_minutes: number;
        automatic_scan: false;
        publication_assessed: false;
    };
    workflow_urls: { workforce_settings: string | null };
};
export type CoverageGap = {
    finding_id: string;
    site_id: number;
    site_name: string;
    rule_id?: number | null;
    rule_name: string;
    window_label: string;
    starts_at?: string;
    ends_at?: string;
    source_assessment: 'assessed' | 'not_assessed';
    assessment_description?: string;
    required_staff: number;
    assigned_staff: number | null;
    missing_staff: number | null;
    planned_staff?: number | null;
    coverage_window_key?: string | null;
    preferred_client_id?: number | null;
    action_window?: {
        site_id: number;
        coverage_requirement_id: number | null;
        window_starts_at: string;
        window_ends_at: string;
    };
    can?: { acknowledge: boolean; dismiss: boolean; clear: boolean };
    urls: {
        roster: string | null;
        ack?: string | null;
        dismiss?: string | null;
        clear?: string | null;
    };
    acknowledgement?: {
        id: number;
        state: 'acked' | 'dismissed';
        since: string | null;
    } | null;
    acknowledgement_assessment?: 'assessed' | 'unavailable' | 'not_assessed';
    open_shift_ids?: number[];
    contributing_shifts?: ShiftRow[];
    role_shortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number;
    }>;
    planned_role_shortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number;
    }>;
    unfilled_after_open_shifts?: number | null;
    coverage_state?: string | null;
    planned_coverage_state?: string | null;
    gap_kind?: string | null;
    recommended_fill_action?: string | null;
};
export type ConflictsProps = {
    weekStart: string;
    weekEnd: string;
    workerTimezone: string;
    assessment?: ConflictAssessment;
    staffOverlaps: Array<{
        pair_id: string;
        staff_id: number;
        staff_name: string;
        first: ShiftRow;
        second: ShiftRow;
    }>;
    clientOverlaps: Array<{
        pair_id: string;
        client_id: number;
        client_name: string;
        first: ShiftRow;
        second: ShiftRow;
    }>;
    tightTurnarounds: Array<{
        pair_id: string;
        staff_id: number;
        staff_name: string;
        gap_minutes: number;
        first: ShiftRow;
        second: ShiftRow;
    }>;
    timeOffConflicts: Array<{
        pair_id: string;
        shift: ShiftRow;
        time_off: {
            id: number;
            user_id: number;
            user_name: string;
            type: string;
            label: null;
            description: string;
            starts_at?: string;
            ends_at?: string;
            can: { view_leave: boolean };
            urls: { leave: string | null };
        };
    }>;
    openShifts: ShiftRow[];
    activeReplacements: Array<{
        id: number;
        finding_id: string;
        shift: ShiftRow;
        status: string;
        reason: string;
        requested_by?: string | null;
        current_staff?: string | null;
        replacement_staff?: string | null;
        claimed_by?: string | null;
        open_position_id?: number | null;
        can: { view_job_board: boolean };
        urls: { job_board: string | null };
    }>;
    coverageGaps: CoverageGap[];
    recurringCoverageAlignment: {
        rule_drift: CoverageGap[];
        orphan_series: Array<{
            finding_id: string;
            series_id: number;
            site_id: number;
            site_name: string;
            client_name?: string | null;
            weekdays: string[];
            starts_time?: string | null;
            ends_time?: string | null;
            urls: { roster: string | null };
        }>;
    };
};
export function formatWindow(
    startsAt?: string | null,
    endsAt?: string | null,
    workerTimezone = WORKER_TIMEZONE,
) {
    if (!startsAt || !endsAt) return 'Time not set';
    try {
        const startDate = toDateInput(startsAt, workerTimezone);
        const endDate = toDateInput(endsAt, workerTimezone);
        if (!startDate || !endDate) return 'Time unavailable';
        const start = `${dayLabel(startsAt, workerTimezone)} · ${timeLabel(startsAt, workerTimezone)}`;
        const end = `${startDate !== endDate ? `${dayLabel(endsAt, workerTimezone)} · ` : ''}${timeLabel(endsAt, workerTimezone)}`;
        return `${start} → ${end}`;
    } catch {
        return 'Time unavailable';
    }
}

export function dayLabel(
    value?: string | null,
    workerTimezone = WORKER_TIMEZONE,
) {
    if (!value) return 'Time not set';
    try {
        if (!toDateInput(value, workerTimezone)) return 'Time unavailable';
        return new Date(value)
            .toLocaleDateString(WORKER_LOCALE, {
                timeZone: workerTimezone,
                weekday: 'short',
                day: 'numeric',
                month: 'short',
            })
            .replace(/,\s*/g, ' ');
    } catch {
        return 'Time unavailable';
    }
}

export function timeLabel(
    value?: string | null,
    workerTimezone = WORKER_TIMEZONE,
) {
    if (!value) return '';
    try {
        if (!toDateInput(value, workerTimezone)) return '';
        return new Date(value)
            .toLocaleTimeString(WORKER_LOCALE, {
                timeZone: workerTimezone,
                hour: 'numeric',
                minute: '2-digit',
                hour12: true,
            })
            .replace(/[\u202f\u00a0]/g, ' ')
            .toLowerCase();
    } catch {
        return '';
    }
}

export function shiftTypeLabel(value?: string | null) {
    return String(value ?? 'standard').replace(/_/g, ' ');
}

export function coverageRolesForAction(gap: {
    planned_role_shortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number;
    }>;
    role_shortages?: Array<{
        key: string;
        label?: string | null;
        missing?: number;
    }>;
}) {
    return (
        (gap.planned_role_shortages?.length
            ? gap.planned_role_shortages
            : gap.role_shortages) ?? []
    );
}

export function gapKindLabel(kind?: string | null) {
    switch (kind) {
        case 'headcount_open':
            return 'Open shift gap';
        case 'headcount_unplanned':
            return 'Unplanned headcount gap';
        case 'role_open':
            return 'Open role gap';
        case 'role_unplanned':
            return 'Unplanned role gap';
        case 'mixed_open':
            return 'Open shift + role gap';
        case 'mixed_unplanned':
            return 'Headcount + role gap';
        case 'overfill_not_allowed':
            return 'Overfill not allowed';
        case 'overfilled_wrong_role_mix':
            return 'Overfilled role imbalance';
        case 'overfill_and_role_imbalance':
            return 'Overfill + role imbalance';
        default:
            return 'Coverage gap';
    }
}

export function fillActionLabel(action?: string | null) {
    switch (action) {
        case 'fill_existing_open_shift':
            return 'Fill existing open shift';
        case 'retag_or_replace_open_shift':
            return 'Retag or replace open shift';
        case 'create_role_specific_shift':
            return 'Create role-specific cover';
        case 'create_recurring_cover':
            return 'Create recurring cover';
        case 'review_existing_supply':
            return 'Review existing supply';
        case 'rebalance_existing_supply':
            return 'Rebalance existing supply';
        default:
            return 'Create cover shift';
    }
}

export function shouldOfferCreation(action?: string | null) {
    return ![
        'none',
        'review_existing_supply',
        'rebalance_existing_supply',
    ].includes(action ?? '');
}

function toQueueShift(row: ShiftRow, zone: string): QueueShift {
    return {
        id: row.id,
        userId: row.user_id,
        clientId: row.client_id,
        siteId: row.effective_site_id,
        workerTimezone: zone,
        urls: row.urls,
        can: row.can,
        client: row.client_name ?? null,
        staff: row.staff_name ?? null,
        location: row.location ?? null,
        serviceContext: row.service_context ?? null,
        shiftType: row.shift_type ?? null,
        status: row.status,
        startsAt: row.starts_at ?? null,
        endsAt: row.ends_at ?? null,
        seriesId: row.shift_series_id ?? null,
    };
}
function entities(
    shifts: QueueShift[],
    kind: 'staff' | 'sites',
): QueueEntity[] {
    const found = new Map<number, string>();
    for (const shift of shifts) {
        const id = kind === 'staff' ? shift.userId : shift.siteId;
        const name = kind === 'staff' ? shift.staff : shift.location;
        if (id != null && name) found.set(id, name);
    }
    return [...found].map(([id, name]) => ({ id, name }));
}
function link(key: string, label: string, href?: string | null): QueueAction[] {
    return href ? [{ key, label, href, tone: 'default' }] : [];
}
function rosterAction(shifts: QueueShift[]): QueueAction[] {
    const source = shifts.find(
        (shift) => shift.can?.view_roster && shift.urls?.roster,
    );
    return link('roster', 'Open roster', source?.urls?.roster);
}
function entry(
    type: ConflictType,
    id: string,
    who: string,
    summary: string,
    shifts: QueueShift[],
    recommended: string,
    actions: QueueAction[],
    payload: Record<string, unknown> = {},
): QueueItem {
    return {
        id,
        type,
        who,
        summary,
        shifts,
        recommended,
        actions,
        payload,
        severity: TYPE_META[type].severity,
        staff: entities(shifts, 'staff'),
        sites: entities(shifts, 'sites'),
    };
}
export function buildQueue(
    props: ConflictsProps,
    canManage = false,
): QueueItem[] {
    const items: QueueItem[] = [];
    const zone = props.workerTimezone;
    for (const row of props.staffOverlaps) {
        const shifts = [
            toQueueShift(row.first, zone),
            toQueueShift(row.second, zone),
        ];
        items.push(
            entry(
                'staff_overlap',
                row.pair_id,
                row.staff_name,
                'Overlapping duties · ' + dayLabel(row.first.starts_at, zone),
                shifts,
                'Choose the duty that needs to change. Open its details to reassign, adjust its times or make it open, then refresh this queue.',
                rosterAction(shifts),
            ),
        );
    }
    for (const row of props.clientOverlaps) {
        const shifts = [
            toQueueShift(row.first, zone),
            toQueueShift(row.second, zone),
        ];
        const client = shifts.find(
            (shift) => shift.can?.view_client && shift.urls?.client,
        );
        items.push(
            entry(
                'client_overlap',
                row.pair_id,
                row.client_name,
                'Duties share time · ' + dayLabel(row.first.starts_at, zone),
                shifts,
                'Check the person’s support plan and staffing requirements. Concurrent duties may be planned multi-worker support; this finding does not establish a funding ratio or approve an exception.',
                [
                    ...link('client', 'View client', client?.urls?.client),
                    ...rosterAction(shifts),
                ],
            ),
        );
    }
    for (const row of props.timeOffConflicts) {
        const shifts = [toQueueShift(row.shift, zone)];
        const item = entry(
            'leave_clash',
            row.pair_id,
            row.time_off.user_name,
            'Duty overlaps recorded ' + row.time_off.type.replace(/_/g, ' '),
            shifts,
            'Review the time-off record and the duty. Leave approval and cancellation belong to HR; use the shift details to arrange cover.',
            [
                ...link(
                    'leave',
                    'Review HR leave',
                    row.time_off.can.view_leave
                        ? row.time_off.urls.leave
                        : null,
                ),
                ...rosterAction(shifts),
            ],
            { time_off_id: row.time_off.id },
        );
        item.context = {
            tone: 'crit',
            icon: CalendarX,
            text:
                formatWindow(
                    row.time_off.starts_at,
                    row.time_off.ends_at,
                    zone,
                ) +
                ' · ' +
                row.time_off.description,
        };
        items.push(item);
    }
    for (const row of props.tightTurnarounds) {
        const shifts = [
            toQueueShift(row.first, zone),
            toQueueShift(row.second, zone),
        ];
        const item = entry(
            'tight_turnaround',
            row.pair_id,
            row.staff_name,
            row.gap_minutes + ' min between consecutive duties',
            shifts,
            'Check travel and rest needs. Open the appropriate shift to review its times or assignment; this queue cannot accept fatigue risk.',
            rosterAction(shifts),
        );
        item.context = {
            tone: 'info',
            icon: Clock,
            text:
                'Review prompt · no overlap · ' +
                row.gap_minutes +
                ' minute gap',
        };
        items.push(item);
    }
    function coverage(
        gap: CoverageGap,
        type: 'coverage_gap' | 'recurring_alignment',
    ) {
        const shifts = (gap.contributing_shifts ?? []).map((row) =>
            toQueueShift(row, zone),
        );
        const assessed = gap.source_assessment === 'assessed';
        const actions = link(
            'roster',
            type === 'recurring_alignment'
                ? 'Review recurring cover'
                : 'Review roster',
            gap.urls.roster,
        );
        if (type === 'coverage_gap' && assessed) {
            if (
                canManage &&
                gap.starts_at &&
                gap.ends_at &&
                shouldOfferCreation(gap.recommended_fill_action)
            )
                actions.push({
                    key: 'create',
                    label: 'Create cover shift',
                    tone: 'primary',
                });
            if (gap.can?.acknowledge && gap.urls.ack)
                actions.push({
                    key: 'ack',
                    label: 'Acknowledge',
                    tone: 'subtle',
                });
            if (gap.can?.dismiss && gap.urls.dismiss)
                actions.push({
                    key: 'dismiss',
                    label: 'Dismiss review',
                    tone: 'subtle',
                });
            if (gap.acknowledgement && gap.can?.clear && gap.urls.clear)
                actions.push({
                    key: 'clear',
                    label: 'Clear acknowledgement',
                    tone: 'subtle',
                });
        }
        const review = gap.acknowledgement
            ? gap.acknowledgement.state === 'acked'
                ? ' · Acknowledged'
                : ' · Review dismissed'
            : '';
        const item = entry(
            type,
            gap.finding_id,
            gap.site_name,
            gap.rule_name +
                ' · ' +
                formatWindow(gap.starts_at, gap.ends_at, zone) +
                review,
            shifts,
            assessed
                ? 'Review the contributing duties and required roles. Fill an existing open duty before creating additional cover. Acknowledging or dismissing a review does not provide staff.'
                : 'Supply could not be fully assessed within this view. Review the permitted source records; no shortage or safe-cover conclusion is available.',
            actions,
            { gap },
        );
        item.sites = [{ id: gap.site_id, name: gap.site_name }];
        item.context = {
            tone: 'warn',
            icon: Layers,
            text:
                assessed &&
                gap.assigned_staff != null &&
                gap.missing_staff != null
                    ? 'Need ' +
                      gap.required_staff +
                      ' · ' +
                      gap.assigned_staff +
                      ' assigned · ' +
                      gap.missing_staff +
                      ' short'
                    : 'Supply and shortage not assessed',
        };
        items.push(item);
    }
    for (const gap of props.coverageGaps) coverage(gap, 'coverage_gap');
    for (const shift of props.openShifts) {
        const shifts = [toQueueShift(shift, zone)];
        items.push(
            entry(
                'open_shift',
                shift.finding_id ?? 'open_shift:' + shift.id,
                shift.location ?? shift.client_name ?? 'Open shift',
                formatWindow(shift.starts_at, shift.ends_at, zone),
                shifts,
                'Open the shift to assign an eligible, available colleague or review its Job Board options. It remains open until a saved assignment is confirmed.',
                rosterAction(shifts),
            ),
        );
    }
    for (const row of props.activeReplacements) {
        const shifts = [toQueueShift(row.shift, zone)];
        const item = entry(
            'replacement',
            row.finding_id,
            row.shift.client_name ?? 'Replacement',
            row.reason,
            shifts,
            'Review the current replacement and claim in its owning workflow. A request or claim does not by itself confirm staffing.',
            [
                ...link(
                    'board',
                    'Review Job Board',
                    row.can.view_job_board ? row.urls.job_board : null,
                ),
                ...rosterAction(shifts),
            ],
        );
        item.context = {
            tone: 'info',
            icon: RefreshCw,
            text: row.status.replace(/_/g, ' '),
        };
        items.push(item);
    }
    for (const gap of props.recurringCoverageAlignment.rule_drift)
        coverage(gap, 'recurring_alignment');
    for (const row of props.recurringCoverageAlignment.orphan_series) {
        const item = entry(
            'recurring_alignment',
            row.finding_id,
            row.site_name,
            'Recurring supply has no matching demand window',
            [],
            'Review this pattern against the House’s current staffing requirements. Existing duties are not cancelled by this finding.',
            link('recurring', 'Review recurring pattern', row.urls.roster),
            { series_id: row.series_id },
        );
        item.sites = [{ id: row.site_id, name: row.site_name }];
        items.push(item);
    }
    return items;
}
