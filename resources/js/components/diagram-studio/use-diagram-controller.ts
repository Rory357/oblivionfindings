import type {
    DiagramPageV2,
    KnowledgeDiagramV2,
} from '@/lib/diagram-studio/contract';
import type {
    DiagramChange,
    DiagramStudioProps,
} from '@/lib/diagram-studio/host-api';
import { changeDiagram } from '@/lib/diagram-studio/operations';
import { referencedImageFileIds } from '@/lib/diagram-studio/validation';
import { useEffect, useRef, useState } from 'react';

export function useDiagramController(props: DiagramStudioProps) {
    const current = useRef(props.value),
        expected = useRef(JSON.stringify(props.value));
    const past = useRef<KnowledgeDiagramV2[]>([]),
        future = useRef<KnowledgeDiagramV2[]>([]);
    const [revision, refresh] = useState(0),
        [message, setMessage] = useState('');
    const [preview, setPreviewState] = useState<KnowledgeDiagramV2 | null>(
        null,
    );
    const previewRef = useRef<KnowledgeDiagramV2 | null>(null);
    const setPreview = (next: KnowledgeDiagramV2 | null) => {
        previewRef.current = next;
        setPreviewState(next);
    };
    const editable = props.capabilities.editable && !!props.onChange;
    useEffect(() => {
        const incoming = JSON.stringify(props.value);
        if (incoming !== expected.current) {
            past.current = [];
            future.current = [];
            setPreview(null);
            refresh((n) => n + 1);
        }
        current.current = props.value;
        expected.current = incoming;
    }, [props.value]);
    const report = (error: unknown) => {
        const message =
            error instanceof Error
                ? error.message
                      .split('\n')[0]
                      .replace(/^diagram(?:\.[^:]+)?:\s*/, '')
                : 'This change could not be applied.';
        setMessage(message);
        props.onIssue?.([{ path: 'diagram', code: 'edit', message }]);
    };
    const emit = (
        next: KnowledgeDiagramV2,
        label: string,
        kind: DiagramChange['kind'],
    ) => {
        current.current = next;
        expected.current = JSON.stringify(next);
        setPreview(null);
        props.onChange?.(next, {
            kind,
            label,
            recordKey: props.recordKey,
            imageFileIds: referencedImageFileIds(next),
        });
        setMessage(label);
        refresh((n) => n + 1);
    };
    const commit = (
        label: string,
        update: (next: KnowledgeDiagramV2) => void,
        kind: DiagramChange['kind'] = 'edit',
    ): boolean => {
        if (!editable) return false;
        try {
            const before = current.current,
                next = changeDiagram(before, update);
            const existing = new Map(
                before.pages.flatMap((page) =>
                    page.nodes.map(
                        (node) => [node.id.toLowerCase(), node.type] as const,
                    ),
                ),
            );
            if (
                next.pages.some((page) =>
                    page.nodes.some(
                        (node) =>
                            !props.capabilities.allowedShapes.includes(
                                node.type,
                            ) &&
                            existing.get(node.id.toLowerCase()) !== node.type,
                    ),
                )
            ) {
                throw new Error(
                    'This host does not support every shape in this change.',
                );
            }
            if (JSON.stringify(before) === JSON.stringify(next)) {
                setPreview(null);
                return false;
            }
            past.current = [
                ...past.current.slice(-79),
                structuredClone(before),
            ];
            future.current = [];
            emit(next, label, kind);
            return true;
        } catch (error) {
            setPreview(null);
            report(error);
            return false;
        }
    };
    const pageChange = (
        pageId: string,
        label: string,
        update: (page: DiagramPageV2) => void,
        kind?: DiagramChange['kind'],
    ) =>
        commit(
            label,
            (next) => {
                const page = next.pages.find((p) => p.id === pageId);
                if (!page) throw new Error('The page is no longer available.');
                update(page);
            },
            kind,
        );
    const undo = () => {
        if (!editable || !past.current.length) return;
        const next = past.current.pop()!;
        future.current.push(structuredClone(current.current));
        emit(next, 'Undid the last change', 'undo');
    };
    const redo = () => {
        if (!editable || !future.current.length) return;
        const next = future.current.pop()!;
        past.current.push(structuredClone(current.current));
        emit(next, 'Redid the change', 'redo');
    };
    return {
        value: preview ?? props.value,
        current,
        preview,
        previewRef,
        setPreview,
        commit,
        pageChange,
        undo,
        redo,
        canUndo: editable && past.current.length > 0,
        canRedo: editable && future.current.length > 0,
        message,
        setMessage,
        report,
        editable,
        revision,
    };
}
