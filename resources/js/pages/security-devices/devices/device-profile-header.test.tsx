import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { DeviceProfile } from './device-profile';
import { DeviceProfileHeader } from './device-profile-header';
import DeviceShow, { type Props } from './show';

const auth = vi.hoisted(() => ({
    user: { id: 7 },
    can: { securityDevices: { viewAny: true, devicesView: true } },
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: React.ReactNode }) => (
        <a href={href}>{children}</a>
    ),
    usePage: () => ({ props: { auth } }),
    router: { reload: vi.fn(), visit: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const profile = {
    header: {
        identity: {
            id: 9,
            name: 'Reception gateway',
            uid: 'GW-9',
            type: 'Network gateway',
            manufacturer: 'Example vendor',
            model: 'Model A',
        },
        location: {
            id: 12,
            type: 'site',
            name: 'Harbour Site',
            href: '/sites/12',
        },
        assignment: {
            type: 'site',
            name: 'Harbour Site',
            assignedAt: '2026-10-13T00:00:00Z',
            expectedReturnAt: null,
        },
        health: {
            state: 'unknown',
            label: 'Health not measured',
            deviceState: 'active',
            deviceStateLabel: 'Active',
        },
        freshness: {
            state: 'stale',
            observedAt: '2026-10-12T00:00:00Z',
            staleAfterSeconds: 900,
        },
        providerObservation: {
            provider: 'unifi',
            label: 'Last collected provider reading',
            observedAt: '2026-10-12T00:00:00Z',
            source: 'imported',
        },
        requiredAction: {
            state: 'attention',
            label: 'Review observation',
            description: 'No current reading has been recorded',
            section: 'health',
        },
    },
    health: { monitoring: { healthy: 0, enabled: 0 } },
    sections: [
        { key: 'health', label: 'Health', group: 'status' },
        { key: 'monitors', label: 'Monitors', group: 'status' },
        { key: 'assignments', label: 'Assignments', group: 'operations' },
    ],
} as unknown as DeviceProfile;

function header(data = profile) {
    const onSection = vi.fn(),
        onSearch = vi.fn();
    render(
        <DeviceProfileHeader
            profile={data}
            asOf="2026-10-13T08:00:00Z"
            onSection={onSection}
            onSearch={onSearch}
            actions={<button>Edit</button>}
            rail={<span>Profile sections</span>}
        />,
    );
    return { onSection, onSearch };
}

describe('Device profile shared header', () => {
    it('retains identity, Site destination, explicit freshness and truthful recorded state', () => {
        header();
        expect(
            screen.getByRole('heading', { name: 'Reception gateway' }),
        ).toBeVisible();
        expect(screen.getByText('Device UID: GW-9')).toBeInTheDocument();
        expect(
            screen.getByText('Observation freshness: stale'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Harbour Site' }),
        ).toHaveAttribute('href', '/sites/12');
        expect(screen.getAllByText('Active')[0]).toBeVisible();
        expect(screen.queryByText('Online')).not.toBeInTheDocument();
        expect(screen.getByText('0/0')).toBeVisible();
        expect(
            screen.getByText(/not an atomic or live health check/),
        ).toBeInTheDocument();
    });
    it('connects all meters to returned profile sections and opens shared section search', () => {
        const { onSection, onSearch } = header();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Open Health: Recorded health',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Monitors: Monitoring' }),
        );
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Open Assignments: Assignment',
            }),
        );
        expect(onSection.mock.calls.map(([value]) => value)).toEqual([
            'health',
            'monitors',
            'assignments',
        ]);
        fireEvent.click(screen.getByRole('button', { name: 'Find a section' }));
        expect(onSearch).toHaveBeenCalledOnce();
    });
    it('uses a reachable returned section when monitoring is withheld', () => {
        const { onSection } = header({
            ...profile,
            sections: profile.sections.filter((s) => s.key === 'health'),
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Health: Monitoring' }),
        );
        expect(onSection).toHaveBeenCalledWith('health');
    });
    it('distinguishes unmeasured monitors, missing observation and an unnamed real assignment', () => {
        header({
            ...profile,
            header: {
                ...profile.header,
                freshness: { ...profile.header.freshness, observedAt: null },
                assignment: { ...profile.header.assignment!, name: null },
            },
            health: { ...profile.health, monitoring: null },
        });
        expect(screen.getByText('Unavailable')).toBeVisible();
        expect(screen.getByText('Not recorded')).toBeVisible();
        expect(screen.getByText('Name not recorded')).toBeVisible();
    });
    it.each([
        ['active', 'Active', 'bg-status-success-bg'],
        ['degraded', 'Degraded', 'bg-status-warning-bg'],
        ['offline', 'Offline', 'bg-status-critical-bg'],
        // S3 (H&S plan B1.8): an info chip on the header band is an opaque card pill with brand text.
        ['maintenance', 'Maintenance', 'bg-card'],
        [null, 'Device state not recorded', 'bg-muted'],
    ])(
        'tones the title chip for the recorded %s device state',
        (deviceState, text, tone) => {
            header({
                ...profile,
                header: {
                    ...profile.header,
                    health: {
                        ...profile.header.health,
                        deviceState,
                        deviceStateLabel: deviceState ? text : null,
                    },
                },
            });
            // The title chip carries the Activity icon; the meter caption repeats the words without it.
            const chip = screen
                .getAllByText(text)
                .find((element) => element.querySelector('svg'));
            expect(chip).toBeDefined();
            expect(chip).toHaveClass(tone);
        },
    );
    it.each([
        ['cctv', 'lucide-cctv'],
        ['access_control', 'lucide-key-round'],
        ['network', 'lucide-router'],
        ['personal_tracker', 'lucide-map-pin'],
        [null, 'lucide-cpu'],
        ['unlisted_kind', 'lucide-cpu'],
    ])('marks a %s device with a fitting icon', (category, icon) => {
        header({
            ...profile,
            header: {
                ...profile.header,
                identity: { ...profile.header.identity, category },
            },
        });
        expect(document.querySelector('.eh-mark-ring svg')).toHaveClass(icon);
    });
    it('keeps the last observation relative time current and shows the exact time', () => {
        vi.useFakeTimers({ now: new Date('2026-10-12T00:00:30Z') });
        try {
            header();
            const meter = screen.getByRole('button', {
                name: 'Open Health: Last observation',
            });
            expect(meter).toHaveTextContent('just now');
            expect(meter).toHaveTextContent(
                formatDateTime('2026-10-12T00:00:00Z'),
            );
            act(() => vi.advanceTimersByTime(5 * 60_000));
            expect(meter).toHaveTextContent('5m ago');
        } finally {
            vi.useRealTimers();
        }
    });
    it('withholds all device context for another actor and only performs a full read recovery', () => {
        const denied = {
            actor_id: 88,
            as_of: '2026-10-13T08:00:00Z',
            device: { id: 9, name: 'Private record' },
        } as unknown as Props;
        render(<DeviceShow {...denied} />);
        expect(
            screen.getByRole('heading', { name: 'Device access required' }),
        ).toBeVisible();
        expect(screen.queryByText('Private record')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh current record' }),
        );
        expect(router.visit).toHaveBeenCalledWith(
            '/security-devices/devices/9',
            expect.objectContaining({
                preserveState: false,
                preserveScroll: true,
            }),
        );
    });
});
