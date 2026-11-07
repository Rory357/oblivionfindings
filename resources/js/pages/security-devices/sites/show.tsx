/**
 * One Site (S&D rebuild drawing pg-sdr-12, with Network map 12b and its links found 12j, Internet
 * 12e, Rooms 12d, Modes 12c, Devices 12g, Alerts 12h, Logbook 12i and "hub stopped answering" 12f).
 * House modes switch by hand with pop-up 42 and are edited with 43.
 */
import { DonutChart } from '@/components/dashboard/donut-chart';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { AddDeviceChooser, DeviceFormPopup, useDeviceFormOptions } from '@/components/security-devices/device-popups';
import { EditHouseMode, modeIcon, SwitchHouseMode } from '@/components/security-devices/house-mode-popups';
import { HubPopups, useHubPopups } from '@/components/security-devices/hub-popups';
import { NetworkCheckPopup } from '@/components/security-devices/network-check-popup';
import { ScanNetwork, ScanningViewer } from '@/components/security-devices/scan-popups';
import { SiteTopologyTreeCard } from '@/components/security-devices/site-topology-tree';
import type { SiteTopologyTree } from '@/components/security-devices/topology-types';
import { KnowList, Log, PointBars, QuietLine, SideBySide, SideColumn, Story, Surface } from '@/components/signature/blocks';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { agoWords, seenTime, timedText } from '@/lib/security-devices/device-time';
import { deviceStateLabel, deviceStateTone, type DeviceState } from '@/lib/security-devices/device-words';
import { Head, Link, router } from '@inertiajs/react';
import { House, Plus, Radar, SunMoon } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { AlertsTab } from './_alerts';
import { DevicesTab } from './_devices';
import { LinkChanges, LinksFoundTable, NotPlaced, WhereLinksComeFrom } from './_links';
import { LogbookTab } from './_logbook';
import { ModesTab } from './_modes';
import { RoomsTab } from './_rooms';
import type { HouseSummary, LinksFound, SitePageProps, SitePageTab } from './_types';

export type { SitePageProps } from './_types';

// Alerts (12h) is for people who may see alerts, Logbook (12i) for people who may see device events.
const TAB_LABEL: Record<SitePageTab, string> = {
    overview: 'Overview',
    network: 'Network map',
    internet: 'Internet',
    rooms: 'Rooms',
    modes: 'Modes',
    devices: 'Devices',
    alerts: 'Alerts',
    logbook: 'Logbook',
};

const NO_HOUSE: HouseSummary = { current: null, next: null, modes: [] };

/** Each state's ring colour, from the status tokens (never the brand). */
const STATE_COLOUR: Record<DeviceState, string> = {
    online: 'var(--status-success)',
    problem: 'var(--status-warning)',
    offline: 'var(--status-critical)',
    cant_check: 'var(--status-neutral)',
    not_watched: 'var(--border)',
    retired: 'var(--border)',
};

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

type Popup =
    | { kind: 'chooser' }
    | { kind: 'add' }
    | { kind: 'scan' }
    | { kind: 'scanning'; runId: number }
    | { kind: 'switch_mode' }
    | { kind: 'edit_mode' }
    | { kind: 'ask_obli'; session: string | null }
    | null;

/** An IT job's link back to Obli's record: ?tab=network&obli=<session>. */
function obliSessionFromUrl(): string | null {
    if (typeof window === 'undefined') return null;
    const value = new URLSearchParams(window.location.search).get('obli');
    return value && /^[0-9a-f-]{36}$/i.test(value) ? value : null;
}

