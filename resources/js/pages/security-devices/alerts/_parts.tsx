/** Parts of the Alerts page (pg-sdr-13 / 13b): the selected alert's next step and story, and the two tables. */
import { EntityTable, type MenuItem } from '@/components/lists';
import { alertLine } from '@/components/security-devices/alert-popups';
import {
    Log,
    PointBars,
    QuietLine,
    Ring,
    Story,
    Surface,
    type SgTone,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate, formatTime } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import { cn } from '@/lib/utils';
import {
    Bell,
    BellRing,
    CircleCheck,
    Clock,
    Flag,
    MapPin,
    Network,
    WifiOff,
    type LucideIcon,
} from 'lucide-react';
import { Fragment, type MouseEvent, type ReactNode } from 'react';
import type { AlertRuleRow, AlertTone, DoneRow, SelectedAlert } from './_types';

/** "**bold**" in a server line becomes the figure. */
export function Bolded({ text }: { text: string }): ReactNode {
    return (
        <>
            {text
                .split(/\*\*(.+?)\*\*/g)
                .map((part, index) =>
                    index % 2 === 1 ? (
                        <b key={index}>{part}</b>
                    ) : (
                        <Fragment key={index}>{part}</Fragment>
                    ),
                )}
        </>
    );
}

const FACT_ICON: Record<string, LucideIcon> = {
    'map-pin': MapPin,
    clock: Clock,
    network: Network,
};

const LENS: Record<AlertTone, string> = {
    critical:
        'bg-status-critical-bg text-status-critical ring-status-critical/60',
    warning: 'bg-status-warning-bg text-status-warning ring-status-warning/60',
    success: 'bg-status-success-bg text-status-success ring-status-success/60',
};

/**
 * The selected alert's next step (the drawing's "next" box): what state it's in and for how long, the
 * alert, its device, and what you can do. Buttons show only when the server allows them.
 */
export function AlertNextBox({
    alert,
    now,
    onAcknowledge,
    onAssign,
    onSnooze,
    onDone,
}: {
    alert: SelectedAlert;
    now: Date;
    onAcknowledge: () => void;
    onAssign: () => void;
    onSnooze: () => void;
    onDone?: () => void;
}) {
    const label = alertLine(alert.label.text, alert.label.at, now, {
        closed: alert.closed_at,
    });
    const someone = alert.assigned
        ? `${alert.assigned.name} is looking after it${alert.assigned.at ? ` · since ${seenTime(alert.assigned.at, now)}` : ''}`
        : alert.acknowledged
          ? `${alert.acknowledged.by} has it${alert.acknowledged.at ? ` · since ${seenTime(alert.acknowledged.at, now)}` : ''}`
          : null;
    const note = alert.acknowledged?.note ?? null;

    return (
        <Surface tone={alert.tone} aria-label="The alert you chose">
            <div className="flex flex-wrap items-center gap-4 px-5 py-4">
                <span
                    aria-hidden="true"
                    className={cn(
                        'grid size-[46px] shrink-0 place-items-center rounded-full ring-2 ring-offset-2 ring-offset-card',
                        LENS[alert.tone],
                    )}
                >
                    {alert.tone === 'success' ? (
                        <CircleCheck className="size-5" />
                    ) : (
                        <Flag className="size-5" />
                    )}
                </span>
                <div className="grid min-w-0 flex-1 gap-0.5">
                    <span className="sg-kick">
                        <Ring tone={alert.tone} />
                        {label}
                    </span>
                    <h2 className="text-section-title">{alert.title}</h2>
                    {alert.device?.sub ? (
                        <p className="text-subtle">{alert.device.sub}</p>
                    ) : null}
                </div>
                {alert.can.assign ||
                alert.can.snooze ||
                alert.can.acknowledge ||
                alert.can.done ? (
                    <div className="flex shrink-0 flex-wrap gap-2">
                        {alert.can.assign ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onAssign}
                            >
                                Assign
                            </Button>
                        ) : null}
                        {alert.can.snooze ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onSnooze}
                            >
                                Snooze
                            </Button>
                        ) : null}
                        {alert.can.done && onDone ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onDone}
                            >
                                Mark done
                            </Button>
                        ) : null}
                        {alert.can.acknowledge ? (
                            <Button size="sm" onClick={onAcknowledge}>
                                Acknowledge
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </div>
            {someone ? (
                <p className="flex items-start gap-2.5 border-t border-dashed border-border bg-muted/50 px-5 py-2.5 text-sm">
                    <span className="pt-1">
                        <Ring tone="info" />
                    </span>
                    <span className="min-w-0">
                        <b className="font-semibold">{someone}</b>
                        {note ? (
                            <span className="text-muted-foreground">
                                {' '}
                                · “{note}”
                            </span>
                        ) : null}
                    </span>
                </p>
            ) : null}
        </Surface>
    );
}

