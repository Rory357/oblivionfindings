import { ReferralReportAges } from '@/components/referrals/report-ages';
import {
    ReferralDistribution,
    ReferralHandoverGauge,
    ReferralTrend,
    ReportPrivacyContext,
} from '@/components/referrals/report-charts';
import { ReferralHistoryReport } from '@/components/referrals/report-history';
import type { ReferralReport } from '@/components/referrals/report-types';
import { ReferralWorkingTimeReport } from '@/components/referrals/report-working-time';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { expect, it, vi } from 'vitest';
import { ReportDates, ReportExport } from './_report-dialogs';

vi.mock('axios', () => ({
    default: { post: vi.fn(), isAxiosError: () => true },
}));
const report: ReferralReport = {
    generated_at: '2026-10-16T00:00:00Z',
    filters: {
        scope: 'received',
        view: 'overview',
        from: '2026-10-15',
        to: '2026-10-16',
        site: 'central',
        owner: 'unassigned',
        source: '',
        stage: '',
        metric: 'received',
        page: 1,
    },
    metrics: [
        {
            key: 'received',
            label: 'Referrals',
            value: 3,
            definition: 'Matching referrals.',
        },
        {
            key: 'contacted',
            label: 'Human contact recorded',
            value: 1,
            definition: 'Successful conversation.',
        },
    ],
    trend: [
        { date: '2026-10-15', received: 0, contacted: 0 },
        { date: '2026-10-16', received: 3, contacted: 1 },
    ],
    groups: {},
    funding: null,
    delays: {
        contact: { mean_hours: 2, count: 1 },
        attempt: { mean_hours: null, count: 0 },
    },
    definitions: {
        scope: 'Received-date cohort.',
        cutoff: 'Current outcomes.',
        trend: 'Successful contact by generation time, grouped by received date.',
        delays: 'Elapsed hours, among recorded contacts.',
        funding: 'Case counts only.',
    },
};

it('keeps exact chart data accessible including zero dates and the contact subset', () => {
    render(<ReferralTrend report={report} onOpen={vi.fn()} />);
    fireEvent.click(screen.getByText('View chart data'));
    const rows = within(screen.getByRole('table')).getAllByRole('row');
    expect(rows).toHaveLength(3);
    expect(rows[1]).toHaveTextContent('15 Oct 2026');
    expect(
        within(rows[1])
            .getAllByRole('cell')
            .map((cell) => cell.textContent),
    ).toEqual(['0', '0']);
    expect(
        within(rows[2])
            .getAllByRole('cell')
            .map((cell) => cell.textContent),
    ).toEqual(['3', '1']);
});

it('does not turn an empty denominator into a zero-percent result', () => {
    render(
        <ReferralHandoverGauge
            report={{
                ...report,
                metrics: report.metrics.map((metric) => ({
                    ...metric,
                    value: 0,
                })),
            }}
            onOpen={vi.fn()}
        />,
    );
    expect(screen.getByText('No referrals in this scope')).toBeVisible();
    expect(screen.queryByText('0%')).not.toBeInTheDocument();
});

it('distinguishes repeated visits, distinct reopened referrals and unknown working intervals', () => {
    const open = vi.fn();
    render(
        <ReferralHistoryReport
            report={{
                ...report,
                history: {
                    referrals: 3,
                    incomplete_referrals: 1,
                    reopenings: 2,
                    reopened_referrals: 1,
                    stages: [
                        {
                            key: 'assessment',
                            count: 3,
                            elapsed: {
                                total_hours: 6,
                                mean_hours: 3,
                                count: 2,
                            },
                            working: {
                                total_hours: null,
                                mean_hours: null,
                                count: 0,
                            },
                            unknown_dates: 1,
                            unknown_calendar: 2,
                        },
                    ],
                    holds: [],
                },
            }}
            onReopened={open}
        />,
    );
    expect(
        screen.getByText(/2 reopening events across 1 referral/),
    ).toBeVisible();
    expect(
        screen.getByText(/History is incomplete for 1 of 3 referrals/),
    ).toBeVisible();
    const visits = within(
        screen.getByRole('region', {
            name: 'Completed stage visits: Assessment',
        }),
    );
    expect(visits.getByText('3 completed intervals')).toBeVisible();
    expect(visits.getByText('Mean · 2 measured intervals')).toBeVisible();
    expect(visits.getByText('Mean · 0 measured intervals')).toBeVisible();
    expect(visits.getByText('Total: — hours')).toBeVisible();
    expect(visits.getByText('Missing interval dates: 1.')).toBeVisible();
    expect(
        screen.getByText('No completed intervals recorded in this scope.'),
    ).toBeVisible();
    fireEvent.click(
        screen.getByRole('button', { name: 'View reopened referrals' }),
    );
    expect(open).toHaveBeenCalledOnce();
});

