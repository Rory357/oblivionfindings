import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ppeMultipart, usePpeCommand } from './_use-command';
import { usePpeCatalogueCommand } from './_use-catalogue-command';
import type { PpeRecord } from './_workspace-types';
import type { CatalogueRow } from './_catalogue-state';

const io = vi.hoisted(() => ({ get: vi.fn(), request: vi.fn(), draft: vi.fn(), reset: vi.fn() }));
vi.mock('axios', () => ({ default: { get: io.get, request: io.request, isAxiosError: (value: unknown) => !!value && typeof value === 'object' && 'isAxiosError' in value } }));
vi.mock('@/hooks/use-safety-workspace-drafts', async () => {
    const React = await import('react');
    return { useSafetyDraftState: io.draft, useSafetyForm: (initial: Record<string, unknown>) => {
        const [data, setData] = React.useState(initial); const [errors, setError] = React.useState<Record<string, string>>({});
        return { data, setData, errors, setError, processing: false, isDirty: JSON.stringify(initial) !== JSON.stringify(data),
            clearErrors: () => setError({}), reset: () => { io.reset(); setData(initial); } };
    } };
});
const record = (version = 4, can = true) => ({ kind: 'inventory', detail: { id: 9, status: 'available', attachments: [],
    can: { manage: can, inspect: can, allocate: can }, command_context: { root_kind: 'inventory', root_id: 9, source_kind: 'inventory', source_id: 9,
        site_id: 4, user_id: null, expected_version: version, source_schema_version: 1 } } }) as unknown as PpeRecord;
const receipt = (key: string, patch = {}) => ({ accepted: true, actor_id: 12, command: 'update_inventory', idempotency_key: key,
    root_type: 'inventory', root_id: 9, source_type: 'inventory', source_id: 9, expected_version: 4, version: 5, source_version_id: 22, ...patch });
const options = { sites: [{ id: 4, name: 'Site' }], types: [{ id: 3, name: 'Type' }], can: { create: true, catalogue_manage: true } };
beforeEach(() => { vi.clearAllMocks(); });
afterEach(() => { cleanup(); });

