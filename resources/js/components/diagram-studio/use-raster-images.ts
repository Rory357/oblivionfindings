import type { DiagramRasterReadAdapter } from '@/lib/diagram-studio/host-api';
import { assertResolvedRaster } from '@/lib/diagram-studio/host-api';
import type { DiagramIssue } from '@/lib/diagram-studio/validation';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

export type RasterImage = {
    status: 'loading' | 'ready' | 'unavailable';
    url?: string;
    blob?: Blob;
};
export function useRasterImages(
    recordKey: string,
    fileIds: readonly number[],
    adapter?: DiagramRasterReadAdapter,
    onIssue?: (issues: readonly DiagramIssue[]) => void,
): ReadonlyMap<number, RasterImage> {
    const signature = [...new Set(fileIds)].sort((a, b) => a - b).join(',');
    const [state, setState] = useState<{
        key: string;
        images: Map<number, RasterImage>;
    }>({ key: recordKey, images: new Map() });
    const report = useRef(onIssue);
    useLayoutEffect(() => {
        report.current = onIssue;
    }, [onIssue]);
    const resolver = adapter?.resolveRaster;
    const policy = adapter?.policy;
    useEffect(() => {
        const controller = new AbortController();
        const ids = signature ? signature.split(',').map(Number) : [];
        const images = new Map<number, RasterImage>(
            ids.map((id) => [
                id,
                { status: resolver ? 'loading' : 'unavailable' },
            ]),
        );
        const urls: string[] = [];
        setState({ key: recordKey, images: new Map(images) });
        for (const fileId of ids) {
            if (!resolver || !policy) continue;
            void resolver(fileId, { signal: controller.signal })
                .then((result) => {
                    if (controller.signal.aborted) return;
                    assertResolvedRaster(fileId, result, policy);
                    const url = URL.createObjectURL(result.blob);
                    urls.push(url);
                    images.set(fileId, {
                        status: 'ready',
                        url,
                        blob: result.blob,
                    });
                    setState({ key: recordKey, images: new Map(images) });
                })
                .catch(() => {
                    if (controller.signal.aborted) return;
                    images.set(fileId, { status: 'unavailable' });
                    setState({ key: recordKey, images: new Map(images) });
                    report.current?.([
                        {
                            path: 'diagram.images',
                            code: 'image_unavailable',
                            message:
                                'An image is unavailable for this document. Its position has been retained.',
                        },
                    ]);
                });
        }
        return () => {
            controller.abort();
            urls.forEach((url) => URL.revokeObjectURL(url));
        };
    }, [recordKey, signature, resolver, policy]);
    return state.key === recordKey ? state.images : new Map();
}
