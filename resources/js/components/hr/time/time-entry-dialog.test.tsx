import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            const [errors, setErrors] = React.useState<Record<string, string>>(
                {},
            );
            const transform = React.useRef<(values: T) => unknown>(
                (values) => values,
            );

            return {
                data,
                errors,
                processing: false,
                setData: (keyOrValues: keyof T | T, value?: unknown) => {
                    if (typeof keyOrValues === 'object') {
                        setDataState(keyOrValues);
                        return;
                    }

                    setDataState((current) => ({
                        ...current,
                        [keyOrValues]: value,
                    }));
                },
                setError: (key: keyof T, message: string) =>
                    setErrors((current) => ({
                        ...current,
                        [key]: message,
                    })),
                clearErrors: () => setErrors({}),
                reset: () => setDataState(initial),
                transform: (next: (values: T) => unknown) => {
                    transform.current = next;
                },
                post: (url: string, options: unknown) =>
                    inertia.post(url, transform.current(data), options),
                put: (url: string, options: unknown) =>
                    inertia.put(url, transform.current(data), options),
            };
        },
    };
});

vi.mock('@/components/hr/people-picker', () => ({
    PeoplePicker: ({
        value,
        onChange,
        people,
        placeholder,
    }: {
        value: string;
        onChange: (value: string) => void;
        people: Array<{ value: string; label: string }>;
        placeholder: string;
    }) => (
        <select
            aria-label={placeholder}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="">{placeholder}</option>
            {people.map((person) => (
                <option key={person.value} value={person.value}>
                    {person.label}
                </option>
            ))}
        </select>
    ),
}));

