/**
 * Overview › Activity (S&D drawing pg-sdr-18c): a day's stream across the Sites the viewer can see,
 * newest first, in plain sentences, each with what happened next; the night before it is its own
 * block for whoever starts in the morning. Alerts are still acted on in Alerts.
 */
import { DonutChart } from '@/components/dashboard/donut-chart';
import { EntityContextMenu, EntityTable, useEntityContextMenu, type EntityTableColumn, type MenuItem } from '@/components/lists';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { KnowList, Log, QuietLine, SideBySide, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly, formatTime, WORKER_TIMEZONE } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import { BellOff, Cpu, DoorOpen, Download, ExternalLink, Radar, SunMoon, TriangleAlert, type LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { BlockHead, ColumnsMenu, ListPager, useColumns, type ColumnChoice } from '../devices/_parts';
import { lineAction } from '../sites/_logbook';
import type { StreamLine } from '../sites/_types';
import type { ActivityData, ActivityGroup } from './_types';

const PAGE_SIZE = 25;

const COLUMNS: ColumnChoice[] = [
    { key: 'when', label: 'When' },
    { key: 'site', label: 'Site' },
    { key: 'kind', label: 'Kind' },
    { key: 'next', label: 'What happened next' },
];

/** Each group's colour in the donut: the chart tokens, with H&S on the safety tone. */
const GROUP_COLOUR: Record<ActivityGroup, string> = {
    devices: 'var(--chart-1)',
    alarm: 'var(--chart-2)',
    alerts: 'var(--status-warning)',
    doors: 'var(--chart-3)',
    planned: 'var(--chart-4)',
    hs: 'var(--status-critical)',
};

type Row = StreamLine & { group?: ActivityGroup };

/** The line's kind as a badge, toned by what happened. */
export function kindBadge(line: StreamLine): { text: string; tone: StatusVariant } {
    const tone: StatusVariant = line.tone === 'critical' ? 'critical' : line.tone === 'warning' ? 'warning' : line.tone === 'success' ? 'success' : 'info';
    switch (line.kind) {
        case 'alert':
            return { text: 'Alert', tone: line.tone === 'critical' ? 'critical' : 'warning' };
        case 'hs':
            return { text: 'H&S', tone: 'critical' };
        case 'alarm':
            return { text: 'Alarm', tone: 'info' };
        case 'mode':
            return { text: 'House mode', tone: 'info' };
        case 'door':
            return { text: 'Door', tone };
        case 'planned':
            return { text: 'Planned work', tone: 'neutral' };
        default:
            return { text: line.device_id === null ? 'Scan' : 'Device', tone: 'neutral' };
    }
}

function lineIcon(line: StreamLine): LucideIcon {
    if (line.device_kind) return iconForKind(line.device_kind);
    if (line.kind === 'mode') return SunMoon;
    if (line.kind === 'door') return DoorOpen;
    if (line.kind === 'planned') return BellOff;
    if (line.kind === 'device') return Radar;
    return TriangleAlert;
}

export function ActivityBody({ activity, now, canExport }: { activity: ActivityData; now: Date; canExport: boolean }) {
    const [page, setPage] = useState(1);
    const menu = useEntityContextMenu<Row>();
    const columns = useColumns('sd.overview.activity.columns', COLUMNS, COLUMNS.map((column) => column.key));
    const lastPage = Math.max(1, Math.ceil(activity.rows.length / PAGE_SIZE));
    const current = Math.min(page, lastPage);
    const shown = activity.rows.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);
    const dayLabel = activity.today ? 'Today' : formatDateOnly(activity.day);
    const total = activity.by_kind.reduce((sum, group) => sum + group.count, 0);

    const actionsFor = (line: Row): MenuItem[] => {
        const own = lineAction(line);
        return [
            ...(own?.href ? [{ label: own.label, icon: ExternalLink, onClick: () => router.visit(own.href as string) }] : []),
            ...(line.device_id !== null && own?.href !== `/security-devices/devices/${line.device_id}`
                ? [{ label: 'Open the device', icon: Cpu, onClick: () => router.visit(`/security-devices/devices/${line.device_id}`) }]
                : []),
        ];
    };
    const all: Record<string, EntityTableColumn<Row>> = {
        when: { key: 'when', label: 'When', width: '90px', cell: (line) => <span className="whitespace-nowrap">{formatTime(line.at)}</span> },
        site: { key: 'site', label: 'Site', width: '0.8fr', cell: (line) => <span className="py-1 whitespace-normal">{line.site || '—'}</span> },
        kind: {
            key: 'kind',
            label: 'Kind',
            width: '120px',
            cell: (line) => {
                const badge = kindBadge(line);
                return (
                    <StatusBadge variant={badge.tone} size="sm">
                        {badge.text}
                    </StatusBadge>
                );
            },
        },
        next: { key: 'next', label: 'What happened next', width: '1.3fr', cell: (line) => <span className="py-1 whitespace-normal">{line.next ?? <span className="text-muted-foreground">—</span>}</span> },
    };
    const night = activity.overnight;

    return (
        <div className="flex flex-col gap-5">
            <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                <div className="border-b border-border px-4 py-3.5">
                    <BlockHead
                        title={dayLabel}
                        sub="Newest first, in plain words · only the Sites you can see · click a row to open it, ⋮ or right-click for its menu"
                        right={
                            <>
                                <ColumnsMenu all={COLUMNS} shown={columns.shown} onToggle={columns.toggle} />
                                {canExport ? (
                                    <Button variant="outline" size="sm" asChild>
                                        <a href="/security-devices/exports/events.csv" download>
                                            <Download className="size-4" />
                                            Export…
                                        </a>
                                    </Button>
                                ) : null}
                            </>
                        }
                    />
                </div>
                {activity.rows.length === 0 ? (
                    <p className="text-subtle px-4 py-6">{activity.kind === 'all' ? 'Nothing has happened yet on this day.' : 'Nothing of that kind on this day. Try Every kind.'}</p>
                ) : (
                    <EntityTable<Row>
                        className="rounded-none border-0 shadow-none"
                        rows={shown}
                        rowKey={(line) => line.key}
                        identityLabel="What happened"
                        identity={(line) => ({ icon: lineIcon(line), name: line.title, subline: line.sub ?? undefined, linkLabel: `Open: ${line.title}` })}
                        hrefFor={(line) => line.href}
                        columns={columns.shown.map((key) => all[key]).filter(Boolean)}
                        actionsFor={actionsFor}
                        onOpen={(line) => (line.href ? router.visit(line.href) : undefined)}
                        onRowContextMenu={(event, line) => menu.open(event, line)}
                        rowHeight="content"
                        minWidth={900}
                    />
                )}
                <ListPager
                    meta={{
                        total: activity.rows.length,
                        page: current,
                        last_page: lastPage,
                        from: activity.rows.length === 0 ? 0 : (current - 1) * PAGE_SIZE + 1,
                        to: Math.min(activity.rows.length, current * PAGE_SIZE),
                    }}
                    noun="events"
                    onPage={setPage}
                />
                {activity.total > activity.rows.length ? (
                    <p className="text-subtle px-4 pb-3">
                        Showing {activity.rows.length} of {activity.total}. Choose one kind to see more of it.
                    </p>
                ) : null}
            </section>
            <SideBySide cols="minmax(0, 1.3fr) minmax(0, 1fr) minmax(0, 1fr)">
                {night.rows.length === 0 ? (
                    <QuietLine title="Overnight" sub={nightWords(activity)} text="Nothing happened overnight." />
                ) : (
                    <Log
                        title="Overnight"
                        sub={nightWords(activity)}
                        limit={night.rows.length}
                        items={night.rows.map((line) => ({
                            key: line.key,
                            time: seenTime(line.at, new Date(night.to)),
                            tone: line.tone,
                            title: line.title,
                            sub: [line.sub, line.site].filter(Boolean).join(' · ') || undefined,
                            action: line.action ? { label: line.action.label, href: line.action.href, ariaLabel: `${line.action.label}: ${line.title}` } : null,
                        }))}
                    />
                )}
                <Surface title={`${dayLabel} by kind`} sub={activity.today ? 'Midnight to now' : 'The whole day'}>
                    <div className="px-[18px] pt-2 pb-4">
                        {total === 0 ? (
                            <p className="text-subtle">Nothing yet.</p>
                        ) : (
                            <DonutChart
                                size={140}
                                thickness={20}
                                centerValue={activity.today ? formatTime(now) : 'All day'}
                                centerLabel={activity.today ? 'so far' : undefined}
                                data={activity.by_kind.filter((group) => group.count > 0).map((group) => ({ label: group.label, value: group.count, color: GROUP_COLOUR[group.key] }))}
                            />
                        )}
                    </div>
                </Surface>
                <KnowList
                    title="What’s in this stream"
                    items={[
                        {
                            tone: 'info',
                            text: `Alerts and how they ended, the alarm and house modes, ${activity.doors ? 'doors worth a look, ' : ''}scans, settings changes, planned quiet times and H&S hazards from sensors`,
                        },
                        {
                            tone: 'info',
                            text: (
                                <>
                                    <b>Every door coming and going</b> is in Access › Activity. Door rows name staff only for people who can see Access, and only for 90 days;
                                    residents are never named
                                </>
                            ),
                        },
                        { tone: 'info', text: 'For people who may see device events. A resident’s sensor names them only if you can open their record; otherwise it says “a person”' },
                        {
                            tone: 'info',
                            text: (
                                <>
                                    The <b>Today</b> filter picks another day with the date picker
                                </>
                            ),
                        },
                    ]}
                />
            </SideBySide>

            {menu.ctx ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={lineIcon(menu.ctx.record)}
                    title={menu.ctx.record.title}
                    items={actionsFor(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}
        </div>
    );
}

/** "Wed 9:00 pm – Thu 7:00 am · Night mode at Aurora House and Kauri House". */
function nightWords(activity: ActivityData): string {
    const night = activity.overnight;
    const from = new Date(night.from);
    const to = new Date(night.to);
    const day = (date: Date) => date.toLocaleDateString('en-NZ', { weekday: 'short', timeZone: WORKER_TIMEZONE });
    const sites = night.sites;
    const where = sites.length === 0 ? '' : ` · Night mode at ${sites.length === 1 ? sites[0] : `${sites.slice(0, -1).join(', ')} and ${sites[sites.length - 1]}`}`;
    return `${day(from)} ${formatTime(from)} – ${day(to)} ${formatTime(to)}${where}`;
}
