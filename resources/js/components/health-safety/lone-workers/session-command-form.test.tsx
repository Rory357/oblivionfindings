import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    confirmedSessionCommand,
    SessionCommandForm,
} from './session-command-form';
import type { SafetySession } from './types';

const state = vi.hoisted(() => ({
    submit: vi.fn(),
    response: 'confirmed' as 'confirmed' | 'wrong' | 'interrupted',
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        router: { on: () => () => {} },
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = React.useState(initial);
            const transform = React.useRef(
                (value: Record<string, unknown>) => value,
            );
            return {
                data,
                errors: {},
                isDirty: false,
                processing: false,
                setData: (
                    key:
                        | string
                        | ((
                              value: Record<string, unknown>,
                          ) => Record<string, unknown>),
                    value: unknown,
                ) =>
                    setData((current) =>
                        typeof key === 'function'
                            ? key(current)
                            : { ...current, [key]: value },
                    ),
                transform: (next: typeof transform.current) => {
                    transform.current = next;
                },
                reset: vi.fn(),
                submit: (
                    method: string,
                    url: string,
                    options: {
                        onSuccess: (page: { props: object }) => void;
                        onFinish: () => void;
                    },
                ) => {
                    const payload = transform.current(data);
                    state.submit(method, url, payload);
                    if (state.response !== 'interrupted')
                        options.onSuccess({
                            props: {
                                lone_worker_confirmation: {
                                    id: 8,
                                    lock_version:
                                        Number(payload.expected_version) + 1,
                                    idempotency_key:
                                        state.response === 'wrong'
                                            ? 'different-request'
                                            : payload.idempotency_key,
                                    command:
                                        method === 'patch'
                                            ? 'update'
                                            : 'checkIn',
                                    removed: false,
                                },
                            },
                        });
                    options.onFinish();
                },
            };
        },
    };
});
vi.mock('@/components/wizard/primitives', () => ({
    Field: ({
        label,
        children,
    }: {
        label: string;
        children: React.ReactNode;
    }) => (
        <label>
            {label}
            {children}
        </label>
    ),
    SelectInput: ({
        options,
        value,
        onChange,
        placeholder,
    }: {
        options: { value: string; label: string; disabled?: boolean }[];
        value: string;
        onChange: (value: string) => void;
        placeholder: string;
    }) => (
        <select
            aria-label={placeholder}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            {options.map((item) => (
                <option
                    key={item.value}
                    value={item.value}
                    disabled={item.disabled}
                >
                    {item.label}
                </option>
            ))}
        </select>
    ),
}));
const detail = {
    id: 8,
    expected_version: 4,
    lock_version: 4,
    expected_end_at: '2026-10-08T10:00:00Z',
    check_in_interval_minutes: 30,
    activity_description: null,
    location: null,
    can: { check_in: true, end: true, manage: false },
} as unknown as SafetySession;
beforeEach(() => {
    state.submit.mockReset();
    state.response = 'confirmed';
});
afterEach(cleanup);