vi.mock('@/components/hr/wizard', async () => {
    const React = await import('react');

    return {
        Field: ({
            label,
            required,
            error,
            children,
        }: {
            label?: string;
            required?: boolean;
            error?: string;
            children: ReactNode;
        }) => (
            <label>
                <span>
                    {label}
                    {required ? ' *' : ''}
                </span>
                {children}
                {error ? <span role="alert">{error}</span> : null}
            </label>
        ),
        SelectInput: ({
            value,
            onChange,
            placeholder,
            options,
        }: {
            value: string;
            onChange: (value: string) => void;
            placeholder: string;
            options: Array<{ value: string; label: string }>;
        }) => (
            <select
                aria-label={placeholder}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">{placeholder}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        ),
        StepHead: ({ title }: { title: string }) => <h2>{title}</h2>,
        WizardShell: ({
            open,
            title,
            children,
            footerStart,
            footerEnd,
        }: {
            open: boolean;
            title: string;
            children: ReactNode;
            footerStart?: ReactNode;
            footerEnd?: ReactNode;
        }) =>
            open ? (
                <div role="dialog" aria-label={title}>
                    {children}
                    <footer>
                        {footerStart}
                        {footerEnd}
                    </footer>
                </div>
            ) : null,
        WizardStepPane: ({ children }: { children: ReactNode }) => (
            <section>{children}</section>
        ),
        WizardSuccessPane: () => null,
        useWizard: (stepCount: number) => {
            const [index, setIndex] = React.useState(0);
            const clamp = (value: number) =>
                Math.max(0, Math.min(stepCount - 1, value));

            return {
                index,
                goTo: (value: number) => setIndex(clamp(value)),
                next: () => setIndex((value) => clamp(value + 1)),
                back: () => setIndex((value) => clamp(value - 1)),
                reset: () => setIndex(0),
                isFirst: index === 0,
                isLast: index === stepCount - 1,
                progress: Math.round(((index + 1) / stepCount) * 100),
            };
        },
    };
});

vi.mock('sonner', () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

import { TimeEntryDialog, type TimeDialogMode } from './time-entry-dialog';
import type { TimeEntry } from './types';

const baseProps = {
    staff: [{ id: 7, name: 'Aroha Rangi' }],
    sites: [{ id: 4, name: 'Harbour Site' }],
    clients: [{ id: 21, name: 'Mereana Kauri' }],
    onClose: vi.fn(),
};

function reachContextStep(mode: Extract<TimeDialogMode, 'add' | 'behalf'>) {
    render(<TimeEntryDialog {...baseProps} mode={mode} />);

    fireEvent.change(screen.getByLabelText('Select a staff member…'), {
        target: { value: '7' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

    fireEvent.change(screen.getByLabelText(/Clock in/), {
        target: { value: '2026-08-28T08:00' },
    });
    if (mode === 'add') {
        fireEvent.change(screen.getByLabelText(/Clock out/), {
            target: { value: '2026-08-28T16:00' },
        });
    }
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
}

const existingEntry: TimeEntry = {
    id: 33,
    user_name: 'Aroha Rangi',
    user_id: 7,
    initials: 'AR',
    site_name: 'Harbour Site',
    entry_date: '2026-08-28',
    clock_in: '2026-08-28T08:00',
    clock_in_short: '08:00',
    clock_out: '2026-08-28T16:00',
    clock_out_short: '16:00',
    break_minutes: 30,
    total_hours: 7.5,
    entry_type: 'manual',
    can_mutate: true,
    is_attendance_backed: false,
    status: 'submitted',
    pay_type: 'standard',
    is_sleepover: false,
    is_on_call: false,
    is_public_holiday: false,
    sleepover_disturbances: [],
    break_compliance_met: true,
    mileage_km: null,
    notes: null,
    project_code: null,
    cost_centre: null,
    approved_by: null,
    amended_by: null,
    amendment_reason: null,
    amendment_count: 0,
    client_name: 'Mereana Kauri',
    shift: null,
};

describe('TimeEntryDialog canonical client contract', () => {
    beforeEach(() => {
        inertia.post.mockReset();
        inertia.put.mockReset();
        baseProps.onClose.mockReset();
    });

    afterEach(cleanup);

    it.each(['add', 'behalf'] as const)(
        'requires a client before the shiftless %s flow can reach review',
        (mode) => {
            reachContextStep(mode);

            expect(screen.getByText('Client *')).toBeInTheDocument();
            fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

            expect(screen.getByRole('alert')).toHaveTextContent(
                'Pick a client.',
            );
            expect(
                screen.getByRole('heading', { name: 'Pay & context' }),
            ).toBeInTheDocument();
            expect(inertia.post).not.toHaveBeenCalled();
        },
    );

    it('submits the canonical client and no invented Shift identity', () => {
        reachContextStep('add');

        fireEvent.change(screen.getByLabelText('Select a client…'), {
            target: { value: '21' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save entry' }));

        expect(inertia.post).toHaveBeenCalledOnce();
        const [url, payload] = inertia.post.mock.calls[0] as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/hr/time/entries');
        expect(payload.client_id).toBe('21');
        expect(payload).not.toHaveProperty('shift_id');
    });

    it('explains why a shiftless entry cannot continue when no scoped clients are available', () => {
        render(<TimeEntryDialog {...baseProps} mode="add" clients={[]} />);

        fireEvent.change(screen.getByLabelText('Select a staff member…'), {
            target: { value: '7' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.change(screen.getByLabelText(/Clock in/), {
            target: { value: '2026-08-28T08:00' },
        });
        fireEvent.change(screen.getByLabelText(/Clock out/), {
            target: { value: '2026-08-28T16:00' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(screen.getByRole('alert')).toHaveTextContent(
            'No clients are available at your approved Sites.',
        );
        expect(screen.getByText('Client *')).toBeInTheDocument();
    });

    it('does not impose the create-only client choice on an existing-entry amendment', () => {
        render(
            <TimeEntryDialog
                {...baseProps}
                mode="edit"
                entry={existingEntry}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));

        expect(
            screen.getByRole('heading', { name: 'Pay & context' }),
        ).toBeInTheDocument();
        expect(screen.queryByText('Client *')).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(
            screen.getByRole('heading', { name: 'Reason & diff' }),
        ).toBeInTheDocument();
    });

    it.each(['edit', 'void'] as const)(
        'does not expose the generic %s workflow for attendance-backed projections',
        (mode) => {
            render(
                <TimeEntryDialog
                    {...baseProps}
                    mode={mode}
                    entry={{ ...existingEntry, is_attendance_backed: true }}
                />,
            );

            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        },
    );

    it('bounds a void reason to the linked timesheet archive capacity', () => {
        render(
            <TimeEntryDialog
                {...baseProps}
                mode="void"
                entry={existingEntry}
            />,
        );

        expect(screen.getByLabelText(/Reason for voiding/)).toHaveAttribute(
            'maxLength',
            '255',
        );
    });
});
