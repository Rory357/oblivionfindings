/**
 * Devices › Upkeep › Not watched (S&D v13, pg-sdr-2e) and Retired (pg-sdr-2f).
 *
 * Not watched: devices Oblivion knows but doesn't check, grey and never red, each with why (the
 * stored stop-watching reason in plain words, or how it was added without checks, and its own note)
 * and since when. "Watch this device" (pop-up 9) is on each row's ⋮ menu for people who manage
 * checks.
 *
 * Retired: removed devices, read-only, with when, who and what's kept; the chosen one shows its
 * last logbook lines. One meaning only (Stephan, 1 Nov): Remove device keeps the history. There is
 * no Retire action and nothing here puts a device back.
 */
import { EntityContextMenu, EntityTable, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { KnowList, Log, PersonChip, SideBySide } from '@/components/signature/blocks';
import { formatDate, formatDateOnly, WORKER_TIMEZONE } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { useEffect, useState, type MouseEvent, type ReactNode } from 'react';
import { logbookItems } from './_device-logbook';
import { ListPager, type ColumnChoice } from './_parts';
import type { DevicesIndexProps, NotWatchedRow, RetiredRow } from './_types';

const nzDay = (iso: string) => new Date(iso).toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE });

/** "Mon 12 Oct" this year, "12 Oct 2025" before. */
function dayWords(iso: string | null, now: Date): string {
    if (!iso) return '—';
    const day = nzDay(iso);
    const today = now.toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE });
    return day.slice(0, 4) === today.slice(0, 4) ? formatDate(iso) : formatDateOnly(day);
}

export const NOT_WATCHED_COLUMNS: ColumnChoice[] = [
    { key: 'where', label: 'Where' },
    { key: 'why', label: 'Why it isn’t watched' },
    { key: 'since', label: 'Since' },
    { key: 'seen', label: 'Last seen' },
];

export const RETIRED_COLUMNS: ColumnChoice[] = [
    { key: 'was_at', label: 'Was at' },
    { key: 'when', label: 'When' },
    { key: 'by', label: 'By' },
    { key: 'kept', label: 'What’s kept' },
];

export function NotWatchedView({
    data,
    head,
    shown,
    now,
    actionsFor,
    onContextMenu,
    onPage,
}: {
    data: NonNullable<DevicesIndexProps['not_watched']>;
    head: ReactNode;
    shown: string[];
    now: Date;
    actionsFor: (row: NotWatchedRow) => MenuItem[];
    onContextMenu: (event: MouseEvent, row: NotWatchedRow) => void;
    onPage: (page: number) => void;
}) {
    const columns = [
        { key: 'where', label: 'Where', width: '1fr', cell: (row: NotWatchedRow) => row.where ?? '—' },
        {
            key: 'why',
            label: 'Why it isn’t watched',
            width: '1.8fr',
            cell: (row: NotWatchedRow) => (
                <span className="grid gap-0.5 py-1">
                    <span>{row.why}</span>
                    {row.note ? <small className="text-caption">Its note: “{row.note}”</small> : null}
                </span>
            ),
        },
        { key: 'since', label: 'Since', width: '0.7fr', cell: (row: NotWatchedRow) => <span className="whitespace-nowrap">{dayWords(row.since, now)}</span> },
        {
            key: 'seen',
            label: 'Last seen',
            width: '1fr',
            cell: (row: NotWatchedRow) => (row.seen_at ? `Seen ${seenTime(row.seen_at, now)}` : <span className="text-muted-foreground">Not seen by Oblivion</span>),
        },
    ];

    return (
        <div className="flex flex-col gap-5">
            <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                <div className="border-b border-border px-4 py-3.5">{head}</div>
                {data.rows.length === 0 ? (
                    <p className="text-subtle px-4 py-6">Every device here is watched.</p>
                ) : (
                    <EntityTable<NotWatchedRow>
                        className="rounded-none border-0 shadow-none"
                        rows={data.rows}
                        rowKey={(row) => row.id}
                        identityLabel="Device"
                        identity={(row) => ({ icon: iconForKind(row.kind), name: row.name, subline: row.sub, linkLabel: `Open ${row.name}` })}
                        hrefFor={(row) => row.href}
                        onOpen={(row) => router.visit(row.href)}
                        onRowContextMenu={onContextMenu}
                        actionsFor={actionsFor}
                        rowHeight="content"
                        minWidth={920}
                        columns={columns.filter((column) => shown.includes(column.key))}
                    />
                )}
                <ListPager meta={data.meta} noun="devices" onPage={onPage} />
            </section>
            <SideBySide cols="minmax(0, 1.3fr) minmax(0, 1fr)">
                <KnowList
                    title="What “not watched” means"
                    items={[
                        {
                            tone: 'none',
                            text: (
                                <>
                                    <b className="font-semibold">No checks and no alerts.</b> It still shows on the network map and in Rooms, in grey
                                </>
                            ),
                        },
                        {
                            tone: 'info',
                            text: (
                                <>
                                    Its history stays. <b className="font-semibold">Watch this device</b> starts checks again, with sensible timings already set
                                </>
                            ),
                        },
                        { tone: 'info', text: 'Phones and tablets that come and go never land here: they stay in scan results unless someone adds them' },
                        {
                            tone: 'warning',
                            text: (
                                <>
                                    Stopping watching a <b className="font-semibold">safety device</b>, or something others depend on, like a switch or sensor gateway, is flagged
                                    when you save and needs a reason
                                </>
                            ),
                        },
                    ]}
                />
                <Log
                    title="Watching changed lately"
                    sub="The last 30 days"
                    items={
                        data.recent.length > 0
                            ? data.recent.map((line) => ({ key: line.key, time: dayWords(line.at, now), tone: line.tone, title: line.title, sub: line.sub ?? undefined }))
                            : [{ key: 'none', tone: 'none', title: 'No device was added without checks or stopped being watched in the last 30 days' }]
                    }
                />
            </SideBySide>
        </div>
    );
}

