import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ProcedureWithdrawPopup } from './procedure-withdraw-popup';
import type { ProcedureWorkspaceDetail } from './types';

// Withdraw draft (decided 6 Nov 2026): the kit pop-up needs a reason and sends the reviewed revision guards.
const state = vi.hoisted(() => ({ post: vi.fn(), errors: {} as Record<string, string> }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        router: { on: () => () => {} },
        useForm: function useForm(initial: Record<string, unknown>) {
            const [data, setData] = React.useState(initial);
            const transform = React.useRef((value: Record<string, unknown>) => value);
            return {
                data, errors: state.errors, isDirty: data.reason !== '', processing: false,
                setData: (key: string, value: unknown) => setData((current) => ({ ...current, [key]: value })),
                transform: (fn: typeof transform.current) => { transform.current = fn; },
                reset: vi.fn(), clearErrors: vi.fn(),
                post: (url: string, options: { onSuccess: () => void }) => { state.post(url, transform.current(data)); options.onSuccess(); },
            };
        },
    };
});

const detail = { id: 7, title: 'Draft made by mistake', reference_number: 'SWP-7', version: 2, expected_version: 2, lock_version: 5 } as unknown as ProcedureWorkspaceDetail;
beforeEach(() => { state.post.mockClear(); state.errors = {}; });
afterEach(cleanup);

it('needs a reason, then withdraws the reviewed revision and closes', () => {
    const onClose = vi.fn();
    render(<ProcedureWithdrawPopup open detail={detail} onClose={onClose} />);
    expect(screen.getByRole('dialog')).toHaveTextContent('Withdraw this draft?');
    expect(screen.getByText('SWP-7 · Draft made by mistake')).toBeInTheDocument();
    const submit = screen.getByRole('button', { name: 'Withdraw draft' });
    expect(submit).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Why are you withdrawing it/), { target: { value: '   ' } });
    expect(submit).toBeDisabled();
    fireEvent.change(screen.getByLabelText(/Why are you withdrawing it/), { target: { value: 'Created twice by mistake' } });
    fireEvent.click(submit);
    expect(state.post).toHaveBeenCalledWith('/health-safety/procedures/7/withdraw', { reason: 'Created twice by mistake', expected_version: 2, expected_lock_version: 5 });
    expect(onClose).toHaveBeenCalled();
});

it('shows a refusal from the server in the pop-up', () => {
    state.errors = { status: 'Only a draft that has never been published can be withdrawn. Archive a published procedure instead.' };
    render(<ProcedureWithdrawPopup open detail={detail} onClose={vi.fn()} />);
    expect(screen.getByRole('dialog')).toHaveTextContent('Only a draft that has never been published can be withdrawn.');
});
