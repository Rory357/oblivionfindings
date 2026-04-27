/**
 * Worker-facing status vocabulary — single source of truth.
 *
 * This file defines the *presentation layer* for frontline/staff-facing
 * statuses. Backend / database statuses are intentionally NOT renamed here:
 * we simply collapse the richer backend vocabulary into a small set of
 * plain-language worker-facing states per concept.
 *
 * Scope of this file:
 *   - Define the worker-facing states for shift, timesheet, med, incident.
 *   - Provide tone + label + icon for each state (icon + text + color).
 *   - Provide mappers from backend statuses → worker-facing states.
 *
 * NOT in scope:
 *   - Manager / admin status systems.
 *   - Backend status renames.
 *   - App-wide badge rewrites.
 */
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    CircleDashed,
    Clock,
    FileEdit,
    PauseCircle,
    PlayCircle,
    Send,
    XCircle,
    type LucideIcon,
} from 'lucide-react';

/**
 * Visual tone buckets for worker-facing statuses.
 *
 * These are the only tones the `<StaffStatus>` primitive supports — keep this
 * list tight. Adding a new tone should be a deliberate design-system decision,
 * not a one-off.
 */
export type StaffStatusTone =
    | 'neutral'
    | 'info'
    | 'progress'
    | 'success'
    | 'warning'
    | 'danger';

/** Worker-facing status kinds supported by `<StaffStatus>`. */
export type StaffStatusKind = 'shift' | 'timesheet' | 'med' | 'incident';

/** Simplified worker-facing states, per kind. */
export type ShiftState =
    | 'upcoming'
    | 'starting-soon'
    | 'active'
    | 'on-break'
    | 'ending-soon'
    | 'late'
    | 'completed'
    | 'missed'
    | 'returned-timesheet';
export type TimesheetState = 'not_sent' | 'sent' | 'needs_changes';
export type MedState = 'due' | 'given' | 'missed';
export type IncidentState = 'draft' | 'submitted' | 'action_needed';

/**
 * Mapping from kind → its worker-facing state union.
 * Used to type `<StaffStatus>` so that `kind` and `state` stay in sync.
 */
export type StaffStatusStateMap = {
    shift: ShiftState;
    timesheet: TimesheetState;
    med: MedState;
    incident: IncidentState;
};

/** A single rendered status entry. */
export interface StaffStatusEntry {
    /** Plain-language worker-facing label. Short, sentence-case. */
    label: string;
    /** Icon reinforcing meaning (decorative — label carries the meaning). */
    icon: LucideIcon;
    /** Visual tone bucket for color. */
    tone: StaffStatusTone;
}

/**
 * Worker-facing vocabulary.
 *
 * Label copy rules:
 *   - Plain language, no jargon.
 *   - Short enough to fit on a mobile pill.
 *   - Sentence-case ("Needs your changes", not "NEEDS YOUR CHANGES").
 */
export const staffStatusVocab: {
    shift: Record<ShiftState, StaffStatusEntry>;
    timesheet: Record<TimesheetState, StaffStatusEntry>;
    med: Record<MedState, StaffStatusEntry>;
    incident: Record<IncidentState, StaffStatusEntry>;
} = {
    shift: {
        upcoming: {
            label: 'Upcoming',
            icon: CalendarClock,
            tone: 'info',
        },
        'starting-soon': {
            label: 'Starting soon',
            icon: Clock,
            tone: 'warning',
        },
        active: {
            label: 'Active',
            icon: PlayCircle,
            tone: 'progress',
        },
        'on-break': {
            label: 'On break',
            icon: PauseCircle,
            tone: 'warning',
        },
        'ending-soon': {
            label: 'Ending soon',
            icon: Clock,
            tone: 'warning',
        },
        late: {
            label: 'Late',
            icon: AlertTriangle,
            tone: 'danger',
        },
        completed: {
            label: 'Completed',
            icon: CheckCircle2,
            tone: 'success',
        },
        missed: {
            label: 'Missed',
            icon: XCircle,
            tone: 'danger',
        },
        'returned-timesheet': {
            label: 'Timesheet returned',
            icon: FileEdit,
            tone: 'warning',
        },
    },
    timesheet: {
        not_sent: {
            label: 'Not sent',
            icon: CircleDashed,
            tone: 'neutral',
        },
        sent: {
            label: 'Sent',
            icon: Send,
            tone: 'info',
        },
        needs_changes: {
            label: 'Needs your changes',
            icon: AlertTriangle,
            tone: 'warning',
        },
    },
    med: {
        due: {
            label: 'Due',
            icon: PauseCircle,
            tone: 'warning',
        },
        given: {
            label: 'Given',
            icon: CheckCircle2,
            tone: 'success',
        },
        missed: {
            label: 'Missed',
            icon: XCircle,
            tone: 'danger',
        },
    },
    incident: {
        draft: {
            label: 'Draft',
            icon: FileEdit,
            tone: 'neutral',
        },
        submitted: {
            label: 'Submitted',
            icon: Send,
            tone: 'info',
        },
        action_needed: {
            label: 'Action needed',
            icon: AlertTriangle,
            tone: 'warning',
        },
    },
};

