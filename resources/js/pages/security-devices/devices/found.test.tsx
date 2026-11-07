import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import FoundDevices, { type FoundDevicesProps, type FoundRow } from './found';

const inertia = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), visit: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({
            props: {
                auth: { user: { id: 7 } },
                matchDevices: [
                    { value: '30', label: 'Synthetic printer', description: 'Synthetic M4 · 10.0.0.60' },
                    { value: '31', label: 'Synthetic Wi-Fi', description: 'Synthetic U6 · 10.0.0.13' },
                ],
            },
        }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

function row(overrides: Partial<FoundRow>): FoundRow {
    return {
        id: 1,
        tab: 'new',
        guess: 'Synthetic camera',
        sub: 'Synthetic DS-1 · “Back yard 2”',
        kind: 'camera',
        address: '10.0.0.41',
        maker: 'Synthetic',
        why: 'Matched ONVIF + port 554',
        sure: { pct: 96, band: 'high', label: 'Very sure' },
        suggested_name: 'Back yard 2',
        closest: null,
        matched: null,
        actionable: true,
        decision: 'proposed',
        ...overrides,
    };
}

function fixture(overrides: Partial<FoundDevicesProps> = {}): FoundDevicesProps {
    return {
        scan: {
            id: 12,
            scope_id: 3,
            site_id: 4,
            site: 'Synthetic House',
            range: '10.0.0.0/24',
            runs_from: 'Synthetic hub',
            status: 'completed',
            running: false,
            planned: 254,
            started_at: new Date(Date.now() - 4 * 60_000).toISOString(),
            completed_at: new Date(Date.now() - 60_000).toISOString(),
            cancelled: false,
        },
        tab: 'new',
        filters: { q: '', sure: 'all' },
        counts: { new: 2, added: 5, ignored: 0, unsure: 1, addable: 1 },
        rows: [
            row({}),
            row({ id: 2, guess: 'Unknown device', sub: 'Answers on port 80 only', kind: 'other', address: '10.0.0.200', why: 'Small Wi-Fi chip; often a smart plug', sure: { pct: 30, band: 'low', label: 'Not sure' }, decision: 'review', closest: { id: 31, name: 'Synthetic Wi-Fi' } }),
        ],
        can: { add: true, same_as: true, ignore: true, scan: true },
        ...overrides,
    };
}

beforeEach(() => Object.values(inertia).forEach((fn) => fn.mockReset()));
afterEach(cleanup);

describe('Found devices', () => {
    it('shows what each one probably is, how sure we are and why, with the scan in the header', () => {
        render(<FoundDevices {...fixture()} />);

        expect(screen.getByRole('heading', { name: 'Scan of Synthetic House' })).toBeTruthy();
        expect(screen.getByText(/10\.0\.0\.0\/24 · by Synthetic hub · finished/)).toBeTruthy();
        expect(screen.getByText('Matched ONVIF + port 554')).toBeTruthy();
        expect(screen.getByText('Very sure')).toBeTruthy();
        expect(screen.getByText('Not sure')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: /Add all 1/ }));
        expect(inertia.post).toHaveBeenCalledWith('/security-devices/discovery/candidates/adopt-all', { scope_id: 3 }, expect.anything());
    });

    it('asks Same device? with the closest match first, and confirms Ignore', () => {
        render(<FoundDevices {...fixture()} />);

        fireEvent.contextMenu(screen.getByText('Unknown device'));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Same device?' }));
        const same = screen.getByRole('dialog');
        expect(within(same).getByText('Found by scan')).toBeTruthy();
        expect(within(same).getByRole('combobox', { name: 'Your devices' }).textContent).toContain('Synthetic Wi-Fi');
        fireEvent.click(within(same).getByRole('button', { name: 'Merge into this device' }));
        expect(inertia.post).toHaveBeenCalledWith('/security-devices/discovery/candidates/2/same-as', { device_id: 31 }, expect.anything());
        cleanup();

        render(<FoundDevices {...fixture()} />);
        fireEvent.contextMenu(screen.getByText('Synthetic camera'));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Ignore' }));
        const confirm = screen.getByRole('alertdialog');
        expect(within(confirm).getByRole('heading', { name: 'Ignore 10.0.0.41?' })).toBeTruthy();
        fireEvent.click(within(confirm).getByRole('button', { name: 'Ignore device' }));
        expect(inertia.post).toHaveBeenCalledWith('/security-devices/discovery/candidates/1/ignore', {}, expect.anything());
    });

    it('opens an already-added device and offers no review actions on it', () => {
        render(
            <FoundDevices
                {...fixture({
                    tab: 'added',
                    rows: [row({ id: 7, tab: 'added', actionable: false, decision: 'matched', matched: { id: 30, name: 'Synthetic printer', href: '/security-devices/devices/30' } })],
                })}
            />,
        );
        expect(screen.getByText('Synthetic printer')).toBeTruthy();
        fireEvent.contextMenu(screen.getByText('Synthetic camera'));
        expect(screen.queryByRole('menuitem', { name: 'Ignore' })).toBeNull();
        fireEvent.click(screen.getByRole('menuitem', { name: 'Open Synthetic printer' }));
        expect(inertia.visit).toHaveBeenCalledWith('/security-devices/devices/30');
    });
});
