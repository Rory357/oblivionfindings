import type { ReferralSettingsPage } from '@/components/referrals/settings-types';
import { configurationChanges } from '@/components/referrals/settings-types';
import '@testing-library/jest-dom/vitest';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import ReferralsSettings from './index';

const { post, get, crumbs } = vi.hoisted(() => ({
    post: vi.fn(),
    get: vi.fn(),
    crumbs: vi.fn(),
}));
vi.mock('axios', () => ({
    default: {
        post,
        get: vi.fn(() => new Promise(() => undefined)),
        isAxiosError: (error: { isAxiosError?: boolean }) =>
            !!error.isAxiosError,
    },
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        preserveState,
        ...props
    }: AnchorHTMLAttributes<HTMLAnchorElement> & {
        preserveState?: boolean;
    }) => {
        void preserveState;
        return <a {...props} />;
    },
    router: { get, on: () => () => {}, visit: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        children,
        breadcrumbs,
    }: {
        children: ReactNode;
        breadcrumbs: unknown;
    }) => {
        crumbs(breadcrumbs);
        return <main>{children}</main>;
    },
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

const choices = {
    hold: [{ id: 'awaiting_person', label: 'Waiting to hear', enabled: true }],
    close: [
        { id: 'information_only', label: 'Information only', enabled: true },
        { id: 'duplicate', label: 'Duplicate referral', enabled: true },
    ],
    priority: [{ id: 'standard', label: 'Standard', enabled: true }],
};
const configuration: ReferralSettingsPage['configuration'] = {
    public: {
        enabled: false,
        provider_name: 'Oblivion Care',
        contact_phone: '',
        response_hours: 'Office hours',
        notice_version: 'v1',
        notice: 'Current notice',
        acknowledgement_enabled: false,
    },
    services: [
        { id: 'supported_living', label: 'Supported living', enabled: true },
    ],
    intake: {
        team: 'Central intake',
        first_contact_business_days: 2,
        hold_review_business_days: 5,
    },
    business_calendar: {
        timezone: 'Pacific/Auckland',
        weekdays: [1, 2, 3, 4, 5],
        holidays: [],
        closing_time: '17:00',
    },
    stages: [{ id: 'new', label: 'New' }],
    pathways: [
        {
            id: 'dss_nasc',
            label: 'DSS / NASC',
            enabled: true,
            personal_income: false,
            service_ids: ['supported_living'],
            evidence_checklist: [],
            source_url: null,
            verified_on: null,
            review_on: null,
        },
    ],
    proposals: {
        approval_limit_cents: 0,
        approval_limits: [],
        temporary_funding_enabled: false,
    },
    choices,
};
const settings: ReferralSettingsPage = {
    latest: null,
    effective: null,
    selected: null,
    configuration,
    history: { data: [], total: 0, current_page: 1, last_page: 1, links: [] },
    roles: [],
    service_types: [
        { id: 'home_support', name: 'Home & Community Support' },
        { id: 'planned_respite', name: 'Planned Respite' },
    ],
    permissions: {},
    protected_choices: {
        hold: [],
        close: ['duplicate'],
        priority: ['standard'],
    },
    built_in_choices: {
        hold: ['awaiting_person'],
        close: ['information_only', 'duplicate'],
        priority: ['standard'],
    },
    can_manage: true,
    can_publish: true,
    can_govern: true,
    can_edit_roles: false,
    actor_id: 1,
};
const impact = {
    checked_at: '2026-10-20T01:00:00Z',
    version_id: null,
    scope: 'Only records you can currently view.',
    open_referrals: 0,
    without_owner: 0,
    outside_enabled_services: 0,
    retaining_another_version: 0,
    funding_outside_enabled_pathways: 0,
    intake_team: 'Central intake',
    enabled_services: ['Supported living'],
    public_enabled: false,
    notice_version: 'v1',
    lines: [
        {
            area: 'intake',
            affects: 'People enquiring online',
            text: 'The website shows updated provider name, phone or response hours.',
            loosens: false,
        },
    ],
};
beforeEach(() => {
    post.mockReset();
    get.mockReset();
    crumbs.mockReset();
    window.location.hash = '';
});
afterEach(cleanup);

