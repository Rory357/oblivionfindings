import { DiagramRenderer } from '@/components/diagram-studio/diagram-renderer';
import { DiagramStudio } from '@/components/diagram-studio/diagram-studio';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { upgradeLegacyDiagram } from '@/lib/diagram-studio/compatibility';
import {
    DIAGRAM_ENUMS,
    DIAGRAM_LIMITS,
    type KnowledgeDiagramSource,
    type KnowledgeDiagramV2,
} from '@/lib/diagram-studio/contract';
import type { DiagramRasterAdapter } from '@/lib/diagram-studio/host-api';
import {
    validateDiagramCollection,
    validateDiagramSource,
} from '@/lib/diagram-studio/validation';
import { useId, useLayoutEffect, useRef, useState } from 'react';
import { DiagramView } from './knowledge-legacy-diagrams';

export { DiagramView } from './knowledge-legacy-diagrams';
export type KnowledgeDiagram = KnowledgeDiagramSource;

function blankDiagram(): KnowledgeDiagramV2 {
    return {
        schema_version: 2,
        id: crypto.randomUUID(),
        title: 'New diagram',
        pages: [
            {
                id: crypto.randomUUID(),
                name: 'Page 1',
                subtitle: null,
                width: 1080,
                height: 680,
                orientation: 'landscape',
                paper: 'custom',
                scale: '1:1',
                background: '#ffffff',
                grid: { enabled: true, size: 10, snap: true },
                layers: [
                    {
                        id: crypto.randomUUID(),
                        name: 'Diagram',
                        visible: true,
                        locked: false,
                        print: true,
                    },
                ],
                groups: [],
                nodes: [],
                edges: [],
            },
        ],
    };
}

