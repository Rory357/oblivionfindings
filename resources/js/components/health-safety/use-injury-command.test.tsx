import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { InjuryRecord } from './injury-workspace-types';
import { injuryCommandContext, useInjuryCommand } from './use-injury-command';

type Submission = {
    values: Record<string, unknown>;
    options: { onSuccess: (page: { props: Record<string, unknown> }) => void;
        onError: () => void; onCancel: () => void; onFinish: () => void };
};
const io = vi.hoisted(() => ({ get: vi.fn(), reset: vi.fn(), clearErrors: vi.fn(),
    draft: vi.fn(), submissions: [] as Submission[] }));
vi.mock('axios', () => ({ default: { get: io.get,
    isAxiosError: (value: unknown) => !!value && typeof value === 'object' && 'isAxiosError' in value } }));
vi.mock('@/hooks/use-safety-workspace-drafts', async () => {
    const React = await import('react');
    return { useSafetyDraftState: io.draft, useSafetyForm: (initial: Record<string, unknown>) => {
        const [data, setData] = React.useState(initial);
        const transform = React.useRef((value: Record<string, unknown>) => value);
        return { data, setData, errors: {}, processing: false,
            isDirty: JSON.stringify(data) !== JSON.stringify(initial),
            transform: (callback: typeof transform.current) => { transform.current = callback; },
            submit: (_method: string, _href: string, options: Submission['options']) => {
                io.submissions.push({ values: transform.current(data), options });
            }, clearErrors: io.clearErrors, reset: () => { io.reset(); setData(initial); } };
    } };
});
const record = (version = 0) => ({
    id: 9, source_version: version, source_integrity: 'unknown', status: 'reported', medical_access: 'readable', recovery_integrity: 'valid',
    commands: { expected_version: version, transitions: ['under_treatment', 'closed'] },
    can: { update: true, transition: true, assign: true, review: true, recovery: true,
        upload_evidence: true, remove_evidence: true }, rtw_plans: [], attachments: [],
}) as unknown as InjuryRecord;
const receipt = (key: string, overrides = {}) => ({
    accepted: true, injury_id: 9, source_type: 'injury', source_id: 9, actor_id: 12,
    command: 'update', idempotency_key: key, expected_version: 0, version: 0,
    source_version_id: 3, changed: false, receipt_id: 5, replayed: true, ...overrides,
});
beforeEach(() => { vi.clearAllMocks(); io.submissions = []; });
afterEach(() => { cleanup(); vi.restoreAllMocks(); });

it('confirms a factual legacy zero-version no-op through the shared command recovery and safe source return', async () => {
    const { result } = renderHook(() => useInjuryCommand({ initial: { description: '' },
        detail: record(), command: 'edit', actorId: 12 }));
    act(() => result.current.change('description', 'Recorded injury facts'));
    const key = result.current.guard.key;
    act(() => result.current.save('put', '/health-safety/injuries/9', result.current.form.data));
    expect(io.submissions[0].values.expected_version).toBe(0);
    act(() => io.submissions[0].options.onFinish());
    expect(result.current.form.data.description).toBe('Recorded injury facts');
    expect(result.current.guard.key).toBe(key);
    io.get.mockResolvedValueOnce({ data: { detail: record(), injury_confirmation: receipt(key),
        injury_href: '/health-safety/injuries/9?section=overview&action=close&receipt_key=private&return_to=%2Ftasks%3Fassigned%3Dme' } });
    await act(async () => result.current.checkCurrent());
    expect(result.current.receipt?.changed).toBe(false);
    expect(result.current.confirmedHref).toBe('/health-safety/injuries/9?section=overview&return_to=%2Ftasks%3Fassigned%3Dme');
    expect(io.reset).toHaveBeenCalledTimes(1);
});

it('requires explicit review of a newer aggregate and preserves factual entries without replaying the old version', async () => {
    const { result } = renderHook(() => useInjuryCommand({ initial: { description: '' },
        detail: record(4), command: 'edit', actorId: 12 }));
    act(() => result.current.change('description', 'Retained correction'));
    const key = result.current.guard.key;
    io.get.mockResolvedValueOnce({ data: { detail: record(6) } });
    await act(async () => result.current.checkCurrent());
    expect(result.current.stale).toBe(true);
    act(() => result.current.save('put', '/health-safety/injuries/9', result.current.form.data));
    expect(io.submissions).toHaveLength(0);
    act(() => result.current.useCurrent());
    expect(result.current.guard.key).not.toBe(key);
    expect(result.current.form.data.description).toBe('Retained correction');
    act(() => result.current.save('put', '/health-safety/injuries/9', result.current.form.data));
    expect(io.submissions[0].values.expected_version).toBe(6);
});

