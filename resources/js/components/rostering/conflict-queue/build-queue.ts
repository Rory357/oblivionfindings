import { CalendarX, Clock, Layers, RefreshCw } from 'lucide-react';

import { ACTIONS, TYPE_META, type QueueItem, type QueueShift } from './types';

/**
 * The shape of a shift row as the `conflicts` controller serialises it. Kept
 * identical to the controller payload — the adapter is the only place that knows
 * about both this raw shape and the unified `QueueItem` model.
 */
export type ShiftRow = {
    id: number;
    client_name: string;
    staff_name?: string | null;
    service_context?: string | null;
    status: string;
    shift_type?: string | null;
    location?: string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    shift_series_id?: number | null;
};

export type CoverageGap = {
    site_id: number;
    site_name: string;
    rule_id?: number;
    coverage_window_key?: string | null;
    rule_name: string;
    window_label: string;
    starts_at?: string;
    ends_at?: string;
    required_staff: number;
    assigned_staff: number;
    planned_staff?: number;
    missing_staff: number;
    preferred_client_id?: number | null;
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
    unfilled_after_open_shifts?: number;
    coverage_state: string;
    planned_coverage_state?: string;
    gap_kind?: string | null;
    recommended_fill_action?: string | null;
    contradictions?: string[];
    partial_window_uncovered_slices?: Array<{
        starts_at: string;
        ends_at: string;
        missing_staff?: number;
    }>;
    acknowledgement?: {
        state: 'acked' | 'dismissed';
        actor?: { id: number; name?: string | null } | null;
        reason?: string | null;
        since?: string | null;
    } | null;
    open_shift_ids?: number[];
    contributing_shifts?: ShiftRow[];
    matching_series?: Array<{
        id: number;
        client_name?: string | null;
        staff_name?: string | null;
        service_context_name?: string | null;
        shift_type?: string | null;
        weekdays: string[];
        starts_time?: string | null;
        ends_time?: string | null;
        location?: string | null;
        next_starts_at?: string | null;
        active_occurrences_count?: number;
        open_occurrences_count?: number;
    }>;
};

export type ConflictsProps = {
    weekStart: string;
    weekEnd: string;
    staffOverlaps: Array<{
        staff_id: number;
        staff_name: string;
        first: ShiftRow;
        second: ShiftRow;
    }>;
    clientOverlaps: Array<{
        client_id: number;
        client_name: string;
        first: ShiftRow;
        second: ShiftRow;
    }>;
    timeOffConflicts: Array<{
        shift: ShiftRow;
        time_off: {
            id: number;
            user_name: string;
            type: string;
            label?: string | null;
            starts_at?: string | null;
            ends_at?: string | null;
        };
    }>;
    tightTurnarounds: Array<{
        staff_id: number;
        staff_name: string;
        gap_minutes: number;
        first: ShiftRow;
        second: ShiftRow;
    }>;
    openShifts: ShiftRow[];
    activeReplacements: Array<{
        id: number;
        shift: ShiftRow;
        status: string;
        reason: string;
        requested_by?: string | null;
        current_staff?: string | null;
        replacement_staff?: string | null;
        claimed_by?: string | null;
        open_position_id?: number | null;
    }>;
    coverageGaps: CoverageGap[];
    recurringCoverageAlignment: {
        rule_drift: Array<Record<string, unknown>>;
        orphan_series: Array<Record<string, unknown>>;
    };
};

/* ----------------------------- shared helpers ----------------------------- */

export function formatWindow(startsAt?: string | null, endsAt?: string | null) {
    if (!startsAt || !endsAt) return 'Time not set';
    const start = new Date(startsAt);
    const end = new Date(endsAt);
    return `${start.toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    })} · ${start.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    })}-${end.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    })}`;
}

export function dayLabel(value?: string | null) {
    if (!value) return 'Time not set';
    return new Date(value).toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });
}

export function timeLabel(value?: string | null) {
    if (!value) return '';
    return new Date(value).toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    });
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
    return !['review_existing_supply', 'rebalance_existing_supply'].includes(
        action ?? '',
    );
}