/** Keep the original legacy source until an explicit edit; document saving remains host-owned. */
export function KnowledgeDiagrams({
    diagrams,
    onChange,
    recordKey = 'knowledge-preview',
    raster,
}: {
    diagrams: KnowledgeDiagram[];
    onChange?: (diagrams: KnowledgeDiagram[]) => void;
    recordKey?: string;
    raster?: DiagramRasterAdapter;
}) {
    const instance = useId();
    const scope = `${recordKey}:${instance}`;
    const currentScope = useRef(scope);
    useLayoutEffect(() => {
        currentScope.current = scope;
        return () => {
            currentScope.current = '';
        };
    }, [scope]);
    const [editor, setEditor] = useState<{
        scope: string;
        value: KnowledgeDiagramV2;
    } | null>(null);
    const [pages, setPages] = useState<Record<string, string>>({});
    const [error, setError] = useState('');
    const opened = editor?.scope === scope ? editor : null;
    const replace = (next: KnowledgeDiagram) => {
        const collection = diagrams.map((item) =>
            item.id === next.id ? next : item,
        );
        const validation = validateDiagramCollection(collection);
        setError(validation.issues[0]?.message ?? '');
        onChange?.(collection);
    };
    const open = (diagram: KnowledgeDiagram) => {
        try {
            const validation = validateDiagramSource(diagram);
            if (!validation.valid)
                throw new Error(
                    validation.issues[0]?.message ??
                        'The saved diagram cannot be opened.',
                );
            const value =
                'schema_version' in diagram
                    ? structuredClone(diagram)
                    : upgradeLegacyDiagram(diagram);
            setEditor({ scope, value });
            setError('');
        } catch (failure) {
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'The original diagram has been retained.',
            );
        }
    };
    return (
        <div className="space-y-5">
            {error && (
                <p role="alert" className="text-sm text-status-critical">
                    {error}
                </p>
            )}
            {!diagrams.length && (
                <p className="text-subtle">No diagrams have been added.</p>
            )}
            {diagrams.map((diagram) => {
                const valid = validateDiagramSource(diagram).valid;
                const v2 = 'schema_version' in diagram;
                return (
                    <section
                        key={diagram.id}
                        className="space-y-3 rounded-xl border border-border bg-card p-5"
                    >
                        <div className="flex flex-wrap items-end gap-3">
                            {onChange ? (
                                <div className="min-w-48 flex-1 space-y-2">
                                    <Label
                                        htmlFor={`${instance}-title-${diagram.id}`}
                                    >
                                        Diagram title
                                    </Label>
                                    <Input
                                        id={`${instance}-title-${diagram.id}`}
                                        value={diagram.title}
                                        maxLength={DIAGRAM_LIMITS.titleLength}
                                        onChange={(event) =>
                                            replace({
                                                ...diagram,
                                                title: event.target.value,
                                            })
                                        }
                                    />
                                </div>
                            ) : (
                                <h3 className="text-section-title flex-1">
                                    {diagram.title}
                                </h3>
                            )}
                            {onChange && (
                                <>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => open(diagram)}
                                    >
                                        Edit diagram
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            onChange(
                                                diagrams.filter(
                                                    (item) =>
                                                        item.id !== diagram.id,
                                                ),
                                            )
                                        }
                                    >
                                        Remove diagram
                                    </Button>
                                </>
                            )}
                        </div>
                        {!valid ? (
                            <p role="status" className="text-subtle">
                                This diagram format cannot be displayed. Its
                                original source has been retained.
                            </p>
                        ) : v2 ? (
                            <>
                                {diagram.pages.length > 1 && (
                                    <div className="space-y-2">
                                        <Label
                                            htmlFor={`${instance}-page-${diagram.id}`}
                                        >
                                            Diagram page
                                        </Label>
                                        <select
                                            id={`${instance}-page-${diagram.id}`}
                                            className="select w-full"
                                            value={
                                                pages[diagram.id] ??
                                                diagram.pages[0].id
                                            }
                                            onChange={(event) =>
                                                setPages((previous) => ({
                                                    ...previous,
                                                    [diagram.id]:
                                                        event.target.value,
                                                }))
                                            }
                                        >
                                            {diagram.pages.map((page) => (
                                                <option
                                                    key={page.id}
                                                    value={page.id}
                                                >
                                                    {page.name}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                )}
                                <DiagramRenderer
                                    recordKey={`${scope}:${diagram.id}`}
                                    value={diagram}
                                    pageId={
                                        pages[diagram.id] ?? diagram.pages[0].id
                                    }
                                    raster={raster}
                                />
                            </>
                        ) : (
                            <DiagramView diagram={diagram} />
                        )}
                    </section>
                );
            })}
            {onChange && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={diagrams.length >= DIAGRAM_LIMITS.diagrams}
                    onClick={() => {
                        const diagram = blankDiagram();
                        onChange([...diagrams, diagram]);
                        setEditor({ scope, value: diagram });
                    }}
                >
                    Create diagram
                </Button>
            )}
            <Dialog
                open={opened !== null}
                onOpenChange={(isOpen) => {
                    if (!isOpen) setEditor(null);
                }}
            >
                <DialogContent className="top-4! left-4! flex h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-none! translate-x-0! translate-y-0! flex-col gap-0 overflow-hidden p-0">
                    <DialogHeader className="shrink-0 border-b border-border px-5 py-3">
                        <DialogTitle>
                            {opened?.value.title ?? 'Diagram editor'}
                        </DialogTitle>
                        <DialogDescription>
                            Drawing changes stay in your document draft. Save
                            the document draft after closing this editor.
                        </DialogDescription>
                    </DialogHeader>
                    {opened && (
                        <DiagramStudio
                            key={`${scope}:${opened.value.id}`}
                            recordKey={scope}
                            value={opened.value}
                            raster={raster}
                            className="min-h-0 flex-1"
                            capabilities={{
                                editable: true,
                                allowedShapes: DIAGRAM_ENUMS.shape,
                                pages: true,
                                importJson: true,
                                exportJson: true,
                                exportSvg: true,
                                exportRaster: true,
                                importImage: Boolean(raster?.uploadRaster),
                            }}
                            hostActions={
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditor(null)}
                                >
                                    Done editing
                                </Button>
                            }
                            onChange={(value, change) => {
                                if (
                                    change.recordKey !== scope ||
                                    currentScope.current !== scope
                                )
                                    return;
                                setEditor({ scope, value });
                                replace(value);
                            }}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
