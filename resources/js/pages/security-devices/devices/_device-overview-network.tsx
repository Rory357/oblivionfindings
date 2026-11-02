/**
 * Network gear's overview (drawings pg-sdr-3 switch, pg-sdr-20 Wi-Fi point) and the page's own
 * "something is wrong" state when it's down (pg-sdr-17): what Oblivion knows, the devices behind
 * it in grey "Can't check", the downtime so far and who the alert rule tells.
 */
import {
    AnsweredDonut,
    DeviceMap,
    NextStep,
    TrendChart,
    type MapNodeData,
    type TrendRangeKey,
} from '@/components/security-devices/device-blocks';
import { FactRows, KnowList, Log, QuietLine, SideBySide } from '@/components/signature/blocks';
import { seenTime } from '@/lib/security-devices/device-time';
import type { DeviceKind } from '@/lib/security-devices/device-words';
import type { DeviceOverview, DevicePageProps, LogEntry, TroubleState } from './_device-page-types';
import { logItems, MainSide, plural } from './_device-page-parts';

const RANGES: TrendRangeKey[] = ['24h', '7d', '30d'];

/** The short name each kind goes by in sentences: "the switch", "the UPS". */
export const KIND_NOUN: Partial<Record<DeviceKind, string>> = {
    switch: 'switch',
    router: 'router',
    wifi_point: 'Wi-Fi point',
    network_device: 'device',
    computer: 'PC',
    printer: 'printer',
    ups: 'UPS',
    recorder: 'recorder',
    sensor_gateway: 'gateway',
    other: 'device',
};

export function aboutTitle(kind: DeviceKind): string {
    return `About this ${KIND_NOUN[kind] ?? 'device'}`;
}

export function RecentLog({
    title = 'Recent events',
    entries,
    canSee,
    now,
    onCompare,
}: {
    title?: string;
    entries: LogEntry[];
    canSee: boolean;
    now: Date;
    onCompare?: (snapshotId: number) => void;
}) {
    if (!canSee) return <QuietLine title={title} text="You can’t see this device’s events." />;
    if (entries.length === 0) return <QuietLine title={title} text="Nothing recorded at this Site yet." />;
    return (
        <Log
            title={title}
            items={logItems(entries, now, (entry) =>
                entry.compare_id && onCompare ? { label: 'See change', onClick: () => onCompare(entry.compare_id as number) } : undefined,
            )}
        />
    );
}

export function ReplyTimeTrend({
    overview,
    range,
    onRange,
    noun,
}: {
    overview: DeviceOverview;
    range: TrendRangeKey;
    onRange: (range: TrendRangeKey) => void;
    noun: string;
}) {
    if (!overview.trend) {
        return <QuietLine title="Reply time" text="Not available: no reply times recorded at this Site in this range." />;
    }
    return (
        <TrendChart
            title="Reply time"
            sub={`How long the ${noun} takes to answer a check`}
            start={overview.trend.start}
            step={overview.trend.step_seconds}
            points={overview.trend.points}
            unit={overview.trend.unit}
            range={range}
            ranges={RANGES}
            onRange={onRange}
            peakWords="Slowest"
        />
    );
}

export function OnlineDonut({ overview }: { overview: DeviceOverview }) {
    const availability = overview.availability;
    if (!availability) return <QuietLine title="Online in the last 30 days" text="Not available: it hasn’t been checked at this Site." />;
    return (
        <AnsweredDonut
            title="Online in the last 30 days"
            answered={availability.answered}
            missed={availability.missed}
            percent={availability.percent}
            days={availability.days}
            lastMissedAt={availability.last_missed_at}
        />
    );
}

export function NetworkOverviewTab({
    props,
    now,
    onRange,
    onCompare,
}: {
    props: DevicePageProps;
    now: Date;
    onRange: (range: TrendRangeKey) => void;
    onCompare: (snapshotId: number) => void;
}) {
    const overview = props.overview;
    if (!overview) return null;
    const noun = KIND_NOUN[props.device.kind] ?? 'device';
    const connected = overview.connected;

    return (
        <MainSide
            main={
                <>
                    <ReplyTimeTrend overview={overview} range={props.range} onRange={onRange} noun={noun} />
                    {connected ? (
                        <DeviceMap
                            title="Connected to"
                            sub="From the links recorded in Oblivion"
                            levels={[connected.up, [connected.self], connected.down] as MapNodeData[][]}
                            selectedId={connected.self.id}
                            more={connected.more > 0 ? `and ${plural(connected.more, 'more device', 'more devices')}` : null}
                            legend={[
                                { tone: 'success', label: 'Online' },
                                { tone: 'critical', label: 'Offline' },
                                { tone: 'none', label: 'Can’t check or not watched' },
                            ]}
                        />
                    ) : null}
                </>
            }
            side={
                <>
                    <OnlineDonut overview={overview} />
                    <RecentLog entries={overview.events} canSee={props.can.see_events} now={now} onCompare={onCompare} />
                    <FactRows title={aboutTitle(props.device.kind)} rows={overview.facts} />
                </>
            }
        />
    );
}

