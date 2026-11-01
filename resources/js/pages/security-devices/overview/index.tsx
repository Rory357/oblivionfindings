/**
 * Security & devices home (S&D rebuild drawings pg-sdr-1, its first-time view 1b, and the Trends
 * tab pg-sdr-18): what needs attention, the Sites, cameras and hubs, then trends and activity.
 */
import { DonutChart } from '@/components/dashboard/donut-chart';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageLayout,
} from '@/components/page';
import { AddDeviceChooser, DeviceFormPopup, useDeviceFormOptions } from '@/components/security-devices/device-popups';
import { ScanNetwork, ScanningViewer } from '@/components/security-devices/scan-popups';
import { Journey, Log, PlaceCards, PointBars, QuietLine, SideBySide, SideColumn, Story, Surface, Tally } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { seenTime, timedText } from '@/lib/security-devices/device-time';
import { deviceStateLabel, deviceStateTone, devicesTabLabel } from '@/lib/security-devices/device-words';
import { Head, Link, router } from '@inertiajs/react';
import { Monitor, Network, Plus, Radar, RefreshCw, ShieldCheck } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { FactText, SiteFlagBadge } from '../sites/_parts';
import type { ActivityRow, OverviewProps, OverviewTab } from './_types';

export type { OverviewProps } from './_types';

const BASE = '/security-devices';

// Reports (18b) comes later; Activity (18c) waits for its drawing's approval.
const TAB_LABEL: Record<OverviewTab, string> = { overview: 'Overview', trends: 'Trends' };

const KIND_COLOURS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

const TICK = { fill: 'var(--muted-foreground)', fontSize: 12 };

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

type Popup = { kind: 'chooser' } | { kind: 'add' } | { kind: 'scan' } | { kind: 'scanning'; runId: number } | null;

export default function Overview(props: OverviewProps) {
    const { tab, site, sites, meters, can } = props;
    const now = useNow();
    const [popup, setPopup] = useState<Popup>(null);
    const { form, load } = useDeviceFormOptions();
    const open = (next: Exclude<Popup, null>) => {
        load();
        setPopup(next);
    };
    const go = (changes: { tab?: OverviewTab; site?: string }) => {
        const next = { tab: changes.tab ?? tab, site: changes.site ?? site };
        router.get(
            BASE,
            Object.fromEntries(Object.entries({ tab: next.tab === 'overview' ? undefined : next.tab, site: next.site === 'all' ? undefined : next.site }).filter(([, v]) => v !== undefined)),
            { preserveState: true, preserveScroll: true },
        );
    };
    const devicesHref = (status?: string) => `/security-devices/devices?${new URLSearchParams({ ...(site !== 'all' ? { site } : {}), ...(status ? { status } : {}) }).toString()}`;

    const header = (
        <PageHeader
            icon={ShieldCheck}
            title="Security & devices"
            subline="Your houses, devices and alerts in one place"
            actions={
                <>
                    {can.scan ? (
                        <PageHeaderGlassButton icon={Radar} onClick={() => open({ kind: 'scan' })}>
                            Scan network
                        </PageHeaderGlassButton>
                    ) : null}
                    {can.add || can.scan ? (
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
                        tone={meters.online.of > 0 && meters.online.count === meters.online.of ? 'success' : 'brand'}
                        onClick={() => router.visit(devicesHref('online'))}
                        ariaLabel="Show online devices"
                    >
                        <PageHeaderMeterBig>{meters.online.of > 0 ? `${meters.online.count} of ${meters.online.of}` : 'Not checked yet'}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{timedText(meters.online.caption, now)}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {meters.online.of > 0 ? (
                        <>
                            <PageHeaderMeterBlock label="Offline" tone={meters.offline.count > 0 ? 'critical' : 'success'} onClick={() => router.visit(devicesHref('offline'))} ariaLabel="Show offline devices">
                                <PageHeaderMeterBig>{meters.offline.count}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{timedText(meters.offline.caption, now)}</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock label="Can’t check" onClick={() => router.visit(devicesHref('cant_check'))} ariaLabel="Show devices that can’t be checked">
                                <PageHeaderMeterBig>{meters.cant_check.count}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{meters.cant_check.caption}</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    ) : null}
                    <PageHeaderMeterBlock label="Alerts open" tone={meters.alerts.count > 0 ? 'warning' : 'success'} onClick={() => go({ tab: 'overview' })} ariaLabel="Show what needs attention">
                        <PageHeaderMeterBig>{meters.alerts.count}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{meters.alerts.caption}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={sites.length > 1 ? <PageHeaderFilterSelect label="All Sites" value={site} options={sites} onChange={(value) => go({ site: value })} /> : undefined}
            rail={
                <PageHeaderRail<OverviewTab>
                    ariaLabel="Security & devices views"
                    items={(Object.keys(TAB_LABEL) as OverviewTab[]).map((key) => ({ key, label: TAB_LABEL[key] }))}
                    value={tab}
                    onSelect={(key) => go({ tab: key })}
                />
            }
        />
    );

    let body: ReactNode = null;
    if (tab === 'overview' && props.first_time) body = <FirstTime firstTime={props.first_time} canConnect={can.connect} />;
    else if (tab === 'overview' && props.overview) body = <OverviewBody overview={props.overview} now={now} />;
    else if (tab === 'trends' && props.trends) body = <Trends trends={props.trends} />;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: BASE },
            ]}
        >
            <Head title="Security & devices" />
            <PageLayout hero={header}>{body}</PageLayout>

            <AddDeviceChooser
                open={popup?.kind === 'chooser'}
                can={{ add: can.add, scan: can.scan, connect: false }}
                onClose={() => setPopup(null)}
                onChoose={(choice) => setPopup(choice === 'scan' ? { kind: 'scan' } : { kind: 'add' })}
            />
            <DeviceFormPopup
                open={popup?.kind === 'add'}
                mode="add"
                form={form}
                canAssign={can.assign}
                initialSiteId={site !== 'all' ? Number(site) : null}
                onClose={() => setPopup(null)}
            />
            <ScanNetwork
                open={popup?.kind === 'scan'}
                form={form}
                initialSiteId={site !== 'all' ? Number(site) : null}
                onClose={() => setPopup(null)}
                onStarted={(runId) => setPopup({ kind: 'scanning', runId })}
            />
            <ScanningViewer open={popup?.kind === 'scanning'} runId={popup?.kind === 'scanning' ? popup.runId : null} canStop={can.scan} onClose={() => setPopup(null)} />
        </AppLayout>
    );
}

