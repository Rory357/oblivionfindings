import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DevicesIndex from './index';
import type { DevicesIndexProps, LogbookLine, NotWatchedRow, RetiredRow } from './_types';

/** Devices › Upkeep › Not watched (pg-sdr-2e) and Retired (pg-sdr-2f), with synthetic devices only. */

const inertia = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), visit: vi.fn(), reload: vi.fn(), delete: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: React.ReactNode }) => children }));

const daysAgo = (days: number) => new Date(Date.now() - days * 86_400_000).toISOString();
const meta = (total: number) => ({ total, page: 1, last_page: 1, from: total ? 1 : 0, to: total });

function notWatched(overrides: Partial<NotWatchedRow> = {}): NotWatchedRow {
    return {
        id: 31,
        name: 'Synthetic meeting screen',
        model: 'Synthetic Board',
        kind: 'other',
        state: 'not_watched',
        sub: 'Synthetic Board',
        where: 'Synthetic House · meeting room',
        why: 'Stopped by Synthetic Manager · no longer needed',
        note: 'Switched off at the wall after hours',
        since: daysAgo(9),
        seen_at: daysAgo(0.01),
        site: 'Synthetic House',
        site_id: 4,
        address: '10.0.0.31',
        watching: 'Nothing',
        href: '/security-devices/devices/31',
        ...overrides,
    };
}

function retired(overrides: Partial<RetiredRow> = {}): RetiredRow {
    return {
        id: 51,
        name: 'Synthetic old router',
        kind: 'router',
        model: 'Synthetic USG',
        sub: 'Synthetic USG',
        was_at: 'Synthetic House · comms cupboard',
        removed_at: daysAgo(20),
        by: 'Synthetic Manager',
        kept: 'Logbook and settings backups',
        ...overrides,
    };
}

function line(index: number, overrides: Partial<LogbookLine> = {}): LogbookLine {
    return {
        key: `line-${index}`,
        kind: 'alerts',
        at: daysAgo(20 + index),
        tone: 'info',
        title: `Synthetic line ${index}`,
        sub: null,
        who: 'Oblivion',
        compare_id: null,
        span: null,
        href: null,
        tab: null,
        ...overrides,
    };
}

function fixture(overrides: Partial<DevicesIndexProps> = {}): DevicesIndexProps {
    return {
        tab: 'upkeep',
        layout: 'cards',
        upkeep: 'not_watched',
        filters: { site: 'all', status: 'all', q: '' },
        tabs: [
            { key: 'all', count: 2 },
            { key: 'upkeep', count: null },
        ],
        meters: {
            online: { count: 2, caption: { text: 'Watched and answering', at: null } },
            offline: { count: 0, caption: { text: 'Nothing offline', at: null } },
            cant_check: { count: 0, caption: { text: 'Everything can be checked', at: null } },
            batteries_low: { count: 0, caption: { text: 'No battery at 20% or below', at: null } },
        },
        sites: [{ value: 'all', label: 'All Sites' }],
        statuses: [{ value: 'all', label: 'Any status' }],
        can: { add: false, assign: false, scan: false, connect: false, edit: false, move: false, retire: false, watch: true },
        cards: null,
        cameras: null,
        list: null,
        batteries: null,
        updates: null,
        not_watched: null,
        retired: null,
        openDialog: null,
        ...overrides,
    };
}

beforeEach(() => {
    Object.values(inertia).forEach((fn) => fn.mockReset());
    window.localStorage.clear();
});
afterEach(cleanup);

