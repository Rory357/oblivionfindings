import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { RaCommand, riskCommandAllowed } from './ra-command';
import type { RaDetail } from './types';
const state = vi.hoisted(() => ({ post: vi.fn(), success: false }));
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
                isDirty: false,
                processing: false,
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                transform: (fn: typeof transform.current) => {
                    transform.current = fn;
                },
                reset: vi.fn(),
                clearErrors: vi.fn(),
                post: (
                    url: string,
                    options: { onSuccess: (page: { props: object }) => void },
                ) => {
                    state.post(url, transform.current(data));
                    if (state.success)
                        options.onSuccess({ props: { flash: {} } });
                },
            };
        },
    };
});
const detail = {
    id: 8,
    status: 'under_review',
    can: { manage: true, record_residual: true, complete_review: true },
    residual_likelihood: null,
    residual_consequence: null,
    risk_acceptable: null,
    review_due_at: '2026-10-08',
} as RaDetail;
beforeEach(() => {
    vi.clearAllMocks();
    state.success = false;
});
afterEach(cleanup);
it('never treats recording residual risk as review completion', () => {
    const saved = vi.fn();
    render(
        <RaCommand
            detail={detail}
            command="residual"
            onCancel={vi.fn()}
            onSaved={saved}
        />,
    );
    fireEvent.change(screen.getByLabelText('Residual risk notes'), {
        target: { value: 'Controls checked' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Record residual risk' }),
    );
    expect(state.post).toHaveBeenCalledWith(
        '/health-safety/risk-assessments/8/residual',
        {
            residual_likelihood: null,
            residual_consequence: null,
            risk_acceptable: null,
            review_note: 'Controls checked',
        },
    );
    expect(screen.getByLabelText('Residual risk notes')).toHaveValue(
        'Controls checked',
    );
    expect(saved).not.toHaveBeenCalled();
});
it('posts explicit review findings and a new date only to complete-review', () => {
    render(
        <RaCommand
            detail={detail}
            command="complete-review"
            onCancel={vi.fn()}
            onSaved={vi.fn()}
        />,
    );
    fireEvent.change(screen.getByLabelText('Review findings'), {
        target: { value: 'Support plan checked' },
    });
    fireEvent.change(screen.getByLabelText('Next review due'), {
        target: { value: '2026-11-08' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Complete review' }));
    expect(state.post).toHaveBeenCalledWith(
        '/health-safety/risk-assessments/8/complete-review',
        { review_note: 'Support plan checked', review_due_at: '2026-11-08' },
    );
});
it('requires current command capabilities, not just generic management', () => {
    expect(
        riskCommandAllowed({ ...detail, can: { manage: true } }, 'archive'),
    ).toBe(false);
    expect(
        riskCommandAllowed(
            { ...detail, can: { manage: false, complete_review: true } },
            'complete-review',
        ),
    ).toBe(false);
});