export function RetiredView({
    data,
    head,
    shown,
    now,
    onSelect,
    onPage,
}: {
    data: NonNullable<DevicesIndexProps['retired']>;
    head: ReactNode;
    shown: string[];
    now: Date;
    onSelect: (row: RetiredRow) => void;
    onPage: (page: number) => void;
}) {
    const selected = data.selected;
    const [whole, setWhole] = useState(false);
    useEffect(() => setWhole(false), [selected?.id]);
    const lines = selected ? (whole ? selected.lines : selected.lines.slice(0, 6)) : [];
    // The kebab and right-click carry the same one item (DESIGN.md entity rows).
    const menu = useEntityContextMenu<RetiredRow>();
    const actionsFor = (row: RetiredRow): MenuItem[] => [{ label: 'Show its logbook lines', icon: ScrollText, onClick: () => onSelect(row) }];

    const columns = [
        { key: 'was_at', label: 'Was at', width: '1fr', cell: (row: RetiredRow) => row.was_at ?? '—' },
        { key: 'when', label: 'When', width: '0.8fr', cell: (row: RetiredRow) => <span className="whitespace-nowrap">{row.removed_at ? formatDateOnly(nzDay(row.removed_at)) : '—'}</span> },
        { key: 'by', label: 'By', width: '0.8fr', cell: (row: RetiredRow) => (row.by ? <PersonChip name={row.by} /> : <span className="text-muted-foreground">Not recorded</span>) },
        { key: 'kept', label: 'What’s kept', width: '1.2fr', cell: (row: RetiredRow) => row.kept },
    ];

    return (
        <div className="flex flex-col gap-5">
            <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                <div className="border-b border-border px-4 py-3.5">{head}</div>
                {data.rows.length === 0 ? (
                    <p className="text-subtle px-4 py-6">No device has been removed from your Sites.</p>
                ) : (
                    <EntityTable<RetiredRow>
                        className="rounded-none border-0 shadow-none"
                        rows={data.rows}
                        rowKey={(row) => row.id}
                        identityLabel="Device"
                        identity={(row) => ({ icon: iconForKind(row.kind), name: row.name, subline: row.sub })}
                        onOpen={onSelect}
                        selectedKeys={new Set(selected ? [selected.id] : [])}
                        actionsFor={actionsFor}
                        onRowContextMenu={(event, row) => menu.open(event, row)}
                        rowHeight="content"
                        minWidth={900}
                        columns={columns.filter((column) => shown.includes(column.key))}
                    />
                )}
                <ListPager meta={data.meta} noun="removed devices" onPage={onPage} />
            </section>
            <SideBySide cols="minmax(0, 1.2fr) minmax(0, 1fr)">
                {selected ? (
                    <Log
                        title={`${selected.name} · its last logbook lines`}
                        sub={`Read-only${selected.removed_at ? ` · removed ${dayWords(selected.removed_at, now)}` : ''}${selected.by ? ` by ${selected.by}` : ''} · only from while it was at your Sites`}
                        action={
                            selected.lines.length > 6
                                ? { label: whole ? 'Fewer lines' : 'Whole history', onClick: () => setWhole((current) => !current) }
                                : null
                        }
                        limit={Math.max(lines.length, 1)}
                        items={
                            lines.length > 0
                                ? logbookItems(lines, now)
                                : [{ key: 'none', tone: 'none', title: 'Nothing recorded from while it was at your Sites' }]
                        }
                    />
                ) : (
                    <Log title="Its last logbook lines" items={[{ key: 'none', tone: 'none', title: 'Choose a removed device to see its last lines' }]} />
                )}
                <KnowList
                    title="What Remove device keeps"
                    items={[
                        {
                            tone: 'info',
                            text: (
                                <>
                                    <b className="font-semibold">Remove device</b> takes it off every list and stops its checks. Its logbook, readings, alerts,
                                    settings backups, clip references and viewing log stay here, read-only
                                </>
                            ),
                        },
                        {
                            tone: 'info',
                            text: (
                                <>
                                    Its checks go first: <b className="font-semibold">Stop watching</b>, then remove. The pop-up says so if they’re still on
                                </>
                            ),
                        },
                        { tone: 'none', text: 'The device itself isn’t changed' },
                    ]}
                />
            </SideBySide>
            {menu.ctx ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={iconForKind(menu.ctx.record.kind)}
                    title={menu.ctx.record.name}
                    items={actionsFor(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}
        </div>
    );
}
