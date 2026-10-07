import { act, renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    payrollAdjustmentReceipt,
    usePayrollAdjustmentCommand,
    type PayrollAdjustmentReceipt,
} from './use-payroll-adjustment-command';
const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post } }));
const identity = { actorId: 7, amendmentId: 12, timesheetId: 51 };
const receipt: PayrollAdjustmentReceipt = {
    action: 'process_payroll_adjustment',
    actor_id: 7,
    amendment_id: 12,
    timesheet_id: 51,
    changed: true,
    outcome: 'recorded_external_processing',
    processing_method: 'external',
    applied_at: '2026-10-07T08:17:00.000Z',
};
beforeEach(() => vi.clearAllMocks());
it('confirms only the same requester and adjustment, with an actual committed timestamp', () => {
    expect(
        payrollAdjustmentReceipt(
            { timesheet_payroll_adjustment_result: receipt },
            identity,
        ),
    ).toEqual({ status: 'confirmed', receipt });
    for (const invalid of [
        { actor_id: 9 },
        { amendment_id: 13 },
        { timesheet_id: 52 },
        { changed: 'true' },
        { outcome: 'already_recorded' },
        { processing_method: 'paid' },
        { action: 'approve' },
        { applied_at: '2026-02-30T08:17:00.000Z' },
        { applied_at: null },
    ])
        expect(
            payrollAdjustmentReceipt(
                {
                    timesheet_payroll_adjustment_result: {
                        ...receipt,
                        ...invalid,
                    },
                },
                identity,
            ).status,
        ).toBe('unknown');
    for (const flash of [
        { success: 'Processed.' },
        {},
        { warning: 'Not saved', timesheet_payroll_adjustment_result: receipt },
        { error: 'Not saved', timesheet_payroll_adjustment_result: receipt },
    ])
        expect(payrollAdjustmentReceipt(flash, identity).status).toBe(
            'unknown',
        );
});
it('retains the original timestamp on an already-recorded outcome', () => {
    const prior = {
        ...receipt,
        changed: false,
        outcome: 'already_recorded' as const,
        applied_at: '2026-09-01T00:00:00.000Z',
    };
    expect(
        payrollAdjustmentReceipt(
            { timesheet_payroll_adjustment_result: prior },
            identity,
        ),
    ).toEqual({ status: 'confirmed', receipt: prior });
});
it('allows one in-flight identity-only request and never replays an interrupted outcome', () => {
    const { result } = renderHook(() => usePayrollAdjustmentCommand('7:12:51'));
    act(() => {
        result.current.submit(identity);
        result.current.submit(identity);
    });
    expect(post).toHaveBeenCalledTimes(1);
    expect(post.mock.calls[0].slice(0, 2)).toEqual([
        '/operations/timesheets/amendments/12/mark-processed',
        {},
    ]);
    const options = post.mock.calls[0][2];
    act(() => {
        options.onCancel();
        options.onFinish();
        result.current.submit(identity);
    });
    expect(result.current.outcome?.status).toBe('unknown');
    expect(result.current.pending).toBe(false);
    expect(post).toHaveBeenCalledTimes(1);
});
it('keeps the first definitive outcome when callbacks arrive late or more than once', () => {
    const { result } = renderHook(() => usePayrollAdjustmentCommand('7:12:51'));
    act(() => result.current.submit(identity));
    const options = post.mock.calls[0][2];
    act(() => {
        options.onSuccess({
            props: { flash: { timesheet_payroll_adjustment_result: receipt } },
        });
        options.onCancel();
        options.onFinish();
        options.onError({ failed: 'Late' });
        options.onFinish();
    });
    expect(result.current.outcome).toEqual({ status: 'confirmed', receipt });
});
it('does not confirm a late response after the signed-in person changes and changes back', () => {
    const { result, rerender } = renderHook(
        ({ context }) => usePayrollAdjustmentCommand(context),
        { initialProps: { context: '7:12:51' } },
    );
    act(() => result.current.submit(identity));
    const options = post.mock.calls[0][2];
    rerender({ context: '8:12:51' });
    rerender({ context: '7:12:51' });
    act(() => {
        options.onSuccess({
            props: { flash: { timesheet_payroll_adjustment_result: receipt } },
        });
        options.onFinish();
    });
    expect(result.current.outcome?.status).toBe('unknown');
});
it('holds the action after a synchronous transport fault or a finish without a receipt', () => {
    post.mockImplementationOnce(() => {
        throw new Error('Transport');
    });
    const first = renderHook(() => usePayrollAdjustmentCommand('7:12:51'));
    act(() => first.result.current.submit(identity));
    expect(first.result.current.outcome?.status).toBe('unknown');
    expect(first.result.current.pending).toBe(false);
    first.unmount();
    post.mockReset();
    const next = renderHook(() => usePayrollAdjustmentCommand('7:12:51'));
    act(() => next.result.current.submit(identity));
    act(() => post.mock.calls[0][2].onFinish());
    expect(next.result.current.outcome?.status).toBe('unknown');
});
