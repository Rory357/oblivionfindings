import { DiagramRenderer } from '@/components/diagram-studio/diagram-renderer';
import { DiagramStudio } from '@/components/diagram-studio/diagram-studio';
import { useRasterImages } from '@/components/diagram-studio/use-raster-images';
import type { KnowledgeDiagramV2 } from '@/lib/diagram-studio/contract';
import { DIAGRAM_ENUMS } from '@/lib/diagram-studio/contract';
import { serializeSvg } from '@/lib/diagram-studio/export';
import type {
    DiagramRasterAdapter,
    DiagramRasterResult,
    DiagramStudioProps,
} from '@/lib/diagram-studio/host-api';
import { blankPage, makeId, newNode } from '@/lib/diagram-studio/operations';
import {
    act,
    cleanup,
    fireEvent,
    render,
    renderHook,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { StrictMode, useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import './setup';

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});
const capabilities = {
    editable: true,
    allowedShapes: DIAGRAM_ENUMS.shape,
    pages: true,
    importJson: true,
    exportJson: true,
    exportSvg: true,
    exportRaster: true,
    importImage: true,
};
const policy = {
    maxBytes: 20971520,
    maxWidth: 8192,
    maxHeight: 8192,
    maxPixels: 16000000,
    mimeTypes: ['image/png', 'image/jpeg'] as const,
};
function initial() {
    const page = blankPage();
    page.nodes.push(
        newNode('process', page.layers[0].id, 100, 100, 'First step'),
    );
    return {
        schema_version: 2,
        id: makeId(),
        title: 'Test drawing',
        pages: [page],
    } as KnowledgeDiagramV2;
}
function Host({
    source = initial(),
    changed = vi.fn(),
    ...props
}: Partial<DiagramStudioProps> & {
    source?: KnowledgeDiagramV2;
    changed?: (...args: any[]) => void;
}) {
    const [value, setValue] = useState(source);
    return (
        <DiagramStudio
            value={value}
            recordKey="record-a"
            capabilities={capabilities}
            onChange={(next, change) => {
                changed(next, change);
                setValue(next);
            }}
            {...props}
        />
    );
}
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((r) => {
        resolve = r;
    });
    return { promise, resolve };
}
const rasterResult = (fileId = 7) => ({
    fileId,
    mime: 'image/png' as const,
    width: 8,
    height: 4,
    bytes: 3,
    blob: new Blob(['png'], { type: 'image/png' }),
});

