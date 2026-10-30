import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
const io = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('axios', () => ({ default: { get: io.get, isAxiosError: (value: unknown) => !!value && typeof value === 'object' && 'response' in value } }));
import { useExposureChoices } from './use-exposure-choices';
const options = (name = 'Current choice') => ({ sites: [{ id: 3, name }], staff: { items: [{ id: 7, name }], next_cursor: 7 },
    receivers: { items: [{ id: 8, name }], next_cursor: null }, incidents: { access: 'restricted', items: null, page: null } });
const props = { actorId: 7, rootId: 22, siteId: '3', enabled: true };
const tick = async () => { await act(async () => { await vi.advanceTimersByTimeAsync(260); }); };
beforeEach(() => vi.useFakeTimers());
afterEach(() => { cleanup(); vi.useRealTimers(); io.get.mockReset(); });
it('withdraws old choices immediately on a Site change and ignores a late response for that older Site', async () => {
    let oldResult!: (value: unknown) => void; io.get.mockImplementationOnce(() => new Promise((resolve) => { oldResult = resolve; }));
    const { result, rerender } = renderHook((current) => useExposureChoices(current), { initialProps: props }); await tick();
    const originalSignal = io.get.mock.calls[0][1].signal;
    rerender({ ...props, siteId: '4' }); expect(originalSignal.aborted).toBe(true); expect(result.current.choices).toBeNull();
    await act(async () => oldResult({ data: options('STALE PRIVATE CHOICE') })); expect(result.current.choices).toBeNull();
    io.get.mockResolvedValueOnce({ data: { ...options('New Site choice'), sites: [{ id: 4, name: 'New Site choice' }] } }); await tick();
    expect(result.current.choices?.sites[0].name).toBe('New Site choice');
});
it('uses actual staff cursors and independently resets incident paging when its search changes', async () => {
    io.get.mockResolvedValue({ data: options() }); const { result } = renderHook(() => useExposureChoices(props)); await tick();
    act(() => result.current.moreStaff(7)); await tick(); expect(io.get.mock.calls.at(-1)![1].params.after_id).toBe(7);
    act(() => result.current.setSearch('New worker')); await tick(); expect(io.get.mock.calls.at(-1)![1].params).toMatchObject({ search: 'New worker', after_id: 0 });
    act(() => result.current.setIncidentPage(3)); await tick(); act(() => result.current.setIncidentSearch('INC-45')); await tick();
    expect(io.get.mock.calls.at(-1)![1].params).toMatchObject({ incident_page: 1, incident_search: 'INC-45' });
});
it('withholds cached choices when disabled and reports actual current access denial to the workspace', async () => {
    io.get.mockResolvedValueOnce({ data: options() }); const onWithdraw = vi.fn();
    const { result, rerender } = renderHook((current) => useExposureChoices(current), { initialProps: { ...props, onWithdraw } }); await tick();
    expect(result.current.choices?.staff.items).toHaveLength(1);
    rerender({ ...props, enabled: false, onWithdraw }); expect(result.current.choices).toBeNull();
    io.get.mockRejectedValueOnce({ response: { status: 403 } }); rerender({ ...props, onWithdraw }); await tick();
    expect(result.current.choices).toBeNull(); expect(result.current.withdrawn).toBe(true); expect(onWithdraw).toHaveBeenCalledOnce();
});
it('keeps capture choices separate from saved-record choices and never accepts a substituted chemical', async () => {
    const captureProps = { actorId: 7, substanceId: 9, siteId: '3', enabled: true };
    io.get.mockResolvedValueOnce({ data: { ...options(), substance: { id: 10, name: 'Other chemical' }, can: { create: true } } });
    const { result } = renderHook(() => useExposureChoices(captureProps)); await tick();
    expect(io.get).toHaveBeenCalledWith('/health-safety/chemical-exposures/capture-options', expect.objectContaining({ params: expect.objectContaining({ substance_id: 9 }) }));
    expect(result.current.choices).toBeNull(); expect(result.current.error).toContain('could not be confirmed');
    expect(io.get.mock.calls[0][0]).not.toContain('/22/command-options');
});
it('withdraws cached eligible people when the source revision changes within the same Site and actor', async () => {
    io.get.mockResolvedValueOnce({ data: options('Earlier source choices') });
    const initial = { ...props, sourceRevision: 3 };
    const { result, rerender } = renderHook((current) => useExposureChoices(current), { initialProps: initial }); await tick();
    expect(result.current.choices?.staff.items[0].name).toBe('Earlier source choices');
    let resolve!: (value: unknown) => void; io.get.mockImplementationOnce(() => new Promise((done) => { resolve = done; }));
    rerender({ ...initial, sourceRevision: 4 }); expect(result.current.choices).toBeNull(); expect(result.current.loading).toBe(true);
    await tick(); expect(io.get).toHaveBeenCalledTimes(2);
    await act(async () => { resolve({ data: options('Current independently checked choices') }); });
    expect(result.current.choices?.staff.items[0].name).toBe('Current independently checked choices');
    expect(result.current.loading).toBe(false);
});
