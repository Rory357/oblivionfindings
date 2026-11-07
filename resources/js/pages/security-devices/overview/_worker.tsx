/**
 * The support worker's Overview (S&D rebuild drawing pg-sdr-1c): the house they work at, safety
 * first, then its doors, alarm, panic buttons and sensors, and what they were told today. Built
 * only from what has data behind it today (WorkerOverviewPresenter says what's left out and why).
 */
import { PageHeader, PageHeaderFilterSelect, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderRail, PageLayout } from '@/components/page';
import { NextStep } from '@/components/security-devices/device-blocks';
import { KnowList, Log, PlaceCards, QuietLine, SideBySide, SideColumn, type SgTone } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatTime } from '@/lib/datetime';
import { agoWords, seenTime, timedText } from '@/lib/security-devices/device-time';
import { Head, Link, router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { WorkerKitItem, WorkerLine, WorkerMeter, WorkerOverviewProps, WorkerTone } from './_types';

const BASE = '/security-devices';

function useNow(): Date {
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 60_000);
        return () => window.clearInterval(timer);
    }, []);
    return now;
}

/** A server line's ring: the kit's "neutral" state is the dashed ring. */
function ring(tone: WorkerLine['tone']): SgTone {
    return tone === 'neutral' ? 'none' : tone;
}

function badge(flag: { tone: WorkerTone; text: string }) {
    return <StatusBadge variant={flag.tone}>{flag.text}</StatusBadge>;
}

function scrollTo(id: string) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function subline(props: WorkerOverviewProps): string {
    if (!props.site) return 'Your house’s doors, alarm and sensors';
    if (props.shift) return `${props.site.name}, where you’re on shift today · ${formatTime(props.shift.starts_at)} – ${formatTime(props.shift.ends_at)}`;
    if (props.on_shift) return `${props.site.name}, where you’re on shift now`;
    return `${props.site.name} · doors, alarm and sensors`;
}