/* -------------------------------------------------------------------------- */
/*  Backend → worker-facing mappers                                           */
/* -------------------------------------------------------------------------- */
/*
 * These mappers intentionally collapse richer backend vocabularies into the
 * simplified worker-facing set. They do NOT rename any backend values — they
 * exist purely at the presentation boundary.
 *
 * Each mapper returns `null` for an unknown input. Callers decide whether to
 * fall back to a neutral badge, hide the chip, or render the raw value.
 */

function normalize(value: string | null | undefined): string {
    return (value ?? '')
        .toString()
        .trim()
        .toLowerCase()
        .replace(/[\s-]+/g, '_');
}

/** Map a backend shift status into a worker-facing shift state. */
export function mapShiftStatus(
    backend: string | null | undefined,
): ShiftState | null {
    switch (normalize(backend)) {
        case 'draft':
        case 'scheduled':
        case 'upcoming':
        case 'pending':
            return 'upcoming';
        case 'starting_soon':
        case 'starts_soon':
        case 'soon':
            return 'starting-soon';
        case 'in_progress':
        case 'active':
        case 'clocked_in':
        case 'started':
            return 'active';
        case 'on_break':
        case 'break':
            return 'on-break';
        case 'ending_soon':
        case 'ends_soon':
        case 'wrapping_up':
            return 'ending-soon';
        case 'late':
        case 'started_late':
            return 'late';
        case 'completed':
        case 'done':
        case 'clocked_out':
        case 'finished':
            return 'completed';
        case 'missed':
        case 'no_show':
            return 'missed';
        case 'returned_timesheet':
        case 'timesheet_returned':
        case 'returned':
            return 'returned-timesheet';
        default:
            return null;
    }
}

/** Map a backend timesheet status into a worker-facing timesheet state. */
export function mapTimesheetStatus(
    backend: string | null | undefined,
): TimesheetState | null {
    switch (normalize(backend)) {
        case 'draft':
        case 'not_sent':
        case 'not_submitted':
            return 'not_sent';
        case 'submitted':
        case 'sent':
        case 'approved':
            return 'sent';
        case 'returned':
        case 'rejected':
        case 'needs_changes':
        case 'changes_requested':
            return 'needs_changes';
        default:
            return null;
    }
}

/** Map a backend med/eMAR status into a worker-facing med state. */
export function mapMedStatus(
    backend: string | null | undefined,
): MedState | null {
    switch (normalize(backend)) {
        case 'due':
        case 'scheduled':
        case 'pending':
        case 'upcoming':
            return 'due';
        case 'given':
        case 'administered':
        case 'taken':
        case 'completed':
            return 'given';
        case 'missed':
        case 'refused':
        case 'omitted':
        case 'not_given':
        case 'overdue':
            return 'missed';
        default:
            return null;
    }
}

/** Map a backend incident status into a worker-facing incident state. */
export function mapIncidentStatus(
    backend: string | null | undefined,
): IncidentState | null {
    switch (normalize(backend)) {
        case 'draft':
            return 'draft';
        case 'submitted':
        case 'open':
        case 'under_review':
        case 'investigating':
            return 'submitted';
        case 'action_needed':
        case 'corrective_action':
        case 'returned':
        case 'needs_action':
            return 'action_needed';
        default:
            return null;
    }
}

/**
 * Convenience dispatcher — map any backend status for any supported kind.
 * Returns `null` when the input cannot be safely collapsed.
 */
export function mapBackendStatus<K extends StaffStatusKind>(
    kind: K,
    backend: string | null | undefined,
): StaffStatusStateMap[K] | null {
    switch (kind) {
        case 'shift':
            return mapShiftStatus(backend) as StaffStatusStateMap[K] | null;
        case 'timesheet':
            return mapTimesheetStatus(backend) as StaffStatusStateMap[K] | null;
        case 'med':
            return mapMedStatus(backend) as StaffStatusStateMap[K] | null;
        case 'incident':
            return mapIncidentStatus(backend) as StaffStatusStateMap[K] | null;
        default:
            return null;
    }
}

/** Look up the presentation entry for a given kind + worker-facing state. */
export function getStaffStatusEntry<K extends StaffStatusKind>(
    kind: K,
    state: StaffStatusStateMap[K],
): StaffStatusEntry {
    const bucket = staffStatusVocab[kind] as Record<string, StaffStatusEntry>;
    return bucket[state as string];
}