it('preserves an untouched plan by omission and sends explicit null only when it is cleared', () => {
    const onSaved = vi.fn();
    const plan = {
        kind: 'planned' as const,
        method: 'manual' as const,
        description: 'Reviewed intended place',
        lat: 0,
        lng: 0,
        recorded_at: null,
        recorded_by: null,
        source_site_id: null,
        source_shift_id: null,
    };
    render(
        <SessionCommandForm
            detail={{
                ...detail,
                planned_location: plan,
                can: { ...detail.can, edit: true },
            }}
            command="edit"
            returnTo="/my-day"
            onSaved={onSaved}
            onCancel={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(state.submit.mock.calls[0][2]).not.toHaveProperty(
        'planned_location',
    );
    expect(onSaved).toHaveBeenCalledOnce();
    fireEvent.change(
        screen.getByRole('combobox', {
            name: 'Choose how to set the planned location',
        }),
        { target: { value: 'none' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(state.submit.mock.calls[1][2]).toHaveProperty(
        'planned_location',
        null,
    );
});

it('retains planned coordinates and exact retry identity after an uncertain save', () => {
    state.response = 'interrupted';
    render(
        <SessionCommandForm
            detail={{ ...detail, can: { ...detail.can, edit: true } }}
            command="edit"
            returnTo="/my-day"
            onSaved={vi.fn()}
            onCancel={vi.fn()}
        />,
    );
    fireEvent.change(
        screen.getByRole('combobox', {
            name: 'Choose how to set the planned location',
        }),
        { target: { value: 'manual' } },
    );
    fireEvent.change(
        screen.getByRole('spinbutton', { name: 'Planned latitude' }),
        { target: { value: '0' } },
    );
    fireEvent.change(
        screen.getByRole('spinbutton', { name: 'Planned longitude' }),
        { target: { value: '0' } },
    );
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(screen.getByRole('alert')).toHaveTextContent(
        'can’t tell if this saved',
    );
    const first = state.submit.mock.calls[0][2];
    expect(first.planned_location).toMatchObject({
        method: 'manual',
        latitude: 0,
        longitude: 0,
    });
    fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
    expect(state.submit.mock.calls[1][2]).toEqual(first);
    expect(
        screen.getByRole('spinbutton', { name: 'Planned latitude' }),
    ).toHaveValue(0);
});

it('keeps the reviewed revision and exact request identity when background data changes', () => {
    const onSaved = vi.fn();
    const props = {
        detail,
        command: 'check-in' as const,
        returnTo: '/my-day',
        onSaved,
        onCancel: vi.fn(),
    };
    const view = render(<SessionCommandForm {...props} />);
    fireEvent.change(screen.getByRole('textbox', { name: 'Check-in notes' }), {
        target: { value: 'Spoke with Mere' },
    });
    view.rerender(
        <SessionCommandForm
            {...props}
            detail={{ ...detail, expected_version: 7, lock_version: 7 }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record check-in' }));
    expect(state.submit).toHaveBeenCalledWith(
        'post',
        '/health-safety/lone-workers/sessions/8/check-in',
        expect.objectContaining({
            expected_version: 4,
            notes: 'Spoke with Mere',
            idempotency_key: expect.any(String),
        }),
    );
    expect(onSaved).toHaveBeenCalledOnce();
});

it.each(['wrong', 'interrupted'] as const)(
    'retains the draft and retry identity after a %s confirmation',
    (response) => {
        state.response = response;
        const onSaved = vi.fn();
        render(
            <SessionCommandForm
                detail={detail}
                command="check-in"
                returnTo="/my-day"
                onSaved={onSaved}
                onCancel={vi.fn()}
            />,
        );
        fireEvent.change(
            screen.getByRole('textbox', { name: 'Check-in notes' }),
            { target: { value: 'Retain this note' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Record check-in' }),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'can’t tell if this saved',
        );
        expect(
            screen.getByRole('textbox', { name: 'Check-in notes' }),
        ).toHaveValue('Retain this note');
        const first = state.submit.mock.calls[0][2];
        fireEvent.click(
            screen.getByRole('button', { name: 'Record check-in' }),
        );
        expect(state.submit.mock.calls[1][2]).toEqual(first);
        expect(onSaved).not.toHaveBeenCalled();
    },
);

it('retains entries if fresh permissions remove the action', () => {
    const props = {
        detail,
        command: 'check-in' as const,
        returnTo: '/my-day',
        onSaved: vi.fn(),
        onCancel: vi.fn(),
    };
    const view = render(<SessionCommandForm {...props} />);
    fireEvent.change(screen.getByRole('textbox', { name: 'Check-in notes' }), {
        target: { value: 'Not yet saved' },
    });
    view.rerender(
        <SessionCommandForm
            {...props}
            detail={{ ...detail, can: { ...detail.can, check_in: false } }}
        />,
    );
    expect(screen.getByRole('textbox', { name: 'Check-in notes' })).toHaveValue(
        'Not yet saved',
    );
    expect(
        screen.getByRole('button', { name: 'Record check-in' }),
    ).toBeDisabled();
    expect(state.submit).not.toHaveBeenCalled();
});

it('requires the exact command, source, revision and removal proof', () => {
    const expected = {
        id: 8,
        version: 4,
        key: 'request-1',
        command: 'remove' as const,
    };
    const receipt = {
        id: 8,
        lock_version: 5,
        idempotency_key: 'request-1',
        command: 'delete',
        removed: true,
    };
    expect(confirmedSessionCommand(receipt, expected)).toBe(true);
    for (const change of [
        { id: 9 },
        { lock_version: 6 },
        { idempotency_key: 'request-2' },
        { command: 'end' },
        { removed: false },
    ]) {
        expect(
            confirmedSessionCommand({ ...receipt, ...change }, expected),
        ).toBe(false);
    }
});