export function WorkerOverview(props: WorkerOverviewProps) {
    const { site, sites, meters, safety, kit, around, told, can } = props;
    const now = useNow();
    const deviceHref = (item: WorkerKitItem) => (can.open_devices ? `/security-devices/devices/${item.id}${item.kind === 'zone' ? '?tab=zones' : ''}` : undefined);

    const meterTarget = (meter: WorkerMeter): { href: string } | { onClick: () => void } =>
        meter.key === 'alarm' && meter.device_id !== null && can.open_devices
            ? { href: `/security-devices/devices/${meter.device_id}` }
            : { onClick: () => scrollTo(meter.key === 'safety' ? 'sd-safety' : 'sd-kit') };

    const header = (
        <PageHeader
            icon={ShieldCheck}
            title="Security & devices"
            subline={subline(props)}
            meters={
                meters.length > 0 ? (
                    <>
                        {meters.map((meter) => (
                            <PageHeaderMeterBlock key={meter.key} label={meter.label} tone={meter.tone ?? 'brand'} ariaLabel={`Show ${meter.label.toLowerCase()}`} {...meterTarget(meter)}>
                                <PageHeaderMeterBig>{meter.value}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{timedText(meter.caption, now)}</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ))}
                    </>
                ) : undefined
            }
            filters={
                site && sites.length > 1 ? (
                    <PageHeaderFilterSelect
                        label="Your Sites"
                        value={String(site.id)}
                        allValue=""
                        options={sites}
                        onChange={(value) => router.get(BASE, value ? { site: value } : {}, { preserveScroll: true })}
                    />
                ) : undefined
            }
            rail={<PageHeaderRail<'overview'> ariaLabel="Security & devices views" items={[{ key: 'overview', label: 'Overview' }]} value="overview" onSelect={() => undefined} />}
        />
    );

    const [first, ...rest] = safety;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & devices', href: BASE },
            ]}
        >
            <Head title="Security & devices" />
            <PageLayout hero={header}>
                {!site ? (
                    <QuietLine title="Your house" text="Your HR profile doesn’t link you to a Site yet, so there’s nothing to show here. Your manager can add your Site to your profile." />
                ) : (
                    <SideBySide cols="minmax(0, 1fr) 360px">
                        <div className="flex flex-col gap-5">
                            <div id="sd-safety" className="flex scroll-mt-5 flex-col gap-5">
                                {first ? (
                                    <NextStep
                                        tone={first.tone}
                                        label={`${first.safety ? 'Safety' : 'Needs a look'} · since ${seenTime(first.at, now)}`}
                                        title={first.title}
                                        sub={first.sub || undefined}
                                        then={first.then}
                                        actions={
                                            first.href ? (
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={first.href}>Open alert</Link>
                                                </Button>
                                            ) : undefined
                                        }
                                    />
                                ) : (
                                    <QuietLine
                                        title="Safety"
                                        text={props.on_shift ? 'Nothing needs you right now.' : 'Nothing has been told to you. While you’re on shift here, every safety alert for the house shows here.'}
                                    />
                                )}
                                {rest.length > 0 ? (
                                    <Log
                                        title="Also open"
                                        sub="Worst first"
                                        items={rest.map((alert) => ({
                                            key: alert.id,
                                            time: seenTime(alert.at, now),
                                            tone: alert.tone,
                                            title: alert.title,
                                            sub: alert.sub || undefined,
                                            action: alert.href ? { label: 'Open alert', href: alert.href, ariaLabel: `Open alert: ${alert.title}` } : null,
                                        }))}
                                    />
                                ) : null}
                            </div>

                            <div id="sd-kit" className="scroll-mt-5">
                                {kit.items.length === 0 ? (
                                    <QuietLine title="Doors and alarm" text={`No doors, alarm or panic buttons are set up at ${site.name} yet.`} />
                                ) : (
                                    <PlaceCards
                                        title="Doors and alarm"
                                        sub={
                                            kit.reported_at
                                                ? `As the alarm last reported, ${agoWords(kit.reported_at, now)} · arm and disarm at the keypad${kit.brand ? ` or in the ${kit.brand} app` : ''}`
                                                : 'Arm and disarm at the keypad'
                                        }
                                        cols={3}
                                        items={kit.items.map((item) => ({
                                            id: item.key,
                                            name: item.name,
                                            sub: item.sub,
                                            flag: badge(item.flag),
                                            facts: item.facts.map((fact) => ({ tone: ring(fact.tone), text: timedText(fact, now) })),
                                            href: deviceHref(item),
                                        }))}
                                    />
                                )}
                            </div>

                            {around.length > 0 ? (
                                <PlaceCards
                                    title="Around the house"
                                    cols={3}
                                    items={around.map((place) => ({
                                        id: place.key,
                                        name: place.name,
                                        sub: place.sub,
                                        flag: badge(place.flag),
                                        facts: place.facts.map((fact) => ({ tone: ring(fact.tone), text: timedText(fact, now) })),
                                        href: place.href ?? undefined,
                                    }))}
                                />
                            ) : null}
                        </div>

                        <SideColumn>
                            <KnowList
                                title="What you see here"
                                items={[
                                    { tone: 'success', text: `${site.name}’s doors, alarm, panic buttons and sensors` },
                                    { tone: 'success', text: 'Safety alerts while you’re on shift, in the app' },
                                    {
                                        tone: 'none',
                                        text: (
                                            <>
                                                <b>No live cameras.</b> Managers and IT watch cameras, and each view is recorded
                                            </>
                                        ),
                                    },
                                    { tone: 'none', text: 'No setup and no other houses' },
                                ]}
                            />
                            {told.length === 0 ? (
                                <QuietLine title="Told to you today" text="Nothing yet today." />
                            ) : (
                                <Log
                                    title="Told to you today"
                                    items={told.map((row) => ({ key: row.id, time: row.at ? seenTime(row.at, now) : '', tone: row.tone, title: row.title, sub: row.sub ? `${row.sub} · in the app` : 'In the app' }))}
                                />
                            )}
                        </SideColumn>
                    </SideBySide>
                )}
            </PageLayout>
        </AppLayout>
    );
}
