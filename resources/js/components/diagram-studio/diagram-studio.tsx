import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    remapDiagramForImport,
    upgradeLegacyDiagram,
} from '@/lib/diagram-studio/compatibility';
import type {
    DiagramNodeV2,
    DiagramPageV2,
    DiagramShapeKind,
    KnowledgeDiagramV2,
} from '@/lib/diagram-studio/contract';
import {
    downloadJson,
    downloadRaster,
    safeFilename,
    serializeSvg,
} from '@/lib/diagram-studio/export';
import {
    bounds,
    centre,
    clamp,
    editable,
    rotate,
    selectionForNode,
    snapDelta,
    visible,
} from '@/lib/diagram-studio/geometry';
import type { DiagramStudioProps } from '@/lib/diagram-studio/host-api';
import type { DiagramClipboard } from '@/lib/diagram-studio/operations';
import {
    blankPage,
    copySelection,
    ensureEditable,
    groupSelection,
    makeId,
    newNode,
    pasteSelection,
    removeSelection,
    selectedNodes,
    ungroupSelection,
} from '@/lib/diagram-studio/operations';
import { STENCIL_GROUPS } from '@/lib/diagram-studio/stencils';
import { STARTERS, createStarterPage } from '@/lib/diagram-studio/templates';
import {
    assertDiagramSource,
    validateDiagramSource,
} from '@/lib/diagram-studio/validation';
import type { LucideIcon } from 'lucide-react';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    ArrowDownToLine,
    ArrowUpToLine,
    Bold,
    CheckCheck,
    ChevronRight,
    Copy,
    Download,
    Grid2X2,
    Group,
    Hand,
    ImagePlus,
    Layers,
    LayoutTemplate,
    Maximize2,
    MousePointer2,
    PanelLeftClose,
    PanelRightClose,
    Pencil,
    Plus,
    Redo2,
    Search,
    Settings2,
    Trash2,
    Type,
    Undo2,
    Ungroup,
    Upload,
    Waypoints,
    X,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import type { KeyboardEvent, PointerEvent, ReactNode } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { DiagramScene } from './diagram-renderer';
import './diagram-studio.css';
import { DraftField, Inspector } from './editor-fields';
import { ShapeGraphic } from './shape-graphic';
import { useDiagramController } from './use-diagram-controller';
import { useRasterImages } from './use-raster-images';
import {
    DataTools,
    LayerTools,
    PageTools,
    ValidationTools,
} from './workspace-panels';

const tabs = [
    'Home',
    'Insert',
    'Design',
    'Arrange',
    'Data',
    'Process',
    'Review',
    'View',
] as const;
type Tab = (typeof tabs)[number];
type Tool = 'select' | 'connect' | 'pan' | 'rectangle' | 'ink';
type Modal =
    | 'file'
    | 'templates'
    | 'pages'
    | 'inspector'
    | 'data'
    | 'validation'
    | 'text'
    | 'shapes'
    | null;
type Gesture = {
    kind:
        | 'move'
        | 'resize'
        | 'rotate'
        | 'marquee'
        | 'pan'
        | 'rectangle'
        | 'ink';
    pointer: number;
    x: number;
    y: number;
    clientX: number;
    clientY: number;
    before: KnowledgeDiagramV2;
    ids: string[];
    node?: DiagramNodeV2;
    changed: boolean;
    scrollX: number;
    scrollY: number;
    points: { x: number; y: number }[];
};
function Command({
    icon: Icon,
    children,
    onClick,
    disabled,
    active,
    label,
}: {
    icon?: LucideIcon;
    children?: ReactNode;
    onClick: () => void;
    disabled?: boolean;
    active?: boolean;
    label?: string;
}) {
    return (
        <Button
            type="button"
            size="sm"
            variant={active ? 'secondary' : 'ghost'}
            className="ds-command"
            aria-label={label}
            title={label}
            aria-pressed={active === undefined ? undefined : active}
            disabled={disabled}
            onClick={onClick}
        >
            {Icon && <Icon size={17} aria-hidden="true" />}
            {children}
        </Button>
    );
}

export function DiagramStudio(props: DiagramStudioProps) {
    const validation = validateDiagramSource(props.value);
    if (!validation.valid)
        return (
            <div role="alert">
                This diagram cannot be edited in this version. Its original
                source has been retained.
            </div>
        );
    return <StudioSession key={props.recordKey} {...props} />;
}

