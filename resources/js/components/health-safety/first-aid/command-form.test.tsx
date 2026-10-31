import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { confirmedFirstAidCommand, FirstAidCommandForm } from './command-form';
import type { FirstAidPickers, FirstAidRecord } from './types';

const state = vi.hoisted(() => ({
    submit: vi.fn(),
    reset: vi.fn(),
    response: 'confirmed',
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
                reset: state.reset,
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
                submit: (
                    method: string,
                    href: string,
                    options: {
                        onSuccess: (page: { props: object }) => void;
                        onFinish: () => void;
                    },
                ) => {
                    const payload = transform.current(data);
                    state.submit(method, href, payload);
                    if (state.response !== 'interrupted')
                        options.onSuccess({
                            props: {
                                first_aid_confirmation: {
                                    id: 8,
                                    lock_version:
                                        Number(payload.expected_version) + 1,
                                    idempotency_key:
                                        state.response === 'wrong'
                                            ? 'other-request'
                                            : payload.idempotency_key,
                                    command: href.endsWith('/complete')
                                        ? 'complete_followup'
                                        : href.includes('/attachments')
                                          ? 'add_evidence'
                                          : method === 'put'
                                            ? 'update_followup'
                                            : 'add_followup',
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
        value,
        onChange,
        placeholder,
        options,
    }: {
        value: string;
        onChange: (value: string) => void;
        placeholder: string;
        options: { value: string; label: string }[];
    }) => (
        <select
            aria-label={placeholder}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        >
            <option value="">{placeholder}</option>
            {options.map((item) => (
                <option key={item.value} value={item.value}>
                    {item.label}
                </option>
            ))}
        </select>
    ),
}));
vi.mock('@/components/health-safety/hazard-staff-picker', async () => {
    const { SelectInput } = await import('@/components/wizard/primitives');
    return { HazardStaffPicker: SelectInput };
});
const detail = {
    id: 8,
    expected_version: 4,
    lock_version: 4,
    status: 'recorded',
    can: {
        add_followup: true,
        add_evidence: true,
        link_incident: true,
        create_incident: false,
    },
    followups: [
        {
            id: 3,
            notes: 'Review with the coordinator',
            assigned_to: { id: 7, name: 'Earlier owner' },
            due_at: null,
            can: { edit: true, complete: true },
        },
    ],
    attachments: [],
} as unknown as FirstAidRecord;
const pickers = {
    followup_assignees: [{ id: 9, name: 'Current owner', site_ids: [1] }],
    incidents: [],
    attachment_kinds: [],
} as unknown as FirstAidPickers;
beforeEach(() => {
    state.submit.mockReset();
    state.reset.mockReset();
    state.response = 'confirmed';
});
afterEach(cleanup);
const base = { detail, pickers, returnTo: '/my-tasks', onCancel: () => {} };

it('requires an exact actor-command receipt rather than a newer record version', () => {
    const expected = {
        id: 8,
        version: 4,
        key: 'request',
        command: 'archive' as const,
    };
    const receipt = {
        id: 8,
        lock_version: 5,
        idempotency_key: 'request',
        command: 'archive',
        removed: true,
    };
    expect(confirmedFirstAidCommand(receipt, expected)).toBe(true);
    for (const change of [
        { lock_version: 6 },
        { id: 9 },
        { command: 'update' },
        { idempotency_key: 'other' },
        { removed: false },
    ])
        expect(
            confirmedFirstAidCommand({ ...receipt, ...change }, expected),
        ).toBe(false);
});
it.each(['wrong', 'interrupted'])(
    'retains entries and exact retry identity after %s confirmation',
    (response) => {
        state.response = response;
        const onSaved = vi.fn();
        const view = render(
            <FirstAidCommandForm
                {...base}
                command="complete-followup"
                childId={3}
                onSaved={onSaved}
            />,
        );
        fireEvent.change(screen.getByLabelText('What happened'), {
            target: { value: 'Coordinator confirmed the next review.' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Record follow-up outcome' }),
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'can’t tell if this saved',
        );
        expect(state.reset).not.toHaveBeenCalled();
        expect(onSaved).not.toHaveBeenCalled();
        view.rerender(
            <FirstAidCommandForm
                {...base}
                detail={{ ...detail, expected_version: 12 }}
                command="complete-followup"
                childId={3}
                onSaved={onSaved}
            />,
        );
        state.response = 'confirmed';
        fireEvent.click(
            screen.getByRole('button', { name: 'Record follow-up outcome' }),
        );
        expect(state.submit.mock.calls[1][2]).toEqual(
            state.submit.mock.calls[0][2],
        );
        expect(state.submit.mock.calls[1][2].expected_version).toBe(4);
        expect(onSaved).toHaveBeenCalledOnce();
    },
);
it('preserves a deliberately unassigned follow-up without manufacturing a due date', () => {
    render(
        <FirstAidCommandForm
            {...base}
            command="edit-followup"
            childId={3}
            onSaved={() => {}}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Leave unassigned' }));
    expect(
        screen.getByLabelText('Due (optional) (New Zealand time)'),
    ).not.toBeRequired();
    fireEvent.click(screen.getByRole('button', { name: 'Edit follow-up' }));
    expect(state.submit).toHaveBeenCalledWith(
        'put',
        '/health-safety/first-aid/8/followups/3',
        expect.objectContaining({
            assigned_to_user_id: null,
            due_at: null,
            notes: 'Review with the coordinator',
            expected_version: 4,
        }),
    );
});
it('preserves an untouched due instant including seconds during a notes-only edit', () => {
    const due = '2026-10-08T05:30:47Z';
    render(
        <FirstAidCommandForm
            {...base}
            detail={{
                ...detail,
                followups: [{ ...detail.followups[0], due_at: due }],
            }}
            command="edit-followup"
            childId={3}
            onSaved={() => {}}
        />,
    );
    fireEvent.change(screen.getByLabelText('What needs to happen'), {
        target: { value: 'Updated instructions only' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Edit follow-up' }));
    expect(state.submit.mock.calls[0][2].due_at).toBe(due);
});
it('blocks a revoked child command while preserving the entered outcome', () => {
    const view = render(
        <FirstAidCommandForm
            {...base}
            command="complete-followup"
            childId={3}
            onSaved={() => {}}
        />,
    );
    fireEvent.change(screen.getByLabelText('What happened'), {
        target: { value: 'Keep these notes' },
    });
    view.rerender(
        <FirstAidCommandForm
            {...base}
            detail={{ ...detail, followups: [] }}
            command="complete-followup"
            childId={3}
            onSaved={() => {}}
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Record follow-up outcome' }),
    ).toBeDisabled();
    expect(screen.getByLabelText('What happened')).toHaveValue(
        'Keep these notes',
    );
    expect(state.submit).not.toHaveBeenCalled();
});
it('keeps a failed upload selected and retries the same bytes and metadata', () => {
    state.response = 'interrupted';
    const { container } = render(
        <FirstAidCommandForm
            {...base}
            command="add-evidence"
            onSaved={() => {}}
        />,
    );
    const file = new File(['source evidence'], 'record.pdf', {
        type: 'application/pdf',
    });
    fireEvent.change(container.querySelector('input[type="file"]')!, {
        target: { files: [file] },
    });
    fireEvent.change(screen.getByLabelText('Notes'), {
        target: { value: 'Original evidence' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add evidence' }));
    expect(screen.getByText('record.pdf')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Add evidence' }));
    expect(state.submit.mock.calls[1][2]).toEqual(
        state.submit.mock.calls[0][2],
    );
    expect(state.submit.mock.calls[1][2].file).toBe(file);
    expect(state.reset).not.toHaveBeenCalled();
});
it('does not silently create an incident when the current choice is unavailable', () => {
    const { container } = render(
        <FirstAidCommandForm
            {...base}
            command="link-incident"
            onSaved={() => {}}
        />,
    );
    fireEvent.submit(container.querySelector('form')!);
    expect(screen.getByRole('alert')).toHaveTextContent(
        'Choose an incident you can see',
    );
    expect(state.submit).not.toHaveBeenCalled();
});