function FirstTime({ firstTime, canConnect }: { firstTime: NonNullable<OverviewProps['first_time']>; canConnect: boolean }) {
    return (
        <SideBySide cols="minmax(0, 1fr) 360px">
            <div className="flex flex-col gap-5">
                <Journey
                    title="Get started in 4 steps"
                    sub="About 15 minutes for your first Site"
                    steps={firstTime.steps}
                    caption={
                        <>
                            <b>Add a hub</b> is the first step
                        </>
                    }
                />
                <Story
                    title="What a hub is"
                    facts={[
                        { icon: Monitor, text: 'Runs on a Windows PC or a Raspberry Pi' },
                        { icon: Network, text: 'One hub can watch other Sites over the link to head office' },
                        { icon: RefreshCw, text: 'Updates itself' },
                    ]}
                    paras={[
                        { h: 'Why you need one', t: 'Your devices sit on each house’s private network. A hub is a small program at a Site that checks them and sends the results to Oblivion.' },
                        { h: 'What it never does', t: 'It only makes outbound connections to Oblivion, so nothing needs opening at the house.' },
                    ]}
                />
            </div>
            <SideColumn>
                <QuietLine
                    title="Have UniFi, Milesight or alarm accounts?"
                    text="Connect them and their devices appear here straight away."
                    action={canConnect ? { label: 'Connect a service', href: '/security-devices/integrations' } : null}
                />
            </SideColumn>
        </SideBySide>
    );
}

