import type { ReactNode } from 'react';
import type { DiagramStudioCapabilities, KnowledgeDiagramV2 } from './contract';
import type { DiagramIssue } from './validation';

export type DiagramRasterMime = 'image/png' | 'image/jpeg';
export type DiagramRasterPolicy = {
    /** Values come from the host's matching server policy, not persisted diagram fields. */
    maxBytes: number;
    maxWidth: number;
    maxHeight: number;
    maxPixels: number;
    mimeTypes: readonly DiagramRasterMime[];
};
export type DiagramRasterResult = {
    fileId: number;
    mime: DiagramRasterMime;
    width: number;
    height: number;
    bytes: number;
};
export type DiagramRasterReadAdapter = {
    policy: DiagramRasterPolicy;
    /** Closure binds canonical article, actor and (for history) revision. Requests honour signal. */
    resolveRaster: (
        fileId: number,
        context: { signal: AbortSignal },
    ) => Promise<DiagramRasterResult & { blob: Blob }>;
};
export type DiagramRasterAdapter = DiagramRasterReadAdapter & {
    /** Upload creates a ready authorized raster; it does not publish or independently save a diagram. */
    uploadRaster?: (
        file: File,
        context: { signal: AbortSignal },
    ) => Promise<DiagramRasterResult>;
};

export type DiagramChange = {
    /** Echo of the mounted host scope. The host rejects a late callback from another actor/record. */
    recordKey: string;
    kind: 'edit' | 'undo' | 'redo' | 'import' | 'insert-image';
    label: string;
    /** Complete set referenced by next value. Host retains unrelated Word/PDF/image attachments. */
    imageFileIds: readonly number[];
};

/** Planned export from components/diagram-studio/diagram-studio.tsx. */
export type DiagramStudioProps = {
    /** Opaque actor + article + editing-session scope. Changing it aborts loads and resets history. */
    recordKey: string;
    value: KnowledgeDiagramV2;
    /** One callback per completed transaction, not per pointer-move. Never performs HTTP itself. */
    onChange?: (next: KnowledgeDiagramV2, change: DiagramChange) => void;
    capabilities: DiagramStudioCapabilities;
    raster?: DiagramRasterAdapter;
    /** Actual save/review/publication controls are supplied by the Knowledge host. */
    hostActions?: ReactNode;
    onIssue?: (issues: readonly DiagramIssue[]) => void;
    className?: string;
};

/** Planned export from components/diagram-studio/diagram-renderer.tsx. */
export type DiagramRendererProps = {
    /** Include revision identity for history; scope changes invalidate outstanding image requests. */
    recordKey: string;
    value: KnowledgeDiagramV2;
    pageId?: string; // Omitted: first page. Never modifies the source.
    label?: string;
    raster?: DiagramRasterReadAdapter;
    onIssue?: (issues: readonly DiagramIssue[]) => void;
    className?: string;
};

/**
 * Renderer receives bytes from a trusted host callback, never a persisted/network URL.
 * Create an object URL only after this check, revoke it on replacement/unmount, and ignore
 * aborted or stale-scope responses. Server must independently decode/validate the real raster.
 */
export function assertResolvedRaster(
    expectedFileId: number,
    result: DiagramRasterResult & { blob: Blob },
    policy: DiagramRasterPolicy,
): void {
    const { fileId, mime, width, height, bytes, blob } = result;
    if (
        fileId !== expectedFileId ||
        !Number.isSafeInteger(fileId) ||
        fileId < 1
    )
        throw new Error('The image response belongs to another file.');
    if (
        !['image/png', 'image/jpeg'].includes(mime) ||
        !policy.mimeTypes.includes(mime) ||
        blob.type !== mime
    )
        throw new Error('This image type is not supported.');
    if (
        !Number.isSafeInteger(bytes) ||
        bytes < 1 ||
        bytes !== blob.size ||
        bytes > policy.maxBytes
    )
        throw new Error(
            'The image exceeds the document upload limit or its size is invalid.',
        );
    if (
        !Number.isSafeInteger(width) ||
        !Number.isSafeInteger(height) ||
        width < 1 ||
        height < 1 ||
        width > policy.maxWidth ||
        height > policy.maxHeight ||
        width * height > policy.maxPixels
    )
        throw new Error('The image dimensions exceed the document limit.');
}
