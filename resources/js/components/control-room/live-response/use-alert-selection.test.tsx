import type { AlertWorklistRow } from '@/components/control-room/alert-worklist/types';
import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({ get: vi.fn(), url: '/control-room?alert=1' }));

vi.mock('@inertiajs/react', () => ({
    router: { get: inertia.get },
    usePage: () => ({ url: inertia.url, props: {} }),
}));

import { useAlertSelection } from './use-alert-selection';

const row = (id: number) => ({ id }) as unknown as AlertWorklistRow;
const detailFor = (id: number) => ({ alert: { id } }) as unknown as AlertWorkspaceDetail;

function at(url: string) {
    inertia.url = url;
    window.history.replaceState(null, '', url);
}

afterEach(() => {
    vi.clearAllMocks();
    at('/');
});

describe('useAlertSelection', () => {
    it('keeps a loaded selection without reloading it', () => {
        at('/control-room?alert=1');
        renderHook(() =>
            useAlertSelection({ rows: [row(1), row(2)], detail: detailFor(1), runAction: vi.fn() }),
        );
        expect(inertia.get).not.toHaveBeenCalled();
    });

    it('puts the selection back when a background reload drops it from the address', () => {
        at('/control-room?alert=1');
        const { rerender } = renderHook(
            ({ detail }) => useAlertSelection({ rows: [row(1), row(2)], detail, runAction: vi.fn() }),
            { initialProps: { detail: detailFor(1) as AlertWorkspaceDetail | null } },
        );
        // The shell's deferred props reload the first address (no ?alert=).
        at('/control-room');
        rerender({ detail: null });
        expect(inertia.get).toHaveBeenCalledTimes(1);
        expect(inertia.get).toHaveBeenCalledWith(
            '/control-room?alert=1',
            {},
            expect.objectContaining({ only: ['detail'], replace: true, async: true }),
        );
    });

    it('moves to the top of the list when the chosen alert leaves it', () => {
        at('/control-room?alert=5');
        const { result } = renderHook(() =>
            useAlertSelection({ rows: [row(1), row(2)], detail: null, runAction: vi.fn() }),
        );
        expect(result.current.selectedId).toBe(1);
        expect(inertia.get).toHaveBeenCalledWith(
            '/control-room?alert=1',
            {},
            expect.objectContaining({ only: ['detail'] }),
        );
    });
});
