import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useId, useRef, useState } from 'react';

export type DiagramNode = {
    id: string;
    shape: 'rectangle' | 'ellipse' | 'diamond';
    text: string;
    x: number;
    y: number;
};
export type KnowledgeDiagram = {
    id: string;
    title: string;
    nodes: DiagramNode[];
    edges: { id: string; from: string; to: string; label: string | null }[];
};

export function DiagramView({
    diagram,
    selected,
    onSelect,
    onMove,
}: {
    diagram: KnowledgeDiagram;
    selected?: string;
    onSelect?: (id: string) => void;
    onMove?: (id: string, x: number, y: number) => void;
}) {
    const marker = useId().replaceAll(':', '');
    const svg = useRef<SVGSVGElement>(null);
    const drag = useRef<{ id: string; dx: number; dy: number } | null>(null);
    const position = (event: React.PointerEvent) => {
        const bounds = svg.current!.getBoundingClientRect();
        return {
            x: ((event.clientX - bounds.left) * 1000) / bounds.width,
            y: ((event.clientY - bounds.top) * 600) / bounds.height,
        };
    };
    return (
        <svg
            ref={svg}
            viewBox="0 0 1000 600"
            role={onSelect ? 'group' : 'img'}
            aria-label={diagram.title}
            className="w-full rounded-lg border border-border bg-card text-foreground"
            style={{ touchAction: onMove ? 'none' : undefined }}
            onPointerMove={(event) => {
                if (!drag.current || !onMove) return;
                const point = position(event);
                onMove(
                    drag.current.id,
                    Math.max(
                        0,
                        Math.min(840, Math.round(point.x - drag.current.dx)),
                    ),
                    Math.max(
                        0,
                        Math.min(520, Math.round(point.y - drag.current.dy)),
                    ),
                );
            }}
            onPointerUp={() => {
                drag.current = null;
            }}
            onPointerCancel={() => {
                drag.current = null;
            }}
        >
            <title>{diagram.title}</title>
            <defs>
                <marker
                    id={marker}
                    markerWidth="10"
                    markerHeight="10"
                    refX="8"
                    refY="3"
                    orient="auto"
                >
                    <path d="M0,0 L0,6 L9,3 z" fill="currentColor" />
                </marker>
            </defs>
            {diagram.edges.map((edge) => {
                const from = diagram.nodes.find(
                    (node) => node.id === edge.from,
                );
                const to = diagram.nodes.find((node) => node.id === edge.to);
                if (!from || !to) return null;
                const dx = to.x - from.x,
                    dy = to.y - from.y;
                const ratio = Math.min(
                    80 / Math.max(1, Math.abs(dx)),
                    40 / Math.max(1, Math.abs(dy)),
                );
                return (
                    <g key={edge.id}>
                        <line
                            x1={from.x + 80 + dx * ratio}
                            y1={from.y + 40 + dy * ratio}
                            x2={to.x + 80 - dx * ratio}
                            y2={to.y + 40 - dy * ratio}
                            stroke="currentColor"
                            strokeWidth="2"
                            markerEnd={`url(#${marker})`}
                        />
                        {edge.label && (
                            <text
                                x={(from.x + to.x) / 2 + 80}
                                y={(from.y + to.y) / 2 + 32}
                                textAnchor="middle"
                                fill="currentColor"
                                fontSize="14"
                            >
                                {edge.label}
                            </text>
                        )}
                    </g>
                );
            })}
            {diagram.nodes.map((node) => (
                <g
                    key={node.id}
                    transform={`translate(${node.x}, ${node.y})`}
                    role={onSelect ? 'button' : undefined}
                    tabIndex={onSelect ? 0 : undefined}
                    aria-label={`${node.shape}: ${node.text || 'Untitled shape'}`}
                    aria-pressed={onSelect ? selected === node.id : undefined}
                    className={
                        onSelect
                            ? 'cursor-move focus-visible:outline-2 focus-visible:outline-ring'
                            : undefined
                    }
                    onClick={() => onSelect?.(node.id)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            onSelect?.(node.id);
                        }
                        if (
                            onMove &&
                            [
                                'ArrowLeft',
                                'ArrowRight',
                                'ArrowUp',
                                'ArrowDown',
                            ].includes(event.key)
                        ) {
                            event.preventDefault();
                            onSelect?.(node.id);
                            onMove(
                                node.id,
                                Math.max(
                                    0,
                                    Math.min(
                                        840,
                                        node.x +
                                            (event.key === 'ArrowRight'
                                                ? 10
                                                : event.key === 'ArrowLeft'
                                                  ? -10
                                                  : 0),
                                    ),
                                ),
                                Math.max(
                                    0,
                                    Math.min(
                                        520,
                                        node.y +
                                            (event.key === 'ArrowDown'
                                                ? 10
                                                : event.key === 'ArrowUp'
                                                  ? -10
                                                  : 0),
                                    ),
                                ),
                            );
                        }
                    }}
                    onPointerDown={(event) => {
                        if (!onMove) return;
                        const point = position(event);
                        onSelect?.(node.id);
                        drag.current = {
                            id: node.id,
                            dx: point.x - node.x,
                            dy: point.y - node.y,
                        };
                        svg.current?.setPointerCapture(event.pointerId);
                    }}
                >
                    {node.shape === 'ellipse' ? (
                        <ellipse
                            cx="80"
                            cy="40"
                            rx="78"
                            ry="38"
                            fill="var(--card)"
                            stroke={
                                selected === node.id
                                    ? 'var(--primary)'
                                    : 'currentColor'
                            }
                            strokeWidth="2"
                        />
                    ) : node.shape === 'diamond' ? (
                        <polygon
                            points="80,1 159,40 80,79 1,40"
                            fill="var(--card)"
                            stroke={
                                selected === node.id
                                    ? 'var(--primary)'
                                    : 'currentColor'
                            }
                            strokeWidth="2"
                        />
                    ) : (
                        <rect
                            x="1"
                            y="1"
                            width="158"
                            height="78"
                            rx="8"
                            fill="var(--card)"
                            stroke={
                                selected === node.id
                                    ? 'var(--primary)'
                                    : 'currentColor'
                            }
                            strokeWidth="2"
                        />
                    )}
                    <text
                        x="80"
                        y="32"
                        textAnchor="middle"
                        fill="currentColor"
                        fontSize="13"
                        pointerEvents="none"
                    >
                        {(
                            node.text.match(/.{1,20}(?:\s|$)|.{1,20}/g) ?? [
                                'Shape',
                            ]
                        )
                            .slice(0, 3)
                            .map((line, i) => (
                                <tspan key={i} x="80" dy={i ? 16 : 0}>
                                    {line.trim()}
                                </tspan>
                            ))}
                    </text>
                </g>
            ))}
        </svg>
    );
}

