import { expect, it, vi } from 'vitest';
import { TimesheetWriteScope, type TimesheetNoSendBasis } from './timesheet-write-scope';

it('acquires one synchronous owner before another preparation can begin', () => {
    const scope = new TimesheetWriteScope(() => 7);
    const first = scope.tryPrepare(7);
    expect(first).not.toBeNull(); expect(scope.snapshot()).toBe('busy');
    expect(scope.tryPrepare(7)).toBeNull();
    expect(scope.canDispatch(first!)).toBe(true);
});
it('refuses coercion, another actor and unavailable current identity', () => {
    const scope = new TimesheetWriteScope(() => 7);
    for (const candidate of [0, -1, 1.2, '7', true, null, 8, Number.MAX_SAFE_INTEGER + 1])
        expect(scope.tryPrepare(candidate)).toBeNull();
    const unavailable = new TimesheetWriteScope(() => { throw new Error('unavailable'); });
    expect(unavailable.tryPrepare(7)).toBeNull();
});
it('lets a proven preparation exit release without ever invoking transport', () => {
    const scope = new TimesheetWriteScope(() => 7), first = scope.tryPrepare(7)!;
    expect(scope.completeNoSend(first, 'preparation_exit')).toBe(true);
    expect(scope.snapshot()).toBe('idle'); expect(scope.tryPrepare(7)).not.toBeNull();
});
it('distinguishes transport invocation from exact synchronous before-veto no-send', () => {
    const scope = new TimesheetWriteScope(() => 7), first = scope.tryPrepare(7)!;
    expect(scope.completeNoSend(first, 'exact_before_veto')).toBe(false);
    expect(scope.markInvoked(first)).toBe(true);
    expect(scope.completeNoSend(first, 'unproven' as TimesheetNoSendBasis)).toBe(false);
    expect(scope.completeNoSend(first, 'preparation_exit')).toBe(false);
    expect(scope.completeNoSend(first, 'exact_before_veto')).toBe(true);
    expect(scope.snapshot()).toBe('idle');
});
it('refuses no-send when the admitted request has started', () => {
    const scope = new TimesheetWriteScope(() => 7), first = scope.tryPrepare(7)!;
    scope.markInvoked(first); scope.markStarted(first);
    expect(scope.completeNoSend(first, 'exact_before_veto')).toBe(false);
    expect(scope.snapshot()).toBe('busy');
});
it('refuses no-send when a response or cancellation callback contradicts it', () => {
    const scope = new TimesheetWriteScope(() => 7), first = scope.tryPrepare(7)!;
    scope.markInvoked(first); scope.markCallback(first);
    expect(scope.completeNoSend(first, 'exact_before_veto')).toBe(false);
});
it('allows only the original invoked owner to finish an assessed matching result', () => {
    const scope = new TimesheetWriteScope(() => 7), first = scope.tryPrepare(7)!;
    expect(scope.completeMatched(first)).toBe(false);
    scope.markInvoked(first); scope.markStarted(first); scope.markCallback(first);
    expect(scope.completeMatched(Symbol('foreign'))).toBe(false);
    expect(scope.completeMatched(first)).toBe(true); expect(scope.snapshot()).toBe('idle');
});
it('keeps an unknown result held despite late matching/rejection signals', () => {
    const scope = new TimesheetWriteScope(() => 7), first = scope.tryPrepare(7)!;
    scope.markInvoked(first); scope.markStarted(first); scope.holdUnknown(first);
    expect(scope.snapshot()).toBe('needs_check'); expect(scope.tryPrepare(7)).toBeNull();
    expect(scope.completeMatched(first)).toBe(false);
    expect(scope.completeKnownRejection(first)).toBe(false);
});
it('invalidates the original owner when the signed-in actor changes and returns', () => {
    let actor = 7;
    const scope = new TimesheetWriteScope(() => actor), first = scope.tryPrepare(7)!;
    actor = 8; scope.observeActor(); actor = 7; scope.observeActor();
    expect(scope.canDispatch(first)).toBe(false); expect(scope.snapshot()).toBe('needs_check');
    expect(scope.completeNoSend(first, 'preparation_exit')).toBe(true);
    expect(scope.tryPrepare(7)).not.toBeNull();
});
it('does not let an old completion release a newly acquired owner', () => {
    const scope = new TimesheetWriteScope(() => 7), old = scope.tryPrepare(7)!;
    scope.completeNoSend(old, 'preparation_exit'); const current = scope.tryPrepare(7)!;
    scope.holdUnknown(old); scope.markStarted(old); scope.markCallback(old);
    expect(scope.completeMatched(old)).toBe(false);
    expect(scope.completeNoSend(old, 'preparation_exit')).toBe(false);
    expect(scope.snapshot()).toBe('busy'); expect(scope.canDispatch(current)).toBe(true);
});
it('does not strand a lease when a rendering subscriber throws', () => {
    const scope = new TimesheetWriteScope(() => 7), good = vi.fn();
    scope.subscribe(() => { throw new Error('render failed'); }); const unsubscribe = scope.subscribe(good);
    const first = scope.tryPrepare(7)!; expect(first).toBeTypeOf('symbol');
    expect(good).toHaveBeenCalledOnce(); unsubscribe();
    expect(scope.completeNoSend(first, 'preparation_exit')).toBe(true);
    expect(good).toHaveBeenCalledOnce();
});
it('does not disclose command fields, rows or actor identity in its public snapshot', () => {
    const scope = new TimesheetWriteScope(() => 7);
    scope.tryPrepare(7); expect(scope.snapshot()).toBe('busy');
    expect(JSON.stringify(scope)).toBe('{}');
});

it('requires an observed original callback before assessed completion', () => {
    const scope = new TimesheetWriteScope(() => 7), first = scope.tryPrepare(7)!;
    scope.markInvoked(first);
    expect(scope.completeMatched(first)).toBe(false);
    expect(scope.completeKnownRejection(first)).toBe(false);
    expect(scope.snapshot()).toBe('busy'); expect(scope.tryPrepare(7)).toBeNull();
    scope.markCallback(first);
    expect(scope.completeKnownRejection(first)).toBe(true);
    expect(scope.snapshot()).toBe('idle');
});
it('bounds a notification when a listener unsubscribes and resubscribes itself', () => {
    const scope = new TimesheetWriteScope(() => 7);
    let calls = 0;
    let unsubscribe: () => void;
    const listener = () => {
        calls += 1;
        unsubscribe();
        unsubscribe = scope.subscribe(listener);
    };
    unsubscribe = scope.subscribe(listener);
    const first = scope.tryPrepare(7)!;
    expect(first).toBeTypeOf('symbol'); expect(calls).toBe(1);
    expect(scope.completeNoSend(first, 'preparation_exit')).toBe(true);
    expect(calls).toBe(2); unsubscribe();
});