it('retains exact uncertain intent and key until a proven accepted response is recovered', async () => {
    const { result } = renderHook(() => usePpeCommand({ initial: { notes: '' }, record: record(), command: 'edit', actorId: 12 }));
    act(() => result.current.change('notes', 'Retained equipment correction'));
    const key = result.current.guard.key;
    io.request.mockRejectedValueOnce(new Error('connection interrupted'));
    await act(async () => result.current.save('put', '/health-safety/ppe/inventory/9', { notes: result.current.form.data.notes }));
    expect(result.current.unresolved).toBe(true);
    act(() => result.current.change('notes', 'Different intended request'));
    expect(result.current.form.data.notes).toBe('Retained equipment correction'); expect(result.current.guard.key).toBe(key);
    await act(async () => result.current.save('put', '/health-safety/ppe/inventory/9', {})); expect(io.request).toHaveBeenCalledTimes(1);
    io.get.mockResolvedValueOnce({ data: { ppe_confirmation: receipt(key) } });
    await act(async () => result.current.checkCurrent());
    expect(result.current.receipt?.source_version_id).toBe(22); expect(result.current.unresolved).toBe(false); expect(io.reset).toHaveBeenCalledTimes(1);
});
it('requires explicit current-version review before saving retained entries against a changed record', async () => {
    const { result } = renderHook(() => usePpeCommand({ initial: { brand: '' }, record: record(), command: 'edit', actorId: 12 }));
    act(() => result.current.change('brand', 'Recorded brand')); const original = result.current.guard.key;
    io.get.mockResolvedValueOnce({ data: { ...record(6), ppe_confirmation: null } }); await act(async () => result.current.checkCurrent());
    expect(result.current.stale).toBe(true);
    await act(async () => result.current.save('put', '/health-safety/ppe/inventory/9', {})); expect(io.request).not.toHaveBeenCalled();
    act(() => result.current.useCurrent()); expect(result.current.guard.version).toBe(6); expect(result.current.guard.key).not.toBe(original);
    expect(result.current.form.data.brand).toBe('Recorded brand');
    io.request.mockResolvedValueOnce({ data: { ppe_confirmation: receipt(result.current.guard.key, { expected_version: 6, version: 7 }) } });
    await act(async () => result.current.save('put', '/health-safety/ppe/inventory/9', { brand: 'Recorded brand' }));
    expect(io.request.mock.calls[0][0].data.expected_version).toBe(6); expect(result.current.receipt?.version).toBe(7);
});
it.each(['malformed', 'denied', 'different_receipt'] as const)('blocks saving after a %s current check without discarding draft facts', async (kind) => {
    const { result } = renderHook(() => usePpeCommand({ initial: { notes: '' }, record: record(), command: 'edit', actorId: 12 }));
    act(() => result.current.change('notes', 'Retained factual note'));
    io.get.mockResolvedValueOnce({ data: kind === 'malformed' ? { kind: 'inventory', detail: null, ppe_confirmation: null } :
        { ...record(4, kind !== 'denied'), ppe_confirmation: kind === 'different_receipt' ? receipt(result.current.guard.key, { actor_id: 13 }) : null } });
    await act(async () => result.current.checkCurrent());
    expect(result.current.receipt).toBeNull(); expect(result.current.allowed && !result.current.checkFailed).toBe(false);
    expect(result.current.form.data.notes).toBe('Retained factual note');
    await act(async () => result.current.save('put', '/health-safety/ppe/inventory/9', {})); expect(io.request).not.toHaveBeenCalled();
});
it('does not confuse a validation rejection with confirmed success or rotate the uncertain command', async () => {
    const { result } = renderHook(() => usePpeCommand({ initial: { notes: '' }, record: record(), command: 'edit', actorId: 12 }));
    const key = result.current.guard.key;
    io.request.mockRejectedValueOnce({ isAxiosError: true, response: { status: 422, data: { errors: { expected_version: ['Record changed.'] } } } });
    await act(async () => result.current.save('put', '/health-safety/ppe/inventory/9', {}));
    expect(result.current.receipt).toBeNull(); expect(result.current.stale).toBe(true); expect(result.current.guard.key).toBe(key);
    io.get.mockResolvedValueOnce({ data: { ...record(5), ppe_confirmation: null } }); await act(async () => result.current.checkCurrent());
    expect(result.current.unresolved).toBe(false); expect(result.current.stale).toBe(true);
});
it('serializes the exact staged files and factual booleans without putting command keys in navigation', () => {
    const file = new File(['evidence'], 'inspection.pdf', { type: 'application/pdf' });
    const body = ppeMultipart({ documents: [{ file, kind: 'inspection_report', note: 'Recorded observation' }], fit_test_completed: false, expiry_date: null });
    expect(body.get('documents[0][file]')).toBe(file); expect(body.get('documents[0][note]')).toBe('Recorded observation');
    expect(body.get('fit_test_completed')).toBe('0'); expect(body.get('expiry_date')).toBe('');
});
it('recovers unknown new-stock identity from its accepted receipt before requesting fresh create choices', async () => {
    const { result } = renderHook(() => usePpeCatalogueCommand({ name: '' }, 'create_inventory', 12));
    io.get.mockResolvedValueOnce({ data: { ppe_confirmation: null } }).mockResolvedValueOnce({ data: options });
    await act(async () => result.current.checkCurrent()); expect(result.current.allowed).toBe(true);
    act(() => result.current.change('name', 'Recorded stock'));
    io.request.mockRejectedValueOnce(new Error('interrupted'));
    await act(async () => result.current.save({ ppe_type_id: 3, site_id: 4 }));
    expect(io.request.mock.calls[0][0].data).not.toHaveProperty('expected_version');
    const key = result.current.guard.key;
    io.get.mockResolvedValueOnce({ data: { ppe_confirmation: receipt(key, { command: 'create_inventory', expected_version: null, version: 1 }) } });
    await act(async () => result.current.checkCurrent());
    expect(result.current.receipt?.root_id).toBe(9); expect(io.get).toHaveBeenCalledTimes(3);
});
it('withholds create fields after malformed choices and keeps the retained input recoverable', async () => {
    const { result } = renderHook(() => usePpeCatalogueCommand({ name: '' }, 'create_type', 12));
    act(() => result.current.change('name', 'Retained catalogue name'));
    io.get.mockResolvedValueOnce({ data: { ppe_confirmation: null } }).mockResolvedValueOnce({ data: { ...options, can: null } });
    await act(async () => result.current.checkCurrent()); expect(result.current.allowed).toBe(false); expect(result.current.options).toBeNull();
    expect(result.current.form.data.name).toBe('Retained catalogue name');
    await act(async () => result.current.save({ name: 'Retained catalogue name' })); expect(io.request).not.toHaveBeenCalled();
    io.get.mockResolvedValueOnce({ data: { ppe_confirmation: null } }).mockResolvedValueOnce({ data: options });
    await act(async () => result.current.checkCurrent()); expect(result.current.allowed).toBe(true);
});
it('does not silently update a type revision when another person changes its catalogue facts', async () => {
    const type = { id: 9, name: 'Type', category: 'head', is_active: true, source_integrity: 'intact', can: { manage: true },
        command_context: { root_kind: 'type', root_id: 9, source_kind: 'type', source_id: 9, site_id: null, user_id: null, expected_version: 4, source_schema_version: 1 } } as CatalogueRow;
    const { result } = renderHook(() => usePpeCatalogueCommand({ name: 'Type' }, 'update_type', 12, type));
    act(() => result.current.change('name', 'Retained correction'));
    io.get.mockResolvedValueOnce({ data: { ppe_confirmation: null } }).mockResolvedValueOnce({ data: { can: { manage: true }, types: [{ ...type, name: 'Other correction', command_context: { ...type.command_context, expected_version: 6 } }] } });
    await act(async () => result.current.checkCurrent()); expect(result.current.currentType?.name).toBe('Other correction'); expect(result.current.stale).toBe(true);
    await act(async () => result.current.save({ name: 'Retained correction', category: 'head' })); expect(io.request).not.toHaveBeenCalled();
    act(() => result.current.useCurrent()); expect(result.current.guard.version).toBe(6); expect(result.current.form.data.name).toBe('Retained correction');
});
