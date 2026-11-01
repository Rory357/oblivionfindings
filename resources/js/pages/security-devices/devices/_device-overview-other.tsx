/**
 * The overview for printers (pg-sdr-10), UPSs (pg-sdr-16), video recorders (pg-sdr-21), sensor
 * gateways (pg-sdr-22) and other devices. Readings the device doesn't send say "Not available";
 * nothing is filled in for it.
 */
import { EntityContextMenu, EntityKebab, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { TrendChart, type TrendRangeKey } from '@/components/security-devices/device-blocks';
import { KindIcon, iconForKind } from '@/components/security-devices/device-kind-icon';
import type { PopupDevice } from '@/components/security-devices/device-popups';
import { FactRows, QuietLine, SectionHead, Surface } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { seenTime } from '@/lib/security-devices/device-time';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { DeviceCard } from './_device-card';
import type { DevicePageProps, GatewaySensor } from './_device-page-types';
import { MainSide } from './_device-page-parts';
import { aboutTitle, KIND_NOUN, OnlineDonut, RecentLog, ReplyTimeTrend } from './_device-overview-network';

function GatewaySensors({
    sensors,
    now,
    menuFor,
    onContextMenu,
}: {
    sensors: GatewaySensor[];
    now: Date;
    menuFor: (device: PopupDevice) => MenuItem[];
    onContextMenu: (event: MouseEvent, device: PopupDevice) => void;
}) {
    if (sensors.length === 0) {
        return <QuietLine title="Sensors on this gateway" text="No sensors are linked to this gateway yet." />;
    }
    const asDevice = (sensor: GatewaySensor): PopupDevice => ({
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
        <Surface title="Sensors on this gateway" sub="Linked to it in Oblivion · each one’s latest reading and battery">
            <div className="mt-3 overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Sensor</TableHead>
                            <TableHead>Where</TableHead>
                            <TableHead>Last reading</TableHead>
                            <TableHead>Battery</TableHead>
                            <TableHead className="w-10"><span className="sr-only">Actions</span></TableHead>
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
                                <TableCell>{sensor.where ?? '—'}</TableCell>
                                <TableCell>
                                    {sensor.state === 'cant_check' || sensor.state === 'offline' ? (
                                        <StatusBadge variant={sensor.state === 'offline' ? 'critical' : 'neutral'}>
                                            {sensor.state === 'offline' ? 'Offline' : 'Can’t check'}
                                        </StatusBadge>
                                    ) : (
                                        <span className="grid">
                                            <span>{sensor.reading ?? 'Not available'}</span>
                                            {sensor.seen_at ? <small className="text-caption">{seenTime(sensor.seen_at, now)}</small> : null}
                                        </span>
                                    )}
                                </TableCell>
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

export function OtherOverviewTab({
    props,
    now,
    onRange,
    menuFor,
}: {
    props: DevicePageProps;
    now: Date;
    onRange: (range: TrendRangeKey) => void;
    menuFor: (device: PopupDevice) => MenuItem[];
}) {
    const menu = useEntityContextMenu<PopupDevice>();
    const overview = props.overview;
    if (!overview) return null;
    const kind = props.device.kind;
    const noun = KIND_NOUN[kind] ?? 'device';
    const reading = overview.reading_trend ?? null;
    const readingBlock = reading ? (
        <TrendChart
            title={reading.title ?? 'Reading'}
            sub="From its own reports"
            start={reading.start}
            step={reading.step_seconds}
            points={reading.points}
            unit={reading.unit}
            peakWords="Highest"
        />
    ) : kind === 'printer' ? (
        <QuietLine title="Toner left" text="Not available: this printer doesn’t send its toner level or page counts to Oblivion." />
    ) : kind === 'ups' ? (
        <QuietLine title="Battery and load" text="Not available: this UPS doesn’t send its battery, load or runtime to Oblivion." />
    ) : null;

    const cameras = overview.cameras ?? [];
    const factsTitle = kind === 'recorder' ? 'For IT' : kind === 'sensor_gateway' ? 'Gateway' : aboutTitle(kind);

    return (
        <MainSide
            main={
                <>
                    {kind === 'sensor_gateway' ? <GatewaySensors sensors={overview.sensors ?? []} now={now} menuFor={menuFor} onContextMenu={menu.open} /> : null}
                    {kind === 'recorder' ? (
                        cameras.length > 0 ? (
                            <section>
                                <SectionHead title="Cameras on this recorder" sub="Each camera’s own checks over the last 24 hours" />
                                <div className="grid gap-5 [grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr))]">
                                    {cameras.map((camera) => (
                                        <DeviceCard
                                            key={camera.id}
                                            device={camera}
                                            actions={menuFor(camera)}
                                            onOpen={() => router.visit(camera.href)}
                                            onContextMenu={(event) => menu.open(event, camera)}
                                            selectable={false}
                                            selected={false}
                                            onToggle={() => undefined}
                                            now={now}
                                        />
                                    ))}
                                </div>
                            </section>
                        ) : (
                            <QuietLine title="Cameras on this recorder" text="No cameras are linked to this recorder yet." />
                        )
                    ) : null}
                    {readingBlock}
                    <ReplyTimeTrend overview={overview} range={props.range} onRange={onRange} noun={noun} />
                    {kind === 'ups' ? (
                        <RecentLog title="Power history" entries={overview.events} canSee={props.can.see_events} now={now} />
                    ) : null}
                </>
            }
            side={
                <>
                    <OnlineDonut overview={overview} />
                    {kind !== 'ups' ? <RecentLog title="Recent" entries={overview.events} canSee={props.can.see_events} now={now} /> : null}
                    <FactRows title={factsTitle} rows={overview.facts} />
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