export default function SiteShow(props: SitePageProps) {
    const { site, tab, flag, hub, meters, can } = props;
    const tabs = props.tabs ?? { devices: 0, alerts: false, logbook: false };
    const house = props.house ?? NO_HOUSE;
    // Change mode offers every mode but the one the house is in.
    const switchable = can.switch_mode ? house.modes.filter((mode) => mode.id !== house.current?.id) : [];
    const now = useNow();
    const base = `/security-devices/sites/${site.id}`;
    const [popup, setPopup] = useState<Popup>(() => {
        const session = can.ask_obli ? obliSessionFromUrl() : null;
        return session ? { kind: 'ask_obli', session } : null;
    });
    // The mode a house mode pop-up is about; kept while it closes so its words don't change.
    const [modeTarget, setModeTarget] = useState<number | null>(null);
    const switchMode = (modeId: number) => {
        setModeTarget(modeId);
        setPopup({ kind: 'switch_mode' });
    };
    const editMode = (modeId: number | null) => {
        setModeTarget(modeId);
        setPopup({ kind: 'edit_mode' });
    };
    const { form, load } = useDeviceFormOptions();
    const open = (next: Exclude<Popup, null>) => {
        load();
        setPopup(next);
    };
    const go = (next: SitePageTab) => router.get(base, next === 'overview' ? {} : { tab: next }, { preserveScroll: true });
    const hubs = useHubPopups(load);
    const hubId = hub?.id ?? null;

    // The "watched by" line opens the hub panel (sdr-13) when the viewer may see it.
    const hubName =
        hub === null ? null : hubId !== null ? (
            // eslint-disable-next-line no-restricted-syntax -- the hub's name in the header line, opening its panel; a text link, not a form button.
            <button type="button" onClick={() => hubs.openPanel(hubId)} className="rounded-sm underline underline-offset-2 outline-none hover:no-underline focus-visible:ring-2 focus-visible:ring-band-foreground">
                {hub.label}
            </button>
        ) : (
            hub.label
        );
    const hubWords =
        hub === null ? (
            'no hub watches it yet'
        ) : hub.own ? (
            <>its own hub is {hubName}</>
        ) : (
            <>watched by {hubName}</>
        );
    const openHub = () => {
        if (hubId !== null) hubs.openPanel(hubId);
        else if (hub === null && can.add_hub) hubs.openAdd(site.id);
        else go('overview');
    };

    const header = (
        <PageHeader
            variant="profile"
            backHref="/security-devices/sites"
            icon={House}
            title={site.name}
            titleChip={<PageHeaderStatusChip variant={flag.tone}>{flag.text}</PageHeaderStatusChip>}
            subline={
                <>
                    {site.address ? `${site.address} · ` : null}
                    {hubWords}
                </>
            }
            actions={
                <>
                    {can.scan ? (
                        <PageHeaderGlassButton icon={Radar} onClick={() => open({ kind: 'scan' })}>
                            Scan now
                        </PageHeaderGlassButton>
                    ) : null}
                    {switchable.length > 0 ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <PageHeaderGlassButton icon={SunMoon}>Change mode</PageHeaderGlassButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                {switchable.map((mode) => {
                                    const Icon = modeIcon(mode.name);
                                    return (
                                        <DropdownMenuItem key={mode.id} onSelect={() => switchMode(mode.id)}>
                                            <Icon aria-hidden="true" />
                                            Switch to {mode.name}
                                        </DropdownMenuItem>
                                    );
                                })}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null}
                    {can.add_device || can.scan ? (
                        <PageHeaderPrimaryButton icon={Plus} onClick={() => open({ kind: 'chooser' })}>
                            Add device
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Devices online"
                        tone={meters.online.of > 0 && meters.online.count === meters.online.of ? 'success' : meters.online.of > 0 ? 'warning' : 'brand'}
                        onClick={() => go('devices')}
                        ariaLabel="Show this Site’s devices"
                    >
                        <PageHeaderMeterBig>{meters.online.of > 0 ? `${meters.online.count} of ${meters.online.of}` : 'Not available'}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{timedText(meters.online.caption, now)}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="House mode" onClick={() => go('modes')} ariaLabel="Show this Site’s house modes">
                        <PageHeaderMeterBig>{house.current ? house.current.name : house.modes.length > 0 ? 'Not set yet' : 'None yet'}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {house.next
                                ? timedText({ text: `${house.next.name} starts {time}`, at: house.next.at }, now)
                                : house.current
                                  ? timedText({ text: 'Since {time}', at: house.current.since }, now)
                                  : house.modes.length > 0
                                    ? 'Switched by hand only'
                                    : can.edit_modes
                                      ? 'Add one on the Modes tab'
                                      : 'No house modes yet'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Alerts open"
                        tone={meters.alerts.count > 0 ? 'warning' : 'success'}
                        onClick={() => (tabs.alerts ? go('alerts') : router.visit(`/security-devices?site=${site.id}`))}
                        ariaLabel={tabs.alerts ? 'Show this Site’s alerts' : 'Show what needs attention at this Site'}
                    >
                        <PageHeaderMeterBig>{meters.alerts.count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{meters.alerts.caption}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {meters.scan ? (
                        <PageHeaderMeterBlock label="Last scan" onClick={() => router.visit(meters.scan!.href)} ariaLabel="Open the last scan’s results">
                            <PageHeaderMeterBig>{meters.scan.at ? seenTime(meters.scan.at, now) : 'Not available'}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>{meters.scan.caption}</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Hub"
                        tone={hub === null ? 'brand' : hub.answering ? 'success' : 'critical'}
                        onClick={openHub}
                        ariaLabel={hubId !== null ? `Open ${hub?.label ?? 'the hub'}` : hub === null && can.add_hub ? 'Add a hub for this Site' : 'Show how the hub is doing'}
                    >
                        <PageHeaderMeterBig>{hub === null ? 'None yet' : hub.answering ? 'Answering' : 'Not answering'}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {hub === null ? 'Add a hub to watch this Site' : [hub.label, hub.version ? `version ${hub.version}` : null].filter(Boolean).join(' · ')}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={
                <PageHeaderRail<SitePageTab>
                    ariaLabel="About this Site"
                    items={(Object.keys(TAB_LABEL) as SitePageTab[])
                        .filter((key) => (key !== 'alerts' || tabs.alerts) && (key !== 'logbook' || tabs.logbook))
                        .map((key) => ({ key, label: TAB_LABEL[key], count: key === 'devices' ? tabs.devices : undefined }))}
                    value={tab}
                    onSelect={go}
                />
            }
        />
    );

    let body: ReactNode = null;
    if (tab === 'overview' && props.overview) body = <OverviewTab overview={props.overview} siteName={site.name} now={now} onDevices={() => router.visit(`/security-devices/devices?site=${site.id}`)} />;
    if (tab === 'network' && props.network)
        body = (
            <NetworkTab
                tree={props.network.tree as SiteTopologyTree | undefined}
                links={props.network.links ?? null}
                siteId={site.id}
                siteName={site.name}
                hubLabel={hub?.label ?? null}
                now={now}
                onAddHub={hub === null && can.add_hub ? () => hubs.openAdd(site.id) : null}
                onAskObli={can.ask_obli ? () => setPopup({ kind: 'ask_obli', session: null }) : null}
            />
        );
    if (tab === 'devices' && props.devices)
        body = <DevicesTab devices={props.devices} siteId={site.id} siteName={site.name} now={now} form={form} load={load} canAssign={can.assign} />;
    if (tab === 'alerts' && props.alerts) body = <AlertsTab alerts={props.alerts} siteId={site.id} siteName={site.name} now={now} />;
    if (tab === 'logbook' && props.logbook) body = <LogbookTab logbook={props.logbook} base={base} siteName={site.name} />;
    if (tab === 'internet' && props.internet) body = <InternetTab internet={props.internet} now={now} />;
    if (tab === 'rooms' && props.rooms) body = <RoomsTab rooms={props.rooms} siteName={site.name} />;
    if (tab === 'modes' && props.modes)
        body = (
            <ModesTab
                modes={props.modes}
                can={{ switch_mode: !!can.switch_mode }}
                now={now}
                onSwitch={switchMode}
                onEdit={editMode}
            />
        );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: '/security-devices' },
                { title: 'Sites', href: '/security-devices/sites' },
                { title: site.name, href: base },
            ]}
        >
            <Head title={site.name} />
            <PageLayout hero={header}>{body}</PageLayout>

            <AddDeviceChooser
                open={popup?.kind === 'chooser'}
                can={{ add: can.add_device, scan: can.scan, connect: false }}
                onClose={() => setPopup(null)}
                onChoose={(choice) => setPopup(choice === 'scan' ? { kind: 'scan' } : { kind: 'add' })}
            />
            <DeviceFormPopup open={popup?.kind === 'add'} mode="add" form={form} canAssign={can.assign} initialSiteId={site.id} onClose={() => setPopup(null)} />
            <ScanNetwork
                open={popup?.kind === 'scan'}
                form={form}
                initialSiteId={site.id}
                onClose={() => setPopup(null)}
                onStarted={(runId) => setPopup({ kind: 'scanning', runId })}
            />
            <ScanningViewer open={popup?.kind === 'scanning'} runId={popup?.kind === 'scanning' ? popup.runId : null} canStop={can.scan} onClose={() => setPopup(null)} />
            <HubPopups state={hubs} form={form} canApprove={!!can.approve_hub} canStopScan={can.scan} />
            {can.ask_obli ? (
                <NetworkCheckPopup
                    open={popup?.kind === 'ask_obli'}
                    siteId={site.id}
                    siteName={site.name}
                    sessionId={popup?.kind === 'ask_obli' ? popup.session : null}
                    onClose={() => setPopup(null)}
                />
            ) : null}
            {can.switch_mode ? (
                <SwitchHouseMode
                    open={popup?.kind === 'switch_mode'}
                    onClose={() => setPopup(null)}
                    siteId={site.id}
                    siteName={site.name}
                    house={house}
                    modeId={modeTarget}
                />
            ) : null}
            {props.modes?.edit ? (
                <EditHouseMode
                    open={popup?.kind === 'edit_mode'}
                    onClose={() => setPopup(null)}
                    siteId={site.id}
                    siteName={site.name}
                    mode={modeTarget !== null ? (props.modes.items.find((mode) => mode.id === modeTarget) ?? null) : null}
                    options={props.modes.edit}
                />
            ) : null}
        </AppLayout>
    );
}

function OverviewTab({ overview, siteName, now, onDevices }: { overview: NonNullable<SitePageProps['overview']>; siteName: string; now: Date; onDevices: () => void }) {
    const total = overview.states.reduce((sum, row) => sum + row.count, 0);
    const known = overview.week ? overview.week.values.map((value, index) => ({ value, label: overview.week!.labels[index] })).filter((day) => day.value !== null) : [];

    const stopped = overview.care_stopped ?? [];

    return (
        <div className="flex flex-col gap-5">
            {stopped.length > 0 ? (
                <Log
                    title="Care alerts stopped: consent ended"
                    sub="These sensors no longer alert staff about the person. Link them again once consent is renewed"
                    items={stopped.map((row) => ({
                        key: row.device_id,
                        time: row.ended_at ? seenTime(row.ended_at, now) : '',
                        tone: 'warning',
                        title: `${row.person}’s consent ended`,
                        sub: row.device,
                        action: { label: 'Open sensor', href: row.device_href, ariaLabel: `Open sensor: ${row.device}` },
                    }))}
                />
            ) : null}
            {overview.hub_down ? (
                <KnowList
                    title={`${overview.hub_down.hub} has stopped answering`}
                    sub={overview.hub_down.seen_at ? `Last heard from ${agoWords(overview.hub_down.seen_at, now)}` : 'Not heard from yet'}
                    items={[
                        {
                            tone: 'none',
                            text: `${overview.hub_down.cant_check} ${overview.hub_down.cant_check === 1 ? 'device' : 'devices'} it watches can’t be checked until it’s back. They show grey, not red.`,
                        },
                        { tone: 'info', text: 'It keeps its checks and sensor readings for at least 24 hours and sends them when it’s back.' },
                        ...overview.hub_down.still_seen.map((device) => ({
                            tone: 'success' as const,
                            text: (
                                <>
                                    <Link href={device.href} className="font-medium underline-offset-2 hover:underline">
                                        {device.name}
                                    </Link>{' '}
                                    still reports through its maker’s service
                                </>
                            ),
                        })),
                    ]}
                />
            ) : null}
            <SideBySide cols="minmax(0, 1fr) 360px">
                <div className="flex flex-col gap-5">
                    <Surface title={`Devices at ${siteName}`} sub={total === 0 ? undefined : "Open one from the Devices list"} action={total === 0 ? null : { label: 'All devices', onClick: onDevices }}>
                        <div className="px-[18px] pt-2 pb-4">
                            {total === 0 ? (
                                <p className="text-subtle">No devices here yet.</p>
                            ) : (
                                <div className="flex flex-wrap items-center gap-6">
                                    <DonutChart
                                        size={140}
                                        thickness={20}
                                        centerValue={total}
                                        centerLabel={total === 1 ? 'device' : 'devices'}
                                        data={overview.states.map((row) => ({ label: deviceStateLabel[row.state], value: row.count, color: STATE_COLOUR[row.state] }))}
                                    />
                                </div>
                            )}
                        </div>
                    </Surface>
                    {overview.week && known.length > 0 ? (
                        <PointBars
                            title="Network over the last 7 days"
                            sub="Share of the network gear’s checks that answered, each day"
                            values={known.map((day) => day.value as number)}
                            labels={known.map((day) => day.label)}
                            note={overview.week.note}
                        />
                    ) : (
                        <QuietLine title="Network over the last 7 days" text="No network gear here is checked yet, so there’s no week to show." />
                    )}
                </div>
                <SideColumn>
                    {overview.camera_count === 0 ? (
                        <QuietLine title="Cameras" text="No cameras at this Site." />
                    ) : (
                        <Surface title="Cameras" sub={`${overview.camera_count} ${overview.camera_count === 1 ? 'camera' : 'cameras'} · open one to see it`}>
                            <ul className="mt-2 flex flex-col divide-y divide-border">
                                {overview.cameras.map((camera) => (
                                    <li key={camera.id} className="flex items-center justify-between gap-3 px-[18px] py-2.5">
                                        <Link href={camera.href} className="min-w-0 truncate font-medium underline-offset-2 hover:underline">
                                            {camera.name}
                                        </Link>
                                        <StatusBadge variant={deviceStateTone[camera.state]}>{deviceStateLabel[camera.state]}</StatusBadge>
                                    </li>
                                ))}
                            </ul>
                        </Surface>
                    )}
                </SideColumn>
            </SideBySide>
        </div>
    );
}

function NetworkTab({
    tree,
    links,
    siteId,
    siteName,
    hubLabel,
    now,
    onAddHub,
    onAskObli,
}: {
    tree: SiteTopologyTree | undefined;
    links: LinksFound | null;
    siteId: number;
    siteName: string;
    hubLabel: string | null;
    now: Date;
    onAddHub: (() => void) | null;
    onAskObli: (() => void) | null;
}) {
    const empty = !tree || (tree.roots.length === 0 && tree.unplaced.length === 0);
    // pg-sdr-12j: the map shows only the links people added or confirmed.
    const map = empty ? (
        <QuietLine title="Network map" text="No devices at this Site yet." />
    ) : (
        <SiteTopologyTreeCard
            tree={tree}
            siteName={siteName}
            title="Network map"
            description="The links people have added or confirmed"
            unplacedNote="No link is on the map for these yet. Add one from Links found below."
        />
    );

    return (
        <div className="flex flex-col gap-5">
            {links === null ? (
                map
            ) : links.nothing ? (
                <>
                    {map}
                    <QuietLine title="Links found" text="Nothing reports links here yet." action={onAddHub ? { label: 'Add a hub', onClick: onAddHub } : null} />
                    {links.unplaced.count > 0 ? <NotPlaced links={links} siteId={siteId} siteName={siteName} /> : null}
                </>
            ) : (
                <>
                    <SideBySide cols="minmax(0, 2.3fr) minmax(0, 1fr)">
                        {map}
                        <WhereLinksComeFrom links={links} now={now} />
                    </SideBySide>
                    <LinksFoundTable links={links} siteName={siteName} now={now} />
                    <SideBySide cols="minmax(0, 1.15fr) minmax(0, 1fr)">
                        <LinkChanges links={links} now={now} />
                        <NotPlaced links={links} siteId={siteId} siteName={siteName} />
                    </SideBySide>
                </>
            )}
            {/* S&D Phase I (pop-up 97): read-only network checks through the hub, for IT. */}
            {onAskObli ? (
                <QuietLine
                    title="Something not working?"
                    text={`Obli checks the internet, switch ports and Wi-Fi through ${hubLabel ?? 'the Site’s hub'} and tells you what it found. It only looks; it changes nothing.`}
                    action={{ label: 'Ask Obli', onClick: onAskObli }}
                />
            ) : null}
        </div>
    );
}

function InternetTab({ internet, now }: { internet: NonNullable<SitePageProps['internet']>; now: Date }) {
    return (
        <SideBySide cols="minmax(0, 1fr) 360px">
            <div className="flex flex-col gap-5">
                {internet.gateways.length === 0 ? (
                    <QuietLine title="Router" text="No router or gateway is added for this Site yet." />
                ) : (
                    <Surface title={internet.gateways.length === 1 ? 'Router' : 'Routers'} sub="What connects this Site to the internet">
                        <ul className="mt-2 flex flex-col divide-y divide-border">
                            {internet.gateways.map((row) => (
                                <li key={row.id} className="flex items-center justify-between gap-3 px-[18px] py-2.5">
                                    <div className="min-w-0">
                                        <Link href={row.href} className="font-medium underline-offset-2 hover:underline">
                                            {row.name}
                                        </Link>
                                        <p className="text-caption truncate">
                                            {row.subline}
                                            {row.seen_at ? ` · seen ${agoWords(row.seen_at, now)}` : ''}
                                        </p>
                                    </div>
                                    <StatusBadge variant={deviceStateTone[row.state]}>{deviceStateLabel[row.state]}</StatusBadge>
                                </li>
                            ))}
                        </ul>
                    </Surface>
                )}
                {internet.outages.length === 0 ? (
                    <QuietLine title="Outages" text="No outages recorded for this Site’s router." />
                ) : (
                    <Log
                        title="Outages"
                        sub="When the router dropped out and came back"
                        items={internet.outages.map((event) => ({
                            key: event.id,
                            time: event.at ? seenTime(event.at, now) : '',
                            tone: event.tone,
                            title: event.title,
                            sub: event.device,
                        }))}
                    />
                )}
            </div>
            <SideColumn>
                <Story
                    title="If the internet is down"
                    paras={[
                        { h: 'What keeps going', t: 'The Site’s hub keeps its checks and sensor readings for at least 24 hours and sends them, with their real times, when the line is back.' },
                        { h: 'What you’ll see', t: 'Devices behind the router show “Can’t check” in grey, not red, so one outage doesn’t look like many.' },
                        { h: 'What Oblivion doesn’t know yet', t: 'Which internet line the Site uses and whether it has a backup line.' },
                    ]}
                />
            </SideColumn>
        </SideBySide>
    );
}
