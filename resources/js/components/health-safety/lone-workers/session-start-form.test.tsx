import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { SessionStartForm } from './session-start-form';
import type { SessionPickers } from './types';

const state = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }));
vi.mock('axios', () => ({
    default: { post: state.post, get: state.get, isAxiosError: () => false },
}));
vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {} },
    Link: (props: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a {...props} />
    ),
}));
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
        disabled,
    }: {
        options: { value: string; label: string }[];
        value: string;
        onChange: (value: string) => void;
        placeholder: string;
        disabled?: boolean;
    }) => (
        <select
            aria-label={placeholder}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            disabled={disabled}
        >
            <option value="">Choose</option>
            {options.map((item) => (
                <option key={item.value} value={item.value}>
                    {item.label}
                </option>
            ))}
        </select>
    ),
}));
const pickers: SessionPickers = {
    sites: [
        {
            id: 3,
            name: 'Kauri',
            address: '1 Kauri Road',
            latitude: 0,
            longitude: 0,
        },
    ],
    workers: [{ id: 7, name: 'Mere', site_ids: [3] }],
    clients: [{ id: 4, name: 'Aroha', site_id: 3 }],
    shifts: [
        {
            id: 12,
            user_id: 7,
            worker: { id: 7, name: 'Mere' },
            site_id: 3,
            client_id: 4,
            starts_at: '2026-10-08T01:00:00Z',
            ends_at: '2026-10-08T05:00:00Z',
            actual_starts_at: '2026-10-08T01:00:00Z',
            is_lone_worker: true,
            address: '2 Shift Road',
        },
    ],
    check_in_statuses: ['ok', 'concern'],
    check_in_intervals: [30, 60],
};
beforeEach(() => {
    state.post.mockReset();
    state.get.mockReset();
});
afterEach(cleanup);
function chooseShift() {
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Choose an active shift' }),
        { target: { value: '12' } },
    );
}
function submit() {
    fireEvent.click(screen.getByRole('button', { name: 'Start session' }));
}

it('starts another fresh draft only after confirmation and fresh eligible options, with a new retry identity', async () => {
    state.post.mockImplementation(async (_url, data) => ({
        data: {
            detail: { id: 22 },
            lone_worker_confirmation: {
                id: 22,
                idempotency_key: data.idempotency_key,
                command: 'start',
                lock_version: 1,
            },
        },
    }));
    state.get
        .mockRejectedValueOnce(new Error('offline'))
        .mockResolvedValue({ data: { can: { start: true }, pickers } });
    render(
        <SessionStartForm
            pickers={pickers}
            returnTo="/health-safety/lone-workers"
            onCancel={vi.fn()}
        />,
    );
    chooseShift();
    expect(
        screen.getByRole('textbox', { name: 'Location description' }),
    ).toHaveValue('2 Shift Road');
    fireEvent.change(
        screen.getByRole('combobox', {
            name: 'Choose a planned location source',
        }),
        { target: { value: 'shift_address' } },
    );
    submit();
    await screen.findByRole('link', { name: 'Open safety session' });
    const first = state.post.mock.calls[0][1];
    expect(first.planned_location).toEqual({ method: 'shift_address' });
    fireEvent.click(
        screen.getByRole('button', { name: 'Start another session' }),
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'next form could not be loaded',
    );
    expect(
        screen.getByRole('link', { name: 'Open safety session' }),
    ).toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Start another session' }),
    );
    await screen.findByRole('button', { name: 'Start session' });
    expect(
        screen.getByRole('combobox', { name: 'Choose an active shift' }),
    ).toHaveValue('standalone');
    expect(
        screen.getByRole('combobox', {
            name: 'Choose a planned location source',
        }),
    ).toHaveValue('none');
    chooseShift();
    submit();
    await waitFor(() => expect(state.post).toHaveBeenCalledTimes(2));
    expect(state.post.mock.calls[1][1].idempotency_key).not.toBe(
        first.idempotency_key,
    );
    expect(state.post.mock.calls[1][1].planned_location).toBeNull();
});

