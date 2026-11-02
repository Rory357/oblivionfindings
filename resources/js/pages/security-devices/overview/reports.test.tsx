import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { minutesWords, viewWhat, waitWords } from './_reports';
import type { ReportsData } from './_types';
import Overview, { type OverviewProps } from './index';

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, get: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

function reports(overrides: Partial<ReportsData> = {}): ReportsData {
    return {
        month: { key: '2026-10', label: 'October 2026', short: 'October', range: '1–31 Oct', current: true, ready_at: '2026-10-31T18:00:00Z' },
        months: [
            { value: '2026-10', label: 'October 2026' },
            { value: '2026-09', label: 'September 2026' },
        ],
        site_label: 'All your Sites',
        device_count: 12,
        offline: {
            sites: [
                { name: 'Synthetic Office', minutes: 0, still_offline: false },
                { name: 'Synthetic House', minutes: 38, still_offline: false },
                { name: 'Synthetic Lodge', minutes: 120, still_offline: true },
            ],
        },
        alerts: {
            total: 9,
            kinds: [
                { label: 'Device offline', count: 5 },
                { label: 'Battery low', count: 3 },
                { label: 'Panic button', count: 1 },
            ],
        },
        safety: {
            weeks: [
                { label: '3 Aug', minutes: 6.1 },
                { label: '10 Aug', minutes: null },
                { label: '17 Aug', minutes: 2.1 },
            ],
            month: { count: 2, picked: 1, not_picked: 1, average_minutes: 2.1 },
        },
        upkeep: { batteries: 3, software: 0, settings: 6 },
        cameras: { cameras: 2, with_hours: 1488, without_hours: 7.5, percent: 99.4, worst: [{ name: 'Synthetic yard camera', hours: 7.5 }] },
        camera_views: {
            rows: [
                { key: 'live-1', at: '2026-10-27T21:15:00Z', who: 'Synthetic Manager', camera: 'Synthetic door camera · Synthetic House', camera_id: 31, kind: 'live', from: null, to: null, reason: 'Incident review' },
                { key: 'clip-a', at: '2026-10-23T02:40:00Z', who: 'Synthetic Lead', camera: 'Synthetic door camera · Synthetic House', camera_id: 31, kind: 'clip', from: '2026-10-23T02:15:00Z', to: '2026-10-23T02:16:00Z', reason: '' },
            ],
            total: 2,
            live: true,
            clips: true,
            export: '/security-devices/monthly-report/camera-views.csv?month=2026-10',
        },
        kept: { door_names_days: 90, audit_years: 2, months: 13 },
        links: {
            pdf: '/security-devices/monthly-report.pdf?month=2026-10',
            csv: '/security-devices/monthly-report.csv?month=2026-10',
            devices: '/security-devices/exports/devices.csv',
            events: '/security-devices/exports/events.csv',
            upkeep: '/security-devices/exports/upkeep.csv',
        },
        email: {
            on: true,
            recipients: [
                { id: 3, name: 'Synthetic Manager' },
                { id: 4, name: 'Synthetic Lead' },
            ],
            next_at: '2026-10-31T18:00:00Z',
            can_change: true,
            choices: [
                { id: 3, name: 'Synthetic Manager' },
                { id: 4, name: 'Synthetic Lead' },
                { id: 5, name: 'Synthetic Officer' },
            ],
        },
        ...overrides,
    };
}

function fixture(overrides: Partial<OverviewProps> = {}): OverviewProps {
    return {
        tab: 'reports',
        site: 'all',
        sites: [
            { value: '4', label: 'Synthetic House' },
            { value: '5', label: 'Synthetic Lodge' },
        ],
        first_time: null,
        meters: {
            online: { count: 2, of: 3, caption: { text: 'Checked {ago}', at: new Date(Date.now() - 60_000).toISOString() } },
            offline: { count: 1, caption: { text: 'Synthetic router, since {time}', at: new Date(Date.now() - 3_600_000).toISOString() } },
            cant_check: { count: 0, caption: 'Everything can be checked' },
            alerts: { count: 0, caption: 'None open' },
        },
        overview: null,
        trends: null,
        reports: reports(),
        can: { add: true, scan: true, connect: true, assign: true, reports: true },
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
});

