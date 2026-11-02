/**
 * Access › Activity (drawing pg-sdr-19f): the door log, newest first. Only staff matched at that
 * Site are named, for 90 days (D14); residents show as "Resident fob" (D19). Below it: today's
 * events worth a look, how people got in today, and what's kept.
 */
import { EntityTable, type MenuItem } from '@/components/lists';
import { KnowList, Log, QuietLine, Ring, Surface, type SgTone } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime } from '@/lib/security-devices/device-time';
import { DoorOpen } from 'lucide-react';
import type { MouseEvent } from 'react';
import { BlockHead, ListPager } from '../devices/_parts';
import type { ActivityData, ActivityRow } from './_types';

/** Chart colours for the ways in, in order; "Unlocked remotely" keeps the warning hue. */
const SLICE = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

function HowInDonut({ data }: { data: NonNullable<ActivityData['how_in']> }) {
    let at = 0;
    let colour = 0;
    const slices = data.parts.map((part) => {
        const share = data.total > 0 ? (part.value / data.total) * 100 : 0;
        const fill = part.label === 'Unlocked remotely' ? 'var(--status-warning)' : SLICE[colour++ % SLICE.length];
        const slice = { ...part, fill, from: at, to: at + share };
        at += share;
        return slice;
    });
    const gradient = slices.length > 0 ? slices.map((slice) => `${slice.fill} ${slice.from}% ${slice.to}%`).join(', ') : 'var(--muted) 0% 100%';
    return (
        <Surface title="How people got in today" sub="Every door at these Sites">
            <div className="flex items-center gap-5 px-[18px] py-4">
                <div
                    role="img"
                    aria-label={`${data.total} came in today: ${data.parts.map((part) => `${part.label} ${part.value}`).join(', ') || 'nobody yet'}`}
                    className="relative grid size-[120px] shrink-0 place-items-center rounded-full"
                    style={{ background: `conic-gradient(${gradient})` }}
                >
                    <span className="absolute inset-[18px] rounded-full bg-card" />
                    <span className="relative grid justify-items-center">
                        <b className="text-xl font-bold tracking-tight tabular-nums">{data.total}</b>
                        <small className="text-caption">came in</small>
                    </span>
                </div>
                {slices.length === 0 ? (
                    <p className="text-subtle">Nobody has come in through a door yet today.</p>
                ) : (
                    <dl className="grid min-w-0 flex-1 gap-2 text-sm">
                        {slices.map((slice) => (
                            <div key={slice.label} className="flex items-center gap-2">
                                <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full" style={{ background: slice.fill }} />
                                <dt>{slice.label}</dt>
                                <dd className="ml-auto font-semibold tabular-nums">{slice.value}</dd>
                            </div>
                        ))}
                    </dl>
                )}
            </div>
        </Surface>
    );
}

export function ActivityView({
    data,
    filtered,
    actionsFor,
    onContextMenu,
    onPage,
    now,
}: {
    data: ActivityData;
    filtered: boolean;
    actionsFor: (row: ActivityRow) => MenuItem[];
    onContextMenu: (event: MouseEvent, row: ActivityRow) => void;
    onPage: (page: number) => void;
    now: Date;
}) {
    const sub = `Newest first · names kept 90 days · residents show as “Resident fob”${data.person ? ` · only ${data.person}` : ''}`;
    return (
        <div className="flex flex-col gap-5">
            <section className="flex flex-col gap-3">
                <BlockHead title="Door events" sub={sub} />
                {data.rows.length === 0 ? (
                    <QuietLine
                        text={
                            !data.events_read
                                ? 'No door events yet: the hub hasn’t read any from UniFi Access.'
                                : filtered
                                  ? 'No door events match. Try another day, door or search.'
                                  : 'No door events today yet.'
                        }
                    />
                ) : (
                    <div className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                        <EntityTable<ActivityRow>
                            className="rounded-none border-0 shadow-none"
                            rows={data.rows}
                            rowKey={(row) => row.id}
                            identityLabel="When"
                            identityWidth="150px"
                            identity={(row) => ({ name: seenTime(row.at, now), linkLabel: `Actions for the event at ${seenTime(row.at, now)}` })}
                            columns={[
                                {
                                    key: 'door',
                                    label: 'Door',
                                    width: '1.4fr',
                                    cell: (row) => (
                                        <span className="flex items-center gap-1.5 py-1">
                                            <DoorOpen aria-hidden="true" className="size-3.5 shrink-0 text-muted-foreground" />
                                            {row.door} · {row.site}
                                        </span>
                                    ),
                                },
                                { key: 'who', label: 'Who', width: '1.2fr', cell: (row) => <span className={row.who.named ? 'font-medium' : 'text-muted-foreground'}>{row.who.text}</span> },
                                { key: 'how', label: 'How', width: '1fr', cell: (row) => row.how },
                                {
                                    key: 'what',
                                    label: 'What happened',
                                    width: '1.2fr',
                                    cell: (row) =>
                                        row.what.badge ? (
                                            <StatusBadge variant={row.what.badge.tone} size="sm">
                                                {row.what.badge.text}
                                            </StatusBadge>
                                        ) : (
                                            row.what.text
                                        ),
                                },
                            ]}
                            actionsFor={actionsFor}
                            onRowContextMenu={onContextMenu}
                            rowHeight="content"
                            minWidth={900}
                        />
                        <ListPager meta={data.meta} noun="door events" onPage={onPage} />
                    </div>
                )}
            </section>
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)]">
                {data.worth_a_look.length > 0 ? (
                    <Log
                        title="Worth a look today"
                        items={data.worth_a_look.map((item) => ({
                            key: item.key,
                            time: seenTime(item.at, now),
                            tone: item.tone as SgTone,
                            title: item.title,
                            sub: item.sub,
                        }))}
                    />
                ) : (
                    <Surface title="Worth a look today">
                        <p className="sg-quiet">
                            <Ring tone="success" />
                            <span>Nothing forced, held open or refused today.</span>
                        </p>
                    </Surface>
                )}
                {data.how_in ? <HowInDonut data={data.how_in} /> : null}
                <KnowList
                    title="What’s kept"
                    items={[
                        { tone: 'info', text: <>Names kept <b className="font-semibold">90 days</b>. After that, the event stays without a name</> },
                        { tone: 'info', text: <>Residents show as <b className="font-semibold">Resident fob</b></> },
                        { tone: 'info', text: 'Only staff matched at that Site are named. Anyone not matched shows as “Not matched to staff”' },
                        { tone: 'success', text: 'Events come in through each Site’s hub, every few minutes' },
                    ]}
                />
            </div>
        </div>
    );
}
