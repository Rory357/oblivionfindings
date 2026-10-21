import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { ProposalAgreementCheck } from './proposal-agreement';
import type { ProposalAgreementData } from './proposal-types';
import type { ReferralDetail } from './types';

const { get, post, reload, visit } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('axios', () => ({
    default: {
        get,
        post,
        isCancel: () => false,
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            error.isAxiosError === true,
    },
}));
vi.mock('@inertiajs/react', () => ({
    router: { reload, visit },
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

const referral = {
    reference: '01JREFERRALAGREEMENT000001',
    version: 5,
    name: 'Example person',
} as ReferralDetail;

const ready: ProposalAgreementData = {
    state: 'ready',
    version: 5,
    proposal: { id: 4, version: 1, title: 'Supported living plan' },
    blockers: [],
    can_create: true,
    permission_reason: null,
    person: { id: 7, name: 'Casey Example' },
    preview: {
        header: {
            title: 'Supported living plan',
            agreement_type: 'nasc',
            funding_type: null,
            funding_body: 'DSS / NASC',
            funding_reference: 'NASC-123',
            starts_at: '2026-10-21',
            ends_at: '2026-11-21',
            first_service_date: null,
            total_budget: '2400.00',
            gst_inclusive: false,
        },
        lines: [
            {
                key: 'daily-support',
                description: 'Daily living support',
                service: 'Supported living',
                proposal_quantity: '8',
                proposal_unit: 'hours',
                period: 'week',
                periods: 5,
                unit: 'hour',
                unit_price: '60.00',
                quantity: '40.00',
                budget: '2400.00',
                price_basis: 'exclusive',
                funding_reference: 'NASC-123',
                issue: null,
            },
        ],
        currency: 'NZD',
    },
    agreement: null,
    earlier: [],
    approval_steps: [
        {
            title: 'Submit for approval',
            detail: 'Someone who can update service agreements submits the draft.',
        },
        {
            title: 'Independent approval',
            detail: 'A different colleague who can update service agreements approves it. Only then does it become active.',
        },
    ],
};

beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollTo = vi.fn();
    get.mockResolvedValue({ data: ready });
    post.mockResolvedValue({
        data: {
            saved: true,
            replayed: false,
            proposal_version: 1,
            agreement: {
                id: 31,
                title: 'Supported living plan',
                status: 'draft',
                href: '/operations/service-agreements/31',
                can_open: true,
            },
        },
    });
});

it('reviews the mapped lines, totals and approvers before creating the draft agreement', async () => {
    render(<ProposalAgreementCheck referral={referral} />);
    expect(await screen.findByText('Ready to create')).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Create draft service agreement' }),
    );

    // Lines and totals, copied without retyping.
    expect(screen.getByText('Daily living support')).toBeInTheDocument();
    expect(screen.getByText('8 hours a week')).toBeInTheDocument();
    expect(screen.getByText('40.00 hours (5 weeks)')).toBeInTheDocument();
    expect(screen.getByText('$60.00 / hour')).toBeInTheDocument();
    expect(screen.getAllByText('$2,400.00')).toHaveLength(2);
    expect(screen.getByText(/excluding GST/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByText('DSS / NASC')).toBeInTheDocument();
    expect(screen.getByText('NASC-allocated')).toBeInTheDocument();
    expect(screen.getByText('Draft')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByText('Who approves next')).toBeInTheDocument();
    expect(screen.getByText('Independent approval')).toBeInTheDocument();

    fireEvent.click(
        screen.getByRole('button', { name: 'Create draft agreement' }),
    );
    expect(
        await screen.findByText(
            'Confirm the statements below before creating the draft.',
        ),
    ).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();

    fireEvent.click(screen.getByLabelText(/I have checked these lines/));
    fireEvent.click(
        screen.getByRole('button', { name: 'Create draft agreement' }),
    );
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    expect(post.mock.calls[0][0]).toBe(
        '/operations/referrals/01JREFERRALAGREEMENT000001/service-agreement',
    );
    expect(post.mock.calls[0][1]).toEqual({
        version: 5,
        proposal_id: 4,
        proposal_version: 1,
        acknowledge_draft: true,
    });
    expect(
        await screen.findByText('Draft agreement created'),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Open agreement' }),
    ).toHaveAttribute('href', '/operations/service-agreements/31');
    expect(reload).toHaveBeenCalled();
});

it('blocks creation with a clear reason when a line has no price', async () => {
    get.mockResolvedValue({
        data: {
            ...ready,
            state: 'blocked',
            blockers: [
                'Daily living support: No price yet. Revise the proposal with a quantity, rate and GST treatment; an agreement line is never created at $0.',
            ],
        },
    });
    render(<ProposalAgreementCheck referral={referral} />);
    expect(await screen.findByText('Not ready')).toBeInTheDocument();
    expect(
        screen.getByText(/an agreement line is never created at \$0/),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Create draft service agreement' }),
    ).toBeDisabled();
});

it('shows an agreement from an older version as a record with a review warning and one shared menu', async () => {
    get.mockResolvedValue({
        data: {
            ...ready,
            state: 'blocked',
            proposal: { id: 9, version: 2, title: 'Revised plan' },
            blockers: [
                'Proposal version 2 needs current independent internal approval.',
            ],
            agreement: {
                id: 31,
                title: 'Supported living plan',
                status: 'active',
                total_budget: '2400.00',
                gst_inclusive: false,
                starts_on: '2026-10-21',
                ends_on: '2026-11-21',
                proposal_id: 4,
                proposal_version: 1,
                created_at: '2026-10-21T00:00:00Z',
                created_by_name: 'Example coordinator',
                outdated: true,
                warning:
                    'Agreement is from version 1; review it. Proposal version 2 has different terms, and the agreement has not been changed.',
                can_open: true,
                href: '/operations/service-agreements/31',
            },
        },
    });
    render(<ProposalAgreementCheck referral={referral} />);
    expect(await screen.findByText('Review needed')).toBeInTheDocument();
    expect(
        screen.getByText(/Agreement is from version 1; review it/),
    ).toBeInTheDocument();
    expect(screen.getByText('Active')).toBeInTheDocument();
    expect(screen.getByText(/From proposal version 1/)).toBeInTheDocument();

    fireEvent.contextMenu(screen.getByText('Supported living plan'));
    expect(
        await screen.findByRole('menuitem', { name: /Open agreement/ }),
    ).toBeInTheDocument();
    const create = screen.getByRole('menuitem', {
        name: /Create draft from version 2/,
    });
    expect(create).toHaveAttribute('aria-disabled', 'true');
    expect(
        screen.getByRole('menuitem', { name: /Refresh status/ }),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('menuitem', { name: /Open agreement/ }));
    expect(visit).toHaveBeenCalledWith('/operations/service-agreements/31');
});

it('keeps a deleted agreement as history and offers a fresh draft from the same version', async () => {
    get.mockResolvedValue({
        data: {
            ...ready,
            earlier: [
                {
                    id: 31,
                    title: 'Supported living plan',
                    status: 'deleted',
                    total_budget: '2400.00',
                    gst_inclusive: false,
                    starts_on: '2026-10-21',
                    ends_on: '2026-11-21',
                    proposal_id: 4,
                    proposal_version: 1,
                    created_at: '2026-10-21T00:00:00Z',
                    created_by_name: 'Example manager',
                    outdated: false,
                    warning: null,
                    can_open: false,
                    href: '/operations/service-agreements/31',
                },
            ],
        },
    });
    render(<ProposalAgreementCheck referral={referral} />);
    expect(await screen.findByText('Deleted')).toBeInTheDocument();
    expect(
        screen.getByText(
            /was deleted in Service agreements\. Create a fresh draft/,
        ),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('link', { name: 'Open agreement' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Create draft service agreement' }),
    ).toBeEnabled();
    fireEvent.contextMenu(screen.getByText('Supported living plan'));
    expect(
        await screen.findByRole('menuitem', { name: /Open agreement/ }),
    ).toHaveAttribute('aria-disabled', 'true');
});
