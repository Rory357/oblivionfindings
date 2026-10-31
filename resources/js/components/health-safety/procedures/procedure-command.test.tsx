import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ProcedureCommand } from './procedure-command';
import type { ProcedureWorkspaceDetail } from './types';
const state = vi.hoisted(() => ({
    post: vi.fn(),
    error: null as string | null,
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
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                transform: (fn: typeof transform.current) => {
                    transform.current = fn;
                },
                reset: vi.fn(),
                clearErrors: vi.fn(),
                post: (
                    url: string,
                    options: {
                        onSuccess: (page: { props: object }) => void;
                        onFinish: () => void;
                    },
                ) => {
                    state.post(url, transform.current(data));
                    options.onSuccess({
                        props: { flash: { error: state.error } },
                    });
                    options.onFinish();
                },
            };
        },
    };
});
beforeEach(() => {
    state.post.mockReset();
    state.error = null;
});
afterEach(cleanup);
function detail(overrides: Partial<ProcedureWorkspaceDetail> = {}) {
    return {
        id: 9,
        expected_version: 4,
        lock_version: 7,
        review_date: '2026-11-08',
        viewed_revision: {
            version: 3,
            version_id: 12,
            kind: 'published',
            legacy: false,
        },
        publication: { version_id: 12, version: 3 },
        can: { approve: true, acknowledge: true, record_review: true },
        ...overrides,
    } as ProcedureWorkspaceDetail;
}
it('approval binds the expected working revision and lock, not the displayed publication', () => {
    render(
        <ProcedureCommand
            detail={detail()}
            command="approve"
            onCancel={vi.fn()}
            onSaved={vi.fn()}
        />,
    );
    fireEvent.change(screen.getByRole('textbox', { name: 'Approval note' }), {
        target: { value: 'Checked controls and documents.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Approve & publish' }));
    expect(state.post).toHaveBeenCalledWith(
        '/health-safety/procedures/9/approve',
        {
            expected_version: 4,
            expected_lock_version: 7,
            approval_note: 'Checked controls and documents.',
            review_date: '2026-11-08',
        },
    );
});
it('acknowledgement requires explicit reading confirmation and binds the exact publication', () => {
    render(
        <ProcedureCommand
            detail={detail()}
            command="acknowledge"
            onCancel={vi.fn()}
            onSaved={vi.fn()}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Acknowledge this version' }),
    );
    expect(state.post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('checkbox'));
    fireEvent.click(
        screen.getByRole('button', { name: 'Acknowledge this version' }),
    );
    expect(state.post).toHaveBeenCalledWith(
        '/health-safety/procedures/9/acknowledge',
        { published_version_id: 12 },
    );
});
it('retains entered review findings when the server rejects a stale revision', () => {
    state.error = 'This procedure changed. Review the current version.';
    const saved = vi.fn();
    render(
        <ProcedureCommand
            detail={detail()}
            command="record-review"
            onCancel={vi.fn()}
            onSaved={saved}
        />,
    );
    fireEvent.change(screen.getByRole('textbox', { name: /Review notes/ }), {
        target: { value: 'Hoist instructions need checking.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Start a review' }));
    expect(saved).not.toHaveBeenCalled();
    expect(
        (
            screen.getByRole('textbox', {
                name: /Review notes/,
            }) as HTMLTextAreaElement
        ).value,
    ).toBe('Hoist instructions need checking.');
    expect(screen.getByRole('alert').textContent).toContain(
        'procedure changed',
    );
});
it('keeps historical or revoked capabilities from dispatching a command', () => {
    const d = detail();
    d.can.approve = false;
    const { container } = render(
        <ProcedureCommand
            detail={d}
            command="approve"
            onCancel={vi.fn()}
            onSaved={vi.fn()}
        />,
    );
    fireEvent.submit(container.querySelector('form')!);
    expect(state.post).not.toHaveBeenCalled();
});
