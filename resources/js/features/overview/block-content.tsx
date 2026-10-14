import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate, formatDateTime, formatTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    CalendarHeart,
    CheckCheck,
    CircleDashed,
    Clock3,
    GraduationCap,
    LockKeyhole,
    TriangleAlert,
    UsersRound,
} from 'lucide-react';
import { createContext, Fragment, useContext, useState } from 'react';
import {
    Area,
    CartesianGrid,
    ComposedChart,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import type { BlockData, BlockMeta, Row } from './types';

const colors = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
];

function RowContent({ row }: { row: Row }) {
    const date = row.date ? formatDate(row.date).split(' ') : null;
    return (
        <>
            {date ? (
                <span className="ov-date-tile" aria-hidden="true">
                    <span>{date[2]}</span>
                    <b>{date[1]}</b>
                </span>
            ) : (
                <span className="ov-row-mark">
                    {row.tone === 'critical' ? (
                        <TriangleAlert size={16} />
                    ) : row.tone === 'warning' ? (
                        <Clock3 size={16} />
                    ) : (
                        <CheckCheck size={16} />
                    )}
                </span>
            )}
            <span className="ov-row-copy">
                <strong>{row.label}</strong>
                <span>
                    {row.date
                        ? row.date.includes('T')
                            ? `${formatDateTime(row.date)}${row.end ? ` – ${formatTime(row.end)}` : ''}`
                            : formatDate(row.date)
                        : row.detail}
                </span>
            </span>
            {row.value !== undefined && (
                <b
                    className="ov-row-value"
                    aria-label={
                        row.target !== undefined ? row.detail : undefined
                    }
                >
                    {row.value}
                    {row.target !== undefined ? ` / ${row.target}` : ''}
                </b>
            )}
            {row.status && (
                <StatusBadge
                    variant={
                        row.tone === 'critical'
                            ? 'critical'
                            : row.tone === 'warning'
                              ? 'warning'
                              : 'neutral'
                    }
                >
                    {row.status}
                </StatusBadge>
            )}
            {row.href && <ArrowUpRight className="ov-row-arrow" size={15} />}
        </>
    );
}

/**
 * Opens the read-only detail panel for a row that carries facts (approved
 * mockup: rows open their details in place; the panel links on to the
 * module). Absent in previews, where rows stay inert.
 */
export const OverviewDetailContext = createContext<((row: Row) => void) | null>(
    null,
);

function SummaryItem({
    row,
    preview,
    children,
    className,
    tone,
}: {
    row: Row;
    preview: boolean;
    children: React.ReactNode;
    className: string;
    tone?: Row['tone'];
}) {
    const openDetail = useContext(OverviewDetailContext);
    if (!preview && openDetail && row.facts?.length)
        return (
            // eslint-disable-next-line no-restricted-syntax -- the whole summary row opens its detail panel
            <button
                type="button"
                className={className}
                data-tone={tone}
                aria-haspopup="dialog"
                // A short name: the row's own content can include long
                // descriptions such as 24 hourly connection checks.
                aria-label={[row.label, row.status, row.detail, 'show details']
                    .filter(Boolean)
                    .join(', ')}
                onClick={() => openDetail(row)}
            >
                {children}
            </button>
        );
    return row.href && !preview ? (
        <Link href={row.href} className={className} data-tone={tone}>
            {children}
        </Link>
    ) : (
        <div className={className} data-tone={tone}>
            {children}
        </div>
    );
}

/**
 * An outline of a block's shape for the library when it has nothing to show
 * yet — honest about the layout without inventing sample figures.
 */
export function BlockSketch({ kind }: { kind: BlockMeta['kind'] }) {
    return (
        <svg
            className="ov-sketch"
            viewBox="0 0 120 64"
            aria-hidden="true"
            data-kind={kind}
        >
            {kind === 'donut' || kind === 'progress' ? (
                <>
                    <circle
                        cx="32"
                        cy="32"
                        r="22"
                        className="ov-sketch-track"
                    />
                    <circle
                        cx="32"
                        cy="32"
                        r="22"
                        className="ov-sketch-line"
                        strokeDasharray="88 139"
                        transform="rotate(-90 32 32)"
                    />
                    {[18, 32, 46].map((y) => (
                        <rect
                            key={y}
                            x="68"
                            y={y - 3}
                            width={y === 32 ? 36 : 44}
                            height="6"
                            rx="3"
                            className="ov-sketch-bar"
                        />
                    ))}
                </>
            ) : kind === 'trend' ? (
                <>
                    {[16, 32, 48].map((y) => (
                        <line
                            key={y}
                            x1="4"
                            x2="116"
                            y1={y}
                            y2={y}
                            className="ov-sketch-grid"
                        />
                    ))}
                    <polyline
                        points="4,44 22,34 40,38 58,22 76,28 94,14 116,20"
                        className="ov-sketch-line"
                    />
                </>
            ) : kind === 'bars' ? (
                [10, 26, 42].map((y, i) => (
                    <g key={y}>
                        <rect
                            x="4"
                            y={y}
                            width="112"
                            height="8"
                            rx="4"
                            className="ov-sketch-track-fill"
                        />
                        <rect
                            x="4"
                            y={y}
                            width={[92, 64, 38][i]}
                            height="8"
                            rx="4"
                            className="ov-sketch-bar"
                        />
                    </g>
                ))
            ) : (
                [10, 28, 46].map((y) => (
                    <g key={y}>
                        <rect
                            x="4"
                            y={y}
                            width="10"
                            height="10"
                            rx="3"
                            className="ov-sketch-bar"
                        />
                        <rect
                            x="22"
                            y={y + 1}
                            width="62"
                            height="4"
                            rx="2"
                            className="ov-sketch-track-fill"
                        />
                        <rect
                            x="22"
                            y={y + 7}
                            width="40"
                            height="3"
                            rx="1.5"
                            className="ov-sketch-track-fill"
                        />
                    </g>
                ))
            )}
        </svg>
    );
}

function connectionCheckLabel(hour: NonNullable<Row['history']>[number]) {
    return `${formatDateTime(hour.at)} · ${hour.checks ? `${hour.checks} recorded checks · ${hour.state === 'healthy' ? 'all healthy' : hour.state === 'failed' ? 'failure recorded' : hour.state === 'degraded' ? 'degraded result recorded' : 'state unknown'}` : 'No checks recorded'}`;
}

