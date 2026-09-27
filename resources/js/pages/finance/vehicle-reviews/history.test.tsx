import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { ReviewRequestDialog, type ReviewRequest } from './_dialogs';

vi.mock('@/components/finance', () => ({
    formatMoney: (amount: number) => `$${amount.toFixed(2)}`,
}));
vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    useForm: (data: unknown) => ({
        data,
        errors: {},
        processing: false,
        clearErrors: vi.fn(),
        setError: vi.fn(),
        setData: vi.fn(),
        post: vi.fn(),
    }),
}));
const record = {
    id: 9,
    reference: 'FRQ-9',
    type_label: 'Invoice review',
    status: 'resolved',
    status_label: 'Resolved',
    tone: 'success',
    vehicle: {
        id: 7,
        name: 'Van',
        registration: 'DEMO',
        site: 'Approved site',
        url: null,
    },
    amount: 500,
    note: 'Check invoice',
    source: 'Bill',
    requested_by: 'Worker',
    requested_at: null,
    decided_by: 'Reviewer',
    decided_at: null,
    decision_note: 'Checked',
    lock_version: 2,
    files: [],
    history: [
        {
            id: 11,
            label: 'Resolved by Finance',
            actor: 'Reviewer',
            note: 'Latest event',
            occurred_at: null,
        },
    ],
    history_next_before: 11,
    own_request: false,
    can_decide: false,
} satisfies ReviewRequest;
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it.each([{}, { saved: false }, { saved: 'yes' }])(
    'keeps an unconfirmed decision recoverable (%j)',
    async (malformed) => {
        const send = vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => malformed,
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ saved: true }),
            });
        vi.stubGlobal('fetch', send);
        render(
            <ReviewRequestDialog
                request={{
                    ...record,
                    status: 'submitted',
                    status_label: 'Ready',
                    can_decide: true,
                    evidence_token: 'reviewed-evidence',
                }}
                onClose={() => {}}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Decision/ }));
        fireEvent.click(screen.getByRole('button', { name: /^Resolve/ }));
        fireEvent.change(screen.getByLabelText('Decision note *'), {
            target: { value: 'The invoice matches the quote.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Record decision' }),
        );
        const retry = await screen.findByRole('button', {
            name: 'Retry this submission',
        });
        expect(screen.getByLabelText('Decision note *')).toBeDisabled();
        fireEvent.click(retry);
        await screen.findByText('Decision recorded');
        expect(send.mock.calls[1][1].body).toBe(send.mock.calls[0][1].body);
        expect(send.mock.calls[1][1].headers['Idempotency-Key']).toBe(
            send.mock.calls[0][1].headers['Idempotency-Key'],
        );
    },
);

it('loads older events on demand, preserves existing history and hides the control at the end', async () => {
    const fetchHistory = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({
            history: [
                {
                    id: 1,
                    label: 'Submitted to Finance',
                    actor: 'Worker',
                    note: 'Original event',
                    occurred_at: null,
                },
            ],
            history_next_before: null,
        }),
    });
    vi.stubGlobal('fetch', fetchHistory);
    render(<ReviewRequestDialog request={record} onClose={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: /Evidence/ }));
    expect(fetchHistory).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Load older history' }));
    await screen.findByText('Original event');
    expect(fetchHistory).toHaveBeenCalledWith(
        '/finance/vehicle-reviews/9/history?before=11',
        expect.objectContaining({ credentials: 'same-origin' }),
    );
    const history = screen.getByRole('region', { name: 'History' });
    expect(within(history).getAllByRole('listitem')).toHaveLength(2);
    expect(history).toHaveTextContent('Latest event');
    expect(
        screen.queryByRole('button', { name: 'Load older history' }),
    ).not.toBeInTheDocument();
});

it('retains history and allows retry after retrieval fails', async () => {
    const fetchHistory = vi
        .fn()
        .mockResolvedValueOnce({ ok: false })
        .mockResolvedValueOnce({
            ok: true,
            json: async () => ({ history: [], history_next_before: null }),
        });
    vi.stubGlobal('fetch', fetchHistory);
    render(<ReviewRequestDialog request={record} onClose={() => {}} />);
    fireEvent.click(screen.getByRole('button', { name: /Evidence/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Load older history' }));
    await screen.findByRole('alert');
    expect(screen.getByText('Latest event')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Load older history' }));
    await waitFor(() =>
        expect(screen.queryByRole('alert')).not.toBeInTheDocument(),
    );
    expect(fetchHistory).toHaveBeenCalledTimes(2);
});
