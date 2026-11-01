import { EntityTable, ProgressValue, type MenuItem } from '@/components/lists';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { KnowList, Log, SideBySide } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import type { MouseEvent, ReactNode } from 'react';
import { ListPager } from './_parts';
import type { BatteryRow, DevicesIndexProps } from './_types';

const BAR_TONE = { critical: 'critical', warning: 'warning', success: 'success', info: 'brand', neutral: 'brand' } as const;

/** Upkeep › Batteries (drawing 2c), lowest first. */
export function BatteriesView({
    data,
    head,
    actionsFor,
    onContextMenu,
    onPage,
    now,
}: {
    data: NonNullable<DevicesIndexProps['batteries']>;
    head: ReactNode;
    actionsFor: (row: BatteryRow) => MenuItem[];
    onContextMenu: (event: MouseEvent, row: BatteryRow) => void;
    onPage: (page: number) => void;
    now: Date;
}) {
    return (
        <div className="flex flex-col gap-5">
            <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                <div className="border-b border-border px-4 py-3.5">{head}</div>
                {data.rows.length === 0 ? (
                    <p className="text-subtle px-4 py-6">No device here reports a battery level yet.</p>
                ) : (
                    <EntityTable<BatteryRow>
                        className="rounded-none border-0 shadow-none"
                        rows={data.rows}
                        rowKey={(row) => row.id}
                        identityLabel="Device"
                        identity={(row) => ({ icon: iconForKind(row.kind), name: row.name, subline: row.model, linkLabel: `Open ${row.name}` })}
                        hrefFor={(row) => row.href}
                        onOpen={(row) => router.visit(row.href)}
                        onRowContextMenu={onContextMenu}
                        actionsFor={actionsFor}
                        rowHeight="content"
                        minWidth={860}
                        columns={[
                            { key: 'where', label: 'Where', width: '1.2fr', cell: (row) => row.where ?? '—' },
                            {
                                key: 'battery',
                                label: 'Battery',
                                width: '1.1fr',
                                cell: (row) =>
                                    row.cant_check ? (
                                        <span className="flex flex-col items-start gap-1 py-1">
                                            <StatusBadge variant="neutral" size="sm">
                                                Can’t check
                                            </StatusBadge>
                                            <span className="text-xs text-muted-foreground">
                                                {row.behind ? `${row.behind} is offline · ` : ''}last known {row.battery}%
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="w-28">
                                            <ProgressValue percent={row.battery} tone={BAR_TONE[row.tone] ?? 'brand'}>
                                                {row.battery}%
                                            </ProgressValue>
                                        </span>
                                    ),
                            },
                            {
                                key: 'lasts',
                                label: 'Lasts about',
                                width: '1fr',
                                cell: () => <span className="text-muted-foreground">Not available</span>,
                            },
                            {
                                key: 'read',
                                label: 'Battery read',
                                width: '1fr',
                                cell: (row) => (row.read_at ? seenTime(row.read_at, now) : '—'),
                            },
                            {
                                key: 'changed',
                                label: 'Last changed',
                                width: '1fr',
                                cell: () => <span className="text-muted-foreground">Not available</span>,
                            },
                        ]}
                    />
                )}
                <ListPager meta={data.meta} noun="batteries" onPage={onPage} />
            </section>
            <KnowList
                title="How this list works"
                items={[
                    { tone: 'info', text: 'Lowest battery first, from each device’s latest report' },
                    { tone: 'info', text: 'Trackers are charged, not changed' },
                    { tone: 'none', text: 'Devices on mains power or PoE aren’t listed' },
                    {
                        tone: 'warning',
                        text: (
                            <>
                                <b className="font-semibold">No forecast yet:</b> working out how long a battery lasts needs its
                                last 30 days, and Oblivion keeps only the latest reading for now
                            </>
                        ),
                    },
                ]}
            />
        </div>
    );
}

/** Upkeep › Updates (drawing 2d). No source tells Oblivion yet which devices have newer software. */
export function UpdatesView({
    data,
    head,
}: {
    data: NonNullable<DevicesIndexProps['updates']>;
    head: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-5">
            <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                <div className="border-b border-border px-4 py-3.5">{head}</div>
                <p className="text-subtle px-4 py-6">
                    Oblivion can’t tell yet which devices have newer software. Reading it from UniFi,
                    RouterOS and Hikvision isn’t built yet, so nothing is listed here.
                </p>
            </section>
            <SideBySide cols="minmax(0, 1.3fr) minmax(0, 1fr)">
                <KnowList
                    title="How updates work here"
                    items={[
                        {
                            tone: 'info',
                            text: (
                                <>
                                    <b className="font-semibold">Oblivion shows updates, it doesn’t install them.</b> Update
                                    UniFi gear in UniFi, and cameras and routers on their own web page
                                </>
                            ),
                        },
                        {
                            tone: 'none',
                            text: 'Where they’ll come from: the UniFi Network API, RouterOS’s own update check through the hub, and the camera’s own update check (Hikvision ISAPI). None is read yet',
                        },
                    ]}
                />
                {data.recent.length > 0 ? (
                    <Log
                        title="Updated this month"
                        items={data.recent.map((event) => ({
                            key: event.id,
                            time: event.at ? formatDate(event.at) : '—',
                            tone: 'success',
                            title: event.name,
                            sub: event.versions ?? 'Its software changed',
                        }))}
                    />
                ) : (
                    <Log
                        title="Updated this month"
                        items={[{ key: 'none', tone: 'none', title: 'No software changes reported in the last 30 days' }]}
                    />
                )}
            </SideBySide>
        </div>
    );
}