/** The router-in-trouble state (pg-sdr-17), in place of the overview. */
export function TroubleOverview({ props, trouble, now }: { props: DevicePageProps; trouble: TroubleState; now: Date }) {
    const name = props.device.name;
    const every = trouble.every_seconds;
    const everyWords = every === null ? null : every < 120 ? 'every minute' : `every ${Math.round(every / 60)} min`;
    const toldFirst = trouble.told?.rows[0]?.[1];

    const downtime: LogEntry[] = [
        ...(trouble.last_reply
            ? [{
                  key: 'last-reply',
                  at: trouble.last_reply.at,
                  tone: 'success' as const,
                  title: 'Last reply',
                  sub: trouble.last_reply.latency_ms !== null ? `${trouble.last_reply.latency_ms} ms` : null,
              }]
            : []),
        ...(trouble.missed.first_at
            ? [{ key: 'first-miss', at: trouble.missed.first_at, tone: 'warning' as const, title: 'No reply', sub: everyWords ? `${trouble.checked_by} tries ${everyWords}` : null }]
            : []),
        ...trouble.events,
        ...(trouble.missed.last_at && trouble.missed.count > 1
            ? [{ key: 'still', at: trouble.missed.last_at, tone: 'critical' as const, title: 'Still no reply', sub: plural(trouble.missed.count, 'check missed', 'checks missed') }]
            : []),
    ];

    const behindNodes: MapNodeData[] = trouble.behind.map((device) => ({
        id: device.id,
        name: device.name,
        kind: device.kind,
        sub: device.model,
        state: 'cant_check',
        href: device.href,
    }));

    return (
        <div className="grid gap-5">
            <NextStep
                tone="critical"
                label={trouble.since ? `Offline since ${seenTime(trouble.since, now)}` : 'Offline'}
                title={`${name} isn’t answering`}
                sub={
                    <>
                        {trouble.checked_by} {everyWords ? `tries ${everyWords}` : 'keeps trying'}
                        {trouble.last_try_at ? `, last ${seenTime(trouble.last_try_at, now)}` : ''}.
                        {toldFirst ? ` The alert rule tells ${toldFirst.split(' · ')[0]}.` : ''}
                    </>
                }
                then={trouble.next}
            />
            <DeviceMap
                title="Where it sits"
                sub={`${trouble.checked_by} checks it · grey means can’t check`}
                levels={[
                    [{ id: 'hub', name: trouble.checked_by, kind: 'hub', sub: 'Still checking', state: 'hub_online', href: null }],
                    [{ id: props.device.id, name, kind: props.device.kind, sub: trouble.since ? `Offline since ${seenTime(trouble.since, now)}` : 'Offline', state: 'offline', href: null }],
                    behindNodes.slice(0, 6),
                ]}
                selectedId={props.device.id}
                more={trouble.behind_count > 6 ? `and ${plural(trouble.behind_count - 6, 'more device', 'more devices')} behind it` : null}
                legend={[
                    { tone: 'success', label: 'Online' },
                    { tone: 'critical', label: 'Offline' },
                    { tone: 'none', label: 'Can’t check' },
                ]}
            />
            <SideBySide cols="repeat(auto-fit, minmax(min(100%, 280px), 1fr))">
                {trouble.behind_count > 0 ? (
                    <KnowList
                        title={`${plural(trouble.behind_count, 'device', 'devices')} behind it can’t be checked`}
                        sub={`Grey, not ${plural(trouble.behind_count, 'more alert', 'more alerts')}`}
                        items={[
                            ...trouble.behind.map((device) => ({
                                tone: 'none' as const,
                                text: (
                                    <>
                                        <b className="font-semibold">{device.name}</b> · {device.model}
                                        {device.last_seen_at ? ` · last seen ${seenTime(device.last_seen_at, now)}` : ''}
                                    </>
                                ),
                            })),
                            ...(trouble.behind_count > trouble.behind.length
                                ? [{ tone: 'none' as const, text: `and ${plural(trouble.behind_count - trouble.behind.length, 'more device', 'more devices')}` }]
                                : []),
                        ]}
                    />
                ) : (
                    <QuietLine title="Behind it" text="No devices are recorded behind it." />
                )}
                <Log title="Downtime so far" items={logItems(downtime, now)} limit={10} />
                {trouble.told ? (
                    <FactRows title="Who’s told" sub={trouble.told.sub} rows={trouble.told.rows} />
                ) : (
                    <QuietLine title="Who’s told" text="Put it at a Site so an alert rule covers it." />
                )}
            </SideBySide>
        </div>
    );
}
