import { describe, expect, it } from 'vitest';
import {
    addCalendarMonths,
    changeStatus,
    completionItems,
    daysBetween,
    itemOutcomeLabel,
    reviewStatus,
} from './model';
import type { OutcomeDraft, Review, ReviewItem, ReviewOrder } from './types';

const item = (patch: Partial<ReviewItem> = {}): ReviewItem => ({
    id: 1,
    client_medication_id: 5,
    name: 'Synthetic medicine',
    controlled_hidden: false,
    outcome: 'change',
    recommendation: 'Synthetic recommendation',
    watch_until: null,
    watch_text: null,
    decision: 'waiting',
    prescriber_name: null,
    decision_date: null,
    decision_method: null,
    decision_note: null,
    order_url: null,
    linked_order_version_id: null,
    followup_url: null,
    ...patch,
});
describe('review calendar dates and truthful statuses', () => {
    it('clamps calendar month cadence at month ends and preserves leap years', () => {
        expect(addCalendarMonths('2026-01-31', 1)).toBe('2026-02-28');
        expect(addCalendarMonths('2028-01-31', 1)).toBe('2028-02-29');
        expect(addCalendarMonths('2026-10-31', 3)).toBe('2027-01-31');
        expect(addCalendarMonths('2026-02-30', 3)).toBe('');
    });
    it('compares date-only values independently of NZ daylight saving transitions', () => {
        expect(daysBetween('2026-09-28', '2026-09-27')).toBe(1);
        expect(daysBetween('2026-04-06', '2026-04-05')).toBe(1);
        expect(daysBetween('2026-10-02', '2026-10-03')).toBe(-1);
    });
    it('shows a controlled pending outcome without marking it Continue', () => {
        const pending = item({
            controlled_hidden: true,
            outcome: 'pending_controlled',
        });
        expect(changeStatus(pending).label).toBe('Outcome to add');
        expect(itemOutcomeLabel(pending)).toBe('Outcome to add');
    });
    it('does not infer a clinical classification or a checked order from a link', () => {
        expect(changeStatus(item({ classification_pending: true })).label).toBe(
            'Classification to check',
        );
        expect(
            itemOutcomeLabel(
                item({ classification_pending: true, controlled_hidden: true }),
            ),
        ).toBe('Classification to check');
        expect(
            changeStatus(
                item({ decision: 'agreed', linked_order_version_id: 18 }),
            ).label,
        ).toBe('Entered in Orders — check status there');
    });
    it('only calls a watch recorded with canonical completion proof', () => {
        expect(
            changeStatus(
                item({
                    outcome: 'watch',
                    followup_url: '/tasks?open=synthetic',
                }),
            ).label,
        ).toBe('Follow-up open');
        expect(
            changeStatus(
                item({
                    outcome: 'watch',
                    followup_url: '/tasks?open=synthetic',
                    watch_completed: true,
                }),
            ).label,
        ).toBe('Follow-up recorded');
    });
    it('distinguishes an appointment outcome from a future booked review', () => {
        const review = {
            status: 'scheduled',
            scheduled_date: '2026-10-04',
            appointment_date: '2026-10-02',
        } as Review;
        expect(reviewStatus(review, '2026-10-03').label).toBe(
            'Outcome to record',
        );
    });
});
describe('review completion payload', () => {
    it('omits hidden and removed order IDs so the server creates controlled pending outcomes', () => {
        const orders: ReviewOrder[] = [
            {
                id: 5,
                name: 'Synthetic A',
                dosage: null,
                frequency: null,
                controlled_hidden: false,
            },
            {
                id: 6,
                name: 'Controlled medicine',
                dosage: null,
                frequency: null,
                controlled_hidden: true,
            },
        ];
        const drafts: OutcomeDraft[] = [
            {
                client_medication_id: 5,
                outcome: 'change',
                recommendation: '  Synthetic change  ',
                watch_text: 'Old watch text',
                watch_until: '2026-10-20',
            },
            {
                client_medication_id: 6,
                outcome: 'pending_controlled',
                recommendation: '',
                watch_text: '',
                watch_until: '',
            },
            {
                client_medication_id: 7,
                outcome: 'continue',
                recommendation: '',
                watch_text: '',
                watch_until: '',
            },
        ];
        expect(completionItems(drafts, orders)).toEqual([
            {
                client_medication_id: 5,
                outcome: 'change',
                recommendation: 'Synthetic change',
                watch_text: null,
                watch_until: null,
            },
        ]);
    });
});
