import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Overview, { type WorkerOverviewProps } from './index';

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, get: vi.fn(), visit: vi.fn(), on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();

function worker(overrides: Partial<WorkerOverviewProps> = {}): WorkerOverviewProps {
    return {
        view: 'worker',
        tab: 'overview',
        site: { id: 4, name: 'Synthetic House' },
        sites: [{ value: '4', label: 'Synthetic House' }],
        shift: { starts_at: minutesAgo(120), ends_at: new Date(Date.now() + 4 * 3_600_000).toISOString() },
        on_shift: true,
        meters: [
            { key: 'alarm', label: 'Alarm', value: 'Online', caption: { text: 'Synthetic panel · armed state not shown to you', at: null }, tone: null, device_id: 21 },
            { key: 'safety', label: 'Safety', value: '1 alert', caption: { text: 'Synthetic button: panic button pressed', at: null }, tone: 'critical', device_id: null },
            { key: 'panic', label: 'Panic buttons', value: '1 ready', caption: { text: 'Answering, battery fine', at: null }, tone: 'success', device_id: null },
        ],
        safety: [
            {
                id: 31,
                title: 'Synthetic button: panic button pressed',
                tone: 'critical',
                safety: true,
                at: minutesAgo(12),
                sub: 'Panic button pressed. Control Room has it too',
                then: 'It stays open until someone who looks after alerts marks it done.',
            },
        ],
        kit: {
            brand: null,
            reported_at: null,
            items: [
                {
                    key: 'alarm-21',
                    kind: 'alarm',
                    id: 21,
                    name: 'Synthetic panel',
                    sub: 'Synthetic Hub',
                    flag: { tone: 'success', text: 'Online' },
                    facts: [{ tone: 'info', text: 'Last heard from {time}', at: minutesAgo(2) }],
                    panel: null,
                },
                {
                    key: 'panic-22',
                    kind: 'panic',
                    id: 22,
                    name: 'Synthetic button',
                    sub: 'Staff office',
                    flag: { tone: 'success', text: 'Ready' },
                    facts: [{ tone: 'success', text: 'Battery 64%', at: null }],
                    panel: null,
                    ready: true,
                },
            ],
        },
        around: [
            {
                key: 'place:kitchen',
                name: 'Kitchen',
                sub: 'Synthetic fridge sensor',
                flag: { tone: 'success', text: 'All fine' },
                facts: [{ tone: 'success', text: 'Synthetic fridge sensor: 3.2 °C', at: null }],
                href: null,
            },
        ],
        told: [{ id: 'n-1', at: minutesAgo(12), tone: 'critical', title: 'Synthetic button: panic button pressed', sub: 'Panic button pressed' }],
        can: { open_devices: true, open_alerts: false },
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
});

describe('Support worker’s Overview (1c)', () => {
    it('leads with safety, then the house’s doors, alarm and sensors, and no cameras or setup', () => {
        render(<Overview {...worker()} />);

        expect(screen.getByRole('heading', { name: 'Security & devices' })).toBeInTheDocument();
        expect(screen.getByText(/Synthetic House, where you’re on shift today/)).toBeInTheDocument();
        expect(screen.getAllByText('Synthetic button: panic button pressed').length).toBeGreaterThan(0);
        expect(screen.getByText(/^Safety · since/)).toBeInTheDocument();
        expect(screen.getByText('Doors and alarm')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Synthetic panel/ })).toHaveAttribute('href', '/security-devices/devices/21');
        expect(screen.getByText('Around the house')).toBeInTheDocument();
        expect(screen.getByText('Synthetic fridge sensor: 3.2 °C')).toBeInTheDocument();
        expect(screen.getByText('What you see here')).toBeInTheDocument();
        expect(screen.getByText('No live cameras.')).toBeInTheDocument();
        expect(screen.getByText('Told to you today')).toBeInTheDocument();
        // Not the IT Overview: no Add device, Scan network, Trends or Sites.
        expect(screen.queryByRole('button', { name: /Add device|Scan network/ })).not.toBeInTheDocument();
        expect(screen.queryByText('Trends')).not.toBeInTheDocument();
        expect(screen.queryByText('Cameras')).not.toBeInTheDocument();
        // Someone who can't work alerts isn't offered "Open alert".
        expect(screen.queryByRole('link', { name: 'Open alert' })).not.toBeInTheDocument();
    });

    it('says plainly when nothing has been told and the person is off shift, and offers their other Sites', () => {
        render(
            <Overview
                {...worker({
                    on_shift: false,
                    shift: null,
                    safety: [],
                    told: [],
                    sites: [
                        { value: '4', label: 'Synthetic House' },
                        { value: '5', label: 'Synthetic Lodge' },
                    ],
                })}
            />,
        );

        expect(screen.getByText(/Nothing has been told to you/)).toBeInTheDocument();
        expect(screen.getByText('Nothing yet today.')).toBeInTheDocument();
        expect(screen.getByText('Synthetic House · doors, alarm and sensors')).toBeInTheDocument();
        const header = screen.getByRole('heading', { name: 'Security & devices' }).closest('header') ?? document.body;
        expect(within(header as HTMLElement).getByText('Synthetic House')).toBeInTheDocument();
    });

    it('explains when the person isn’t linked to a Site yet', () => {
        render(<Overview {...worker({ site: null, sites: [], meters: [], safety: [], kit: { brand: null, reported_at: null, items: [] }, around: [], told: [] })} />);

        expect(screen.getByText(/doesn’t link you to a Site yet/)).toBeInTheDocument();
        expect(screen.queryByText('Doors and alarm')).not.toBeInTheDocument();
    });
});