function open(area: string, tab?: string) {
    fireEvent.click(screen.getByRole('tab', { name: area }));
    if (tab) fireEvent.click(screen.getByRole('tab', { name: tab }));
}
function respond(...results: unknown[]) {
    for (const result of results)
        post.mockImplementationOnce(() =>
            result instanceof Error ||
            (result as { isAxiosError?: boolean })?.isAxiosError
                ? Promise.reject(result)
                : Promise.resolve(result),
        );
}
async function reviewAndSave(
    reason = 'Update the published provider name',
    onImpact?: () => void,
) {
    fireEvent.click(
        screen.getAllByRole('button', { name: 'Review changes' })[0],
    );
    await screen.findByText(/Every changed value/);
    onImpact?.();
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    fireEvent.change(screen.getByLabelText(/Reason for this change/), {
        target: { value: reason },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save draft' }));
}

it('compares optional document and follow-up settings against their real legacy defaults', () => {
    expect(
        configurationChanges(configuration, {
            ...configuration,
            document_requests: { enabled: false, maximum_days: 7 },
            follow_up: {
                manager_after_days: 3,
                day_basis: 'calendar',
                office_hours_only: false,
            },
        }),
    ).toEqual([]);
    const changes = configurationChanges(
        {
            ...configuration,
            document_requests: { enabled: true, maximum_days: 7 },
        },
        configuration,
    );
    expect(changes).toHaveLength(1);
    expect(changes[0]).toMatchObject({ before: 'Yes', after: 'No' });
});

it('roots the breadcrumb at Home and opens on the Overview of review cards', () => {
    render(<ReferralsSettings settings={settings} />);
    expect(crumbs).toHaveBeenCalledWith([
        { title: 'Home', href: '/dashboard' },
        { title: 'Settings', href: '/settings/profile' },
        { title: 'Referral settings', href: '/settings/referrals' },
    ]);
    for (const link of [
        'Review intake and forms',
        'Review routing and follow-up',
        'Review funding pathways',
        'Review permissions and record scope',
    ])
        expect(screen.getByRole('button', { name: link })).toBeVisible();
    expect(screen.getByText('Not published yet')).toBeVisible();
});

it('keeps legacy document requests off until switched on, then counts the unsaved change', () => {
    render(<ReferralsSettings settings={settings} />);
    open('Intake', 'Configuration');
    const toggle = screen.getByRole('switch', {
        name: 'Secure document requests',
    });
    expect(toggle).not.toBeChecked();
    fireEvent.click(toggle);
    expect(toggle).toBeChecked();
    fireEvent.change(screen.getByLabelText('Longest a link stays open'), {
        target: { value: '3' },
    });
    expect(screen.getByText('2 changes not saved')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
    fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
            name: 'Discard changes',
        }),
    );
    expect(
        screen.getByRole('switch', { name: 'Secure document requests' }),
    ).not.toBeChecked();
});