/** What happened (facts, what we saw, what it affects), the timeline, and "Is someone already on it?". */
export function AlertStory({
    alert,
    now,
    onReportIncident,
}: {
    alert: SelectedAlert;
    now: Date;
    onReportIncident: (() => void) | null;
}) {
    return (
        <>
            <Story
                title="What happened"
                facts={alert.facts.map((fact) => ({
                    icon: FACT_ICON[fact.icon],
                    // When it started always shows its day, as drawn: "**Thu 29 Oct**, 7:42 am".
                    text:
                        fact.at && fact.text === '**{time}**' ? (
                            <>
                                <b>{formatDate(fact.at)}</b>,{' '}
                                {formatTime(fact.at)}
                            </>
                        ) : (
                            <Bolded text={alertLine(fact.text, fact.at, now)} />
                        ),
                }))}
                paras={[
                    {
                        h: 'What we saw',
                        t: alertLine(alert.saw, alert.opened_at, now),
                    },
                    ...(alert.affects
                        ? [{ h: 'What it affects', t: alert.affects.text }]
                        : []),
                ]}
            />
            {alert.timeline.length > 0 ? (
                <Log
                    title="Timeline"
                    limit={50}
                    items={alert.timeline.map((item) => ({
                        key: item.id,
                        time: seenTime(item.at, now),
                        tone: item.tone as SgTone,
                        title: item.title,
                        sub: item.sub ?? undefined,
                    }))}
                />
            ) : (
                <QuietLine
                    title="Timeline"
                    text="Nothing recorded on this alert yet."
                />
            )}
            <QuietLine
                title="Is someone already on it?"
                text="Acknowledge it so others know. Report an incident only if someone was hurt or put at risk."
                action={
                    onReportIncident && alert.can.report_incident
                        ? {
                              label: 'Report incident',
                              onClick: onReportIncident,
                          }
                        : null
                }
            />
        </>
    );
}

/** The Done table (alerts closed in the last 30 days). */
export function DoneTable({
    rows,
    now,
    actionsFor,
    onOpen,
    onContextMenu,
}: {
    rows: DoneRow[];
    now: Date;
    actionsFor: (row: DoneRow) => MenuItem[];
    onOpen: (row: DoneRow) => void;
    onContextMenu: (event: MouseEvent, row: DoneRow) => void;
}) {
    return (
        <EntityTable<DoneRow>
            className="rounded-none border-0 shadow-none"
            rows={rows}
            rowKey={(row) => row.id}
            identityLabel="What"
            identity={(row) => ({
                icon: BellRing,
                name: row.title,
                linkLabel: `See what happened: ${row.title}`,
            })}
            identityWidth="2fr"
            columns={[
                {
                    key: 'site',
                    label: 'Site',
                    width: '1fr',
                    cell: (row) => row.site ?? '—',
                },
                {
                    key: 'opened',
                    label: 'Opened',
                    width: '150px',
                    cell: (row) => (
                        <span className="whitespace-nowrap">
                            {seenTime(row.opened_at, now)}
                        </span>
                    ),
                },
                {
                    key: 'lasted',
                    label: 'Lasted',
                    width: '110px',
                    cell: (row) => row.lasted,
                },
                {
                    key: 'how',
                    label: 'How it ended',
                    width: '1.5fr',
                    cell: (row) => row.how,
                },
            ]}
            actionsFor={actionsFor}
            onOpen={onOpen}
            onRowContextMenu={onContextMenu}
            rowHeight="content"
            minWidth={880}
        />
    );
}

const RULE_ICON: Record<string, LucideIcon> = {
    'wifi-off': WifiOff,
    bell: Bell,
};

/** The Rules & automations table (13b): when, where, then, last ran, on. */
export function RulesTable({
    rows,
    actionsFor,
    onContextMenu,
}: {
    rows: AlertRuleRow[];
    actionsFor: (row: AlertRuleRow) => MenuItem[];
    onContextMenu: (event: MouseEvent, row: AlertRuleRow) => void;
}) {
    return (
        <EntityTable<AlertRuleRow>
            className="rounded-none border-0 shadow-none"
            rows={rows}
            rowKey={(row) => row.id}
            identityLabel="When"
            identity={(row) => ({
                icon: RULE_ICON[row.icon] ?? Bell,
                name: row.when,
                subline: `${row.sub} · ${row.name}`,
            })}
            identityWidth="1.6fr"
            wrapIdentity
            columns={[
                {
                    key: 'where',
                    label: 'Where',
                    width: '1fr',
                    cell: (row) => row.where,
                },
                {
                    key: 'then',
                    label: 'Then',
                    width: '2.4fr',
                    cell: (row) => <span className="py-1">{row.then}</span>,
                },
                {
                    key: 'ran',
                    label: 'Last ran',
                    width: '110px',
                    cell: () => (
                        <span className="text-muted-foreground">
                            Not available
                        </span>
                    ),
                },
                {
                    key: 'on',
                    label: 'On',
                    width: '76px',
                    cell: (row) => (
                        <StatusBadge
                            variant={row.on ? 'success' : 'neutral'}
                            size="sm"
                        >
                            {row.on ? 'On' : 'Off'}
                        </StatusBadge>
                    ),
                },
            ]}
            actionsFor={actionsFor}
            onRowContextMenu={onContextMenu}
            mutedFor={(row) => !row.on}
            rowHeight="content"
            minWidth={900}
        />
    );
}

/** Alerts per week (13b), with one sentence on what changed. */
export function AlertsPerWeek({
    weeks,
}: {
    weeks: { labels: string[]; values: number[] };
}) {
    const values = weeks.values;
    const last = values[values.length - 1] ?? 0;
    const before = values.length > 1 ? values[values.length - 2] : null;
    const note = (
        <>
            <b>{last} this week</b>
            {before === null
                ? '.'
                : last === before
                  ? ', the same as the week before.'
                  : last < before
                    ? `, down from ${before}.`
                    : `, up from ${before}.`}
        </>
    );

    return (
        <PointBars
            title="Alerts per week"
            sub="Opened at your Sites"
            values={values}
            labels={weeks.labels}
            note={note}
        />
    );
}

/** A block that couldn't load: why, and Retry. */
export function BlockFailed({
    title,
    text,
    onRetry,
}: {
    title: string;
    text: string;
    onRetry: () => void;
}) {
    return (
        <Surface title={title}>
            <div className="flex flex-wrap items-center gap-3 px-[18px] pt-2 pb-4">
                <p className="text-subtle">{text}</p>
                <Button variant="outline" size="sm" onClick={onRetry}>
                    Retry
                </Button>
            </div>
        </Surface>
    );
}