function StudioSession(props: DiagramStudioProps) {
    const controller = useDiagramController(props),
        value = controller.value;
    const [pageId, setPageId] = useState(value.pages[0].id),
        [selection, setSelection] = useState<string[]>([]),
        [edgeId, setEdgeId] = useState<string>();
    const [tab, setTab] = useState<Tab>('Home'),
        [tool, setTool] = useState<Tool>('select'),
        [modal, setModal] = useState<Modal>(null);
    const [search, setSearch] = useState(''),
        [family, setFamily] = useState<string>('Flowchart'),
        [inspectorTab, setInspectorTab] = useState<
            'format' | 'layers' | 'data'
        >('format');
    const [leftOpen, setLeftOpen] = useState(true),
        [rightOpen, setRightOpen] = useState(true),
        [compact, setCompact] = useState(false),
        [focus, setFocus] = useState(false);
    const [zoom, setZoom] = useState(0.8),
        [rulers, setRulers] = useState(true),
        [minimap, setMinimap] = useState(true),
        [scroll, setScroll] = useState({ x: 0, y: 0 });
    const [layerId, setLayerId] = useState(value.pages[0].layers[0].id),
        [connectFrom, setConnectFrom] = useState<string>(),
        [uploading, setUploading] = useState(false);
    const [marquee, setMarquee] = useState<{
        x: number;
        y: number;
        w: number;
        h: number;
    }>();
    const [exportLink, setExportLink] = useState<{
        url: string;
        filename: string;
        bytes: number;
    }>();
    const exportUrl = useRef<string | undefined>(undefined);
    const root = useRef<HTMLElement>(null),
        viewport = useRef<HTMLDivElement>(null),
        svg = useRef<SVGSVGElement>(null),
        gesture = useRef<Gesture | null>(null);
    const clipboard = useRef<DiagramClipboard | null>(null),
        imageInput = useRef<HTMLInputElement>(null),
        jsonInput = useRef<HTMLInputElement>(null),
        uploads = useRef(new Set<AbortController>()),
        mounted = useRef(true);
    const page = value.pages.find((p) => p.id === pageId) ?? value.pages[0],
        activeLayer =
            page.layers.find((l) => l.id === layerId) ?? page.layers[0];
    const chosen = page.nodes.filter((n) => selection.includes(n.id)),
        node = chosen[0],
        edge = page.edges.find((e) => e.id === edgeId);
    const canEditSelection =
        controller.editable &&
        chosen.length > 0 &&
        chosen.every((n) => editable(n, page));
    const edgeEditable =
        controller.editable &&
        !!edge &&
        page.layers.some(
            (l) => l.id === edge.layerId && l.visible && !l.locked,
        );
    const imageIds = page.nodes.flatMap((n) =>
        n.imageFileId === null ? [] : [n.imageFileId],
    );
    const images = useRasterImages(
        props.recordKey,
        imageIds,
        props.raster,
        props.onIssue,
    );
    const changePage = (
        label: string,
        update: (draft: DiagramPageV2) => void,
    ) => controller.pageChange(page.id, label, update);
    const setPage = (id: string) => {
        setPageId(id);
        setSelection([]);
        setEdgeId(undefined);
        setConnectFrom(undefined);
        setModal(null);
        setTool('select');
    };
    const fit = () => {
        const element = viewport.current;
        if (element)
            setZoom(
                clamp(
                    Math.min(
                        (element.clientWidth - 72) / page.width,
                        (element.clientHeight - 72) / page.height,
                    ),
                    0.15,
                    2,
                ),
            );
    };
    const firstLayerId = page.layers[0].id;
    useLayoutEffect(() => {
        const element = viewport.current;
        if (element)
            setZoom(clamp((element.clientWidth - 72) / page.width, 0.7, 1));
        setLayerId(firstLayerId);
    }, [page.id, page.width, page.height, firstLayerId]);
    useEffect(() => {
        const element = root.current;
        if (!element) return;
        const observer = new ResizeObserver((entries) => {
            const next = entries[0].contentRect.width < 1000;
            setCompact(next);
            if (next) {
                setLeftOpen(false);
                setRightOpen(false);
            }
        });
        observer.observe(element);
        return () => observer.disconnect();
    }, []);
    useEffect(() => {
        mounted.current = true;
        const pendingUploads = uploads.current;
        return () => {
            mounted.current = false;
            pendingUploads.forEach((c) => c.abort());
            if (exportUrl.current) URL.revokeObjectURL(exportUrl.current);
        };
    }, []);
    useEffect(() => {
        const element = viewport.current;
        if (!element) return;
        const wheel = (event: WheelEvent) => {
            if (!event.ctrlKey) return;
            event.preventDefault();
            const rect = element.getBoundingClientRect(),
                x = event.clientX - rect.left,
                y = event.clientY - rect.top;
            setZoom((before) => {
                const next = clamp(
                    before * (event.deltaY < 0 ? 1.1 : 1 / 1.1),
                    0.15,
                    3,
                );
                requestAnimationFrame(() => {
                    if (element.isConnected) {
                        element.scrollLeft =
                            ((element.scrollLeft + x) * next) / before - x;
                        element.scrollTop =
                            ((element.scrollTop + y) * next) / before - y;
                    }
                });
                return next;
            });
        };
        element.addEventListener('wheel', wheel, { passive: false });
        return () => element.removeEventListener('wheel', wheel);
    }, []);
    const point = (event: { clientX: number; clientY: number }) => {
        const rect = svg.current!.getBoundingClientRect();
        return {
            x: ((event.clientX - rect.left) * page.width) / rect.width,
            y: ((event.clientY - rect.top) * page.height) / rect.height,
        };
    };
    const assertLayer = () => {
        if (!activeLayer.visible || activeLayer.locked)
            throw new Error('Choose a visible, unlocked layer before drawing.');
    };
    const patchNode = (patch: Partial<DiagramNodeV2>) =>
        changePage('Updated shape', (p) => {
            const nodes = selectedNodes(p, selection);
            ensureEditable(p, nodes);
            nodes.forEach((n) => {
                const { style, ...fields } = patch;
                Object.assign(n, fields);
                if (style && node)
                    for (const key of Object.keys(
                        style,
                    ) as (keyof typeof style)[])
                        if (style[key] !== node.style[key])
                            Object.assign(n.style, { [key]: style[key] });
            });
        });
    const patchEdge = (patch: Partial<NonNullable<typeof edge>>) =>
        changePage('Updated connector', (p) => {
            if (!edgeEditable)
                throw new Error('Unlock the connector layer to edit it.');
            const item = p.edges.find((e) => e.id === edgeId);
            if (item) Object.assign(item, patch);
        });
    const addShape = (
        type: DiagramShapeKind,
        label: string | null,
        at?: { x: number; y: number },
    ) => {
        if (
            !controller.editable ||
            !props.capabilities.allowedShapes.includes(type) ||
            type === 'image' ||
            type === 'ink'
        )
            return;
        try {
            assertLayer();
            const created = newNode(
                type,
                activeLayer.id,
                at?.x ?? Math.max(20, page.width / 2 - 80),
                at?.y ?? Math.max(20, page.height / 2 - 40),
                label,
            );
            if (changePage('Added shape', (p) => p.nodes.push(created))) {
                setSelection([created.id]);
                setEdgeId(undefined);
                setTool('select');
                if (compact) setLeftOpen(false);
                setModal(null);
            }
        } catch (error) {
            controller.report(error);
        }
    };
    const connect = (id: string) => {
        if (!connectFrom) {
            setConnectFrom(id);
            controller.setMessage('Choose the destination shape.');
            return;
        }
        if (connectFrom === id) return;
        try {
            assertLayer();
            const created = {
                id: makeId(),
                from: connectFrom,
                to: id,
                label: null,
                layerId: activeLayer.id,
                route: 'orthogonal' as const,
                fromPort: 'auto' as const,
                toPort: 'auto' as const,
                arrow: 'triangle' as const,
                startArrow: 'none' as const,
                stroke: '#83749f',
                width: 1.8,
                dash: 'solid' as const,
                bend: 0,
                labelPos: 0.5,
            };
            if (changePage('Added connector', (p) => p.edges.push(created))) {
                setConnectFrom(undefined);
                setEdgeId(created.id);
                setSelection([]);
                setTool('select');
            }
        } catch (error) {
            controller.report(error);
        }
    };
    const remove = () => {
        if (edge && !edgeEditable) return;
        if (
            changePage('Removed selection', (p) =>
                removeSelection(p, selection, edgeId),
            )
        ) {
            setSelection([]);
            setEdgeId(undefined);
        }
    };
    const copy = () => {
        try {
            if (!selection.length) return false;
            clipboard.current = copySelection(
                controller.current.current,
                page,
                selection,
            );
            controller.setMessage('Selection copied within this document.');
            return true;
        } catch (error) {
            controller.report(error);
            return false;
        }
    };
    const paste = () => {
        try {
            assertLayer();
            if (!clipboard.current) return;
            let ids: string[] = [];
            if (
                changePage('Pasted selection', (p) => {
                    ids = pasteSelection(p, clipboard.current!, activeLayer.id);
                })
            ) {
                setSelection(ids);
                setEdgeId(undefined);
            }
        } catch (error) {
            controller.report(error);
        }
    };
    const duplicate = () => {
        if (copy()) paste();
    };
    const group = (container = false) => {
        let ids: string[] = [];
        if (
            changePage(
                container ? 'Added container' : 'Grouped selection',
                (p) => {
                    ids = groupSelection(p, selection, container);
                },
            )
        )
            setSelection(ids);
    };
    const align = (
        kind:
            | 'left'
            | 'center'
            | 'right'
            | 'top'
            | 'middle'
            | 'bottom'
            | 'horizontal'
            | 'vertical',
    ) =>
        changePage('Aligned selection', (p) => {
            const nodes = selectedNodes(p, selection);
            ensureEditable(p, nodes);
            if (nodes.length < 2) return;
            const minX = Math.min(...nodes.map((n) => n.x)),
                minY = Math.min(...nodes.map((n) => n.y)),
                maxX = Math.max(...nodes.map((n) => n.x + n.w)),
                maxY = Math.max(...nodes.map((n) => n.y + n.h));
            if (kind === 'horizontal' || kind === 'vertical') {
                if (nodes.length < 3)
                    throw new Error(
                        'Select at least three shapes to distribute.',
                    );
                const axis = kind === 'horizontal' ? 'x' : 'y',
                    size = axis === 'x' ? 'w' : 'h',
                    sorted = nodes.sort((a, b) => a[axis] - b[axis]);
                const extent =
                        sorted.at(-1)![axis] +
                        sorted.at(-1)![size] -
                        sorted[0][axis],
                    gap =
                        (extent - sorted.reduce((sum, n) => sum + n[size], 0)) /
                        (sorted.length - 1);
                let cursor = sorted[0][axis];
                sorted.forEach((n) => {
                    n[axis] = cursor;
                    cursor += n[size] + gap;
                });
                return;
            }
            nodes.forEach((n) => {
                if (kind === 'left') n.x = minX;
                if (kind === 'center') n.x = (minX + maxX - n.w) / 2;
                if (kind === 'right') n.x = maxX - n.w;
                if (kind === 'top') n.y = minY;
                if (kind === 'middle') n.y = (minY + maxY - n.h) / 2;
                if (kind === 'bottom') n.y = maxY - n.h;
            });
        });
    const order = (front: boolean) =>
        changePage(
            front ? 'Brought selection forward' : 'Sent selection back',
            (p) => {
                const selected = selectedNodes(p, selection);
                ensureEditable(p, selected);
                const ids = new Set(selected.map((n) => n.id)),
                    other = p.nodes.filter((n) => !ids.has(n.id));
                p.nodes = front
                    ? [...other, ...selected]
                    : [...selected, ...other];
            },
        );

    const beginGesture = (
        event: PointerEvent<SVGElement>,
        kind: Gesture['kind'],
        ids = selection,
        selectedNode?: DiagramNodeV2,
    ) => {
        if (
            event.button !== 0 ||
            (kind !== 'pan' && kind !== 'marquee' && !controller.editable)
        )
            return;
        const pos = point(event);
        gesture.current = {
            kind,
            pointer: event.pointerId,
            ...pos,
            clientX: event.clientX,
            clientY: event.clientY,
            before: structuredClone(controller.current.current),
            ids,
            node: selectedNode ? structuredClone(selectedNode) : undefined,
            changed: false,
            scrollX: viewport.current?.scrollLeft ?? 0,
            scrollY: viewport.current?.scrollTop ?? 0,
            points: [pos],
        };
    };
    const onNodeDown = (event: PointerEvent<SVGGElement>, n: DiagramNodeV2) => {
        if (tool === 'pan') return;
        if (tool === 'ink' || tool === 'rectangle') return;
        event.stopPropagation();
        if (tool === 'connect' && controller.editable) {
            connect(n.id);
            return;
        }
        const members = selectionForNode(page, n),
            selected = event.shiftKey
                ? selection.includes(n.id)
                    ? selection.filter((id) => !members.includes(id))
                    : [...new Set([...selection, ...members])]
                : selection.includes(n.id)
                  ? selection
                  : members;
        setSelection(selected);
        setEdgeId(undefined);
        if (editable(n, page) && selected.length)
            beginGesture(event, 'move', selected, n);
    };
    const onCanvasDown = (event: PointerEvent<SVGSVGElement>) => {
        if (tool === 'pan') {
            beginGesture(event, 'pan');
            return;
        }
        if (tool === 'ink' || tool === 'rectangle') {
            if (!controller.editable) return;
            try {
                assertLayer();
                if (
                    !props.capabilities.allowedShapes.includes(
                        tool === 'ink' ? 'ink' : 'rectangle',
                    )
                )
                    return;
                const pos = point(event),
                    created = newNode(
                        tool === 'ink' ? 'ink' : 'rectangle',
                        activeLayer.id,
                        pos.x,
                        pos.y,
                    );
                created.w = 1;
                created.h = 1;
                if (tool === 'ink') {
                    created.style.fill = 'none';
                    created.points = [
                        { x: 0, y: 0 },
                        { x: 0, y: 0 },
                    ];
                }
                beginGesture(event, tool, [created.id], created);
            } catch (error) {
                controller.report(error);
            }
            return;
        }
        if (tool === 'connect') return;
        if (!event.shiftKey) {
            setSelection([]);
            setEdgeId(undefined);
        }
        beginGesture(event, 'marquee', event.shiftKey ? selection : []);
    };
    const onMove = (event: PointerEvent<SVGSVGElement>) => {
        const active = gesture.current;
        if (!active || active.pointer !== event.pointerId) return;
        if (
            !active.changed &&
            Math.hypot(
                event.clientX - active.clientX,
                event.clientY - active.clientY,
            ) < 3
        )
            return;
        active.changed = true;
        if (!event.currentTarget.hasPointerCapture(event.pointerId))
            event.currentTarget.setPointerCapture(event.pointerId);
        if (active.kind === 'pan') {
            if (viewport.current) {
                viewport.current.scrollLeft =
                    active.scrollX - event.clientX + active.clientX;
                viewport.current.scrollTop =
                    active.scrollY - event.clientY + active.clientY;
            }
            return;
        }
        const pos = point(event),
            dx = pos.x - active.x,
            dy = pos.y - active.y;
        if (active.kind === 'marquee') {
            setMarquee({
                x: Math.min(pos.x, active.x),
                y: Math.min(pos.y, active.y),
                w: Math.abs(dx),
                h: Math.abs(dy),
            });
            return;
        }
        const next = structuredClone(active.before),
            p = next.pages.find((p) => p.id === page.id)!;
        try {
            if (active.kind === 'move') {
                const nodes = selectedNodes(p, active.ids);
                ensureEditable(p, nodes);
                const delta = snapDelta(p, nodes, dx, dy, event.altKey);
                nodes.forEach((n) => {
                    n.x += delta.x;
                    n.y += delta.y;
                });
            }
            if (active.kind === 'resize' && active.node) {
                const n = p.nodes.find((n) => n.id === active.node!.id)!;
                ensureEditable(p, [n]);
                const anchor = rotate(
                        { x: n.x, y: n.y },
                        centre(n),
                        n.rotation,
                    ),
                    local = rotate(
                        { x: pos.x - anchor.x, y: pos.y - anchor.y },
                        { x: 0, y: 0 },
                        -n.rotation,
                    );
                n.w = clamp(local.x, 10, 10000);
                n.h = clamp(local.y, 10, 10000);
                if (event.shiftKey) n.h = (n.w * active.node.h) / active.node.w;
                const half = rotate(
                    { x: n.w / 2, y: n.h / 2 },
                    { x: 0, y: 0 },
                    n.rotation,
                );
                n.x = anchor.x + half.x - n.w / 2;
                n.y = anchor.y + half.y - n.h / 2;
            }
            if (active.kind === 'rotate' && active.node) {
                const n = p.nodes.find((n) => n.id === active.node!.id)!;
                ensureEditable(p, [n]);
                const c = centre(n),
                    angle =
                        (Math.atan2(pos.y - c.y, pos.x - c.x) * 180) / Math.PI +
                        90;
                n.rotation = event.shiftKey
                    ? Math.round(angle / 15) * 15
                    : Math.round(angle);
            }
            if (
                (active.kind === 'rectangle' || active.kind === 'ink') &&
                active.node
            ) {
                const created = structuredClone(active.node);
                if (active.kind === 'ink') {
                    if (
                        active.points.length < 512 &&
                        Math.hypot(
                            pos.x - active.points.at(-1)!.x,
                            pos.y - active.points.at(-1)!.y,
                        ) > 1
                    )
                        active.points.push(pos);
                    const x = Math.min(...active.points.map((p) => p.x)),
                        y = Math.min(...active.points.map((p) => p.y)),
                        w = Math.max(
                            1,
                            Math.max(...active.points.map((p) => p.x)) - x,
                        ),
                        h = Math.max(
                            1,
                            Math.max(...active.points.map((p) => p.y)) - y,
                        );
                    Object.assign(created, {
                        x,
                        y,
                        w,
                        h,
                        points: active.points.map((p) => ({
                            x: (p.x - x) / w,
                            y: (p.y - y) / h,
                        })),
                    });
                } else
                    Object.assign(created, {
                        x: Math.min(pos.x, active.x),
                        y: Math.min(pos.y, active.y),
                        w: Math.max(10, Math.abs(dx)),
                        h: Math.max(10, Math.abs(dy)),
                    });
                p.nodes.push(created);
            }
            controller.setPreview(next);
        } catch (error) {
            gesture.current = null;
            controller.setPreview(null);
            controller.report(error);
        }
    };
    const finishGesture = (
        event: PointerEvent<SVGSVGElement>,
        cancel = false,
    ) => {
        const active = gesture.current;
        if (!active || active.pointer !== event.pointerId) return;
        gesture.current = null;
        if (event.currentTarget.hasPointerCapture(event.pointerId))
            event.currentTarget.releasePointerCapture(event.pointerId);
        if (cancel || !active.changed) {
            setMarquee(undefined);
            controller.setPreview(null);
            return;
        }
        if (active.kind === 'marquee') {
            const end = point(event),
                box = {
                    x: Math.min(active.x, end.x),
                    y: Math.min(active.y, end.y),
                    w: Math.abs(end.x - active.x),
                    h: Math.abs(end.y - active.y),
                };
            const ids = page.nodes
                .filter((n) => visible(n, page))
                .filter((n) => {
                    const b = bounds([n])!;
                    return (
                        b.x >= box.x &&
                        b.y >= box.y &&
                        b.x + b.w <= box.x + box.w &&
                        b.y + b.h <= box.y + box.h
                    );
                })
                .flatMap((n) => selectionForNode(page, n));
            setSelection([...new Set([...active.ids, ...ids])]);
            setMarquee(undefined);
            return;
        }
        if (active.kind === 'pan') return;
        if (
            JSON.stringify(controller.current.current) !==
            JSON.stringify(active.before)
        ) {
            controller.setPreview(null);
            controller.report(
                new Error(
                    'The drawing changed during this gesture. Please try the move again.',
                ),
            );
            return;
        }
        const finalPreview = controller.previewRef.current;
        const label =
            active.kind === 'move'
                ? 'Moved selection'
                : active.kind === 'resize'
                  ? 'Resized shape'
                  : active.kind === 'rotate'
                    ? 'Rotated shape'
                    : active.kind === 'rectangle'
                      ? 'Drew rectangle'
                      : 'Drew pen stroke';
        if (
            finalPreview &&
            controller.commit(label, (next) =>
                Object.assign(next, finalPreview),
            )
        ) {
            setSelection(active.ids);
            setEdgeId(undefined);
        }
        if (active.kind === 'rectangle') setTool('select');
    };
    const moveByKey = (dx: number, dy: number) =>
        changePage('Moved selection', (p) => {
            const nodes = selectedNodes(p, selection);
            ensureEditable(p, nodes);
            const delta = snapDelta(p, nodes, dx, dy, true);
            nodes.forEach((n) => {
                n.x += delta.x;
                n.y += delta.y;
            });
        });
    const onKeyDown = (event: KeyboardEvent<HTMLElement>) => {
        const target = event.target as HTMLElement;
        const nestedDialog = target.closest('[role="dialog"]');
        if (
            target.closest('input,textarea,select,[contenteditable="true"]') ||
            (nestedDialog &&
                nestedDialog !== root.current?.closest('[role="dialog"]'))
        )
            return;
        const command = event.ctrlKey || event.metaKey;
        if (event.key === 'Escape') {
            gesture.current = null;
            controller.setPreview(null);
            setMarquee(undefined);
            setConnectFrom(undefined);
            setTool('select');
            return;
        }
        if (command && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            if (event.shiftKey) controller.redo();
            else controller.undo();
        } else if (command && event.key.toLowerCase() === 'y') {
            event.preventDefault();
            controller.redo();
        } else if (command && event.key.toLowerCase() === 'c') {
            event.preventDefault();
            copy();
        } else if (command && event.key.toLowerCase() === 'v') {
            event.preventDefault();
            paste();
        } else if (command && event.key.toLowerCase() === 'd') {
            event.preventDefault();
            duplicate();
        } else if (command && event.key.toLowerCase() === 'a') {
            event.preventDefault();
            setSelection(
                page.nodes.filter((n) => visible(n, page)).map((n) => n.id),
            );
        } else if (command && event.key.toLowerCase() === 'g') {
            event.preventDefault();
            if (event.shiftKey) {
                changePage('Ungrouped selection', (p) =>
                    ungroupSelection(p, selection),
                );
            } else group();
        } else if (['Delete', 'Backspace'].includes(event.key)) {
            event.preventDefault();
            remove();
        } else if (
            ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(
                event.key,
            ) &&
            selection.length
        ) {
            event.preventDefault();
            const step = event.shiftKey ? 1 : 10;
            moveByKey(
                event.key === 'ArrowLeft'
                    ? -step
                    : event.key === 'ArrowRight'
                      ? step
                      : 0,
                event.key === 'ArrowUp'
                    ? -step
                    : event.key === 'ArrowDown'
                      ? step
                      : 0,
            );
        } else if (!command) {
            const map: Record<string, Tool> = {
                v: 'select',
                c: 'connect',
                h: 'pan',
                r: 'rectangle',
                p: 'ink',
            };
            if (map[event.key.toLowerCase()])
                setTool(map[event.key.toLowerCase()]);
            if (event.key === '0') fit();
        }
    };

    const uploadImage = async (file: File) => {
        if (
            !controller.editable ||
            !props.capabilities.importImage ||
            !props.capabilities.allowedShapes.includes('image') ||
            !props.raster?.uploadRaster
        )
            return;
        const adapter = props.raster,
            upload = adapter.uploadRaster!,
            targetPage = page.id,
            targetLayer = activeLayer.id,
            abort = new AbortController();
        try {
            assertLayer();
            if (file.size > adapter.policy.maxBytes)
                throw new Error(
                    'The image is larger than the document upload limit.',
                );
            uploads.current.add(abort);
            setUploading(true);
            const result = await upload(file, { signal: abort.signal });
            if (!mounted.current || abort.signal.aborted) return;
            if (
                !Number.isSafeInteger(result.fileId) ||
                result.fileId < 1 ||
                !adapter.policy.mimeTypes.includes(result.mime) ||
                !Number.isSafeInteger(result.width) ||
                !Number.isSafeInteger(result.height) ||
                !Number.isSafeInteger(result.bytes) ||
                result.bytes < 1 ||
                result.width < 1 ||
                result.height < 1 ||
                result.width > adapter.policy.maxWidth ||
                result.height > adapter.policy.maxHeight ||
                result.width * result.height > adapter.policy.maxPixels ||
                result.bytes > adapter.policy.maxBytes
            )
                throw new Error(
                    'The image upload returned invalid or unsupported metadata.',
                );
            let added: string | undefined;
            const committed = controller.pageChange(
                targetPage,
                'Inserted image',
                (p) => {
                    const layer = p.layers.find((l) => l.id === targetLayer);
                    if (!layer || layer.locked || !layer.visible)
                        throw new Error(
                            'The destination layer is no longer editable.',
                        );
                    const image = newNode(
                        'image',
                        targetLayer,
                        Math.max(20, p.width / 2 - 140),
                        Math.max(20, p.height / 2 - 90),
                    );
                    image.w = Math.min(360, p.width * 0.6);
                    image.h = Math.max(
                        1,
                        (image.w * result.height) / result.width,
                    );
                    image.imageFileId = result.fileId;
                    if (image.h > p.height * 0.7) {
                        image.w = Math.max(
                            1,
                            (image.w * p.height * 0.7) / image.h,
                        );
                        image.h = p.height * 0.7;
                    }
                    p.nodes.push(image);
                    added = image.id;
                },
                'insert-image',
            );
            if (committed && added) {
                setPageId(targetPage);
                setSelection([added]);
                setEdgeId(undefined);
            }
        } catch (error) {
            if (mounted.current && !abort.signal.aborted)
                controller.report(error);
        } finally {
            uploads.current.delete(abort);
            if (mounted.current) setUploading(uploads.current.size > 0);
        }
    };
    const importJson = async (file: File) => {
        try {
            if (!props.capabilities.importJson || !controller.editable) return;
            if (file.size > 262144)
                throw new Error(
                    'Use a diagram JSON file smaller than 256 KiB.',
                );
            const parsed: unknown = JSON.parse(await file.text());
            if (!mounted.current) return;
            assertDiagramSource(parsed);
            const normalized =
                'schema_version' in parsed
                    ? parsed
                    : upgradeLegacyDiagram(parsed);
            if (
                normalized.pages.some((p) =>
                    p.nodes.some(
                        (n) =>
                            !props.capabilities.allowedShapes.includes(n.type),
                    ),
                )
            )
                throw new Error(
                    'This host does not support every imported shape.',
                );
            const currentImages = controller.current.current.pages.flatMap(
                (p) =>
                    p.nodes.flatMap((n) =>
                        n.imageFileId === null ? [] : [n.imageFileId],
                    ),
            );
            const imported = remapDiagramForImport(normalized, {
                imageFileIds: new Map(currentImages.map((id) => [id, id])),
            });
            if (
                controller.commit(
                    'Imported diagram pages',
                    (d) => d.pages.push(...imported.pages),
                    'import',
                )
            )
                setPage(imported.pages[0].id);
        } catch (error) {
            if (mounted.current) controller.report(error);
        }
    };
    const deliverExport = (blob: Blob, filename: string) => {
        if (!mounted.current) return;
        if (exportUrl.current) URL.revokeObjectURL(exportUrl.current);
        const url = URL.createObjectURL(blob);
        exportUrl.current = url;
        setExportLink({ url, filename, bytes: blob.size });
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        (
            document.activeElement?.closest('[role="dialog"]') ?? document.body
        ).append(link);
        link.click();
        link.remove();
    };
    const exportDrawing = async (format: 'svg' | 'png' | 'jpeg' | 'print') => {
        try {
            if (
                !svg.current ||
                !(format === 'png' || format === 'jpeg'
                    ? props.capabilities.exportRaster
                    : props.capabilities.exportSvg)
            )
                return;
            if (
                page.nodes.some(
                    (n) =>
                        n.type === 'image' &&
                        n.imageFileId !== null &&
                        visible(n, page) &&
                        page.layers.some(
                            (l) => l.id === n.layerId && l.print,
                        ) &&
                        images.get(n.imageFileId)?.status !== 'ready',
                )
            )
                throw new Error(
                    'Wait for each visible image to load before exporting.',
                );
            const blobs = new Map(
                [...images.values()].flatMap((image) =>
                    image.url && image.blob
                        ? [[image.url, image.blob] as const]
                        : [],
                ),
            );
            const text = await serializeSvg(svg.current, blobs),
                name = safeFilename(value.title);
            if (!mounted.current) return;
            if (format === 'svg')
                deliverExport(
                    new Blob([text], { type: 'image/svg+xml' }),
                    `${name}.svg`,
                );
            else if (format === 'print') {
                const frame = document.createElement('iframe');
                frame.title = 'Diagram print view';
                frame.style.cssText =
                    'position:fixed;width:1px;height:1px;left:-9999px;border:0';
                frame.onload = () => {
                    frame.contentWindow?.addEventListener(
                        'afterprint',
                        () => frame.remove(),
                        { once: true },
                    );
                    frame.contentWindow?.print();
                    setTimeout(() => frame.remove(), 60000);
                };
                frame.srcdoc = `<!doctype html><html><head><title>${name}</title><style>@page{size:${page.orientation};margin:12mm}body{margin:0}svg{width:100%;height:auto}</style></head><body>${text}</body></html>`;
                document.body.append(frame);
            } else
                await downloadRaster(
                    text,
                    `${name}.${format === 'jpeg' ? 'jpg' : 'png'}`,
                    format === 'jpeg' ? 'image/jpeg' : 'image/png',
                    page.width,
                    page.height,
                    deliverExport,
                );
        } catch (error) {
            controller.report(error);
        }
    };
    const palette = (
        <div className="ds-stencils">
            <div className="ds-search">
                <Search size={15} />
                <Input
                    aria-label="Search shapes"
                    placeholder="Search shapes…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </div>
            {STENCIL_GROUPS.map((group) => {
                const shapes = group.shapes.filter(
                    ([kind, name]) =>
                        props.capabilities.allowedShapes.includes(kind) &&
                        `${name} ${kind} ${group.name}`
                            .toLowerCase()
                            .includes(search.toLowerCase()),
                );
                if (!shapes.length) return null;
                const open = !!search || family === group.name;
                return (
                    <section key={group.name}>
                        <Button
                            type="button"
                            variant="ghost"
                            className="ds-stencil-heading"
                            aria-expanded={open}
                            onClick={() => setFamily(open ? '' : group.name)}
                        >
                            {group.name}
                            <ChevronRight size={14} />
                        </Button>
                        {open && (
                            <>
                                <p>{group.desc}</p>
                                <div className="ds-stencil-grid">
                                    {shapes.map(([kind, label], index) => (
                                        // eslint-disable-next-line no-restricted-syntax -- Stencil tiles use native dragging and the drawing library's grid layout.
                                        <button
                                            key={`${kind}-${index}`}
                                            type="button"
                                            disabled={!controller.editable}
                                            draggable={controller.editable}
                                            aria-label={`Add ${label}`}
                                            onDragStart={(event) =>
                                                event.dataTransfer.setData(
                                                    'application/x-diagram-shape',
                                                    JSON.stringify({
                                                        kind,
                                                        label,
                                                    }),
                                                )
                                            }
                                            onClick={() =>
                                                addShape(kind, label)
                                            }
                                        >
                                            <svg
                                                viewBox="-4 -4 88 54"
                                                aria-hidden="true"
                                            >
                                                <ShapeGraphic
                                                    thumbnail
                                                    node={{
                                                        ...newNode(
                                                            kind,
                                                            activeLayer.id,
                                                            0,
                                                            0,
                                                        ),
                                                        w: 80,
                                                        h: 46,
                                                    }}
                                                />
                                            </svg>
                                            <span>{label}</span>
                                        </button>
                                    ))}
                                </div>
                            </>
                        )}
                    </section>
                );
            })}
        </div>
    );
    const inspector = (
        <Inspector
            node={node}
            edge={edge}
            page={page}
            enabled={edge ? edgeEditable : canEditSelection}
            patchNode={patchNode}
            patchEdge={patchEdge}
        />
    );
    const modalTitle: Record<Exclude<Modal, null>, string> = {
        file: 'Drawing file',
        templates: 'Start with an editable example',
        pages: 'Pages and drawing setup',
        inspector: 'Format selection',
        data: 'Shape data',
        validation: 'Check this drawing',
        text: 'Edit shape text',
        shapes: 'Shape library',
    };
    return (
        <section
            ref={root}
            className={`ds-studio ${props.className ?? ''}`}
            data-compact={compact}
            data-focus={focus}
            onKeyDown={onKeyDown}
            aria-label="Diagram studio"
        >
            <input
                ref={imageInput}
                type="file"
                accept="image/png,image/jpeg"
                hidden
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    e.target.value = '';
                    if (file) void uploadImage(file);
                }}
            />
            <input
                ref={jsonInput}
                type="file"
                accept=".json,application/json"
                hidden
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    e.target.value = '';
                    if (file) void importJson(file);
                }}
            />
            {!focus && (
                <>
                    <header className="ds-heading">
                        <div className="ds-heading-title">
                            <Waypoints size={25} />
                            <div>
                                <span>Diagram studio</span>
                                <strong>{value.title}</strong>
                            </div>
                        </div>
                        <div className="ds-button-row">
                            <Command
                                icon={Undo2}
                                label="Undo"
                                disabled={!controller.canUndo}
                                onClick={controller.undo}
                            />
                            <Command
                                icon={Redo2}
                                label="Redo"
                                disabled={!controller.canRedo}
                                onClick={controller.redo}
                            />
                            {props.hostActions}
                        </div>
                    </header>
                    <nav className="ds-tabs" aria-label="Drawing commands">
                        <Button
                            type="button"
                            className="ds-file"
                            onClick={() => setModal('file')}
                        >
                            File
                        </Button>
                        {tabs.map((name) => (
                            // eslint-disable-next-line no-restricted-syntax -- Ribbon tabs use the drawing workspace's compact tab layout.
                            <button
                                type="button"
                                key={name}
                                aria-pressed={tab === name}
                                onClick={() => setTab(name)}
                            >
                                {name}
                            </button>
                        ))}
                    </nav>
                    <div className="ds-ribbon">
                        {tab === 'Home' && (
                            <>
                                <div className="ds-ribbon-group">
                                    <Command
                                        icon={MousePointer2}
                                        active={tool === 'select'}
                                        onClick={() => setTool('select')}
                                    >
                                        Select
                                    </Command>
                                    <Command
                                        icon={Waypoints}
                                        active={tool === 'connect'}
                                        disabled={!controller.editable}
                                        onClick={() => {
                                            setTool('connect');
                                            setConnectFrom(undefined);
                                        }}
                                    >
                                        Connector
                                    </Command>
                                    <Command
                                        icon={Hand}
                                        active={tool === 'pan'}
                                        onClick={() => setTool('pan')}
                                    >
                                        Pan
                                    </Command>
                                </div>
                                <div className="ds-ribbon-group">
                                    <Command
                                        icon={Copy}
                                        disabled={!canEditSelection}
                                        onClick={copy}
                                    >
                                        Copy
                                    </Command>
                                    <Command
                                        icon={Copy}
                                        disabled={
                                            !controller.editable ||
                                            !clipboard.current
                                        }
                                        onClick={paste}
                                    >
                                        Paste
                                    </Command>
                                    <Command
                                        icon={Trash2}
                                        disabled={
                                            !(canEditSelection || edgeEditable)
                                        }
                                        onClick={remove}
                                    >
                                        Delete
                                    </Command>
                                </div>
                                <div className="ds-ribbon-group">
                                    <Command
                                        icon={Type}
                                        disabled={!canEditSelection}
                                        onClick={() => setModal('text')}
                                    >
                                        Edit text
                                    </Command>
                                    <Command
                                        icon={Bold}
                                        disabled={!canEditSelection}
                                        active={node?.style.bold}
                                        onClick={() =>
                                            node &&
                                            patchNode({
                                                style: {
                                                    ...node.style,
                                                    bold: !node.style.bold,
                                                },
                                            })
                                        }
                                    >
                                        Bold
                                    </Command>
                                    <Command
                                        icon={Settings2}
                                        disabled={!node && !edge}
                                        onClick={() => setModal('inspector')}
                                    >
                                        Format
                                    </Command>
                                </div>
                            </>
                        )}
                        {tab === 'Insert' && (
                            <>
                                <Command
                                    icon={Plus}
                                    onClick={() => setModal('shapes')}
                                >
                                    Shapes
                                </Command>
                                <Command
                                    icon={Grid2X2}
                                    disabled={!controller.editable}
                                    active={tool === 'rectangle'}
                                    onClick={() => setTool('rectangle')}
                                >
                                    Rectangle
                                </Command>
                                <Command
                                    icon={Pencil}
                                    disabled={!controller.editable}
                                    active={tool === 'ink'}
                                    onClick={() => setTool('ink')}
                                >
                                    Pen
                                </Command>
                                <Command
                                    icon={Type}
                                    disabled={!controller.editable}
                                    onClick={() => addShape('text', 'Text')}
                                >
                                    Text
                                </Command>
                                <Command
                                    icon={ImagePlus}
                                    disabled={
                                        !controller.editable ||
                                        !props.capabilities.importImage ||
                                        !props.raster?.uploadRaster ||
                                        uploading
                                    }
                                    onClick={() => imageInput.current?.click()}
                                >
                                    {uploading ? 'Uploading…' : 'Image'}
                                </Command>
                                <Command
                                    icon={LayoutTemplate}
                                    disabled={
                                        !props.capabilities.pages ||
                                        !controller.editable
                                    }
                                    onClick={() => setModal('templates')}
                                >
                                    Templates
                                </Command>
                                <Command
                                    icon={Layers}
                                    disabled={!controller.editable}
                                    onClick={() => group(true)}
                                >
                                    Container
                                </Command>
                            </>
                        )}
                        {tab === 'Design' && (
                            <>
                                <Command
                                    icon={Settings2}
                                    onClick={() => setModal('pages')}
                                >
                                    Page setup
                                </Command>
                                {[
                                    {
                                        name: 'Violet',
                                        stroke: '#6855cf',
                                        fill: '#f0ecfc',
                                    },
                                    {
                                        name: 'Teal',
                                        stroke: '#247e77',
                                        fill: '#e9f5f0',
                                    },
                                    {
                                        name: 'Amber',
                                        stroke: '#956719',
                                        fill: '#fff4da',
                                    },
                                    {
                                        name: 'Neutral',
                                        stroke: '#697488',
                                        fill: '#eef1f5',
                                    },
                                ].map((theme) => (
                                    <Button
                                        key={theme.name}
                                        type="button"
                                        variant="outline"
                                        disabled={!controller.editable}
                                        onClick={() =>
                                            changePage(
                                                'Applied colour theme',
                                                (p) =>
                                                    p.nodes
                                                        .filter(
                                                            (n) =>
                                                                (selection.length
                                                                    ? selection.includes(
                                                                          n.id,
                                                                      )
                                                                    : true) &&
                                                                editable(n, p),
                                                        )
                                                        .forEach((n) => {
                                                            n.style.stroke =
                                                                theme.stroke;
                                                            if (
                                                                n.style.fill !==
                                                                    'none' &&
                                                                n.type !==
                                                                    'image'
                                                            )
                                                                n.style.fill =
                                                                    theme.fill;
                                                        }),
                                            )
                                        }
                                    >
                                        <span
                                            className="ds-swatch"
                                            style={{
                                                background: theme.fill,
                                                borderColor: theme.stroke,
                                            }}
                                        />
                                        {theme.name}
                                    </Button>
                                ))}
                                <Command
                                    icon={LayoutTemplate}
                                    onClick={() => setModal('templates')}
                                >
                                    Templates
                                </Command>
                            </>
                        )}
                        {tab === 'Arrange' && (
                            <>
                                <Command
                                    icon={Group}
                                    disabled={!canEditSelection}
                                    onClick={() => group()}
                                >
                                    Group
                                </Command>
                                <Command
                                    icon={Ungroup}
                                    disabled={!canEditSelection}
                                    onClick={() =>
                                        changePage('Ungrouped selection', (p) =>
                                            ungroupSelection(p, selection),
                                        )
                                    }
                                >
                                    Ungroup
                                </Command>
                                {(
                                    [
                                        ['left', AlignLeft],
                                        ['center', AlignCenter],
                                        ['right', AlignRight],
                                        ['top', ArrowUpToLine],
                                        ['bottom', ArrowDownToLine],
                                    ] as const
                                ).map(([kind, icon]) => (
                                    <Command
                                        key={kind}
                                        icon={icon}
                                        label={`Align ${kind}`}
                                        disabled={
                                            !canEditSelection ||
                                            chosen.length < 2
                                        }
                                        onClick={() => align(kind)}
                                    />
                                ))}
                                <Command
                                    disabled={
                                        !canEditSelection || chosen.length < 3
                                    }
                                    onClick={() => align('horizontal')}
                                >
                                    Distribute ↔
                                </Command>
                                <Command
                                    disabled={
                                        !canEditSelection || chosen.length < 3
                                    }
                                    onClick={() => align('vertical')}
                                >
                                    Distribute ↕
                                </Command>
                                <Command
                                    disabled={!canEditSelection}
                                    onClick={() => order(true)}
                                >
                                    Bring forward
                                </Command>
                                <Command
                                    disabled={!canEditSelection}
                                    onClick={() => order(false)}
                                >
                                    Send back
                                </Command>
                                <Command
                                    icon={Copy}
                                    disabled={!canEditSelection}
                                    onClick={duplicate}
                                >
                                    Duplicate
                                </Command>
                            </>
                        )}
                        {tab === 'Data' && (
                            <>
                                <Command
                                    icon={Upload}
                                    disabled={!controller.editable}
                                    onClick={() => setModal('data')}
                                >
                                    Link CSV data
                                </Command>
                                <Command
                                    icon={Waypoints}
                                    disabled={
                                        !controller.editable ||
                                        !props.capabilities.pages
                                    }
                                    onClick={() => setModal('data')}
                                >
                                    Organisation chart
                                </Command>
                                <Command
                                    icon={Settings2}
                                    onClick={() => {
                                        setInspectorTab('data');
                                        setRightOpen(true);
                                    }}
                                >
                                    Shape properties
                                </Command>
                            </>
                        )}
                        {tab === 'Process' && (
                            <>
                                <Command
                                    icon={CheckCheck}
                                    onClick={() => setModal('validation')}
                                >
                                    Check drawing
                                </Command>
                                <span className="ds-ribbon-note">
                                    Check structure, labels and connections.
                                </span>
                            </>
                        )}
                        {tab === 'Review' && (
                            <span className="ds-ribbon-note">
                                Save and review this drawing with the document’s
                                review controls above.
                            </span>
                        )}
                        {tab === 'View' && (
                            <>
                                <Command icon={Maximize2} onClick={fit}>
                                    Fit page
                                </Command>
                                <Command
                                    icon={PanelLeftClose}
                                    active={leftOpen}
                                    onClick={() => setLeftOpen((v) => !v)}
                                >
                                    Shapes
                                </Command>
                                <Command
                                    icon={PanelRightClose}
                                    active={rightOpen}
                                    onClick={() => setRightOpen((v) => !v)}
                                >
                                    Inspector
                                </Command>
                                <Command
                                    active={rulers}
                                    onClick={() => setRulers((v) => !v)}
                                >
                                    Rulers
                                </Command>
                                <Command
                                    active={minimap}
                                    onClick={() => setMinimap((v) => !v)}
                                >
                                    Minimap
                                </Command>
                                <Command
                                    icon={Maximize2}
                                    onClick={() => setFocus(true)}
                                >
                                    Focus mode
                                </Command>
                            </>
                        )}
                    </div>
                </>
            )}
            <div className="ds-context-bar">
                <div className="ds-button-row">
                    <Command
                        icon={Plus}
                        label="Open shape library"
                        onClick={() => setModal('shapes')}
                    />
                    <span>{page.name}</span>
                    <Command
                        icon={Settings2}
                        label="Manage pages"
                        onClick={() => setModal('pages')}
                    />
                </div>
                <div className="ds-button-row">
                    <label className="ds-check">
                        <input
                            type="checkbox"
                            checked={page.grid.enabled}
                            disabled={!controller.editable}
                            onChange={(e) =>
                                changePage('Updated grid', (p) => {
                                    p.grid.enabled = e.target.checked;
                                })
                            }
                        />
                        Grid
                    </label>
                    <label className="ds-check">
                        <input
                            type="checkbox"
                            checked={page.grid.snap}
                            disabled={!controller.editable}
                            onChange={(e) =>
                                changePage('Updated snapping', (p) => {
                                    p.grid.snap = e.target.checked;
                                })
                            }
                        />
                        Snap
                    </label>
                    <Command
                        icon={Settings2}
                        label="Format selected object"
                        onClick={() => setModal('inspector')}
                    />
                    {focus && (
                        <Command icon={X} onClick={() => setFocus(false)}>
                            Exit focus
                        </Command>
                    )}
                </div>
            </div>
            <div
                className="ds-layout"
                data-left={leftOpen && !focus}
                data-right={rightOpen && !focus}
            >
                {leftOpen && !focus && (
                    <aside className="ds-panel ds-left">
                        <header>
                            <strong>Shapes</strong>
                            <Command
                                icon={X}
                                label="Close shape library"
                                onClick={() => setLeftOpen(false)}
                            />
                        </header>
                        {palette}
                    </aside>
                )}
                <main className="ds-canvas-area">
                    <div
                        ref={viewport}
                        className="ds-viewport"
                        data-tool={tool}
                        onScroll={(e) =>
                            setScroll({
                                x: e.currentTarget.scrollLeft,
                                y: e.currentTarget.scrollTop,
                            })
                        }
                        onDragOver={(e) => {
                            if (
                                controller.editable &&
                                e.dataTransfer.types.includes(
                                    'application/x-diagram-shape',
                                )
                            )
                                e.preventDefault();
                        }}
                        onDrop={(e) => {
                            if (!controller.editable) return;
                            e.preventDefault();
                            try {
                                const data: unknown = JSON.parse(
                                    e.dataTransfer.getData(
                                        'application/x-diagram-shape',
                                    ),
                                );
                                if (
                                    typeof data === 'object' &&
                                    data &&
                                    'kind' in data &&
                                    'label' in data &&
                                    typeof data.kind === 'string' &&
                                    typeof data.label === 'string' &&
                                    props.capabilities.allowedShapes.includes(
                                        data.kind as DiagramShapeKind,
                                    )
                                )
                                    addShape(
                                        data.kind as DiagramShapeKind,
                                        data.label.slice(0, 160),
                                        point(e),
                                    );
                            } catch {
                                controller.setMessage(
                                    'Choose a shape from the library.',
                                );
                            }
                        }}
                    >
                        <div
                            className="ds-paper-wrap"
                            style={{
                                width: page.width * zoom + 72,
                                minHeight: page.height * zoom + 72,
                            }}
                        >
                            {rulers && (
                                <div
                                    className="ds-ruler"
                                    style={{ width: page.width * zoom }}
                                >
                                    {Array.from(
                                        {
                                            length:
                                                Math.floor(page.width / 100) +
                                                1,
                                        },
                                        (_, i) => (
                                            <span
                                                key={i}
                                                style={{ left: i * 100 * zoom }}
                                            >
                                                {i * 100}
                                            </span>
                                        ),
                                    )}
                                </div>
                            )}
                            <svg
                                ref={svg}
                                className="ds-paper"
                                style={{
                                    width: page.width * zoom,
                                    height: page.height * zoom,
                                }}
                                viewBox={`0 0 ${page.width} ${page.height}`}
                                role="group"
                                aria-label={`${value.title} drawing canvas`}
                                tabIndex={0}
                                onPointerDown={onCanvasDown}
                                onPointerMove={onMove}
                                onPointerUp={(e) => finishGesture(e)}
                                onPointerCancel={(e) => finishGesture(e, true)}
                            >
                                <title>
                                    {value.title} — {page.name}
                                </title>
                                <DiagramScene
                                    page={page}
                                    images={images}
                                    grid
                                    renderNode={(n, graphic) => (
                                        <g
                                            key={n.id}
                                            role="button"
                                            tabIndex={0}
                                            aria-label={`${n.type}: ${n.text || 'Untitled shape'}`}
                                            aria-pressed={selection.includes(
                                                n.id,
                                            )}
                                            onFocus={() => {
                                                if (!selection.includes(n.id)) {
                                                    setSelection(
                                                        selectionForNode(
                                                            page,
                                                            n,
                                                        ),
                                                    );
                                                    setEdgeId(undefined);
                                                }
                                            }}
                                            onPointerDown={(e) =>
                                                onNodeDown(e, n)
                                            }
                                            onDoubleClick={(e) => {
                                                e.stopPropagation();
                                                if (
                                                    controller.editable &&
                                                    editable(n, page)
                                                ) {
                                                    setSelection([n.id]);
                                                    setEdgeId(undefined);
                                                    setModal('text');
                                                }
                                            }}
                                        >
                                            {graphic}
                                            {selection.includes(n.id) && (
                                                <rect
                                                    data-editor-only="true"
                                                    className="ds-selection-outline"
                                                    x={n.x - 3}
                                                    y={n.y - 3}
                                                    width={n.w + 6}
                                                    height={n.h + 6}
                                                    transform={`rotate(${n.rotation} ${n.x + n.w / 2} ${n.y + n.h / 2})`}
                                                    fill="none"
                                                    stroke="#7c62d9"
                                                    strokeWidth={1.7 / zoom}
                                                    strokeDasharray={`${5 / zoom} ${3 / zoom}`}
                                                    pointerEvents="none"
                                                />
                                            )}
                                        </g>
                                    )}
                                    renderEdge={(id, path, graphic) => (
                                        <g
                                            key={id}
                                            role="button"
                                            tabIndex={0}
                                            aria-label={`Connector: ${page.edges.find((e) => e.id === id)?.label || 'Unlabelled'}`}
                                            aria-pressed={edgeId === id}
                                            onPointerDown={(e) => {
                                                if (tool !== 'select') return;
                                                e.stopPropagation();
                                                setEdgeId(id);
                                                setSelection([]);
                                            }}
                                            onFocus={() => {
                                                setEdgeId(id);
                                                setSelection([]);
                                            }}
                                        >
                                            {graphic}
                                            <path
                                                data-editor-only="true"
                                                d={path}
                                                stroke={
                                                    edgeId === id
                                                        ? '#a898ee'
                                                        : 'transparent'
                                                }
                                                strokeOpacity={
                                                    edgeId === id ? 0.35 : 0
                                                }
                                                strokeWidth={14 / zoom}
                                                fill="none"
                                            />
                                        </g>
                                    )}
                                >
                                    {marquee && (
                                        <rect
                                            data-editor-only="true"
                                            {...{
                                                x: marquee.x,
                                                y: marquee.y,
                                                width: marquee.w,
                                                height: marquee.h,
                                            }}
                                            fill="#8c74db"
                                            fillOpacity=".12"
                                            stroke="#7c62d9"
                                            strokeWidth={1 / zoom}
                                        />
                                    )}
                                    {node &&
                                        chosen.length === 1 &&
                                        canEditSelection && (
                                            <g
                                                data-editor-only="true"
                                                transform={`translate(${node.x} ${node.y}) rotate(${node.rotation} ${node.w / 2} ${node.h / 2})`}
                                            >
                                                <rect
                                                    role="button"
                                                    aria-label="Resize selected shape"
                                                    tabIndex={0}
                                                    x={node.w - 6 / zoom}
                                                    y={node.h - 6 / zoom}
                                                    width={12 / zoom}
                                                    height={12 / zoom}
                                                    fill="#ffffff"
                                                    stroke="#7c62d9"
                                                    strokeWidth={1.5 / zoom}
                                                    onPointerDown={(e) => {
                                                        e.stopPropagation();
                                                        beginGesture(
                                                            e,
                                                            'resize',
                                                            [node.id],
                                                            node,
                                                        );
                                                    }}
                                                    onKeyDown={(e) => {
                                                        if (
                                                            e.key ===
                                                                'ArrowRight' ||
                                                            e.key ===
                                                                'ArrowDown'
                                                        ) {
                                                            e.stopPropagation();
                                                            e.preventDefault();
                                                            patchNode(
                                                                e.key ===
                                                                    'ArrowRight'
                                                                    ? {
                                                                          w:
                                                                              node.w +
                                                                              10,
                                                                      }
                                                                    : {
                                                                          h:
                                                                              node.h +
                                                                              10,
                                                                      },
                                                            );
                                                        }
                                                    }}
                                                />
                                                <line
                                                    x1={node.w / 2}
                                                    y1={0}
                                                    x2={node.w / 2}
                                                    y2={-24 / zoom}
                                                    stroke="#7c62d9"
                                                    strokeWidth={1 / zoom}
                                                />
                                                <circle
                                                    role="button"
                                                    aria-label="Rotate selected shape"
                                                    tabIndex={0}
                                                    cx={node.w / 2}
                                                    cy={-28 / zoom}
                                                    r={6 / zoom}
                                                    fill="#ffffff"
                                                    stroke="#7c62d9"
                                                    strokeWidth={1.5 / zoom}
                                                    onPointerDown={(e) => {
                                                        e.stopPropagation();
                                                        beginGesture(
                                                            e,
                                                            'rotate',
                                                            [node.id],
                                                            node,
                                                        );
                                                    }}
                                                    onKeyDown={(e) => {
                                                        if (
                                                            e.key ===
                                                                'ArrowLeft' ||
                                                            e.key ===
                                                                'ArrowRight'
                                                        ) {
                                                            e.stopPropagation();
                                                            e.preventDefault();
                                                            patchNode({
                                                                rotation:
                                                                    (node.rotation +
                                                                        (e.key ===
                                                                        'ArrowRight'
                                                                            ? 15
                                                                            : -15)) %
                                                                    360,
                                                            });
                                                        }
                                                    }}
                                                />
                                            </g>
                                        )}
                                </DiagramScene>
                            </svg>
                        </div>
                    </div>
                    {connectFrom && (
                        <div className="ds-canvas-hint">
                            Choose a destination shape · Escape to cancel
                        </div>
                    )}
                    {minimap && (
                        <svg
                            className="ds-minimap"
                            viewBox={`0 0 ${page.width} ${page.height}`}
                            role="button"
                            tabIndex={0}
                            aria-label="Page overview. Click to move around the drawing."
                            onPointerDown={(e) => {
                                const rect =
                                    e.currentTarget.getBoundingClientRect();
                                if (viewport.current) {
                                    viewport.current.scrollLeft =
                                        ((e.clientX - rect.left) / rect.width) *
                                            page.width *
                                            zoom -
                                        viewport.current.clientWidth / 2;
                                    viewport.current.scrollTop =
                                        ((e.clientY - rect.top) / rect.height) *
                                            page.height *
                                            zoom -
                                        viewport.current.clientHeight / 2;
                                }
                            }}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') fit();
                            }}
                        >
                            <rect
                                width={page.width}
                                height={page.height}
                                fill={page.background}
                            />
                            {page.nodes
                                .filter((n) => visible(n, page))
                                .map((n) => (
                                    <rect
                                        key={n.id}
                                        x={n.x}
                                        y={n.y}
                                        width={n.w}
                                        height={n.h}
                                        fill={
                                            n.style.fill === 'none'
                                                ? '#ede9f5'
                                                : n.style.fill
                                        }
                                        stroke="#83749f"
                                        strokeWidth="4"
                                    />
                                ))}
                            <rect
                                x={Math.max(0, (scroll.x - 36) / zoom)}
                                y={Math.max(0, (scroll.y - 36) / zoom)}
                                width={Math.min(
                                    page.width,
                                    (viewport.current?.clientWidth ?? 500) /
                                        zoom,
                                )}
                                height={Math.min(
                                    page.height,
                                    (viewport.current?.clientHeight ?? 400) /
                                        zoom,
                                )}
                                fill="#8c74db"
                                fillOpacity=".1"
                                stroke="#6855cf"
                                strokeWidth="6"
                            />
                        </svg>
                    )}
                </main>
                {rightOpen && !focus && (
                    <aside className="ds-panel ds-right">
                        <header>
                            <strong>
                                {edge
                                    ? 'Connector'
                                    : chosen.length > 1
                                      ? `${chosen.length} shapes`
                                      : node
                                        ? node.type
                                        : 'Inspector'}
                            </strong>
                            <Command
                                icon={X}
                                label="Close inspector"
                                onClick={() => setRightOpen(false)}
                            />
                        </header>
                        <div className="ds-panel-tabs">
                            {(['format', 'layers', 'data'] as const).map(
                                (name) => (
                                    // eslint-disable-next-line no-restricted-syntax -- Inspector tabs share the drawing workspace's tab styling.
                                    <button
                                        key={name}
                                        type="button"
                                        aria-pressed={inspectorTab === name}
                                        onClick={() => setInspectorTab(name)}
                                    >
                                        {name.charAt(0).toUpperCase() +
                                            name.slice(1)}
                                    </button>
                                ),
                            )}
                        </div>
                        <div className="ds-panel-body">
                            {inspectorTab === 'format' ? (
                                inspector
                            ) : inspectorTab === 'layers' ? (
                                <LayerTools
                                    page={page}
                                    activeLayerId={activeLayer.id}
                                    setActiveLayer={setLayerId}
                                    enabled={controller.editable}
                                    changePage={changePage}
                                />
                            ) : (
                                <DataTools
                                    page={page}
                                    node={node}
                                    enabled={canEditSelection}
                                    patchNode={patchNode}
                                    changePage={changePage}
                                    addPage={(p) => {
                                        if (
                                            controller.commit(
                                                'Created organisation chart',
                                                (d) => d.pages.push(p),
                                            )
                                        )
                                            setPage(p.id);
                                    }}
                                    canAddPage={
                                        props.capabilities.pages &&
                                        controller.editable
                                    }
                                    report={controller.report}
                                />
                            )}
                        </div>
                    </aside>
                )}
            </div>
            <div className="ds-page-strip">
                <div className="ds-page-tabs">
                    {value.pages.map((p, i) => (
                        // eslint-disable-next-line no-restricted-syntax -- Page tabs contain their own numbered page indicator.
                        <button
                            type="button"
                            key={p.id}
                            aria-pressed={p.id === page.id}
                            onClick={() => setPage(p.id)}
                        >
                            <span>{i + 1}</span>
                            {p.name}
                        </button>
                    ))}
                    {props.capabilities.pages && (
                        <Command
                            icon={Plus}
                            label="Add page"
                            disabled={!controller.editable}
                            onClick={() => {
                                const created = blankPage(
                                    `Page ${value.pages.length + 1}`,
                                );
                                if (
                                    controller.commit('Added page', (d) =>
                                        d.pages.push(created),
                                    )
                                )
                                    setPage(created.id);
                            }}
                        />
                    )}
                </div>
                <Command
                    icon={Settings2}
                    label="Manage drawing pages"
                    onClick={() => setModal('pages')}
                />
            </div>
            <footer className="ds-status">
                <span aria-live="polite">
                    {controller.message ||
                        `${page.nodes.length} shapes · ${page.edges.length} connectors`}
                </span>
                <div className="ds-button-row">
                    <Command
                        icon={ZoomOut}
                        label="Zoom out"
                        onClick={() => setZoom((z) => Math.max(0.15, z / 1.2))}
                    />
                    {/* eslint-disable-next-line no-restricted-syntax -- The status-bar zoom readout is also the compact fit control. */}
                    <button
                        type="button"
                        onClick={fit}
                        aria-label="Fit drawing to window"
                    >
                        {Math.round(zoom * 100)}%
                    </button>
                    <Command
                        icon={ZoomIn}
                        label="Zoom in"
                        onClick={() => setZoom((z) => Math.min(3, z * 1.2))}
                    />
                </div>
            </footer>
            <Dialog
                open={modal !== null}
                onOpenChange={(open) => {
                    if (!open) setModal(null);
                }}
            >
                <DialogContent
                    className={`ds-dialog ${modal === 'templates' || modal === 'pages' ? 'ds-dialog-wide' : ''}`}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {modal ? modalTitle[modal] : ''}
                        </DialogTitle>
                        <DialogDescription>
                            {modal === 'templates'
                                ? 'Examples add an editable page to this drawing. Labels are illustrative.'
                                : modal === 'file'
                                  ? 'Drawing data stays within the document’s save and review flow.'
                                  : 'Changes update this drawing and can be undone.'}
                        </DialogDescription>
                    </DialogHeader>
                    {modal === 'shapes' && palette}
                    {modal === 'inspector' && inspector}
                    {modal === 'text' && node && (
                        <>
                            <DraftField
                                label="Shape text"
                                multiline
                                value={node.text}
                                disabled={!canEditSelection}
                                onCommit={(text) =>
                                    patchNode({ text: text || null })
                                }
                            />
                            <Button
                                type="button"
                                onClick={() => setModal(null)}
                            >
                                Done
                            </Button>
                        </>
                    )}
                    {modal === 'templates' && (
                        <div className="ds-template-grid">
                            {STARTERS.map((starter) => (
                                // eslint-disable-next-line no-restricted-syntax -- Template choices are visual preview tiles in the drawing library.
                                <button
                                    key={starter.id}
                                    type="button"
                                    disabled={
                                        !controller.editable ||
                                        !props.capabilities.pages
                                    }
                                    onClick={() => {
                                        const created = createStarterPage(
                                            starter.id,
                                        );
                                        if (
                                            controller.commit(
                                                'Added template page',
                                                (d) => d.pages.push(created),
                                            )
                                        )
                                            setPage(created.id);
                                    }}
                                >
                                    <LayoutTemplate size={27} />
                                    <strong>{starter.name}</strong>
                                    <span>{starter.family}</span>
                                    <p>{starter.description}</p>
                                </button>
                            ))}
                        </div>
                    )}
                    {modal === 'pages' && (
                        <PageTools
                            value={value}
                            page={page}
                            enabled={controller.editable}
                            canManage={props.capabilities.pages}
                            commit={controller.commit}
                            changePage={changePage}
                            onPage={setPage}
                        />
                    )}
                    {modal === 'data' && (
                        <DataTools
                            page={page}
                            node={node}
                            enabled={controller.editable}
                            patchNode={patchNode}
                            changePage={changePage}
                            addPage={(p) => {
                                if (
                                    controller.commit(
                                        'Created organisation chart',
                                        (d) => d.pages.push(p),
                                    )
                                )
                                    setPage(p.id);
                            }}
                            canAddPage={
                                props.capabilities.pages && controller.editable
                            }
                            report={controller.report}
                        />
                    )}
                    {modal === 'validation' && (
                        <ValidationTools
                            value={value}
                            page={page}
                            onSelect={(id) => {
                                setSelection([id]);
                                setEdgeId(undefined);
                                setModal(null);
                            }}
                        />
                    )}
                    {modal === 'file' && (
                        <div className="ds-file-actions">
                            <DraftField
                                label="Diagram title"
                                value={value.title}
                                disabled={!controller.editable}
                                onCommit={(title) =>
                                    controller.commit(
                                        'Renamed drawing',
                                        (d) => {
                                            d.title = title;
                                        },
                                    )
                                }
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={
                                    !props.capabilities.importJson ||
                                    !controller.editable
                                }
                                onClick={() => jsonInput.current?.click()}
                            >
                                <Upload size={17} />
                                Import diagram pages
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={
                                    !props.capabilities.importImage ||
                                    !props.raster?.uploadRaster ||
                                    !controller.editable ||
                                    uploading
                                }
                                onClick={() => imageInput.current?.click()}
                            >
                                <ImagePlus size={17} />
                                Insert PNG or JPEG
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={!props.capabilities.exportJson}
                                onClick={() =>
                                    downloadJson(
                                        controller.current.current,
                                        deliverExport,
                                    )
                                }
                            >
                                <Download size={17} />
                                Export editable JSON
                            </Button>
                            {(['svg', 'png', 'jpeg', 'print'] as const).map(
                                (format) => (
                                    <Button
                                        key={format}
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            format === 'png' ||
                                            format === 'jpeg'
                                                ? !props.capabilities
                                                      .exportRaster
                                                : !props.capabilities.exportSvg
                                        }
                                        onClick={() =>
                                            void exportDrawing(format)
                                        }
                                    >
                                        <Download size={17} />
                                        {format === 'print'
                                            ? 'Print / save PDF'
                                            : `Export ${format.toUpperCase()}`}
                                    </Button>
                                ),
                            )}
                            <div aria-live="polite">
                                {exportLink && (
                                    <p>
                                        Export ready (
                                        {Math.ceil(exportLink.bytes / 1024)}{' '}
                                        KB).{' '}
                                        <a
                                            href={exportLink.url}
                                            download={exportLink.filename}
                                        >
                                            Download {exportLink.filename}
                                        </a>
                                    </p>
                                )}
                            </div>
                            <p>
                                JSON images reference document files. Importing
                                images into another document requires authorized
                                file mapping.
                            </p>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
