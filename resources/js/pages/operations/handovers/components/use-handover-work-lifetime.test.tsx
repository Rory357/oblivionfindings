import { act, render, renderHook } from '@testing-library/react';
import { StrictMode, useLayoutEffect } from 'react';
import { describe, expect, it } from 'vitest';
import type { HandoverWorkAttempt, HandoverWorkContext, HandoverWorkDraft, HandoverWorkLifetime } from './handover-work-lifetime';
import { useHandoverWorkLifetime } from './use-handover-work-lifetime';

const context: HandoverWorkContext = { actorId: 4, recordId: 12, expectedVersion: 3, canRead: true, canWrite: true };
const uuid = '7d886442-95d4-4bb6-a8b5-364489f0d22d';
const draft: HandoverWorkDraft = {
    client_id: '7', outgoing: '4', outgoing_shift: '20', incoming: '9', incoming_shift: '21',
    leave_open: false, narrative: 'Support after dinner.', mood: 'Settled', medications: [],
    incidents: [], followups: [], tasks: [], cd_result: '', cd_witness: '', cd_notes: '',
};

describe('committed operations handover owner', () => {
    it('retains one owner when equivalent current props are recreated', () => {
        const { result, rerender } = renderHook((props) => useHandoverWorkLifetime(props), { initialProps: context });
        const original = result.current.work;
        act(() => { original.initialise(draft); original.capture({ ...draft, narrative: 'Retained typing' }, 2); });
        rerender({ ...context });
        expect(result.current.work).toBe(original);
        expect(result.current.work.resume()?.step).toBe(2);
        expect(result.current.state.dirty).toBe(true);
    });

    it('publishes account withdrawal before descendant layout callbacks can finish a save', () => {
        let work!: HandoverWorkLifetime;
        let attempt: HandoverWorkAttempt | null = null;
        let lateAllowed: boolean | null = null;
        function Child({ actorId, owner }: { actorId: number; owner: HandoverWorkLifetime }) {
            useLayoutEffect(() => {
                if (actorId === 5 && attempt) lateAllowed = owner.finish(attempt);
            }, [actorId, owner]);
            return null;
        }
        function Owner({ actorId }: { actorId: number }) {
            const owner = useHandoverWorkLifetime({ ...context, actorId });
            // Hand the owner to the test once the render commits, not during it.
            useLayoutEffect(() => {
                work = owner.work;
            });
            return <Child actorId={actorId} owner={owner.work} />;
        }
        const page = render(<Owner actorId={4} />);
        act(() => { work.initialise(draft); attempt = work.begin(uuid, false); });
        expect(attempt).not.toBeNull();
        page.rerender(<Owner actorId={5} />);
        expect(lateAllowed).toBe(false);
        expect(work.resume()).toBeNull();
        page.rerender(<Owner actorId={4} />);
        act(() => { expect(work.finish(attempt!)).toBe(false); });
    });

    it('retires the actual owner on unmount and never admits its later callback', () => {
        const { result, unmount } = renderHook(() => useHandoverWorkLifetime(context));
        const work = result.current.work;
        let attempt: HandoverWorkAttempt | null = null;
        act(() => { work.initialise(draft); attempt = work.begin(uuid, false); });
        expect(attempt).not.toBeNull();
        unmount();
        expect(work.resume()).toBeNull();
        expect(work.finish(attempt!)).toBe(false);
        expect(work.canNavigate()).toBe(false);
    });

    it('keeps the mounted owner usable under the application StrictMode wrapper', () => {
        const { result, unmount } = renderHook(() => useHandoverWorkLifetime(context), { wrapper: StrictMode });
        const work = result.current.work;
        act(() => {
            expect(work.initialise(draft)).toBe(true);
            expect(work.begin(uuid, false)).not.toBeNull();
        });
        expect(result.current.state.phase).toBe('pending');
        unmount();
        expect(work.resume()).toBeNull();
    });
});
