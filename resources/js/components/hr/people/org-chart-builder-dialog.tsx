/* eslint-disable no-restricted-syntax -- The builder uses native dnd-kit drag
 * handles, drop zones and compact custom rows (not shadcn primitives). All
 * colours are semantic design tokens. */
import {
    DndContext,
    DragOverlay,
    PointerSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
    type DragEndEvent,
    type DragStartEvent,
} from '@dnd-kit/core';
import { router } from '@inertiajs/react';
import { GripVertical, Network } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

import type { OrgNode } from './org-chart-pane';

const ROOT_ID = 'org-root';

function initials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

type FlatRow = { node: OrgNode; depth: number };

function flatten(nodes: OrgNode[], depth = 0): FlatRow[] {
    const out: FlatRow[] = [];
    for (const n of nodes) {
        out.push({ node: n, depth });
        if (n.children.length) out.push(...flatten(n.children, depth + 1));
    }
    return out;
}

function descendantIds(node: OrgNode): Set<number> {
    const ids = new Set<number>();
    const walk = (n: OrgNode) => {
        for (const c of n.children) {
            ids.add(c.id);
            walk(c);
        }
    };
    walk(node);
    return ids;
}

function findNode(nodes: OrgNode[], id: number): OrgNode | null {
    for (const n of nodes) {
        if (n.id === id) return n;
        const found = findNode(n.children, id);
        if (found) return found;
    }
    return null;
}

function RootDropZone() {
    const { setNodeRef, isOver } = useDroppable({ id: ROOT_ID });
    return (
        <div
            ref={setNodeRef}
            className={`mb-2 rounded-lg border border-dashed p-2.5 text-center text-xs font-semibold transition-colors ${
                isOver
                    ? 'border-primary bg-primary/5 text-primary'
                    : 'border-border text-muted-foreground'
            }`}
        >
            Top level — drop here to remove the manager
        </div>
    );
}

function BuilderRow({
    row,
    activeId,
    invalidIds,
}: {
    row: FlatRow;
    activeId: number | null;
    invalidIds: Set<number>;
}) {
    const { node, depth } = row;
    const {
        attributes,
        listeners,
        setNodeRef: dragRef,
        isDragging,
    } = useDraggable({ id: node.id });

    const dropDisabled =
        activeId !== null && (node.id === activeId || invalidIds.has(node.id));
    const { setNodeRef: dropRef, isOver } = useDroppable({
        id: node.id,
        disabled: dropDisabled,
    });

    const setRefs = (el: HTMLElement | null) => {
        dragRef(el);
        dropRef(el);
    };

    return (
        <div
            ref={setRefs}
            style={{ marginLeft: depth * 20 }}
            className={`flex items-center gap-2 rounded-lg border bg-card p-2 transition-shadow ${
                isDragging ? 'opacity-40' : ''
            } ${
                isOver && !dropDisabled
                    ? 'border-primary ring-2 ring-primary'
                    : 'border-border'
            }`}
        >
            <button
                type="button"
                {...listeners}
                {...attributes}
                aria-label={`Drag ${node.name}`}
                className="cursor-grab touch-none rounded p-1 text-muted-foreground hover:bg-muted"
            >
                <GripVertical className="h-4 w-4" />
            </button>
            <span className="grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-md bg-primary/10 text-[11px] font-semibold text-primary">
                {node.photo_url ? (
                    <img src={node.photo_url} alt="" className="h-full w-full object-cover" />
                ) : (
                    initials(node.name)
                )}
            </span>
            <div className="min-w-0">
                <p className="truncate text-sm font-medium">{node.name}</p>
                <p className="truncate text-xs text-muted-foreground">
                    {node.position_title || '—'}
                </p>
            </div>
        </div>
    );
}

/**
 * "Build org chart" — drag a person onto their new manager to change reporting
 * lines. Each drop writes live through the existing orgchart.update endpoint
 * (per-move). Drops onto a node's own descendants are blocked client-side; the
 * server's wouldCreateCycle guard is the source of truth.
 */
export function OrgChartBuilderDialog({
    open,
    onClose,
    hierarchy,
}: {
    open: boolean;
    onClose: () => void;
    hierarchy: OrgNode[];
}) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    );
    const [activeId, setActiveId] = useState<number | null>(null);

    const rows = useMemo(() => flatten(hierarchy), [hierarchy]);
    const activeNode = activeId !== null ? findNode(hierarchy, activeId) : null;
    const invalidIds = useMemo(
        () => (activeNode ? descendantIds(activeNode) : new Set<number>()),
        [activeNode],
    );

    const onDragStart = (e: DragStartEvent) => setActiveId(Number(e.active.id));

    const onDragEnd = (e: DragEndEvent) => {
        const draggedId = Number(e.active.id);
        setActiveId(null);
        if (!e.over) return;

        const dragged = findNode(hierarchy, draggedId);
        if (!dragged) return;

        let managerUserId: number | null;
        if (e.over.id === ROOT_ID) {
            managerUserId = null;
        } else {
            const overId = Number(e.over.id);
            if (overId === draggedId) return; // self
            if (descendantIds(dragged).has(overId)) return; // would cycle
            const overNode = findNode(hierarchy, overId);
            if (!overNode) return;
            if (dragged.manager_user_id === overNode.user_id) return; // no-op
            managerUserId = overNode.user_id;
        }

        router.put(
            `/hr/orgchart/${dragged.id}`,
            { manager_user_id: managerUserId },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[88vh] overflow-hidden sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary">
                            <Network className="h-4 w-4" />
                        </span>
                        Build org chart
                    </DialogTitle>
                    <DialogDescription>
                        Drag a person by the handle onto their new manager to
                        change reporting lines. Drop on “Top level” to remove
                        their manager. Changes save instantly; circular reports
                        are blocked.
                    </DialogDescription>
                </DialogHeader>

                <DndContext
                    sensors={sensors}
                    onDragStart={onDragStart}
                    onDragEnd={onDragEnd}
                >
                    <div className="max-h-[58vh] space-y-1.5 overflow-y-auto pr-1">
                        <RootDropZone />
                        {rows.map((row) => (
                            <BuilderRow
                                key={row.node.id}
                                row={row}
                                activeId={activeId}
                                invalidIds={invalidIds}
                            />
                        ))}
                    </div>
                    <DragOverlay>
                        {activeNode ? (
                            <div className="rounded-lg border border-primary bg-card px-3 py-2 text-sm font-medium shadow-lg">
                                {activeNode.name}
                            </div>
                        ) : null}
                    </DragOverlay>
                </DndContext>

                <div className="flex justify-end border-t border-border pt-3">
                    <Button onClick={onClose}>Done</Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default OrgChartBuilderDialog;
