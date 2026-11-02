/**
 * The sensor gateway's overview (drawing pg-sdr-22): the sensors behind it with their reading,
 * signal and battery; what happens if it goes quiet; recent alerts on it and its sensors; how it
 * reaches Oblivion (its own login on the sensor broker and the Site's hub, never the public
 * internet, D21); and the gateway's own details. Readings the sensors don't send say "Not
 * available"; nothing is filled in for them.
 */
import { EntityContextMenu, EntityKebab, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { KindIcon, iconForKind } from '@/components/security-devices/device-kind-icon';
import type { PopupDevice } from '@/components/security-devices/device-popups';
import { FactRows, Log, Note, QuietLine, Surface } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { seenTime } from '@/lib/security-devices/device-time';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import type { MouseEvent } from 'react';
import type { DeviceOverview, DevicePageProps, GatewaySensor, LogEntry } from './_device-page-types';
import { logTime, MainSide } from './_device-page-parts';
import { nzDate } from './_sensor-tracker-parts';

export type GatewaySensorRow = GatewaySensor & { reading_tone?: 'warning' | 'critical' | null; signal?: string | null };

export type GatewayRoute = {
    hub: string | null;
    reaches: string | null;
    connection: { tone: string; text: string };
    logins: { id: number; username: string | null; topic: string | null; made_at: string | null; last_reading_at: string | null }[];
    broker_href: string | null;
};

export type GatewayExtras = {
    route: GatewayRoute;
    quiet: { watched: boolean; text: string };
    recent: (LogEntry & { href?: string | null })[] | null;
};

export type GatewayOverview = DeviceOverview & { sensors?: GatewaySensorRow[]; gateway?: GatewayExtras };

function GatewaySensors({
    sensors,
    now,
    menuFor,
    onContextMenu,
}: {
    sensors: GatewaySensorRow[];
    now: Date;
    menuFor: (device: PopupDevice) => MenuItem[];
    onContextMenu: (event: MouseEvent, device: PopupDevice) => void;
}) {
    if (sensors.length === 0) {
        return <QuietLine title="Sensors on this gateway" text="No sensors are linked to this gateway yet." />;
    }
    const asDevice = (sensor: GatewaySensorRow): PopupDevice => ({
        id: sensor.id,
        name: sensor.name,
        sub: sensor.model,
        kind: sensor.kind,
        state: sensor.state,
        address: sensor.address,
        site: sensor.site,
        site_id: sensor.site_id,
    });
    return (
        <Surface title="Sensors on this gateway" sub="Wireless sensors around the house · each one’s latest reading, signal and battery">
            <div className="mt-3 overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Sensor</TableHead>
                            <TableHead>Where</TableHead>
                            <TableHead>Last reading</TableHead>
                            <TableHead>Signal</TableHead>
                            <TableHead>Battery</TableHead>
                            <TableHead className="w-10">
                                <span className="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {sensors.map((sensor) => (
                            <TableRow
                                key={sensor.id}
                                className="cursor-pointer"
                                onClick={() => router.visit(sensor.href)}
                                onContextMenu={(event) => onContextMenu(event, asDevice(sensor))}
                            >
                                <TableCell>
                                    <span className="flex items-center gap-2.5">
                                        <KindIcon kind={sensor.kind} className="size-4 shrink-0 text-muted-foreground" />
                                        <span className="grid min-w-0">
                                            <b className="truncate text-sm font-semibold">{sensor.name}</b>
                                            <small className="text-caption truncate">{sensor.model}</small>
                                        </span>
                                    </span>
                                </TableCell>
                                <TableCell className="whitespace-normal">{sensor.where ?? '—'}</TableCell>
                                <TableCell className="whitespace-normal">
                                    {sensor.state === 'cant_check' || sensor.state === 'offline' ? (
                                        <StatusBadge variant={sensor.state === 'offline' ? 'critical' : 'neutral'}>
                                            {sensor.state === 'offline' ? 'Offline' : 'Can’t check'}
                                        </StatusBadge>
                                    ) : (
                                        <span className="grid justify-items-start gap-0.5">
                                            {sensor.reading && sensor.reading_tone ? (
                                                <StatusBadge variant={sensor.reading_tone}>{sensor.reading}</StatusBadge>
                                            ) : (
                                                <span>{sensor.reading ?? 'Not available'}</span>
                                            )}
                                            {sensor.seen_at ? <small className="text-caption">{seenTime(sensor.seen_at, now)}</small> : null}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="tabular-nums">{sensor.signal ?? <span className="text-muted-foreground">Not available</span>}</TableCell>
                                <TableCell>
                                    {sensor.battery === null ? (
                                        <span className="text-muted-foreground">Not available</span>
                                    ) : (
                                        <span className="flex items-center gap-2">
                                            <span aria-hidden="true" className="h-1.5 w-14 overflow-hidden rounded-full bg-muted">
                                                <i
                                                    className={cn('block h-full rounded-full', sensor.battery_low ? 'bg-status-warning' : 'bg-status-success')}
                                                    style={{ width: `${Math.max(4, Math.min(100, sensor.battery))}%` }}
                                                />
                                            </span>
                                            <span className="tabular-nums">{sensor.battery}%</span>
                                            {sensor.battery_low ? <StatusBadge variant="warning">Low</StatusBadge> : null}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell onClick={(event) => event.stopPropagation()}>
                                    <EntityKebab actions={menuFor(asDevice(sensor))} label={`Actions for ${sensor.name}`} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </Surface>
    );
}

function RecentOnGateway({ recent, now }: { recent: GatewayExtras['recent'] | undefined; now: Date }) {
    if (!recent) return <QuietLine title="Recent" text="You can’t see this gateway’s alerts." />;
    if (recent.length === 0) return <QuietLine title="Recent" text="No alerts on this gateway or its sensors in the last 90 days." />;
    return (
        <Log
            title="Recent"
            sub="Alerts on the gateway and its sensors"
            items={recent.map((entry) => ({
                key: entry.key,
                time: entry.at ? logTime(entry.at, now) : '—',
                tone: entry.tone,
                title: entry.title,
                sub: entry.sub ?? undefined,
                action: entry.href ? { label: 'Open', href: entry.href, ariaLabel: `Open alert: ${entry.title}` } : undefined,
            }))}
        />
    );
}

function HowItReaches({ route, now }: { route: GatewayRoute | undefined; now: Date }) {
    const broker = route?.broker_href ? { label: 'Open Sensor broker', href: route.broker_href } : null;
    if (!route || route.logins.length === 0) {
        return <QuietLine title="How it reaches Oblivion" text="No sensor broker login is linked to this gateway." action={broker} />;
    }
    const rows: [string, string][] = [
        ['Path', route.reaches ?? 'Not available'],
        ['Connection', route.connection.text],
    ];
    route.logins.forEach((login) => {
        const made = login.made_at ? `made ${nzDate(login.made_at)}` : null;
        rows.push(['Login', [login.username, made].filter(Boolean).join(' · ') || 'Its own login']);
        if (login.topic) rows.push(['May send to', login.topic]);
        rows.push(['Last reading', login.last_reading_at ? seenTime(login.last_reading_at, now) : 'None yet']);
    });
    return <FactRows title="How it reaches Oblivion" sub="Its own login, made by Oblivion" action={broker} rows={rows} />;
}

export function GatewayOverviewTab({
    props,
    now,
    menuFor,
    onWatch,
}: {
    props: DevicePageProps;
    now: Date;
    menuFor: (device: PopupDevice) => MenuItem[];
    onWatch: (() => void) | null;
}) {
    const menu = useEntityContextMenu<PopupDevice>();
    const overview = props.overview as GatewayOverview | null;
    if (!overview) return null;
    const gateway = overview.gateway;
    const hub = gateway?.route.hub;

    return (
        <MainSide
            main={
                <>
                    <GatewaySensors sensors={overview.sensors ?? []} now={now} menuFor={menuFor} onContextMenu={menu.open} />
                    {gateway ? (
                        <QuietLine
                            title="If this gateway goes quiet"
                            text={gateway.quiet.text}
                            action={!gateway.quiet.watched && onWatch ? { label: 'Watch it', onClick: onWatch } : null}
                        />
                    ) : null}
                    <RecentOnGateway recent={gateway?.recent} now={now} />
                </>
            }
            side={
                <>
                    {gateway && gateway.route.logins.length > 0 ? (
                        <Note icon={ShieldCheck}>
                            <b className="font-semibold">Never on the public internet.</b> This gateway sends its readings to {hub ?? 'its Site’s hub'} on the
                            house network, and the hub passes them to Oblivion. If the link to Oblivion drops, the hub keeps them for up to 24 hours and
                            sends them when it’s back.
                        </Note>
                    ) : null}
                    <HowItReaches route={gateway?.route} now={now} />
                    <FactRows title="Gateway" rows={overview.facts} />
                    {menu.ctx ? (
                        <EntityContextMenu
                            x={menu.ctx.x}
                            y={menu.ctx.y}
                            icon={iconForKind(menu.ctx.record.kind ?? 'other')}
                            title={menu.ctx.record.name}
                            items={menuFor(menu.ctx.record)}
                            onClose={menu.close}
                        />
                    ) : null}
                </>
            }
        />
    );
}
