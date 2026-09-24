import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FinanceStudio } from './finance-studio';
import type {
    FinanceLinkedRecord,
    VehicleFinanceWorkspace,
} from './finance-types';
import type { VehicleWorkspace } from './types';

afterEach(() => {
    cleanup();
    window.localStorage.clear();
    vi.unstubAllGlobals();
});

const workspace = {
    vehicle: {
        id: 14,
        name: 'Kōwhai van',
        asset_tag: 'VH-014',
        registration_number: 'KWH014',
        site: { id: 3, name: 'Kōwhai House' },
    },
    can: { manage: true, manage_documents: true },
} as unknown as VehicleWorkspace;

const invoice: FinanceLinkedRecord = {
    key: 'bill-5',
    type: 'bill',
    id: 5,
    kind: 'Supplier invoice',
    name: 'Harbour Workshop · INV-2231',
    reference: 'BILL-202609-004',
    status: 'approved',
    status_label: 'Awaiting payment',
    tone: 'info',
    amount: 408.25,
    date: '2026-09-05',
    owner: 'Accounts payable',
    workspace: 'Accounts payable',
    detail: '$355.00 net + $53.25 GST. Invoice approval and payment remain Finance-owned.',
    basis_note:
        'Linked from this vehicle by Alex Morgan on 22 Sep 2026: Service invoice',
    href: '/finance/bills/5',
    restricted: false,
    available: true,
    basis: 'vehicle',
    link_id: 9,
    can_unlink: true,
    files: [
        {
            id: 77,
            name: 'invoice.pdf',
            state: 'available',
            url: '/fleet-assets/vehicles/14/documents/77/file',
            waiting: false,
        },
    ],
};

function finance(
    overrides: Partial<VehicleFinanceWorkspace> = {},
): VehicleFinanceWorkspace {
    return {
        can: {
            view: true,
            view_spend: true,
            link: true,
            link_fixed_asset: true,
            link_spend: true,
            request_review: true,
            decide: false,
            attach_files: true,
            open_documents: true,
        },
        fixed_asset: { id: 21, label: 'FA-2201', count: 1 },
        cost_centre: {
            id: 4,
            code: 'CC-KOWHAI',
            name: 'Kōwhai House',
            active: true,
        },
        pending_requests: 1,
        records: [
            {
                ...invoice,
                key: 'fixed_asset-21',
                type: 'fixed_asset',
                id: 21,
                kind: 'Fixed asset',
                name: 'KWH014 · Toyota Hiace',
                reference: 'FA-2201',
                status: 'active',
                status_label: 'Active',
                amount: 42000,
                owner: 'Finance assets',
                workspace: 'Fixed assets',
                href: '/finance/fixed-assets/21',
                basis: 'finance',
                link_id: null,
                can_unlink: false,
                files: [],
            },
            invoice,
        ],
        records_total: 2,
        requests: [
            {
                id: 3,
                reference: 'FRQ-2026-0001',
                type: 'supplier_invoice_review',
                type_label: 'Supplier invoice review',
                source: {
                    type: 'bill',
                    id: 5,
                    label: 'BILL-202609-004 · Harbour Workshop',
                },
                amount: 408.25,
                note: 'The invoice is $50 above the approved quote.',
                status: 'submitted',
                status_label: 'Pending Finance review',
                tone: 'warning',
                requested_by: 'Alex Morgan',
                requested_at: '2026-09-22T21:00:00Z',
                decided_by: null,
                decided_at: null,
                decision_note: null,
                lock_version: 1,
                history: [
                    {
                        id: 1,
                        action: 'submitted',
                        label: 'Submitted to Finance',
                        actor: 'Alex Morgan',
                        note: null,
                        occurred_at: '2026-09-22T21:00:00Z',
                    },
                ],
                files: [],
                can_decide: false,
                can_add_files: true,
            },
        ],
        request_types: [
            {
                value: 'supplier_invoice_review',
                label: 'Supplier invoice review',
            },
            { value: 'purchase_approval', label: 'Purchase approval' },
        ],
        sources: [
            {
                value: 'vehicle',
                label: 'KWH014 · Vehicle record',
                detail: 'VH-014',
            },
        ],
        documents: [],
        link_state: {
            finance_fixed_asset: {
                id: 21,
                label: 'FA-2201 · KWH014 · Toyota Hiace',
                link_id: null,
            },
            vehicle_fixed_asset: null,
        },
        as_of: '2026-09-23T00:00:00Z',
        ...overrides,
    };
}

const studio = (data: VehicleFinanceWorkspace | undefined) =>
    render(
        <FinanceStudio
            workspace={workspace}
            finance={data}
            onChanged={vi.fn()}
        />,
    );

