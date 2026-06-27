/**
 * Shared feed contract for the unified HR calendar (`/hr/calendar`).
 *
 * One `HrCalendarAggregator` (PHP) emits a flat list of `CalendarLayerFeed`
 * entries — one per visible item across every active layer — and the React page
 * groups them into FullCalendar `eventSource`s by `layer`. The same shape is the
 * single contract the Leave hub's Calendar tab and this page agree on, so the two
 * surfaces never diverge.
 *
 * Colours are token *references* (CSS custom-property names), never raw hex —
 * the page resolves them to `hsl(var(--token))` / `color-mix()` tints at render.
 */

export type CalendarLayer =
    | 'event'
    | 'leave'
    | 'shift'
    | 'holiday'
    | 'compliance'
    | 'milestone';

export interface CalendarLayerFeed {
    layer: CalendarLayer;
    id: string;
    title: string;
    /** ISO-8601 start. */
    start: string;
    /** ISO-8601 end. */
    end: string;
    allDay: boolean;
    /** Token name (e.g. `category-hr`, `status-neutral`) — resolved client-side. */
    color: string;
    /** True only for `layer === 'event'`; every other layer is read-only here. */
    editable: boolean;
    /** Where a read-only entry opens its real editor (`/hr/leave`, `/operations/rostering`, …). */
    deepLink?: string;
    extendedProps: {
        site?: string | null;
        department?: string | null;
        person?: string | null;
        /** Event category key (matches `hr_calendar_event_categories.key`). */
        category?: string | null;
        /** Leave: request is pending (render dashed). */
        pending?: boolean;
        /** Shift: coverage gap window. */
        gap?: boolean;
        /** Compliance: how close the renewal is. */
        urgency?: 'warning' | 'critical';
        attendeeCount?: number;
        recurring?: boolean;
        /** Leave: a sensitive reason was hidden from this viewer. */
        redacted?: boolean;
        /** Free-form per-layer detail for the popover (location, notes, meta…). */
        [key: string]: unknown;
    };
}

/** The six layers in canonical render order (background layers first). */
export const CALENDAR_LAYERS: readonly CalendarLayer[] = [
    'holiday',
    'shift',
    'leave',
    'compliance',
    'milestone',
    'event',
] as const;

/** Display order for UI lists (rail + legend) — editable HR events lead. */
export const LAYER_DISPLAY_ORDER: readonly CalendarLayer[] = [
    'event',
    'leave',
    'shift',
    'holiday',
    'compliance',
    'milestone',
] as const;

/** Layers shown by default; compliance + milestones start hidden. */
export const DEFAULT_ACTIVE_LAYERS: readonly CalendarLayer[] = [
    'event',
    'leave',
    'shift',
    'holiday',
] as const;

export interface CalendarLayerMeta {
    layer: CalendarLayer;
    label: string;
    /** Token name for the legend swatch / event accent. */
    color: string;
    editable: boolean;
    /** Default visibility. */
    on: boolean;
}

export const LAYER_META: Record<CalendarLayer, CalendarLayerMeta> = {
    event: { layer: 'event', label: 'Company & HR events', color: 'category-hr', editable: true, on: true },
    leave: { layer: 'leave', label: "Leave / who's off", color: 'status-neutral', editable: false, on: true },
    shift: { layer: 'shift', label: 'Shifts & coverage', color: 'live', editable: false, on: true },
    holiday: { layer: 'holiday', label: 'Public holidays', color: 'status-warning', editable: false, on: true },
    compliance: { layer: 'compliance', label: 'Compliance renewals', color: 'status-critical', editable: false, on: false },
    milestone: { layer: 'milestone', label: 'People milestones', color: 'category-finance', editable: false, on: false },
};