function coverageRecommended(gap: CoverageGap): string {
    if (gap.recommended_fill_action === 'fill_existing_open_shift') {
        return 'Demand is already represented by open shifts. Fill one of those rather than creating another.';
    }
    if (gap.recommended_fill_action === 'retag_or_replace_open_shift') {
        return 'An open shift already exists but is not carrying the right role demand. Retag it or create a role-specific cover shift.';
    }
    if (gap.unfilled_after_open_shifts && gap.unfilled_after_open_shifts > 0) {
        return `${gap.unfilled_after_open_shifts} more shift slot(s) still need to be created or reopened after existing open shifts are filled.`;
    }
    if (gap.planned_role_shortages && gap.planned_role_shortages.length > 0) {
        return 'Planned supply exists, but the required role mix is still not covered.';
    }
    if (gap.missing_staff > 0) {
        return `Need ${gap.required_staff} staff and only ${gap.assigned_staff} assigned — create cover or fill an open shift for this window.`;
    }
    return 'Current planned supply already represents this demand window.';
}

function leaveWindowLabel(
    timeOff: ConflictsProps['timeOffConflicts'][number]['time_off'],
): string {
    const typeLabel =
        timeOff.label ?? String(timeOff.type ?? 'Leave').replace(/_/g, ' ');
    if (!timeOff.starts_at) return typeLabel;
    const start = new Date(timeOff.starts_at);
    const end = timeOff.ends_at ? new Date(timeOff.ends_at) : null;
    const startDay = start.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
    if (!end) return `${typeLabel} · ${startDay}`;
    const sameMonth = start.getMonth() === end.getMonth();
    const endDay = end.toLocaleDateString('en-NZ', {
        day: 'numeric',
        ...(sameMonth ? {} : { month: 'short' }),
    });
    return `${typeLabel} · ${startDay}–${endDay}`;
}

function toQueueShift(row: ShiftRow, overrideStatus?: string): QueueShift {
    return {
        id: row.id,
        client: row.client_name ?? null,
        staff: row.staff_name ?? null,
        location: row.location ?? null,
        serviceContext: row.service_context ?? null,
        shiftType: row.shift_type ?? null,
        status: overrideStatus ?? row.status,
        startsAt: row.starts_at ?? null,
        endsAt: row.ends_at ?? null,
        seriesId: row.shift_series_id ?? null,
    };
}

/* ------------------------------- the adapter ------------------------------ */