/** 24 hourly connection-check windows for a site, oldest first. */
export function ConnectionHistoryStrip({ row }: { row: Row }) {
    if (!row.history?.length) return null;
    return (
        <>
            <span
                className="ov-connection-history"
                role="img"
                aria-label={`${row.label} recorded connection checks: ${row.history.map(connectionCheckLabel).join('; ')}`}
            >
                {row.history.map((hour) => (
                    <i
                        key={hour.at}
                        data-state={hour.state}
                        title={connectionCheckLabel(hour)}
                    />
                ))}
            </span>
            <span className="ov-connection-axis">
                <span>{formatDateTime(row.history[0].at)}</span>
                <span>
                    {formatTime(row.history[row.history.length - 1].at)} ·
                    current hour
                </span>
            </span>
        </>
    );
}

export function BlockContent({
    meta,
    data,
    preview = false,
    expanded = false,
    onAction,
}: {
    meta: BlockMeta;
    data?: BlockData;
    preview?: boolean;
    expanded?: boolean;
    onAction?: () => void;
}) {
    const [selectedDate, setSelectedDate] = useState<string | null>(null);
    const openDetail = useContext(OverviewDetailContext);
    if (!data || data.state === 'deferred')
        return (
            <div className="ov-block-skeleton" role="status" aria-busy="true">
                <span className="sr-only">Loading this summary…</span>
                <Skeleton className="h-4 w-2/5" />
                <Skeleton className="h-14 w-full" />
                <Skeleton className="h-14 w-full" />
            </div>
        );
    if (data.state !== 'ready')
        return (
            <div className="ov-empty">
                <span className="ov-empty-icon">
                    {data.state === 'restricted' ? (
                        <LockKeyhole />
                    ) : (
                        <CircleDashed />
                    )}
                </span>
                <strong>
                    {data.state === 'proposed'
                        ? 'Proposed block'
                        : data.state === 'restricted'
                          ? 'Permission required'
                          : 'Information unavailable'}
                </strong>
                <p>{data.message}</p>
                {data.state === 'proposed' && (
                    <span className="text-caption">
                        You can include it in your layout now.
                    </span>
                )}
            </div>
        );
    if (data.presentation === 'progress-rows') {
        return (
            <div className="ov-progress-rows">
                {data.rows.length ? (
                    data.rows.map((row, index) => (
                        <div
                            key={`${row.label}-${index}`}
                            className="ov-progress-item"
                        >
                            <SummaryItem
                                row={row}
                                preview={preview}
                                className="ov-row"
                            >
                                <RowContent row={row} />
                            </SummaryItem>
                            {row.target && row.target > 0 ? (
                                <progress
                                    aria-label={`${row.label}: ${row.value ?? 0} of ${row.target}`}
                                    max={row.target}
                                    value={Math.min(row.target, row.value ?? 0)}
                                />
                            ) : (
                                <span className="text-caption text-muted-foreground">
                                    No percentage to show
                                </span>
                            )}
                        </div>
                    ))
                ) : (
                    <EmptyState
                        variant="compact"
                        icon={CheckCheck}
                        heading="No open records"
                        description={
                            data.empty ?? 'There are no records in this view.'
                        }
                        className="ov-block-empty"
                    />
                )}
            </div>
        );
    }
    if (data.presentation === 'decisions') {
        const priority = data.rows.find(
            (row) => row.tone === 'critical' || row.tone === 'warning',
        );
        return (
            <div className="ov-decisions">
                {priority && (
                    <SummaryItem
                        row={priority}
                        preview={preview}
                        className="ov-decision-alert"
                    >
                        <TriangleAlert size={17} />
                        <span>
                            <strong>{priority.label}</strong>
                            <small>{priority.detail}</small>
                        </span>
                        <ArrowUpRight size={16} />
                    </SummaryItem>
                )}
                {data.decisions === null ? (
                    <EmptyState
                        variant="compact"
                        icon={LockKeyhole}
                        heading="Decision permission required"
                        description="Your other priorities are still in Needs attention."
                        className="ov-block-empty"
                    />
                ) : data.decisions?.length ? (
                    data.decisions.map((row, i) => (
                        <SummaryItem
                            key={`${row.label}-${i}`}
                            row={row}
                            preview={preview}
                            className="ov-decision-row"
                        >
                            <span>{String(i + 1).padStart(2, '0')}</span>
                            <div>
                                <strong>{row.label}</strong>
                                <small>
                                    {row.detail}
                                    {row.date
                                        ? ` · Decision ${formatDate(row.date)}`
                                        : ' · No deadline recorded'}
                                </small>
                            </div>
                            <StatusBadge variant="neutral">
                                {row.status}
                            </StatusBadge>
                            <ArrowUpRight size={15} />
                        </SummaryItem>
                    ))
                ) : (
                    <EmptyState
                        variant="compact"
                        icon={CheckCheck}
                        heading="No open decisions"
                        description="No proposed or open decision papers you can see."
                        className="ov-block-empty"
                    />
                )}
            </div>
        );
    }
    if (data.presentation === 'assurance') {
        return (
            <div className="ov-assurance-columns">
                {data.sections?.map((section) => (
                    <section key={section.title} aria-label={section.title}>
                        <div className="ov-section-heading">
                            <strong>{section.title}</strong>
                            <span>{section.caption}</span>
                        </div>
                        {section.state === 'restricted' ? (
                            <EmptyState
                                variant="compact"
                                icon={LockKeyhole}
                                heading="Permission required"
                                description="You don’t have access to this register."
                                className="ov-block-empty"
                            />
                        ) : section.rows.length ? (
                            section.rows.map((row, i) => (
                                <SummaryItem
                                    key={`${row.label}-${i}`}
                                    row={row}
                                    preview={preview}
                                    className="ov-assurance-item"
                                >
                                    <span>
                                        <strong>{row.label}</strong>
                                        <span>{row.detail}</span>
                                        {row.date && (
                                            <small>
                                                {section.title ===
                                                'Risks to watch'
                                                    ? 'Review'
                                                    : 'Due'}{' '}
                                                {formatDate(row.date)}
                                            </small>
                                        )}
                                    </span>
                                    <span>
                                        <StatusBadge
                                            variant={
                                                row.tone === 'critical'
                                                    ? 'critical'
                                                    : row.tone === 'warning'
                                                      ? 'warning'
                                                      : 'neutral'
                                            }
                                        >
                                            {row.status}
                                        </StatusBadge>
                                        <ArrowUpRight size={15} />
                                    </span>
                                </SummaryItem>
                            ))
                        ) : (
                            <EmptyState
                                variant="compact"
                                icon={CheckCheck}
                                heading="No items recorded"
                                description={
                                    section.title === 'Risks to watch'
                                        ? 'No current risks in this register.'
                                        : 'No evidence or sign-off gaps recorded in the due window.'
                                }
                                className="ov-block-empty"
                            />
                        )}
                    </section>
                ))}
            </div>
        );
    }
    if (data.presentation === 'service-health') {
        return (
            <div className="ov-service-grid">
                {data.rows.length ? (
                    data.rows.map((row, i) => (
                        <SummaryItem
                            key={`${row.label}-${i}`}
                            row={row}
                            preview={preview}
                            className="ov-service-tile"
                        >
                            <span className="ov-service-heading">
                                <span
                                    className={`ov-health-dot ${row.tone ?? 'neutral'}`}
                                    aria-hidden="true"
                                />
                                <strong>{row.label}</strong>
                                <ArrowUpRight size={15} />
                            </span>
                            <StatusBadge
                                variant={
                                    row.tone === 'critical'
                                        ? 'critical'
                                        : row.tone === 'warning'
                                          ? 'warning'
                                          : 'neutral'
                                }
                            >
                                {row.status}
                            </StatusBadge>
                            <ConnectionHistoryStrip row={row} />
                            <small>{row.detail}</small>
                        </SummaryItem>
                    ))
                ) : (
                    <EmptyState
                        variant="compact"
                        icon={CircleDashed}
                        heading="No sites with devices you can see"
                        description="Device observations will appear when equipment is assigned."
                        className="ov-block-empty"
                    />
                )}
                {data.rows.some((row) => row.history?.length) && (
                    <div className="ov-connection-legend">
                        {[
                            ['healthy', 'All healthy'],
                            ['degraded', 'Degraded'],
                            ['failed', 'Failure'],
                            ['unknown', 'Unknown / no checks'],
                        ].map(([state, label]) => (
                            <span key={state}>
                                <i data-state={state} aria-hidden="true" />
                                {label}
                            </span>
                        ))}
                    </div>
                )}
            </div>
        );
    }
    if (data.presentation === 'priorities') {
        return (
            <div className="ov-priorities">
                {data.rows.length ? (
                    data.rows.map((row, i) => (
                        <SummaryItem
                            key={`${row.label}-${i}`}
                            row={row}
                            preview={preview}
                            className="ov-priority-row"
                        >
                            <span
                                className="ov-priority-ring"
                                role="img"
                                aria-label={`${row.label}: ${typeof row.value === 'number' ? `${row.value}% recorded progress` : 'Progress not recorded'}`}
                            >
                                <svg viewBox="0 0 72 72" aria-hidden="true">
                                    <circle
                                        cx="36"
                                        cy="36"
                                        r="29"
                                        fill="none"
                                        stroke="var(--muted)"
                                        strokeWidth="6"
                                    />
                                    <circle
                                        cx="36"
                                        cy="36"
                                        r="29"
                                        fill="none"
                                        stroke="var(--chart-1)"
                                        strokeWidth="6"
                                        strokeLinecap="round"
                                        strokeDasharray={`${Math.max(0, Math.min(100, row.value ?? 0)) * 1.822} 182.2`}
                                        transform="rotate(-90 36 36)"
                                    />
                                </svg>
                                <b>
                                    {typeof row.value === 'number'
                                        ? `${Math.round(row.value)}%`
                                        : '—'}
                                </b>
                            </span>
                            <span className="ov-priority-copy">
                                <strong>{row.label}</strong>
                                <small>
                                    Recorded goal progress · {row.detail}
                                </small>
                                <StatusBadge
                                    variant={
                                        row.tone === 'warning'
                                            ? 'warning'
                                            : 'neutral'
                                    }
                                >
                                    {row.status}
                                </StatusBadge>
                            </span>
                            <ArrowUpRight size={15} />
                        </SummaryItem>
                    ))
                ) : (
                    <EmptyState
                        variant="compact"
                        icon={CircleDashed}
                        heading="No current approved priorities"
                        description="Goals appear here when their approved plan is in its active period."
                        className="ov-block-empty"
                    />
                )}
            </div>
        );
    }
    if (data.presentation === 'links' || meta.kind === 'links') {
        return (
            <div className="ov-shortcuts">
                {data.rows.map((row, index) => {
                    const Icon = [Clock3, CalendarHeart, GraduationCap][
                        index % 3
                    ];
                    const content = (
                        <>
                            <Icon size={19} />
                            <span>
                                <strong>{row.label}</strong>
                                <small>{row.detail}</small>
                            </span>
                        </>
                    );
                    return row.href && !preview ? (
                        <Link key={row.label} href={row.href}>
                            {content}
                        </Link>
                    ) : (
                        <div key={row.label}>{content}</div>
                    );
                })}
            </div>
        );
    }
    if (data.coverage) {
        const coverage = data.coverage;
        const periods = [
            ['Morning', 'Starts 7 am–3 pm'],
            ['Afternoon', 'Starts 3–11 pm'],
            ['Night', 'Starts 11 pm–7 am'],
        ];
        return (
            <div className="ov-coverage">
                <div className="ov-coverage-head">
                    <span>
                        <UsersRound size={14} /> Assigned / required
                    </span>
                    <StatusBadge
                        variant={
                            coverage.gaps || coverage.unassessed
                                ? 'warning'
                                : 'neutral'
                        }
                    >
                        {coverage.unassessed
                            ? `${coverage.unassessed} not assessed`
                            : coverage.gaps
                              ? `${coverage.gaps} windows need review`
                              : coverage.windows
                                ? 'Recorded staffing matched'
                                : 'No requirements recorded'}
                    </StatusBadge>
                </div>
                <div className="ov-coverage-scroll">
                    <table aria-label="Shift coverage by site and start time">
                        <thead>
                            <tr>
                                <th scope="col">Site</th>
                                {periods.map(([label, time]) => (
                                    <th scope="col" key={label}>
                                        {label}
                                        <small>{time}</small>
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {coverage.rows.map((site) => (
                                <tr key={site.id}>
                                    <th scope="row">
                                        <span
                                            className="ov-site-avatar"
                                            aria-hidden="true"
                                        >
                                            {site.name
                                                .split(' ')
                                                .map((word) => word[0])
                                                .slice(0, 2)
                                                .join('')}
                                        </span>
                                        <span>{site.name}</span>
                                    </th>
                                    {site.cells.map((windows, index) => (
                                        <td key={index}>
                                            {windows.length ? (
                                                windows.map((window) => {
                                                    const content = (
                                                        <>
                                                            <span
                                                                className="ov-cover-people"
                                                                aria-hidden="true"
                                                            >
                                                                {Array.from(
                                                                    {
                                                                        length: Math.min(
                                                                            window.required,
                                                                            6,
                                                                        ),
                                                                    },
                                                                    (_, i) => (
                                                                        <i
                                                                            key={
                                                                                i
                                                                            }
                                                                            className={
                                                                                window.assigned !==
                                                                                    null &&
                                                                                i <
                                                                                    window.assigned
                                                                                    ? 'filled'
                                                                                    : ''
                                                                            }
                                                                        />
                                                                    ),
                                                                )}
                                                            </span>
                                                            <strong>
                                                                {window.assigned ??
                                                                    '—'}{' '}
                                                                <em>
                                                                    /{' '}
                                                                    {
                                                                        window.required
                                                                    }
                                                                </em>
                                                            </strong>
                                                            <small>
                                                                {window.assigned ===
                                                                null
                                                                    ? 'Not assessed'
                                                                    : window.roleGap
                                                                      ? 'Check role cover'
                                                                      : window.gap
                                                                        ? `${Math.max(0, window.required - window.assigned)} unfilled`
                                                                        : 'Assigned'}
                                                            </small>
                                                            <span className="ov-cover-time">
                                                                {formatTime(
                                                                    window.starts,
                                                                )}
                                                                –
                                                                {formatTime(
                                                                    window.ends,
                                                                )}
                                                            </span>
                                                        </>
                                                    );
                                                    const props = {
                                                        className: `ov-coverage-cell ${window.gap ? 'has-gap' : ''}`,
                                                        'aria-label': `${site.name}, ${periods[index][0]}: ${window.assigned === null ? 'not assessed' : `${window.assigned} of ${window.required} assigned`}${window.gap ? ', staffing review needed' : ''}`,
                                                    };
                                                    if (!preview && openDetail)
                                                        return (
                                                            // eslint-disable-next-line no-restricted-syntax -- a coverage cell opens its window details
                                                            <button
                                                                type="button"
                                                                key={window.id}
                                                                aria-haspopup="dialog"
                                                                {...props}
                                                                onClick={() =>
                                                                    openDetail({
                                                                        label: `${site.name} · ${periods[index][0].toLowerCase()}`,
                                                                        detail: 'Rostered cover against the recorded staffing requirement',
                                                                        status:
                                                                            window.assigned ===
                                                                            null
                                                                                ? 'Not assessed'
                                                                                : window.gap
                                                                                  ? 'Needs cover'
                                                                                  : 'Covered',
                                                                        tone: window.gap
                                                                            ? 'warning'
                                                                            : 'neutral',
                                                                        href: window.href,
                                                                        actionLabel:
                                                                            'Open roster',
                                                                        facts: [
                                                                            {
                                                                                label: 'Window',
                                                                                value: `${formatTime(window.starts)} – ${formatTime(window.ends)}`,
                                                                            },
                                                                            {
                                                                                label: 'Assigned',
                                                                                value:
                                                                                    window.assigned ===
                                                                                    null
                                                                                        ? 'Not assessed'
                                                                                        : String(
                                                                                              window.assigned,
                                                                                          ),
                                                                            },
                                                                            {
                                                                                label: 'Required',
                                                                                value: String(
                                                                                    window.required,
                                                                                ),
                                                                            },
                                                                            ...(window.roleGap
                                                                                ? [
                                                                                      {
                                                                                          label: 'Roles',
                                                                                          value: 'A required role is not covered',
                                                                                      },
                                                                                  ]
                                                                                : []),
                                                                        ],
                                                                    })
                                                                }
                                                            >
                                                                {content}
                                                            </button>
                                                        );
                                                    return preview ? (
                                                        <div
                                                            key={window.id}
                                                            {...props}
                                                        >
                                                            {content}
                                                        </div>
                                                    ) : (
                                                        <Link
                                                            key={window.id}
                                                            href={window.href}
                                                            {...props}
                                                        >
                                                            {content}
                                                        </Link>
                                                    );
                                                })
                                            ) : (
                                                <span className="ov-coverage-missing">
                                                    No requirement
                                                </span>
                                            )}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="ov-coverage-note">
                    {formatDate(coverage.date)} · Windows grouped by start time.
                    Ratios show the lowest assigned staffing within each window.
                </p>
            </div>
        );
    }
    if (data.presentation === 'pipeline') {
        const largest = Math.max(1, ...data.rows.map((row) => row.value ?? 0));
        return (
            <div className="ov-pipeline">
                <div className="ov-chart-head">
                    <strong>
                        {data.total ?? 0} <small>active candidates</small>
                    </strong>
                    <span>From application to joining</span>
                </div>
                {data.rows.map((row, index) => {
                    const content = (
                        <>
                            <span className="ov-pipeline-stage">
                                <i>{index + 1}</i>
                                {row.label}
                            </span>
                            <span className="ov-pipeline-track">
                                <i
                                    style={{
                                        width: `${((row.value ?? 0) / largest) * 100}%`,
                                        background:
                                            colors[index % colors.length],
                                    }}
                                />
                            </span>
                            <strong>{row.value ?? 0}</strong>
                            <ArrowUpRight size={14} />
                        </>
                    );
                    return row.href && !preview ? (
                        <Link key={row.label} href={row.href}>
                            {content}
                        </Link>
                    ) : (
                        <div key={row.label}>{content}</div>
                    );
                })}
            </div>
        );
    }
    if (data.presentation === 'connected-teams') {
        return (
            <div className="ov-connected-teams">
                <div className="ov-team-head" aria-hidden="true">
                    <span>Site & team</span>
                    <span>Next handover</span>
                    <span>Open follow-ups</span>
                </div>
                {data.rows.map((row) => (
                    <div className="ov-team-row" key={row.label}>
                        <SummaryItem
                            row={row}
                            preview={preview}
                            className="ov-team-site"
                        >
                            <span className="ov-site-avatar" aria-hidden="true">
                                {row.label
                                    .split(' ')
                                    .map((word) => word[0])
                                    .slice(0, 2)
                                    .join('')}
                            </span>
                            <span>
                                <strong>{row.label}</strong>
                                <small>{row.detail}</small>
                                <small data-tone={row.tone}>{row.status}</small>
                            </span>
                        </SummaryItem>
                        {[row.handover, row.followups].map(
                            (item, index) =>
                                item && (
                                    <SummaryItem
                                        key={index}
                                        row={item}
                                        preview={preview}
                                        className="ov-team-work"
                                    >
                                        <span className="ov-team-mobile-label">
                                            {index ? 'Follow-ups' : 'Handover'}
                                        </span>
                                        <strong data-tone={item.tone}>
                                            {item.label}
                                        </strong>
                                        <small>
                                            {item.date
                                                ? formatDateTime(item.date)
                                                : item.detail}
                                        </small>
                                        {item.href && (
                                            <ArrowUpRight
                                                size={14}
                                                aria-hidden="true"
                                            />
                                        )}
                                    </SummaryItem>
                                ),
                        )}
                    </div>
                ))}
                {!data.rows.length && (
                    <EmptyState
                        variant="compact"
                        icon={UsersRound}
                        heading="No sites in your view"
                        description="Sites appear here when you're assigned to lead them."
                        className="ov-block-empty"
                    />
                )}
            </div>
        );
    }
    if (data.presentation === 'site-status') {
        return (
            <div className="ov-site-status">
                {data.rows.map((row) => {
                    const content = (
                        <>
                            <span className="ov-site-avatar" aria-hidden="true">
                                {row.label
                                    .split(' ')
                                    .map((word) => word[0])
                                    .slice(0, 2)
                                    .join('')}
                            </span>
                            <span className="ov-row-copy">
                                <strong>{row.label}</strong>
                                <span>{row.detail}</span>
                            </span>
                            <StatusBadge
                                variant={
                                    row.tone === 'warning'
                                        ? 'warning'
                                        : 'neutral'
                                }
                            >
                                {row.status}
                            </StatusBadge>
                            <ArrowUpRight size={15} />
                        </>
                    );
                    return row.href && !preview ? (
                        <Link key={row.label} href={row.href}>
                            {content}
                        </Link>
                    ) : (
                        <div key={row.label}>{content}</div>
                    );
                })}
            </div>
        );
    }
    if (data.calendar) {
        const total = data.calendar.reduce((sum, day) => sum + day.count, 0);
        const hours = data.calendar.every((day) => day.hours !== undefined)
            ? Math.round(
                  data.calendar.reduce((sum, day) => sum + day.hours!, 0) * 10,
              ) / 10
            : null;
        return (
            <div className="ov-week">
                <div className="ov-chart-head">
                    <strong>
                        {hours ?? total}{' '}
                        <small>
                            {hours !== null
                                ? 'hours'
                                : total === 1
                                  ? 'shift'
                                  : 'shifts'}
                        </small>
                    </strong>
                    <span>
                        {hours !== null
                            ? `${total} scheduled ${total === 1 ? 'shift' : 'shifts'} · before breaks`
                            : 'across your next seven days'}
                    </span>
                </div>
                <div className="ov-week-grid">
                    {data.calendar.map((day, index) => {
                        const parts = formatDate(day.date).split(' ');
                        return (
                            <div
                                key={day.date}
                                className={`ov-day ${index === 0 ? 'ov-day-current' : ''}`}
                            >
                                <span>{parts[0]}</span>
                                <b>{parts[1]}</b>
                                <span
                                    className={`ov-shift-slot ${day.count ? '' : 'ov-shift-off'}`}
                                >
                                    {day.count && day.starts && day.ends ? (
                                        <>
                                            {formatTime(day.starts)}
                                            <span>
                                                – {formatTime(day.ends)}
                                            </span>
                                            {day.count > 1 && (
                                                <small>
                                                    + {day.count - 1} more
                                                </small>
                                            )}
                                        </>
                                    ) : (
                                        'No shifts'
                                    )}
                                </span>
                            </div>
                        );
                    })}
                </div>
                <div className="ov-week-note">
                    <CalendarHeart size={13} />
                    <span>
                        {formatDate(data.calendar[0]?.date)} –{' '}
                        {formatDate(data.calendar.at(-1)?.date)} · Your selected
                        sites
                    </span>
                </div>
            </div>
        );
    }
    if (meta.kind === 'focus') {
        const first = data.rows[0];
        const target = first ? first.href || data.href : data.href;
        const label = first
            ? (data.actionLabel ?? 'Open workspace')
            : (data.emptyActionLabel ?? data.actionLabel ?? 'Open workspace');
        // Something to act on gets the primary action; an empty card keeps a
        // quiet route to the workspace instead of a call to action.
        const variant = first ? 'default' : 'outline';
        const action =
            preview || !target ? null : data.actionTarget === 'attention' ? (
                <Button
                    className="ov-focus-action"
                    variant={variant}
                    onClick={onAction}
                >
                    <ArrowUpRight />
                    {label}
                </Button>
            ) : (
                <Button asChild className="ov-focus-action" variant={variant}>
                    <Link href={target}>
                        <ArrowUpRight />
                        {label}
                    </Link>
                </Button>
            );
        return (
            <div className="ov-focus">
                <StatusBadge
                    variant={
                        first?.tone === 'critical'
                            ? 'critical'
                            : first?.tone === 'warning'
                              ? 'warning'
                              : first
                                ? 'info'
                                : 'neutral'
                    }
                >
                    {data.focusTag ??
                        first?.status ??
                        (first ? 'Next step' : 'Nothing scheduled')}
                </StatusBadge>
                {first ? (
                    <>
                        <strong className="ov-focus-number">
                            {data.headline ??
                                (first.date
                                    ? first.date.includes('T')
                                        ? `${formatTime(first.date)}${first.end ? ` – ${formatTime(first.end)}` : ''}`
                                        : formatDate(first.date)
                                    : (data.total ?? 'Ready'))}
                        </strong>
                        <h3>{first.label}</h3>
                        <p>
                            {first.detail ??
                                (first.date ? formatDate(first.date) : '')}
                        </p>
                        {data.focusMeta ? (
                            <div className="ov-focus-facts">
                                {data.focusMeta.map((fact, index) => (
                                    <span key={fact}>
                                        {index ? (
                                            <UsersRound size={15} />
                                        ) : (
                                            <Clock3 size={15} />
                                        )}
                                        {fact}
                                    </span>
                                ))}
                            </div>
                        ) : (
                            <div className="ov-focus-meta">
                                <Clock3 size={13} />
                                <span>
                                    {first.date
                                        ? `${formatDate(first.date)}${first.end ? ` · until ${formatTime(first.end)}` : ''}`
                                        : 'Current work you can see'}
                                </span>
                            </div>
                        )}
                        {action}
                    </>
                ) : (
                    <>
                        <strong className="ov-focus-number ov-focus-empty">
                            {data.headline ?? 'Nothing scheduled'}
                        </strong>
                        <p>
                            {data.empty ??
                                'Nothing is recorded for you in this view yet.'}
                        </p>
                        {action}
                    </>
                )}
            </div>
        );
    }
    if (meta.kind === 'trend' && data.series.length)
        return (
            <div className="ov-chart-wrap">
                <div className="ov-chart-legend">
                    <span>
                        <i style={{ background: colors[0] }} />
                        {data.seriesLabel ?? 'Recorded'}
                    </span>
                    {data.comparisonLabel && (
                        <span>
                            <i style={{ background: colors[1] }} />
                            {data.comparisonLabel}
                        </span>
                    )}
                    {data.unit && <small>Share ({data.unit})</small>}
                </div>
                <div
                    className="ov-area"
                    role="img"
                    aria-label={`${data.title ?? meta.title} chart. Every figure is in the chart figures table below.`}
                >
                    <ResponsiveContainer width="100%" height="100%">
                        <ComposedChart
                            data={data.series}
                            accessibilityLayer={false}
                            className={preview ? undefined : 'ov-chart-pick'}
                            onClick={(state) => {
                                if (!preview && state?.activeLabel != null)
                                    setSelectedDate(String(state.activeLabel));
                            }}
                            margin={{
                                top: 10,
                                right: 12,
                                left: -20,
                                bottom: 0,
                            }}
                        >
                            <defs>
                                <linearGradient
                                    id={`overview-${meta.id}-${preview ? 'preview' : 'card'}`}
                                    x1="0"
                                    y1="0"
                                    x2="0"
                                    y2="1"
                                >
                                    <stop
                                        offset="0%"
                                        stopColor="var(--chart-1)"
                                        stopOpacity={0.25}
                                    />
                                    <stop
                                        offset="100%"
                                        stopColor="var(--chart-1)"
                                        stopOpacity={0.015}
                                    />
                                </linearGradient>
                            </defs>
                            <CartesianGrid
                                stroke="var(--border)"
                                vertical={false}
                                strokeDasharray="3 5"
                            />
                            <XAxis
                                dataKey="label"
                                tickFormatter={(v) =>
                                    data.series.length <= 7
                                        ? formatDate(v).split(' ')[0]
                                        : formatDate(v)
                                }
                                axisLine={false}
                                tickLine={false}
                                minTickGap={38}
                                tick={{
                                    fill: 'var(--muted-foreground)',
                                    fontSize: '0.75rem',
                                }}
                            />
                            <YAxis
                                domain={
                                    data.unit === '%' ? [0, 100] : undefined
                                }
                                tickFormatter={(value) =>
                                    `${value}${data.unit ?? ''}`
                                }
                                allowDecimals={false}
                                axisLine={false}
                                tickLine={false}
                                tick={{
                                    fill: 'var(--muted-foreground)',
                                    fontSize: '0.75rem',
                                }}
                            />
                            <Tooltip
                                labelFormatter={(v) => formatDate(String(v))}
                                formatter={(value, name) => [
                                    value == null
                                        ? 'No data'
                                        : `${value}${data.unit ?? ''}`,
                                    name,
                                ]}
                                contentStyle={{
                                    background: 'var(--popover)',
                                    border: '1px solid var(--border)',
                                    borderRadius: 10,
                                    color: 'var(--foreground)',
                                }}
                            />
                            <Area
                                type="monotone"
                                name={data.seriesLabel ?? 'Recorded'}
                                dataKey="value"
                                stroke="var(--chart-1)"
                                strokeWidth={2.5}
                                fill={`url(#overview-${meta.id}-${preview ? 'preview' : 'card'})`}
                                isAnimationActive={false}
                                dot={
                                    data.series.filter((p) => p.value !== null)
                                        .length === 1
                                        ? { r: 4 }
                                        : false
                                }
                            />
                            {data.comparisonLabel && (
                                <Line
                                    type="monotone"
                                    name={data.comparisonLabel}
                                    dataKey="comparison"
                                    stroke={colors[1]}
                                    strokeWidth={2}
                                    strokeDasharray="5 5"
                                    dot={false}
                                    isAnimationActive={false}
                                />
                            )}
                        </ComposedChart>
                    </ResponsiveContainer>
                </div>
                {!preview && data.series.length > 0 && (
                    <div className="ov-chart-inspector">
                        {/* The chart is the date picker (as in the approved
                            mockup); keyboard and screen-reader users get
                            every figure from the table below. */}
                        <p aria-live="polite">
                            {(() => {
                                const point = data.series.find(
                                    (p) => p.label === selectedDate,
                                );
                                if (!point)
                                    return 'Select a date on the chart to see its figures.';
                                if (
                                    data.unit === '%' &&
                                    point.planned !== undefined
                                )
                                    return `${formatDate(point.label)} · ${point.value === null ? 'No ended shifts yet' : `${point.value}% delivered · ${point.completed} of ${point.planned} planned shifts`}`;
                                return `${formatDate(point.label)} · ${data.seriesLabel ?? 'Recorded'}: ${point.value === null ? 'No data yet' : `${point.value}${data.unit ?? ''}`}${data.comparisonLabel ? ` · ${data.comparisonLabel}: ${point.comparison == null ? 'No data yet' : `${point.comparison}${data.unit ?? ''}`}` : ''}`;
                            })()}
                        </p>
                    </div>
                )}
                <details className="ov-data-table">
                    <summary>View chart figures</summary>
                    <div className="ov-data-table-scroll">
                        <table>
                            <caption className="sr-only">
                                {data.title ?? meta.title} figures by date
                            </caption>
                            <thead>
                                <tr>
                                    <th scope="col">Date</th>
                                    <th scope="col">
                                        {data.seriesLabel ?? 'Recorded'}
                                    </th>
                                    {data.comparisonLabel && (
                                        <th scope="col">
                                            {data.comparisonLabel}
                                        </th>
                                    )}
                                    {data.series.some(
                                        (p) => p.planned !== undefined,
                                    ) && (
                                        <th scope="col">Completed / planned</th>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {data.series.map((p) => (
                                    <tr key={p.label}>
                                        <th scope="row">
                                            {formatDate(p.label)}
                                        </th>
                                        <td>
                                            {p.value === null
                                                ? 'No data yet'
                                                : `${p.value}${data.unit ?? ''}`}
                                        </td>
                                        {data.comparisonLabel && (
                                            <td>
                                                {p.comparison == null
                                                    ? 'No data yet'
                                                    : `${p.comparison}${data.unit ?? ''}`}
                                            </td>
                                        )}
                                        {data.series.some(
                                            (point) =>
                                                point.planned !== undefined,
                                        ) && (
                                            <td>
                                                {p.planned !== undefined
                                                    ? `${p.completed ?? 0} of ${p.planned}`
                                                    : '—'}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </details>
            </div>
        );
    const values = data.rows.filter((r) => typeof r.value === 'number');
    const total = values.reduce((n, r) => n + (r.value ?? 0), 0);
    if (
        (meta.kind === 'donut' || meta.kind === 'progress') &&
        data.state === 'ready'
    ) {
        let offset = 0;
        return (
            <>
                <div className="ov-distribution">
                    <div
                        className="ov-donut"
                        role="img"
                        aria-label={
                            total
                                ? values
                                      .map((r) => `${r.label}: ${r.value}`)
                                      .join(', ')
                                : 'No records in this breakdown'
                        }
                    >
                        <svg viewBox="0 0 160 160">
                            <circle
                                cx="80"
                                cy="80"
                                r="62"
                                fill="none"
                                stroke="var(--muted)"
                                strokeWidth="16"
                            />
                            {values.map((r, i) => {
                                const length = total
                                    ? ((r.value ?? 0) / total) * 389.56
                                    : 0;
                                const start = offset;
                                offset += length;
                                return (
                                    <circle
                                        key={r.label}
                                        cx="80"
                                        cy="80"
                                        r="62"
                                        fill="none"
                                        stroke={colors[i % colors.length]}
                                        strokeWidth="16"
                                        strokeDasharray={`${Math.max(0, length - 3)} ${389.56 - Math.max(0, length - 3)}`}
                                        strokeDashoffset={-start}
                                        transform="rotate(-90 80 80)"
                                    />
                                );
                            })}
                        </svg>
                        <div>
                            <strong>
                                {total && data.measurementKnown !== false
                                    ? `${Math.round(((values[0]?.value ?? 0) / total) * 100)}%`
                                    : '—'}
                            </strong>
                            <span>
                                {data.measurementKnown === false
                                    ? 'Not assessed'
                                    : total
                                      ? values[0]?.label
                                      : 'No records yet'}
                            </span>
                        </div>
                    </div>
                    <div className="ov-legend">
                        {!values.length && (
                            <p className="ov-chart-empty">
                                Your breakdown will appear here as work is
                                recorded.
                            </p>
                        )}
                        {values.map((r, i) => (
                            <div key={r.label}>
                                <i
                                    style={{
                                        background: colors[i % colors.length],
                                    }}
                                />
                                <span>{r.label}</span>
                                <b>{r.value}</b>
                            </div>
                        ))}
                    </div>
                </div>
                <div className="ov-breakdown-total">
                    <span>{total} in this breakdown</span>
                    {!preview && data.href && (
                        <Link href={data.href}>
                            Explore <ArrowUpRight size={13} />
                        </Link>
                    )}
                </div>
            </>
        );
    }
    if (data.presentation === 'delivery') {
        return (
            <div className="ov-delivery">
                <div className="ov-delivery-labels">
                    <span>Delivered against planned shifts</span>
                    <span>Completed / planned</span>
                </div>
                <div className="ov-delivery-sites">
                    {data.rows.map((row, index) => {
                        const percent = row.target
                            ? Math.round(((row.value ?? 0) / row.target) * 100)
                            : null;
                        const content = (
                            <>
                                <span
                                    className="ov-site-avatar"
                                    aria-hidden="true"
                                >
                                    {row.label
                                        .split(' ')
                                        .slice(0, 2)
                                        .map((word) => word[0])
                                        .join('')}
                                </span>
                                <span className="ov-delivery-name">
                                    <strong>{row.label}</strong>
                                    <small>{row.detail}</small>
                                </span>
                                <svg
                                    className="ov-site-spark"
                                    viewBox="0 0 100 32"
                                    aria-hidden="true"
                                >
                                    {(row.sparkline ?? []).map(
                                        (value, i, series) =>
                                            value !== null && (
                                                <g key={i}>
                                                    {i > 0 &&
                                                        series[i - 1] !==
                                                            null && (
                                                            <line
                                                                x1={
                                                                    ((i - 1) /
                                                                        Math.max(
                                                                            1,
                                                                            series.length -
                                                                                1,
                                                                        )) *
                                                                        96 +
                                                                    2
                                                                }
                                                                y1={
                                                                    29 -
                                                                    series[
                                                                        i - 1
                                                                    ]! *
                                                                        0.25
                                                                }
                                                                x2={
                                                                    (i /
                                                                        Math.max(
                                                                            1,
                                                                            series.length -
                                                                                1,
                                                                        )) *
                                                                        96 +
                                                                    2
                                                                }
                                                                y2={
                                                                    29 -
                                                                    value * 0.25
                                                                }
                                                            />
                                                        )}
                                                    <circle
                                                        cx={
                                                            (i /
                                                                Math.max(
                                                                    1,
                                                                    series.length -
                                                                        1,
                                                                )) *
                                                                96 +
                                                            2
                                                        }
                                                        cy={29 - value * 0.25}
                                                        r="2"
                                                    />
                                                </g>
                                            ),
                                    )}
                                </svg>
                                <span className="ov-delivery-percent">
                                    <b>
                                        {percent ?? '—'}
                                        {percent !== null && <em>%</em>}
                                    </b>
                                    <small>
                                        {row.value} / {row.target}
                                    </small>
                                </span>
                                {row.href && <ArrowUpRight size={14} />}
                            </>
                        );
                        return row.href && !preview ? (
                            <Link
                                className="ov-delivery-row"
                                key={`${row.label}-${index}`}
                                href={row.href}
                            >
                                {content}
                            </Link>
                        ) : (
                            <div
                                className="ov-delivery-row"
                                key={`${row.label}-${index}`}
                            >
                                {content}
                            </div>
                        );
                    })}
                </div>
                <p className="ov-delivery-note">
                    Open a site to review the records behind its delivery.
                </p>
            </div>
        );
    }
    if (meta.kind === 'bars' && values.length) {
        const max = Math.max(1, ...values.map((r) => r.value ?? 0));
        return (
            <div className="ov-bars">
                {values.map((r, i) => (
                    <div className="ov-site-row" key={`${r.label}-${i}`}>
                        <span className="ov-site-avatar" aria-hidden="true">
                            {r.label
                                .split(' ')
                                .slice(0, 2)
                                .map((word) => word[0])
                                .join('')}
                        </span>
                        <div className="ov-site-content">
                            <div className="ov-between">
                                <strong>{r.label}</strong>
                                <b>
                                    {r.target !== undefined
                                        ? r.target
                                            ? `${Math.round(((r.value ?? 0) / r.target) * 100)}%`
                                            : '—'
                                        : r.value}
                                </b>
                            </div>
                            <div className="ov-track">
                                <span
                                    style={{
                                        width: `${Math.min(100, ((r.value ?? 0) / (r.target || max)) * 100)}%`,
                                        background: colors[i % colors.length],
                                    }}
                                />
                            </div>
                            {r.target !== undefined && (
                                <small>
                                    {r.value} of {r.target} completed
                                </small>
                            )}
                        </div>
                        {!preview && r.href && (
                            <Link
                                href={r.href}
                                className="ov-icon-button"
                                aria-label={`Open ${r.label}`}
                            >
                                <ArrowUpRight size={15} />
                            </Link>
                        )}
                    </div>
                ))}
            </div>
        );
    }
    if (
        (!data.rows.length && !(expanded && data.reviews !== undefined)) ||
        ((meta.kind === 'donut' || meta.kind === 'progress') && total === 0)
    )
        return meta.id !== 'attention' && !data.partial && !data.total ? (
            <EmptyState
                variant="compact"
                icon={CheckCheck}
                heading="Nothing recorded yet"
                description={
                    data.empty ?? 'This summary updates as work is recorded.'
                }
                className="ov-block-empty"
            />
        ) : (
            <div className="ov-empty">
                <span className="ov-empty-icon">
                    <CheckCheck />
                </span>
                <strong>
                    {data.partial
                        ? 'Some information is unavailable'
                        : data.total
                          ? `${data.total} in this view`
                          : meta.id === 'attention'
                            ? 'You’re up to date'
                            : 'No records in this view'}
                </strong>
                <p>
                    {data.partial
                        ? 'Refresh to try the unavailable sources again.'
                        : data.total
                          ? 'Open the workspace to review these records.'
                          : 'This summary updates as work is recorded.'}
                </p>
            </div>
        );
    if (meta.id === 'attention') {
        return (
            <div className="ov-priorities">
                {expanded && data.reviews !== undefined && (
                    <section
                        className="ov-review-queue"
                        aria-label="Decision queue"
                    >
                        <div className="ov-review-queue-heading">
                            <strong>Ready for your review</strong>
                            <span>
                                {data.reviewTotal ?? data.reviews.length}{' '}
                                requests
                            </span>
                        </div>
                        {data.reviews.map((row, index) => (
                            <SummaryItem
                                key={row.href ?? index}
                                row={row}
                                preview={preview}
                                className="ov-decision-row"
                            >
                                <span>
                                    {String(index + 1).padStart(2, '0')}
                                </span>
                                <div>
                                    <strong>{row.label}</strong>
                                    <small>
                                        {row.detail}
                                        {row.date
                                            ? ` · Review by ${formatDate(row.date)}`
                                            : ''}
                                    </small>
                                </div>
                                <StatusBadge
                                    variant={
                                        row.tone === 'critical'
                                            ? 'critical'
                                            : row.tone === 'warning'
                                              ? 'warning'
                                              : 'neutral'
                                    }
                                >
                                    {row.status}
                                </StatusBadge>
                                <ArrowUpRight size={15} />
                            </SummaryItem>
                        ))}
                        {!data.reviews.length && (
                            <p>
                                No submitted requests are waiting for your
                                review.
                            </p>
                        )}
                        {(data.reviewTotal ?? 0) > data.reviews.length && (
                            <p>
                                Showing the first {data.reviews.length}{' '}
                                requests. Open a full workspace below to review
                                the remaining work.
                            </p>
                        )}
                        {!!data.reviewQueues?.length && (
                            <div className="ov-review-workspaces">
                                {data.reviewQueues.map((row) => (
                                    <SummaryItem
                                        key={row.href}
                                        row={row}
                                        preview={preview}
                                        className="ov-review-workspace"
                                    >
                                        <span>
                                            {row.label} · {row.value}
                                        </span>
                                        <ArrowUpRight size={14} />
                                    </SummaryItem>
                                ))}
                            </div>
                        )}
                    </section>
                )}
                {(expanded ? data.rows : data.rows.slice(0, 3)).map(
                    (row, index) => {
                        const featured = index === 0;
                        const content = (
                            <>
                                {featured && (
                                    <div className="ov-priority-eyebrow">
                                        <span>
                                            <TriangleAlert size={14} />
                                            {row.tone === 'critical'
                                                ? 'Priority now'
                                                : 'Start here'}
                                        </span>
                                        {row.status && (
                                            <span>{row.status}</span>
                                        )}
                                    </div>
                                )}
                                <div className="ov-priority-main">
                                    {/* Shape as well as colour: urgent, due soon, other. */}
                                    {!featured &&
                                        (row.tone === 'critical' ? (
                                            <TriangleAlert
                                                className="ov-priority-mark"
                                                size={13}
                                                aria-hidden="true"
                                            />
                                        ) : row.tone === 'warning' ? (
                                            <Clock3
                                                className="ov-priority-mark"
                                                size={13}
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <span
                                                className="ov-priority-dot"
                                                aria-hidden="true"
                                            />
                                        ))}
                                    <span className="ov-priority-copy">
                                        <strong>
                                            {!featured && row.tone && (
                                                <span className="sr-only">
                                                    {row.tone === 'critical'
                                                        ? 'Urgent: '
                                                        : row.tone === 'warning'
                                                          ? 'Due soon: '
                                                          : ''}
                                                </span>
                                            )}
                                            {row.label}
                                        </strong>
                                        <small>
                                            {row.detail ??
                                                `${row.value ?? 0} items to review`}
                                        </small>
                                    </span>
                                    {row.href && (
                                        <span className="ov-priority-action">
                                            {row.actionLabel ?? 'Review work'}
                                            <ArrowUpRight size={14} />
                                        </span>
                                    )}
                                </div>
                            </>
                        );
                        const item =
                            row.href && !preview ? (
                                <Link
                                    key={`${row.label}-${index}`}
                                    href={row.href}
                                    className={`ov-priority ${featured ? 'ov-priority-featured' : ''}`}
                                    data-tone={row.tone}
                                >
                                    {content}
                                </Link>
                            ) : (
                                <div
                                    key={`${row.label}-${index}`}
                                    className={`ov-priority ${featured ? 'ov-priority-featured' : ''}`}
                                    data-tone={row.tone}
                                >
                                    {content}
                                </div>
                            );
                        return expanded && index === 1 ? (
                            <Fragment key={`${row.label}-${index}`}>
                                <p className="ov-also-heading">
                                    Also on your list
                                </p>
                                {item}
                            </Fragment>
                        ) : (
                            item
                        );
                    },
                )}
            </div>
        );
    }
    return (
        <div className="ov-rows">
            {data.rows.map((row, index) => (
                <SummaryItem
                    key={`${row.label}-${index}`}
                    row={row}
                    preview={preview}
                    className="ov-row"
                    tone={row.tone}
                >
                    <RowContent row={row} />
                </SummaryItem>
            ))}
        </div>
    );
}
