/* eslint-disable no-restricted-syntax -- Bucketed renewal groups ported 1:1 from
 * the design prototype: shield-tiled list rows + due pills are styled native
 * <button> surfaces, not shadcn primitives. Colours are token / color-mix. */
import { type CalendarLayerFeed } from '@/lib/calendar/layer-feed';
import { dayStart } from './calendar-render';

const DAY_MS = 86_400_000;

export function CalendarRenewals({
    renewals,
    today,
    onOpen,
}: {
    renewals: CalendarLayerFeed[] | null;
    today: Date;
    onOpen: (href: string) => void;
}) {
    const banner = (
        <div
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 11,
                borderRadius: 13,
                border: '1px solid color-mix(in oklch, var(--status-warning) 35%, var(--border))',
                background: 'var(--status-warning-bg)',
                padding: '12px 15px',
            }}
        >
            <svg
                width="18"
                height="18"
                viewBox="0 0 24 24"
                fill="none"
                stroke="var(--status-warning)"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                style={{ flex: 'none' }}
            >
                <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0ZM12 9v4M12 17h.01" />
            </svg>
            <div style={{ fontSize: 12.5, color: 'var(--foreground)' }}>
                Compliance &amp; cert renewals, folded in from the Compliance
                hub. <strong>Read-only here</strong> — actioning opens the staff
                member in Compliance.
            </div>
        </div>
    );

    if (renewals === null) {
        return (
            <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
                {banner}
                <div
                    style={{
                        fontSize: 13,
                        color: 'var(--muted-foreground)',
                        padding: '4px 2px',
                    }}
                >
                    Loading renewals…
                </div>
            </div>
        );
    }

    const t0 = dayStart(today).getTime();
    const sorted = [...renewals].sort(
        (a, b) => new Date(a.start).getTime() - new Date(b.start).getTime(),
    );
    const buckets: Record<'overdue' | 'soon' | 'later', CalendarLayerFeed[]> = {
        overdue: [],
        soon: [],
        later: [],
    };
    for (const e of sorted) {
        const days = Math.round(
            (dayStart(new Date(e.start)).getTime() - t0) / DAY_MS,
        );
        if (days < 0) buckets.overdue.push(e);
        else if (days <= 7) buckets.soon.push(e);
        else buckets.later.push(e);
    }

    const groupDefs: [keyof typeof buckets, string, string][] = [
        ['overdue', 'Overdue', 'var(--status-critical)'],
        ['soon', 'Due within 7 days', 'var(--status-warning)'],
        ['later', 'Due within 30 days', 'var(--status-info)'],
    ];
    const groups = groupDefs.filter(([key]) => buckets[key].length > 0);

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
            {banner}
            {groups.length === 0 ? (
                <div
                    style={{
                        fontSize: 13,
                        color: 'var(--muted-foreground)',
                        padding: '4px 2px',
                    }}
                >
                    Nothing expires in the next 30 days.
                </div>
            ) : null}
            {groups.map(([key, label, col]) => (
                <div key={key}>
                    <div
                        style={{
                            display: 'flex',
                            alignItems: 'baseline',
                            gap: 10,
                            marginBottom: 9,
                        }}
                    >
                        <span
                            style={{
                                height: 10,
                                width: 10,
                                borderRadius: 3,
                                background: col,
                            }}
                        />
                        <h3
                            style={{ margin: 0, fontSize: 14, fontWeight: 700 }}
                        >
                            {label}
                        </h3>
                        <span
                            style={{
                                fontSize: 12,
                                color: 'var(--muted-foreground)',
                            }}
                        >
                            {buckets[key].length} item
                            {buckets[key].length === 1 ? '' : 's'}
                        </span>
                    </div>
                    <div
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            gap: 7,
                        }}
                    >
                        {buckets[key].map((e) => {
                            const days = Math.round(
                                (dayStart(new Date(e.start)).getTime() - t0) /
                                    DAY_MS,
                            );
                            const c =
                                e.extendedProps.urgency === 'critical'
                                    ? 'var(--status-critical)'
                                    : 'var(--status-warning)';
                            const due =
                                days < 0
                                    ? `${Math.abs(days)}d overdue`
                                    : days === 0
                                      ? 'Today'
                                      : `in ${days}d`;
                            const requirement =
                                (e.extendedProps.requirement as string) ||
                                e.title;
                            const who =
                                (e.extendedProps.person as string) || '';
                            return (
                                <button
                                    key={e.id}
                                    type="button"
                                    onClick={() =>
                                        onOpen(e.deepLink ?? '/hr/compliance')
                                    }
                                    className="hrcal-renewal-row"
                                    style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 14,
                                        borderRadius: 13,
                                        border: '1px solid var(--border)',
                                        background: 'var(--card)',
                                        padding: '12px 15px',
                                        textAlign: 'left',
                                        cursor: 'pointer',
                                    }}
                                >
                                    <span
                                        style={{
                                            display: 'grid',
                                            height: 38,
                                            width: 38,
                                            flex: 'none',
                                            placeItems: 'center',
                                            borderRadius: 11,
                                            background: `color-mix(in oklch, ${c} 14%, var(--card))`,
                                            color: c,
                                        }}
                                    >
                                        <svg
                                            width="17"
                                            height="17"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                        >
                                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" />
                                        </svg>
                                    </span>
                                    <span style={{ minWidth: 0, flex: 1 }}>
                                        <span
                                            style={{
                                                display: 'block',
                                                fontSize: 14,
                                                fontWeight: 600,
                                            }}
                                        >
                                            {requirement}
                                        </span>
                                        <span
                                            style={{
                                                display: 'block',
                                                fontSize: 12,
                                                color: 'var(--muted-foreground)',
                                            }}
                                        >
                                            {who}
                                        </span>
                                    </span>
                                    <span
                                        style={{
                                            flex: 'none',
                                            fontSize: 12,
                                            fontWeight: 700,
                                            color: c,
                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                        {due}
                                    </span>
                                    <span
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            gap: 5,
                                            fontSize: 11.5,
                                            fontWeight: 700,
                                            color: 'var(--primary)',
                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                        Open in Compliance
                                        <svg
                                            width="13"
                                            height="13"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                        >
                                            <path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />
                                        </svg>
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
        </div>
    );
}

export default CalendarRenewals;
