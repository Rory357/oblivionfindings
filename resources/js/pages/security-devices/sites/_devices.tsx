/**
 * Site › Devices (S&D drawing pg-sdr-12g): the Site's own device list, as Devices' List: problems
 * first (worst at the top), then by name; devices that aren't watched last, in grey. The room
 * replaces the Site; Select opens the Devices bulk bar; how the devices are checked sits beside it.
 */
import { EntityContextMenu, EntityTable, useEntityContextMenu, type EntityTableColumn, type MenuItem } from '@/components/lists';
import { PopupConfirm, PopupSelectionBar } from '@/components/popup';
import {
    BulkActionConfirm,
    BulkActionsMenu,
    bulkResultLines,
    type BulkAction,
    type BulkOptions,
    type BulkResult,
} from '@/components/security-devices/bulk-action-popups';
import {
    deviceMenu,
    DeviceFormPopup,
    MoveDevices,
    PingViewer,
    RetireDevice,
    WatchDevice,
    type DeviceAction,
    type PopupDevice,
} from '@/components/security-devices/device-popups';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { KnowList, QuietLine, SideBySide, SideColumn, Tally } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuLabel, DropdownMenuRadioGroup, DropdownMenuRadioItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime, timedText } from '@/lib/security-devices/device-time';
import { devicesTabLabel, type DevicesTab } from '@/lib/security-devices/device-words';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { BellPlus, ChevronDown, Download, ListChecks, MoveRight } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { BlockHead, ColumnsMenu, ListPager, useColumns, type ColumnChoice } from '../devices/_parts';
import type { DeviceFormData } from '../devices/_types';
import { BoldText } from './_parts';
import type { SiteDeviceListRow, SiteDevices } from './_types';

const PAGE_SIZE = 25;

const KIND_ORDER: DevicesTab[] = ['network', 'cameras', 'alarms_doors', 'sensors', 'trackers', 'computers', 'other'];

export const SITE_DEVICE_COLUMNS: ColumnChoice[] = [
    { key: 'room', label: 'Room' },
    { key: 'status', label: 'Status' },
    { key: 'watching', label: 'Watching' },
    { key: 'seen', label: 'Last seen' },
    { key: 'kind', label: 'Kind' },
    { key: 'address', label: 'Address' },
    { key: 'battery', label: 'Battery' },
];

const DEFAULT_COLUMNS = ['room', 'status', 'watching', 'seen'];

type Popup =
    | { kind: 'edit'; device: PopupDevice }
    | { kind: 'ping'; device: PopupDevice }
    | { kind: 'watch'; device: PopupDevice }
    | { kind: 'remove'; device: PopupDevice }
    | { kind: 'move'; devices: PopupDevice[] }
    | { kind: 'watch-many'; devices: PopupDevice[] }
    | { kind: 'bulk'; action: BulkAction; stepUpCurrent: boolean; ticked: number[] }
    | null;