function OverviewBody({ overview, now }: { overview: NonNullable<OverviewProps['overview']>; now: Date }) {
    return (
        <SideBySide cols="minmax(0, 1fr) 360px">
            <div className="flex flex-col gap-5">
                {overview.attention.length === 0 ? (
                    <QuietLine title="Needs attention" text="Nothing needs attention right now." />
                ) : (
                    <Log
                        title="Needs attention"
                        sub={overview.attention_count > overview.attention.length ? `Newest ${overview.attention.length} of ${overview.attention_count}` : 'Newest first · open one to act on it'}
                        items={overview.attention.map((row) => ({
                            key: row.key,
                            time: row.at ? seenTime(row.at, now) : '',
                            tone: row.tone,
                            title: row.title,
                            sub: row.sub,
                            action: { label: row.action, href: row.href, ariaLabel: `${row.action}: ${row.title}` },
                        }))}
                    />
                )}
                {overview.sites.length === 0 ? (
                    <QuietLine title="Sites" text="No Sites to show." />
                ) : (
                    <PlaceCards
                        title="Sites"
                        action={{ label: 'All Sites', href: '/security-devices/sites' }}
                        cols={3}
                        items={overview.sites.map((card) => ({
                            id: card.id,
                            name: card.name,
                            sub: card.address,
                            flag: <SiteFlagBadge flag={card.flag} />,
                            facts: card.facts.slice(0, 3).map((fact) => ({ tone: fact.tone, text: <FactText fact={fact} now={now} /> })),
                            href: card.href,
                        }))}
                    />
                )}
            </div>
            <SideColumn>
                {overview.camera_count === 0 ? null : (
                    <Surface title="Cameras" sub={`${overview.camera_count} ${overview.camera_count === 1 ? 'camera' : 'cameras'} · open one to see it`} action={{ label: 'All cameras', href: '/security-devices/devices?tab=cameras' }}>
                        <ul className="flex flex-col divide-y divide-border">
                            {overview.cameras.map((camera) => (
                                <li key={camera.id} className="flex items-center justify-between gap-3 py-2.5">
                                    <div className="min-w-0">
                                        <Link href={camera.href} className="block truncate font-medium underline-offset-2 hover:underline">
                                            {camera.name}
                                        </Link>
                                        {camera.site ? <p className="text-caption truncate">{camera.site}</p> : null}
                                    </div>
                                    <StatusBadge variant={deviceStateTone[camera.state]}>{deviceStateLabel[camera.state]}</StatusBadge>
                                </li>
                            ))}
                        </ul>
                    </Surface>
                )}
                {overview.hubs ? (
                    <Tally
                        title="Hubs"
                        sub="The small programs at your Sites that check your devices"
                        items={[
                            { label: 'Answering', value: overview.hubs.online, tone: 'success' },
                            { label: 'Not answering', value: overview.hubs.offline, tone: 'critical' },
                            { label: 'Last update didn’t install', value: overview.hubs.not_installed, tone: 'warning' },
                        ]}
                        calm={overview.hubs.calm ?? undefined}
                    />
                ) : null}
                <Activity rows={overview.activity} now={now} limit={6} title="Recent activity" />
            </SideColumn>
        </SideBySide>
    );
}

function Activity({ rows, now, limit, title, sub, action }: { rows: ActivityRow[]; now: Date; limit: number; title: string; sub?: string; action?: { label: string; href: string } }) {
    if (rows.length === 0) {
        return <QuietLine title={title} text="Nothing has been recorded about your devices yet." />;
    }

    return (
        <Log
            title={title}
            sub={sub}
            action={action ?? null}
            limit={limit}
            items={rows.map((row) => ({
                key: row.id,
                time: row.at ? seenTime(row.at, now) : '',
                tone: row.tone,
                title: row.title,
                sub: row.sub,
                action: { label: 'Open', href: row.href, ariaLabel: `Open ${row.sub || row.title}` },
            }))}
        />
    );
}

