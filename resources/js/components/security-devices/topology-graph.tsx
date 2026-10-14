import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import {
    Expand,
    Minus,
    Network,
    Plus,
    Router,
    Server,
    Wifi,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
    type KeyboardEvent,
} from 'react';
import {
    edgeKey,
    NODE_HEIGHT,
    NODE_WIDTH,
    topologyLayout,
} from './topology-layout';
import type { TopologyEdge, TopologyNode } from './topology-types';

export function TopologyGraph({
    nodes,
    edges,
    selectedNode,
    selectedEdge,
    path,
    onNode,
    onEdge,
}: {
    nodes: TopologyNode[];
    edges: TopologyEdge[];
    selectedNode: number | null;
    selectedEdge: string | null;
    path: { nodes: number[]; edges: string[] } | null;
    onNode: (id: number) => void;
    onEdge: (id: string) => void;
}) {
    const layout = useMemo(() => topologyLayout(nodes, edges), [nodes, edges]);
    const byId = new Map(layout.nodes.map((node) => [node.id, node]));
    const lanes = useMemo(() => {
        const groups = new Map<string, TopologyEdge[]>();
        for (const edge of layout.edges) {
            const key =
                Math.min(edge.parentId, edge.childId) +
                ':' +
                Math.max(edge.parentId, edge.childId);
            const group = groups.get(key) ?? [];
            group.push(edge);
            groups.set(key, group);
        }
        const offsets = new Map<string, number>();
        for (const group of groups.values())
            group.forEach((edge, index) =>
                offsets.set(
                    edgeKey(edge),
                    (index - (group.length - 1) / 2) * 40,
                ),
            );
        return offsets;
    }, [layout.edges]);
    const viewport = useRef<HTMLDivElement>(null);
    const drag = useRef<{
        id: number;
        x: number;
        y: number;
        left: number;
        top: number;
    } | null>(null);
    const [size, setSize] = useState({ width: 900, height: 520 });
    const [view, setView] = useState({ scale: 1, x: 24, y: 24 });
    const marker = useId().replaceAll(':', '');
    const fit = () => {
        const width = viewport.current?.clientWidth || size.width;
        const scale = Math.max(
            0.02,
            Math.min(
                1,
                (width - 32) / layout.width,
                (size.height - 32) / layout.height,
            ),
        );
        setView({
            scale,
            x: (width - layout.width * scale) / 2,
            y: (size.height - layout.height * scale) / 2,
        });
    };
    useEffect(() => {
        const element = viewport.current;
        if (!element) return;
        let measuredWidth = 0;
        let measuredHeight = 0;
        const resize = () => {
            const width = element.clientWidth || 900,
                height = element.clientHeight || 520;
            // ResizeObserver repeats the initial measurement. Do not let that
            // notification undo the selected device's readable position.
            if (width === measuredWidth && height === measuredHeight) return;
            measuredWidth = width;
            measuredHeight = height;
            setSize({ width, height });
            const scale = Math.max(
                0.02,
                Math.min(
                    1,
                    (width - 32) / layout.width,
                    (height - 32) / layout.height,
                ),
            );
            setView({
                scale,
                x: (width - layout.width * scale) / 2,
                y: (height - layout.height * scale) / 2,
            });
        };
        resize();
        if (typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(resize);
        observer.observe(element);
        return () => observer.disconnect();
    }, [layout.width, layout.height]);
    function zoom(
        factor: number,
        anchor = { x: size.width / 2, y: size.height / 2 },
    ) {
        setView((current) => {
            const scale = Math.max(0.02, Math.min(2, current.scale * factor)),
                ratio = scale / current.scale;
            return {
                scale,
                x: anchor.x - (anchor.x - current.x) * ratio,
                y: anchor.y - (anchor.y - current.y) * ratio,
            };
        });
    }
    function keyPan(event: KeyboardEvent<HTMLDivElement>) {
        if (event.target !== event.currentTarget) return;
        const step = event.shiftKey ? 120 : 48;
        const delta: Record<string, [number, number]> = {
            ArrowLeft: [step, 0],
            ArrowRight: [-step, 0],
            ArrowUp: [0, step],
            ArrowDown: [0, -step],
        };
        if (delta[event.key]) {
            event.preventDefault();
            const [x, y] = delta[event.key];
            setView((v) => ({ ...v, x: v.x + x, y: v.y + y }));
        }
        if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            zoom(1.25);
        }
        if (event.key === '-') {
            event.preventDefault();
            zoom(0.8);
        }
        if (event.key === 'Home') {
            event.preventDefault();
            fit();
        }
    }
    const reveal = useCallback(
        (node: { x: number; y: number }) => {
            setView((current) => {
                // Focused labels must remain readable even when a large graph
                // was fitted into an overview. Keep the whole node on screen.
                const maximum = Math.max(
                    0.1,
                    Math.min(
                        2,
                        (size.width - 32) / NODE_WIDTH,
                        (size.height - 32) / NODE_HEIGHT,
                    ),
                );
                const scale = Math.min(maximum, Math.max(1, current.scale));
                if (
                    scale === current.scale &&
                    node.x * scale + current.x >= 0 &&
                    (node.x + NODE_WIDTH) * scale + current.x <= size.width &&
                    node.y * scale + current.y >= 0 &&
                    (node.y + NODE_HEIGHT) * scale + current.y <= size.height
                )
                    return current;
                return {
                    scale,
                    x: size.width / 2 - (node.x + NODE_WIDTH / 2) * scale,
                    y: size.height / 2 - (node.y + NODE_HEIGHT / 2) * scale,
                };
            });
        },
        [size.width, size.height],
    );
    useEffect(() => {
        const node = layout.nodes.find((item) => item.id === selectedNode);
        if (node) reveal(node);
    }, [layout.nodes, selectedNode, reveal]);
    return (
        // eslint-disable-next-line no-restricted-syntax -- The diagram owns its clipped canvas, viewport and control strip.
        <div className="overflow-hidden rounded-lg border bg-background">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b p-3">
                <p className="text-caption text-muted-foreground">
                    Drag to pan · arrow keys to move · + / − to zoom
                </p>
                <div
                    className="flex items-center gap-1"
                    aria-label="Map controls"
                >
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Zoom out"
                        onClick={() => zoom(0.8)}
                    >
                        <Minus aria-hidden />
                    </Button>
                    <output
                        className="text-caption min-w-12 text-center"
                        aria-label="Map zoom"
                    >
                        {Math.round(view.scale * 100)}%
                    </output>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Zoom in"
                        onClick={() => zoom(1.25)}
                    >
                        <Plus aria-hidden />
                    </Button>
                    <Button variant="outline" onClick={fit}>
                        <Expand aria-hidden />
                        Fit map
                    </Button>
                </div>
            </div>
            <div
                ref={viewport}
                role="region"
                aria-label="Network topology map"
                tabIndex={0}
                className="relative h-[520px] touch-none overflow-hidden bg-muted/20 focus-visible:outline-2 focus-visible:outline-ring"
                onKeyDown={keyPan}
                onWheel={(event) => {
                    if (!event.ctrlKey && !event.metaKey) return;
                    event.preventDefault();
                    const r = event.currentTarget.getBoundingClientRect();
                    zoom(event.deltaY < 0 ? 1.1 : 1 / 1.1, {
                        x: event.clientX - r.left,
                        y: event.clientY - r.top,
                    });
                }}
                onPointerDown={(event) => {
                    if (
                        event.button !== 0 ||
                        (event.target as Element).closest(
                            'button,a,[role="button"]',
                        )
                    )
                        return;
                    drag.current = {
                        id: event.pointerId,
                        x: event.clientX,
                        y: event.clientY,
                        left: view.x,
                        top: view.y,
                    };
                    event.currentTarget.setPointerCapture(event.pointerId);
                }}
                onPointerMove={(event) => {
                    const start = drag.current;
                    if (start?.id === event.pointerId)
                        setView((v) => ({
                            ...v,
                            x: start.left + event.clientX - start.x,
                            y: start.top + event.clientY - start.y,
                        }));
                }}
                onPointerUp={(event) => {
                    if (drag.current?.id === event.pointerId) {
                        drag.current = null;
                        event.currentTarget.releasePointerCapture(
                            event.pointerId,
                        );
                    }
                }}
                onPointerCancel={() => {
                    drag.current = null;
                }}
            >
                <div
                    className="absolute top-0 left-0 origin-top-left"
                    style={{
                        width: layout.width,
                        height: layout.height,
                        transform:
                            'translate(' +
                            view.x +
                            'px,' +
                            view.y +
                            'px) scale(' +
                            view.scale +
                            ')',
                    }}
                >
                    <svg
                        width={layout.width}
                        height={layout.height}
                        className="absolute inset-0 overflow-visible"
                        aria-label="Observed connections"
                    >
                        <defs>
                            <marker
                                id={marker}
                                markerWidth="8"
                                markerHeight="8"
                                refX="6"
                                refY="4"
                                orient="auto-start-reverse"
                            >
                                <path
                                    d="M 0 1 L 6 4 L 0 7"
                                    fill="none"
                                    stroke="context-stroke"
                                    strokeWidth="1.5"
                                />
                            </marker>
                        </defs>
                        {layout.edges.map((edge, index) => {
                            const from = byId.get(edge.parentId)!,
                                to = byId.get(edge.childId)!;
                            const key = edgeKey(edge),
                                active =
                                    selectedEdge === key ||
                                    Boolean(path?.edges.includes(key));
                            const lane = lanes.get(key) ?? 0;
                            const forward = to.x >= from.x;
                            const x1 = from.x + (forward ? NODE_WIDTH : 0),
                                x2 = to.x + (forward ? 0 : NODE_WIDTH);
                            const y1 = from.y + NODE_HEIGHT / 2,
                                y2 = to.y + NODE_HEIGHT / 2;
                            const bend =
                                (forward ? 1 : -1) *
                                Math.max(48, Math.abs(x2 - x1) / 2);
                            const sameColumn = from.x === to.x;
                            const control =
                                from.x + NODE_WIDTH + 64 + (index % 3) * 18;
                            const d = sameColumn
                                ? 'M ' +
                                  (from.x + NODE_WIDTH) +
                                  ' ' +
                                  y1 +
                                  ' C ' +
                                  control +
                                  ' ' +
                                  (y1 - 56) +
                                  ', ' +
                                  control +
                                  ' ' +
                                  (y2 + 56) +
                                  ', ' +
                                  (to.x + NODE_WIDTH) +
                                  ' ' +
                                  y2
                                : 'M ' +
                                  x1 +
                                  ' ' +
                                  y1 +
                                  ' C ' +
                                  (x1 + bend) +
                                  ' ' +
                                  (y1 + lane) +
                                  ', ' +
                                  (x2 - bend) +
                                  ' ' +
                                  (y2 + lane) +
                                  ', ' +
                                  x2 +
                                  ' ' +
                                  y2;
                            const inferred =
                                (edge.evidenceState ?? edge.reviewState) !==
                                'reviewed';
                            return (
                                <g
                                    key={key}
                                    role="button"
                                    tabIndex={0}
                                    aria-label={
                                        'Inspect connection ' +
                                        edge.parentName +
                                        ' to ' +
                                        edge.childName +
                                        (edge.port ? ' on ' + edge.port : '')
                                    }
                                    aria-pressed={selectedEdge === key}
                                    onFocus={(event) => {
                                        if (
                                            event.currentTarget.matches(
                                                ':focus-visible',
                                            )
                                        )
                                            reveal({
                                                x: (from.x + to.x) / 2,
                                                y: (from.y + to.y) / 2,
                                            });
                                    }}
                                    className="group cursor-pointer focus:outline-none"
                                    onClick={() => onEdge(key)}
                                    onKeyDown={(event) => {
                                        if (
                                            event.key === 'Enter' ||
                                            event.key === ' '
                                        ) {
                                            event.preventDefault();
                                            onEdge(key);
                                        }
                                    }}
                                >
                                    <title>
                                        {edge.label +
                                            (edge.port
                                                ? ' · ' + edge.port
                                                : '')}
                                    </title>
                                    <path
                                        d={d}
                                        fill="none"
                                        strokeWidth={16}
                                        className="stroke-transparent group-hover:stroke-accent group-focus-visible:stroke-ring"
                                        vectorEffect="non-scaling-stroke"
                                    />
                                    <path
                                        d={d}
                                        fill="none"
                                        strokeWidth={active ? 3 : 1.5}
                                        strokeDasharray={
                                            inferred ? '6 5' : undefined
                                        }
                                        markerEnd={'url(#' + marker + ')'}
                                        className={cn(
                                            active
                                                ? 'stroke-primary'
                                                : edge.freshness?.state ===
                                                    'stale'
                                                  ? 'stroke-status-warning'
                                                  : 'stroke-muted-foreground',
                                        )}
                                        vectorEffect="non-scaling-stroke"
                                    />
                                    {edge.port && (
                                        <text
                                            x={
                                                sameColumn
                                                    ? control
                                                    : (x1 + x2) / 2
                                            }
                                            y={(y1 + y2) / 2 + lane * 0.75 - 10}
                                            textAnchor="middle"
                                            className="text-caption fill-foreground"
                                            paintOrder="stroke"
                                            stroke="var(--background)"
                                            strokeWidth={5}
                                            strokeLinejoin="round"
                                        >
                                            {edge.port.length > 30
                                                ? edge.port.slice(0, 27) + '…'
                                                : edge.port}
                                        </text>
                                    )}
                                </g>
                            );
                        })}
                    </svg>
                    {layout.nodes.map((node) => {
                        const type = node.subcategory ?? node.category ?? '';
                        const Icon = /wifi|access_point/.test(type)
                            ? Wifi
                            : /server/.test(type)
                              ? Server
                              : /router|gateway|firewall/.test(type)
                                ? Router
                                : Network;
                        const active =
                            selectedNode === node.id ||
                            Boolean(path?.nodes.includes(node.id));
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- Positioned diagram node is a selectable card with measured graph geometry.
                            <button
                                type="button"
                                key={node.id}
                                aria-label={'Inspect ' + node.name}
                                aria-pressed={selectedNode === node.id}
                                onClick={() => onNode(node.id)}
                                onFocus={(event) => {
                                    // Pointer focus precedes click: moving now can steal
                                    // the click. Selection reveals after click instead.
                                    if (
                                        event.currentTarget.matches(
                                            ':focus-visible',
                                        )
                                    )
                                        reveal(node);
                                }}
                                className={cn(
                                    'absolute flex flex-col justify-center gap-2 rounded-lg border bg-card px-4 py-3 text-left shadow-sm focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-ring',
                                    active
                                        ? 'border-primary ring-2 ring-primary/30'
                                        : 'border-border',
                                )}
                                style={{
                                    left: node.x,
                                    top: node.y,
                                    width: NODE_WIDTH,
                                    height: NODE_HEIGHT,
                                }}
                            >
                                <span className="flex w-full items-center gap-2">
                                    <Icon
                                        className="size-4 shrink-0 text-muted-foreground"
                                        aria-hidden
                                    />
                                    <span
                                        className="truncate font-medium"
                                        title={node.name}
                                    >
                                        {node.name}
                                    </span>
                                </span>
                                <span className="flex w-full items-center justify-between gap-2">
                                    <span className="text-caption truncate text-muted-foreground">
                                        {node.site ?? 'Site not assigned'}
                                    </span>
                                    <StatusBadge
                                        status={node.health ?? 'unknown'}
                                        size="sm"
                                    />
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>
            <div className="text-caption flex flex-wrap gap-x-5 gap-y-1 border-t p-3 text-muted-foreground">
                <span>Solid: reviewed link</span>
                <span>Dashed: inferred or unconfirmed</span>
                <span>Arrows: recorded relationship direction</span>
            </div>
        </div>
    );
}
