import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import BillShow from './Show';

const visits = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: visits,
    Head: () => null,
    Link: ({ children }: { children: ReactNode }) => <span>{children}</span>,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));
vi.mock('@/components/lists', () => ({
    EntityTable: () => null,
    ListCaption: () => null,
}));
vi.mock('@/components/finance', async () => ({
    ConfirmDialog: (await import('@/components/confirm-dialog')).ConfirmDialog,
    FinanceSectionRail: () => null,
    NewBillDialog: () => null,
    formatMoney: (amount: string | number) => `$${Number(amount).toFixed(2)}`,
}));
vi.mock('@/components/page', () => {
    const Box = ({ children }: { children?: ReactNode }) => (
        <div>{children}</div>
    );
    const Action = ({
        children,
        onClick,
        disabled,
    }: {
        children?: ReactNode;
        onClick?: () => void;
        disabled?: boolean;
    }) => (
        // eslint-disable-next-line no-restricted-syntax -- Minimal page-action test double preserves disabled and click behavior.
        <button onClick={onClick} disabled={disabled}>
            {children}
        </button>
    );
    return {
        PageLayout: ({
            hero,
            children,
        }: {
            hero: ReactNode;
            children: ReactNode;
        }) => (
            <>
                {hero}
                {children}
            </>
        ),
        PageHeader: ({ actions }: { actions: ReactNode }) => (
            <header>{actions}</header>
        ),
        PageHeaderGlassButton: Action,
        PageHeaderPrimaryButton: Action,
        PageHeaderMeterBig: Box,
        PageHeaderMeterBlock: Box,
        PageHeaderMeterCaption: Box,
        PageHeaderStatusChip: Box,
    };
});

const props = {
    bill: {
        id: 42,
        bill_number: 'BILL-42',
        status: 'awaiting_approval',
        bill_date: '2026-09-01',
        due_date: '2026-10-01',
        vendor_id: 2,
        vendor: { id: 2, name: 'Workshop' },
        subtotal: '500.00',
        gst_amount: '0.00',
        total_amount: '500.00',
        amount_paid: '0.00',
        lines: [],
        payment_allocations: [],
    },
    approvalSnapshot: 'reviewed-snapshot',
    canManage: true,
    vendors: [],
    accounts: [],
    costCentres: [],
    fundingStreams: [],
    purchaseOrders: [],
    spendApprovals: [],
} as unknown as ComponentProps<typeof BillShow>;

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    vi.unstubAllGlobals();
});

describe('Bill approval review', () => {
    it.each(['lost response', 'incomplete receipt', 'wrong bill'])(
        'pins reviewed values and safely retries %s with the same identity',
        async (failure) => {
            const send = vi.fn();
            if (failure === 'lost response')
                send.mockRejectedValueOnce(new Error('lost response'));
            else
                send.mockResolvedValueOnce({
                    ok: true,
                    status: 200,
                    json: async () => ({
                        receipt:
                            failure === 'wrong bill'
                                ? {
                                      bill_id: 999,
                                      bill_number: 'BILL-999',
                                      total: '500.00',
                                      journal_id: 7,
                                      journal_number: 'JNL-7',
                                  }
                                : { bill_id: 42 },
                    }),
                });
            send.mockResolvedValueOnce({
                ok: true,
                json: async () => ({
                    receipt: {
                        bill_id: 42,
                        bill_number: 'BILL-42',
                        total: '500.00',
                        journal_id: 7,
                        journal_number: 'JNL-7',
                    },
                }),
            });
            vi.stubGlobal('fetch', send);
            const page = render(<BillShow {...props} />);
            fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
            page.rerender(
                <BillShow
                    {...props}
                    approvalSnapshot="new-snapshot"
                    bill={{ ...props.bill, total_amount: '600.00' }}
                />,
            );
            fireEvent.click(
                screen.getByRole('button', { name: /Approve and post/ }),
            );
            const dialog = screen.getByRole('dialog');
            expect(
                within(dialog).getAllByText(/500.00/).length,
            ).toBeGreaterThan(0);
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'I have reviewed the bill, allocations and evidence',
                }),
            );
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Approve bill and post journal',
                }),
            );
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Retry this submission',
                }),
            );
            await screen.findByText('Bill approved');
            expect(JSON.parse(send.mock.calls[0][1].body)).toEqual({
                approval_snapshot: 'reviewed-snapshot',
            });
            expect(send.mock.calls[1][1].headers['Idempotency-Key']).toBe(
                send.mock.calls[0][1].headers['Idempotency-Key'],
            );
            expect(send.mock.calls[1][1].body).toBe(send.mock.calls[0][1].body);
            expect(screen.getByRole('dialog')).toBeInTheDocument();
        },
    );

    it('keeps the stale rejection visible and requires a deliberate reload', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                status: 409,
                json: async () => ({
                    message: 'The bill changed. Review it again.',
                }),
            }),
        );
        render(<BillShow {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
        fireEvent.click(
            screen.getByRole('button', { name: /Approve and post/ }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'I have reviewed the bill, allocations and evidence',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Approve bill and post journal',
            }),
        );
        fireEvent.click(
            await screen.findByRole('button', { name: 'Review latest record' }),
        );
        expect(visits.reload).toHaveBeenCalledTimes(1);
    });

    it('keeps approval unavailable to read-only viewers', () => {
        render(<BillShow {...props} canManage={false} />);
        expect(
            screen.queryByRole('button', { name: 'Approve' }),
        ).not.toBeInTheDocument();
    });
});
