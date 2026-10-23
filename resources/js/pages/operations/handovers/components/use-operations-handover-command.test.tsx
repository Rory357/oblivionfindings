import { act, render, renderHook } from '@testing-library/react';
import { StrictMode, useLayoutEffect } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { HandoverCommandSession } from './handover-command-session';
import { useOperationsHandoverCommand } from './use-operations-handover-command';

const { request } = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('axios', () => ({ default: { request } }));
const context = { recordId: 12, version: 3, shiftId: 20, canRead: true, canWrite: true };

describe('Operations handover command owner', () => {
    it('leaves legacy shared callers without an Operations session or transport', () => {
        const { result, rerender } = renderHook(({ actor }) => useOperationsHandoverCommand(false, actor, context),
            { initialProps: { actor: 4 } });
        expect(result.current.session).toBeNull();
        rerender({ actor: 5 });
        expect(result.current.session).toBeNull();
        expect(request).not.toHaveBeenCalled();
    });

    it('retains the same mounted owner through StrictMode and retires it on actual unmount', () => {
        const { result, rerender, unmount } = renderHook(() => useOperationsHandoverCommand(true, 4, { ...context }),
            { wrapper: StrictMode });
        const session = result.current.session!;
        expect(session.canEdit()).toBe(true);
        rerender();
        expect(result.current.session).toBe(session);
        expect(session.belongsTo(4)).toBe(true);
        unmount();
        expect(session.canEdit()).toBe(false);
        expect(session.belongsTo(4)).toBe(false);
    });

    it('publishes actor withdrawal before descendant layout callbacks can use the old owner', () => {
        let allowed: boolean | null = null;
        let owner!: HandoverCommandSession;
        function Child({ actor, session }: { actor: number; session: HandoverCommandSession }) {
            useLayoutEffect(() => { if (actor === 5) allowed = session.belongsTo(4); }, [actor, session]);
            return null;
        }
        function Host({ actor }: { actor: number }) {
            const { session } = useOperationsHandoverCommand(true, actor, context);
            owner = session!;
            return <Child actor={actor} session={session!} />;
        }
        const page = render(<Host actor={4} />);
        page.rerender(<Host actor={5} />);
        expect(allowed).toBe(false);
        page.rerender(<Host actor={4} />);
        expect(owner.canEdit()).toBe(false);
    });

    it('publishes current read withdrawal and conceals the previous record even if access later returns', () => {
        const { result, rerender } = renderHook(({ canRead }) => useOperationsHandoverCommand(true, 4, { ...context, canRead }),
            { initialProps: { canRead: true } });
        const owner = result.current.session!;
        rerender({ canRead: false });
        expect(result.current.state.phase).toBe('unavailable');
        expect(owner.belongsTo(4)).toBe(false);
        rerender({ canRead: true });
        act(() => { expect(owner.canEdit()).toBe(false); });
    });
});