export function KnowledgeDiagrams({
    diagrams,
    onChange,
}: {
    diagrams: KnowledgeDiagram[];
    onChange?: (diagrams: KnowledgeDiagram[]) => void;
}) {
    const [selected, setSelected] = useState('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [label, setLabel] = useState('');
    const update = (id: string, change: Partial<KnowledgeDiagram>) =>
        onChange?.(
            diagrams.map((item) =>
                item.id === id ? { ...item, ...change } : item,
            ),
        );
    return (
        <div className="space-y-5">
            {!diagrams.length && (
                <p className="text-subtle">No diagrams have been added.</p>
            )}
            {diagrams.map((diagram, index) => {
                const node = diagram.nodes.find((item) => item.id === selected);
                const editNode = (change: Partial<DiagramNode>) =>
                    update(diagram.id, {
                        nodes: diagram.nodes.map((item) =>
                            item.id === selected
                                ? { ...item, ...change }
                                : item,
                        ),
                    });
                return (
                    <section
                        key={diagram.id}
                        className="space-y-3 rounded-xl border border-border bg-card p-5"
                    >
                        {onChange ? (
                            <div className="flex items-end gap-3">
                                <div className="flex-1 space-y-2">
                                    <Label
                                        htmlFor={`diagram-title-${diagram.id}`}
                                    >
                                        Diagram title
                                    </Label>
                                    <Input
                                        id={`diagram-title-${diagram.id}`}
                                        value={diagram.title}
                                        maxLength={160}
                                        onChange={(event) =>
                                            update(diagram.id, {
                                                title: event.target.value,
                                            })
                                        }
                                    />
                                </div>
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
                            </div>
                        ) : (
                            <h3 className="text-section-title">
                                {diagram.title}
                            </h3>
                        )}
                        {onChange && (
                            <>
                                <div className="flex flex-wrap gap-2">
                                    {(
                                        [
                                            'rectangle',
                                            'ellipse',
                                            'diamond',
                                        ] as const
                                    ).map((shape) => (
                                        <Button
                                            type="button"
                                            key={shape}
                                            variant="outline"
                                            disabled={
                                                diagram.nodes.length >= 80
                                            }
                                            onClick={() => {
                                                const id = crypto.randomUUID();
                                                update(diagram.id, {
                                                    nodes: [
                                                        ...diagram.nodes,
                                                        {
                                                            id,
                                                            shape,
                                                            text: 'New shape',
                                                            x:
                                                                40 +
                                                                (diagram.nodes
                                                                    .length %
                                                                    5) *
                                                                    180,
                                                            y:
                                                                40 +
                                                                (Math.floor(
                                                                    diagram
                                                                        .nodes
                                                                        .length /
                                                                        5,
                                                                ) %
                                                                    5) *
                                                                    100,
                                                        },
                                                    ],
                                                });
                                                setSelected(id);
                                            }}
                                        >
                                            Add {shape}
                                        </Button>
                                    ))}
                                </div>
                                <p className="text-caption">
                                    Drag shapes to move them. With a shape
                                    focused, use arrow keys to move and Enter to
                                    edit its label.
                                </p>
                            </>
                        )}
                        <DiagramView
                            diagram={diagram}
                            selected={selected}
                            onSelect={onChange ? setSelected : undefined}
                            onMove={
                                onChange
                                    ? (id, x, y) =>
                                          update(diagram.id, {
                                              nodes: diagram.nodes.map(
                                                  (item) =>
                                                      item.id === id
                                                          ? { ...item, x, y }
                                                          : item,
                                              ),
                                          })
                                    : undefined
                            }
                        />
                        {onChange && node && (
                            <div className="flex items-end gap-3">
                                <div className="flex-1 space-y-2">
                                    <Label htmlFor={`node-label-${diagram.id}`}>
                                        Selected shape label
                                    </Label>
                                    <Input
                                        id={`node-label-${diagram.id}`}
                                        maxLength={160}
                                        value={node.text}
                                        onChange={(event) =>
                                            editNode({
                                                text: event.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        update(diagram.id, {
                                            nodes: diagram.nodes.filter(
                                                (item) => item.id !== selected,
                                            ),
                                            edges: diagram.edges.filter(
                                                (edge) =>
                                                    edge.from !== selected &&
                                                    edge.to !== selected,
                                            ),
                                        })
                                    }
                                >
                                    Delete shape
                                </Button>
                            </div>
                        )}
                        {onChange && (
                            <fieldset className="flex flex-wrap items-end gap-3">
                                <legend className="text-caption mb-2">
                                    Connect two shapes
                                </legend>
                                <label className="grid gap-2 text-sm">
                                    From
                                    <select
                                        className="select"
                                        aria-label={`Connector start ${index + 1}`}
                                        value={
                                            diagram.nodes.some(
                                                (item) => item.id === from,
                                            )
                                                ? from
                                                : ''
                                        }
                                        onChange={(event) =>
                                            setFrom(event.target.value)
                                        }
                                    >
                                        <option value="">Choose shape</option>
                                        {diagram.nodes.map((item) => (
                                            <option
                                                key={item.id}
                                                value={item.id}
                                            >
                                                {item.text}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-2 text-sm">
                                    To
                                    <select
                                        className="select"
                                        aria-label={`Connector end ${index + 1}`}
                                        value={
                                            diagram.nodes.some(
                                                (item) => item.id === to,
                                            )
                                                ? to
                                                : ''
                                        }
                                        onChange={(event) =>
                                            setTo(event.target.value)
                                        }
                                    >
                                        <option value="">Choose shape</option>
                                        {diagram.nodes.map((item) => (
                                            <option
                                                key={item.id}
                                                value={item.id}
                                            >
                                                {item.text}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="grid gap-2 text-sm">
                                    Connector label
                                    <Input
                                        value={label}
                                        maxLength={80}
                                        onChange={(event) =>
                                            setLabel(event.target.value)
                                        }
                                    />
                                </label>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        diagram.edges.length >= 160 ||
                                        from === to ||
                                        !diagram.nodes.some(
                                            (item) => item.id === from,
                                        ) ||
                                        !diagram.nodes.some(
                                            (item) => item.id === to,
                                        )
                                    }
                                    onClick={() => {
                                        update(diagram.id, {
                                            edges: [
                                                ...diagram.edges,
                                                {
                                                    id: crypto.randomUUID(),
                                                    from,
                                                    to,
                                                    label,
                                                },
                                            ],
                                        });
                                        setLabel('');
                                    }}
                                >
                                    Add connector
                                </Button>
                            </fieldset>
                        )}
                        {!!diagram.edges.length && (
                            <details>
                                <summary className="cursor-pointer text-sm font-medium">
                                    Connections ({diagram.edges.length})
                                </summary>
                                <ul className="space-y-2 pt-2">
                                    {diagram.edges.map((edge) => (
                                        <li
                                            key={edge.id}
                                            className="flex items-center justify-between gap-3 text-sm"
                                        >
                                            <span>
                                                {
                                                    diagram.nodes.find(
                                                        (item) =>
                                                            item.id ===
                                                            edge.from,
                                                    )?.text
                                                }{' '}
                                                →{' '}
                                                {
                                                    diagram.nodes.find(
                                                        (item) =>
                                                            item.id === edge.to,
                                                    )?.text
                                                }
                                                {edge.label
                                                    ? ` · ${edge.label}`
                                                    : ''}
                                            </span>
                                            {onChange && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        update(diagram.id, {
                                                            edges: diagram.edges.filter(
                                                                (item) =>
                                                                    item.id !==
                                                                    edge.id,
                                                            ),
                                                        })
                                                    }
                                                >
                                                    Remove connector
                                                </Button>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}
                        {!!diagram.nodes.length && (
                            <details>
                                <summary className="cursor-pointer text-sm font-medium">
                                    Shapes and full labels (
                                    {diagram.nodes.length})
                                </summary>
                                <ul className="space-y-2 pt-2">
                                    {diagram.nodes.map((item) => (
                                        <li
                                            key={item.id}
                                            className="text-sm break-words"
                                        >
                                            {item.shape}:{' '}
                                            {item.text || 'Untitled shape'}
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}
                    </section>
                );
            })}
            {onChange && (
                <Button
                    type="button"
                    variant="outline"
                    disabled={diagrams.length >= 12}
                    onClick={() =>
                        onChange([
                            ...diagrams,
                            {
                                id: crypto.randomUUID(),
                                title: `Diagram ${diagrams.length + 1}`,
                                nodes: [],
                                edges: [],
                            },
                        ])
                    }
                >
                    Create diagram
                </Button>
            )}
        </div>
    );
}