export function DevicesTab({
    devices,
    siteId,
    siteName,
    now,
    form,
    load,
    canAssign,
}: {
    devices: SiteDevices;
    siteId: number;
    siteName: string;
    now: Date;
    form: DeviceFormData | undefined;
    load: () => void;
    canAssign: boolean;
}) {
    const { can } = devices;
    const [kind, setKind] = useState<DevicesTab | 'all'>('all');
    const [page, setPage] = useState(1);
    const [selecting, setSelecting] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [popup, setPopup] = useState<Popup>(null);
    const [watchingMany, setWatchingMany] = useState(false);
    const menu = useEntityContextMenu<SiteDeviceListRow>();
    const columns = useColumns('sd.site.devices.columns', SITE_DEVICE_COLUMNS, DEFAULT_COLUMNS);

    const rows = useMemo(() => (kind === 'all' ? devices.rows : devices.rows.filter((row) => row.tab === kind)), [devices.rows, kind]);
    const lastPage = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    const current = Math.min(page, lastPage);
    const shown = rows.slice((current - 1) * PAGE_SIZE, current * PAGE_SIZE);
    const kinds = KIND_ORDER.filter((key) => (devices.by_kind[key] ?? 0) > 0);
    const canSelect = can.watch || can.move || !!can.commands || !!can.export;
    const selectedRows = devices.rows.filter((row) => selected.has(row.id));
    const tickedIds = [...selected];

    const open = (next: Exclude<Popup, null>) => {
        menu.close();
        if (['edit', 'watch', 'move', 'watch-many'].includes(next.kind)) load();
        setPopup(next);
    };
    const close = () => setPopup(null);
    const act = (action: DeviceAction, device: PopupDevice) => {
        if (action === 'ping') open({ kind: 'ping', device });
        if (action === 'edit') open({ kind: 'edit', device });
        if (action === 'watch') open({ kind: 'watch', device });
        if (action === 'move') open({ kind: 'move', devices: [device] });
        if (action === 'remove') open({ kind: 'remove', device });
    };
    const actionsFor = (row: SiteDeviceListRow): MenuItem[] => deviceMenu(row, can, act);
    const toggle = (id: number, on: boolean) =>
        setSelected((before) => {
            const next = new Set(before);
            if (on) next.add(id);
            else next.delete(id);
            return next;
        });

    const watchMany = async (list: PopupDevice[]) => {
        setWatchingMany(true);
        let started = 0;
        const skipped: string[] = [];
        for (const device of list) {
            if (!device.address) {
                skipped.push(device.name);
                continue;
            }
            try {
                await axios.post(`/security-devices/devices/${device.id}/offline-alert`, { enabled: true }, { headers: { Accept: 'application/json' } });
                started += 1;
            } catch {
                skipped.push(device.name);
            }
        }
        setWatchingMany(false);
        close();
        setSelected(new Set());
        if (started > 0) toast.success(`Watching ${started} ${started === 1 ? 'device' : 'devices'}.`);
        if (skipped.length > 0) toast.warning(`Not watched: ${skipped.join(', ')}. Each needs an address.`);
        router.reload();
    };
    const finishBulk = (result: BulkResult) => {
        close();
        setSelected(new Set());
        const lines = bulkResultLines(result);
        if (lines.done) toast.success(lines.done);
        if (lines.warning) toast.warning(lines.warning);
        router.reload();
    };

    const all: Record<string, EntityTableColumn<SiteDeviceListRow>> = {
        room: { key: 'room', label: 'Room', width: '1fr', cell: (row) => row.room ?? '—' },
        status: {
            key: 'status',
            label: 'Status',
            width: '1.5fr',
            cell: (row) => (
                <span className="flex min-w-0 flex-col items-start gap-1 py-1">
                    <StatusBadge variant={row.badge.tone} size="sm">
                        {row.badge.label}
                    </StatusBadge>
                    <span className="max-w-full truncate text-xs text-muted-foreground">{timedText(row.note, now)}</span>
                </span>
            ),
        },
        watching: { key: 'watching', label: 'Watching', width: '1.1fr', cell: (row) => row.watching },
        seen: { key: 'seen', label: 'Last seen', width: '120px', cell: (row) => <span className="whitespace-nowrap">{row.seen_at ? seenTime(row.seen_at, now) : '—'}</span> },
        kind: { key: 'kind', label: 'Kind', width: '1fr', cell: (row) => row.kind_label },
        address: { key: 'address', label: 'Address', width: '1fr', cell: (row) => row.address ?? '—' },
        battery: { key: 'battery', label: 'Battery', width: '90px', cell: (row) => (row.battery === null ? '—' : `${row.battery}%`) },
    };

    const kindPicker =
        kinds.length > 1 ? (
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button type="button" variant="outline" size="sm">
                        {kind === 'all' ? 'Every kind' : devicesTabLabel[kind]}
                        <ChevronDown className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuLabel>Show</DropdownMenuLabel>
                    <DropdownMenuRadioGroup
                        value={kind}
                        onValueChange={(value) => {
                            setKind(value as DevicesTab | 'all');
                            setPage(1);
                        }}
                    >
                        <DropdownMenuRadioItem value="all">Every kind</DropdownMenuRadioItem>
                        {kinds.map((key) => (
                            <DropdownMenuRadioItem key={key} value={key}>
                                {devicesTabLabel[key]}
                            </DropdownMenuRadioItem>
                        ))}
                    </DropdownMenuRadioGroup>
                </DropdownMenuContent>
            </DropdownMenu>
        ) : null;

    const quiet = [
        devices.not_watched_count > 0
            ? `${listWords(devices.not_watched, devices.not_watched_count)} ${devices.not_watched_count === 1 ? 'isn’t' : 'aren’t'} watched, so ${devices.not_watched_count === 1 ? 'it’s' : 'they’re'} last in this list, in grey.`
            : null,
        devices.retired_count > 0
            ? `${devices.retired_count === 1 ? 'One device here was' : `${devices.retired_count} devices here were`} removed (${listWords(devices.retired, devices.retired_count)}); ${devices.retired_count === 1 ? 'its' : 'their'} history is kept.`
            : null,
    ].filter(Boolean);

    return (
        <SideBySide cols="minmax(0, 1fr) 360px">
            <div className="flex flex-col gap-5">
                {selecting ? (
                    <PopupSelectionBar count={selected.size} noun="device" onClear={() => setSelected(new Set())}>
                        {can.watch ? (
                            <Button type="button" variant="outline" onClick={() => open({ kind: 'watch-many', devices: selectedRows })}>
                                <BellPlus className="size-4" />
                                Watch them
                            </Button>
                        ) : null}
                        {can.move ? (
                            <Button type="button" variant="outline" onClick={() => open({ kind: 'move', devices: selectedRows })}>
                                <MoveRight className="size-4" />
                                Move to Site
                            </Button>
                        ) : null}
                        {can.commands && selected.size >= 2 ? (
                            <BulkActionsMenu
                                ids={tickedIds}
                                onChoose={(action: BulkAction, options: BulkOptions) => open({ kind: 'bulk', action, stepUpCurrent: options.step_up_current, ticked: tickedIds })}
                            />
                        ) : null}
                        {can.export && selectedRows.length > 0 ? (
                            <Button variant="outline" asChild>
                                <a href={`/security-devices/exports/devices.csv?ids=${selectedRows.map((row) => row.id).join(',')}`} download>
                                    <Download className="size-4" />
                                    Export
                                </a>
                            </Button>
                        ) : null}
                    </PopupSelectionBar>
                ) : null}
                {devices.rows.length === 0 ? (
                    <QuietLine title={`Devices at ${siteName}`} text="No devices here yet." />
                ) : (
                    <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                        <div className="border-b border-border px-4 py-3.5">
                            <BlockHead
                                title={`Devices at ${siteName}`}
                                sub="Problems first (worst at the top), then by name, as in Devices · click a row to open it · ⋮ or right-click for its menu"
                                right={
                                    <>
                                        {kindPicker}
                                        <ColumnsMenu all={SITE_DEVICE_COLUMNS} shown={columns.shown} onToggle={columns.toggle} />
                                        {canSelect ? (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                aria-pressed={selecting}
                                                onClick={() => {
                                                    setSelecting((before) => !before);
                                                    setSelected(new Set());
                                                }}
                                            >
                                                {selecting ? 'Done' : 'Select'}
                                            </Button>
                                        ) : null}
                                    </>
                                }
                            />
                        </div>
                        <EntityTable<SiteDeviceListRow>
                            className="rounded-none border-0 shadow-none"
                            rows={shown}
                            rowKey={(row) => row.id}
                            identityLabel="Device"
                            identity={(row) => ({ icon: iconForKind(row.kind), name: row.name, subline: row.sub, linkLabel: `Open ${row.name}` })}
                            hrefFor={(row) => row.href}
                            columns={columns.shown.map((key) => all[key]).filter(Boolean)}
                            actionsFor={actionsFor}
                            onOpen={(row) => router.visit(row.href)}
                            onRowContextMenu={(event, row) => menu.open(event, row)}
                            mutedFor={(row) => row.state === 'not_watched'}
                            rowHeight="content"
                            minWidth={820}
                            selection={selecting ? { keys: selected, onToggle: (row, on) => toggle(row.id, on), labelFor: (row) => `Select ${row.name}` } : undefined}
                        />
                        <ListPager
                            meta={{
                                total: rows.length,
                                page: current,
                                last_page: lastPage,
                                from: rows.length === 0 ? 0 : (current - 1) * PAGE_SIZE + 1,
                                to: Math.min(rows.length, current * PAGE_SIZE),
                            }}
                            noun="devices"
                            onPage={setPage}
                        />
                    </section>
                )}
                {quiet.length > 0 ? (
                    <QuietLine
                        title="Not watched and removed here"
                        text={quiet.join(' ')}
                        action={{
                            label: 'Open Devices › Upkeep',
                            href: `/security-devices/devices?tab=upkeep&upkeep=${devices.retired_count > 0 ? 'retired' : 'not_watched'}&site=${siteId}`,
                        }}
                    />
                ) : null}
            </div>
            <SideColumn>
                {kinds.length > 0 ? (
                    <Tally
                        title="By kind"
                        items={kinds.map((key) => ({ label: devicesTabLabel[key], value: devices.by_kind[key] ?? 0 }))}
                    />
                ) : null}
                <KnowList title="How they’re checked" items={devices.checked.map((line) => ({ tone: line.tone, text: <BoldText text={line.text} /> }))} />
            </SideColumn>

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
            <DeviceFormPopup
                open={popup?.kind === 'edit'}
                mode="edit"
                deviceId={popup?.kind === 'edit' ? popup.device.id : null}
                context={popup?.kind === 'edit' ? popup.device : null}
                form={form}
                canAssign={canAssign}
                onClose={close}
            />
            <PingViewer open={popup?.kind === 'ping'} device={popup?.kind === 'ping' ? popup.device : null} onClose={close} />
            <WatchDevice open={popup?.kind === 'watch'} device={popup?.kind === 'watch' ? popup.device : null} form={form} onClose={close} />
            <RetireDevice open={popup?.kind === 'remove'} device={popup?.kind === 'remove' ? popup.device : null} onClose={close} />
            <MoveDevices open={popup?.kind === 'move'} devices={popup?.kind === 'move' ? popup.devices : []} form={form} onClose={close} />
            {can.commands ? (
                <BulkActionConfirm
                    open={popup?.kind === 'bulk'}
                    action={popup?.kind === 'bulk' ? popup.action : null}
                    ticked={popup?.kind === 'bulk' ? popup.ticked : []}
                    stepUpCurrent={popup?.kind === 'bulk' ? popup.stepUpCurrent : false}
                    onClose={close}
                    onDone={finishBulk}
                />
            ) : null}
            <PopupConfirm
                open={popup?.kind === 'watch-many'}
                onClose={close}
                onConfirm={() => popup?.kind === 'watch-many' && watchMany(popup.devices)}
                icon={ListChecks}
                title={`Watch ${popup?.kind === 'watch-many' ? popup.devices.length : 0} devices?`}
                description={
                    form
                        ? `We’ll check each one is online ${form.watch.every.toLowerCase()} and alert after ${form.watch.after}. Devices without an address are skipped.`
                        : 'We’ll check each one is online with the standard offline check. Devices without an address are skipped.'
                }
                confirmLabel="Start watching"
                processing={watchingMany}
                processingLabel="Starting…"
            />
        </SideBySide>
    );
}

/** "Staff phone base and Lounge TV", or "A, B, C and 2 more". */
function listWords(names: string[], total: number): string {
    const rest = total - names.length;
    const parts = rest > 0 ? [...names, `${rest} more`] : names;
    if (parts.length <= 1) return parts[0] ?? '';
    return `${parts.slice(0, -1).join(', ')} and ${parts[parts.length - 1]}`;
}