it('clears a source-bound plan when the selected source changes', () => {
    render(
        <SessionStartForm
            pickers={pickers}
            returnTo="/health-safety/lone-workers"
            onCancel={vi.fn()}
        />,
    );
    chooseShift();
    fireEvent.change(
        screen.getByRole('combobox', {
            name: 'Choose a planned location source',
        }),
        { target: { value: 'shift_address' } },
    );
    expect(
        screen.getByRole('textbox', { name: 'Planned address or description' }),
    ).toHaveValue('2 Shift Road');
    fireEvent.change(
        screen.getByRole('combobox', { name: 'Choose an active shift' }),
        { target: { value: 'standalone' } },
    );
    expect(
        screen.getByRole('combobox', {
            name: 'Choose a planned location source',
        }),
    ).toHaveValue('none');
});
it('derives the worker, Site and client from the reviewed shift and requires a matching source confirmation', async () => {
    state.post.mockImplementation(async (_url, data) => ({
        data: {
            detail: { id: 22 },
            lone_worker_confirmation: {
                id: 22,
                idempotency_key: data.idempotency_key,
                command: 'start',
                lock_version: 1,
            },
        },
    }));
    render(
        <SessionStartForm
            pickers={pickers}
            returnTo="/my-day"
            onCancel={vi.fn()}
        />,
    );
    chooseShift();
    submit();
    expect(
        await screen.findByRole('link', { name: 'Open safety session' }),
    ).toHaveAttribute(
        'href',
        '/health-safety/lone-workers/sessions/22?return_to=%2Fmy-day',
    );
    expect(state.post).toHaveBeenCalledWith(
        '/health-safety/lone-workers/sessions',
        expect.objectContaining({
            user_id: 7,
            site_id: 3,
            client_id: 4,
            shift_id: 12,
            expected_end_at: '2026-10-08T18:00:00+13:00',
            idempotency_key: expect.any(String),
        }),
        expect.any(Object),
    );
    expect(screen.getByRole('status')).toHaveTextContent(
        'Open the session to review its check-ins and response',
    );
});
it.each([
    'wrong-source',
    'wrong-key',
    'wrong-command',
    'missing-proof',
    'network',
])('retains the draft and exact retry identity after %s', async (failure) => {
    state.post.mockImplementation(async (_url, data) => {
        if (failure === 'network') throw new Error('lost connection');
        return {
            data: {
                detail: { id: 22 },
                lone_worker_confirmation:
                    failure === 'missing-proof'
                        ? null
                        : {
                              id: failure === 'wrong-source' ? 23 : 22,
                              idempotency_key:
                                  failure === 'wrong-key'
                                      ? 'other'
                                      : data.idempotency_key,
                              command:
                                  failure === 'wrong-command'
                                      ? 'checkIn'
                                      : 'start',
                              lock_version: 1,
                          },
            },
        };
    });
    render(
        <SessionStartForm
            own
            pickers={pickers}
            returnTo="/my-day"
            onCancel={vi.fn()}
        />,
    );
    chooseShift();
    fireEvent.change(screen.getByRole('textbox', { name: 'Activity' }), {
        target: { value: 'Visiting Aroha' },
    });
    submit();
    expect(await screen.findByRole('alert')).toHaveTextContent(
        'could not be confirmed',
    );
    expect(screen.getByRole('textbox', { name: 'Activity' })).toHaveValue(
        'Visiting Aroha',
    );
    expect(
        screen.queryByRole('link', { name: 'Open safety session' }),
    ).not.toBeInTheDocument();
    const first = state.post.mock.calls[0][1];
    submit();
    await waitFor(() => expect(state.post).toHaveBeenCalledTimes(2));
    expect(state.post.mock.calls[1][1]).toEqual(first);
    expect(state.post.mock.calls[0][0]).toBe(
        '/health-safety/lone-workers/sessions/start-own',
    );
    expect(first).not.toHaveProperty('user_id');
    expect(first).not.toHaveProperty('client_id');
});
it('does not submit an own session without an eligible shift', () => {
    render(
        <SessionStartForm
            own
            pickers={{ ...pickers, shifts: [] }}
            returnTo="/my-day"
            onCancel={vi.fn()}
        />,
    );
    expect(
        screen.getByRole('button', { name: 'Start session' }),
    ).toBeDisabled();
    expect(state.post).not.toHaveBeenCalled();
});