it('keeps short elapsed intervals distinct from measured zero working hours', () => {
    render(
        <ReferralHistoryReport
            report={{
                ...report,
                history: {
                    referrals: 1,
                    incomplete_referrals: 0,
                    reopenings: 0,
                    reopened_referrals: 0,
                    stages: [],
                    holds: [
                        {
                            key: 'capacity_waitlist',
                            count: 1,
                            elapsed: {
                                total_hours: 0.03,
                                mean_hours: 0.03,
                                count: 1,
                            },
                            working: {
                                total_hours: 0,
                                mean_hours: 0,
                                count: 1,
                            },
                            unknown_dates: 0,
                            unknown_calendar: 0,
                        },
                    ],
                },
            }}
            onReopened={vi.fn()}
        />,
    );
    expect(screen.getByText('Total: 0 hours')).toBeVisible();
    expect(screen.getByText('Total: <0.1 hours')).toBeVisible();
    expect(
        screen.queryByText(/Working calendar unavailable/),
    ).not.toBeInTheDocument();
});

it('renders a zero-count bucket without a misleading coloured bar', () => {
    const { container } = render(
        <ReferralDistribution
            title="Waiting"
            rows={[{ key: '0_6', label: 'Under 7 elapsed days', count: 0 }]}
        />,
    );
    expect(container.querySelector('[style*="width"]')).toHaveStyle({
        width: '0%',
    });
    expect(screen.getByText('0')).toBeVisible();
});

it('distinguishes unavailable working time from measured zero and exposes both denominators', () => {
    render(
        <ReferralWorkingTimeReport
            report={{
                ...report,
                working_time: [
                    {
                        key: 'contact',
                        label: 'Successful human contact',
                        elapsed: { mean_hours: 66, count: 2 },
                        working: { mean_hours: 2, count: 1 },
                        unknown_calendar: 1,
                        unknown_dates: 0,
                    },
                    {
                        key: 'attempt',
                        label: 'First human attempt',
                        elapsed: { mean_hours: 0, count: 1 },
                        working: { mean_hours: 0, count: 1 },
                        unknown_calendar: 0,
                        unknown_dates: 0,
                    },
                    {
                        key: 'hold',
                        label: 'Current hold',
                        elapsed: { mean_hours: 8, count: 1 },
                        working: { mean_hours: null, count: 0 },
                        unknown_calendar: 1,
                        unknown_dates: 1,
                    },
                ],
            }}
        />,
    );
    const contact = within(
        screen.getByRole('region', { name: 'Successful human contact' }),
    );
    expect(contact.getByText('2 referrals')).toBeVisible();
    expect(contact.getByText('1 referral')).toBeVisible();
    expect(
        contact.getByText('Working calendar unavailable for 1.'),
    ).toBeVisible();
    expect(
        within(
            screen.getByRole('region', { name: 'First human attempt' }),
        ).getAllByText('0'),
    ).toHaveLength(2);
    expect(
        within(screen.getByRole('region', { name: 'Current hold' })).getByText(
            '—',
        ),
    ).toBeVisible();
    expect(screen.getByText('Missing or invalid dates: 1.')).toBeVisible();
});

