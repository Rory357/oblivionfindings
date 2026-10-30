import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import ControlRoomCoverage, { coverageNextAction, reportAge, type CoverageRow } from './coverage';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    url: '/control-room/coverage',
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        preserveState: _preserveState,
        preserveScroll: _preserveScroll,
        ...props
    }: {
        href: string;
        children: ReactNode;
        preserveState?: boolean;
        preserveScroll?: boolean;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        url: inertia.url,
        props: { auth: { can: { controlRoom: { viewAny: true } } } },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

function row(overrides: Partial<CoverageRow>): CoverageRow {
    return {
        key: '2|sensor',
        site_id: 2,
        site_name: 'Kōwhai Lodge',
        type: 'sensor',
        type_label: 'Sensor',
        sources: [],
        devices: 1,
        reporting: 1,
        quiet: 0,
        never: 0,
        state: 'reporting',
        last_signal_at: minutesAgo(5),
        ...overrides,
    };
}

function props(
    overrides: Partial<ComponentProps<typeof ControlRoomCoverage>> = {},
): ComponentProps<typeof ControlRoomCoverage> {
    return {
        rows: [
            row({
                key: '2|bed_sensor',
                type: 'bed_sensor',
                type_label: 'Bed Sensor',
                devices: 3,
                reporting: 2,
                quiet: 1,
                state: 'quiet',
                last_signal_at: minutesAgo(3 * 24 * 60),
            }),
            row({ key: '3|none', site_id: 3, site_name: 'Tōtara House', type: null, type_label: null, devices: 0, reporting: 0, state: 'none', last_signal_at: null }),
            row({ sources: ['Milesight gateway'], devices: 2, reporting: 2 }),
        ],
        stats: { houses: 2, houses_covered: 1, sources: 2, reporting: 1, quiet: 1, never: 0, no_monitoring: 1 },
        filters: { site_id: '', type: '', state: '' },
        sites: [
            { id: 2, name: 'Kōwhai Lodge' },
            { id: 3, name: 'Tōtara House' },
        ],
        device_types: { sensor: 'Sensor', bed_sensor: 'Bed Sensor', camera: 'Camera' },
        quiet_after_hours: 24,
        can: { view_canonical_devices: true },
        canonicalIndexUrl: '/security-devices/devices',
        ...overrides,
    };
}

const tableRow = (house: string, state: string) =>
    [...document.querySelectorAll(`tr[data-state="${state}"]`)].find((tr) =>
        tr.textContent?.includes(house),
    ) as HTMLElement;

describe('Monitoring › Coverage', () => {
    it('opens on the Monitoring header; every number filters the table by its state', () => {
        render(<ControlRoomCoverage {...props()} />);

        expect(screen.getByRole('heading', { level: 1, name: 'Monitoring' })).toBeInTheDocument();
        expect(screen.getByText(/Quiet means no signal for 24 hours/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Show every house and source' })).toHaveAttribute(
            'href',
            '/control-room/coverage',
        );
        expect(screen.getByRole('link', { name: 'Show sources that have gone quiet' })).toHaveAttribute(
            'href',
            '/control-room/coverage?state=quiet',
        );
        expect(screen.getByRole('link', { name: 'Show sources that have never reported' })).toHaveAttribute(
            'href',
            '/control-room/coverage?state=never',
        );
        expect(screen.getByRole('link', { name: 'Show houses with no monitoring' })).toHaveAttribute(
            'href',
            '/control-room/coverage?state=none',
        );
        expect(screen.getByRole('link', { name: 'Open Security & Devices' })).toHaveAttribute(
            'href',
            '/security-devices/devices',
        );
    });

    it('header numbers keep the house and source filters', () => {
        render(<ControlRoomCoverage {...props({ filters: { site_id: '2', type: 'bed_sensor', state: '' } })} />);

        expect(screen.getByRole('link', { name: 'Show sources that have gone quiet' })).toHaveAttribute(
            'href',
            '/control-room/coverage?site_id=2&type=bed_sensor&state=quiet',
        );
        expect(screen.getByRole('link', { name: 'Show houses with no monitoring' })).toHaveTextContent(
            'house with no bed sensors',
        );
    });

    it('shows house first, then the source, its age and state in plain words', () => {
        render(<ControlRoomCoverage {...props()} />);

        const quiet = tableRow('Kōwhai Lodge', 'quiet');
        expect(within(quiet).getByText('Bed sensor')).toBeInTheDocument();
        expect(within(quiet).getByText('3 days ago')).toBeInTheDocument();
        expect(within(quiet).getByText('1 of 3 quiet')).toBeInTheDocument();
        expect(within(quiet).getByText('Quiet')).toBeInTheDocument();
        expect(within(quiet).getByRole('link', { name: 'Check the quiet sources' })).toHaveAttribute(
            'href',
            '/control-room/devices?site_id=2&type=bed_sensor&activity=quiet',
        );

        const reporting = tableRow('Kōwhai Lodge', 'reporting');
        expect(within(reporting).getByText('2 devices · Milesight gateway')).toBeInTheDocument();
        expect(within(reporting).getByText('5 min ago')).toBeInTheDocument();
        expect(within(reporting).getByRole('link', { name: 'View signals' })).toHaveAttribute(
            'href',
            '/control-room/devices?site_id=2&type=sensor',
        );

        const none = tableRow('Tōtara House', 'none');
        expect(within(none).getByText('No sources')).toBeInTheDocument();
        expect(within(none).getByText('No monitoring')).toBeInTheDocument();
        expect(
            within(none).getByRole('link', { name: 'Connect a source in Security & Devices' }),
        ).toHaveAttribute('href', '/security-devices/devices?site_id=3');
        // Hide empty: no dash placeholders.
        expect(none.textContent).not.toContain('—');
    });

    it('rows keep the kebab and the right-click menu with the same actions', () => {
        render(<ControlRoomCoverage {...props()} />);

        fireEvent.contextMenu(tableRow('Kōwhai Lodge', 'quiet'));
        expect(screen.getByRole('menuitem', { name: 'Open signals' })).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: 'Open quiet sources' })).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: 'Open in Security & Devices' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Show only this house' }));
        expect(inertia.get).toHaveBeenCalledWith(
            '/control-room/coverage',
            { site_id: '2' },
            expect.objectContaining({ preserveState: true }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Actions for Kōwhai Lodge Sensor' }));
        expect(screen.getByRole('menuitem', { name: 'Open signals' })).toBeInTheDocument();
    });

    it('without Security & Devices access, a house with no monitoring says who to ask, with no link', () => {
        render(<ControlRoomCoverage {...props({ can: { view_canonical_devices: false }, canonicalIndexUrl: null })} />);

        const none = tableRow('Tōtara House', 'none');
        expect(within(none).getByText('Ask a manager to connect a source')).toBeInTheDocument();
        expect(within(none).queryByRole('link')).not.toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Open Security & Devices' })).not.toBeInTheDocument();
    });

    it('one empty message when nothing matches', () => {
        render(<ControlRoomCoverage {...props({ rows: [], filters: { site_id: '', type: '', state: 'never' } })} />);

        expect(screen.getByText('Nothing matches these filters')).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it('words ages and next actions without bare times', () => {
        const now = new Date('2026-10-24T12:00:00Z').getTime();
        expect(reportAge('2026-10-24T11:59:30Z', now)).toBe('just now');
        expect(reportAge('2026-08-10T12:00:00Z', now)).toBe('75 days ago');
        expect(reportAge(null, now)).toBeNull();

        expect(coverageNextAction(row({ state: 'never', never: 1, reporting: 0 }), null)).toEqual({
            label: 'Check the set-up',
            href: '/control-room/devices?site_id=2&type=sensor&activity=never',
        });
    });
});
