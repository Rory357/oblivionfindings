import type { DiagramNodeV2 } from '@/lib/diagram-studio/contract';
const fonts = {
    sans: 'Instrument Sans, Segoe UI, sans-serif',
    serif: 'Georgia, serif',
    mono: 'Consolas, monospace',
};
const dashes = { solid: '', dashed: '6 4', dotted: '2 4' };
export function ShapeGraphic({
    node,
    thumbnail = false,
    imageSrc,
}: {
    node: DiagramNodeV2;
    thumbnail?: boolean;
    imageSrc?: string;
}) {
    const n = {
        ...node,
        ...node.style,
        w: node.type === 'image' ? node.w : Math.max(40, node.w),
        h: node.type === 'image' ? node.h : Math.max(40, node.h),
        font: fonts[node.style.font],
        dash: dashes[node.style.dash],
    };
    const { w, h, type } = n;
    const fill = n.fill || '#ffffff',
        stroke = n.stroke || '#83749f';
    const base = {
        fill,
        stroke,
        strokeWidth: n.strokeWidth ?? 1.5,
        strokeDasharray: n.dash || undefined,
        strokeLinejoin: 'round' as const,
    };
    let body;
    switch (type) {
        case 'text':
            body = null;
            break;
        case 'diamond':
        case 'decision':
        case 'gateway':
        case 'parallel':
        case 'milestone':
            body = (
                <>
                    <path
                        {...base}
                        d={`M ${w / 2} 0 L ${w} ${h / 2} ${w / 2} ${h} 0 ${h / 2} Z`}
                    />
                    {['gateway', 'parallel'].includes(type) && (
                        <path
                            d={
                                type === 'gateway'
                                    ? `M ${w * 0.38} ${h * 0.38} L ${w * 0.62} ${h * 0.62} M ${w * 0.62} ${h * 0.38} L ${w * 0.38} ${h * 0.62}`
                                    : `M ${w * 0.3} ${h / 2} H ${w * 0.7} M ${w / 2} ${h * 0.3} V ${h * 0.7}`
                            }
                            fill="none"
                            stroke={stroke}
                            strokeWidth="2.5"
                        />
                    )}
                </>
            );
            break;
        case 'terminator':
        case 'state':
        case 'usecase':
            body = <rect {...base} width={w} height={h} rx={h / 2} />;
            break;
        case 'ellipse':
        case 'event':
        case 'end':
        case 'intermediate':
            body = (
                <>
                    <ellipse
                        {...base}
                        cx={w / 2}
                        cy={h / 2}
                        rx={w / 2}
                        ry={h / 2}
                    />
                    {type === 'intermediate' && (
                        <ellipse
                            fill="none"
                            stroke={stroke}
                            cx={w / 2}
                            cy={h / 2}
                            rx={w / 2 - 5}
                            ry={h / 2 - 5}
                        />
                    )}
                </>
            );
            break;
        case 'database':
        case 'tank':
            body = (
                <>
                    <path
                        {...base}
                        d={`M 0 12 C 0 -4 ${w} -4 ${w} 12 V ${h - 12} C ${w} ${h + 4} 0 ${h + 4} 0 ${h - 12} Z`}
                    />
                    <path
                        d={`M 0 12 C 0 28 ${w} 28 ${w} 12`}
                        stroke={stroke}
                        fill="none"
                    />
                </>
            );
            break;
        case 'document':
            body = (
                <path
                    {...base}
                    d={`M 0 0 H ${w} V ${h - 10} C ${w * 0.65} ${h - 28} ${w * 0.38} ${h + 14} 0 ${h - 5} Z`}
                />
            );
            break;
        case 'data':
            body = (
                <path {...base} d={`M 18 0 H ${w} L ${w - 18} ${h} H 0 Z`} />
            );
            break;
        case 'manual':
            body = (
                <path {...base} d={`M 0 0 H ${w} L ${w - 18} ${h} H 18 Z`} />
            );
            break;
        case 'offpage':
            body = (
                <path
                    {...base}
                    d={`M 0 0 H ${w} V ${h * 0.7} L ${w / 2} ${h} 0 ${h * 0.7} Z`}
                />
            );
            break;
        case 'delay':
            body = (
                <path
                    {...base}
                    d={`M 0 0 H ${w * 0.6} C ${w * 1.14} 0 ${w * 1.14} ${h} ${w * 0.6} ${h} H 0 Z`}
                />
            );
            break;
        case 'note':
            body = (
                <>
                    <path
                        {...base}
                        d={`M 0 0 H ${w - 16} L ${w} 16 V ${h} H 0 Z`}
                    />
                    <path
                        d={`M ${w - 16} 0 V 16 H ${w}`}
                        stroke={stroke}
                        fill="none"
                    />
                </>
            );
            break;
        case 'cloud':
            body = (
                <path
                    {...base}
                    d={`M ${w * 0.18} ${h * 0.85} C ${-w * 0.07} ${h * 0.85} ${-w * 0.03} ${h * 0.3} ${w * 0.2} ${h * 0.35} C ${w * 0.2} ${-h * 0.08} ${w * 0.69} ${-h * 0.12} ${w * 0.72} ${h * 0.23} C ${w * 1.03} ${h * 0.1} ${w * 1.12} ${h * 0.84} ${w * 0.83} ${h * 0.85} Z`}
                />
            );
            break;
        case 'lane':
        case 'pool':
            body = (
                <>
                    <rect {...base} width={w} height={h} />
                    <rect
                        x="0"
                        y="0"
                        width={Math.min(w * 0.085, 75)}
                        height={h}
                        fill={stroke}
                        fillOpacity=".06"
                        stroke="none"
                    />
                    <path
                        d={`M ${Math.min(w * 0.085, 75)} 0 V ${h}`}
                        stroke={stroke}
                        strokeOpacity=".25"
                    />
                </>
            );
            break;
        case 'container':
        case 'list':
        case 'package':
            body = (
                <>
                    <rect {...base} width={w} height={h} rx="10" />
                    <path
                        d={`M 0 34 H ${w}`}
                        stroke={stroke}
                        strokeOpacity=".3"
                    />
                </>
            );
            break;
        case 'phase':
            body = (
                <>
                    <rect {...base} width={w} height={h} fillOpacity=".1" />
                    <path
                        d={`M ${w / 2} 30 V ${h}`}
                        stroke={stroke}
                        strokeDasharray="5 5"
                    />
                </>
            );
            break;
        case 'server':
        case 'rack':
            body = (
                <>
                    <rect {...base} width={w} height={h} rx="5" />
                    <rect
                        x="10"
                        y="10"
                        width="23"
                        height={h - 20}
                        rx="3"
                        fill={stroke}
                        fillOpacity=".12"
                    />
                    {[0, 1, 2].map((i) => (
                        <g key={i}>
                            <path
                                d={`M 15 ${17 + i * 12} h 13`}
                                stroke={stroke}
                            />
                            <circle
                                cx="18"
                                cy={20 + i * 12}
                                r="1"
                                fill={stroke}
                            />
                        </g>
                    ))}
                </>
            );
            break;
        case 'switch':
        case 'router':
        case 'wifi':
        case 'firewall':
        case 'laptop':
            body = (
                <>
                    <rect {...base} width={w} height={h} rx="7" />
                    {type === 'switch' ? (
                        Array.from({ length: 7 }, (_, i) => (
                            <rect
                                key={i}
                                x={15 + i * 9}
                                y={h - 14}
                                width="5"
                                height="4"
                                fill={stroke}
                            />
                        ))
                    ) : type === 'firewall' ? (
                        <path
                            d="M 12 10 H 35 V 30 H 12 Z M 12 17 H 35 M 12 24 H 35 M 23 10 V 17 M 17 17 V 24 M 28 17 V 24 M 23 24 V 30"
                            fill="none"
                            stroke={stroke}
                        />
                    ) : type === 'wifi' ? (
                        <path
                            d="M 12 19 Q 25 5 38 19 M 17 24 Q 25 15 33 24 M 22 29 Q 25 25 28 29"
                            fill="none"
                            stroke={stroke}
                            strokeWidth="2"
                        />
                    ) : type === 'laptop' ? (
                        <path
                            d="M 12 9 H 35 V 26 H 12 Z M 8 30 H 39"
                            fill="none"
                            stroke={stroke}
                        />
                    ) : (
                        <path
                            d="M 12 18 H 35 M 29 13 L 35 18 29 23 M 18 23 L 12 28 18 33 M 12 28 H 35"
                            fill="none"
                            stroke={stroke}
                        />
                    )}
                </>
            );
            break;
        case 'person':
        case 'position':
        case 'team':
            body = (
                <>
                    <rect {...base} width={w} height={h} rx="8" />
                    <rect
                        x="0"
                        y="0"
                        width="5"
                        height={h}
                        rx="2"
                        fill={stroke}
                    />
                    <circle
                        cx="25"
                        cy={h / 2 - 8}
                        r="7"
                        fill={stroke}
                        fillOpacity=".15"
                        stroke={stroke}
                    />
                    <path
                        d={`M 14 ${h / 2 + 13} Q 14 ${h / 2} 25 ${h / 2} Q 36 ${h / 2} 36 ${h / 2 + 13}`}
                        fill="none"
                        stroke={stroke}
                    />
                </>
            );
            break;
        case 'actor':
            body = (
                <>
                    <circle cx={w / 2} cy={h * 0.15} r={h * 0.12} {...base} />
                    <path
                        d={`M ${w / 2} ${h * 0.28} V ${h * 0.65} M ${w * 0.2} ${h * 0.4} H ${w * 0.8} M ${w / 2} ${h * 0.65} L ${w * 0.2} ${h} M ${w / 2} ${h * 0.65} L ${w * 0.8} ${h}`}
                        stroke={stroke}
                        fill="none"
                    />
                </>
            );
            break;
        case 'class':
        case 'entity':
        case 'interface':
            body = (
                <>
                    <rect {...base} width={w} height={h} rx="5" />
                    <rect
                        width={w}
                        height="38"
                        fill={stroke}
                        fillOpacity=".08"
                        rx="5"
                    />
                </>
            );
            break;
        case 'component':
            body = (
                <>
                    <rect {...base} width={w} height={h} rx="3" />
                    <path
                        d={`M ${w - 30} 8 h 20 v 23 h -20 Z M ${w - 35} 12 h 10 v 5 h -10 Z M ${w - 35} 22 h 10 v 5 h -10 Z`}
                        fill={fill}
                        stroke={stroke}
                    />
                </>
            );
            break;
        case 'lifeline':
            body = (
                <>
                    <rect {...base} width={w} height="38" />
                    <path
                        d={`M ${w / 2} 38 V ${h}`}
                        stroke={stroke}
                        strokeDasharray="5 5"
                    />
                </>
            );
            break;
        case 'room':
        case 'wall':
        case 'window':
        case 'desk':
        case 'taskbar':
            body = (
                <rect
                    {...base}
                    width={w}
                    height={h}
                    rx={['desk', 'taskbar'].includes(type) ? 5 : 0}
                />
            );
            break;
        case 'door':
            body = (
                <>
                    <path
                        d={`M 0 ${h} V 0 M 0 ${h} A ${w} ${h} 0 0 1 ${w} 0`}
                        fill="none"
                        stroke={stroke}
                        strokeWidth="2"
                    />
                    <path
                        d={`M 0 0 H ${w}`}
                        stroke={stroke}
                        strokeDasharray="3 3"
                    />
                </>
            );
            break;
        case 'stairs':
            body = (
                <>
                    <rect {...base} width={w} height={h} />
                    {Array.from({ length: 7 }, (_, i) => (
                        <path
                            key={i}
                            d={`M 0 ${(h * (i + 1)) / 8} H ${w}`}
                            stroke={stroke}
                        />
                    ))}
                </>
            );
            break;
        case 'tree':
        case 'chair':
            body = (
                <>
                    <ellipse
                        {...base}
                        cx={w / 2}
                        cy={h / 2}
                        rx={w / 2}
                        ry={h / 2}
                    />
                    <path
                        d={`M ${w * 0.2} ${h / 2} H ${w * 0.8} M ${w / 2} ${h * 0.2} V ${h * 0.8}`}
                        stroke={stroke}
                    />
                </>
            );
            break;
        case 'dimension':
            body = (
                <path
                    d={`M 0 0 V ${h} M ${w} 0 V ${h} M 0 ${h / 2} H ${w} M 8 ${h / 2 - 4} L 0 ${h / 2} 8 ${h / 2 + 4} M ${w - 8} ${h / 2 - 4} L ${w} ${h / 2} ${w - 8} ${h / 2 + 4}`}
                    stroke={stroke}
                    fill="none"
                />
            );
            break;
        case 'pump':
        case 'motor':
            body = (
                <>
                    <ellipse
                        {...base}
                        cx={w / 2}
                        cy={h / 2}
                        rx={w / 2}
                        ry={h / 2}
                    />
                    <path
                        d={`M ${w * 0.23} ${h * 0.23} L ${w * 0.78} ${h / 2} ${w * 0.23} ${h * 0.78} Z`}
                        stroke={stroke}
                        fill="none"
                    />
                </>
            );
            break;
        case 'valve':
            body = <path {...base} d={`M 0 0 L ${w} ${h} V 0 L 0 ${h} Z`} />;
            break;
        case 'resistor':
            body = (
                <path
                    d={`M 0 ${h / 2} H ${w * 0.2} l ${w * 0.08} ${-h * 0.3} ${w * 0.1} ${h * 0.6} ${w * 0.1} ${-h * 0.6} ${w * 0.1} ${h * 0.6} ${w * 0.1} ${-h * 0.6} ${w * 0.08} ${h * 0.3} H ${w}`}
                    fill="none"
                    stroke={stroke}
                    strokeWidth="2"
                />
            );
            break;
        case 'ground':
            body = (
                <path
                    d={`M ${w / 2} 0 V ${h * 0.5} M 0 ${h * 0.5} H ${w} M ${w * 0.2} ${h * 0.7} H ${w * 0.8} M ${w * 0.4} ${h * 0.9} H ${w * 0.6}`}
                    fill="none"
                    stroke={stroke}
                    strokeWidth="2"
                />
            );
            break;
        case 'funnel':
            body = (
                <path
                    {...base}
                    d={`M 0 0 H ${w} L ${w * 0.6} ${h * 0.6} V ${h} H ${w * 0.4} V ${h * 0.6} Z`}
                />
            );
            break;
        case 'browser':
        case 'phone':
            body = (
                <>
                    <rect {...base} width={w} height={h} rx="8" />
                    <path d={`M 0 22 H ${w}`} stroke={stroke} />
                    <circle cx="12" cy="11" r="3" fill={stroke} />
                    <circle cx="23" cy="11" r="3" fill={stroke} />
                </>
            );
            break;
        case 'ink':
            body = (
                <polyline
                    points={n.points
                        .map((p) => `${p.x * w},${p.y * h}`)
                        .join(' ')}
                    fill="none"
                    stroke={stroke}
                    strokeWidth={n.strokeWidth ?? 2}
                    strokeLinecap="round"
                />
            );
            break;
        case 'image':
            body = imageSrc ? (
                <image
                    href={imageSrc}
                    width={w}
                    height={h}
                    preserveAspectRatio="xMidYMid meet"
                />
            ) : (
                <g>
                    <rect {...base} width={w} height={h} fill="#f2f0f7" />
                    <text
                        x={w / 2}
                        y={h / 2}
                        textAnchor="middle"
                        fill="#605775"
                        fontSize="14"
                    >
                        Image unavailable
                    </text>
                </g>
            );
            break;
        default:
            body = (
                <>
                    <rect
                        {...base}
                        width={w}
                        height={h}
                        rx={['task', 'process'].includes(type) ? 8 : 3}
                    />
                    {type === 'subprocess' && (
                        <>
                            <path
                                d={`M 10 0 V ${h} M ${w - 10} 0 V ${h}`}
                                stroke={stroke}
                            />
                            <rect
                                x={w / 2 - 6}
                                y={h - 15}
                                width="12"
                                height="10"
                                fill={fill}
                                stroke={stroke}
                            />
                            <path
                                d={`M ${w / 2 - 3} ${h - 10} H ${w / 2 + 3} M ${w / 2} ${h - 13} V ${h - 7}`}
                                stroke={stroke}
                            />
                        </>
                    )}
                </>
            );
    }
    const text = n.text || '';
    const fontSize = n.fontSize || 17;
    const special = [
        'person',
        'position',
        'team',
        'server',
        'rack',
        'router',
        'firewall',
        'laptop',
        'wifi',
    ].includes(type);
    const width = w - (special ? 55 : 22);
    const max = Math.max(6, Math.floor(width / (fontSize * 0.53)));
    const lines = text.split('\n').flatMap((line) => {
        if (line.length <= max) return [line];
        const arr: string[] = [];
        let s = '';
        for (const word of line.split(' ')) {
            if ((s + ' ' + word).trim().length > max && s) {
                arr.push(s);
                s = word;
            } else s += (s ? ' ' : '') + word;
        }
        if (s) arr.push(s);
        return arr;
    });
    const topLabel = ['container', 'package', 'phase', 'list', 'room'].includes(
            type,
        ),
        lane = ['lane', 'pool'].includes(type);
    let labelY = topLabel
        ? 23
        : (h - (lines.length - 1) * fontSize * 1.26) / 2 + fontSize * 0.35;
    if (['class', 'entity', 'interface'].includes(type)) labelY = 26;
    return (
        <g opacity={n.opacity} transform={`scale(${node.w / w} ${node.h / h})`}>
            {body}
            {!thumbnail && text && (
                <g pointerEvents="none">
                    <text
                        fill={n.color || '#302b48'}
                        fontSize={lane ? 12 : fontSize}
                        fontFamily={n.font}
                        fontWeight={n.bold || lane || topLabel ? 600 : 400}
                        fontStyle={n.italic ? 'italic' : 'normal'}
                        textDecoration={n.underline ? 'underline' : undefined}
                        textAnchor={
                            lane
                                ? 'middle'
                                : n.align === 'left'
                                  ? 'start'
                                  : n.align === 'right'
                                    ? 'end'
                                    : 'middle'
                        }
                        transform={
                            lane
                                ? `translate(37 ${h / 2}) rotate(-90)`
                                : undefined
                        }
                    >
                        {lines.map((line, i) => (
                            <tspan
                                key={i}
                                x={
                                    lane
                                        ? 0
                                        : n.align === 'left'
                                          ? 12
                                          : n.align === 'right'
                                            ? w - 12
                                            : special
                                              ? w / 2 + 16
                                              : w / 2
                                }
                                y={lane ? i * 15 : labelY + i * fontSize * 1.26}
                            >
                                {line}
                            </tspan>
                        ))}
                    </text>
                </g>
            )}
            {n.dataGraphic && !thumbnail && (
                <g>
                    <rect
                        x="9"
                        y={h - 8}
                        width={w - 18}
                        height="4"
                        rx="2"
                        fill="#dedde4"
                    />
                    <rect
                        x="9"
                        y={h - 8}
                        width={
                            ((w - 18) *
                                Math.min(
                                    100,
                                    Math.max(
                                        0,
                                        Number(
                                            node.properties.find(
                                                (p) =>
                                                    p.key === node.dataGraphic,
                                            )?.value,
                                        ) || 0,
                                    ),
                                )) /
                            100
                        }
                        height="4"
                        rx="2"
                        fill="#247e77"
                    />
                    <text
                        x={w - 8}
                        y="-7"
                        fontSize="12"
                        textAnchor="end"
                        fill="#247e77"
                    >
                        {
                            node.properties.find(
                                (p) => p.key === node.dataGraphic,
                            )?.value
                        }
                        %
                    </text>
                </g>
            )}
        </g>
    );
}
