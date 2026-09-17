import type {
    DiagramNodeV2,
    DiagramPageV2,
} from '@/lib/diagram-studio/contract';
import { edgeGeometry, visible } from '@/lib/diagram-studio/geometry';
import type { DiagramRendererProps } from '@/lib/diagram-studio/host-api';
import { validateDiagramSource } from '@/lib/diagram-studio/validation';
import type { ReactNode } from 'react';
import { useId, useMemo } from 'react';
import { ShapeGraphic } from './shape-graphic';
import type { RasterImage } from './use-raster-images';
import { useRasterImages } from './use-raster-images';

export const dashArray = { solid: undefined, dashed: '6 4', dotted: '2 4' };
export function DiagramScene({
    page,
    images,
    printing = false,
    grid = false,
    renderNode,
    renderEdge,
    children,
}: {
    page: DiagramPageV2;
    images: ReadonlyMap<number, RasterImage>;
    printing?: boolean;
    grid?: boolean;
    renderNode?: (node: DiagramNodeV2, graphic: ReactNode) => ReactNode;
    renderEdge?: (
        edgeId: string,
        path: string,
        graphic: ReactNode,
    ) => ReactNode;
    children?: ReactNode;
}) {
    const scope = useId().replace(/[^a-zA-Z0-9_-]/g, '');
    const show = (element: { layerId: string }) =>
        visible(element, page) &&
        (!printing ||
            page.layers.some(
                (l) =>
                    l.id.toLowerCase() === element.layerId.toLowerCase() &&
                    l.print,
            ));
    const printable = (element: { layerId: string }) =>
        page.layers.some(
            (l) =>
                l.id.toLowerCase() === element.layerId.toLowerCase() && l.print,
        );
    const background = (node: DiagramNodeV2) =>
        ['lane', 'pool', 'container', 'list', 'phase', 'room'].includes(
            node.type,
        );
    const drawNode = (node: DiagramNodeV2) => {
        const image =
            node.imageFileId === null
                ? undefined
                : images.get(node.imageFileId);
        const graphic = (
            <g
                key={node.id}
                data-node-id={node.id}
                data-printable={printable(node)}
                transform={`translate(${node.x} ${node.y}) rotate(${node.rotation} ${node.w / 2} ${node.h / 2})`}
            >
                <ShapeGraphic node={node} imageSrc={image?.url} />
                {node.type === 'image' && image?.status === 'loading' && (
                    <text
                        x={node.w / 2}
                        y={node.h / 2 + 22}
                        textAnchor="middle"
                        fontSize="12"
                        fill="#605775"
                    >
                        Loading image…
                    </text>
                )}
            </g>
        );
        return renderNode ? renderNode(node, graphic) : graphic;
    };
    return (
        <>
            <defs>
                <pattern
                    id={`${scope}-grid`}
                    width={page.grid.size}
                    height={page.grid.size}
                    patternUnits="userSpaceOnUse"
                >
                    <path
                        d={`M ${page.grid.size} 0 H 0 V ${page.grid.size}`}
                        fill="none"
                        stroke="#d7d1e3"
                        strokeWidth="0.5"
                    />
                </pattern>
                {(['triangle', 'open', 'diamond', 'circle'] as const).map(
                    (arrow) => (
                        <marker
                            key={arrow}
                            id={`${scope}-${arrow}`}
                            viewBox="0 0 10 10"
                            refX="9"
                            refY="5"
                            markerWidth="7"
                            markerHeight="7"
                            orient="auto-start-reverse"
                        >
                            {arrow === 'circle' ? (
                                <circle
                                    cx="5"
                                    cy="5"
                                    r="3.5"
                                    fill="context-stroke"
                                />
                            ) : (
                                <path
                                    d={
                                        arrow === 'diamond'
                                            ? 'M 0 5 L 5 1 L 10 5 L 5 9 Z'
                                            : arrow === 'open'
                                              ? 'M 1 1 L 9 5 L 1 9'
                                              : 'M 1 1 L 9 5 L 1 9 Z'
                                    }
                                    fill={
                                        arrow === 'open'
                                            ? 'none'
                                            : 'context-stroke'
                                    }
                                    stroke="context-stroke"
                                    strokeWidth="1"
                                />
                            )}
                        </marker>
                    ),
                )}
            </defs>
            <rect
                width={page.width}
                height={page.height}
                fill={page.background}
            />
            {grid && page.grid.enabled && (
                <rect
                    data-editor-only="true"
                    width={page.width}
                    height={page.height}
                    fill={`url(#${scope}-grid)`}
                />
            )}
            {page.nodes.filter((n) => show(n) && background(n)).map(drawNode)}
            {page.edges.filter(show).map((edge) => {
                const from = page.nodes.find(
                        (n) => n.id.toLowerCase() === edge.from.toLowerCase(),
                    ),
                    to = page.nodes.find(
                        (n) => n.id.toLowerCase() === edge.to.toLowerCase(),
                    );
                if (!from || !to || !show(from) || !show(to)) return null;
                const geometry = edgeGeometry(edge, page.nodes);
                if (!geometry) return null;
                const graphic = (
                    <g
                        key={edge.id}
                        data-edge-id={edge.id}
                        data-printable={
                            printable(edge) && printable(from) && printable(to)
                        }
                    >
                        <path
                            d={geometry.d}
                            fill="none"
                            stroke={edge.stroke}
                            strokeWidth={edge.width}
                            strokeDasharray={dashArray[edge.dash]}
                            markerStart={
                                edge.startArrow === 'none'
                                    ? undefined
                                    : `url(#${scope}-${edge.startArrow})`
                            }
                            markerEnd={
                                edge.arrow === 'none'
                                    ? undefined
                                    : `url(#${scope}-${edge.arrow})`
                            }
                        />
                        {edge.label && (
                            <text
                                x={geometry.label.x}
                                y={geometry.label.y - 8}
                                textAnchor="middle"
                                fontFamily="Instrument Sans, Segoe UI, sans-serif"
                                fontSize="14"
                                fill="#302b48"
                                stroke={page.background}
                                strokeWidth="4"
                                paintOrder="stroke"
                                strokeLinejoin="round"
                            >
                                {edge.label}
                            </text>
                        )}
                    </g>
                );
                return renderEdge
                    ? renderEdge(edge.id, geometry.d, graphic)
                    : graphic;
            })}
            {page.nodes.filter((n) => show(n) && !background(n)).map(drawNode)}
            {children}
        </>
    );
}

export function DiagramRenderer({
    recordKey,
    value,
    pageId,
    label,
    raster,
    onIssue,
    className,
}: DiagramRendererProps) {
    const validation = useMemo(() => validateDiagramSource(value), [value]);
    const page = validation.valid
        ? (value.pages.find((p) => p.id === pageId) ?? value.pages[0])
        : undefined;
    const ids =
        page?.nodes.flatMap((n) =>
            n.imageFileId === null ? [] : [n.imageFileId],
        ) ?? [];
    const images = useRasterImages(recordKey, ids, raster, onIssue);
    if (!page)
        return (
            <div role="alert">
                This diagram cannot be displayed. Its original source has been
                retained.
            </div>
        );
    return (
        <svg
            className={className}
            viewBox={`0 0 ${page.width} ${page.height}`}
            role="img"
            aria-label={label ?? `${value.title} — ${page.name}`}
            style={{ display: 'block', width: '100%', height: 'auto' }}
        >
            <title>{label ?? `${value.title} — ${page.name}`}</title>
            <DiagramScene page={page} images={images} />
        </svg>
    );
}
