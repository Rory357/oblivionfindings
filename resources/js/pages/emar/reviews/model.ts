import { formatDateOnly } from '@/lib/datetime';
import type { Review, ReviewItem, ReviewOutcome, StatusLabel } from './types';

export const OUTCOME_LABELS: Record<ReviewOutcome, string> = {
    pending_controlled: 'Outcome to add',
    continue: 'Continue',
    change: 'Change the dose or timing',
    stop: 'Stop',
    start: 'Start a medicine',
    swap: 'Swap to another medicine',
    watch: 'Watch for something',
};
export const TRIGGER_LABELS: Record<string, string> = {
    fall: 'A fall',
    hospital: 'Back from hospital',
    error: 'A medication error',
    health: 'A change in health',
    asked: 'The clinician asked for a review',
    refusals: 'Repeated refusals',
    other: 'Something else',
};
export const MOVE_LABELS: Record<string, string> = {
    clinician: 'Clinician unavailable',
    unwell: 'The person is unwell',
    hospital: 'The person is in hospital',
    whanau: 'Whānau asked to move it',
    other: 'Another reason',
};
export const LOCATION_LABELS = {
    house: 'At the house',
    practice: 'At the practice',
    phone: 'By phone',
    video: 'By video',
};
export const DECISION_LABELS = {
    writing: 'In writing',
    phone: 'By phone',
    review: 'At the review',
    person: 'In person',
};

/** Date columns stay calendar dates; this comparison never uses the browser timezone. */
export function daysBetween(date: string, today: string): number {
    if (formatDateOnly(date, '') === '' || formatDateOnly(today, '') === '')
        return Number.NaN;
    return Math.round(
        (Date.parse(`${date}T12:00:00Z`) - Date.parse(`${today}T12:00:00Z`)) /
            86_400_000,
    );
}
export function addCalendarMonths(date: string, months: number): string {
    if (!formatDateOnly(date, '') || !Number.isInteger(months)) return '';
    const [year, month, day] = date.split('-').map(Number);
    const target = new Date(Date.UTC(year, month - 1 + months, 1));
    const lastDay = new Date(
        Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0),
    ).getUTCDate();
    return `${target.getUTCFullYear()}-${String(target.getUTCMonth() + 1).padStart(2, '0')}-${String(Math.min(day, lastDay)).padStart(2, '0')}`;
}
export function reviewStatus(review: Review, today: string): StatusLabel {
    if (review.status === 'completed')
        return { label: 'Recorded', variant: 'success' };
    if (review.status === 'cancelled')
        return { label: 'Cancelled', variant: 'neutral' };
    if (review.status === 'closed')
        return { label: 'Closed', variant: 'neutral' };
    if (review.appointment_date && review.appointment_date < today)
        return { label: 'Outcome to record', variant: 'warning' };
    const days = daysBetween(review.scheduled_date, today);
    if (days < 0) return { label: 'Overdue', variant: 'critical' };
    if (days === 0) return { label: 'Due today', variant: 'warning' };
    if (days <= 30) return { label: 'Due soon', variant: 'info' };
    return { label: 'Booked', variant: 'info' };
}
export function changeStatus(item: ReviewItem): StatusLabel {
    if (item.classification_pending)
        return { label: 'Classification to check', variant: 'warning' };
    if (item.outcome === 'pending_controlled')
        return { label: 'Outcome to add', variant: 'warning' };
    if (item.outcome === 'continue')
        return { label: 'Continue', variant: 'success' };
    if (item.outcome === 'watch')
        return {
            label: item.watch_completed
                ? 'Follow-up recorded'
                : item.followup_url
                  ? 'Follow-up open'
                  : 'Follow-up to arrange',
            variant: item.watch_completed ? 'success' : 'info',
        };
    if (item.linked_order_version_id)
        return {
            label: 'Entered in Orders — check status there',
            variant: 'info',
        };
    if (item.decision === 'not_agreed')
        return { label: 'Not agreed', variant: 'neutral' };
    if (item.decision === 'agreed')
        return { label: 'Agreed — enter in Orders', variant: 'warning' };
    return { label: 'Waiting for the prescriber', variant: 'warning' };
}
export function kindLabel(review: Review): string {
    return review.review_type === 'regular' ? 'Regular' : 'Triggered';
}
export function recommendationOutcome(outcome: string): boolean {
    return ['change', 'stop', 'swap', 'start'].includes(outcome);
}
export function reviewUrl(id: number, clientId?: number): string {
    return `/emar/reviews?${new URLSearchParams({ review: String(id), ...(clientId ? { client_id: String(clientId) } : {}) })}`;
}
export function medicationRecordUrl(clientId: number): string {
    return `/emar/mar?client_id=${clientId}&tab=clinical&view=reviews`;
}
export function cadenceLabel(months: number): string {
    return `Every ${months} ${months === 1 ? 'month' : 'months'}`;
}
export function firstError(errors: Record<string, string>): void {
    const name = Object.keys(errors)[0];
    if (!name) return;
    window.requestAnimationFrame(() => {
        const target =
            document.getElementById(name) ??
            document.querySelector<HTMLElement>('[aria-invalid="true"]');
        target?.focus();
        target?.scrollIntoView({ block: 'nearest' });
    });
}

export function itemOutcomeLabel(item: ReviewItem): string {
    return item.classification_pending && item.controlled_hidden
        ? 'Classification to check'
        : OUTCOME_LABELS[item.outcome];
}
export function completionItems(
    items: import('./types').OutcomeDraft[],
    orders: import('./types').ReviewOrder[],
) {
    return items
        .filter((item) =>
            orders.some(
                (order) =>
                    order.id === item.client_medication_id &&
                    !order.controlled_hidden,
            ),
        )
        .map((item) => ({
            client_medication_id: item.client_medication_id,
            outcome: item.outcome,
            recommendation: recommendationOutcome(item.outcome)
                ? item.recommendation.trim()
                : null,
            watch_text:
                item.outcome === 'watch' ? item.watch_text.trim() : null,
            watch_until: item.outcome === 'watch' ? item.watch_until : null,
        }));
}
