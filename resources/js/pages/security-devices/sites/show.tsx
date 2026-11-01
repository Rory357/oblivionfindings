/**
 * One Site (S&D rebuild drawing pg-sdr-12, with Network map 12b, Internet 12e and "hub stopped
 * answering" 12f). Rooms and house modes (12c, 12d) come with Phase F.
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
import { ScanNetwork, ScanningViewer } from '@/components/security-devices/scan-popups';
import { SiteTopologyTreeCard } from '@/components/security-devices/site-topology-tree';
import type { SiteTopologyTree } from '@/components/security-devices/topology-types';
import { KnowList, Log, PointBars, QuietLine, SideBySide, SideColumn, Story, Surface } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { agoWords, seenTime, timedText } from '@/lib/security-devices/device-time';
import { deviceStateLabel, deviceStateTone, type DeviceState } from '@/lib/security-devices/device-words';
import { Head, Link, router } from '@inertiajs/react';
import { House, Plus, Radar } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import type { SitePageProps, SitePageTab } from './_types';

export type { SitePageProps } from './_types';

// The Devices, Alerts and Logbook tabs (12g–12i) wait for their drawings' approval.
const TAB_LABEL: Record<SitePageTab, string> = {
    overview: 'Overview',
    network: 'Network map',
    internet: 'Internet',
};

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

type Popup = { kind: 'chooser' } | { kind: 'add' } | { kind: 'scan' } | { kind: 'scanning'; runId: number } | null;

export default function SiteShow(props: SitePageProps) {
    const { site, tab, flag, hub, meters, can } = props;
    const now = useNow();
    const base = `/security-devices/sites/${site.id}`;
    const [popup, setPopup] = useState<Popup>(null);
    const { form, load } = useDeviceFormOptions();
    const open = (next: Exclude<Popup, null>) => {
        load();
        setPopup(next);
    };
    const go = (next: SitePageTab) => router.get(base, next === 'overview' ? {} : { tab: next }, { preserveScroll: true });

    const hubWords = hub === null ? 'no hub watches it yet' : hub.own ? `its own hub is ${hub.label}` : `watched by ${hub.label}`;

    const header = (
        <PageHeader
            variant="profile"
            backHref="/security-devices/sites"
            icon={House}
            title={site.name}
            titleChip={<PageHeaderStatusChip variant={flag.tone}>{flag.text}</PageHeaderStatusChip>}
            subline={[site.address, hubWords].filter(Boolean).join(' · ')}
            actions={
                <>
                    {can.scan ? (
                        <PageHeaderGlassButton icon={Radar} onClick={() => open({ kind: 'scan' })}>
                            Scan now
                        </PageHeaderGlassButton>
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
                        onClick={() => router.visit(`/security-devices/devices?site=${site.id}`)}
                        ariaLabel="Show this Site’s devices"
                    >
                        <PageHeaderMeterBig>{meters.online.of > 0 ? `${meters.online.count} of ${meters.online.of}` : 'Not available'}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{timedText(meters.online.caption, now)}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Alerts open" tone={meters.alerts.count > 0 ? 'warning' : 'success'} onClick={() => router.visit(`/security-devices?site=${site.id}`)} ariaLabel="Show what needs attention at this Site">
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
                        onClick={() => go('overview')}
                        ariaLabel="Show how the hub is doing"
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
                    items={(Object.keys(TAB_LABEL) as SitePageTab[]).map((key) => ({ key, label: TAB_LABEL[key] }))}
                    value={tab}
                    onSelect={go}
                />
            }
        />
    );

    let body: ReactNode = null;
    if (tab === 'overview' && props.overview) body = <OverviewTab overview={props.overview} siteName={site.name} now={now} onDevices={() => go('devices')} />;
    if (tab === 'network' && props.network) body = <NetworkTab tree={props.network.tree as SiteTopologyTree | undefined} siteName={site.name} />;
    if (tab === 'internet' && props.internet) body = <InternetTab internet={props.internet} now={now} />;

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
        </AppLayout>
    );
}

function OverviewTab({ overview, siteName, now, onDevices }: { overview: NonNullable<SitePageProps['overview']>; siteName: string; now: Date; onDevices: () => void }) {
    const total = overview.states.reduce((sum, row) => sum + row.count, 0);
    const known = overview.week ? overview.week.values.map((value, index) => ({ value, label: overview.week!.labels[index] })).filter((day) => day.value !== null) : [];

    return (
        <div className="flex flex-col gap-5">
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
                    <Surface title={`Devices at ${siteName}`} sub={total === 0 ? undefined : 'Open one from the Devices tab'} action={total === 0 ? null : { label: 'All devices', onClick: onDevices }}>
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
                            <ul className="flex flex-col divide-y divide-border">
                                {overview.cameras.map((camera) => (
                                    <li key={camera.id} className="flex items-center justify-between gap-3 py-2.5">
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

function NetworkTab({ tree, siteName }: { tree: SiteTopologyTree | undefined; siteName: string }) {
    const empty = !tree || (tree.roots.length === 0 && tree.unplaced.length === 0);

    return empty ? (
        <QuietLine title="Network map" text="Oblivion hasn’t mapped this Site’s network yet. A scan through its hub fills it in." />
    ) : (
        <SiteTopologyTreeCard tree={tree} siteName={siteName} />
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
                        <ul className="flex flex-col divide-y divide-border">
                            {internet.gateways.map((row) => (
                                <li key={row.id} className="flex items-center justify-between gap-3 py-2.5">
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
