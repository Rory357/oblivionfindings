import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { MedicationFollowupDialog } from './followup-dialog';
import type { MedicationFollowup } from './types';

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    load: () => null,
    clear: vi.fn(),
    flush: vi.fn(),
}));
vi.mock('axios', () => ({ default: { get: mocks.get } }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: { user: { id: 7 }, can: { medications: { view: true } } },
        },
    }),
    Link: () => null,
}));
vi.mock('@/hooks/use-form-autosave', () => ({
    useFormAutosave: () => ({
        load: mocks.load,
        clear: mocks.clear,
        flush: mocks.flush,
        savedAt: null,
    }),
}));
vi.mock('@/hooks/use-offline-queue', () => ({
    useOfflineQueueState: () => ({
        pendingSubmissions: [],
        rejectedSubmissions: [],
    }),
}));

const reason =
    'This correction changed the dose evidence. The original colleague confirmation does not verify this corrected dose. A house lead must review it.';
const row: MedicationFollowup = {
    id: 91,
    type: 'unconfirmed',
    label: 'Unconfirmed dose',
    client: { id: 16, name: 'Synthetic person' },
    site: { id: 5, name: 'Synthetic house' },
    medication: { id: 61, name: 'Synthetic medicine' },
    administration_id: 72,
    owner: null,
    original_owner: null,
    due_at: null,
    completed_at: null,
    state: 'open',
    revision: 1,
    context: { reason },
    lead: true,
    source_owned: false,
    source_url: null,
    can_complete: true,
    can_reassign: false,
    shift_end: null,
    why: null,
    record_url: null,
    url: '/medication-followups?open=91',
    history: [],
};

beforeEach(() => {
    mocks.get.mockReset();
    mocks.get.mockResolvedValue({ data: row });
});
afterEach(cleanup);

it('explains why a corrected dose needs lead review before the decision and in its history', async () => {
    render(<MedicationFollowupDialog id={91} onClose={vi.fn()} />);
    expect(await screen.findByText(reason)).toBeVisible();
    expect(
        screen.getByLabelText('What was reviewed and decided?'),
    ).toBeVisible();
    expect(
        screen.queryByText('Confirmed in their own account'),
    ).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Details and history' }),
    );
    expect(screen.getByText(reason)).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Next action' }));
    expect(screen.getByText(reason)).toBeVisible();
});

it('keeps permission explanations without inventing a missing clinical review reason', async () => {
    mocks.get.mockResolvedValue({
        data: {
            ...row,
            context: {},
            can_complete: false,
            why: 'Only the current house lead can finish this review.',
        },
    });
    render(<MedicationFollowupDialog id={91} onClose={vi.fn()} />);
    expect(
        await screen.findByText(
            'Only the current house lead can finish this review.',
        ),
    ).toBeVisible();
    expect(screen.queryByText('Reason for review')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Save follow-up' }),
    ).not.toBeInTheDocument();
});
