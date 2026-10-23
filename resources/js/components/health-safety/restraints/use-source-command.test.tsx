import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    confirmsSourceReceipt,
    useSourceCommand,
    type CommandContext,
} from './use-source-command';

type Submission = {
    method: string;
    href: string;
    values: Record<string, unknown>;
    options: {
        onSuccess: (page: { props: Record<string, unknown> }) => void;
        onError: () => void;
        onCancel: () => void;
        onFinish: () => void;
    };
};
const io = vi.hoisted(() => ({
    get: vi.fn(),
    reset: vi.fn(),
    clearErrors: vi.fn(),
    draft: vi.fn(),
    submissions: [] as Submission[],
    processing: false,
    errors: {} as Record<string, string>,
}));
vi.mock('axios', () => ({
    default: {
        get: io.get,
        isAxiosError: (error: unknown) =>
            !!error && typeof error === 'object' && 'isAxiosError' in error,
    },
}));
vi.mock('@/hooks/use-safety-workspace-drafts', async () => {
    const React = await import('react');
    return {
        useSafetyDraftState: io.draft,
        useSafetyForm: (initial: Record<string, unknown>) => {
            const [data, setData] = React.useState(initial);
            const transform = React.useRef(
                (value: Record<string, unknown>) => value,
            );
            return {
                data,
                setData,
                errors: io.errors,
                processing: io.processing,
                isDirty: JSON.stringify(data) !== JSON.stringify(initial),
                transform: (callback: typeof transform.current) => {
                    transform.current = callback;
                },
                submit: (
                    method: string,
                    href: string,
                    options: Submission['options'],
                ) => {
                    io.submissions.push({
                        method,
                        href,
                        options,
                        values: transform.current(data),
                    });
                },
                clearErrors: io.clearErrors,
                reset: () => {
                    io.reset();
                    setData(initial);
                },
            };
        },
    };
});
const eventContext: CommandContext = { id: 9, version: 4, allowed: true };
function options(
    context: CommandContext | null = eventContext,
    overrides = {},
) {
    return {
        initial: { notes: '' },
        kind: 'event' as const,
        command: 'review',
        context,
        checkHref: '/health-safety/restraints/events/9',
        receiptKey: 'restraint_confirmation' as const,
        readContext: (value: unknown) =>
            (value as { current?: CommandContext }).current ?? null,
        ...overrides,
    };
}
function eventReceipt(key: string, overrides: Record<string, unknown> = {}) {
    return {
        accepted: true,
        event_id: 9,
        version: 5,
        source_revision: 5,
        recorded_at: '2026-10-12T01:30:00Z',
        command: 'review',
        idempotency_key: key,
        replayed: false,
        ...overrides,
    };
}
beforeEach(() => {
    vi.clearAllMocks();
    io.submissions = [];
    io.processing = false;
    io.errors = {};
    let sequence = 0;
    vi.spyOn(crypto, 'randomUUID').mockImplementation(
        () => `00000000-0000-4000-8000-${String(++sequence).padStart(12, '0')}`,
    );
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('keeps draft facts and the same command identity after an uncertain submission, then recovers only its exact receipt', async () => {
    const { result } = renderHook(() => useSourceCommand(options()));
    act(() =>
        result.current.change(
            'notes',
            'Checked the person and called the nurse.',
        ),
    );
    const key = result.current.guard.key;
    act(() =>
        result.current.save('post', '/source/review', result.current.form.data),
    );
    expect(io.submissions[0].values).toEqual({
        notes: 'Checked the person and called the nurse.',
        idempotency_key: key,
        expected_version: 4,
    });
    act(() => io.submissions[0].options.onFinish());
    expect(result.current.failure).toContain('could not be confirmed');
    expect(result.current.form.data.notes).toBe(
        'Checked the person and called the nurse.',
    );
    expect(result.current.guard.key).toBe(key);
    io.get.mockResolvedValueOnce({
        data: {
            current: eventContext,
            restraint_confirmation: eventReceipt(key),
            restraint_href:
                '/health-safety/restraints/events/9?section=review&action=remove-evidence&receipt_key=private',
        },
    });
    await act(async () => result.current.checkCurrent());
    expect(io.get).toHaveBeenCalledWith(
        '/health-safety/restraints/events/9',
        expect.objectContaining({
            params: { receipt_key: key, include_history: 0 },
        }),
    );
    expect(result.current.receipt?.accepted).toBe(true);
    expect(result.current.confirmedHref).toBe(
        '/health-safety/restraints/events/9?section=review',
    );
    expect(io.reset).toHaveBeenCalledTimes(1);
    act(() =>
        result.current.save('post', '/source/review', result.current.form.data),
    );
    expect(io.submissions).toHaveLength(1);
});

it('retains facts and changes command identity only when the operator changes the draft', () => {
    const { result } = renderHook(() => useSourceCommand(options()));
    act(() => result.current.change('notes', 'Entered facts.'));
    const key = result.current.guard.key;
    act(() =>
        result.current.save('post', '/source/review', result.current.form.data),
    );
    act(() => io.submissions[0].options.onCancel());
    act(() => result.current.change('notes', 'Entered facts.'));
    expect(result.current.guard.key).toBe(key);
    act(() =>
        result.current.save('post', '/source/review', result.current.form.data),
    );
    expect(io.submissions[1].values.idempotency_key).toBe(key);
    act(() => result.current.change('notes', 'Corrected operator facts.'));
    expect(result.current.guard.key).not.toBe(key);
    expect(result.current.failure).toBeNull();
});

it('requires explicit review of changed source facts while preserving entered notes', async () => {
    const { result } = renderHook(() => useSourceCommand(options()));
    act(() => result.current.change('notes', 'Retained findings.'));
    const key = result.current.guard.key;
    io.get.mockResolvedValueOnce({
        data: { current: { ...eventContext, version: 6 } },
    });
    await act(async () => result.current.checkCurrent());
    expect(result.current.stale).toBe(true);
    act(() =>
        result.current.save('post', '/source/review', result.current.form.data),
    );
    expect(io.submissions).toHaveLength(0);
    act(() => result.current.useCurrent());
    expect(result.current.guard.version).toBe(6);
    expect(result.current.guard.key).not.toBe(key);
    expect(result.current.form.data.notes).toBe('Retained findings.');
    act(() =>
        result.current.save('post', '/source/review', result.current.form.data),
    );
    expect(io.submissions[0].values.expected_version).toBe(6);
});

it('does not reuse a command for a different source with the same lock version', () => {
    const { result, rerender } = renderHook(
        ({ context }) => useSourceCommand(options(context)),
        { initialProps: { context: eventContext } },
    );
    act(() => result.current.change('notes', 'Person nine findings.'));
    rerender({ context: { ...eventContext, id: 10 } });
    expect(result.current.stale).toBe(true);
    act(() =>
        result.current.save(
            'post',
            '/health-safety/restraints/events/10/review',
            result.current.form.data,
        ),
    );
    expect(io.submissions).toHaveLength(0);
    expect(result.current.form.data.notes).toBe('Person nine findings.');
});

it.each([401, 403, 404])(
    'withholds a save after current source access returns %s without discarding the draft',
    async (status) => {
        const { result } = renderHook(() => useSourceCommand(options()));
        act(() => result.current.change('notes', 'Private draft.'));
        io.get.mockRejectedValueOnce({
            isAxiosError: true,
            response: { status },
        });
        await act(async () => result.current.checkCurrent());
        expect(result.current.allowed).toBe(false);
        expect(result.current.checkFailed).toBe(true);
        expect(result.current.form.data.notes).toBe('Private draft.');
        act(() =>
            result.current.save(
                'post',
                '/source/review',
                result.current.form.data,
            ),
        );
        expect(io.submissions).toHaveLength(0);
    },
);

it('requires a successful retry after a failed current check even when an old capability remains', async () => {
    const { result } = renderHook(() => useSourceCommand(options()));
    io.get.mockRejectedValueOnce(new Error('Connection lost'));
    await act(async () => result.current.checkCurrent());
    act(() => result.current.save('post', '/source/review', {}));
    expect(io.submissions).toHaveLength(0);
    io.get.mockResolvedValueOnce({ data: { current: eventContext } });
    await act(async () => result.current.checkCurrent());
    act(() => result.current.save('post', '/source/review', {}));
    expect(io.submissions).toHaveLength(1);
});

it('ignores a late current-check response after the command unmounts', async () => {
    let resolve!: (value: unknown) => void;
    io.get.mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const { result, unmount } = renderHook(() => useSourceCommand(options()));
    const key = result.current.guard.key;
    let pending!: Promise<void>;
    act(() => {
        pending = result.current.checkCurrent();
    });
    const signal = io.get.mock.calls[0][1].signal as AbortSignal;
    unmount();
    expect(signal.aborted).toBe(true);
    await act(async () => {
        resolve({
            data: {
                current: eventContext,
                restraint_confirmation: eventReceipt(key),
            },
        });
        await pending;
    });
    expect(io.reset).not.toHaveBeenCalled();
});

it('withholds draft edits and another save while the form is processing', () => {
    io.processing = true;
    const { result } = renderHook(() => useSourceCommand(options()));
    const key = result.current.guard.key;
    act(() => {
        result.current.change('notes', 'Accidental new draft.');
        result.current.save('post', '/source/review', {});
    });
    expect(result.current.form.data.notes).toBe('');
    expect(result.current.guard.key).toBe(key);
    expect(io.submissions).toHaveLength(0);
});

it('requires explicit review when approval policy or candidate content changes, and confirms the selected current candidate', async () => {
    const context = {
        id: 8,
        version: 4,
        allowed: true,
        policyRevision: 2,
        candidateId: 21,
        candidateDigest: 'a'.repeat(64),
    };
    const settings = {
        kind: 'plan' as const,
        command: 'approval',
        receiptKey: 'behaviour_support_plan_confirmation' as const,
        childKey: 'candidate_version_id',
        childId: 21,
        checkHref: '/health-safety/restraints/plans/8/approval',
    };
    const { result } = renderHook(() =>
        useSourceCommand(options(context, settings)),
    );
    act(() => result.current.change('notes', 'Retained decision basis.'));
    const next = {
        ...context,
        policyRevision: 3,
        candidateId: 22,
        candidateDigest: 'b'.repeat(64),
    };
    io.get.mockResolvedValueOnce({ data: { current: next } });
    await act(async () => result.current.checkCurrent());
    expect(result.current.stale).toBe(true);
    act(() => result.current.save('post', '/source/approvals', {}));
    expect(io.submissions).toHaveLength(0);
    act(() => result.current.useCurrent());
    act(() =>
        result.current.save('post', '/source/approvals', {
            notes: result.current.form.data.notes,
        }),
    );
    const submission = io.submissions[0];
    expect(submission.values.expected_policy_revision).toBe(3);
    const proof = {
        accepted: true,
        source_type: 'plan',
        plan_id: 8,
        command: 'approval',
        version: 5,
        receipt_id: 91,
        replayed: false,
        idempotency_key: result.current.guard.key,
    };
    act(() =>
        submission.options.onSuccess({
            props: {
                behaviour_support_plan_confirmation: {
                    ...proof,
                    candidate_version_id: 21,
                },
            },
        }),
    );
    expect(result.current.receipt).toBeNull();
    expect(result.current.form.data.notes).toBe('Retained decision basis.');
    io.get.mockResolvedValueOnce({
        data: {
            current: { ...next, version: 5 },
            behaviour_support_plan_confirmation: {
                ...proof,
                candidate_version_id: 22,
            },
            plan_href: '/health-safety/restraints/plans/8/approval',
        },
    });
    await act(async () => result.current.checkCurrent());
    expect(result.current.receipt?.candidate_version_id).toBe(22);
    expect(result.current.confirmedHref).toBe(
        '/health-safety/restraints/plans/8/approval',
    );
});

it.each([
    '/health-safety/restraints/events/90',
    '/health-safety/restraints/events/9/reviewevil',
    '/health-safety/restraints/events/9/approval',
    'https://outside.example/health-safety/restraints/events/9',
    '/health-safety/restraints/events/9#private',
])('never turns a valid receipt into a confirmation link to %s', (href) => {
    const { result } = renderHook(() => useSourceCommand(options()));
    act(() => result.current.save('post', '/source/review', {}));
    act(() =>
        io.submissions[0].options.onSuccess({
            props: {
                restraint_confirmation: eventReceipt(result.current.guard.key),
                restraint_href: href,
            },
        }),
    );
    expect(result.current.receipt?.accepted).toBe(true);
    expect(result.current.confirmedHref).toBeNull();
});

it.each(['correct_observation', 'correct_debrief'])(
    'confirms the immutable replacement and exact predecessor for %s rather than expecting the old child id to be the new fact',
    (command) => {
        const key = '00000000-0000-4000-8000-000000000001';
        const childKey =
            command === 'correct_observation' ? 'observation_id' : 'debrief_id';
        const expected = {
            kind: 'event' as const,
            id: 9,
            version: 4,
            key,
            command,
            childKey: 'supersedes_id',
            childId: 7,
        };
        const proof = eventReceipt(key, {
            command,
            supersedes_id: 7,
            [childKey]: 8,
        });
        expect(confirmsSourceReceipt(proof, expected)).toBe(true);
        expect(
            confirmsSourceReceipt({ ...proof, supersedes_id: 6 }, expected),
        ).toBe(false);
        expect(
            confirmsSourceReceipt({ ...proof, [childKey]: 7 }, expected),
        ).toBe(false);
        expect(
            confirmsSourceReceipt({ ...proof, [childKey]: null }, expected),
        ).toBe(false);
    },
);

it.each([
    { accepted: false },
    { event_id: 10 },
    { event_id: '9' },
    { command: 'correct' },
    { idempotency_key: 'another-command' },
    { version: 4 },
    { source_revision: null },
    { recorded_at: 'not a recorded instant' },
    { replayed: undefined },
    { observation_id: 8 },
])(
    'does not clear or confirm work using foreign or incomplete command evidence: %j',
    (changes) => {
        const key = '00000000-0000-4000-8000-000000000001';
        expect(
            confirmsSourceReceipt(
                eventReceipt(key, { observation_id: 7, ...changes }),
                {
                    kind: 'event',
                    id: 9,
                    version: 4,
                    key,
                    command: 'review',
                    childKey: 'observation_id',
                    childId: 7,
                },
            ),
        ).toBe(false);
    },
);
