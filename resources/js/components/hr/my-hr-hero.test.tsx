import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { MyHrHero } from './my-hr-hero';
import type { MyHrShellData } from './my-hr-types';

const mocks = vi.hoisted(() => ({ visit: vi.fn(), actorId: 7 }));
vi.mock('@inertiajs/react', () => ({
    router: { visit: mocks.visit },
    usePage: () => ({ props: { auth: { user: { id: mocks.actorId } } } }),
}));
vi.mock('./my-hr-calendar', () => ({ MyHrCalendar: () => null }));
vi.mock('./my-hr-clock-card', () => ({ MyHrClockCard: () => null }));
vi.mock('@/lib/offline-queue', () => ({ guardMedicationSignOut: vi.fn() }));

function fixture(timezone = 'Pacific/Auckland'): MyHrShellData {
    return {
        teammates: [], kudosCategories: {}, kudosImpacts: {},
        profile: { name: 'Mere Test', first_name: 'Mere', initials: 'MT',
            position_title: null, site_name: 'Kauri House', avatar: null },
        activeClock: null, todayTotal: 0,
        attendance: {
            schema_version: 1, scope: 'my_hr_self_attendance', actor_id: 7,
            state: 'none', timezone, can_break: false, can_clock_out: false,
            session: null, time_entry: null,
        },
        weekly: { total_hours: 43.5, daily_hours: {}, target_hours: 40 },
        nextShift: { id: 81, starts_at: '2026-10-20T12:00:00Z',
            ends_at: '2026-10-20T20:00:00Z', location: 'Kauri House', service_context_name: null },
        counts: { pendingLeave: 0, docsToSign: 0, policiesDue: 0, onesToAck: 0, kudosThisMonth: 0 },
        calendar: { month: '2026-10', events: {} },
    };
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-20T12:00:00Z'));
    mocks.actorId = 7;
    mocks.visit.mockReset();
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

it('uses the work date for both the shared header and next shift across UTC midnight', () => {
    render(<MyHrHero myHr={fixture()} />);
    expect(screen.getByText('21 Oct 2026')).toBeInTheDocument();
    expect(screen.getByText('Pacific/Auckland')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Next shift.*21 Oct 2026.*1:00 am/ })).toBeInTheDocument();
});

it('uses a validated alternate work timezone consistently and preserves roster navigation', () => {
    render(<MyHrHero myHr={fixture('America/New_York')} />);
    expect(screen.getByText('20 Oct 2026')).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: /Mōrena, Mere/ })).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /Next shift.*20 Oct 2026.*8:00 am/ }));
    expect(mocks.visit).toHaveBeenCalledWith('/hr/my/time');
    expect(screen.getByRole('button', { name: /Weekly recorded hours.*43.5h/ })).toBeInTheDocument();
});

it('does not use another actor’s timezone and falls back to the shared worker zone', () => {
    mocks.actorId = 8;
    render(<MyHrHero myHr={fixture('America/New_York')} />);
    expect(screen.getByText('Pacific/Auckland')).toBeInTheDocument();
    expect(screen.getByText('21 Oct 2026')).toBeInTheDocument();
});

it('updates the work date while the page stays open over midnight', () => {
    vi.setSystemTime(new Date('2026-10-20T10:59:45Z'));
    render(<MyHrHero myHr={fixture()} />);
    expect(screen.getByText('20 Oct 2026')).toBeInTheDocument();
    act(() => vi.advanceTimersByTime(30_000));
    expect(screen.getByText('21 Oct 2026')).toBeInTheDocument();
});

it.each([null, 'invalid', '2026-10-20T12:00:00'])('withholds an absent or ambiguous next-shift time: %s', (starts_at) => {
    const data = fixture();
    data.nextShift!.starts_at = starts_at;
    render(<MyHrHero myHr={data} />);
    // jsdom computes no block layout, so the label and value spans join without a space.
    expect(screen.getByRole('button', { name: /^Next shift\s*—$/ })).toBeInTheDocument();
});
