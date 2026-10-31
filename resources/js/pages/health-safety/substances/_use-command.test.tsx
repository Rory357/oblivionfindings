import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
const io = vi.hoisted(() => ({ request: vi.fn(), get: vi.fn() }));
vi.mock('axios', () => ({ default: { request: io.request, get: io.get, isAxiosError: (value: unknown) => !!value && typeof value === 'object' && 'response' in value } }));
vi.mock('@/hooks/use-safety-workspace-drafts', () => ({ useSafetyDraftState: vi.fn() }));
import { confirmsChemicalReceipt, useChemicalCommand, type ChemicalCommandTarget } from './_use-command';
const create: ChemicalCommandTarget = { command: 'create_catalogue', actorId: 7, substanceId: null, sourceType: 'catalogue', sourceId: null,
    expectedVersion: null, parentExpectedVersion: null, method: 'post', path: '/health-safety/substances', allowed: true };
const receipt = (key: string) => ({ accepted: true, actor_id: 7, command: 'create_catalogue', idempotency_key: key,
    root_type: 'catalogue', root_id: 9, source_type: 'catalogue', source_id: 9, parent_id: null, expected_version: null,
    parent_expected_version: null, version: 1, source_version_id: 13, changed: true });
afterEach(() => { cleanup(); vi.clearAllMocks(); });
describe('chemical exact submission and uncertainty', () => {
    it('requires the full immutable actor/source/revision receipt and refuses unrelated or embellished success', () => {
        const key = '94efabfa-9336-4933-a974-63ec251bc874'; const accepted = receipt(key);
        expect(confirmsChemicalReceipt(accepted, create, key)).toBe(true);
        for (const patch of [{ actor_id: 8 }, { source_type: 'exposure' }, { root_id: 10 }, { parent_id: 9 }, { version: 2 },
            { expected_version: 0 }, { changed: false }, { changed: false, source_version_id: null }, { source_version_id: null }, { replayed: true }]) expect(confirmsChemicalReceipt({ ...accepted, ...patch }, create, key)).toBe(false);
        const storage = { ...create, command: 'create_storage', substanceId: 9, sourceType: 'storage', parentExpectedVersion: 4 } as ChemicalCommandTarget;
        const own = { ...accepted, root_type: 'storage', root_id: 22, source_type: 'storage', source_id: 22, parent_id: 9, parent_expected_version: 4, command: 'create_storage' };
        expect(confirmsChemicalReceipt(own, storage, key)).toBe(true);
        expect(confirmsChemicalReceipt({ ...own, version: 5 }, storage, key)).toBe(false);
    });
    it('keeps the unknown created identity and exact UUID/body through a lost response and duplicate activation', async () => {
        let fail: (value: unknown) => void = () => {}; io.request.mockImplementationOnce(() => new Promise((_resolve, reject) => { fail = reject; }));
        const { result } = renderHook(() => useChemicalCommand(create, true));
        act(() => { result.current.submit({ name: 'Recorded chemical', physical_form: 'liquid' }); result.current.submit({ name: 'Second draft', physical_form: 'solid' }); });
        expect(io.request).toHaveBeenCalledTimes(1); const original = io.request.mock.calls[0][0];
        expect(result.current.canCheck).toBe(false); await act(async () => fail(new Error('Response lost')));
        expect(result.current.uncertain).toBe(true); expect(result.current.receipt).toBeNull();
        io.request.mockResolvedValueOnce({ data: { chemical_confirmation: receipt(original.data.idempotency_key), chemical_href: null } });
        act(() => result.current.retry()); await waitFor(() => expect(result.current.receipt?.root_id).toBe(9));
        expect(io.request.mock.calls[1][0].data).toEqual(original.data); expect(io.get).not.toHaveBeenCalled(); expect(result.current.href).toBeNull();
    });
    it('keeps selected sheet bytes and original revision when an empty recovery check cannot confirm acceptance', async () => {
        const target = { ...create, command: 'upload_sds', substanceId: 9, sourceType: 'sds', expectedVersion: 4,
            path: '/health-safety/substances/9/sds' } as ChemicalCommandTarget;
        io.request.mockRejectedValueOnce(new Error('Interrupted')); const file = new File(['retained-sheet'], 'sheet.pdf', { type: 'application/pdf' });
        const { result } = renderHook(() => useChemicalCommand(target, true));
        act(() => result.current.submit({ file, version: 'v2', issue_date: '2026-09-02', review_date: null }, true));
        await waitFor(() => expect(result.current.busy).toBe(false)); const original = io.request.mock.calls[0][0].data as FormData;
        io.get.mockResolvedValueOnce({ data: { chemical_confirmation: null, chemical_href: null } });
        await act(async () => result.current.check()); expect(result.current.uncertain).toBe(true); expect(result.current.locked).toBe(true);
        expect(io.get.mock.calls[0][0]).toBe(`/health-safety/substances/9/sds/recover/${original.get('idempotency_key')}`);
        io.request.mockRejectedValueOnce(new Error('Still interrupted')); act(() => result.current.retry()); await waitFor(() => expect(result.current.busy).toBe(false));
        const retried = io.request.mock.calls[1][0].data as FormData;
        expect(retried.get('idempotency_key')).toBe(original.get('idempotency_key')); expect(retried.get('expected_version')).toBe('4');
        expect(retried.get('file')).toBe(file); expect(retried.get('issue_date')).toBe('2026-09-02');
    });
    it('does not turn a failed receipt lookup into permission to replace an uncertain submission', async () => {
        const target = { ...create, command: 'update_catalogue', substanceId: 9, sourceId: 9, expectedVersion: 4,
            method: 'put', path: '/health-safety/substances/9' } as ChemicalCommandTarget;
        io.request.mockRejectedValueOnce(new Error('Write response lost'));
        const { result } = renderHook(() => useChemicalCommand(target, true));
        act(() => result.current.submit({ name: 'Retained original entries' }));
        await waitFor(() => expect(result.current.busy).toBe(false));
        const original = io.request.mock.calls[0][0].data;
        io.get.mockRejectedValueOnce({ response: { status: 422, data: { errors: { expected_version: ['Current revision changed.'] } } } });
        await act(async () => result.current.check());
        expect(result.current.canReviewRejected).toBe(false);
        expect(result.current.resetReviewedRejection()).toBe(false);
        expect(result.current.uncertain).toBe(true);
        io.request.mockRejectedValueOnce(new Error('Retry response lost'));
        act(() => result.current.retry());
        await waitFor(() => expect(result.current.busy).toBe(false));
        expect(io.request.mock.calls[1][0].data).toEqual(original);
    });
    it('withholds further submissions after current access denial and retains uncertainty', async () => {
        io.request.mockRejectedValueOnce({ response: { status: 403 } }); const { result } = renderHook(() => useChemicalCommand(create, true));
        act(() => result.current.submit({ name: 'Retained private choice', physical_form: 'solid' }));
        await waitFor(() => expect(result.current.withdrawn).toBe(true)); act(() => result.current.retry());
        expect(io.request).toHaveBeenCalledTimes(1); expect(result.current.uncertain).toBe(true); expect(result.current.receipt).toBeNull();
    });

    it('recovers an unknown storage identity with original parent revision after a current record refresh', async () => {
        const target = { ...create, command: 'create_storage', substanceId: 9, sourceType: 'storage', parentExpectedVersion: 4,
            path: '/health-safety/substances/9/storage-locations', canRecover: true } as ChemicalCommandTarget;
        io.request.mockRejectedValueOnce(new Error('Acceptance response lost'));
        const { result, rerender } = renderHook(({ current }) => useChemicalCommand(current, true), { initialProps: { current: target } });
        act(() => result.current.submit({ site_id: '3', location_description: 'Recorded cabinet', current_quantity: null }));
        await waitFor(() => expect(result.current.busy).toBe(false)); const original = io.request.mock.calls[0][0].data;
        rerender({ current: { ...target, allowed: false, parentExpectedVersion: 5 } });
        expect(result.current.canCheck).toBe(false); expect(result.current.canRetry).toBe(true);
        io.request.mockResolvedValueOnce({ data: { chemical_confirmation: { ...receipt(original.idempotency_key), command: 'create_storage', root_type: 'storage', root_id: 22,
            source_type: 'storage', source_id: 22, parent_id: 9, parent_expected_version: 4 }, chemical_href: '/health-safety/substances/9' } });
        act(() => result.current.retry()); await waitFor(() => expect(result.current.receipt?.source_id).toBe(22));
        expect(io.request.mock.calls[1][0].data).toEqual(original); expect(io.request.mock.calls[1][0].data.parent_expected_version).toBe(4);
        expect(result.current.href).toBe('/health-safety/substances/9');
    });
});
