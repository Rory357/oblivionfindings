import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import EditTimesheetDialog from './edit-timesheet-dialog';
const { put, post } = vi.hoisted(() => ({ put: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        router: { post },
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = React.useState(initial);
            return {
                data,
                setData: (
                    key: string | Record<string, unknown>,
                    value?: unknown,
                ) =>
                    setData((current) =>
                        typeof key === 'string'
                            ? { ...current, [key]: value }
                            : key,
                    ),
                setDefaults: () => {},
                clearErrors: () => {},
                errors: {},
                processing: false,
                put,
            };
        },
    };
});
it('retains entered edit values when current permission disappears', () => {
    const row = {
        id: 51,
        work_date: '2026-10-05',
        starts_at: '2026-10-05T00:00:00Z',
        ends_at: '2026-10-05T01:00:00Z',
        break_minutes: 0,
        status: 'draft',
        notes: 'Original draft',
    };
    const { rerender } = render(
        <EditTimesheetDialog
            open
            timesheet={row}
            clients={[]}
            canEdit
            onOpenChange={() => {}}
        />,
    );
    fireEvent.change(screen.getByDisplayValue('Original draft'), {
        target: { value: 'Unsaved explanation' },
    });
    expect(
        screen.getByRole('button', { name: 'Save' }),
    ).toBeTruthy();
    rerender(
        <EditTimesheetDialog
            open
            timesheet={row}
            clients={[]}
            canEdit={false}
            onOpenChange={() => {}}
        />,
    );
    expect(screen.getByDisplayValue('Unsaved explanation')).toBeDisabled();
    expect(
        screen.queryByRole('button', { name: 'Save' }),
    ).toBeNull();
    expect(
        screen.queryByRole('button', { name: 'Submit for approval' }),
    ).toBeNull();
    expect(put).not.toHaveBeenCalled();
    expect(post).not.toHaveBeenCalled();
});
