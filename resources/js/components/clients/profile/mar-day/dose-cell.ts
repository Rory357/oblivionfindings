import type { StatusVariant } from '@/components/ui/status-badge';

import type { DayDose, MedicationDay } from './types';

/**
 * What a dose cell says, in Meds today's words (P00): the record's outcome
 * when one is matched, else the projection's state for that day.
 */
export type CellKind =
    | 'given'
    | 'refused'
    | 'withheld'
    | 'missed'
    | 'overdue'
    | 'not_recorded'
    | 'due_now'
    | 'due'
    | 'pending_check'
    | 'away'
    | 'upcoming';

export const CELL_META: Record<
    CellKind,
    { label: string; tone: StatusVariant; legend: string }
> = {
    due_now: {
        label: 'Due now',
        tone: 'warning',
        legend: 'Inside its time window',
    },
    due: {
        label: 'Due',
        tone: 'warning',
        legend: 'Its window opens shortly',
    },
    overdue: {
        label: 'Overdue',
        tone: 'critical',
        legend: 'Window ended today with nothing recorded',
    },
    not_recorded: {
        label: 'Not recorded',
        tone: 'critical',
        legend: 'An earlier day with nothing recorded',
    },
    given: { label: 'Given', tone: 'success', legend: 'Recorded as given' },
    refused: {
        label: 'Refused',
        tone: 'critical',
        legend: 'The person declined it',
    },
    withheld: {
        label: 'Withheld',
        tone: 'neutral',
        legend: 'Held back, with a reason',
    },
    missed: {
        label: 'Missed (recorded)',
        tone: 'critical',
        legend: 'Recorded as missed',
    },
    pending_check: {
        label: 'Waiting for the order check',
        tone: 'info',
        legend: 'The order changed and waits for its check',
    },
    away: {
        label: 'Away',
        tone: 'info',
        legend: 'The person is away, so not due',
    },
    upcoming: {
        label: 'Upcoming',
        tone: 'neutral',
        legend: 'Later in the day',
    },
};

/** Legend order: what needs doing first, then outcomes, then the rest. */
export const LEGEND_ORDER: CellKind[] = [
    'overdue',
    'due_now',
    'due',
    'upcoming',
    'given',
    'refused',
    'withheld',
    'missed',
    'not_recorded',
    'pending_check',
    'away',
];

export function cellKind(dose: DayDose, isToday: boolean): CellKind {
    switch (dose.status) {
        case 'given':
        case 'refused':
        case 'withheld':
        case 'missed':
        case 'pending_check':
        case 'away':
            return dose.status;
        case 'overdue':
            return !isToday || dose.state === 'not_recorded'
                ? 'not_recorded'
                : 'overdue';
        case 'due':
            return dose.state === 'due' ? 'due_now' : 'due';
        default:
            return 'upcoming';
    }
}

/** "8:05 am" from "08:05". */
export function clockLabel(hm: string | null | undefined): string {
    if (!hm) return '';
    const [h, m] = hm.split(':').map(Number);
    const suffix = h < 12 ? 'am' : 'pm';
    const hour = h % 12 === 0 ? 12 : h % 12;
    return `${hour}:${String(m).padStart(2, '0')} ${suffix}`;
}

export function cellLabel(dose: DayDose, kind: CellKind): string {
    if (kind === 'given' && dose.recorded?.time) {
        return `Given ✓ ${clockLabel(dose.recorded.time)}`;
    }
    if (kind === 'away' && dose.away_reason) {
        return `${CELL_META.away.label} · ${dose.away_reason}`;
    }
    return CELL_META[kind].label;
}

export const RECORDABLE_KINDS: CellKind[] = ['due_now', 'due', 'overdue'];

/** A recorded dose opens its record. */
export function isRecorded(kind: CellKind): boolean {
    return (
        kind === 'given' ||
        kind === 'refused' ||
        kind === 'withheld' ||
        kind === 'missed'
    );
}

/**
 * Why this reader can't record this dose here, or null when they can. The
 * recorder enforces the same rules; the grid only says so before anyone
 * tries.
 */
export function recordBlock(
    dose: DayDose,
    kind: CellKind,
    day: Pick<MedicationDay, 'date' | 'today' | 'can'>,
    personName: string,
): string | null {
    if (!RECORDABLE_KINDS.includes(kind)) return null;
    if (day.date !== day.today) return 'Only today’s doses are recorded here.';
    if (day.can.record_reason === 'no_permission') {
        return 'Recording needs medication recording access.';
    }
    if (!day.can.record) {
        return `Clock in to a shift covering ${personName} to record their doses.`;
    }
    if (dose.is_controlled && !day.can.record_controlled) {
        return 'Controlled doses need controlled-medicine recording access.';
    }
    return null;
}

/** The words a cell that does nothing on click shows on hover and focus. */
export function idleHint(dose: DayDose, kind: CellKind): string | null {
    switch (kind) {
        case 'upcoming':
            return dose.window_opens_at
                ? `Not due yet — its window opens at ${clockLabel(dose.window_opens_at.slice(11, 16))}.`
                : 'Not due yet.';
        case 'pending_check':
            return 'Can be recorded once the order is checked.';
        case 'away':
            return 'Not due while the person is away.';
        case 'not_recorded':
            return 'Nothing was recorded for this dose.';
        default:
            return null;
    }
}
