import '@testing-library/jest-dom/vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { ProposalEditDialog } from './proposal-edit-dialog';
import { ProposalReviewDialog } from './proposal-review-dialog';
import type { ProposalData, SupportProposal } from './proposal-types';
import { ProposalValues } from './proposal-values';
import { ProposalsPanel } from './proposals-panel';
import type { Options, Page, ReferralDetail } from './types';

const { get, post, reload } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
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
    router: { reload },
    usePage: () => ({ props: { auth: { user: { id: 2 } } } }),
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));
vi.mock('@/components/fleet-assets/maintenance/date-picker', () => ({
    DatePicker: ({
        id,
        label,
        value,
        onChange,
    }: {
        id: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
vi.mock('./date-time', () => ({
    ReferralDateTime: ({
        id,
        label,
        value,
        onChange,
    }: {
        id: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
const page = <T,>(data: T[]): Page<T> => ({
    data,
    total: data.length,
    current_page: 1,
    last_page: 1,
    links: [],
});
const proposal: SupportProposal = {
    id: 4,
    version: 2,
    authored_by: 2,
    authored_at: '2026-10-16T00:00:00Z',
    terms: {
        title: 'Example support proposal',
        starts_on: '2026-10-20',
        ends_on: '2026-11-20',
        support_rationale: 'Support daily living goals',
        funding_position: 'Funding is still being arranged',
        capacity_plan: 'Confirm the available support team',
        person_participation: 'The person reviewed their support choices',
        reason: 'Initial proposal',
        allocations: [],
        lines: [
            {
                key: 'daily-support',
                service_id: 'supported_living',
                description: 'Daily living support',
                quantity: null,
                unit: 'hours',
                unit_label: null,
                period: 'week',
                currency: 'NZD',
                unit_rate: null,
                tax_rate_id: null,
                price_basis: 'unknown',
                price_source: 'Price still requires a quote',
                tax: null,
                estimate: { known: false, net: null, tax: null, gross: null },
            },
        ],
        totals: [
            {
                currency: 'NZD',
                period: 'week',
                known: false,
                known_gross: '0.00',
                unknown_lines: 1,
            },
        ],
    },
};
const referral: ReferralDetail = {
    reference: 'REF',
    version: 8,
    name: 'Example person',
    preferred_name: null,
    site_id: 1,
    site_name: 'Example site',
    owner_id: 2,
    owner_name: 'Example owner',
    team: 'Intake',
    stage: 'assessment',
    source: 'website',
    requested_service: 'supported_living',
    priority: 'routine',
    restricted: false,
    general_enquiry: false,
    next_action: 'Review support',
    next_due_at: null,
    created_at: '2026-10-16T00:00:00Z',
    first_human_contact_at: null,
    hold_reason: null,
    closure_reason: null,
    primary_work_item_id: null,
    work_counts: { open: 0, overdue: 0 },
    identity: {},
    contact: {},
    intake: {},
    contact_permission: true,
    can_complete_intake: false,
    funding: {
        cases: page([]),
        authority: { assistance_permitted: true, version: 1, sharing: [] },
    },
    work: page([]),
    activity: page([]),
};
const options: Options = {
    pathways: [],
    services: [{ id: 'supported_living', label: 'Supported living' }],
    sites: [],
    stages: [],
    intake: null,
};
const data: ProposalData = {
    current: proposal,
    latest_id: proposal.id,
    proposals: page([proposal]),
    tax_rates: [],
    readiness: {
        ready: false,
        issues: ['Costs need review'],
        internal_approval_current: false,
        person_acceptance_current: false,
        private_payers: [],
        coverage: { usable: false, issues: [], lines: [] },
    },
};
beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollTo = vi.fn();
    get.mockResolvedValue({ data: { documents: page([]) } });
    post.mockResolvedValue({ data: { saved: true } });
});

it('creates a new draft version without submitting old calculated estimates or acceptance', async () => {
    render(
        <ProposalEditDialog
            referral={referral}
            current={proposal}
            taxes={[]}
            options={options}
            onClose={vi.fn()}
        />,
    );
    for (let step = 0; step < 3; step++)
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByText('20 Oct 2026')).toBeInTheDocument();
    expect(screen.getByText('20 Nov 2026')).toBeInTheDocument();
    expect(screen.getByText('Support daily living goals')).toBeInTheDocument();
    expect(
        screen.getByText('The person reviewed their support choices'),
    ).toBeInTheDocument();
    expect(
        screen.getByText('Confirm the available support team'),
    ).toBeInTheDocument();
    expect(screen.getByText('Not yet costed')).toBeInTheDocument();
    expect(
        screen.getByText(
            'No funding allocated. Funding coverage still needs review.',
        ),
    ).toBeInTheDocument();
    fireEvent.change(
        screen.getByLabelText('Reason for this proposal version'),
        { target: { value: 'Review the updated support plan' } },
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Save proposal version' }),
    );
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    const payload = post.mock.calls[0][1];
    expect(payload.previous_proposal_id).toBe(4);
    expect(payload.lines[0]).toEqual(
        expect.objectContaining({
            quantity: null,
            unit_rate: null,
            tax_rate_id: null,
        }),
    );
    expect(payload.lines[0]).not.toHaveProperty('estimate');
    expect(payload.lines[0]).not.toHaveProperty('tax');
    expect(payload).not.toHaveProperty('accepted');
    expect(
        await screen.findByText('Proposal version saved'),
    ).toBeInTheDocument();
});

it('requires linked evidence before recording acceptance of a proposal version', async () => {
    get.mockResolvedValue({
        data: {
            documents: page([
                {
                    id: 5,
                    title: 'Version acceptance evidence',
                    audience: 'funding',
                    state: 'available',
                    expired: false,
                    superseded_by_id: null,
                    created_at: '2026-10-16T00:00:00Z',
                    size_bytes: 100,
                    category: 'agreement',
                },
            ]),
        },
    });
    render(
        <ProposalReviewDialog
            mode="acceptance"
            referral={referral}
            proposal={proposal}
            readiness={data.readiness}
            onClose={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText('Signatory / person responding'), {
        target: { value: 'Example person' },
    });
    fireEvent.change(screen.getByLabelText('Authority and identity checked'), {
        target: { value: 'Current authority checked' },
    });
    fireEvent.change(
        screen.getByLabelText(
            'How the person participated and understood the terms',
        ),
        { target: { value: 'Terms explained together' } },
    );
    fireEvent.change(screen.getByLabelText('Response / review notes'), {
        target: { value: 'Person agreed to the terms' },
    });
    fireEvent.change(screen.getByLabelText('Actual response date and time'), {
        target: { value: '2026-10-16T10:30+13:00' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(
        await screen.findByText(
            'Complete the response, supporting notes and required evidence before saving.',
        ),
    ).toBeInTheDocument();
    expect(post).not.toHaveBeenCalled();
    fireEvent.click(
        await screen.findByLabelText('Version acceptance evidence'),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByText('Current authority checked')).toBeInTheDocument();
    expect(screen.getByText('Terms explained together')).toBeInTheDocument();
    expect(screen.getByText('16 October 2026, 10:30 am')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Record response' }));
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    expect(post.mock.calls[0][1]).toEqual(
        expect.objectContaining({
            authority_note: 'Current authority checked',
            participation: 'Terms explained together',
            evidence_ids: [5],
            occurred_at: '2026-10-16T10:30+13:00',
        }),
    );
});

it('keeps different budget periods and unknown costs explicit in the review', () => {
    const mixed: SupportProposal = {
        ...proposal,
        terms: {
            ...proposal.terms,
            totals: [
                {
                    currency: 'NZD',
                    period: 'week',
                    known: true,
                    known_gross: '552.00',
                    unknown_lines: 0,
                },
                {
                    currency: 'NZD',
                    period: 'month',
                    known: false,
                    known_gross: '0.00',
                    unknown_lines: 1,
                },
            ],
        },
    };
    render(<ProposalValues proposal={mixed} />);
    expect(screen.getByText('NZD / Week')).toBeInTheDocument();
    expect(screen.getByText('NZD / Month')).toBeInTheDocument();
    expect(
        screen.getByText('0.00 known · 1 lines not yet costed'),
    ).toBeInTheDocument();
    expect(screen.getAllByText('Not yet costed').length).toBeGreaterThan(0);
});

it('does not offer an author the action to approve their own proposal', async () => {
    get.mockResolvedValue({ data });
    render(
        <ProposalsPanel
            referral={referral}
            options={options}
            permissions={{ 'funding.view': true, 'proposals.approve': true }}
        />,
    );
    expect(
        await screen.findByText(
            'Another authorized colleague must approve the proposal you prepared.',
        ),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Independent approval' }),
    ).not.toBeInTheDocument();
});
