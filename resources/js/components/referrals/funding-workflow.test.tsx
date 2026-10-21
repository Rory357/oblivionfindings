import '@testing-library/jest-dom/vitest';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { EvidencePicker } from './evidence-picker';
import { FundingActionDialog } from './funding-action-dialog';
import { FundingPanel } from './funding-panel';
import type { FundingCase } from './funding-types';
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
    Link: 'a',
}));
// Date widgets have their own calendar/DST contracts. Exercise the command flow here.
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
const guide = {
    id: 'dss_nasc',
    label: 'DSS assessment',
    personal_income: false,
    service_ids: ['supported_living'],
    evidence_checklist: [],
    source_url: null,
    verified_on: null,
    review_on: null,
};
const item: FundingCase = {
    id: 10,
    reference: 'CASE',
    version: 2,
    pathway: 'dss_nasc',
    pathway_label: 'DSS assessment',
    status: 'preparing',
    personal_income: false,
    navigator_id: 3,
    navigator_name: 'Example navigator',
    previous_case_id: null,
    settings_version_id: 1,
    details: {
        service_id: 'supported_living',
        starting_point: 'Help with assessment',
        assistance_requested: 'Prepare evidence',
        person_review_plan: 'Review together',
        guide,
        roles: {},
        preparation: [],
        submission_count: 0,
    },
    next_action: 'Review the response',
    next_due_at: '2027-01-05T00:00:00Z',
    next_work_status: 'open',
    latest_decision: null,
    availability: { usable: false, issues: [], lines: [] },
};
const referral: ReferralDetail = {
    reference: 'REF',
    version: 4,
    name: 'Example person',
    preferred_name: null,
    site_id: 1,
    site_name: 'Example site',
    owner_id: 2,
    owner_name: 'Example owner',
    team: 'Intake',
    stage: 'funding_assistance',
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
    work_counts: { open: 1, overdue: 0 },
    identity: {},
    contact: {},
    intake: {},
    contact_permission: true,
    can_complete_intake: false,
    funding: {
        cases: page([item]),
        authority: { assistance_permitted: true, version: 3, sharing: [] },
    },
    work: page([]),
    activity: page([]),
};
const options: Options = {
    pathways: [guide],
    services: [{ id: 'supported_living', label: 'Supported living' }],
    sites: [],
    stages: [],
    intake: null,
};
const document = {
    id: 5,
    reference: 'DOC',
    title: 'External decision letter',
    audience: 'funding',
    category: 'external_decision',
    source: 'Person supplied',
    state: 'available',
    expires_at: null,
    expired: false,
    previous_version_id: null,
    superseded_by_id: null,
    size_bytes: 100,
    created_at: '2026-10-16T00:00:00Z',
};

beforeEach(() => {
    vi.clearAllMocks();
    Element.prototype.scrollTo = vi.fn();
    get.mockResolvedValue({ data: { documents: page([document]) } });
    post.mockResolvedValue({ data: { saved: true } });
});

it('withdraws the complete funding view on history access denial and ignores an older successful response', async () => {
    let finishOld!: (value: unknown) => void;
    get.mockReturnValueOnce(
        new Promise((resolve) => {
            finishOld = resolve;
        }),
    ).mockRejectedValueOnce({ isAxiosError: true, response: { status: 403 } });
    render(
        <FundingPanel referral={referral} options={options} permissions={{}} />,
    );
    fireEvent.click(
        screen.getByRole('button', {
            name: /^DSS assessment Supported living/,
        }),
    );
    expect(screen.getByText('Help with assessment')).toBeInTheDocument();
    const dialog = screen.getByRole('dialog');
    fireEvent.click(
        within(dialog).getByRole('button', { name: /^Decision history/ }),
    );
    await waitFor(() => expect(get).toHaveBeenCalledTimes(1));
    fireEvent.click(
        within(dialog).getByRole('button', { name: /^Current funding/ }),
    );
    fireEvent.click(
        within(dialog).getByRole('button', { name: /^Decision history/ }),
    );
    await screen.findByText('Funding information is no longer available');
    await act(async () => {
        finishOld({ data: { decisions: page([]) } });
    });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(screen.queryByText('Help with assessment')).not.toBeInTheDocument();
    expect(screen.queryByText('Example navigator')).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Check current access' }),
    ).toHaveAttribute('href', '/operations/referrals/REF');
});

