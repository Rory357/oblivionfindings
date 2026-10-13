import { Button } from '@/components/ui/button';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { CalendarPane } from './calendar-pane';
import { monthMatrix, ymdKey } from './calendar-shared';

const state = vi.hoisted(() => ({ success: (() => {}) as () => void }));
vi.mock('@inertiajs/react', () => ({
    Link: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    usePage: () => ({
        props: {
            auth: {
                user: { id: 5 },
                can: {
                    shifts: { manageAny: true, create: true, update: true },
                    calendar: { viewAny: true },
                },
            },
        },
    }),
    router: {
        on: (_event: string, handler: () => void) => {
            state.success = handler;
            return () => {};
        },
    },
}));
vi.mock(
    '@/pages/operations/shifts/components/use-create-shift-launcher',
    () => ({
        useCreateShiftLauncher: () => ({ openWith: vi.fn(), dialog: null }),
    }),
);
vi.mock('./week-grid-pane', () => ({ buildShiftActions: () => [] }));
vi.mock('./shift-context-menu', () => ({ ShiftContextMenu: () => null }));
vi.mock('./day-detail-dialog', () => ({
    DayDetailDialog: ({
        open,
        dayKey,
        shifts,
        onNav,
    }: {
        open: boolean;
        dayKey: string;
        shifts: { id: number }[];
        onNav: (delta: number) => void;
    }) =>
        open ? (
            <div role="dialog" aria-label="Day details">
                <span>{dayKey}</span>
                <span>{shifts.length} duties</span>
                <Button onClick={() => onNav(1)}>Next day</Button>
            </div>
        ) : null,
}));

const reads: { url: string; resolve: (value: Response) => void }[] = [];
const writes: {
    url: string;
    body: string;
    resolve: (value: Response) => void;
}[] = [];
const event = {
    id: 11,
    title: 'Duty',
    start: '2026-10-10T09:00:00',
    end: '2026-10-10T17:00:00',
    extendedProps: {
        status: 'scheduled',
        client: 'Calendar duty',
        user_id: 5,
        staff: 'Worker',
    },
};
function response(data: unknown, ok = true) {
    return { ok, json: async () => data } as Response;
}
async function complete(index: number, data: unknown = [], ok = true) {
    await act(async () => reads[index].resolve(response(data, ok)));
}
function openDay(key: string) {
    const range = new URL(reads.at(-1)!.url, 'http://localhost').searchParams;
    const date = new Date(range.get('start')! + 'T12:00:00');
    const offset = Math.round(
        (new Date(key + 'T12:00:00').getTime() - date.getTime()) / 86400000,
    );
    fireEvent.click(screen.getAllByTitle('View day')[offset]);
}
beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-11T12:00:00'));
    reads.length = 0;
    writes.length = 0;
    vi.stubGlobal(
        'fetch',
        vi.fn(
            (url: string, options?: { method?: string; body?: string }) =>
                new Promise<Response>((resolve) => {
                    if (options?.method === 'PATCH')
                        writes.push({ url, body: options.body!, resolve });
                    else reads.push({ url, resolve });
                }),
        ),
    );
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it('keeps the actual drag update and request path, then withholds the optimistic duty until the saved range reloads', async () => {
    render(<CalendarPane canManageAny onCreateShift={vi.fn()} />);
    await complete(0, [event]);
    const chip = screen.getByText('Calendar duty').closest('button')!;
    fireEvent.dragStart(chip, {
        dataTransfer: { effectAllowed: '', setData: vi.fn() },
    });
    const range = monthMatrix(2026, 9).flat().map(ymdKey);
    const destination =
        screen.getAllByTitle('View day')[range.indexOf('2026-10-12')];
    fireEvent.drop(destination, { dataTransfer: { getData: () => '11' } });
    expect(writes).toHaveLength(1);
    expect(writes[0].url).toBe('/operations/rostering/calendar/shifts/11');
    expect(new Date(JSON.parse(writes[0].body).starts_at).getDate()).toBe(12);
    expect(within(destination).getByText('Calendar duty')).toBeInTheDocument();
    await act(async () => writes[0].resolve(response({ success: true })));
    expect(reads).toHaveLength(2);
    expect(screen.queryByText('Calendar duty')).not.toBeInTheDocument();
    await complete(1, [
        { ...event, start: '2026-10-12T09:00:00', end: '2026-10-12T17:00:00' },
    ]);
    expect(within(destination).getByText('Calendar duty')).toBeInTheDocument();
});

it('does not reopen an old future day after a failed refresh followed by toolbar Today and a successful current-range read', async () => {
    render(<CalendarPane canManageAny />);
    await complete(0);
    fireEvent.click(screen.getByRole('button', { name: 'Next month' }));
    await complete(1);
    openDay('2026-11-25');
    expect(screen.getByRole('dialog')).toHaveTextContent('2026-11-25');
    act(() => state.success());
    await complete(2, [], false);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Today' }));
    await complete(3);
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
});

it('preserves deliberate day navigation across a month boundary and only shows the destination after its range is assessed', async () => {
    render(<CalendarPane canManageAny />);
    await complete(0);
    openDay('2026-10-31');
    fireEvent.click(screen.getByRole('button', { name: 'Next day' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    await complete(1);
    expect(screen.getByRole('dialog')).toHaveTextContent('2026-11-01');
});