describe('editor transaction boundary', () => {
    it('keeps file-dialog shortcuts separate from drawing history', () => {
        const changed = vi.fn(),
            source = initial();
        render(
            <div role="dialog" aria-label="Knowledge drawing">
                <Host source={source} changed={changed} />
            </div>,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add Process' }));
        expect(changed).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'File' }));
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Export editable JSON' }),
            { key: 'z', ctrlKey: true },
        );
        expect(changed).toHaveBeenCalledTimes(1);
        fireEvent.click(
            within(
                screen.getByRole('dialog', { name: 'Drawing file' }),
            ).getByRole('button', { name: 'Close' }),
        );
        fireEvent.keyDown(
            screen.getByRole('group', { name: 'Test drawing drawing canvas' }),
            { key: 'z', ctrlKey: true },
        );
        expect(changed.mock.calls[1][0]).toEqual(source);
    });
    it('keeps drawing keyboard commands available inside a host dialog', () => {
        const changed = vi.fn(),
            source = initial();
        render(
            <div role="dialog" aria-label="Knowledge drawing">
                <Host source={source} changed={changed} />
            </div>,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add Process' }));
        expect(changed.mock.calls[0][0].pages[0].nodes).toHaveLength(2);
        fireEvent.keyDown(
            screen.getByRole('group', { name: 'Test drawing drawing canvas' }),
            { key: 'z', ctrlKey: true },
        );
        expect(changed.mock.calls[1][0]).toEqual(source);
        expect(changed.mock.calls[1][1].kind).toBe('undo');
    });
    it('opens without mutation, emits one move per completed gesture and supports undo/redo', () => {
        const changed = vi.fn(),
            source = initial();
        render(<Host source={source} changed={changed} />);
        expect(changed).not.toHaveBeenCalled();
        const shape = screen.getByRole('button', {
                name: 'process: First step',
            }),
            canvas = screen.getByRole('group', {
                name: 'Test drawing drawing canvas',
            });
        fireEvent.pointerDown(shape, {
            clientX: 120,
            clientY: 120,
            pointerId: 1,
            button: 0,
        });
        fireEvent.pointerMove(canvas, {
            clientX: 180,
            clientY: 150,
            pointerId: 1,
            buttons: 1,
        });
        fireEvent.pointerMove(canvas, {
            clientX: 200,
            clientY: 170,
            pointerId: 1,
            buttons: 1,
        });
        expect(changed).not.toHaveBeenCalled();
        fireEvent.pointerUp(canvas, {
            clientX: 200,
            clientY: 170,
            pointerId: 1,
            button: 0,
        });
        expect(changed).toHaveBeenCalledTimes(1);
        expect(changed.mock.calls[0][0].pages[0].nodes[0]).toMatchObject({
            x: 180,
            y: 150,
        });
        fireEvent.click(screen.getByRole('button', { name: 'Undo' }));
        expect(changed.mock.calls[1][0]).toEqual(source);
        expect(changed.mock.calls[1][1].kind).toBe('undo');
        fireEvent.click(screen.getByRole('button', { name: 'Redo' }));
        expect(changed.mock.calls[2][0].pages[0].nodes[0].x).toBe(180);
    });
    it('cancels a pointer gesture without changing the host source', () => {
        const changed = vi.fn();
        render(<Host changed={changed} />);
        const canvas = screen.getByRole('group', {
            name: 'Test drawing drawing canvas',
        });
        fireEvent.pointerDown(
            screen.getByRole('button', { name: 'process: First step' }),
            { clientX: 120, clientY: 120, button: 0 },
        );
        fireEvent.pointerMove(canvas, {
            clientX: 180,
            clientY: 150,
            buttons: 1,
        });
        fireEvent.pointerCancel(canvas);
        expect(changed).not.toHaveBeenCalled();
    });
    it('blocks locked shapes and read-only edits, while allowing a read-only renderer', () => {
        const source = initial(),
            changed = vi.fn();
        source.pages[0].nodes[0].locked = true;
        const view = render(<Host source={source} changed={changed} />);
        fireEvent.focus(
            screen.getByRole('button', { name: 'process: First step' }),
        );
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'process: First step' }),
            { key: 'Delete' },
        );
        expect(changed).not.toHaveBeenCalled();
        view.unmount();
        const readonly = render(
            <Host
                changed={changed}
                capabilities={{ ...capabilities, editable: false }}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Add Process' }),
        ).toBeDisabled();
        readonly.unmount();
        render(<DiagramRenderer recordKey="read" value={source} />);
        expect(screen.getByRole('img')).toBeInTheDocument();
        expect(screen.queryByRole('button')).toBeNull();
    });
    it('resets undo history when the host record changes', () => {
        const source = initial(),
            changed = vi.fn();
        const view = render(
            <Host source={source} changed={changed} recordKey="a" />,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add Process' }));
        expect(changed).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('button', { name: 'Undo' })).toBeEnabled();
        view.rerender(<Host source={source} changed={changed} recordKey="b" />);
        expect(screen.getByRole('button', { name: 'Undo' })).toBeDisabled();
    });
    it('inserts an image through the host in StrictMode and emits only a file ID', async () => {
        const changed = vi.fn(),
            result = rasterResult(),
            adapter: DiagramRasterAdapter = {
                policy,
                resolveRaster: vi.fn(async () => result),
                uploadRaster: vi.fn(async () => result),
            };
        const view = render(
            <StrictMode>
                <Host changed={changed} raster={adapter} />
            </StrictMode>,
        );
        fireEvent.change(
            view.container.querySelector(
                'input[accept="image/png,image/jpeg"]',
            )!,
            {
                target: {
                    files: [
                        new File(['png'], 'test.png', { type: 'image/png' }),
                    ],
                },
            },
        );
        await waitFor(() => expect(changed).toHaveBeenCalledTimes(1));
        const [next, change] = changed.mock.calls[0];
        expect(change).toMatchObject({
            recordKey: 'record-a',
            kind: 'insert-image',
            imageFileIds: [7],
        });
        expect(next.pages[0].nodes.at(-1)).toMatchObject({
            type: 'image',
            imageFileId: 7,
            w: 360,
            h: 180,
        });
        expect(JSON.stringify(next)).not.toMatch(/blob:|data:|test\.png/);
        await waitFor(() =>
            expect(
                view.container.querySelector('image')?.getAttribute('href'),
            ).toMatch(/^blob:/),
        );
    });
    it('ignores a late upload after changing record scope', async () => {
        const pending = deferred<DiagramRasterResult>(),
            changed = vi.fn(),
            upload = vi.fn(
                (_file: File, _context: { signal: AbortSignal }) =>
                    pending.promise,
            ),
            adapter: DiagramRasterAdapter = {
                policy,
                uploadRaster: upload,
                resolveRaster: vi.fn(async () => rasterResult()),
            };
        const view = render(
            <Host changed={changed} raster={adapter} recordKey="a" />,
        );
        fireEvent.change(
            view.container.querySelector(
                'input[accept="image/png,image/jpeg"]',
            )!,
            {
                target: {
                    files: [
                        new File(['png'], 'test.png', { type: 'image/png' }),
                    ],
                },
            },
        );
        view.rerender(
            <Host changed={changed} raster={adapter} recordKey="b" />,
        );
        expect(upload.mock.calls[0][1].signal.aborted).toBe(true);
        await act(async () => pending.resolve(rasterResult()));
        expect(changed).not.toHaveBeenCalled();
    });
    it('rejects invalid upload metadata without modifying source', async () => {
        const changed = vi.fn(),
            adapter: DiagramRasterAdapter = {
                policy,
                uploadRaster: async () => ({ ...rasterResult(), bytes: -1 }),
                resolveRaster: async () => rasterResult(),
            };
        const view = render(<Host changed={changed} raster={adapter} />);
        fireEvent.change(
            view.container.querySelector(
                'input[accept="image/png,image/jpeg"]',
            )!,
            {
                target: {
                    files: [
                        new File(['png'], 'test.png', { type: 'image/png' }),
                    ],
                },
            },
        );
        await screen.findByText(
            'The image upload returned invalid or unsupported metadata.',
        );
        expect(changed).not.toHaveBeenCalled();
    });
});