it('edits a service’s delivery settings in a dialog and saves only a draft', async () => {
    respond(
        { data: { governance_required: false, impact } },
        {
            data: { version: { id: 7 } },
        },
    );
    render(<ReferralsSettings settings={settings} />);
    open('Intake', 'Configuration');
    fireEvent.click(screen.getByRole('button', { name: 'Configure' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.click(
        within(dialog).getByRole('switch', {
            name: 'Limit the kinds of setting it can start in',
        }),
    );
    fireEvent.click(
        within(dialog).getByRole('checkbox', {
            name: 'Home & Community Support',
        }),
    );
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Apply to draft' }),
    );
    await reviewAndSave(
        'Map this service to its approved delivery setting',
        () =>
            expect(
                screen.getByText('After: Home & Community Support'),
            ).toBeInTheDocument(),
    );
    await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(2));
    expect(post.mock.calls[0][0]).toBe('/settings/referrals/preview');
    expect(post.mock.calls[1][0]).toBe('/settings/referrals/drafts');
    expect(
        post.mock.calls[1][1].configuration.services[0].context_types,
    ).toEqual(['home_support']);
    await vi.waitFor(() =>
        expect(get).toHaveBeenCalledWith(
            '/settings/referrals',
            { version: 7 },
            { preserveState: false },
        ),
    );
});

it('shows a missing opening time honestly and uses the approved pickers in the calendar dialog', () => {
    render(<ReferralsSettings settings={settings} />);
    open('Workflow', 'Routing & stages');
    expect(screen.getByText(/opening time not recorded/)).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Edit calendar' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('Not recorded')).toBeVisible();
    expect(
        within(dialog).getByRole('button', { name: /^Opening time: / }),
    ).toBeInTheDocument();
    expect(
        within(dialog).getByRole('button', {
            name: /^Add a non-working date: /,
        }),
    ).toBeInTheDocument();
    fireEvent.click(
        within(dialog).getByRole('button', { name: 'Apply to draft' }),
    );
    expect(screen.getByText('No unsaved changes')).toBeVisible();
    expect(configuration.business_calendar.opening_time).toBeUndefined();
});

it('edits reminder timing in place and the opening-hours switch on the delivery tab', () => {
    render(<ReferralsSettings settings={settings} />);
    open('Workflow', 'Routing & stages');
    const days = screen.getByLabelText(
        'Tell a manager when a follow-up is overdue by',
    );
    expect(days).toHaveValue(3);
    fireEvent.change(days, { target: { value: '2' } });
    fireEvent.click(screen.getByRole('tab', { name: 'Delivery & channels' }));
    fireEvent.click(
        screen.getByRole('switch', {
            name: 'Send reminders only during opening hours',
        }),
    );
    expect(screen.getByText('2 changes not saved')).toBeVisible();
    expect(configuration.follow_up).toBeUndefined();
});

it('lists notifications with in-app and email columns, locking the safeguards', () => {
    render(<ReferralsSettings settings={settings} />);
    open('Workflow', 'Notifications');
    expect(
        screen.getByRole('switch', {
            name: 'New referral to review in-app (fixed)',
        }),
    ).toBeDisabled();
    const receipt = screen.getByRole('switch', {
        name: 'Enquiry receipt email',
    });
    expect(receipt).toBeEnabled();
    fireEvent.click(receipt);
    expect(screen.getByText('1 change not saved')).toBeVisible();
    expect(
        screen.getAllByRole('link', { name: /Each person’s choice/ })[0],
    ).toHaveAttribute('href', '/settings/notifications');
});

it('adds a custom hold reason and keeps protected close reasons always on', () => {
    render(<ReferralsSettings settings={settings} />);
    open('Workflow', 'Routing & stages');
    expect(
        screen.queryByRole('switch', { name: 'Offer “Duplicate referral”' }),
    ).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Add hold reason' }));
    fireEvent.change(screen.getByLabelText(/^Name/), {
        target: { value: 'Waiting for an interpreter' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add hold reason' }));
    expect(
        screen.getByRole('switch', {
            name: 'Offer “Waiting for an interpreter”',
        }),
    ).toBeChecked();
    expect(screen.getByText('Added by your organisation')).toBeVisible();
});

it('keeps the exact draft request after an uncertain result', async () => {
    respond(
        { data: { governance_required: false, impact } },
        new Error('Connection interrupted'),
        { data: { version: { id: 7 } } },
    );
    render(<ReferralsSettings settings={settings} />);
    open('Intake', 'Configuration');
    fireEvent.change(screen.getByLabelText('Provider name'), {
        target: { value: 'Reviewed provider name' },
    });
    await reviewAndSave();
    expect(
        await screen.findAllByText(
            'The result could not be confirmed. Your request is kept exactly as sent. Retry to recover the saved version.',
        ),
    ).not.toHaveLength(0);
    fireEvent.click(
        screen.getByRole('button', { name: 'Retry saving the draft' }),
    );
    await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(3));
    expect(post.mock.calls[2][1]).toEqual(post.mock.calls[1][1]);
    expect(post.mock.calls[1][1]).toMatchObject({
        base_version_id: null,
        configuration: { public: { provider_name: 'Reviewed provider name' } },
    });
});

it('freezes a stale draft and exposes a current-settings recovery link', async () => {
    respond(
        { data: { governance_required: false, impact } },
        {
            isAxiosError: true,
            response: {
                status: 409,
                data: { message: 'A newer configuration was published.' },
            },
        },
    );
    render(<ReferralsSettings settings={settings} />);
    open('Intake', 'Configuration');
    fireEvent.change(screen.getByLabelText('Provider name'), {
        target: { value: 'Reviewed provider name' },
    });
    await reviewAndSave();
    await screen.findByText('A newer configuration was published.');
    expect(
        screen.getByRole('link', { name: 'Review latest settings' }),
    ).toHaveAttribute('href', '/settings/referrals');
    expect(screen.getByLabelText('Provider name')).toBeDisabled();
});

it('shows a read-only configuration without offering draft or publication actions', () => {
    render(
        <ReferralsSettings
            settings={{ ...settings, can_manage: false, can_publish: false }}
        />,
    );
    expect(
        screen.getByText(/Changing them needs permission to draft/),
    ).toBeVisible();
    expect(
        screen.queryByRole('button', { name: 'Review changes' }),
    ).not.toBeInTheDocument();
    open('Intake', 'Configuration');
    expect(screen.getByLabelText('Provider name')).toBeDisabled();
    expect(
        screen.getByRole('switch', { name: 'Accept online enquiries' }),
    ).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Add service' }),
    ).not.toBeInTheDocument();
});

it('publishes a colleague’s draft only with a publication note, flagged when safeguards loosen', async () => {
    const draft = {
        id: 9,
        version: 2,
        state: 'draft',
        base_version_id: 1,
        created_by: 5,
        created_by_name: 'Aroha Smith',
        published_by: null,
        reason: 'Allow temporary company funding',
        created_at: '2026-10-19T22:00:00Z',
        published_at: null,
        effective_at: null,
    };
    const published = { ...draft, id: 1, version: 1, state: 'published' };
    post.mockResolvedValue({ data: { version: { id: 9 } } });
    render(
        <ReferralsSettings
            settings={{
                ...settings,
                latest: published,
                effective: published,
                impact: {
                    ...impact,
                    version_id: 9,
                    lines: [
                        {
                            area: 'funding',
                            affects: 'Proposal approvers',
                            text: 'Temporary company funding can be proposed.',
                            loosens: true,
                        },
                    ],
                },
                selected: {
                    ...draft,
                    configuration: {
                        ...configuration,
                        proposals: {
                            ...configuration.proposals,
                            temporary_funding_enabled: true,
                        },
                    },
                    base_configuration: configuration,
                    governance_required: true,
                },
            }}
        />,
    );
    expect(screen.getByText(/saved by Aroha Smith/)).toBeVisible();
    fireEvent.click(
        screen.getAllByRole('button', { name: 'Review and publish' })[0],
    );
    expect(screen.getByText('Loosens a safeguard')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    const publish = screen.getByRole('button', { name: 'Publish version 2' });
    expect(publish).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Reason and approval reference/), {
        target: { value: 'Approved at the October governance meeting' },
    });
    expect(publish).toBeEnabled();
    fireEvent.click(publish);
    await vi.waitFor(() => expect(post).toHaveBeenCalled());
    expect(post.mock.calls[0][0]).toBe('/settings/referrals/9/publish');
    expect(post.mock.calls[0][1]).toMatchObject({
        acknowledge_impact: true,
        publication_note: 'Approved at the October governance meeting',
    });
});

it('discards a waiting draft with a reason', async () => {
    const draft = {
        id: 9,
        version: 2,
        state: 'draft',
        base_version_id: null,
        created_by: 1,
        created_by_name: 'Me',
        published_by: null,
        reason: 'First configuration',
        created_at: '2026-10-19T22:00:00Z',
        published_at: null,
        effective_at: null,
    };
    post.mockResolvedValue({ data: { version: { id: 9 } } });
    render(
        <ReferralsSettings
            settings={{
                ...settings,
                selected: {
                    ...draft,
                    configuration,
                    base_configuration: configuration,
                    governance_required: false,
                },
            }}
        />,
    );
    expect(
        screen.getByText(/A different authorised person must publish/),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Discard draft' }));
    const dialog = screen.getByRole('dialog');
    const confirm = within(dialog).getByRole('button', {
        name: 'Discard draft',
    });
    expect(confirm).toBeDisabled();
    fireEvent.change(within(dialog).getByLabelText(/Reason for discarding/), {
        target: { value: 'Replaced by a fuller draft' },
    });
    fireEvent.click(confirm);
    await vi.waitFor(() =>
        expect(post).toHaveBeenCalledWith('/settings/referrals/9/discard', {
            reason: 'Replaced by a fuller draft',
        }),
    );
});

it('removes private settings and releases the leave guard when access is revoked', async () => {
    respond(
        { data: { governance_required: false, impact } },
        { isAxiosError: true, response: { status: 403 } },
    );
    render(<ReferralsSettings settings={settings} />);
    open('Intake', 'Configuration');
    fireEvent.change(screen.getByLabelText('Provider name'), {
        target: { value: 'Reviewed provider name' },
    });
    await reviewAndSave();
    await screen.findByText('Settings are no longer available');
    expect(screen.queryByLabelText('Provider name')).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: 'Check current access' }),
    ).toHaveAttribute('href', '/settings/referrals');
    const unload = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(unload);
    expect(unload.defaultPrevented).toBe(false);
});