it('drills through with the stable owner key while displaying the staff name', () => {
    const select = vi.fn();
    render(
        <ReferralDistribution
            title="Owners"
            rows={[{ key: '42', label: 'Fixture navigator', count: 3 }]}
            onSelect={select}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: /Fixture navigator: 3 referrals/ }),
    );
    expect(select).toHaveBeenCalledWith('42');
});

it('preserves exact report filters and withdraws the preview when export access is denied', async () => {
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 403 } });
    const denied = vi.fn();
    render(
        <ReportExport
            report={report}
            recordCount={3}
            onClose={vi.fn()}
            onDenied={denied}
        />,
    );
    expect(screen.getByText(/Site: Central intake/)).toHaveTextContent(
        'Owner: Unassigned',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Prepare download' }));
    await waitFor(() => expect(denied).toHaveBeenCalledOnce());
    expect(axios.post).toHaveBeenCalledWith(
        '/operations/referrals/reports/export',
        { ...report.filters, format: 'summary' },
        { responseType: 'blob' },
    );
});

it('rejects future received dates before leaving the date picker', () => {
    render(
        <ReportDates
            filters={{
                ...report.filters,
                from: '2099-01-01',
                to: '2099-01-02',
            }}
            onApply={vi.fn()}
            onClose={vi.fn()}
        />,
    );
    expect(screen.getByRole('button', { name: 'Apply dates' })).toBeDisabled();
    expect(screen.getByRole('alert')).toHaveTextContent('today or earlier');
});

it('reviews the classification label and preserves it in the export request', async () => {
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 403 } });
    render(
        <ReportExport
            report={{
                ...report,
                filters: { ...report.filters, classification: 'duplicate' },
            }}
            recordCount={3}
            onClose={vi.fn()}
            onDenied={vi.fn()}
        />,
    );
    expect(screen.getByText(/Confirmed duplicate closures/)).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Prepare download' }));
    await waitFor(() =>
        expect(axios.post).toHaveBeenCalledWith(
            '/operations/referrals/reports/export',
            {
                ...report.filters,
                classification: 'duplicate',
                format: 'summary',
            },
            { responseType: 'blob' },
        ),
    );
});

it('shows the reported region label in export review while retaining its exact stable filter', async () => {
    vi.mocked(axios.post).mockRejectedValue({ response: { status: 403 } });
    const region = 'a'.repeat(64);
    render(
        <ReportExport
            report={{
                ...report,
                filters: { ...report.filters, region },
                groups: {
                    region: [{ key: region, label: 'Waikato Area', count: 3 }],
                },
            }}
            recordCount={3}
            onClose={vi.fn()}
            onDenied={vi.fn()}
        />,
    );
    expect(screen.getByText(/Reported region: Waikato Area/)).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Prepare download' }));
    await waitFor(() =>
        expect(axios.post).toHaveBeenCalledWith(
            '/operations/referrals/reports/export',
            { ...report.filters, region, format: 'summary' },
            { responseType: 'blob' },
        ),
    );
});

it('keeps stage drill-down available without revealing funding age data', () => {
    const stage = vi.fn();
    render(
        <ReferralReportAges
            report={{
                ...report,
                groups: {
                    stage_age: [
                        { key: '7_13', label: '7–13 elapsed days', count: 2 },
                    ],
                },
            }}
            onStage={stage}
            onFunding={vi.fn()}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: /7–13 elapsed days: 2 referrals/ }),
    );
    expect(stage).toHaveBeenCalledWith('7_13');
    expect(
        screen.queryByText('Pending provider-funding applications'),
    ).not.toBeInTheDocument();
});

it('drills into provider and income applications with distinct case counts and stable filters', () => {
    const funding = vi.fn();
    render(
        <ReferralReportAges
            report={{
                ...report,
                funding: {
                    cases: 3,
                    provider_cases: 2,
                    income_cases: 1,
                    by_pathway: [],
                    by_status: [],
                    outcomes: [],
                    pending_age: {
                        provider: [
                            {
                                key: '7_13',
                                label: '7–13 elapsed days',
                                count: 2,
                            },
                        ],
                        income: [
                            {
                                key: 'unknown',
                                label: 'Date missing or invalid',
                                count: 1,
                            },
                        ],
                    },
                },
            }}
            onStage={vi.fn()}
            onFunding={funding}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: /7–13 elapsed days: 2 cases/ }),
    );
    expect(funding).toHaveBeenLastCalledWith('provider', '7_13');
    fireEvent.click(
        screen.getByRole('button', {
            name: /Date missing or invalid: 1 cases/,
        }),
    );
    expect(funding).toHaveBeenLastCalledWith('income', 'unknown');
});

