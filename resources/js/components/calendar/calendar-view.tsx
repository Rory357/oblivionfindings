import type { CalendarOptions, PluginDef } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { useEffect, type Ref } from 'react';

/**
 * Shared FullCalendar theming, extracted verbatim from the My Calendar reference
 * (resources/js/pages/my-calendar.tsx) so every FullCalendar surface — My
 * Calendar, the HR company calendar — renders identically. The hardcoded purple
 * grid lines and red now-indicator from the original were swapped for the
 * --primary / --status-critical design tokens.
 */
const CALENDAR_STYLES = `
/* ── Reset: kill ALL FullCalendar default borders ──────────────────────── */
.fc { --fc-border-color: transparent; --fc-today-bg-color: transparent; --fc-neutral-bg-color: transparent; --fc-page-bg-color: transparent; --fc-non-business-color: transparent; font-family: inherit; }
.fc .fc-scrollgrid, .fc .fc-scrollgrid-section > td, .fc .fc-scrollgrid-section > th { border: none !important; }
.fc table, .fc th, .fc td { border: none !important; }

/* ── Column headers: day name + large number ───────────────────────────── */
.fc .fc-col-header { margin-bottom: 0.25rem; }
.fc .fc-col-header-cell { padding: 0.75rem 0; vertical-align: middle; }
.fc .fc-col-header-cell-cushion {
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    text-decoration: none !important; padding: 0.5rem 1rem; border-radius: 1rem;
}
.fc .fc-col-header-cell-cushion .fc-col-header-cell-content,
.fc .fc-col-header-cell-cushion { font-weight: 500; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; color: hsl(var(--muted-foreground) / 0.6); }
.fc .fc-day-today .fc-col-header-cell-cushion {
    background: hsl(var(--primary)); color: hsl(var(--primary-foreground)) !important; border-radius: 1rem; font-weight: 700;
}

/* ── Time grid: clean minimal lines ────────────────────────────────────── */
.fc .fc-timegrid-axis-cushion, .fc .fc-timegrid-slot-label-cushion {
    font-size: 0.7rem; font-weight: 500; color: hsl(var(--muted-foreground) / 0.45); padding-right: 0.75rem;
}
.fc .fc-timegrid-slot { height: 3.5em; }
.fc .fc-timegrid-slot-lane { border-top: 1px dotted hsl(var(--primary) / 0.12) !important; }
.fc .fc-timegrid-slot-minor { border-top: 1px dotted hsl(var(--primary) / 0.06) !important; }
.fc .fc-timegrid-col { border-right: 1px dotted hsl(var(--primary) / 0.1) !important; }
.fc .fc-timegrid-col:last-child { border-right: none !important; }
.fc .fc-timegrid-divider { display: none; }
.fc .fc-timegrid-axis { border: none !important; }
.fc .fc-timegrid-body { border: none !important; }
.fc .fc-timegrid-slots td { border: none !important; }
.fc .fc-timegrid-slots tr:not(:first-child) .fc-timegrid-slot-lane { border-top: 1px solid hsl(var(--border) / 0.1) !important; }
.fc .fc-timegrid-slot-label { border: none !important; }

/* ── Events: colorful rounded pastel blocks ────────────────────────────── */
.fc .fc-event, .fc .fc-event-mirror {
    border: none !important; border-radius: 0.625rem !important;
    cursor: pointer; transition: all 0.15s ease; overflow: hidden;
}
.fc .fc-event:hover { transform: scale(1.01); z-index: 10 !important; box-shadow: 0 4px 16px rgba(0,0,0,0.1); }
.fc .fc-timegrid-event { border-radius: 0.625rem !important; margin: 1px 2px; min-height: 1.5em; }
.fc .fc-timegrid-event .fc-event-main { padding: 0.375rem 0.5rem; }
.fc .fc-daygrid-event { border-radius: 0.5rem !important; padding: 2px 8px; margin: 1px 3px; }

/* ── All-day slot ──────────────────────────────────────────────────────── */
.fc .fc-daygrid-body { border: none !important; }
.fc .fc-scrollgrid-section-header td { border-bottom: 1px solid hsl(var(--border) / 0.15) !important; }

/* ── Drag-to-select highlight ──────────────────────────────────────────── */
.fc .fc-highlight {
    background: hsl(var(--primary) / 0.06) !important;
    border: 2px dashed hsl(var(--primary) / 0.25) !important;
    border-radius: 0.625rem;
}

/* ── Now indicator ─────────────────────────────────────────────────────── */
.fc .fc-now-indicator-line { border-color: hsl(var(--status-critical)) !important; border-width: 2px !important; z-index: 4; }
.fc .fc-now-indicator-arrow { border-color: hsl(var(--status-critical)) !important; border-width: 5px !important; }

/* ── Today highlight ───────────────────────────────────────────────────── */
.fc .fc-day-today { background: hsl(var(--primary) / 0.02) !important; }

/* ── Month view day numbers ────────────────────────────────────────────── */
.fc .fc-daygrid-day-number { font-weight: 700; font-size: 0.9rem; padding: 0.5rem; color: hsl(var(--foreground)); }
.fc .fc-day-today .fc-daygrid-day-number {
    background: hsl(var(--primary)); color: hsl(var(--primary-foreground)); border-radius: 9999px;
    width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; margin: 0.25rem;
}
.fc .fc-daygrid-day { border-right: 1px dotted hsl(var(--primary) / 0.1) !important; border-bottom: 1px dotted hsl(var(--primary) / 0.1) !important; }

/* ── More link ─────────────────────────────────────────────────────────── */
.fc .fc-more-link { font-size: 0.7rem; font-weight: 600; color: hsl(var(--primary)); }

/* ── List view ─────────────────────────────────────────────────────────── */
.fc .fc-list { border: 1px solid hsl(var(--border) / 0.2) !important; border-radius: 1rem; overflow: hidden; }
.fc .fc-list-event:hover td { background-color: hsl(var(--accent)); }
.fc .fc-list-day-cushion { background: hsl(var(--muted) / 0.15); font-weight: 600; }

/* ── Non-business hours subtle stripe ──────────────────────────────────── */
.fc .fc-non-business { background: hsl(var(--muted) / 0.03) !important; }

/* ── Dark mode ─────────────────────────────────────────────────────────── */
.dark .fc .fc-event:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.3); }
`;

