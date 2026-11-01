import { EntityTable, ProgressValue, type EntityTableColumn, type MenuItem } from '@/components/lists';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { planText, updateVersions, type FirmwareUpdate } from '@/components/security-devices/firmware-update-popup';
import { KnowList, Log, QuietLine, SideBySide } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDate } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import type { MouseEvent, ReactNode } from 'react';
import { ListPager, type ColumnChoice } from './_parts';
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

export const UPDATE_COLUMNS: ColumnChoice[] = [
    { key: 'where', label: 'Where' },
    { key: 'version', label: 'Version' },
    { key: 'fixes', label: 'What it fixes' },
    { key: 'plan', label: 'Plan' },
];

/**
 * Upkeep › Updates (drawing 2d, S&D Phase G): devices a maker's source says have newer software
 * (UniFi today), each opening pop-up 62. Nothing is listed that no source said; devices whose maker's
 * check isn't built are counted under "Not shown here", never shown as up to date.
 */
export function UpdatesView({
    data,
    head,
    shown,
    now,
    onOpen,
    actionsFor,
    onContextMenu,
}: {
    data: NonNullable<DevicesIndexProps['updates']>;
    head: ReactNode;
    /** Column keys chosen in Columns. */
    shown: string[];
    now: Date;
    onOpen: (row: FirmwareUpdate) => void;
    actionsFor: (row: FirmwareUpdate) => MenuItem[];
    onContextMenu: (event: MouseEvent, row: FirmwareUpdate) => void;
}) {
    const columns: EntityTableColumn<FirmwareUpdate>[] = [
        { key: 'where', label: 'Where', width: '1fr', cell: (row) => row.where ?? '—' },
        { key: 'version', label: 'Version', width: '1.1fr', cell: (row) => <span className="whitespace-nowrap tabular-nums">{updateVersions(row)}</span> },
        {
            key: 'fixes',
            label: 'What it fixes',
            width: '1.3fr',
            cell: () => <span className="text-muted-foreground">Not available</span>,
        },
        {
            key: 'plan',
            label: 'Plan',
            width: '1.5fr',
            cell: (row) => (
                <span className="flex flex-col items-start gap-1 py-1">
                    <StatusBadge variant={row.plan.tone} size="sm">
                        {planText(row.plan.label, row.plan.at, now)}
                    </StatusBadge>
                    <span className="text-xs text-muted-foreground">{planText(row.plan.note, row.plan.at, now)}</span>
                </span>
            ),
        },
    ];

    return (
        <div className="flex flex-col gap-5">
            <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                <div className="border-b border-border px-4 py-3.5">{head}</div>
                {data.rows.length > 0 ? (
                    <EntityTable<FirmwareUpdate>
                        className="rounded-none border-0 shadow-none"
                        rows={data.rows}
                        rowKey={(row) => row.id}
                        identityLabel="Device"
                        identity={(row) => ({ icon: iconForKind(row.kind), name: row.name, subline: row.model, linkLabel: `See the update for ${row.name}` })}
                        onOpen={onOpen}
                        onRowContextMenu={onContextMenu}
                        actionsFor={actionsFor}
                        rowHeight="content"
                        minWidth={900}
                        columns={columns.filter((column) => shown.includes(column.key))}
                    />
                ) : (
                    <p className="text-subtle px-4 py-6">
                        {data.source
                            ? 'No update is ready for the UniFi devices here, as UniFi reports it.'
                            : 'Oblivion can’t tell yet which devices have newer software. None of the devices here reports it: only UniFi says when an update is ready.'}
                    </p>
                )}
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
                            tone: 'info',
                            text: (
                                <>
                                    <b className="font-semibold">UniFi:</b> UniFi’s device list says when an update is ready and which version, but not what it fixes
                                </>
                            ),
                        },
                        {
                            tone: 'none',
                            text: (
                                <>
                                    <b className="font-semibold">MikroTik:</b> RouterOS’s own update check, through the hub, isn’t read yet
                                </>
                            ),
                        },
                        {
                            tone: 'none',
                            text: (
                                <>
                                    <b className="font-semibold">Hikvision:</b> the camera’s own update check isn’t read yet
                                </>
                            ),
                        },
                        {
                            tone: 'warning',
                            text: (
                                <>
                                    Set <b className="font-semibold">quiet time</b> first, so nobody is alerted while a device restarts
                                </>
                            ),
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
            <QuietLine
                title="Not shown here"
                text={
                    data.unchecked > 0
                        ? `${data.unchecked} ${data.unchecked === 1 ? 'device' : 'devices'} can’t be checked for updates here yet: only UniFi says when an update is ready. Windows updates are on each computer’s Health tab.`
                        : 'Windows updates are on each computer’s Health tab.'
                }
            />
        </div>
    );
}