describe('private raster lifecycle and export', () => {
    it('revokes ready object URLs and ignores old-scope responses', async () => {
        const pending = deferred<ReturnType<typeof rasterResult>>(),
            resolveRaster = vi.fn(
                (_id: number, _context: { signal: AbortSignal }) =>
                    pending.promise,
            ),
            adapter = { policy, resolveRaster };
        const hook = renderHook(
            ({ recordKey }) => useRasterImages(recordKey, [7], adapter),
            { initialProps: { recordKey: 'a' } },
        );
        hook.rerender({ recordKey: 'b' });
        expect(resolveRaster.mock.calls[0][1].signal.aborted).toBe(true);
        await act(async () => pending.resolve(rasterResult()));
        expect(URL.createObjectURL).toHaveBeenCalledTimes(1);
        expect(hook.result.current.get(7)?.status).toBe('ready');
        const url = hook.result.current.get(7)!.url;
        hook.unmount();
        expect(URL.revokeObjectURL).toHaveBeenCalledWith(url);
    });
    it('keeps denied images unavailable without exposing errors or creating a URL', async () => {
        const issue = vi.fn(),
            adapter = {
                policy,
                resolveRaster: vi.fn(async () => {
                    throw new Error('private filename or denied record');
                }),
            };
        const hook = renderHook(() =>
            useRasterImages('a', [7], adapter, issue),
        );
        await waitFor(() =>
            expect(hook.result.current.get(7)?.status).toBe('unavailable'),
        );
        expect(URL.createObjectURL).not.toHaveBeenCalled();
        expect(JSON.stringify(issue.mock.calls)).not.toContain(
            'private filename',
        );
    });
    it('excludes editor controls and non-printing layers from SVG output', async () => {
        const svg = document.createElementNS(
            'http://www.w3.org/2000/svg',
            'svg',
        );
        svg.innerHTML =
            '<g data-editor-only="true"><rect id="selection"/></g><g data-printable="false"><text>Private guide</text></g><g><text>Published drawing</text></g>';
        const serialized = await serializeSvg(svg, new Map());
        expect(serialized).toContain('Published drawing');
        expect(serialized).not.toMatch(/selection|Private guide/);
        expect(svg.querySelector('[data-editor-only]')).not.toBeNull();
    });
});