export function buildQueue(props: ConflictsProps): QueueItem[] {
    const items: QueueItem[] = [];

    props.staffOverlaps.forEach((conflict, index) => {
        items.push({
            id: `staff_overlap-${conflict.staff_id}-${conflict.first.id}-${conflict.second.id}-${index}`,
            type: 'staff_overlap',
            severity: TYPE_META.staff_overlap.severity,
            blocking: TYPE_META.staff_overlap.blocking,
            who: conflict.staff_name,
            summary: `Double-booked · ${dayLabel(conflict.first.starts_at)}`,
            shifts: [
                toQueueShift(conflict.first),
                toQueueShift(conflict.second),
            ],
            recommended: `Reassign one of ${conflict.staff_name}'s overlapping shifts to an eligible, available colleague — or unassign and make it open.`,
            actions: ACTIONS.staff_overlap,
            payload: {
                first_shift_id: conflict.first.id,
                second_shift_id: conflict.second.id,
            },
        });
    });

    props.clientOverlaps.forEach((conflict, index) => {
        items.push({
            id: `client_overlap-${conflict.client_id}-${conflict.first.id}-${conflict.second.id}-${index}`,
            type: 'client_overlap',
            severity: TYPE_META.client_overlap.severity,
            blocking: TYPE_META.client_overlap.blocking,
            who: conflict.client_name,
            summary: `Two staff rostered at once · ${dayLabel(conflict.first.starts_at)}`,
            shifts: [
                toQueueShift(conflict.first),
                toQueueShift(conflict.second),
            ],
            recommended: `${conflict.client_name} is usually funded 1:1 — drop one shift, or confirm a 2:1 funding exception.`,
            actions: ACTIONS.client_overlap,
            payload: {
                first_shift_id: conflict.first.id,
                second_shift_id: conflict.second.id,
            },
        });
    });

    props.timeOffConflicts.forEach((conflict) => {
        items.push({
            id: `leave_clash-${conflict.shift.id}-${conflict.time_off.id}`,
            type: 'leave_clash',
            severity: TYPE_META.leave_clash.severity,
            blocking: TYPE_META.leave_clash.blocking,
            who: conflict.time_off.user_name,
            summary: `Rostered during approved ${String(conflict.time_off.type ?? 'leave').replace(/_/g, ' ')}`,
            context: {
                tone: 'crit',
                icon: CalendarX,
                text: leaveWindowLabel(conflict.time_off),
            },
            shifts: [toQueueShift(conflict.shift)],
            recommended: `${conflict.time_off.user_name} is on approved leave — reassign the shift to an available colleague or request a replacement.`,
            actions: ACTIONS.leave_clash,
            payload: {
                shift_id: conflict.shift.id,
                time_off_id: conflict.time_off.id,
            },
        });
    });

    props.tightTurnarounds.forEach((turnaround, index) => {
        items.push({
            id: `tight_turnaround-${turnaround.staff_id}-${turnaround.first.id}-${turnaround.second.id}-${index}`,
            type: 'tight_turnaround',
            severity: TYPE_META.tight_turnaround.severity,
            blocking: TYPE_META.tight_turnaround.blocking,
            who: turnaround.staff_name,
            summary: `Only ${turnaround.gap_minutes} min between back-to-back shifts`,
            context: {
                tone: 'info',
                icon: Clock,
                text: `${turnaround.gap_minutes} min between shifts · below safe turnaround`,
            },
            shifts: [
                toQueueShift(turnaround.first),
                toQueueShift(turnaround.second),
            ],
            recommended: `Travel or rest time is tight — reassign the second shift, or push its start to give a safe gap.`,
            actions: ACTIONS.tight_turnaround,
            payload: {
                first_shift_id: turnaround.first.id,
                second_shift_id: turnaround.second.id,
                gap_minutes: turnaround.gap_minutes,
            },
        });
    });

    props.coverageGaps.forEach((gap, index) => {
        const short = Math.max(0, gap.required_staff - gap.assigned_staff);
        items.push({
            id: `coverage_gap-${gap.coverage_window_key ?? `${gap.site_id}-${gap.rule_name}-${gap.window_label}`}-${index}`,
            type: 'coverage_gap',
            severity: TYPE_META.coverage_gap.severity,
            blocking: TYPE_META.coverage_gap.blocking,
            who: gap.site_name,
            summary: `${gap.rule_name} · ${gap.window_label}`,
            context: {
                tone: 'warn',
                icon: Layers,
                text: `${gap.window_label} · ${dayLabel(gap.starts_at)} — need ${gap.required_staff}, ${gap.assigned_staff} assigned (${short} short)`,
            },
            shifts: (gap.contributing_shifts ?? []).map((row) =>
                toQueueShift(row),
            ),
            recommended: coverageRecommended(gap),
            actions: ACTIONS.coverage_gap,
            payload: {
                gap,
                coverage_window_key: gap.coverage_window_key,
                starts_at: gap.starts_at,
                ends_at: gap.ends_at,
                site_id: gap.site_id,
                rule_id: gap.rule_id,
                open_shift_ids: gap.open_shift_ids ?? [],
            },
        });
    });

    props.openShifts.forEach((shift) => {
        items.push({
            id: `open_shift-${shift.id}`,
            type: 'open_shift',
            severity: TYPE_META.open_shift.severity,
            blocking: TYPE_META.open_shift.blocking,
            who: shift.location ?? shift.client_name ?? 'Open shift',
            summary: `${shift.client_name ?? 'Open shift'} · ${formatWindow(shift.starts_at, shift.ends_at)}`,
            shifts: [toQueueShift(shift, 'open')],
            recommended: `Assign an eligible, available staff member — or broadcast to the wider pool if none are free.`,
            actions: ACTIONS.open_shift,
            payload: { shift_id: shift.id },
        });
    });

    props.activeReplacements.forEach((replacement) => {
        const claiming =
            replacement.replacement_staff ?? replacement.claimed_by ?? null;
        items.push({
            id: `replacement-${replacement.id}`,
            type: 'replacement',
            severity: TYPE_META.replacement.severity,
            blocking: TYPE_META.replacement.blocking,
            who: replacement.shift.client_name ?? 'Replacement',
            summary: replacement.reason,
            context: {
                tone: 'info',
                icon: RefreshCw,
                text: `${replacement.status}${replacement.requested_by ? ` · requested by ${replacement.requested_by}` : ''}${claiming ? ` · ${claiming} claiming` : ''}`,
            },
            shifts: [toQueueShift(replacement.shift)],
            recommended: claiming
                ? `${claiming} has claimed this — approve to confirm the cover.`
                : `No claim yet — keep it on the job board, or assign someone directly.`,
            actions: ACTIONS.replacement,
            payload: {
                shift_id: replacement.shift.id,
                open_position_id: replacement.open_position_id ?? null,
                status: replacement.status,
            },
        });
    });

    return items;
}
