/**
 * Worker-facing timeline vocabulary — single source of truth.
 *
 * Frontline timelines surface a long tail of backend event types (shift_started,
 * shift_replacement_approved, medication_refused, progress_note, handover, …).
 * That taxonomy is useful in the backend and on manager/admin surfaces, but on
 * a worker-facing feed it reads like an audit log: too many distinct labels,
 * too many colours, too much to scan.
 *
 * This file collapses those backend types into a small set of plain-language
 * **worker categories** for presentation. Backend storage is untouched — raw
 * event types still flow through; this mapper just decides how each row is
 * summarised on the frontline.
 *
 * Categories were chosen to match how a support worker actually thinks about
 * their day: shifts, clinical care, medication, incidents, communication.
 * Anything that doesn't fit cleanly falls back to "Other" so nothing is lost.
 *
 * Scope:
 *   - Define the five worker-facing categories and their presentation.
 *   - Map raw backend event types → category.
 *   - Provide a small helper for the underlying event label (shown in detail).
 *
 * Not in scope:
 *   - Manager/admin timeline taxonomy — keep richer labels on those surfaces.
 *   - Renaming backend event types.
 *   - Filter UX beyond the category set (individual types can still be
 *     filtered where the surface already supports it).
 */

/** Worker-facing timeline category. */
export type TimelineCategory =
    | 'shift'
    | 'clinical'
    | 'medication'
    | 'incident'
    | 'communication'
    | 'other';

export interface TimelineCategoryEntry {
    /** Plain, short, sentence-case label shown in the row. */
    label: string;
    /** Tailwind class for the small colour dot / accent. */
    dot: string;
    /** Tailwind class for the row background + left border. */
    bg: string;
    /** Tailwind class for the subtle pill/badge background. */
    pill: string;
}

/**
 * Presentation for each worker-facing category.
 *
 * The goal is visual calm: fewer, softer accents. The category should still
 * be distinguishable, but it shouldn't feel like a rainbow of status chips.
 */
export const TIMELINE_CATEGORY_VOCAB: Record<TimelineCategory, TimelineCategoryEntry> = {
    shift: {
        label: 'Shift',
        dot: 'bg-blue-500',
        bg: 'bg-blue-50 border-l-blue-400 dark:bg-blue-950/30 dark:border-l-blue-500',
        pill: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-200',
    },
    clinical: {
        label: 'Clinical',
        dot: 'bg-violet-500',
        bg: 'bg-violet-50 border-l-violet-400 dark:bg-violet-950/30 dark:border-l-violet-500',
        pill: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-200',
    },
    medication: {
        label: 'Medication',
        dot: 'bg-emerald-500',
        bg: 'bg-emerald-50 border-l-emerald-400 dark:bg-emerald-950/30 dark:border-l-emerald-500',
        pill: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-200',
    },
    incident: {
        label: 'Incident',
        dot: 'bg-red-500',
        bg: 'bg-red-50 border-l-red-400 dark:bg-red-950/30 dark:border-l-red-500',
        pill: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-200',
    },
    communication: {
        label: 'Communication',
        dot: 'bg-amber-500',
        bg: 'bg-amber-50 border-l-amber-400 dark:bg-amber-950/30 dark:border-l-amber-500',
        pill: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-200',
    },
    other: {
        label: 'Other',
        dot: 'bg-slate-400',
        bg: 'bg-card border-l-slate-300 dark:border-l-slate-600',
        pill: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    },
};

/**
 * Ordered list of worker-facing categories. Useful for filter dropdowns and
 * iterating in a stable order.
 */
export const TIMELINE_CATEGORY_ORDER: TimelineCategory[] = [
    'shift',
    'clinical',
    'medication',
    'incident',
    'communication',
    'other',
];

function normalize(value: string | null | undefined): string {
    return (value ?? '').toString().trim().toLowerCase().replace(/[\s-]+/g, '_');
}

