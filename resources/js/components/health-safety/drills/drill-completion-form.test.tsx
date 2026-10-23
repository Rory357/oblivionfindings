import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { DrillCompletionForm } from './drill-completion-form';
import type { DrillPickers, DrillWorkspaceDetail } from './types';

const state = vi.hoisted(() => ({ submit: vi.fn(), response: 'unconfirmed' }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = React.useState(initial);
            const transform = React.useRef(
                (value: Record<string, unknown>) => value,
            );
            return {
                data,
                errors: {},
                isDirty: true,
                processing: false,
                setData: (
                    key:
                        | string
                        | ((
                              current: Record<string, unknown>,
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
                    href: string,
                    callbacks: {
                        onSuccess: (page: { props: object }) => void;
                        onFinish: () => void;
                    },
                ) => {
                    state.submit(method, href, transform.current(data));
                    if (state.response === 'confirmed')
                        callbacks.onSuccess({
                            props: { detail: { id: 8, lock_version: 5 } },
                        });
                    callbacks.onFinish();
                },
            };
        },
    };
});
vi.mock('@/components/wizard/primitives', async (original) => {
    const actual =
        await original<typeof import('@/components/wizard/primitives')>();
    return {
        ...actual,
        SelectInput: ({
            id,
            value,
            options,
            onChange,
        }: {
            id: string;
            value: string;
            options: { value: string; label: string }[];
            onChange: (value: string) => void;
        }) => (
            <select
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">Choose</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        ),
    };
});
const detail = {
    id: 8,
    expected_version: 4,
    lock_version: 4,
    started_at: '2026-04-04T12:00:00Z',
    can: { complete: true },
} as unknown as DrillWorkspaceDetail;
const pickers = {
    outcomes: ['passed', 'passed_actions', 'failed'],
} as DrillPickers;
const props = {
    detail,
    pickers,
    returnTo: '/calendar?date=2026-04-05&view=day',
    onSaved: vi.fn(),
    onCancel: vi.fn(),
};
beforeEach(() => {
    state.submit.mockReset();
    state.response = 'unconfirmed';
    props.onSaved.mockReset();
});
afterEach(cleanup);
function fill() {
    fireEvent.change(screen.getByLabelText(/Completed at/), {
        target: { value: '2026-04-05T02:30' },
    });
    fireEvent.change(screen.getByLabelText(/Actual outcome/), {
        target: { value: 'failed' },
    });
}
it('requires the real clock occurrence at NZ daylight-saving fallback and preserves failed checks', () => {
    render(<DrillCompletionForm {...props} />);
    fill();
    fireEvent.click(screen.getByRole('button', { name: 'Record completion' }));
    expect(state.submit).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText(/Which occurrence/), {
        target: { value: '+12:00' },
    });
    state.response = 'confirmed';
    fireEvent.click(screen.getByRole('button', { name: 'Record completion' }));
    expect(state.submit).toHaveBeenCalledWith(
        'post',
        '/health-safety/drills/8/complete',
        expect.objectContaining({
            completed_at: '2026-04-05T02:30:00+12:00',
            outcome: 'failed',
            all_areas_checked: false,
            assembly_point_reached: false,
            roll_call_completed: false,
            total_participants: null,
            residents_evacuated: null,
            expected_version: 4,
            return_to: props.returnTo,
        }),
    );
    expect(props.onSaved).toHaveBeenCalledTimes(1);
});
it('retains completion notes and the original version and identity after an unknown response and refresh', () => {
    const view = render(<DrillCompletionForm {...props} />);
    fill();
    fireEvent.change(screen.getByLabelText(/Which occurrence/), {
        target: { value: '+13:00' },
    });
    fireEvent.change(screen.getByLabelText('Observer notes'), {
        target: { value: 'Roll-call could not be confirmed.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Record completion' }));
    const first = state.submit.mock.calls[0][2];
    expect(props.onSaved).not.toHaveBeenCalled();
    expect(
        (screen.getByLabelText('Observer notes') as HTMLTextAreaElement).value,
    ).toBe('Roll-call could not be confirmed.');
    view.rerender(
        <DrillCompletionForm
            {...props}
            detail={{ ...detail, expected_version: 7, lock_version: 7 }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record completion' }));
    const retry = state.submit.mock.calls[1][2];
    expect(retry.idempotency_key).toBe(first.idempotency_key);
    expect(retry.expected_version).toBe(4);
    expect(screen.getByRole('alert').textContent).toContain(
        'could not be confirmed',
    );
});
it('does not allow completion before the recorded start or after permission disappears', () => {
    const view = render(
        <DrillCompletionForm
            {...props}
            detail={{ ...detail, started_at: '2026-04-05T04:00:00+12:00' }}
        />,
    );
    fill();
    fireEvent.change(screen.getByLabelText(/Which occurrence/), {
        target: { value: '+12:00' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Record completion' }));
    expect(state.submit).not.toHaveBeenCalled();
    expect(screen.getByRole('alert').textContent).toContain('earlier than');
    view.rerender(
        <DrillCompletionForm
            {...props}
            detail={{ ...detail, can: { ...detail.can, complete: false } }}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Record completion' }),
    ).toBeNull();
});
