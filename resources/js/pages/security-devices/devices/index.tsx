/**
 * Devices (S&D rebuild drawings pg-sdr-2, 2a, 2b, 2c, 2d): every device the viewer may see, by
 * kind. Cards put problems first with each device's reading and last 24 hours; List is rows for
 * going through many at once. The Cards | List choice is remembered for each person.
 */
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { PopupConfirm, PopupSelectionBar } from '@/components/popup';
import {
    AddDeviceChooser,
    deviceMenu,
    DeviceFormPopup,
    MoveDevices,
    PingViewer,
    RetireDevice,
    useDeviceFormOptions,
    WatchDevice,
    type DeviceAction,
    type PopupDevice,
} from '@/components/security-devices/device-popups';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { ScanNetwork, ScanningViewer } from '@/components/security-devices/scan-popups';
import { QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { timedText } from '@/lib/security-devices/device-time';
import {
    devicesTabLabel,
    type DevicesTab,
} from '@/lib/security-devices/device-words';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    BatteryMedium,
    BellPlus,
    Cpu,
    HardDriveDownload,
    LayoutGrid,
    List,
    ListChecks,
    MoveRight,
    Plus,
    Radar,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { toast } from 'sonner';
import { CameraWall } from './_camera-wall';
import { DeviceCard } from './_device-card';
import { DEFAULT_LIST_COLUMNS, DevicesList, LIST_COLUMNS } from './_devices-list';
import { BlockHead, ColumnsMenu, ListPager, useColumns, ViewSwitch } from './_parts';
import type { DevicesIndexProps } from './_types';
import { BatteriesView, UpdatesView } from './_upkeep';

export type { DevicesIndexProps } from './_types';

const BASE = '/security-devices/devices';
const PREFERENCE = 'security-devices.devices.view';
const CARDS_PER_GROUP = 8;

type Popup =
    | { kind: 'chooser' }
    | { kind: 'add' }
    | { kind: 'scan' }
    | { kind: 'scanning'; runId: number }
    | { kind: 'edit'; device: PopupDevice }
    | { kind: 'ping'; device: PopupDevice }
    | { kind: 'watch'; device: PopupDevice }
    | { kind: 'remove'; device: PopupDevice }
    | { kind: 'move'; devices: PopupDevice[] }
    | { kind: 'watch-many'; devices: PopupDevice[] }
    | null;

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

export default function DevicesIndex(props: DevicesIndexProps) {
    const { tab, layout, upkeep, filters, tabs, meters, can } = props;
    const now = useNow();
    const [search, setSearch] = useState(filters.q);
    const [popup, setPopup] = useState<Popup>(props.openDialog === 'add' ? { kind: 'chooser' } : null);
    const [selecting, setSelecting] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const [watchingMany, setWatchingMany] = useState(false);
    const searchTimer = useRef<number | null>(null);
    const menu = useEntityContextMenu<PopupDevice>();
    const { form, load } = useDeviceFormOptions();
    const columns = useColumns('sd.devices.list.columns', LIST_COLUMNS, DEFAULT_LIST_COLUMNS);

    // A new view clears what was ticked.
    const viewKey = `${tab}|${layout}|${filters.site}|${filters.status}|${filters.q}`;
    useEffect(() => {
        setSelected(new Set());
        setSelecting(false);
    }, [viewKey]);

    const params = (changes: Record<string, string | number | undefined>) => {
        const next: Record<string, string | number | undefined> = {
            tab: tab === 'all' ? undefined : tab,
            site: filters.site === 'all' ? undefined : filters.site,
            status: filters.status === 'all' ? undefined : filters.status,
            q: filters.q || undefined,
            upkeep: tab === 'upkeep' && upkeep === 'updates' ? 'updates' : undefined,
            ...changes,
        };
        return Object.fromEntries(Object.entries(next).filter(([, value]) => value !== undefined && value !== '' && value !== 'all'));
    };
    const go = (changes: Record<string, string | number | undefined>) => {
        menu.close();
        router.get(BASE, params({ page: undefined, ...changes }), { preserveState: true, preserveScroll: true });
    };

    const updateSearch = (value: string) => {
        setSearch(value);
        if (searchTimer.current) window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(() => go({ q: value.trim() || undefined }), 350);
    };

    const switchLayout = (next: 'cards' | 'list') => {
        if (next === layout) return;
        router.put(
            `/settings/ui-preferences/${PREFERENCE}`,
            { value: [next] },
            {
                preserveState: true,
                preserveScroll: true,
                only: [],
                onFinish: () => router.get(BASE, params({}), { preserveState: true, preserveScroll: true }),
            },
        );
    };

    const open = (next: Popup) => {
        menu.close();
        if (next && ['add', 'scan', 'edit', 'watch', 'move', 'watch-many'].includes(next.kind)) load();
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
    const actionsFor = (device: PopupDevice): MenuItem[] => deviceMenu(device, can, act);
    const contextMenu = (event: MouseEvent, device: PopupDevice) => menu.open(event, device);

    const toggle = (id: number, on: boolean) =>
        setSelected((current) => {
            const next = new Set(current);
            if (on) next.add(id);
            else next.delete(id);
            return next;
        });

    const visibleDevices: PopupDevice[] = useMemo(() => {
        if (props.list) return props.list.rows;
        if (props.cards) return props.cards.groups.flatMap((group) => group.items);
        return [];
    }, [props.list, props.cards]);
    const selectedDevices = visibleDevices.filter((device) => selected.has(device.id));
    const canSelect = (can.watch || can.move) && (props.cards !== null || props.list !== null);

    const watchMany = async (devices: PopupDevice[]) => {
        setWatchingMany(true);
        let started = 0;
        const skipped: string[] = [];
        for (const device of devices) {
            if (!device.address || !device.site_id) {
                skipped.push(device.name);
                continue;
            }
            try {
                await axios.post(`${BASE}/${device.id}/offline-alert`, { enabled: true }, { headers: { Accept: 'application/json' } });
                started += 1;
            } catch {
                skipped.push(device.name);
            }
        }
        setWatchingMany(false);
        close();
        setSelected(new Set());
        if (started > 0) toast.success(`Watching ${started} ${started === 1 ? 'device' : 'devices'}.`);
        if (skipped.length > 0) toast.warning(`Not watched: ${skipped.join(', ')}. Each needs a Site and an address.`);
        router.reload();
    };

    const tabTitle = tab === 'all' ? 'All devices' : devicesTabLabel[tab];
    const layoutSwitch = (
        <ViewSwitch<'cards' | 'list'>
            label="Show devices as"
            value={layout}
            onChange={switchLayout}
            options={[
                { value: 'cards', label: 'Cards', icon: LayoutGrid },
                { value: 'list', label: 'List', icon: List },
            ]}
        />
    );
    const selectButton = canSelect ? (
        <Button type="button" variant="outline" size="sm" aria-pressed={selecting} onClick={() => {
            setSelecting((current) => !current);
            setSelected(new Set());
        }}>
            {selecting ? 'Done' : 'Select'}
        </Button>
    ) : null;

    const header = (
        <PageHeader
            icon={Cpu}
            title="Devices"
            subline="Everything Oblivion watches at your Sites, by kind"
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={updateSearch} placeholder="Search devices" />
                    {can.scan ? (
                        <PageHeaderGlassButton icon={Radar} onClick={() => open({ kind: 'scan' })}>
                            Scan network
                        </PageHeaderGlassButton>
                    ) : null}
                    {can.add || can.scan || can.connect ? (
                        <PageHeaderPrimaryButton icon={Plus} onClick={() => open({ kind: 'chooser' })}>
                            Add device
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="Online" tone="success" onClick={() => go({ status: 'online', tab: undefined })} ariaLabel="Show online devices">
                        <PageHeaderMeterBig>{meters.online.count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{timedText(meters.online.caption, now)}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Offline" tone={meters.offline.count > 0 ? 'critical' : 'brand'} onClick={() => go({ status: 'offline', tab: undefined })} ariaLabel="Show offline devices">
                        <PageHeaderMeterBig>{meters.offline.count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{timedText(meters.offline.caption, now)}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Can’t check" onClick={() => go({ status: 'cant_check', tab: undefined })} ariaLabel="Show devices that can’t be checked">
                        <PageHeaderMeterBig>{meters.cant_check.count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{timedText(meters.cant_check.caption, now)}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Batteries low" tone={meters.batteries_low.count > 0 ? 'warning' : 'brand'} onClick={() => go({ tab: 'upkeep', upkeep: undefined, status: undefined })} ariaLabel="Show batteries">
                        <PageHeaderMeterBig>{meters.batteries_low.count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{timedText(meters.batteries_low.caption, now)}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect label="All Sites" value={filters.site} options={props.sites} onChange={(value) => go({ site: value })} />
                    <PageHeaderFilterSelect label="Any status" value={filters.status} options={props.statuses} onChange={(value) => go({ status: value })} />
                </>
            }
            rail={
                <PageHeaderRail<DevicesTab>
                    ariaLabel="Kinds of device"
                    items={tabs.map((item) => ({
                        key: item.key,
                        label: devicesTabLabel[item.key],
                        count: item.count ?? undefined,
                    }))}
                    value={tab}
                    onSelect={(key) => go({ tab: key === 'all' ? undefined : key, upkeep: undefined })}
                />
            }
        />
    );

    let body: ReactNode = null;
    if (props.batteries || props.updates) {
        const upkeepSwitch = (
            <ViewSwitch<'batteries' | 'updates'>
                label="Upkeep view"
                value={upkeep}
                onChange={(value) => go({ tab: 'upkeep', upkeep: value === 'updates' ? 'updates' : undefined })}
                options={[
                    { value: 'batteries', label: 'Batteries', icon: BatteryMedium },
                    { value: 'updates', label: 'Updates', icon: HardDriveDownload },
                ]}
            />
        );
        body = props.batteries ? (
            <BatteriesView
                data={props.batteries}
                now={now}
                head={<BlockHead title="Batteries" sub="Lowest first · from each device’s latest report" right={upkeepSwitch} />}
                actionsFor={(row) => actionsFor({ id: row.id, name: row.name, sub: row.model, kind: row.kind })}
                onContextMenu={(event, row) => contextMenu(event, { id: row.id, name: row.name, sub: row.model, kind: row.kind })}
                onPage={(page) => go({ tab: 'upkeep', page })}
            />
        ) : props.updates ? (
            <UpdatesView
                data={props.updates}
                head={<BlockHead title="Updates ready" sub="Newer software from the maker · Oblivion shows them, you update in the maker’s app" right={upkeepSwitch} />}
            />
        ) : null;
    } else if (props.cameras) {
        body = (
            <CameraWall
                items={props.cameras.items}
                cantCheck={props.cameras.cant_check}
                now={now}
                head={<BlockHead title="Cameras" sub="Each camera and how it’s doing · open one to watch live" right={layoutSwitch} />}
            />
        );
    } else if (props.list) {
        body = (
            <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                <div className="border-b border-border px-4 py-3.5">
                    <BlockHead
                        title={tabTitle}
                        sub="Problems first, then by name"
                        right={
                            <>
                                {layoutSwitch}
                                <ColumnsMenu all={LIST_COLUMNS} shown={columns.shown} onToggle={columns.toggle} />
                                {selectButton}
                            </>
                        }
                    />
                </div>
                {props.list.rows.length === 0 ? (
                    <p className="text-subtle px-4 py-6">
                        {filters.q || filters.status !== 'all' ? 'No devices match. Try another search or status.' : 'No devices here yet.'}
                    </p>
                ) : (
                    <DevicesList
                        rows={props.list.rows}
                        shown={columns.shown}
                        actionsFor={actionsFor}
                        onOpen={(row) => router.visit(row.href)}
                        onContextMenu={contextMenu}
                        selectable={selecting}
                        selected={selected}
                        onToggle={(row, on) => toggle(row.id, on)}
                        now={now}
                    />
                )}
                <ListPager meta={props.list.meta} noun="devices" onPage={(page) => go({ page })} />
            </section>
        );
    } else if (props.cards) {
        const { groups, not_watched: notWatched } = props.cards;
        const nothing = groups.every((group) => group.count === 0);
        body = (
            <div className="flex flex-col gap-5">
                <BlockHead title={tabTitle} sub="Problems first · each card shows its last 24 hours" right={<>{layoutSwitch}{selectButton}</>} />
                {nothing && notWatched.count === 0 ? (
                    <QuietLine
                        text={filters.q || filters.status !== 'all' ? 'No devices match. Try another search or status.' : 'No devices here yet.'}
                        action={can.add || can.scan ? { label: 'Add device', onClick: () => open({ kind: 'chooser' }) } : null}
                    />
                ) : null}
                {groups
                    .filter((group) => group.count > 0)
                    .map((group) => {
                        const showAll = expanded[group.key] ?? false;
                        const items = showAll ? group.items : group.items.slice(0, CARDS_PER_GROUP);
                        return (
                            <section key={group.key} aria-label={group.title} className="flex flex-col gap-3">
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <span
                                        aria-hidden="true"
                                        className={group.key === 'attention' ? 'size-2.5 rounded-full bg-status-critical' : 'size-2.5 rounded-full bg-status-success'}
                                    />
                                    <h3 className="text-sm font-[650]">{group.title}</h3>
                                    <span className="rounded-md bg-muted px-1.5 py-0.5 text-xs font-semibold tabular-nums">{group.count}</span>
                                    {group.sub ? <span className="text-subtle">{group.sub}</span> : null}
                                    {group.items.length > CARDS_PER_GROUP ? (
                                        <Button
                                            type="button"
                                            variant="link"
                                            className="ml-auto h-auto p-0"
                                            onClick={() => setExpanded((current) => ({ ...current, [group.key]: !showAll }))}
                                        >
                                            {showAll ? 'Show fewer' : `Show all ${group.count}`}
                                        </Button>
                                    ) : null}
                                </div>
                                <div className="grid gap-5 [grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr))]">
                                    {items.map((device) => (
                                        <DeviceCard
                                            key={device.id}
                                            device={device}
                                            actions={actionsFor(device)}
                                            onOpen={() => router.visit(device.href)}
                                            onContextMenu={(event) => contextMenu(event, device)}
                                            selectable={selecting}
                                            selected={selected.has(device.id)}
                                            onToggle={(on) => toggle(device.id, on)}
                                            now={now}
                                        />
                                    ))}
                                </div>
                                {showAll && group.truncated ? (
                                    <p className="text-subtle">
                                        Showing the first {group.items.length}.{' '}
                                        <Button type="button" variant="link" className="h-auto p-0" onClick={() => switchLayout('list')}>
                                            See them all in the list
                                        </Button>
                                    </p>
                                ) : null}
                            </section>
                        );
                    })}
                {notWatched.count > 0 ? (
                    <QuietLine
                        text={
                            <>
                                <b className="font-semibold">{notWatched.count} not watched:</b> {notWatched.names.join(', ')}
                                {notWatched.count > notWatched.names.length ? ` and ${notWatched.count - notWatched.names.length} more` : ''} · no
                                alerts for {notWatched.count === 1 ? 'it' : 'them'}
                            </>
                        }
                        action={{
                            label: 'Show',
                            onClick: () =>
                                router.put(
                                    `/settings/ui-preferences/${PREFERENCE}`,
                                    { value: ['list'] },
                                    { only: [], preserveState: true, onFinish: () => go({ status: 'not_watched' }) },
                                ),
                        }}
                    />
                ) : null}
            </div>
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: '/security-devices' },
                { title: 'Devices', href: BASE },
            ]}
        >
            <Head title="Devices" />
            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    {selecting ? (
                        <PopupSelectionBar count={selected.size} noun="device" onClear={() => setSelected(new Set())}>
                            {can.watch ? (
                                <Button type="button" variant="outline" onClick={() => open({ kind: 'watch-many', devices: selectedDevices })}>
                                    <BellPlus className="size-4" />
                                    Watch them
                                </Button>
                            ) : null}
                            {can.move ? (
                                <Button type="button" variant="outline" onClick={() => open({ kind: 'move', devices: selectedDevices })}>
                                    <MoveRight className="size-4" />
                                    Move to Site
                                </Button>
                            ) : null}
                        </PopupSelectionBar>
                    ) : null}
                    {body}
                </div>
            </PageLayout>

            {menu.ctx ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={iconForKind(menu.ctx.record.kind ?? 'other')}
                    title={menu.ctx.record.name}
                    items={actionsFor(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}

            <AddDeviceChooser
                open={popup?.kind === 'chooser'}
                can={can}
                onClose={close}
                onChoose={(choice) => open(choice === 'scan' ? { kind: 'scan' } : { kind: 'add' })}
            />
            <DeviceFormPopup open={popup?.kind === 'add'} mode="add" form={form} canAssign={can.assign} onClose={close} />
            <DeviceFormPopup
                open={popup?.kind === 'edit'}
                mode="edit"
                deviceId={popup?.kind === 'edit' ? popup.device.id : null}
                context={popup?.kind === 'edit' ? popup.device : null}
                form={form}
                canAssign={can.assign}
                onClose={close}
            />
            <ScanNetwork
                open={popup?.kind === 'scan'}
                form={form}
                initialSiteId={filters.site === 'all' ? null : Number(filters.site)}
                onClose={close}
                onStarted={(runId) => setPopup({ kind: 'scanning', runId })}
            />
            <ScanningViewer open={popup?.kind === 'scanning'} runId={popup?.kind === 'scanning' ? popup.runId : null} canStop={can.scan} onClose={close} />
            <PingViewer open={popup?.kind === 'ping'} device={popup?.kind === 'ping' ? popup.device : null} onClose={close} />
            <WatchDevice open={popup?.kind === 'watch'} device={popup?.kind === 'watch' ? popup.device : null} form={form} onClose={close} />
            <RetireDevice open={popup?.kind === 'remove'} device={popup?.kind === 'remove' ? popup.device : null} onClose={close} />
            <MoveDevices open={popup?.kind === 'move'} devices={popup?.kind === 'move' ? popup.devices : []} form={form} onClose={close} />
            <PopupConfirm
                open={popup?.kind === 'watch-many'}
                onClose={close}
                onConfirm={() => popup?.kind === 'watch-many' && watchMany(popup.devices)}
                icon={ListChecks}
                title={`Watch ${popup?.kind === 'watch-many' ? popup.devices.length : 0} devices?`}
                description={
                    form
                        ? `We’ll check each one is online ${form.watch.every.toLowerCase()} and alert after ${form.watch.after}. Devices without a Site or an address are skipped.`
                        : 'We’ll check each one is online with the standard offline check. Devices without a Site or an address are skipped.'
                }
                confirmLabel="Start watching"
                processing={watchingMany}
                processingLabel="Starting…"
            />
        </AppLayout>
    );
}