const privacy = {
    threshold: 5,
    label: 'Fewer than 5',
    paired_label: 'Hidden',
    active: true,
};

it('shows hidden small groups as words, never 0, without their bars, and says why', () => {
    const select = vi.fn();
    const { container } = render(
        <ReportPrivacyContext.Provider value={privacy}>
            <ReferralDistribution
                title="Stages"
                onSelect={select}
                rows={[
                    { key: 'triage', count: 9 },
                    { key: 'new', count: null, suppressed: 'paired' },
                    { key: 'assessment', count: null, suppressed: 'small' },
                ]}
            />
        </ReportPrivacyContext.Provider>,
    );
    expect(screen.getByText('Fewer than 5')).toBeVisible();
    expect(screen.getByText('Hidden')).toBeVisible();
    expect(screen.queryByText('0')).not.toBeInTheDocument();
    // Only the shown group draws a bar and opens its records.
    expect(container.querySelectorAll('[style*="width"]')).toHaveLength(1);
    expect(screen.getAllByRole('button')).toHaveLength(1);
    expect(
        screen.getByText(
            /Groups of fewer than 5 referrals show as “Fewer than 5”, and one more may show as “Hidden”/,
        ),
    ).toBeVisible();
});

it('leaves hidden days out of the trend and shows them as words in its data table', () => {
    render(
        <ReferralTrend
            report={{
                ...report,
                privacy,
                trend: [
                    {
                        date: '2026-10-15',
                        received: null,
                        contacted: null,
                        received_suppressed: 'small',
                        contacted_suppressed: 'small',
                    },
                    {
                        date: '2026-10-16',
                        received: 9,
                        contacted: null,
                        contacted_suppressed: 'small',
                    },
                ],
                delays: {
                    contact: {
                        mean_hours: null,
                        count: null,
                        suppressed: 'small',
                    },
                    attempt: { mean_hours: 4, count: 6 },
                },
            }}
            onOpen={vi.fn()}
        />,
    );
    expect(
        screen.getByText(
            /Days with fewer than 5 referrals or contacts are left out/,
        ),
    ).toBeVisible();
    expect(screen.getByText('Not shown')).toBeVisible();
    expect(screen.getByText('Not shown: fewer than 5 referrals')).toBeVisible();
    fireEvent.click(screen.getByText('View chart data'));
    const rows = within(screen.getByRole('table')).getAllByRole('row');
    expect(
        within(rows[1])
            .getAllByRole('cell')
            .map((cell) => cell.textContent),
    ).toEqual(['Fewer than 5', 'Fewer than 5']);
    expect(
        within(rows[2])
            .getAllByRole('cell')
            .map((cell) => cell.textContent),
    ).toEqual(['9', 'Fewer than 5']);
});

it('does not draw a handover share from a hidden small group', () => {
    render(
        <ReferralHandoverGauge
            report={{
                ...report,
                privacy,
                metrics: [
                    {
                        key: 'received',
                        label: 'Referrals',
                        value: 12,
                        definition: 'Matching referrals.',
                    },
                    {
                        key: 'converted',
                        label: 'Handed over',
                        value: null,
                        suppressed: 'small',
                        definition: 'Linked to a person.',
                    },
                ],
            }}
            onOpen={vi.fn()}
        />,
    );
    expect(
        screen.getByText('Fewer than 5 of 12 linked to a supported person'),
    ).toBeVisible();
    expect(screen.queryByText(/%/)).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', {
            name: 'Handed over: Fewer than 5. View matching referrals',
        }),
    ).toBeVisible();
});
