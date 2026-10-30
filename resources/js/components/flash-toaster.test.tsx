import { render } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { toast } from 'sonner';
import FlashToaster from './flash-toaster';
const { page, success, error, warning } = vi.hoisted(() => ({
    page: {
        component: 'operations/shift-notes/Index',
        props: {
            flash: {} as Record<string, unknown>,
            errors: {} as Record<string, unknown>,
        },
    },
    success: vi.fn(),
    error: vi.fn(),
    warning: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));
vi.mock('sonner', () => ({
    toast: Object.assign(vi.fn(), { success, error, warning }),
}));
beforeEach(() => {
    vi.clearAllMocks();
    page.component = 'operations/shift-notes/Index';
    page.props = { flash: {}, errors: {} };
});
it.each([undefined, { action: 'create', note_id: 7 }])(
    'leaves Notes success to its receipt-owning dialog',
    (receipt) => {
        page.props.flash = {
            success: 'Shift note added.',
            shift_note_result: receipt,
        };
        render(<FlashToaster />);
        expect(success).not.toHaveBeenCalled();
    },
);
it('preserves validation and error feedback on Notes', () => {
    page.props = {
        flash: { error: 'Access changed' },
        errors: { body: 'Write the note' },
    };
    render(<FlashToaster />);
    expect(error).toHaveBeenCalledWith('Access changed');
    expect(error).toHaveBeenCalledWith('Write the note');
});
it('preserves success notifications on other modules', () => {
    page.component = 'sites/Index';
    page.props.flash = { success: 'Site saved.' };
    render(<FlashToaster />);
    expect(success).toHaveBeenCalledWith('Site saved.');
});

it.each(['operations/timesheets/index', 'operations/rostering/index'])(
    'leaves single Timesheet results to their matching dialog on %s',
    (component) => {
        page.component = component;
        page.props.flash = { success: 'Timesheet updated.' };
        render(<FlashToaster />);
        expect(success).not.toHaveBeenCalled();
    },
);
it.each(['Selected timesheets approved.', 'Timesheet archived.'])(
    'preserves separate Timesheet catalogue and bulk feedback: %s',
    (message) => {
        page.component = 'operations/timesheets/index';
        page.props.flash = { success: message };
        render(<FlashToaster />);
        expect(success).toHaveBeenCalledWith(message);
    },
);

it.each([
    'Payroll adjustment marked as processed.',
    'This adjustment has already been marked as processed.',
])(
    'leaves external adjustment confirmation to its matching receipt: %s',
    (message) => {
        page.component = 'operations/timesheets/payroll-adjustments';
        page.props.flash = { success: message };
        render(<FlashToaster />);
        expect(success).not.toHaveBeenCalled();
    },
);

it.each([
    'operations/shifts/index',
    'operations/rostering/index',
    'operations/shifts/create',
    'operations/shifts/show',
    'operations/clients/show',
    'operations/rostering/conflicts',
])(
    'leaves single Shift confirmation to the owning wizard on %s',
    (component) => {
        page.component = component;
        page.props.flash = { success: 'Shift updated.' };
        render(<FlashToaster />);
        expect(success).not.toHaveBeenCalled();
    },
);
it('retains separate publish and series feedback on the Shift index', () => {
    page.component = 'operations/shifts/index';
    page.props.flash = { success: 'Shift published.' };
    render(<FlashToaster />);
    expect(success).toHaveBeenCalledWith('Shift published.');
});

it.each([
    undefined,
    { action: 'end_session', session_id: 8 },
])('leaves manager session closure to its receipt-owning dialog', (receipt) => {
    page.component = 'attendance/index';
    page.props.flash = {
        success: 'Session ended for Aroha. Draft timesheet #12 synced.',
        attendance_result: receipt,
    };
    render(<FlashToaster />);
    expect(success).not.toHaveBeenCalled();
});

it.each(['Break started.', 'Break ended.', 'Handover submitted.'])(
    'preserves other Attendance success feedback: %s',
    (message) => {
        page.component = 'attendance/index';
        page.props.flash = { success: message };
        render(<FlashToaster />);
        expect(success).toHaveBeenCalledWith(message);
    },
);

it('preserves Attendance validation and authority errors', () => {
    page.component = 'attendance/index';
    page.props = {
        flash: { error: 'Access changed' },
        errors: { reason: 'Enter a reason' },
    };
    render(<FlashToaster />);
    expect(error).toHaveBeenCalledWith('Access changed');
    expect(error).toHaveBeenCalledWith('Enter a reason');
});

it('does not suppress the same text outside Attendance', () => {
    page.component = 'other/index';
    page.props.flash = { success: 'Session ended for Aroha.' };
    render(<FlashToaster />);
    expect(success).toHaveBeenCalledWith('Session ended for Aroha.');
});

it('keeps an Attendance warning when generic end-session success is suppressed', () => {
    page.component = 'attendance/index';
    page.props.flash = {
        success: 'Session ended for Aroha.',
        warning: 'Review the payroll follow-up.',
    };
    render(<FlashToaster />);
    expect(success).not.toHaveBeenCalled();
    expect(warning).toHaveBeenCalledWith('Review the payroll follow-up.');
});

it('keeps the already-closed Attendance information', () => {
    page.component = 'attendance/index';
    page.props.flash = { info: 'This session was already closed.' };
    render(<FlashToaster />);
    expect(toast).toHaveBeenCalledWith('This session was already closed.');
});
