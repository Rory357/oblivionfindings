import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DecisionDialog } from './_change-dialogs';
import { BookReviewDialog, RecordReviewDialog } from './_review-dialogs';
import { AppointmentDialog } from './_upkeep-dialogs';
import type { Review, ReviewItem, ReviewPermissions } from './types';

const transport = vi.hoisted(() => ({
    save: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));

// Keep the real wizard, picker, validation and command hook. Only external
// transport and the unrelated PDF renderer are replaced.
vi.mock('@inertiajs/react', () => ({
    router: { reload: transport.reload, visit: transport.visit },
}));
vi.mock('./_request', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./_request')>()),
    reviewRequest: transport.save,
}));
vi.mock('@/components/files/file-preview-dialog', () => ({
    FilePreviewDialog: () => null,
}));

const today = '2026-10-04';
const asAt = '2026-10-04T09:30:00+13:00';
const defaultInterval = { months: 3, reviewed: false };
const permissions: ReviewPermissions = {
    manage: true,
    orders: true,
    controlled: true,
    summary: true,
};
const review: Review = {
    id: 701,
    client_id: 702,
    client_name: 'Synthetic person',
    site_id: 703,
    site_name: 'Synthetic house',
    review_type: 'regular',
    status: 'scheduled',
    scheduled_date: '2026-10-12',
    completed_date: null,
    owner_id: null,
    owner_name: null,
    trigger_code: null,
    trigger_reason: null,
    reviewer_name: null,
    reviewer_role: null,
    appointment_date: null,
    appointment_time: null,
    appointment_location: null,
    clinical_summary: null,
    participants: null,
    drug_burden_index: null,
    falls_last_quarter: null,
    next_review_date: null,
    next_regular_review_date: null,
    revision: 1,
    items: [],
    history: [],
    source: null,
    current_orders: [],
    cadence: { ...defaultInterval, own: false },
};
const item: ReviewItem = {
    id: 704,
    client_medication_id: 705,
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
};
const fetchSpy = vi.fn();

beforeEach(() => {
    transport.save.mockReset().mockResolvedValue({ saved: true, review_id: 701 });
    transport.reload.mockReset();
    transport.visit.mockReset();
    fetchSpy.mockReset().mockResolvedValue(
        new Response(JSON.stringify({ options: [] }), { status: 200 }),
    );
    vi.stubGlobal('fetch', fetchSpy);
});

afterEach(async () => {
    cleanup();
    vi.unstubAllGlobals();
    // Let Radix finish its focus-return cleanup between actual portal renders.
    await new Promise((resolve) => window.setTimeout(resolve, 0));
});

function continueFrom(dialog: HTMLElement) {
    fireEvent.click(within(dialog).getByRole('button', { name: /^Continue/ }));
}
function expectNoRequest() {
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(transport.save).not.toHaveBeenCalled();
    expect(transport.reload).not.toHaveBeenCalled();
    expect(transport.visit).not.toHaveBeenCalled();
}

/**
 * Run with vitest.reviews-dialogs.config.ts. Its Babel React compiler is the
 * production Vite transform: before the fix it hoisted person!.value and
 * owner!.value from Book's save callback into render-time memo dependencies.
 * Plain, uncompiled Vitest mounts would miss that production crash.
 */
describe('compiled review dialogs with incomplete picker selections', () => {
    it('opens Book with no person or owner and validates instead of saving', async () => {
        render(
            <BookReviewDialog
                action={{ type: 'book' }}
                today={today}
                onClose={vi.fn()}
            />,
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Book a medication review',
        });
        expect(within(dialog).getByRole('combobox', { name: /Person/ }))
            .toHaveTextContent('Choose someone at your houses');
        expectNoRequest();

        continueFrom(dialog);

        expect((await screen.findAllByText('Choose the person.')).length)
            .toBeGreaterThan(0);
        expect((await screen.findAllByText('Choose regular or triggered.')).length)
            .toBeGreaterThan(0);
        expect(screen.getByRole('dialog', { name: 'Book a medication review' }))
            .toBeInTheDocument();
        expectNoRequest();
    });

    it('opens Book for a preselected person, keeps owner empty and blocks continuation', async () => {
        render(
            <BookReviewDialog
                action={{
                    type: 'book',
                    clientId: review.client_id,
                    clientName: review.client_name,
                }}
                today={today}
                onClose={vi.fn()}
            />,
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Book a review — Synthetic person',
        });
        expectNoRequest();
        fireEvent.click(within(dialog).getByRole('radio', { name: /^Regular/ }));
        continueFrom(dialog);

        expect(await within(dialog).findByRole('combobox', { name: /Owner/ }))
            .toHaveTextContent('Who books it and records the outcome');
        expectNoRequest();
        continueFrom(dialog);

        expect((await screen.findAllByText('Choose who owns it.')).length)
            .toBeGreaterThan(0);
        expect((await screen.findAllByText('Choose a valid due date.')).length)
            .toBeGreaterThan(0);
        expectNoRequest();
    });

    it('opens Record with no clinician and shows the required selection', async () => {
        render(
            <RecordReviewDialog
                review={review}
                can={permissions}
                today={today}
                asAt={asAt}
                defaultInterval={defaultInterval}
                onClose={vi.fn()}
            />,
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'Record the outcome — Synthetic person',
        });
        expect(within(dialog).getByRole('combobox', { name: /Clinician/ }))
            .toHaveTextContent('Choose who did the review');
        expectNoRequest();

        continueFrom(dialog);

        expect((await screen.findAllByText('Choose who did the review.')).length)
            .toBeGreaterThan(0);
        expectNoRequest();
    });

    it('opens Appointment with no clinician and blocks continuation', async () => {
        render(<AppointmentDialog review={review} onClose={vi.fn()} />);
        const dialog = await screen.findByRole('dialog', {
            name: 'Appointment — Synthetic person',
        });
        expect(within(dialog).getByRole('combobox', { name: /Clinician/ }))
            .toHaveTextContent('Choose who did the review');
        expectNoRequest();

        continueFrom(dialog);

        expect((await screen.findAllByText('Choose the clinician.')).length)
            .toBeGreaterThan(0);
        expectNoRequest();
    });

    it('opens Decision with no prescriber and validates before any save', async () => {
        render(
            <DecisionDialog
                review={review}
                item={item}
                asAt={asAt}
                onClose={vi.fn()}
            />,
        );
        const dialog = await screen.findByRole('dialog', {
            name: 'The prescriber’s decision — Synthetic medicine',
        });
        expectNoRequest();
        continueFrom(dialog);
        expect((await screen.findAllByText('Choose what happened.')).length)
            .toBeGreaterThan(0);

        fireEvent.click(within(dialog).getByRole('radio', { name: /^Agreed/ }));
        continueFrom(dialog);
        expect(await within(dialog).findByRole('combobox', { name: /Prescriber/ }))
            .toHaveTextContent('Choose the prescriber');
        expectNoRequest();
        continueFrom(dialog);

        expect((await screen.findAllByText('Choose or enter the prescriber.')).length)
            .toBeGreaterThan(0);
        expectNoRequest();
    });
});
