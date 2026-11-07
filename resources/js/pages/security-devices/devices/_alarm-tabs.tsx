/**
 * The alarm panel's Overview (drawings pg-sdr-5 Ajax view only and 5c an Inner Range Inception panel)
 * and Zones tab (5b). Everything is read from the panel's own reports; what it doesn't report says so.
 */
import { DutyClock, FactRows, KnowList, Log, PlaceCards, QuietLine, Surface, type SgTone } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { seenTime, timedText } from '@/lib/security-devices/device-time';
import { AppWindow, DoorClosed, Flame, Keyboard, Radar, Siren, Volume2, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { logItems, MainSide } from './_device-page-parts';
import { commandLineItems } from './_safety-kit-parts';
import type { AlarmArea, AlarmPageProps, AlarmZone } from './_safety-kit-types';

/** A zone's picture from its name: a door, a window, motion, a panic button, a siren, a keypad. */
export function zoneIcon(name: string): LucideIcon {
    const words = name.toLowerCase();
    if (words.includes('window')) return AppWindow;
    if (words.includes('panic') || words.includes('button')) return Siren;
    if (words.includes('siren')) return Volume2;
    if (words.includes('keypad')) return Keyboard;
    if (words.includes('smoke') || words.includes('fire') || words.includes('heat')) return Flame;
    if (words.includes('motion') || words.includes('pir')) return Radar;
    return DoorClosed;
}

const ZONE_RING: Record<AlarmZone['state'], SgTone> = {
    normal: 'success',
    open: 'warning',
    triggered: 'critical',
    bypassed: 'warning',
    tamper: 'warning',
    fault: 'warning',
    not_reported: 'none',
};

function areaFlag(area: AlarmArea): ReactNode {
    if (area.armed === null) return <StatusBadge variant="neutral">No state yet</StatusBadge>;
    return area.armed ? <StatusBadge variant="info">{area.mode ? `Armed (${area.mode})` : 'Armed'}</StatusBadge> : <StatusBadge variant="neutral">Off</StatusBadge>;
}

function bigRing(panel: AlarmPageProps['panel']): { big: ReactNode; unit?: string } {
    switch (panel.state) {
        case 'armed':
            return { big: 'On', unit: panel.mode ?? undefined };
        case 'partly':
            return { big: `${panel.armed}/${panel.total}`, unit: 'areas' };
        case 'disarmed':
            return { big: 'Off' };
        case 'alarm':
            return { big: <Siren aria-label="Alarm" className="size-6" />, unit: 'alarm' };
        default:
            return { big: '—' };
    }
}

function clockTitle(panel: AlarmPageProps['panel'], now: Date): string {
    const since = panel.since ? ` since ${seenTime(panel.since, now)}` : '';
    switch (panel.state) {
        case 'armed':
            return `${panel.label}${since}`;
        case 'partly':
            return `Partly armed: ${panel.armed} of ${panel.total} areas${since}`;
        case 'disarmed':
            return `Disarmed${since}`;
        case 'alarm':
            return `Alarm going off${since}`;
        case 'hidden':
            return 'You can’t see this panel’s reports';
        default:
            return 'No armed state reported yet';
    }
}

export function AlarmOverview({
    props,
    now,
    onTab,
    onArm,
    onArmArea,
    onDisarm,
    onCheck,
}: {
    props: AlarmPageProps;
    now: Date;
    onTab: (tab: AlarmPageProps['tab']) => void;
    onArm: () => void;
    onArmArea: () => void;
    onDisarm: () => void;
    onCheck: () => void;
}) {
    const overview = props.overview;
    if (!overview) return null;
    const { panel, control, can, areas } = props;
    const ring = bigRing(panel);
    const tone = panel.state === 'alarm' ? 'critical' : panel.state === 'armed' || panel.state === 'partly' ? 'success' : undefined;
    const someDisarmed = areas.some((area) => area.armed !== true);
    // Not reported yet: both are offered; otherwise the one that changes something.
    const offerArm = panel.state === 'disarmed' || panel.state === 'not_reported';
    const offerDisarm = panel.state !== 'disarmed';
    const areaName = (id: string) => areas.find((area) => area.id === id)?.name ?? null;

    const lines = [
        ...overview.lines.map((line) => ({
            state: line.state,
            text: (
                <>
                    {line.lead ? <b className="font-semibold">{line.lead}</b> : null}
                    {line.lead ? ' ' : null}
                    {timedText({ text: line.text, at: line.at }, now)}
                </>
            ),
        })),
        ...commandLineItems({ commands: control.commands, areaName, now, deviceId: props.device.id, onCheck }),
    ];

    const actions =
        can.arm || can.disarm ? (
            <>
                {can.arm && areas.length > 1 && someDisarmed ? (
                    <Button type="button" size="sm" variant="outline" onClick={onArmArea}>
                        Arm an area…
                    </Button>
                ) : null}
                {can.arm && offerArm ? (
                    <Button type="button" size="sm" variant={can.disarm && offerDisarm ? 'outline' : 'default'} onClick={onArm}>
                        Arm…
                    </Button>
                ) : null}
                {can.disarm && offerDisarm ? (
                    <Button type="button" size="sm" onClick={onDisarm}>
                        Disarm…
                    </Button>
                ) : null}
            </>
        ) : undefined;

    const sub =
        panel.state === 'partly'
            ? `${areas.filter((area) => area.armed).map((area) => area.name).join(', ')} armed. ${areas.filter((area) => !area.armed).map((area) => area.name).join(', ')} not armed.`
            : overview.last_report
              ? timedText({ text: 'From the panel’s last report {ago}', at: overview.last_report }, now)
              : panel.state === 'hidden'
                ? 'Seeing a panel’s reports needs permission to see device events.'
                : 'The panel hasn’t sent a report to Oblivion yet.';

    return (
        <MainSide
            main={
                <>
                    <DutyClock
                        tone={tone}
                        big={ring.big}
                        unit={ring.unit}
                        kicker={control.view_only ? 'Alarm · view only' : 'Alarm'}
                        title={clockTitle(panel, now)}
                        sub={sub}
                        lines={lines}
                        actions={actions}
                    />
                    {areas.length > 1 ? (
                        <PlaceCards
                            title="Areas"
                            cols={2}
                            items={areas.map((area) => {
                                const zones = control.zones.filter((zone) => zone.area === area.name);
                                const open = zones.filter((zone) => zone.state === 'open');
                                return {
                                    id: area.id,
                                    name: area.name,
                                    sub: zones.length > 0 ? `${zones.length} ${zones.length === 1 ? 'zone' : 'zones'}` : undefined,
                                    flag: areaFlag(area),
                                    facts: [
                                        zones.length > 0
                                            ? { tone: open.length > 0 ? 'warning' : 'success', text: open.length > 0 ? `${open.length} open` : 'All closed' }
                                            : { tone: 'none', text: 'No zone reports yet' },
                                        ...(area.since
                                            ? [{ tone: 'brand' as const, text: `${area.armed ? 'Armed' : 'Disarmed'} ${seenTime(area.since, now)}${area.by ? ` · ${area.by}` : ''}` }]
                                            : []),
                                    ],
                                };
                            })}
                        />
                    ) : null}
                    {/* A panel with areas shows them instead (5c); its zones are on the Zones tab. */}
                    {areas.length > 1 ? null : overview.zones.length > 0 ? (
                        <PlaceCards
                            title="Zones"
                            cols={3}
                            action={overview.zone_count > 0 ? { label: 'All zones', onClick: () => onTab('zones') } : null}
                            items={overview.zones.map((zone) => ({
                                id: zone.id,
                                name: zone.name,
                                sub: [zone.number ? `Zone ${zone.number}` : null, zone.area].filter(Boolean).join(' · ') || undefined,
                                flag: <StatusBadge variant={zone.tone}>{zone.label}</StatusBadge>,
                                facts: zone.changed_at ? [{ tone: ZONE_RING[zone.state], text: `Last change ${seenTime(zone.changed_at, now)}` }] : [{ tone: 'none', text: 'No report yet' }],
                            }))}
                        />
                    ) : (
                        <QuietLine title="Zones" text="No zones yet: the panel hasn’t reported any, and none are named in its settings." />
                    )}
                </>
            }
            side={
                <>
                    {props.can.see_events ? (
                        overview.events.length > 0 ? (
                            <Log title="Recent events" items={logItems(overview.events, now)} />
                        ) : (
                            <QuietLine title="Recent events" text="Nothing reported by the panel yet." />
                        )
                    ) : null}
                    <FactRows title="For IT" rows={overview.facts.map(([label, value]) => [label, timedText(value, now)])} />
                </>
            }
        />
    );
}

export function AlarmZones({ props, now }: { props: AlarmPageProps; now: Date }) {
    const zones = props.zones;
    if (!zones) return null;
    const withArea = zones.rows.some((zone) => zone.area !== null);
    const brand = props.control.brand;

    return (
        <MainSide
            main={
                zones.rows.length === 0 ? (
                    <QuietLine title="Zones" text="No zones yet: the panel hasn’t reported any, and none are named in its settings." />
                ) : (
                    <Surface title="Zones" sub={`${zones.rows.length} ${zones.rows.length === 1 ? 'zone' : 'zones'} · read from the panel’s reports`}>
                        <div className="mt-3 overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Zone</TableHead>
                                        <TableHead>State</TableHead>
                                        {withArea ? <TableHead>Area</TableHead> : null}
                                        <TableHead className="whitespace-nowrap">Last change</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {zones.rows.map((zone) => {
                                        const Icon = zoneIcon(zone.name);
                                        return (
                                            <TableRow key={zone.id}>
                                                <TableCell>
                                                    <span className="flex items-center gap-2.5">
                                                        <Icon aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                                                        <span className="grid min-w-0">
                                                            <b className="truncate text-sm font-semibold">{zone.name}</b>
                                                            {zone.number ? <small className="text-caption">Zone {zone.number}</small> : null}
                                                        </span>
                                                    </span>
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge variant={zone.tone}>{zone.label}</StatusBadge>
                                                </TableCell>
                                                {withArea ? <TableCell>{zone.area ?? '—'}</TableCell> : null}
                                                <TableCell className="whitespace-nowrap">{zone.changed_at ? seenTime(zone.changed_at, now) : '—'}</TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </Surface>
                )
            }
            side={
                <>
                    {zones.modes_help ? (
                        <KnowList
                            title="How the modes work"
                            items={[
                                { tone: 'info', text: <><b>Away</b> arms every zone</> },
                                { tone: 'info', text: <><b>At home</b> arms doors and windows only, so people can move around inside at night</> },
                                { tone: 'warning', text: <>A zone that’s open when the alarm arms is <b>left out</b> until it closes</> },
                                { tone: 'success', text: <>The <b>panic button</b> is always on, even when the alarm is off</> },
                            ]}
                        />
                    ) : null}
                    {props.control.view_only ? (
                        <QuietLine
                            title="View only"
                            text={`Arm, disarm and add zones ${brand === 'Ajax' ? 'in the Ajax app' : 'at the panel or in the maker’s app'}. Changes show here when the panel reports them.`}
                        />
                    ) : null}
                </>
            }
        />
    );
}