function Trends({ trends }: { trends: NonNullable<OverviewProps['trends']> }) {
    const rows = trends.online
        ? trends.online.labels.map((label, index) => ({ label, online: trends.online!.online[index], watched: trends.online!.watched[index] }))
        : [];
    const knownWeeks = trends.alerts_per_week;

    return (
        <div className="flex flex-col gap-5">
            {trends.online ? (
                <Surface title="Devices online" sub="Every Site, last 30 days · a device counts as online for a day when every check answered">
                    <div className="h-64 w-full" role="img" aria-label={`Devices online each day for the last 30 days. ${trends.online.note}`}>
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart data={rows} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                                <CartesianGrid vertical={false} stroke="var(--border)" />
                                <XAxis dataKey="label" tick={TICK} tickLine={false} axisLine={false} interval="preserveStartEnd" minTickGap={24} />
                                <YAxis allowDecimals={false} tick={TICK} tickLine={false} axisLine={false} width={32} />
                                <Tooltip contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8 }} />
                                <Line type="linear" dataKey="watched" name="Watched" stroke="var(--muted-foreground)" strokeWidth={1.5} strokeDasharray="4 4" dot={false} connectNulls={false} isAnimationActive={false} />
                                <Line type="linear" dataKey="online" name="Online" stroke="var(--chart-1)" strokeWidth={2} dot={false} connectNulls={false} isAnimationActive={false} />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                    <p className="text-caption mt-2">{trends.online.note}</p>
                </Surface>
            ) : (
                <QuietLine title="Devices online" text="No device is checked yet, so there’s no history to show." />
            )}
            <div className="grid gap-5 lg:grid-cols-3">
                <Surface title="Devices by kind">
                    {trends.device_count === 0 ? (
                        <p className="text-subtle">No devices yet.</p>
                    ) : (
                        <DonutChart
                            size={140}
                            thickness={20}
                            centerValue={trends.device_count}
                            centerLabel={trends.device_count === 1 ? 'device' : 'devices'}
                            data={trends.kinds.map((kind, index) => ({ label: devicesTabLabel[kind.tab] ?? kind.tab, value: kind.count, color: KIND_COLOURS[index % KIND_COLOURS.length] }))}
                        />
                    )}
                </Surface>
                {knownWeeks ? (
                    <PointBars
                        title="Alerts per week"
                        sub="About your devices"
                        values={knownWeeks.values}
                        labels={knownWeeks.labels}
                        note={
                            knownWeeks.values[knownWeeks.values.length - 1] <= knownWeeks.values[knownWeeks.values.length - 2]
                                ? `${knownWeeks.values[knownWeeks.values.length - 1]} this week, no more than the week before.`
                                : `${knownWeeks.values[knownWeeks.values.length - 1]} this week, up from ${knownWeeks.values[knownWeeks.values.length - 2]}.`
                        }
                    />
                ) : (
                    <QuietLine title="Alerts per week" text="Alerts aren’t available to you." />
                )}
                {trends.batteries.length === 0 ? (
                    <QuietLine title="Batteries to replace soon" text="No battery is at 35% or below." />
                ) : (
                    <Surface title="Batteries to replace soon" sub="Lowest first, from each device’s latest report">
                        <ul className="flex flex-col divide-y divide-border">
                            {trends.batteries.map((battery) => (
                                <li key={battery.id} className="flex items-center justify-between gap-3 py-2.5">
                                    <div className="min-w-0">
                                        <Link href={battery.href} className="block truncate font-medium underline-offset-2 hover:underline">
                                            {battery.name}
                                        </Link>
                                        {battery.site ? <p className="text-caption truncate">{battery.site}</p> : null}
                                    </div>
                                    <StatusBadge variant={(battery.battery ?? 100) <= 20 ? 'warning' : 'neutral'}>{battery.battery}%</StatusBadge>
                                </li>
                            ))}
                        </ul>
                    </Surface>
                )}
            </div>
            {trends.slowest.length === 0 ? (
                <QuietLine title="Slowest devices this week" text="No reply times recorded this week." />
            ) : (
                <Surface title="Slowest devices this week" sub="Average reply time, with its usual over the 30 days before">
                    <table className="w-full text-sm">
                        <thead className="bg-muted text-left text-muted-foreground">
                            <tr>
                                <th className="px-3 py-2 font-medium">Device</th>
                                <th className="px-3 py-2 font-medium">Site</th>
                                <th className="px-3 py-2 font-medium">Average reply</th>
                                <th className="px-3 py-2 font-medium">Usual</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {trends.slowest.map((row) => (
                                <tr key={row.id}>
                                    <td className="px-3 py-2">
                                        <Link href={row.href} className="font-medium underline-offset-2 hover:underline">
                                            {row.name}
                                        </Link>
                                        <p className="text-caption">{row.model}</p>
                                    </td>
                                    <td className="px-3 py-2">{row.site ?? 'Not available'}</td>
                                    <td className="px-3 py-2">
                                        <StatusBadge variant={row.usual_ms !== null && row.week_ms > row.usual_ms * 2 ? 'warning' : 'success'}>{row.week_ms} ms</StatusBadge>
                                    </td>
                                    <td className="px-3 py-2 text-muted-foreground">{row.usual_ms === null ? 'Not available' : `${row.usual_ms} ms`}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Surface>
            )}
        </div>
    );
}