describe('Devices › Upkeep › Not watched (pg-sdr-2e)', () => {
    it('says why and since when, grey and never red, with the four Upkeep views', () => {
        render(
            <DevicesIndex
                {...fixture({
                    not_watched: {
                        rows: [notWatched(), notWatched({ id: 32, name: 'Synthetic phone base', why: 'Added from a scan, never watched', note: null, seen_at: null })],
                        meta: meta(2),
                        recent: [{ key: 'w1', at: daysAgo(2), tone: 'none', title: 'Synthetic meeting screen · watching stopped', sub: 'Synthetic Manager · Synthetic House · no longer needed' }],
                    },
                })}
            />,
        );

        const views = screen.getByRole('group', { name: 'Upkeep view' });
        for (const label of ['Batteries', 'Updates', 'Not watched', 'Retired']) expect(within(views).getByText(label)).toBeInTheDocument();
        expect(within(views).getByRole('radio', { name: /Not watched/ })).toHaveAttribute('aria-checked', 'true');

        const table = screen.getByRole('table');
        for (const header of ['Where', 'Why it isn’t watched', 'Since', 'Last seen']) expect(within(table).getByText(header)).toBeInTheDocument();
        expect(within(table).getByText('Stopped by Synthetic Manager · no longer needed')).toBeInTheDocument();
        expect(within(table).getByText('Its note: “Switched off at the wall after hours”')).toBeInTheDocument();
        expect(within(table).getByText('Added from a scan, never watched')).toBeInTheDocument();
        expect(within(table).getByText('Not seen by Oblivion')).toBeInTheDocument();
        // Grey, never red: no Offline or critical badge here.
        expect(within(table).queryByText('Offline')).not.toBeInTheDocument();
        expect(screen.getByText('Synthetic meeting screen · watching stopped')).toBeInTheDocument();
        expect(screen.getByText(/flagged when you save and needs a reason/)).toBeInTheDocument();

        fireEvent.click(within(views).getByRole('radio', { name: /Retired/ }));
        expect(inertia.get).toHaveBeenLastCalledWith('/security-devices/devices', { tab: 'upkeep', upkeep: 'retired' }, expect.anything());
    });

    it('puts Watch this device on the row menu for people who manage checks, and opens pop-up 9', () => {
        render(<DevicesIndex {...fixture({ not_watched: { rows: [notWatched()], meta: meta(1), recent: [] } })} />);

        fireEvent.contextMenu(within(screen.getByRole('table')).getAllByRole('row')[1]);
        const items = screen.getAllByRole('menuitem').map((item) => item.textContent?.trim());
        expect(items[0]).toBe('Watch this device');
        expect(items).not.toContain('Watch it');
        fireEvent.click(screen.getByRole('menuitem', { name: 'Watch this device' }));
        const popup = screen.getByTestId('sd-watch-device');
        expect(within(popup).getByRole('button', { name: 'Start watching' })).toBeInTheDocument();
    });

    it('leaves Watch this device out for people who can’t manage checks', () => {
        render(
            <DevicesIndex
                {...fixture({
                    can: { add: false, assign: false, scan: false, connect: false, edit: false, move: false, retire: false, watch: false },
                    not_watched: { rows: [notWatched()], meta: meta(1), recent: [] },
                })}
            />,
        );
        expect(screen.queryByText(/Watch this device is on each row/)).not.toBeInTheDocument();
        fireEvent.contextMenu(within(screen.getByRole('table')).getAllByRole('row')[1]);
        expect(screen.queryByRole('menuitem', { name: /Watch/ })).not.toBeInTheDocument();
    });
});

describe('Devices › Upkeep › Retired (pg-sdr-2f)', () => {
    const lines = Array.from({ length: 8 }, (_, index) => line(index));
    lines[0] = line(0, { kind: 'moves', tone: 'none', title: 'Removed', sub: 'Synthetic Manager · off every list from now · its history stays', who: 'Synthetic Manager' });

    it('lists removed devices read-only with when, who and what’s kept, and the chosen one’s last lines', () => {
        render(
            <DevicesIndex
                {...fixture({
                    upkeep: 'retired',
                    can: { add: false, assign: false, scan: false, connect: false, edit: true, move: true, retire: true, watch: true },
                    retired: {
                        rows: [retired(), retired({ id: 52, name: 'Synthetic test camera', kind: 'camera', was_at: 'Synthetic House', by: null, kept: 'Logbook' })],
                        meta: meta(2),
                        selected: { id: 51, name: 'Synthetic old router', removed_at: daysAgo(20), by: 'Synthetic Manager', lines },
                    },
                })}
            />,
        );

        const table = screen.getByRole('table');
        for (const header of ['Was at', 'When', 'By', 'What’s kept']) expect(within(table).getByText(header)).toBeInTheDocument();
        expect(within(table).getByText('Logbook and settings backups')).toBeInTheDocument();
        expect(within(table).getByText('Not recorded')).toBeInTheDocument();
        // One meaning only: no Retire, no putting a device back, no Remove on a removed device.
        expect(screen.queryByText(/Put back|back in service|Retire device|Restore/i)).not.toBeInTheDocument();
        fireEvent.contextMenu(within(table).getAllByRole('row')[1]);
        expect(screen.getAllByRole('menuitem').map((item) => item.textContent?.trim())).toEqual(['Show its logbook lines']);

        expect(screen.getByText('Synthetic old router · its last logbook lines')).toBeInTheDocument();
        expect(screen.getByText('Removed')).toBeInTheDocument();
        expect(screen.getByText(/only from while it was at your Sites/)).toBeInTheDocument();
        expect(screen.queryByText('Synthetic line 7')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Whole history' }));
        expect(screen.getByText('Synthetic line 7')).toBeInTheDocument();
        expect(screen.getByText(/Its checks go first/)).toBeInTheDocument();
    });

    it('opens another removed device’s lines by clicking its row', () => {
        render(
            <DevicesIndex
                {...fixture({
                    upkeep: 'retired',
                    retired: {
                        rows: [retired(), retired({ id: 52, name: 'Synthetic test camera' })],
                        meta: meta(2),
                        selected: { id: 51, name: 'Synthetic old router', removed_at: daysAgo(20), by: null, lines: [] },
                    },
                })}
            />,
        );
        expect(screen.getByText('Nothing recorded from while it was at your Sites')).toBeInTheDocument();
        fireEvent.click(within(screen.getByRole('table')).getByText('Synthetic test camera'));
        expect(inertia.get).toHaveBeenLastCalledWith('/security-devices/devices', { tab: 'upkeep', upkeep: 'retired', retired: 52, page: 1 }, expect.anything());
    });
});
