import { Button } from '@/components/ui/button';
import {
    Check,
    Circle,
    Crosshair,
    MapPin,
    MoreHorizontal,
    MousePointer2,
    Pencil,
    Pentagon,
    Redo2,
    Trash2,
    Undo2,
} from 'lucide-react';
import React, { useEffect, useRef, useState } from 'react';
import type { Point, Zone } from './location-workspace';

type Geometry = Pick<Zone, 'shape' | 'centre' | 'radius' | 'points'>;
const clamp = (n: number, min: number, max: number) =>
    Math.max(min, Math.min(max, n));
const copy = (z: Geometry): Geometry => ({
    shape: z.shape,
    centre: [...z.centre],
    radius: z.radius,
    points: z.points.map((p) => [...p]),
});
function crossing(points: Point[]) {
    const orient = (a: Point, b: Point, c: Point) =>
        (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]);
    for (let i = 0; i < points.length; i++)
        for (let j = i + 2; j < points.length; j++) {
            if (i === 0 && j === points.length - 1) continue;
            const a = points[i],
                b = points[(i + 1) % points.length],
                c = points[j],
                d = points[(j + 1) % points.length];
            if (
                orient(a, b, c) * orient(a, b, d) <= 0 &&
                orient(c, d, a) * orient(c, d, b) <= 0
            )
                return true;
        }
    return false;
}
export function BoundaryEditor({
    zone,
    onChange,
    onReady,
    initialReady = false,
}: {
    zone: Zone;
    onChange: (g: Geometry) => void;
    onReady: (ready: boolean) => void;
    initialReady?: boolean;
}) {
    const [complete, setComplete] = useState(initialReady),
        [past, setPast] = useState<Geometry[]>([]),
        [future, setFuture] = useState<Geometry[]>([]),
        [error, setError] = useState(''),
        [vertex, setVertex] = useState<number | null>(null);
    const svg = useRef<SVGSVGElement>(null),
        drag = useRef<{
            index: number;
            start: Geometry;
            changed: boolean;
        } | null>(null);
    const ready = (v: boolean) => {
        setComplete(v);
        onReady(v);
    };
    const commit = (g: Geometry, finished = false) => {
        setPast((p) => [...p, copy(zone)]);
        setFuture([]);
        onChange(g);
        ready(finished);
        setError('');
    };
    const coords = (e: { clientX: number; clientY: number }): Point => {
        const s = svg.current!;
        const r = s.getBoundingClientRect();
        return [
            Math.round(clamp(((e.clientX - r.left) * 700) / r.width, 10, 690)),
            Math.round(clamp(((e.clientY - r.top) * 345) / r.height, 10, 335)),
        ];
    };
    const finish = () => {
        const area =
            Math.abs(
                zone.points.reduce((sum, p, i) => {
                    const q = zone.points[(i + 1) % zone.points.length];
                    return sum + p[0] * q[1] - q[0] * p[1];
                }, 0),
            ) / 2;
        if (
            zone.shape === 'polygon' &&
            (zone.points.length < 3 || crossing(zone.points) || area < 20)
        ) {
            setError(
                zone.points.length < 3
                    ? 'Add at least three corners.'
                    : area < 20
                      ? 'Draw an area with distinct corners, not a line.'
                      : 'The boundary crosses itself. Move a corner or undo the last point.',
            );
            return;
        }
        setError('');
        ready(true);
    };
    const handle = (index: number, p: Point) => (
        <circle
            key={index}
            cx={p[0]}
            cy={p[1]}
            r="7"
            className="v3-handle"
            role="button"
            tabIndex={0}
            aria-label={
                index === -1
                    ? 'Move circle centre'
                    : index === -2
                      ? 'Resize circle'
                      : `Boundary corner ${index + 1}`
            }
            aria-pressed={vertex === index}
            onFocus={() => setVertex(index)}
            onPointerDown={(e) => {
                e.stopPropagation();
                e.currentTarget.setPointerCapture(e.pointerId);
                drag.current = { index, start: copy(zone), changed: false };
                setVertex(index);
            }}
            onPointerMove={(e) => {
                if (!drag.current || drag.current.index !== index) return;
                const p = coords(e);
                drag.current.changed = true;
                ready(false);
                onChange(
                    index === -1
                        ? { ...copy(zone), centre: p }
                        : index === -2
                          ? {
                                ...copy(zone),
                                radius: Math.round(
                                    clamp(
                                        Math.hypot(
                                            p[0] - zone.centre[0],
                                            p[1] - zone.centre[1],
                                        ),
                                        15,
                                        150,
                                    ),
                                ),
                            }
                          : {
                                ...copy(zone),
                                points: zone.points.map((old, i) =>
                                    i === index ? p : old,
                                ),
                            },
                );
            }}
            onPointerUp={(e) => {
                e.stopPropagation();
                if (drag.current?.changed) {
                    setPast((h) => [...h, drag.current!.start]);
                    setFuture([]);
                }
                drag.current = null;
            }}
            onClick={(e) => {
                e.stopPropagation();
                if (index === 0 && !complete && zone.points.length >= 3)
                    finish();
            }}
            onKeyDown={(e) => {
                if (e.key === 'Enter' && index === 0) {
                    finish();
                    return;
                }
                if (
                    ![
                        'ArrowLeft',
                        'ArrowRight',
                        'ArrowUp',
                        'ArrowDown',
                    ].includes(e.key)
                )
                    return;
                e.preventDefault();
                const dx =
                        e.key === 'ArrowLeft'
                            ? -5
                            : e.key === 'ArrowRight'
                              ? 5
                              : 0,
                    dy =
                        e.key === 'ArrowUp'
                            ? -5
                            : e.key === 'ArrowDown'
                              ? 5
                              : 0;
                commit(
                    index === -2
                        ? {
                              ...copy(zone),
                              radius: clamp(zone.radius + (dx || -dy), 15, 150),
                          }
                        : index === -1
                          ? {
                                ...copy(zone),
                                centre: [
                                    clamp(p[0] + dx, 10, 690),
                                    clamp(p[1] + dy, 10, 335),
                                ],
                            }
                          : {
                                ...copy(zone),
                                points: zone.points.map((q, i) =>
                                    i === index
                                        ? [
                                              clamp(q[0] + dx, 10, 690),
                                              clamp(q[1] + dy, 10, 335),
                                          ]
                                        : q,
                                ),
                            },
                );
            }}
        />
    );
    return (
        <div className="v3-editor">
            <div className="v3-draw-toolbar">
                <div role="group" aria-label="Boundary shape">
                    <Button
                        variant={
                            zone.shape === 'polygon' ? 'default' : 'outline'
                        }
                        onClick={() =>
                            commit({
                                ...copy(zone),
                                shape: 'polygon',
                                points: [],
                            })
                        }
                    >
                        <Pentagon className="size-4" />
                        Custom boundary
                    </Button>
                    <Button
                        variant={
                            zone.shape === 'circle' ? 'default' : 'outline'
                        }
                        onClick={() =>
                            commit({ ...copy(zone), shape: 'circle' })
                        }
                    >
                        <Circle className="size-4" />
                        Circle
                    </Button>
                </div>
                <div className="v2-inline">
                    <Button
                        variant="outline"
                        aria-label="Undo boundary change"
                        disabled={!past.length}
                        onClick={() => {
                            setFuture((f) => [copy(zone), ...f]);
                            onChange(past[past.length - 1]);
                            setPast((p) => p.slice(0, -1));
                            ready(false);
                        }}
                    >
                        <Undo2 className="size-4" />
                        Undo
                    </Button>
                    <Button
                        variant="outline"
                        aria-label="Redo boundary change"
                        disabled={!future.length}
                        onClick={() => {
                            setPast((p) => [...p, copy(zone)]);
                            onChange(future[0]);
                            setFuture((f) => f.slice(1));
                            ready(false);
                        }}
                    >
                        <Redo2 className="size-4" />
                    </Button>
                </div>
            </div>
            <div className="v3-draw-help">
                <MousePointer2 className="size-4" />
                <span>
                    {complete
                        ? 'Boundary ready. Drag a handle to adjust it, then finish again.'
                        : zone.shape === 'circle'
                          ? 'Click to place the centre. Drag the outer handle to resize.'
                          : 'Click around the area to add corners. Click the first corner, or Finish boundary, to close it.'}
                </span>
                <strong>
                    {zone.shape === 'circle'
                        ? `${zone.radius} map units`
                        : `${zone.points.length} ${zone.points.length === 1 ? 'corner' : 'corners'}`}
                </strong>
            </div>
            <svg
                ref={svg}
                viewBox="0 0 700 345"
                preserveAspectRatio="none"
                className="v3-draw-canvas"
                role="group"
                aria-label="Draw safe zone on fictional map"
                onClick={(e) => {
                    if (complete) return;
                    const p = coords(e);
                    commit(
                        zone.shape === 'circle'
                            ? { ...copy(zone), centre: p }
                            : { ...copy(zone), points: [...zone.points, p] },
                    );
                }}
            >
                <rect width="700" height="345" fill="var(--muted)" />
                {Array.from({ length: 20 }, (_, i) => (
                    <rect
                        key={i}
                        x={(i % 5) * 150 + 12}
                        y={Math.floor(i / 5) * 92 + 10}
                        width="120"
                        height="65"
                        rx="9"
                        fill="var(--card)"
                        opacity=".6"
                    />
                ))}
                <path
                    d="M0 163H700M292 0V345M476 0L430 345"
                    stroke="var(--card)"
                    strokeWidth="22"
                    fill="none"
                />
                <path
                    d="M660 0Q610 170 650 345"
                    stroke="#c7d2fe"
                    strokeWidth="31"
                    opacity=".6"
                    fill="none"
                />
                {[
                    [115, 210, 'Example House'],
                    [184, 70, 'Day programme'],
                    [356, 200, 'Example Gardens'],
                    [555, 95, 'Town library'],
                ].map(([x, y, t]) => (
                    <text
                        key={t}
                        x={x}
                        y={y}
                        textAnchor="middle"
                        fill="var(--muted-foreground)"
                        fontSize="11"
                        pointerEvents="none"
                    >
                        {t}
                    </text>
                ))}
                {zone.shape === 'circle' ? (
                    <circle
                        cx={zone.centre[0]}
                        cy={zone.centre[1]}
                        r={zone.radius}
                        className="v3-drawn-zone"
                    />
                ) : (
                    <>
                        <polygon
                            points={zone.points
                                .map((p) => p.join(','))
                                .join(' ')}
                            className="v3-drawn-zone"
                            strokeDasharray={complete ? undefined : '6 4'}
                        />
                        {zone.points.map((p, i) => (
                            <text
                                key={i}
                                x={p[0] + 12}
                                y={p[1] - 12}
                                fontSize="11"
                                fill="var(--primary)"
                                pointerEvents="none"
                            >
                                {i + 1}
                            </text>
                        ))}
                    </>
                )}
                {zone.shape === 'circle' ? (
                    <>
                        {handle(-1, zone.centre)}
                        {handle(-2, [
                            zone.centre[0] + zone.radius,
                            zone.centre[1],
                        ])}
                    </>
                ) : (
                    zone.points.map((p, i) => handle(i, p))
                )}
            </svg>
            {error && (
                <div className="v2-error" role="alert">
                    {error}
                </div>
            )}
            <div className="v3-draw-bottom">
                <span className="micro">
                    Fictional map • scale is illustrative
                </span>
                <div className="v2-inline">
                    {zone.shape === 'polygon' && (
                        <Button
                            variant="ghost"
                            disabled={!zone.points.length}
                            onClick={() =>
                                commit({ ...copy(zone), points: [] })
                            }
                        >
                            <Trash2 className="size-4" />
                            Clear
                        </Button>
                    )}
                    <Button
                        variant={complete ? 'outline' : 'default'}
                        onClick={finish}
                        disabled={complete}
                    >
                        <Check className="size-4" />
                        {complete ? 'Boundary ready' : 'Finish boundary'}
                    </Button>
                </div>
            </div>
            <details className="v3-keyboard">
                <summary>Keyboard drawing & precise adjustments</summary>
                <p>
                    Use the starter rectangle, then tab to a corner and use
                    arrow keys to move it. Circle handles also support arrow
                    keys.
                </p>
                <Button
                    variant="outline"
                    onClick={() =>
                        commit({
                            ...copy(zone),
                            shape: 'polygon',
                            points: [
                                [280, 160],
                                [435, 160],
                                [435, 280],
                                [280, 280],
                            ],
                        })
                    }
                >
                    Start with rectangle
                </Button>
                {zone.shape === 'circle' && (
                    <label>
                        Circle radius{' '}
                        <input
                            aria-label="Circle radius in illustrative map units"
                            type="number"
                            min="15"
                            max="150"
                            value={zone.radius}
                            onChange={(e) =>
                                commit({
                                    ...copy(zone),
                                    radius: clamp(
                                        Number(e.target.value),
                                        15,
                                        150,
                                    ),
                                })
                            }
                        />
                    </label>
                )}
                {zone.shape === 'polygon' && vertex !== null && vertex >= 0 && (
                    <Button
                        variant="outline"
                        onClick={() => {
                            commit({
                                ...copy(zone),
                                points: zone.points.filter(
                                    (_, i) => i !== vertex,
                                ),
                            });
                            setVertex(null);
                        }}
                    >
                        Remove selected corner
                    </Button>
                )}
            </details>
        </div>
    );
}