async function fillDecision() {
    fireEvent.change(screen.getByLabelText('Decision-maker / agency'), {
        target: { value: 'Example external agency' },
    });
    fireEvent.change(
        screen.getByLabelText('External decision reference (if supplied)'),
        {
            target: { value: 'AWARD-1' },
        },
    );
    fireEvent.change(screen.getByLabelText('Decision date'), {
        target: { value: '2026-10-16' },
    });
    fireEvent.change(
        screen.getByLabelText(
            'Conditions and restrictions, including if none are stated',
        ),
        { target: { value: 'No additional conditions stated' } },
    );
    fireEvent.change(screen.getByLabelText('Reason for this update'), {
        target: { value: 'Existing letter provided by the person' },
    });
    fireEvent.click(
        screen.getByLabelText(
            'This is an existing authorization supplied to us, rather than a decision following our recorded submission',
        ),
    );
    fireEvent.click(await screen.findByLabelText('External decision letter'));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.getByText('Example external agency')).toBeInTheDocument();
    expect(screen.getByText('16 Oct 2026')).toBeInTheDocument();
    expect(
        screen.getByText('Existing authorization supplied to us'),
    ).toBeInTheDocument();
    expect(
        screen.getByText('No additional conditions stated'),
    ).toBeInTheDocument();
    expect(screen.getAllByText('Unknown')).toHaveLength(2);
    fireEvent.click(
        screen.getByRole('button', { name: 'Save funding update' }),
    );
}

it('records an external award with unknown quantities preserved and does not verify it implicitly', async () => {
    render(
        <FundingActionDialog
            action="decision"
            item={item}
            referral={referral}
            options={options}
            onClose={vi.fn()}
        />,
    );
    await fillDecision();
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    expect(post).toHaveBeenCalledWith(
        '/operations/referrals/REF/funding/10/decision',
        expect.objectContaining({
            version: 4,
            case_version: 2,
            evidence_ids: [5],
            existing_authorization: true,
            lines: [
                expect.objectContaining({
                    quantity: null,
                    provider_quantity: null,
                    unit: 'money',
                    period: 'week',
                }),
            ],
        }),
    );
    expect(await screen.findByText('Funding update saved')).toBeInTheDocument();
    expect(
        screen.getByText(/An independent reviewer must verify it/),
    ).toBeInTheDocument();
});

it('lets staff check the actual submission and person review before saving', async () => {
    render(
        <FundingActionDialog
            action="submission"
            item={item}
            referral={referral}
            options={options}
            onClose={vi.fn()}
        />,
    );
    fireEvent.change(screen.getByLabelText('Actual destination / agency'), {
        target: { value: 'Example agency' },
    });
    fireEvent.change(screen.getByLabelText('Actual submission date and time'), {
        target: { value: '2026-10-16T10:30' },
    });
    fireEvent.change(
        screen.getByLabelText('External reference (if supplied)'),
        { target: { value: 'APPLICATION-123' } },
    );
    fireEvent.change(
        screen.getByLabelText(
            'Agency acknowledgement (optional, if already received)',
        ),
        { target: { value: 'Portal receipt supplied by the person' } },
    );
    fireEvent.click(
        screen.getByLabelText(
            'The person reviewed the information before it was submitted',
        ),
    );
    fireEvent.click(await screen.findByLabelText('External decision letter'));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(post).not.toHaveBeenCalled();
    expect(screen.getByText('Example agency')).toBeInTheDocument();
    expect(screen.getByText('APPLICATION-123')).toBeInTheDocument();
    expect(
        screen.getByText('Portal receipt supplied by the person'),
    ).toBeInTheDocument();
    expect(screen.getByText('Agency portal')).toBeInTheDocument();
    expect(screen.getByText('16 October 2026, 10:30 am')).toBeInTheDocument();
    expect(screen.getByText('Yes')).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Save funding update' }),
    );
    await waitFor(() => expect(post).toHaveBeenCalledOnce());
    expect(post).toHaveBeenCalledWith(
        '/operations/referrals/REF/funding/10/submission',
        expect.objectContaining({
            person_reviewed: true,
            external_reference: 'APPLICATION-123',
            channel: 'agency_portal',
            acknowledgement: 'Portal receipt supplied by the person',
        }),
    );
});

