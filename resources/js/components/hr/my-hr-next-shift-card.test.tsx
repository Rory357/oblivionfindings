import { act, cleanup, render, screen } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { MyHrNextShiftCard, type MyHrScheduledShift } from './my-hr-next-shift-card';

vi.mock('@inertiajs/react', () => ({
    Link: (props: ComponentProps<'a'>) => <a {...props} />,
}));

const shift: MyHrScheduledShift = {
    id: 81, site: 'Kauri House', shift_type: 'standard',
    starts_at: '2026-10-20T10:00:00Z', ends_at: '2026-10-20T14:00:00Z',
};
beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-20T12:00:00Z'));
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

it('shows elapsed roster time without inferring a clock-in or midpoint break', () => {
    render(<MyHrNextShiftCard shift={shift} colleagues={[]} />);
    expect(screen.getByText('Planned')).toBeInTheDocument();
    expect(screen.getByRole('progressbar', { name: 'Scheduled window elapsed' })).toHaveAttribute('aria-valuenow', '50');
    expect(screen.queryByText(/In progress|Clock in|Clock out|^Break$|✓/)).toBeNull();
    expect(screen.getByText(/rostered times.*recorded work and breaks/)).toBeInTheDocument();
    act(() => vi.advanceTimersByTime(60_000));
    expect(screen.queryByText(/In progress|Clock in|Clock out|^Break$|✓/)).toBeNull();
});

it('shows both local dates for an overnight scheduled shift', () => {
    render(<MyHrNextShiftCard shift={shift} colleagues={[]} />);
    expect(screen.getByText(/20 Oct 2026.*11:00 pm/)).toBeInTheDocument();
    expect(screen.getByText(/21 Oct 2026.*3:00 am/)).toBeInTheDocument();
    expect(screen.getByText(/Scheduled duration: 4 hr.*Pacific\/Auckland/)).toBeInTheDocument();
});

it('formats a supplied work timezone instead of browser-local hours', () => {
    render(<MyHrNextShiftCard shift={shift} colleagues={[]} timezone="America/New_York" />);
    expect(screen.getByText(/20 Oct 2026.*6:00 am/)).toBeInTheDocument();
    expect(screen.getByText(/20 Oct 2026.*10:00 am/)).toBeInTheDocument();
    expect(screen.getByText(/Support shift/)).toBeInTheDocument();
    expect(screen.queryByText(/Day support|Evening support/)).toBeNull();
});

it('does not interpret an empty colleague list as confirmed solo staffing', () => {
    const { rerender } = render(<MyHrNextShiftCard shift={shift} colleagues={[]} />);
    expect(screen.getByText('No colleagues are shown for this shift.')).toBeInTheDocument();
    expect(screen.queryByText(/solo|fully covered/i)).toBeNull();
    rerender(<MyHrNextShiftCard shift={shift} colleaguesShiftId={shift.id}
        colleagues={[{ id: 9, name: 'Mere Test', first_name: 'Mere', initials: 'MT' }]} />);
    expect(screen.getByText('Listed colleagues: Mere Test')).toBeInTheDocument();
});

it('retains real calendar and roster entry points even if times need checking', () => {
    render(<MyHrNextShiftCard shift={{ ...shift, ends_at: null }} colleagues={[]} />);
    expect(screen.getByText(/Schedule times are unavailable/)).toBeInTheDocument();
    expect(screen.queryByText('No upcoming shift shown')).toBeNull();
    expect(screen.queryByRole('progressbar')).toBeNull();
    expect(screen.getByRole('link', { name: 'Add to calendar' })).toHaveAttribute('href', '/hr/my/time/shifts/81/calendar');
    expect(screen.getByRole('link', { name: 'View roster' })).toHaveAttribute('href', '/hr/my/time');
});

it.each([
    { starts_at: '2026-10-20T10:00:00', ends_at: shift.ends_at },
    { starts_at: shift.ends_at, ends_at: shift.starts_at },
])('withholds a schedule timeline for ambiguous or reversed times: %j', (times) => {
    render(<MyHrNextShiftCard shift={{ ...shift, ...times }} colleagues={[]} />);
    expect(screen.getByText(/Schedule times are unavailable/)).toBeInTheDocument();
    expect(screen.queryByRole('progressbar')).toBeNull();
});

it('keeps roster navigation without making a no-work claim when no shift is supplied', () => {
    render(<MyHrNextShiftCard shift={null} colleagues={[]} />);
    expect(screen.getByText('No upcoming shift shown')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'View roster' })).toHaveAttribute('href', '/hr/my/time');
    expect(screen.queryByRole('link', { name: 'Add to calendar' })).toBeNull();
    expect(screen.queryByText(/enjoy the breather|solo/i)).toBeNull();
});

it('does not guess a support type when a next-shift summary omits it', () => {
    render(<MyHrNextShiftCard shift={{ ...shift, shift_type: '' }} colleagues={[]} />);
    expect(screen.getByText(/^Shift · Scheduled duration/)).toBeInTheDocument();
    expect(screen.queryByText(/Support shift|Sleepover|On-call/)).toBeNull();
});

it.each([null, 82])('withholds colleagues not tied to the displayed occurrence: %s', (colleaguesShiftId) => {
    render(<MyHrNextShiftCard shift={shift} colleaguesShiftId={colleaguesShiftId}
        colleagues={[{ id: 9, name: 'Mere Test', first_name: 'Mere', initials: 'MT' }]} />);
    expect(screen.queryByText(/Mere Test/)).toBeNull();
    expect(screen.getByText('No colleagues are shown for this shift.')).toBeInTheDocument();
});