it('does not accept another actor receipt or a withdrawn medical grant while retaining private draft notes', async () => {
    const { result } = renderHook(() => useInjuryCommand({ initial: { notes: '' },
        detail: record(), command: 'review', actorId: 12 }));
    act(() => result.current.change('notes', 'Private retained review'));
    const key = result.current.guard.key;
    io.get.mockResolvedValueOnce({ data: { detail: { ...record(), medical_access: 'restricted',
        rtw_plans: null, attachments: null }, injury_confirmation: receipt(key, { actor_id: 13, command: 'review' }) } });
    await act(async () => result.current.checkCurrent());
    expect(result.current.receipt).toBeNull();
    expect(result.current.allowed).toBe(false);
    expect(result.current.form.data.notes).toBe('Private retained review');
    act(() => result.current.save('post', '/health-safety/injuries/9/reviews', result.current.form.data));
    expect(io.submissions).toHaveLength(0);
});

it('recovers a capture-only unknown-ID receipt without a lasting record link or register grant', async () => {
    const { result } = renderHook(() => useInjuryCommand({ initial: { description: '' },
        detail: null, command: 'create', actorId: 12, captureAllowed: true }));
    act(() => result.current.change('description', 'Submitted factual injury'));
    const key = result.current.guard.key;
    act(() => result.current.save('post', '/health-safety/injuries', result.current.form.data));
    expect(io.submissions[0].values).not.toHaveProperty('expected_version');
    act(() => io.submissions[0].options.onCancel());
    io.get.mockResolvedValueOnce({ data: { capture: { can: { create: true, view_register: false, record_reported_details: true } },
        injury_confirmation: receipt(key, { command: 'create', expected_version: null, version: 1, changed: true }), injury_href: null } });
    await act(async () => result.current.checkCurrent());
    expect(result.current.receipt?.injury_id).toBe(9);
    expect(result.current.confirmedHref).toBeNull();
    expect(result.current.guard.key).toBe(key);
});

it('rejects malformed current context without calling array or capability methods on untrusted shapes', () => {
    for (const detail of [ { ...record(), source_version: '0' }, { ...record(), commands: { expected_version: 1, transitions: [] } },
        { ...record(), can: null }, { ...record(), rtw_plans: [null] }, { ...record(), attachments: [{}] },
        { ...record(), medical_access: 'assumed' }, { ...record(), source_integrity: 'assumed' } ])
        expect(injuryCommandContext({ detail }, 'edit')).toBeNull();
    expect(injuryCommandContext({ capture: { can: { create: 'true' } } }, 'create')).toBeNull();
    expect(injuryCommandContext({ capture: { can: { create: true } } }, 'create')).toBeNull();
    expect(injuryCommandContext({ capture: { can: { create: true, record_reported_details: 'true' } } }, 'create')).toBeNull();
    expect(injuryCommandContext({ capture: { can: { create: true, record_reported_details: false } } }, 'create')).toEqual({ id: 0, version: 0, allowed: false });
    expect(injuryCommandContext({ capture: { can: { create: true, record_reported_details: true } } }, 'create')).toEqual({ id: 0, version: 0, allowed: true });
    expect(injuryCommandContext({ detail: record() }, 'edit')).toEqual({ id: 9, version: 0, allowed: true });
});

it.each(['denied', 'malformed'] as const)('retains a new reported draft through a fresh %s capture response and only restores saving after a valid explicit capability check', async (outcome) => {
    const { result } = renderHook(() => useInjuryCommand({ initial: { notes: '' }, detail: null, command: 'create', actorId: 12, captureAllowed: true }));
    act(() => result.current.change('notes', 'New privately reported detail'));
    io.get.mockResolvedValueOnce({ data: { capture: { can: { create: outcome !== 'denied', record_reported_details: outcome === 'malformed' ? 'true' : false } } } });
    await act(async () => result.current.checkCurrent());
    expect(result.current.checkedResponse).toBeNull(); expect(result.current.form.data.notes).toBe('New privately reported detail');
    if (outcome === 'denied') expect(result.current.allowed).toBe(false); else expect(result.current.checkFailed).toBe(true);
    act(() => result.current.save('post', '/health-safety/injuries', result.current.form.data)); expect(io.submissions).toHaveLength(0);
    io.get.mockResolvedValueOnce({ data: { capture: { can: { create: true, record_reported_details: true } } } });
    await act(async () => result.current.checkCurrent());
    expect(result.current.allowed).toBe(true); expect(result.current.checkFailed).toBe(false);
    expect(result.current.form.data.notes).toBe('New privately reported detail');
});