describe('Overview › Reports (pg-sdr-18b)', () => {
    it('leads with the month’s report, its downloads and who gets the email', () => {
        render(<Overview {...fixture()} />);

        expect(screen.getByRole('tab', { name: 'Reports' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'October report for managers and the board' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Download PDF' })).toHaveAttribute('href', '/security-devices/monthly-report.pdf?month=2026-10');
        expect(screen.getByRole('link', { name: 'Download CSV' })).toHaveAttribute('href', '/security-devices/monthly-report.csv?month=2026-10');
        expect(screen.getByText(/Oblivion emails it on the 1st of each month to/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Change who gets it' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Download Device list (CSV)' })).toHaveAttribute('href', '/security-devices/exports/devices.csv');
        expect(screen.getByRole('link', { name: 'Download Upkeep log (CSV)' })).toHaveAttribute('href', '/security-devices/exports/upkeep.csv');
    });

    it('tells each figure in plain words from the server’s numbers', () => {
        render(<Overview {...fixture()} />);

        expect(screen.getByText('Synthetic Lodge 2 h')).toBeInTheDocument();
        expect(screen.getByText(/still offline now/)).toBeInTheDocument();
        expect(screen.getByText('9 in October')).toBeInTheDocument();
        expect(screen.getByText('2 min 6 s on average in October')).toBeInTheDocument();
        expect(screen.getByText(/1 not picked up/)).toBeInTheDocument();
        expect(screen.getByText('Upkeep in October')).toBeInTheDocument();
        expect(screen.getByText('99.4%')).toBeInTheDocument();
        expect(screen.getByText('Synthetic yard camera 7.5 h without a picture.')).toBeInTheDocument();
        expect(screen.queryByText(/Door event exports/)).not.toBeInTheDocument();
    });

    it('lists who watched cameras, with the reason, and exports the log', () => {
        render(<Overview {...fixture()} />);

        const log = screen.getByRole('region', { name: 'Camera viewing log' });
        expect(within(log).getByText('Incident review')).toBeInTheDocument();
        expect(within(log).getByText('Asked to watch live')).toBeInTheDocument();
        expect(within(log).getByText('No reason given')).toBeInTheDocument();
        expect(within(log).getByRole('link', { name: 'Export' })).toHaveAttribute('href', '/security-devices/monthly-report/camera-views.csv?month=2026-10');
    });

    it('says “Not available” instead of inventing a figure, and hides the camera log from people without the key', () => {
        render(
            <Overview
                {...fixture({
                    reports: reports({
                        offline: null,
                        safety: null,
                        upkeep: null,
                        cameras: null,
                        camera_views: null,
                        alerts: { total: 0, kinds: [] },
                        email: { on: false, recipients: [], next_at: '2026-10-31T18:00:00Z', can_change: false, choices: [] },
                    }),
                })}
            />,
        );

        expect(screen.getByText('Not available: no router or gateway is watched.')).toBeInTheDocument();
        expect(screen.getByText('Not available: no camera is watched.')).toBeInTheDocument();
        expect(screen.getByText('No device alerts in October.')).toBeInTheDocument();
        expect(screen.queryByText('Camera viewing log')).not.toBeInTheDocument();
        expect(screen.getByText('The monthly email is off')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Turn it on' })).not.toBeInTheDocument();
    });

    it('opens the monthly email pop-up with people who can see reports', () => {
        render(<Overview {...fixture()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Change who gets it' }));

        const group = screen.getByRole('group', { name: 'Who gets it' });
        expect(within(group).getByLabelText('Synthetic Officer')).not.toBeChecked();
        expect(within(group).getByLabelText('Synthetic Manager')).toBeChecked();
        expect(screen.getByRole('switch', { name: 'Email the report every month' })).toBeChecked();
    });

    it('keeps the Reports tab from people who may not see reports', () => {
        render(<Overview {...fixture({ tab: 'overview', reports: null, can: { add: true, scan: true, connect: true, assign: true, reports: false }, overview: { attention: [], attention_count: 0, sites: [], cameras: [], camera_count: 0, hubs: null, activity: [] } })} />);

        expect(screen.queryByRole('tab', { name: 'Reports' })).not.toBeInTheDocument();
    });
});

describe('report words', () => {
    it('writes durations the way the drawings do', () => {
        expect(minutesWords(38)).toBe('38 min');
        expect(minutesWords(120)).toBe('2 h');
        expect(minutesWords(125)).toBe('2 h 5 min');
        expect(waitWords(2.1)).toBe('2 min 6 s');
        expect(waitWords(0.5)).toBe('30 s');
        expect(viewWhat({ kind: 'live', from: null, to: null, at: '2026-10-27T21:15:00Z' }, new Date('2026-10-29T00:00:00Z'))).toBe('Asked to watch live');
        expect(viewWhat({ kind: 'clip', from: '2026-10-23T02:15:00Z', to: '2026-10-23T02:16:00Z', at: '2026-10-23T02:40:00Z' }, new Date('2026-10-29T00:00:00Z'))).toBe('Clip saved, 3:15 pm – 3:16 pm');
    });
});
