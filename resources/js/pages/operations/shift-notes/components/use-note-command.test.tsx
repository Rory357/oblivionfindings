import { act, renderHook } from '@testing-library/react';
import { createHash, webcrypto } from 'node:crypto';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import {
    type NoteExpectation,
    type NoteReceipt,
    type NoteValues,
    normalizedNoteValues,
    noteReceipt,
    noteValuesHash,
    useNoteCommand,
} from './use-note-command';
const { post, put, patch } = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ router: { post, put, patch } }));
const values: NoteValues = {
    type: 'shift_note',
    body: 'Kia ora — māia 🌿\nSecond line / care.',
    is_flagged: false,
    flagged_reason: null,
    is_private: true,
};
const hash = createHash('sha256').update(JSON.stringify(values)).digest('hex');
const expected: NoteExpectation = {
    action: 'create',
    actorId: 7,
    clientId: 4,
    shiftId: 11,
    values,
};
const receipt: NoteReceipt = {
    action: 'create',
    actor_id: 7,
    note_id: 9,
    shift_id: 11,
    client_id: 4,
    changed: true,
    values_hash: hash,
    is_flagged: false,
    is_private: true,
    edited_at: null,
    edited_by: null,
    reviewed_at: null,
    reviewed_by: null,
};
beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal('crypto', { subtle: webcrypto.subtle });
});
afterEach(() => vi.unstubAllGlobals());
it('hashes explicit persisted keys, Unicode, newlines, slash and null exactly', async () => {
    expect(await noteValuesHash({ ...values })).toBe(hash);
    expect(await noteValuesHash({ ...values, flagged_reason: '' })).not.toBe(
        hash,
    );
});
it('normalizes submitted Laravel edge characters while retaining internal whitespace', () => {
    expect(
        normalizedNoteValues({
            ...values,
            body: '\u0085\u200b\u{1d173}  A\u200b B\r\nC\u{e0020}\0',
            is_flagged: true,
            flagged_reason: '\u2800',
        }),
    ).toEqual({
        ...values,
        body: 'A\u200b B\r\nC',
        is_flagged: true,
        flagged_reason: null,
    });
});
it('confirms only the persisted actor, source, content, privacy and action', () => {
    expect(
        noteReceipt({ shift_note_result: receipt }, expected, hash).status,
    ).toBe('confirmed');
    for (const mutation of [
        { actor_id: 8 },
        { client_id: 99 },
        { shift_id: 99 },
        { values_hash: 'a'.repeat(64) },
        { is_private: false },
        { changed: false },
        { action: 'update' },
        { note_id: 0 },
    ])
        expect(
            noteReceipt(
                { shift_note_result: { ...receipt, ...mutation } },
                expected,
                hash,
            ).status,
        ).toBe('unknown');
    expect(noteReceipt({ success: 'Saved' }, expected, hash).status).toBe(
        'unknown',
    );
    expect(
        noteReceipt(
            { warning: 'Follow up', shift_note_result: receipt },
            expected,
            hash,
        ).status,
    ).toBe('unknown');
});
it('requires the exact existing note and edit provenance for updates', () => {
    const update = {
        ...receipt,
        action: 'update',
        edited_at: '2026-10-07T00:00:00Z',
        edited_by: 7,
    };
    const intent = { ...expected, action: 'update' as const, noteId: 9 };
    expect(
        noteReceipt({ shift_note_result: update }, intent, hash).status,
    ).toBe('confirmed');
    expect(
        noteReceipt(
            { shift_note_result: update },
            { ...intent, noteId: undefined },
            hash,
        ).status,
    ).toBe('unknown');
    expect(
        noteReceipt(
            { shift_note_result: { ...update, edited_by: 8 } },
            intent,
            hash,
        ).status,
    ).toBe('unknown');
    expect(
        noteReceipt(
            { shift_note_result: { ...update, note_id: 10 } },
            intent,
            hash,
        ).status,
    ).toBe('unknown');
});
it('allows a matching legacy review with no reviewer only when captured unchanged', () => {
    const reviewed = {
        ...receipt,
        action: 'review',
        changed: false,
        reviewed_at: '2026-10-06T02:00:00Z',
        reviewed_by: null,
    };
    const intent = {
        ...expected,
        action: 'review' as const,
        noteId: 9,
        priorReview: { at: reviewed.reviewed_at, by: null },
    };
    expect(
        noteReceipt({ shift_note_result: reviewed }, intent, hash).status,
    ).toBe('confirmed');
    expect(
        noteReceipt(
            { shift_note_result: reviewed },
            { ...intent, priorReview: undefined },
            hash,
        ).status,
    ).toBe('unknown');
    expect(
        noteReceipt(
            { shift_note_result: { ...reviewed, changed: true } },
            intent,
            hash,
        ).status,
    ).toBe('unknown');
    expect(
        noteReceipt(
            { shift_note_result: { ...reviewed, reviewed_by: 8 } },
            intent,
            hash,
        ).status,
    ).toBe('unknown');
});
it('locks before hashing so two clicks send only one write', async () => {
    const { result } = renderHook(() => useNoteCommand('actor7:new'));
    await act(async () => {
        await Promise.all([
            result.current.submit('post', '/notes', {}, expected),
            result.current.submit('post', '/notes', {}, expected),
        ]);
    });
    expect(post).toHaveBeenCalledOnce();
    expect(result.current.pending).toBe(true);
    act(() => {
        post.mock.calls[0][2].onSuccess({
            props: { flash: { shift_note_result: receipt } },
        });
        post.mock.calls[0][2].onFinish();
    });
    expect(result.current.outcome?.status).toBe('confirmed');
    await act(async () => {
        await result.current.submit('post', '/notes', {}, expected);
    });
    expect(post).toHaveBeenCalledOnce();
});
it.each(['missing', 'cancel', 'unfinished', 'throw'])(
    'holds an uncertain %s result and never replays',
    async (mode) => {
        if (mode === 'throw')
            post.mockImplementationOnce(() => {
                throw new Error('connection interrupted');
            });
        const { result } = renderHook(() => useNoteCommand('actor7:new'));
        await act(async () => {
            await result.current.submit('post', '/notes', {}, expected);
        });
        if (mode !== 'throw')
            act(() => {
                const callbacks = post.mock.calls[0][2];
                if (mode === 'missing')
                    callbacks.onSuccess({
                        props: { flash: { success: 'Saved' } },
                    });
                if (mode === 'cancel') callbacks.onCancel();
                callbacks.onFinish();
            });
        expect(result.current.outcome?.status).toBe('unknown');
        await act(async () => {
            await result.current.submit('post', '/notes', {}, expected);
        });
        expect(post).toHaveBeenCalledOnce();
    },
);
it('allows corrected validation to retry but not unknown error bags', async () => {
    const { result } = renderHook(() => useNoteCommand('actor7:new'));
    await act(async () => {
        await result.current.submit('post', '/notes', {}, expected);
    });
    act(() => {
        post.mock.calls[0][2].onError({ body: 'Write the note.' });
        post.mock.calls[0][2].onFinish();
    });
    expect(result.current.outcome?.status).toBe('rejected');
    await act(async () => {
        await result.current.submit('post', '/notes', {}, expected);
    });
    expect(post).toHaveBeenCalledTimes(2);
    act(() => {
        post.mock.calls[1][2].onError({ error: 'Connection lost' });
        post.mock.calls[1][2].onFinish();
    });
    expect(result.current.outcome?.status).toBe('unknown');
});
it('quarantines an A to B to A context change during an in-flight request', async () => {
    const { result, rerender } = renderHook(
        ({ context }) => useNoteCommand(context),
        { initialProps: { context: 'A' } },
    );
    await act(async () => {
        await result.current.submit('post', '/notes', {}, expected);
    });
    rerender({ context: 'B' });
    rerender({ context: 'A' });
    act(() => {
        post.mock.calls[0][2].onSuccess({
            props: { flash: { shift_note_result: receipt } },
        });
        post.mock.calls[0][2].onFinish();
    });
    expect(result.current.outcome?.status).toBe('unknown');
    await act(async () => {
        await result.current.submit('post', '/notes', {}, expected);
    });
    expect(post).toHaveBeenCalledOnce();
});
it('does not send a request if unmounted during hashing', async () => {
    let resolve!: (value: ArrayBuffer) => void;
    vi.stubGlobal('crypto', {
        subtle: {
            digest: () =>
                new Promise<ArrayBuffer>((done) => {
                    resolve = done;
                }),
        },
    });
    const { result, unmount } = renderHook(() => useNoteCommand('A'));
    let pending!: Promise<void>;
    act(() => {
        pending = result.current.submit('post', '/notes', {}, expected);
    });
    unmount();
    await act(async () => {
        resolve(new ArrayBuffer(32));
        await pending;
    });
    expect(post).not.toHaveBeenCalled();
});
it('sends nothing when secure hashing fails and preserves a retryable draft', async () => {
    vi.stubGlobal('crypto', {
        subtle: { digest: vi.fn().mockRejectedValue(new Error('unavailable')) },
    });
    const { result } = renderHook(() => useNoteCommand('A'));
    await act(async () => {
        await result.current.submit('post', '/notes', {}, expected);
    });
    expect(post).not.toHaveBeenCalled();
    expect(result.current.outcome?.status).toBe('rejected');
});
it('ignores a late success callback after an unfinished request was held', async () => {
    const { result } = renderHook(() => useNoteCommand('A'));
    await act(async () => {
        await result.current.submit('post', '/notes', {}, expected);
    });
    act(() => {
        post.mock.calls[0][2].onFinish();
        post.mock.calls[0][2].onSuccess({
            props: { flash: { shift_note_result: receipt } },
        });
    });
    expect(result.current.outcome?.status).toBe('unknown');
});
