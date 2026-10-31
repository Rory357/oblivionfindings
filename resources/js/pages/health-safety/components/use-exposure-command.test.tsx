import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
const io = vi.hoisted(() => ({ request: vi.fn(), get: vi.fn() }));
vi.mock('axios', () => ({ default: { request: io.request, get: io.get, isAxiosError: (value: unknown) => !!value && typeof value === 'object' && 'response' in value } }));
vi.mock('@/hooks/use-safety-workspace-drafts', () => ({ useSafetyDraftState: vi.fn() }));
import { confirmsExposureReceipt, useExposureCommand, type ExposureCommandTarget } from './use-exposure-command';
const create: ExposureCommandTarget = { actorId: 7, command: 'create_exposure', rootId: null, substanceId: 9, sourceType: 'exposure', sourceId: null,
    expectedVersion: null, method: 'post', path: '/health-safety/substances/9/exposures', allowed: true, canRecover: true };
const receipt = (key: string) => ({ accepted: true, actor_id: 7, command: 'create_exposure', idempotency_key: key,
    root_type: 'exposure', root_id: 22, source_type: 'exposure', source_id: 22, expected_version: null, version: 1, source_version_id: 13, changed: true });
const correction: ExposureCommandTarget = { ...create, command: 'correct_exposure', rootId: 22, sourceId: 22, expectedVersion: 4,
    method: 'put', path: '/health-safety/chemical-exposures/22' };