describe('vehicle Overview › Finance', () => {
    it('renders the approved sections and wording with real records', () => {
        studio(finance());

        expect(
            screen.getByText('Finance connection · VH-014'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Ownership, purchasing & costs',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Linked records keep approvals and payment with Finance.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Link records' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Request Finance review' }),
        ).toBeInTheDocument();

        const fixed = screen.getByRole('region', { name: 'Fixed asset' });
        expect(within(fixed).getByText('FA-2201')).toBeInTheDocument();
        expect(
            within(fixed).getByText('Financial asset recognition'),
        ).toBeInTheDocument();
        const centre = screen.getByRole('region', { name: 'Cost centre' });
        expect(within(centre).getByText('Kōwhai House')).toBeInTheDocument();
        expect(within(centre).getByText('CC-KOWHAI')).toBeInTheDocument();
        const queue = screen.getByRole('region', { name: 'Review queue' });
        expect(within(queue).getByText('1 pending')).toBeInTheDocument();
        expect(
            within(queue).getByText('Finance owns the next decision'),
        ).toBeInTheDocument();

        expect(
            screen.getByRole('heading', { name: 'Linked Finance records' }),
        ).toBeInTheDocument();
        for (const column of ['Type', 'Status', 'Amount (NZD)', 'Source'])
            expect(
                screen.getAllByRole('columnheader', { name: column }).length,
            ).toBeGreaterThan(0);
        expect(screen.getByText('$408.25')).toBeInTheDocument();
        expect(screen.getByText('$42,000.00')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Purchase orders and invoices represent different stages of the same spend. They are not added together as vehicle costs.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: 'Finance review requests' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Pending Finance review')).toBeInTheDocument();
        expect(
            screen.getByText('Finance remains the owner'),
        ).toBeInTheDocument();
        expect(document.body.textContent ?? '').not.toMatch(
            /synthetic|demonstration|preview|mockup/i,
        );
    });

    it('shows the Finance access notice and nothing else without Finance access', () => {
        studio(
            finance({
                can: {
                    ...finance().can,
                    view: false,
                    link: false,
                    request_review: false,
                },
                records: [],
                requests: [],
            }),
        );

        expect(screen.getByText('Finance access required')).toBeInTheDocument();
        expect(
            screen.getByText(
                'This role can report vehicle concerns. Financial records are available to permitted Finance viewers.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Ownership, purchasing & costs'),
        ).not.toBeInTheDocument();
    });

    it('keeps the page layout while the Finance records load', () => {
        studio(undefined);

        expect(
            screen.getByText('Loading Finance records…'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'Ownership, purchasing & costs',
            }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Link records' }),
        ).not.toBeInTheDocument();
    });

    it('hides actions the viewer cannot take', () => {
        studio(
            finance({
                can: {
                    ...finance().can,
                    link: false,
                    link_fixed_asset: false,
                    link_spend: false,
                    request_review: false,
                    attach_files: false,
                },
            }),
        );

        expect(
            screen.queryByRole('button', { name: 'Link records' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Request Finance review' }),
        ).not.toBeInTheDocument();
        cleanup();

        // Nothing to link: Finance owns the fixed-asset link and invoices need accounts payable access.
        studio(finance({ can: { ...finance().can, link_spend: false } }));
        expect(
            screen.queryByRole('button', { name: 'Link records' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Request Finance review' }),
        ).toBeInTheDocument();
    });

    it('opens a linked record with its Finance context and files', () => {
        studio(finance());
        fireEvent.click(
            screen
                .getByText('Harbour Workshop · INV-2231')
                .closest('[role="row"]') as HTMLElement,
        );

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText('Supplier invoice · BILL-202609-004'),
        ).toBeInTheDocument();
        expect(within(dialog).getByText('Recorded amount')).toBeInTheDocument();
        expect(within(dialog).getByText('VH-014 · KWH014')).toBeInTheDocument();
        expect(within(dialog).getByText('Record context')).toBeInTheDocument();
        expect(
            within(dialog).getByText('Separate approval / payment'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('Linked documents · 1'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('link', { name: /invoice\.pdf/ }),
        ).toHaveAttribute(
            'href',
            '/fleet-assets/vehicles/14/documents/77/file',
        );
        expect(
            within(dialog).getByRole('link', { name: /Open in Finance/ }),
        ).toHaveAttribute('href', '/finance/bills/5');
    });

    it('does not reveal an accounts payable record without accounts payable access', () => {
        studio(
            finance({
                records: [
                    {
                        ...invoice,
                        name: 'Supplier invoice',
                        reference: null,
                        amount: null,
                        detail: null,
                        basis_note: null,
                        href: null,
                        restricted: true,
                        can_unlink: false,
                        files: [],
                    },
                ],
            }),
        );

        expect(
            screen.getByText('Accounts payable access needed'),
        ).toBeInTheDocument();
        expect(screen.getByText('Restricted')).toBeInTheDocument();
        fireEvent.click(
            screen
                .getByText('Accounts payable access needed')
                .closest('[role="row"]') as HTMLElement,
        );
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText('Finance record unavailable'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText('No accessible linked record'),
        ).toBeInTheDocument();
        expect(
            within(dialog).queryByRole('link', { name: /Open in Finance/ }),
        ).not.toBeInTheDocument();
    });

    it('lets Finance decide an open review request', () => {
        const data = finance({ can: { ...finance().can, decide: true } });
        data.requests[0].can_decide = true;
        studio(data);
        fireEvent.click(
            screen
                .getByText('Supplier invoice review')
                .closest('[role="row"]') as HTMLElement,
        );

        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText('Finance review · FRQ-2026-0001'),
        ).toBeInTheDocument();
        expect(within(dialog).getByText('Finance team')).toBeInTheDocument();
        expect(
            within(dialog).getByText('All Tasks for Finance'),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByText(/Alex Morgan · Submitted to Finance/),
        ).toBeInTheDocument();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Resolve' }),
        );
        expect(
            screen.getByRole('dialog', { name: 'Resolve Finance review' }),
        ).toBeInTheDocument();
    });

    it('uses the approved fields in the link and review wizards', () => {
        studio(finance());
        fireEvent.click(screen.getByRole('button', { name: 'Link records' }));
        const link = screen.getByRole('dialog');
        for (const label of [
            'Fixed asset record',
            'Cost centre',
            'Purchase order or supplier invoice',
            'Reason / supporting details',
        ])
            expect(within(link).getByText(label)).toBeInTheDocument();
        // Finance's own fixed-asset link is shown locked, not as a picker.
        expect(
            within(link).getByText('FA-2201 · KWH014 · Toyota Hiace'),
        ).toBeInTheDocument();
        fireEvent.click(within(link).getByRole('button', { name: 'Continue' }));
        expect(
            within(link).getByText(
                'Choose a record to link, or change the fixed asset.',
            ),
        ).toBeInTheDocument();
        cleanup();

        studio(finance());
        fireEvent.click(
            screen.getByRole('button', { name: 'Request Finance review' }),
        );
        const review = screen.getByRole('dialog');
        for (const label of [
            'Use an existing document',
            'Finance request type',
            'Source record',
            'Amount including GST · NZD',
            'What Finance needs to review',
            'Quote / invoice / supporting files',
        ])
            expect(within(review).getByText(label)).toBeInTheDocument();
        fireEvent.click(
            within(review).getByRole('button', { name: 'Continue' }),
        );
        expect(
            within(review).getByText('Record what Finance needs to review.'),
        ).toBeInTheDocument();
    });

    it('saves a review request, then uploads its supporting files privately', async () => {
        const calls: Array<{ url: string; init: RequestInit }> = [];
        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string, init: RequestInit) => {
                calls.push({ url, init });
                const body = url.endsWith('/finance/review-requests')
                    ? {
                          request: {
                              id: 9,
                              reference: 'FRQ-2026-0002',
                              type_label: 'Supplier invoice review',
                              status: 'submitted',
                              lock_version: 1,
                          },
                      }
                    : {
                          set: { id: 4, lock_version: 1, current_revision: 1 },
                          files: [
                              {
                                  id: 31,
                                  name: 'quote.pdf',
                                  state: 'available',
                                  archived: false,
                              },
                          ],
                      };
                return { ok: true, status: 200, json: async () => body };
            }),
        );
        const onChanged = vi.fn();
        render(
            <FinanceStudio
                workspace={workspace}
                finance={finance()}
                onChanged={onChanged}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Request Finance review' }),
        );
        const dialog = screen.getByRole('dialog');
        fireEvent.change(
            within(dialog).getByLabelText('What Finance needs to review'),
            { target: { value: 'The invoice is above the quote.' } },
        );
        fireEvent.change(
            dialog.querySelector('input[type="file"]') as HTMLInputElement,
            {
                target: {
                    files: [
                        new File(['%PDF-1.4'], 'quote.pdf', {
                            type: 'application/pdf',
                        }),
                    ],
                },
            },
        );
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Continue' }),
        );
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: 'Create Finance review request',
            }),
        );

        expect(
            await screen.findByText('Finance review requested'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/FRQ-2026-0002 is now in the Finance queue/),
        ).toBeInTheDocument();
        expect(calls).toHaveLength(2);
        expect(calls[0].url).toBe(
            '/fleet-assets/vehicles/14/finance/review-requests',
        );
        expect(JSON.parse(String(calls[0].init.body))).toEqual({
            request_type: 'supplier_invoice_review',
            source: 'vehicle',
            amount: null,
            note: 'The invoice is above the quote.',
            existing_document_id: null,
        });
        expect(
            (calls[0].init.headers as Record<string, string>)[
                'Idempotency-Key'
            ],
        ).toBeTruthy();
        expect(calls[1].url).toBe('/fleet-assets/vehicles/14/documents');
        const form = calls[1].init.body as FormData;
        expect(form.get('source_type')).toBe('finance_review_request');
        expect(form.get('source_id')).toBe('9');
        expect(form.get('category')).toBe('Supplier invoice review');
        expect(onChanged).toHaveBeenCalled();
    });
});