const STYLE_ELEMENT_ID = 'calendar-view-styles';
const DEFAULT_PLUGINS: PluginDef[] = [
    dayGridPlugin,
    timeGridPlugin,
    listPlugin,
    interactionPlugin,
];

/** Inject the shared FullCalendar theming once per document. */
function useCalendarStyles(): void {
    useEffect(() => {
        if (document.getElementById(STYLE_ELEMENT_ID)) {
            return;
        }
        const el = document.createElement('style');
        el.id = STYLE_ELEMENT_ID;
        el.textContent = CALENDAR_STYLES;
        document.head.appendChild(el);
    }, []);
}

export type CalendarViewProps = CalendarOptions & {
    /** Override the default plugin set (dayGrid · timeGrid · list · interaction). */
    plugins?: PluginDef[];
    /** Forwarded to the underlying FullCalendar instance (e.g. for the API). */
    calendarRef?: Ref<FullCalendar>;
};

/**
 * Thin, themed wrapper around FullCalendar. Centralises the plugin set, the
 * shared design-token theming, and en-NZ defaults (Monday-first, auto height).
 * Everything is overridable — pass any FullCalendar option straight through.
 */
export function CalendarView({
    plugins,
    calendarRef,
    ...options
}: CalendarViewProps) {
    useCalendarStyles();

    return (
        <div className="fc-themed">
            <FullCalendar
                ref={calendarRef}
                plugins={plugins ?? DEFAULT_PLUGINS}
                firstDay={1}
                height="auto"
                {...options}
            />
        </div>
    );
}

export default CalendarView;