afterEach(() => { cleanup(); vi.clearAllMocks(); });
it('requires the exact private actor, root, child, command and revision receipt including correction no-op rules', () => {
    const key = '94efabfa-9336-4933-a974-63ec251bc874'; const accepted = receipt(key);
    expect(confirmsExposureReceipt(accepted, create, key)).toBe(true);
    for (const patch of [{ actor_id: 8 }, { source_type: 'catalogue' }, { root_id: 23 }, { source_id: 23 }, { version: 2 },
        { expected_version: 0 }, { changed: false, source_version_id: null }, { source_version_id: null }, { replayed: true }]) {
        expect(confirmsExposureReceipt({ ...accepted, ...patch }, create, key)).toBe(false);
    }
    const unchanged = { ...accepted, command: 'correct_exposure', expected_version: 4, version: 4, changed: false, source_version_id: null };
    expect(confirmsExposureReceipt(unchanged, correction, key)).toBe(true);
    expect(confirmsExposureReceipt({ ...unchanged, source_version_id: 13 }, correction, key)).toBe(false);
    const complete: ExposureCommandTarget = { ...correction, command: 'complete_followup', sourceType: 'followup', sourceId: 31 };
    const completed = { ...accepted, command: 'complete_followup', source_type: 'followup', source_id: 31, expected_version: 4, version: 5 };
    expect(confirmsExposureReceipt(completed, complete, key)).toBe(true);
    expect(confirmsExposureReceipt(completed, { ...complete, rootId: null }, key)).toBe(false);
    expect(confirmsExposureReceipt(completed, { ...complete, sourceId: null }, key)).toBe(false);
    expect(confirmsExposureReceipt({ ...completed, source_id: 32 }, complete, key)).toBe(false);
    expect(confirmsExposureReceipt({ ...completed, changed: false, source_version_id: null, version: 4 }, complete, key)).toBe(false);
});
it('retains a create-only original submission through duplicate activation and lost response without inferring saved read', async () => {
    let fail: (value: unknown) => void = () => {}; io.request.mockImplementationOnce(() => new Promise((_resolve, reject) => { fail = reject; }));
    const { result } = renderHook(() => useExposureCommand(create, true));
    act(() => { result.current.submit({ circumstances: 'Retained private occurrence', user_id: 7 }); result.current.submit({ circumstances: 'Second attempt' }); });
    expect(io.request).toHaveBeenCalledTimes(1); const original = io.request.mock.calls[0][0];
    await act(async () => fail(new Error('Lost response')));
    expect(result.current.uncertain).toBe(true); expect(result.current.receipt).toBeNull();
    io.get.mockResolvedValueOnce({ data: { chemical_exposure_confirmation: receipt(original.data.idempotency_key), chemical_exposure_href: null } });
    await act(async () => result.current.check());
    expect(io.get.mock.calls[0][0]).toBe('/health-safety/chemical-exposures/capture-confirmation');
    expect(io.get.mock.calls[0][1].params).toEqual({ receipt_key: original.data.idempotency_key, substance_id: 9 });
    expect(result.current.receipt?.root_id).toBe(22); expect(result.current.href).toBeNull();
    expect(io.request).toHaveBeenCalledTimes(1); expect(result.current.canRetry).toBe(false);
});
it('retries original selected evidence bytes and reviewed revision after refreshed facts cannot grant a new write', async () => {
    const target: ExposureCommandTarget = { ...correction, command: 'upload_evidence', sourceType: 'evidence', sourceId: null, method: 'post',
        path: '/health-safety/chemical-exposures/22/evidence' };
    const file = new File(['private retained bytes'], 'synthetic.txt', { type: 'text/plain' }); io.request.mockRejectedValueOnce(new Error('Lost response'));
    const { result, rerender } = renderHook(({ current }) => useExposureCommand(current, true), { initialProps: { current: target } });
    act(() => result.current.submit({ file, description: 'Retained description' }, true)); await waitFor(() => expect(result.current.busy).toBe(false));
    const original = io.request.mock.calls[0][0].data as FormData;
    rerender({ current: { ...target, expectedVersion: 5, allowed: false } });
    io.get.mockResolvedValueOnce({ data: { chemical_exposure_confirmation: null } }); await act(async () => result.current.check());
    expect(result.current.uncertain).toBe(true); expect(result.current.canRetry).toBe(true); expect(result.current.locked).toBe(true);
    io.request.mockResolvedValueOnce({ data: { chemical_exposure_confirmation: { ...receipt(String(original.get('idempotency_key'))),
        command: 'upload_evidence', source_type: 'evidence', source_id: 32, expected_version: 4, version: 5 }, chemical_exposure_href: '/health-safety/chemical-exposures/22' } });
    act(() => result.current.retry()); await waitFor(() => expect(result.current.receipt?.source_id).toBe(32));
    const retried = io.request.mock.calls[1][0].data as FormData;
    expect(retried.get('idempotency_key')).toBe(original.get('idempotency_key')); expect(retried.get('expected_version')).toBe('4');
    expect(retried.get('description')).toBe('Retained description');
    const bytes = retried.get('file') as File; expect(bytes.name).toBe('synthetic.txt'); expect(bytes.size).toBe(file.size);
    const retainedText = await new Promise<string>((resolve, reject) => { const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result)); reader.onerror = () => reject(reader.error); reader.readAsText(bytes); });
    expect(retainedText).toBe('private retained bytes'); expect(result.current.href).toBe('/health-safety/chemical-exposures/22');
});
it('does not interpret a failed confirmation lookup as a rejected write or permission to replace its UUID', async () => {
    io.request.mockRejectedValueOnce(new Error('Write response lost')); const { result } = renderHook(() => useExposureCommand(correction, true));
    act(() => result.current.submit({ circumstances: 'Original facts', reason: 'Correction reason' })); await waitFor(() => expect(result.current.busy).toBe(false));
    const original = io.request.mock.calls[0][0].data;
    io.get.mockRejectedValueOnce({ response: { status: 422, data: { errors: { expected_version: ['Current facts changed'] } } } });
    await act(async () => result.current.check()); expect(result.current.canReviewRejected).toBe(false); expect(result.current.resetReviewedRejection()).toBe(false);
    io.request.mockRejectedValueOnce(new Error('Retry response lost')); act(() => result.current.retry()); await waitFor(() => expect(result.current.busy).toBe(false));
    expect(io.request.mock.calls[1][0].data).toEqual(original); expect(result.current.uncertain).toBe(true);
});
it('permits explicit reviewed rejection reset only for a definite command validation response and never a UUID collision', async () => {
    io.request.mockRejectedValueOnce({ response: { status: 422, data: { errors: { expected_version: ['Review current facts'] } } } });
    const { result } = renderHook(() => useExposureCommand(correction, true));
    act(() => result.current.submit({ reason: 'Recorded reason' })); await waitFor(() => expect(result.current.busy).toBe(false));
    expect(result.current.canEditRejected).toBe(false); expect(result.current.canReviewRejected).toBe(true);
    act(() => expect(result.current.resetReviewedRejection()).toBe(true));
    io.request.mockRejectedValueOnce({ response: { status: 422, data: { errors: { idempotency_key: ['UUID unavailable'] } } } });
    act(() => result.current.submit({ reason: 'Reviewed reason' })); await waitFor(() => expect(result.current.busy).toBe(false));
    expect(result.current.canReviewRejected).toBe(false); expect(result.current.resetReviewedRejection()).toBe(false);
});
it('withdraws new writes and recovery on current access denial and refuses substituted readable URLs', async () => {
    io.request.mockRejectedValueOnce({ response: { status: 403 } }); const { result } = renderHook(() => useExposureCommand(create, true));
    act(() => result.current.submit({ circumstances: 'Retained private report' })); await waitFor(() => expect(result.current.withdrawn).toBe(true));
    act(() => { result.current.retry(); void result.current.check(); }); expect(io.request).toHaveBeenCalledTimes(1); expect(io.get).not.toHaveBeenCalled();
    expect(result.current.uncertain).toBe(true); expect(result.current.canRetry).toBe(false);
    const readable = renderHook(() => useExposureCommand(create, true));
    io.request.mockImplementationOnce(async (request) => ({ data: { chemical_exposure_confirmation: receipt(request.data.idempotency_key),
        chemical_exposure_href: '/health-safety/chemical-exposures/23?action=correct' } }));
    act(() => readable.result.current.submit({ circumstances: 'Another report' })); await waitFor(() => expect(readable.result.current.receipt).not.toBeNull());
    expect(readable.result.current.href).toBeNull();
});
it('confirms transfer only for the exact existing child and an advancing changed receipt', () => {
    const key = crypto.randomUUID(); const target: ExposureCommandTarget = { ...correction, command: 'reassign_followup', sourceType: 'followup', sourceId: 31,
        method: 'put', path: '/health-safety/chemical-exposures/22/followups/31/reassign' };
    const accepted = { ...receipt(key), command: 'reassign_followup', source_type: 'followup', source_id: 31, expected_version: 4, version: 5 };
    expect(confirmsExposureReceipt(accepted, target, key)).toBe(true);
    expect(confirmsExposureReceipt({ ...accepted, source_id: 32 }, target, key)).toBe(false);
    expect(confirmsExposureReceipt(accepted, { ...target, sourceId: null }, key)).toBe(false);
    expect(confirmsExposureReceipt({ ...accepted, changed: false, source_version_id: null, version: 4 }, target, key)).toBe(false);
});