it('prevents an uncertain save from being repeated before the current case is reviewed', async () => {
    post.mockRejectedValue({ isAxiosError: true });
    render(
        <FundingActionDialog
            action="decision"
            item={item}
            referral={referral}
            options={options}
            onClose={vi.fn()}
        />,
    );
    await fillDecision();
    expect(
        await screen.findByRole('button', { name: 'Reload and keep draft' }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'Save funding update' }),
    ).toBeDisabled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Save funding update' }),
    );
    expect(post).toHaveBeenCalledOnce();
});

it('does not offer provider allocation inputs on a personal income decision', () => {
    render(
        <FundingActionDialog
            action="decision"
            item={{
                ...item,
                personal_income: true,
                details: { ...item.details, service_id: null },
            }}
            referral={referral}
            options={options}
            onClose={vi.fn()}
        />,
    );
    expect(
        screen.queryByLabelText('Quantity allocated to this provider'),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('combobox', { name: 'Authorized service' }),
    ).not.toBeInTheDocument();
    expect(screen.getByLabelText('Original award quantity')).toHaveValue('');
});

it.each(['navigator', 'recorder'] as const)(
    'explains the independent-review boundary before the %s can continue',
    (role) => {
        render(
            <FundingActionDialog
                action="verification"
                item={{
                    ...item,
                    navigator_id: role === 'navigator' ? 2 : 3,
                    latest_decision: {
                        id: 20,
                        version: 1,
                        outcome: 'approved',
                        recorded_by: role === 'recorder' ? 2 : 4,
                        recorded_at: '2026-10-16T00:00:00Z',
                        terms: {
                            decision_maker: 'Example agency',
                            external_reference: 'AWARD-1',
                            decision_date: '2026-10-16',
                            evidence_ids: [5],
                            conditions: 'None',
                            valid_from: '2026-10-16',
                            valid_until: '2026-11-16',
                            review_date: null,
                            deadline_source: null,
                            lines: [],
                            reason: 'Example decision',
                        },
                    },
                }}
                referral={referral}
                options={options}
                onClose={vi.fn()}
            />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'You are its navigator or recorded this decision',
        );
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        expect(screen.getByText(/Authorization dates:/)).toHaveTextContent(
            '16 Oct 2026 to 16 Nov 2026',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(post).not.toHaveBeenCalled();
    },
);

it('prevents quarantined, expired and replaced documents being selected for verification', async () => {
    get.mockResolvedValue({
        data: {
            documents: page([
                {
                    ...document,
                    id: 1,
                    title: 'Quarantined letter',
                    state: 'quarantined',
                },
                { ...document, id: 2, title: 'Expired letter', expired: true },
                {
                    ...document,
                    id: 3,
                    title: 'Replaced letter',
                    superseded_by_id: 4,
                },
            ]),
        },
    });
    render(
        <EvidencePicker
            reference="REF"
            audience="funding"
            selected={[]}
            onChange={vi.fn()}
        />,
    );
    expect(await screen.findByLabelText('Quarantined letter')).toBeDisabled();
    expect(screen.getByLabelText('Expired letter')).toBeDisabled();
    expect(screen.getByLabelText('Replaced letter')).toBeDisabled();
});
