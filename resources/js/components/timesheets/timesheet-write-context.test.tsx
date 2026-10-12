import { cleanup, render } from '@testing-library/react';
import { useLayoutEffect } from 'react';
import { afterEach, expect, it } from 'vitest';
import { TimesheetWriteProvider, useTimesheetWriteCoordination } from './timesheet-write-context';
import type { TimesheetWriteLease, TimesheetWriteScope } from './timesheet-write-scope';

afterEach(cleanup);
it.each(['preparation', 'completion'] as const)(
    'denies the original actor in a child layout callback after committed actor change: %s',
    (mode) => {
        const capture: {
            scope: TimesheetWriteScope | null;
            token: TimesheetWriteLease | null;
            decision: boolean | null;
        } = { scope: null, token: null, decision: null };
        function Child({ attempt }: { attempt: boolean }) {
            const { scope } = useTimesheetWriteCoordination();
            useLayoutEffect(() => {
                if (!scope) throw new Error('Owning provider missing');
                if (!attempt) {
                    capture.scope = scope;
                    capture.token = scope.tryPrepare(7);
                    if (!capture.token) throw new Error('Preparation denied');
                    if (mode === 'completion') {
                        scope.markInvoked(capture.token);
                        scope.markCallback(capture.token);
                    }
                } else {
                    expect(scope).toBe(capture.scope);
                    if (!capture.token) throw new Error('Original token missing');
                    capture.decision = mode === 'preparation'
                        ? scope.canDispatch(capture.token) : scope.completeMatched(capture.token);
                }
            }, [scope, attempt]);
            return null;
        }
        const page = render(<TimesheetWriteProvider actorId={7}><Child attempt={false} /></TimesheetWriteProvider>);
        expect(capture.scope).not.toBeNull();
        page.rerender(<TimesheetWriteProvider actorId={8}><Child attempt /></TimesheetWriteProvider>);
        expect(capture.decision).toBe(false);
        expect(capture.scope!.snapshot()).toBe('needs_check');
        expect(capture.scope!.tryPrepare(8)).toBeNull();
    },
);
