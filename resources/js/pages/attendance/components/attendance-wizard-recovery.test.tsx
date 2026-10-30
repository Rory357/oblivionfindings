import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ClockInWizard } from './clock-in-wizard';
import { ClockOutWizard } from './clock-out-wizard';
import { FixClockOutWizard } from './fix-clock-out-wizard';
import type { FixCandidate, OpenSession } from './shared';
import type { AttendanceResult } from './use-attendance-command';

type Options = {
    onSuccess?: (page: { props: Record<string, unknown> }) => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};
const transport = vi.hoisted(() => ({
    requests: [] as {
        url: string;
        data: Record<string, unknown>;
        options: Options;
    }[],
    page: { flash: {} },
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: transport.page }),
    router: {
        post: (url: string, data: Record<string, unknown>, options: Options) =>
            transport.requests.push({ url, data, options }),
    },
}));
// The shared picker interaction is verified independently; these adapters exercise the owning form's exact values and validation.
vi.mock('@/components/fleet-assets/maintenance/date-picker', () => ({
    DatePicker: ({
        id,
        label,
        value,
        onChange,
    }: {
        id: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
vi.mock('@/components/fleet-assets/maintenance/time-picker', () => ({
    TimePicker: ({
        id,
        label,
        value,
        onChange,
    }: {
        id: string;
        label: string;
        value: string;
        onChange: (value: string) => void;
    }) => (
        <input
            id={id}
            aria-label={label}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    ),
}));
beforeEach(() => {
    transport.requests.length = 0;
    transport.page = { flash: {} };
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-06T04:00:00Z'));
});
afterEach(() => vi.useRealTimers());
const session: OpenSession = {
    id: 8,
    clock_in_at: '2026-10-05T19:00:00Z',
    shift_id: null,
    shift_starts_at: null,
    shift_ends_at: null,
    shift_location: null,
    client_name: null,
    client_id: null,
    timesheet_id: null,
    on_break: false,
    break_started_at: null,
    break_minutes: 20,
    breaks: [],
};
const corrected: FixCandidate = {
    id: 8,
    user_name: 'Aroha',
    clock_in_at: session.clock_in_at,
    clock_out_at: '2026-10-06T03:30:42Z',
    break_minutes: 20,
    shift_id: null,
    location: null,
    is_stale: false,
};
const saved = (delta: Partial<AttendanceResult> = {}): AttendanceResult => ({
    action: 'clock_out',
    session_id: 8,
    shift_id: null,
    session_status: 'closed',
    clock_in_at: session.clock_in_at,
    clock_out_at: '2026-10-06T04:00:00Z',
    break_minutes: 35,
    worked_hours: 8.42,
    timesheet_sync_outcome: 'created',
    timesheet_id: 52,
    timesheet_status: 'draft',
    handover_outcome: 'no_shift',
    ...delta,
});
const finish = (receipt?: AttendanceResult) =>
    act(() => {
        const request = transport.requests.at(-1)!;
        request.options.onSuccess?.({
            props: {
                flash: receipt
                    ? { attendance_result: receipt }
                    : { success: 'Saved' },
            },
        });
        request.options.onFinish?.();
    });
const next = () =>
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
const jump = (name: RegExp) =>
    fireEvent.click(screen.getByRole('button', { name }));
const save = (name: string) =>
    fireEvent.click(screen.getByRole('button', { name }));

describe('Attendance forms preserve choices and report only actual saved results', () => {
    it('explains automatic matching and reports the actual shift and server time', () => {
        render(<ClockInWizard open onClose={vi.fn()} shifts={[]} />);
        expect(
            screen.getByText('Match an assigned shift if available'),
        ).toBeVisible();
        next();
        next();
        save('Clock in');
        expect(transport.requests[0].data.shift_id).toBeNull();
        finish(
            saved({
                action: 'clock_in',
                shift_id: 17,
                clock_in_at: '2026-10-06T04:00:21Z',
                clock_out_at: null,
                session_status: 'open',
                worked_hours: null,
                break_minutes: 0,
                timesheet_sync_outcome: 'none',
                timesheet_id: null,
                timesheet_status: null,
                handover_outcome: null,
            }),
        );
        expect(screen.getByText(/Linked to shift #17/)).toBeVisible();
        expect(screen.getByText("You're on the clock")).toBeVisible();
        expect(
            screen.queryByText(/with no linked shift/),
        ).not.toBeInTheDocument();
    });
    it('retains a note on validation failure and blocks close and double-submit while pending', () => {
        const onClose = vi.fn();
        render(<ClockInWizard open onClose={onClose} shifts={[]} />);
        next();
        fireEvent.change(screen.getByLabelText(/Note for the session/), {
            target: { value: 'Started in the community' },
        });
        next();
        save('Clock in');
        save('Clock in');
        fireEvent.click(
            screen.getByRole('button', { name: 'Close' }),
        );
        expect(onClose).not.toHaveBeenCalled();
        expect(transport.requests).toHaveLength(1);
        act(() => {
            transport.requests[0].options.onError?.({
                clock_in: 'Choose one of your assigned shifts.',
            });
            transport.requests[0].options.onFinish?.();
        });
        expect(
            screen.getByText('Choose one of your assigned shifts.'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Back' }),
        );
        expect(screen.getByLabelText(/Note for the session/)).toHaveValue(
            'Started in the community',
        );
    });
    it('holds a missing receipt and keeps the entered note available for review', () => {
        const onClose = vi.fn();
        render(<ClockInWizard open onClose={onClose} shifts={[]} />);
        next();
        fireEvent.change(screen.getByLabelText(/Note for the session/), {
            target: { value: 'Keep this note' },
        });
        next();
        save('Clock in');
        finish();
        expect(
            screen.getByRole('link', { name: /Check attendance/ }),
        ).toHaveAttribute('target', '_blank');
        expect(
            screen.getByRole('button', { name: 'Clock in' }),
        ).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Back' }),
        );
        expect(screen.getByLabelText(/Note for the session/)).toHaveValue(
            'Keep this note',
        );
        expect(screen.getByLabelText(/Note for the session/)).toBeDisabled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel' }),
        );
        expect(screen.getByText('Close an unconfirmed result?')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep this form open' }),
        );
        expect(onClose).not.toHaveBeenCalled();
        expect(transport.requests).toHaveLength(1);
    });
    it('uses explicit worker date/time and omits zero-extra breaks, retaining success after props close the session', () => {
        const onClose = vi.fn();
        const { rerender } = render(
            <ClockOutWizard
                open
                onClose={onClose}
                session={session}
                timezone="Pacific/Auckland"
            />,
        );
        expect(screen.getByLabelText('Clock-out date')).toHaveValue(
            '2026-10-06',
        );
        expect(screen.getByLabelText('Clock-out time')).toHaveValue('17:00');
        next();
        next();
        save('Clock out');
        expect(transport.requests[0].data).toEqual({
            session_id: 8,
            clock_out_at: '2026-10-06T04:00:00.000Z',
        });
        rerender(
            <ClockOutWizard
                open
                onClose={onClose}
                session={null}
                timezone="Pacific/Auckland"
            />,
        );
        finish(saved());
        expect(
            screen.getByText('8.42h recorded — session closed'),
        ).toBeVisible();
        expect(screen.getByText(/35m of breaks/)).toBeVisible();
        expect(
            screen.getByText(/Draft timesheet #52 was created/),
        ).toBeVisible();
    });
    it('validates times again when the user jumps straight to review', () => {
        render(<ClockOutWizard open onClose={vi.fn()} session={session} />);
        fireEvent.change(screen.getByLabelText('Clock-out date'), {
            target: { value: '2026-10-08' },
        });
        jump(/Review & clock out.*Confirm and close/);
        save('Clock out');
        expect(transport.requests).toHaveLength(0);
        expect(
            screen.getByText(/cannot be more than two minutes in the future/),
        ).toBeVisible();
    });
    it('reports a retained handover without claiming the new narrative was saved', () => {
        render(
            <ClockOutWizard
                open
                onClose={vi.fn()}
                session={{ ...session, shift_id: 7 }}
            />,
        );
        next();
        fireEvent.change(screen.getByLabelText(/How the shift went/), {
            target: { value: 'All details checked for the incoming worker.' },
        });
        next();
        save('Clock out');
        finish(
            saved({
                shift_id: 7,
                handover_outcome: 'existing_submitted_or_acknowledged',
            }),
        );
        expect(
            screen.getByText(/notes entered here were not added/),
        ).toBeVisible();
        expect(
            screen.queryByText(/Your handover was saved as a draft/),
        ).not.toBeInTheDocument();
    });
    it('shows an actual attendance-only payroll follow-up on correction, preserving untouched seconds', () => {
        render(
            <FixClockOutWizard open onClose={vi.fn()} sessions={[corrected]} />,
        );
        next();
        next();
        fireEvent.change(screen.getByLabelText(/Reason for correction/), {
            target: { value: 'Break total confirmed with worker' },
        });
        save('Save correction');
        expect(transport.requests[0].data.clock_out_at).toBe(
            '2026-10-06T03:30:42.000Z',
        );
        finish(
            saved({
                action: 'correct',
                clock_out_at: corrected.clock_out_at,
                handover_outcome: null,
                timesheet_sync_outcome: 'skipped_follow_up',
                timesheet_id: null,
                timesheet_status: null,
            }),
        );
        expect(screen.getByText('Session corrected')).toBeVisible();
        expect(screen.getByText(/timesheet was not changed/)).toBeVisible();
    });
    it('rechecks correction times and breaks even if their step was skipped', () => {
        render(
            <FixClockOutWizard
                open
                onClose={vi.fn()}
                sessions={[{ ...corrected, break_minutes: 600 }]}
            />,
        );
        jump(/Reason & review.*For the audit log/);
        fireEvent.change(screen.getByLabelText(/Reason for correction/), {
            target: { value: 'Review times' },
        });
        save('Save correction');
        expect(transport.requests).toHaveLength(0);
        expect(
            screen.getByText('Enter whole break minutes from 0 to 240.'),
        ).toBeVisible();
    });
    it('asks before switching a correction target with entered details', () => {
        render(
            <FixClockOutWizard
                open
                onClose={vi.fn()}
                sessions={[
                    corrected,
                    { ...corrected, id: 9, user_name: 'Hemi' },
                ]}
            />,
        );
        next();
        next();
        fireEvent.change(screen.getByLabelText(/Reason for correction/), {
            target: { value: 'Aroha’s reason' },
        });
        jump(/Session.*Which one needs fixing/);
        fireEvent.click(screen.getByRole('button', { name: /Hemi · In/ }));
        expect(
            screen.getByText('Change the session being corrected?'),
        ).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Keep current session' }),
        );
        jump(/Reason & review.*For the audit log/);
        expect(screen.getByLabelText(/Reason for correction/)).toHaveValue(
            'Aroha’s reason',
        );
    });
});
