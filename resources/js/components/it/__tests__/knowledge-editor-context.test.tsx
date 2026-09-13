import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, expect, it, vi } from 'vitest';
import { useKnowledgeEditorContext } from '../knowledge-editor-context';

vi.mock('@/components/it/it-wizards', () => ({ KbPreview: () => null }));

const options = { owners: [], sites: [], services: [] };
function response(
    actor = 7,
    id = 23,
    version = 4,
    body = 'Permitted document',
) {
    return {
        data: {
            actor_user_id: actor,
            editable: true,
            article: { id, lock_version: version, body, can: { author: true } },
            options,
        },
    };
}
function deferred() {
    let resolve!: (value: ReturnType<typeof response>) => void;
    const promise = new Promise<ReturnType<typeof response>>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

it('loads the requested document and originating account independently of list state', async () => {
    const get = vi.spyOn(axios, 'get').mockResolvedValue(response());
    const { result } = renderHook(() =>
        useKnowledgeEditorContext(23, 7, options),
    );
    await waitFor(() => expect(result.current.status).toBe('ready'));
    expect(get).toHaveBeenCalledWith(
        '/it/knowledge/23/editor-context',
        expect.objectContaining({
            params: { actor_user_id: 7 },
            signal: expect.any(AbortSignal),
        }),
    );
    if (result.current.status !== 'ready')
        throw new Error('Expected permitted editor');
    expect(result.current.data.article.body).toBe('Permitted document');
    expect(result.current.data.article.lock_version).toBe(4);
});

it('drops a delayed response after the signed-in account changes', async () => {
    const previous = deferred();
    const current = deferred();
    vi.spyOn(axios, 'get')
        .mockReturnValueOnce(previous.promise)
        .mockReturnValueOnce(current.promise);
    const { result, rerender } = renderHook(
        ({ actor }) => useKnowledgeEditorContext(23, actor, options),
        { initialProps: { actor: 7 } },
    );
    rerender({ actor: 9 });
    await act(async () =>
        previous.resolve(response(7, 23, 4, 'Old-account private canary')),
    );
    expect(result.current.status).toBe('loading');
    expect(JSON.stringify(result.current)).not.toContain(
        'Old-account private canary',
    );
    await act(async () =>
        current.resolve(response(9, 23, 5, 'Current-account document')),
    );
    await waitFor(() => expect(result.current.status).toBe('ready'));
    expect(JSON.stringify(result.current)).toContain(
        'Current-account document',
    );
});

it('conceals a loaded document as soon as fresh page permissions arrive', async () => {
    const fresh = deferred();
    const get = vi
        .spyOn(axios, 'get')
        .mockResolvedValueOnce(
            response(7, 23, 4, 'Previously permitted canary'),
        )
        .mockReturnValueOnce(fresh.promise);
    const { result, rerender } = renderHook(
        ({ signal }) => useKnowledgeEditorContext(23, 7, signal),
        { initialProps: { signal: options } },
    );
    await waitFor(() => expect(result.current.status).toBe('ready'));
    rerender({ signal: { owners: [], sites: [], services: [] } });
    expect(result.current.status).toBe('loading');
    expect(JSON.stringify(result.current)).not.toContain(
        'Previously permitted canary',
    );
    await act(async () =>
        fresh.resolve(response(7, 23, 5, 'Freshly checked document')),
    );
    await waitFor(() => expect(result.current.status).toBe('ready'));
    expect(get).toHaveBeenCalledTimes(2);
    expect(JSON.stringify(result.current)).toContain(
        'Freshly checked document',
    );
});

it('treats a response for a different account as lost access', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue(
        response(8, 23, 4, 'Different-account canary'),
    );
    const { result } = renderHook(() =>
        useKnowledgeEditorContext(23, 7, options),
    );
    await waitFor(() => expect(result.current.status).toBe('denied'));
    expect(JSON.stringify(result.current)).not.toContain(
        'Different-account canary',
    );
});

it('does not use content returned for the wrong canonical document', async () => {
    vi.spyOn(axios, 'get').mockResolvedValue(
        response(7, 99, 4, 'Wrong-document canary'),
    );
    const { result } = renderHook(() =>
        useKnowledgeEditorContext(23, 7, options),
    );
    await waitFor(() => expect(result.current.status).toBe('failed'));
    expect(JSON.stringify(result.current)).not.toContain(
        'Wrong-document canary',
    );
});

it('allows a read-only retry after a transient load failure', async () => {
    const get = vi
        .spyOn(axios, 'get')
        .mockRejectedValueOnce(new Error('Temporary failure'))
        .mockResolvedValueOnce(response(7, 23, 6));
    const { result } = renderHook(() =>
        useKnowledgeEditorContext(23, 7, options),
    );
    await waitFor(() => expect(result.current.status).toBe('failed'));
    act(() => result.current.refresh());
    await waitFor(() => expect(result.current.status).toBe('ready'));
    expect(get).toHaveBeenCalledTimes(2);
});

it('maps current authorization denial to a concealed editor', async () => {
    vi.spyOn(axios, 'isAxiosError').mockReturnValue(true);
    vi.spyOn(axios, 'get').mockRejectedValue({ response: { status: 404 } });
    const { result } = renderHook(() =>
        useKnowledgeEditorContext(23, 7, options),
    );
    await waitFor(() => expect(result.current.status).toBe('denied'));
    expect('data' in result.current).toBe(false);
});
