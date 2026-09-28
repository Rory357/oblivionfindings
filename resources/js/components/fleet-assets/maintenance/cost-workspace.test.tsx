import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import {
    WorkCostMeters,
    WorkCostWorkspace,
    type CostWork,
    type CostWorkspaceData,
} from './cost-workspace';
vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    Link: ({ children }: { children: ReactNode }) => <span>{children}</span>,
}));
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});
const work: CostWork = {
    id: 31,
    version: 1,
    title: 'Service',
    reference_number: 'WO-31',
    status: 'open',
    description: null,
    asset: { id: 8, name: 'Van', registration_number: 'TEST' },
    assigned_to: null,
};
const data: CostWorkspaceData = {
    summary: {
        committed: null,
        invoice: null,
        posted: null,
        paid: null,
        incomplete: false,
    },
    site_name: 'North',
    estimates: [],
    documents: [],
    bills: [],
    unavailable_bill_count: 0,
    requests: [],
    vendors: [{ id: 2, name: 'Workshop' }],
    cost_centres: [],
    can: { estimate: true, upload: true, request: false },
};
const response = (status: number, body: unknown) =>
    new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });

it('labels inaccessible linked totals as incomplete rather than showing a partial amount', () => {
    render(
        <WorkCostMeters
            data={{
                ...data,
                summary: {
                    committed: null,
                    invoice: null,
                    posted: null,
                    paid: null,
                    incomplete: true,
                },
            }}
        />,
    );
    expect(screen.getAllByText('Incomplete')).toHaveLength(4);
    expect(
        screen.getByRole('button', { name: 'View estimate source records' }),
    ).toHaveTextContent('Not recorded');
});

it('opens the estimate wizard from the page header without a duplicate workspace action', () => {
    const close = vi.fn();
    render(
        <WorkCostWorkspace
            work={work}
            data={data}
            canManage
            notes={[]}
            attachments={[]}
            onWork={() => {}}
            onComplete={() => {}}
            showHeading={false}
            estimateOpen
            onEstimateClose={close}
        />,
    );
    expect(
        screen.getByRole('dialog', { name: 'Record estimate' }),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    expect(close).toHaveBeenCalledOnce();
});

it('shows a confirmed zero-value posting as posted rather than missing', () => {
    render(
        <WorkCostMeters
            data={{
                ...data,
                summary: {
                    committed: null,
                    invoice: '0.00',
                    posted: '0.00',
                    paid: '0.00',
                    incomplete: false,
                },
                bills: [
                    {
                        id: 1,
                        reference: 'ZERO',
                        status: 'approved',
                        total: '0.00',
                        paid: '0.00',
                        vendor: null,
                        url: '/finance/bills/1',
                        purchase_order: null,
                        journal: {
                            id: 1,
                            journal_number: 'JNL-1',
                            status: 'posted',
                        },
                    },
                ],
            }}
        />,
    );
    const posted = screen.getByRole('button', {
        name: 'View posted source records',
    });
    expect(posted).toHaveTextContent('$0.00');
    expect(posted).not.toHaveTextContent('Not posted');
});

it('allows correcting an estimate after its files were saved without uploading them again', async () => {
    const fetch = vi
        .fn()
        .mockResolvedValueOnce(
            response(200, {
                files: [
                    {
                        id: 70,
                        name: 'quote.pdf',
                        state: 'available',
                        archived: false,
                    },
                ],
            }),
        )
        .mockResolvedValueOnce(
            response(422, {
                errors: { amount: ['Correct this quote amount.'] },
            }),
        )
        .mockResolvedValueOnce(response(200, { saved: true, version: 2 }));
    vi.stubGlobal('fetch', fetch);
    render(
        <WorkCostWorkspace
            work={work}
            data={data}
            canManage
            notes={[]}
            attachments={[]}
            onWork={() => {}}
            onComplete={() => {}}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record estimate' }));
    fireEvent.change(screen.getByLabelText('Supplier', { exact: true }), {
        target: { value: '2' },
    });
    fireEvent.change(screen.getByLabelText('Quote reference'), {
        target: { value: 'Q-1' },
    });
    fireEvent.change(screen.getByLabelText('Amount including GST · NZD'), {
        target: { value: '500.00' },
    });
    fireEvent.change(screen.getByLabelText('Reason for this estimate'), {
        target: { value: 'Workshop quote' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    const input =
        document.querySelector<HTMLInputElement>('input[type="file"]');
    expect(input).not.toBeNull();
    fireEvent.change(input!, {
        target: {
            files: [
                new File(['%PDF-1.4 test'], 'quote.pdf', {
                    type: 'application/pdf',
                }),
            ],
        },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Record estimate' }));
    await screen.findByText('Correct this quote amount.');
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    fireEvent.click(screen.getByRole('button', { name: 'Back' }));
    expect(screen.getByLabelText('Amount including GST · NZD')).toBeEnabled();
    fireEvent.change(screen.getByLabelText('Amount including GST · NZD'), {
        target: { value: '550.00' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Record estimate' }));
    await screen.findByText('Estimate recorded');
    expect(fetch).toHaveBeenCalledTimes(3);
    expect(fetch.mock.calls[0][0]).toBe('/fleet-assets/vehicles/8/documents');
    expect(JSON.parse(fetch.mock.calls[2][1].body)).toMatchObject({
        amount: '550.00',
        document_ids: [70],
        version: 1,
    });
});
