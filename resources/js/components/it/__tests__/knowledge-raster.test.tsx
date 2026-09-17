import type { DiagramRasterAdapter } from '@/lib/diagram-studio/host-api';
import { act, cleanup, render } from '@testing-library/react';
import axios from 'axios';
import { useLayoutEffect } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { useKnowledgeRaster } from '../knowledge-raster';

vi.mock('axios', () => ({
    default: { get: vi.fn(), post: vi.fn(), isAxiosError: () => false },
}));
afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});
let adapter: DiagramRasterAdapter;
function Host({
    actorId = 7,
    articleId = 12,
    revisionId,
    onUploaded,
}: {
    actorId?: number;
    articleId?: number;
    revisionId?: number;
    onUploaded?: () => void;
}) {
    const value = useKnowledgeRaster({
        actorId,
        articleId,
        revisionId,
        version: 4,
        onUploaded,
    });
    useLayoutEffect(() => {
        adapter = value;
    }, [value]);
    return null;
}
function response(actor = 7, article = 12) {
    return {
        data: new Blob(['png'], { type: 'image/png' }),
        headers: {
            'content-type': 'image/png',
            'x-knowledge-actor-id': String(actor),
            'x-knowledge-article-id': String(article),
            'x-knowledge-file-id': '23',
            'x-image-width': '128',
            'x-image-height': '64',
        },
    };
}

it('resolves a raster through the canonical article and historical publication scope', async () => {
    vi.mocked(axios.get).mockResolvedValue(response());
    render(<Host revisionId={31} />);
    const signal = new AbortController().signal;
    const raster = await adapter.resolveRaster(23, { signal });
    expect(raster).toMatchObject({
        fileId: 23,
        mime: 'image/png',
        width: 128,
        height: 64,
        bytes: 3,
    });
    expect(axios.get).toHaveBeenCalledWith(
        '/it/knowledge/12/files/23',
        expect.objectContaining({
            params: { raster: 1, actor_user_id: 7, revision: 31 },
            responseType: 'blob',
            signal,
        }),
    );
    expect(adapter.uploadRaster).toBeUndefined();
});

it('rejects raster metadata for another account or file', async () => {
    render(<Host />);
    vi.mocked(axios.get).mockResolvedValue(response(8));
    await expect(
        adapter.resolveRaster(23, { signal: new AbortController().signal }),
    ).rejects.toThrow('access changed');
    vi.mocked(axios.get).mockResolvedValue(response());
    await expect(
        adapter.resolveRaster(24, { signal: new AbortController().signal }),
    ).rejects.toThrow('another file');
});

it('ignores a delayed image response after switching documents', async () => {
    let finish!: (value: ReturnType<typeof response>) => void;
    vi.mocked(axios.get).mockReturnValue(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    const view = render(<Host />);
    const pending = adapter.resolveRaster(23, {
        signal: new AbortController().signal,
    });
    const rejected = expect(pending).rejects.toThrow('access changed');
    view.rerender(<Host actorId={8} articleId={29} />);
    await act(async () => finish(response()));
    await rejected;
});

it('hands an authorized image receipt and retained document files back to the same editor', async () => {
    const saved = vi.fn();
    const file = new File(['png'], 'diagram.png', { type: 'image/png' });
    vi.mocked(axios.post).mockResolvedValue({
        data: {
            actor_user_id: 7,
            article_id: 12,
            lock_version: 5,
            file_ids: [10, 11, 23],
            file: {
                fileId: 23,
                mime: 'image/png',
                width: 128,
                height: 64,
                bytes: 3,
            },
        },
    });
    render(<Host onUploaded={saved} />);
    await adapter.uploadRaster!(file, { signal: new AbortController().signal });
    expect(saved).toHaveBeenCalledWith(
        expect.objectContaining({ lock_version: 5, file_ids: [10, 11, 23] }),
    );
    const body = vi.mocked(axios.post).mock.calls[0][1] as FormData;
    expect(body.get('actor_user_id')).toBe('7');
    expect(body.get('lock_version')).toBe('4');
    expect(body.get('diagram_image')).toBe('1');
});

it('does not apply a completed upload to a different signed-in account', async () => {
    let finish!: (value: unknown) => void;
    vi.mocked(axios.post).mockReturnValue(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    const saved = vi.fn();
    const view = render(<Host onUploaded={saved} />);
    const pending = adapter.uploadRaster!(
        new File(['png'], 'diagram.png', { type: 'image/png' }),
        { signal: new AbortController().signal },
    );
    const rejected = expect(pending).rejects.toThrow('document changed');
    view.rerender(<Host actorId={8} onUploaded={saved} />);
    await act(async () =>
        finish({
            data: {
                actor_user_id: 7,
                article_id: 12,
                lock_version: 5,
                file_ids: [23],
                file: {
                    fileId: 23,
                    mime: 'image/png',
                    width: 128,
                    height: 64,
                    bytes: 3,
                },
            },
        }),
    );
    await rejected;
    expect(saved).not.toHaveBeenCalled();
});