/**
 * Collapse a raw backend event type into a worker-facing category.
 *
 * Rules (roughly):
 *   - Anything shift/replacement related → shift.
 *   - Any medication event → medication.
 *   - Incidents → incident.
 *   - Notes (progress/shift/plain), handovers, care-plan, conditions,
 *     appointments, assessments, clinical documents, photos → clinical
 *     (the things a worker thinks of as "care recorded").
 *   - Family notes + visit_* → communication (things that involve the
 *     family / external parties).
 *   - Anything unknown → other.
 *
 * Unknown or oddly-namespaced types fall back to "other" rather than being
 * dropped. The underlying detail (subject/body) is still rendered, so nothing
 * meaningful is lost.
 */
export function categorizeTimelineType(
    rawType: string | null | undefined,
): TimelineCategory {
    const t = normalize(rawType);
    if (!t) return 'other';

    if (t === 'incident' || t.startsWith('incident_')) return 'incident';
    if (t === 'medication' || t.startsWith('medication_') || t.startsWith('med_')) {
        return 'medication';
    }
    if (t === 'shift' || t.startsWith('shift_')) {
        // shift_note is a clinical record attached to a shift, not a shift-state change.
        if (t === 'shift_note') return 'clinical';
        return 'shift';
    }
    if (t.startsWith('family_note') || t.startsWith('visit_')) {
        return 'communication';
    }
    if (
        t === 'note' ||
        t === 'progress_note' ||
        t === 'handover' ||
        t === 'condition_added' ||
        t === 'care_plan_created' ||
        t === 'appointment_scheduled' ||
        t === 'document_uploaded' ||
        t === 'photo_uploaded' ||
        t === 'assessment' ||
        t.startsWith('care_plan_') ||
        t.startsWith('assessment_') ||
        t.startsWith('observation')
    ) {
        return 'clinical';
    }
    return 'other';
}

/** Look up the presentation entry for a worker-facing category. */
export function getTimelineCategoryEntry(
    category: TimelineCategory,
): TimelineCategoryEntry {
    return TIMELINE_CATEGORY_VOCAB[category];
}

/**
 * Short, human-readable label for the *underlying* raw event type.
 *
 * This is intentionally quieter than the old 20-plus label table: it is used
 * in the detail/subtitle area, not as the primary row label. The primary row
 * label is the category. This second label preserves useful meaning when
 * `subject` is missing (e.g. "Medication Refused", "Replacement Approved")
 * without reintroducing a noisy per-type colour scheme.
 */
const EVENT_DETAIL_LABELS: Record<string, string> = {
    shift: 'Shift',
    shift_started: 'Shift started',
    shift_completed: 'Shift completed',
    shift_cancelled: 'Shift cancelled',
    shift_replacement_requested: 'Replacement requested',
    shift_replacement_claimed: 'Replacement claimed',
    shift_replacement_approved: 'Replacement approved',
    shift_replacement_cancelled: 'Replacement cancelled',
    note: 'Note',
    shift_note: 'Shift note',
    progress_note: 'Progress note',
    handover: 'Handover',
    incident: 'Incident',
    medication_given: 'Medication given',
    medication_refused: 'Medication refused',
    medication_missed: 'Medication missed',
    medication_prescribed: 'Medication added',
    medication_correction: 'Correction',
    document_uploaded: 'Document',
    condition_added: 'Condition',
    care_plan_created: 'Care plan',
    appointment_scheduled: 'Appointment',
    visit_requested: 'Visit requested',
    visit_approved: 'Visit approved',
    visit_cancelled: 'Visit cancelled',
    photo_uploaded: 'Photo',
    family_note_created: 'Family note',
    family_note_completed: 'Family note done',
};

export function getEventDetailLabel(
    rawType: string | null | undefined,
): string {
    const t = normalize(rawType);
    if (!t) return '';
    if (EVENT_DETAIL_LABELS[t]) return EVENT_DETAIL_LABELS[t];
    // Fallback: turn "some_backend_type" into "Some backend type".
    const spaced = t.replace(/_/g, ' ');
    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}