export function MapActions({
    children,
    selected,
    limited,
    onDraw,
    onDetails,
    onEdit,
    onLocate,
}: {
    children: React.ReactNode;
    selected?: Zone;
    limited: boolean;
    onDraw: (shape: Zone['shape'], point?: Point) => void;
    onDetails: (id: string) => void;
    onEdit: (id: string) => void;
    onLocate?: () => void;
}) {
    const [menu, setMenu] = useState<{
            x: number;
            y: number;
            point?: Point;
            zone?: string;
        } | null>(null),
        ref = useRef<HTMLDivElement>(null),
        trigger = useRef<HTMLElement | null>(null);
    const close = () => {
        setMenu(null);
        trigger.current?.focus();
    };
    useEffect(() => {
        if (!menu) return;
        ref.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
        const outside = (e: PointerEvent) => {
            if (!ref.current?.contains(e.target as Node)) setMenu(null);
        };
        document.addEventListener('pointerdown', outside);
        return () => document.removeEventListener('pointerdown', outside);
    }, [menu]);
    const commands = [
        ...(!limited
            ? [
                  {
                      label: 'Draw custom safe zone here',
                      icon: Pentagon,
                      run: () => onDraw('polygon', menu?.point),
                  },
                  {
                      label: 'Add circular safe zone here',
                      icon: Circle,
                      run: () => onDraw('circle', menu?.point),
                  },
              ]
            : []),
        ...(selected
            ? [
                  {
                      label: 'View zone details',
                      icon: MapPin,
                      run: () => onDetails(menu?.zone || selected.id),
                  },
                  ...(!limited
                      ? [
                            {
                                label: 'Edit zone proposal',
                                icon: Pencil,
                                run: () => onEdit(menu?.zone || selected.id),
                            },
                        ]
                      : []),
              ]
            : []),
        ...(!limited && onLocate
            ? [{ label: 'Locate now', icon: Crosshair, run: onLocate }]
            : []),
    ];
    return (
        <div
            className="v3-map-wrap"
            tabIndex={0}
            aria-label="Map with actions"
            onContextMenu={(e) => {
                e.preventDefault();
                trigger.current = e.currentTarget;
                const s = e.currentTarget.querySelector(
                    'svg[viewBox="0 0 700 345"]',
                );
                const r = s?.getBoundingClientRect();
                const zoom = Number(s?.getAttribute('data-map-zoom') || 1);
                const point: Point | undefined = r
                    ? [
                          ((e.clientX - r.left) * 700) / r.width / zoom +
                              350 * (1 - 1 / zoom),
                          ((e.clientY - r.top) * 345) / r.height / zoom +
                              172 * (1 - 1 / zoom),
                      ]
                    : undefined;
                setMenu({
                    x: Math.min(e.clientX, window.innerWidth - 255),
                    y: Math.min(e.clientY, window.innerHeight - 270),
                    point,
                    zone:
                        (e.target as Element)
                            .closest('[data-zone-id]')
                            ?.getAttribute('data-zone-id') || selected?.id,
                });
            }}
            onKeyDown={(e) => {
                if (
                    e.key === 'ContextMenu' ||
                    (e.shiftKey && e.key === 'F10')
                ) {
                    e.preventDefault();
                    trigger.current = e.currentTarget;
                    const r = e.currentTarget.getBoundingClientRect();
                    setMenu({
                        x: r.left + 20,
                        y: Math.min(r.top + 30, window.innerHeight - 270),
                    });
                }
            }}
        >
            {children}
            <Button
                className="v3-map-actions"
                variant="outline"
                onClick={(e) => {
                    trigger.current = e.currentTarget;
                    const r = e.currentTarget.getBoundingClientRect();
                    setMenu({
                        x: Math.min(r.left, window.innerWidth - 255),
                        y: Math.min(r.bottom + 5, window.innerHeight - 270),
                    });
                }}
            >
                <MoreHorizontal className="size-4" />
                Map actions
            </Button>
            {menu && (
                <div
                    ref={ref}
                    role="menu"
                    aria-label="Map actions"
                    className="v3-context-menu"
                    style={{ left: menu.x, top: menu.y }}
                    onKeyDown={(e) => {
                        const all = Array.from(
                            ref.current!.querySelectorAll<HTMLElement>(
                                '[role="menuitem"]',
                            ),
                        );
                        const i = all.indexOf(
                            document.activeElement as HTMLElement,
                        );
                        if (e.key === 'Escape' || e.key === 'Tab') {
                            e.preventDefault();
                            close();
                        }
                        if (
                            ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(
                                e.key,
                            )
                        ) {
                            e.preventDefault();
                            all[
                                e.key === 'Home'
                                    ? 0
                                    : e.key === 'End'
                                      ? all.length - 1
                                      : (i +
                                            (e.key === 'ArrowDown' ? 1 : -1) +
                                            all.length) %
                                        all.length
                            ]?.focus();
                        }
                    }}
                >
                    <div className="v3-menu-label">Map & zone actions</div>
                    {commands.map(({ label, icon: Icon, run }) => (
                        <button
                            role="menuitem"
                            key={label}
                            onClick={() => {
                                close();
                                run();
                            }}
                        >
                            <Icon className="size-4" />
                            {label}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
