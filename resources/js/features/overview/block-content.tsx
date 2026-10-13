/* eslint-disable no-restricted-syntax -- The approved focus action and chart date selectors have purpose-specific geometry inside overview cards. */
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
    Info,
    LockKeyhole,
    TriangleAlert,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
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
                    <b>{date[1]}</b>
                    <span>{date[2]}</span>
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

function SummaryItem({
    row,
    preview,
    children,
    className,
}: {
    row: Row;
    preview: boolean;
    children: React.ReactNode;
    className: string;
}) {
    return row.href && !preview ? (
        <Link href={row.href} className={className}>
            {children}
        </Link>
    ) : (
        <div className={className}>{children}</div>
    );
}

function connectionCheckLabel(hour: NonNullable<Row['history']>[number]) {
    return `${formatDateTime(hour.at)} · ${hour.checks ? `${hour.checks} recorded checks · ${hour.state === 'healthy' ? 'all healthy' : hour.state === 'failed' ? 'failure recorded' : hour.state === 'degraded' ? 'degraded result recorded' : 'state unknown'}` : 'No checks recorded'}`;
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
    if (!data)
        return (
            <div className="ov-empty" role="status">
                <CircleDashed className="ov-loading" />
                <strong>Loading this summary…</strong>
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
                    <div className="ov-empty">
                        <CheckCheck />
                        <strong>No open records</strong>
                        <p>
                            {data.empty ?? 'There are no records in this view.'}
                        </p>
                    </div>
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
                    <div className="ov-assurance-empty">
                        <LockKeyhole size={20} />
                        <strong>Decision permission required</strong>
                        <span>
                            Your permitted priorities remain available in Needs
                            attention.
                        </span>
                    </div>
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
                    <div className="ov-assurance-empty">
                        <CheckCheck size={20} />
                        <strong>No open decisions</strong>
                        <span>
                            No proposed or open decision papers in your
                            permitted view.
                        </span>
                    </div>
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
                            <div className="ov-assurance-empty">
                                <LockKeyhole size={20} />
                                <strong>Permission required</strong>
                                <span>
                                    This register is outside your permitted
                                    view.
                                </span>
                            </div>
                        ) : section.rows.length ? (
                            section.rows.map((row, i) => (
                                <SummaryItem
                                    key={`${row.label}-${i}`}
                                    row={row}
                                    preview={preview}
                                    className="ov-assurance-item"
                                >
                                    <div>
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
                                    </div>
                                    <div>
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
                                    </div>
                                </SummaryItem>
                            ))
                        ) : (
                            <div className="ov-assurance-empty">
                                <CheckCheck size={20} />
                                <strong>No items recorded</strong>
                                <span>
                                    {section.title === 'Risks to watch'
                                        ? 'No current risks in this register.'
                                        : 'No evidence or sign-off gaps recorded in the due window.'}
                                </span>
                            </div>
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
                            <div className="ov-service-heading">
                                <span
                                    className={`ov-health-dot ${row.tone ?? 'neutral'}`}
                                    aria-hidden="true"
                                />
                                <strong>{row.label}</strong>
                                <ArrowUpRight size={15} />
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
                            {!!row.history?.length && (
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
                                                title={connectionCheckLabel(
                                                    hour,
                                                )}
                                            />
                                        ))}
                                    </span>
                                    <span className="ov-connection-axis">
                                        <span>
                                            {formatDateTime(row.history[0].at)}
                                        </span>
                                        <span>
                                            {formatTime(
                                                row.history[
                                                    row.history.length - 1
                                                ].at,
                                            )}{' '}
                                            · current hour
                                        </span>
                                    </span>
                                </>
                            )}
                            <small>{row.detail}</small>
                        </SummaryItem>
                    ))
                ) : (
                    <div className="ov-assurance-empty">
                        <CircleDashed />
                        <strong>No permitted device sites</strong>
                        <span>
                            Device observations will appear when equipment is
                            assigned.
                        </span>
                    </div>
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
                            <div
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
                            </div>
                            <div className="ov-priority-copy">
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
                            </div>
                            <ArrowUpRight size={15} />
                        </SummaryItem>
                    ))
                ) : (
                    <div className="ov-assurance-empty">
                        <CircleDashed />
                        <strong>No current approved priorities</strong>
                        <span>
                            Goals appear here when their approved plan is in its
                            active period.
                        </span>
                    </div>
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
                    <p className="ov-chart-note">
                        No sites are available in this view.
                    </p>
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
        return (
            <div className="ov-focus">
                <span className="ov-focus-chip">
                    {data.focusTag ??
                        first?.status ??
                        (first ? 'Next step' : 'Nothing scheduled')}
                </span>
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
                                        : 'Current work in your permitted view'}
                                </span>
                            </div>
                        )}
                        {!preview &&
                            (first.href || data.href) &&
                            (data.actionTarget === 'attention' ? (
                                <button
                                    className="ov-focus-link"
                                    onClick={onAction}
                                >
                                    <ArrowUpRight size={16} />
                                    {data.actionLabel}
                                </button>
                            ) : (
                                <Link
                                    className="ov-focus-link"
                                    href={first.href || data.href!}
                                >
                                    <ArrowUpRight size={16} />{' '}
                                    {data.actionLabel ?? 'Open workspace'}
                                </Link>
                            ))}
                    </>
                ) : (
                    <>
                        <strong className="ov-focus-number">
                            {data.headline ?? 'No upcoming item'}
                        </strong>
                        <p>
                            {data.empty ??
                                'No item is currently recorded in this view.'}
                        </p>
                        {!preview &&
                            data.href &&
                            (data.actionTarget === 'attention' ? (
                                <button
                                    className="ov-focus-link"
                                    onClick={onAction}
                                >
                                    <ArrowUpRight size={16} />
                                    {data.actionLabel}
                                </button>
                            ) : (
                                <Link
                                    className="ov-focus-link"
                                    href={data.href}
                                >
                                    <ArrowUpRight size={16} />{' '}
                                    {data.actionLabel ?? 'Open workspace'}
                                </Link>
                            ))}
                    </>
                )}
                <div className="ov-focus-note">
                    <Info size={14} />
                    <span>{data.subtitle}</span>
                </div>
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
                    aria-label={`${data.title ?? meta.title}: ${data.series.map((p) => `${p.label}: ${data.seriesLabel ?? 'Recorded'} ${p.value === null ? 'No data' : `${p.value}${data.unit ?? ''}`}${p.comparison != null ? `, ${data.comparisonLabel} ${p.comparison}${data.unit ?? ''}` : ''}`).join('; ')}`}
                >
                    <ResponsiveContainer width="100%" height="100%">
                        <ComposedChart
                            data={data.series}
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
                                    fontSize: 11,
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
                                    fontSize: 11,
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
                    <div
                        className="ov-chart-inspector"
                        aria-label="Inspect chart values"
                    >
                        <div>
                            {(() => {
                                const lastMeasured = data.series
                                    .map(
                                        (point) =>
                                            point.value !== null ||
                                            point.comparison != null,
                                    )
                                    .lastIndexOf(true);
                                const start = Math.max(
                                    0,
                                    Math.min(
                                        data.series.length - 7,
                                        lastMeasured - 3,
                                    ),
                                );
                                return data.series.slice(start, start + 7);
                            })().map((point) => (
                                <button
                                    key={point.label}
                                    aria-pressed={selectedDate === point.label}
                                    onClick={() => setSelectedDate(point.label)}
                                >
                                    {formatDate(point.label)
                                        .split(' ')
                                        .slice(0, 2)
                                        .join(' ')}
                                </button>
                            ))}
                        </div>
                        <p aria-live="polite">
                            {(() => {
                                const point = data.series.find(
                                    (p) => p.label === selectedDate,
                                );
                                if (!point)
                                    return 'Select a date to see the figures.';
                                if (
                                    data.unit === '%' &&
                                    point.planned !== undefined
                                )
                                    return `${formatDate(point.label)} · ${point.value === null ? 'No ended shifts to measure' : `${point.value}% delivered · ${point.completed} of ${point.planned} planned shifts`}`;
                                return `${formatDate(point.label)} · ${data.seriesLabel ?? 'Recorded'}: ${point.value === null ? 'Not measured' : `${point.value}${data.unit ?? ''}`}${data.comparisonLabel ? ` · ${data.comparisonLabel}: ${point.comparison == null ? 'Not measured' : `${point.comparison}${data.unit ?? ''}`}` : ''}`;
                            })()}
                        </p>
                    </div>
                )}
                <details className="ov-data-table">
                    <summary>
                        View chart figures
                        {data.comparisonLabel &&
                            ` · ${data.seriesLabel} / ${data.comparisonLabel}`}
                    </summary>
                    <div>
                        {data.series.map((p) => (
                            <span key={p.label}>
                                {formatDate(p.label)}{' '}
                                <b>
                                    {p.value === null
                                        ? '—'
                                        : `${p.value}${data.unit ?? ''}`}
                                    {p.comparison !== undefined
                                        ? ` / ${p.comparison === null ? '—' : `${p.comparison}${data.unit ?? ''}`}`
                                        : ''}
                                </b>
                            </span>
                        ))}
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
        return (
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
                                No submitted requests are waiting in your
                                permitted review queues.
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
                        const featured = index === 0 && !expanded;
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
                                    {!featured && (
                                        <span
                                            className="ov-priority-dot"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <span className="ov-priority-copy">
                                        <strong>{row.label}</strong>
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
                        return row.href && !preview ? (
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
                    },
                )}
            </div>
        );
    }
    return (
        <div
            className={`ov-rows ${meta.id === 'attention' ? 'ov-attention' : ''}`}
        >
            {(meta.id === 'attention' && !expanded
                ? data.rows.slice(0, 3)
                : data.rows
            ).map((row, index) =>
                row.href && !preview ? (
                    <Link
                        key={`${row.label}-${index}`}
                        href={row.href}
                        className="ov-row"
                        data-tone={row.tone}
                    >
                        <RowContent row={row} />
                    </Link>
                ) : (
                    <div
                        key={`${row.label}-${index}`}
                        className="ov-row"
                        data-tone={row.tone}
                    >
                        <RowContent row={row} />
                    </div>
                ),
            )}
        </div>
    );
}
