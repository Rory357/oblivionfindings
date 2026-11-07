/**
 * A switch's Ports tab (S&D v13, pg-sdr-3d): what's plugged into each port, its speed, the power it
 * draws, its errors and its last change, problems first, then changes newest first. Read-only:
 * Oblivion doesn't turn a port's power off and on, or restart the switch, so there's no button for
 * it, only the line saying where to do it. A column with nothing read says "Not available", never 0.
 */
import { EntityContextMenu, EntityKebab, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { FactRows, QuietLine, Surface, Tally, type TallyItem } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import { EthernetPort, ExternalLink } from 'lucide-react';
import { useState } from 'react';
import { MainSide, plural } from './_device-page-parts';
import type { PortsTab, SwitchPort } from './_device-page-types';
import { ColumnsMenu, useColumns, type ColumnChoice } from './_parts';

const NA = <span className="text-muted-foreground">Not available</span>;

const PORT_COLUMNS: ColumnChoice[] = [
    { key: 'port', label: 'Port', fixed: true },
    { key: 'plugged_in', label: 'Plugged in', fixed: true },
    { key: 'speed', label: 'Speed' },
    { key: 'poe', label: 'Power (PoE)' },
    { key: 'errors', label: 'Errors' },
    { key: 'changed', label: 'Last change' },
];

function joinPorts(ports: number[]): string {
    if (ports.length <= 1) return ports.map(String).join('');
    return `${ports.slice(0, -1).join(', ')} and ${ports[ports.length - 1]}`;
}

function watts(value: number): string {
    return `${Number.isInteger(value) ? value : value.toFixed(1)} W`;
}

function portName(port: SwitchPort): string {
    if (port.device) return port.device.name;
    if (port.label) return port.label;
    if (port.free) return 'Free';
    return port.up ? 'In use · nothing linked' : 'Nothing linked';
}

export function PortsTabView({ ports, noun, now }: { ports: PortsTab; noun: string; now: Date }) {
    const [showFree, setShowFree] = useState(false);
    const columns = useColumns(
        'sd.device.ports.columns',
        PORT_COLUMNS,
        PORT_COLUMNS.map((column) => column.key),
    );
    const showCol = (key: string) => columns.shown.includes(key);
    const menu = useEntityContextMenu<SwitchPort>();
    const { tally, source } = ports;
    const rows = showFree ? ports.rows : ports.rows.filter((port) => !port.free);
    const freeCount = ports.rows.filter((port) => port.free).length;
    const hasPortData = source.checks > 0 || source.unifi_at !== null;

    const menuFor = (port: SwitchPort): MenuItem[] =>
        port.device ? [{ label: 'Open device', icon: ExternalLink, onClick: () => router.visit(port.device!.href) }] : [];

    const sourceLine = [
        source.checks > 0 ? `From its port checks${source.every ? ` ${source.every}` : ''}${source.read_at ? ` · read ${seenTime(source.read_at, now)}` : ''}` : null,
        source.unifi_at ? `power from UniFi, read ${seenTime(source.unifi_at, now)}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    const tallyItems: TallyItem[] = [];
    if (tally.free !== null) tallyItems.push({ label: 'Free', value: String(tally.free), keep: true });
    tallyItems.push(
        tally.poe_w !== null
            ? { label: 'Power to devices', value: watts(tally.poe_w), sub: plural(tally.powering, 'device powered', 'devices powered'), keep: true }
            : { label: 'Power to devices', value: 'Not available', sub: 'Not read from this switch' },
    );
    if (tally.errors !== null) {
        tallyItems.push({ label: 'With errors', value: String(tally.errors), tone: tally.errors > 0 ? 'warning' : undefined, sub: tally.errors_first ?? undefined });
    }
    tallyItems.push({ label: 'Changed this week', value: String(tally.changed), sub: tally.changed_ports.length > 0 ? `Port${tally.changed_ports.length === 1 ? '' : 's'} ${joinPorts(tally.changed_ports)}` : undefined });

    return (
        <MainSide
            main={
                <>
                    {hasPortData ? (
                        <Tally title="Ports right now" sub={sourceLine || undefined} items={tallyItems} />
                    ) : (
                        <QuietLine
                            title="Ports right now"
                            text={`Ports aren’t read from this ${noun} yet: its port checks (SNMP) aren’t set up. What’s plugged in comes from the devices linked to its ports.`}
                        />
                    )}
                    {ports.rows.length === 0 ? (
                        <QuietLine title="Ports" text={`No port of this ${noun} is read or linked to a device yet.`} />
                    ) : (
                        <Surface title="Ports" sub="Problems and changes first · click a row to open the device · ⋮ or right-click for its menu">
                            <div className="flex flex-wrap items-center justify-between gap-2 px-[18px] pt-2">
                                <span className="text-subtle">{plural(ports.total, 'port', 'ports')} known</span>
                                <div className="flex flex-wrap items-center gap-2">
                                    {freeCount > 0 ? (
                                        <Button type="button" variant="outline" size="sm" aria-pressed={showFree} onClick={() => setShowFree((current) => !current)}>
                                            {showFree ? 'Hide free ports' : `Show free ports (${freeCount})`}
                                        </Button>
                                    ) : null}
                                    <ColumnsMenu all={PORT_COLUMNS} shown={columns.shown} onToggle={columns.toggle} />
                                </div>
                            </div>
                            <div className="mt-3 overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-16">Port</TableHead>
                                            <TableHead>Plugged in</TableHead>
                                            {showCol('speed') ? <TableHead>Speed</TableHead> : null}
                                            {showCol('poe') ? <TableHead>Power (PoE)</TableHead> : null}
                                            {showCol('errors') ? <TableHead>Errors</TableHead> : null}
                                            {showCol('changed') ? <TableHead>Last change</TableHead> : null}
                                            <TableHead className="w-10">
                                                <span className="sr-only">Actions</span>
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rows.map((port) => {
                                            const items = menuFor(port);
                                            const Icon = port.device ? iconForKind(port.device.kind) : EthernetPort;
                                            return (
                                                <TableRow
                                                    key={port.port}
                                                    className={port.device ? 'cursor-pointer' : undefined}
                                                    onClick={port.device ? () => router.visit(port.device!.href) : undefined}
                                                    onContextMenu={items.length ? (event) => menu.open(event, port) : undefined}
                                                >
                                                    <TableCell className="font-semibold tabular-nums whitespace-nowrap">{port.port}</TableCell>
                                                    <TableCell>
                                                        <span className="flex items-center gap-2.5">
                                                            <Icon aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                                                            <span className="grid min-w-0">
                                                                <b className="text-sm font-semibold">{portName(port)}</b>
                                                                {port.device ? (
                                                                    <small className="text-caption">{port.device.model}</small>
                                                                ) : port.up === false && !port.free ? (
                                                                    <small className="text-caption">Down</small>
                                                                ) : null}
                                                            </span>
                                                        </span>
                                                    </TableCell>
                                                    {showCol('speed') ? <TableCell className="whitespace-nowrap">{port.speed ?? (port.up === false ? '—' : NA)}</TableCell> : null}
                                                    {showCol('poe') ? <TableCell className="whitespace-nowrap">{port.poe_w !== null ? (port.poe_w > 0 ? watts(port.poe_w) : '—') : NA}</TableCell> : null}
                                                    {showCol('errors') ? (
                                                        <TableCell>
                                                            {port.errors !== null ? (
                                                                port.errors > 0 ? (
                                                                    <span className="grid justify-items-start gap-0.5">
                                                                        <StatusBadge variant="warning" size="sm">
                                                                            {port.errors.toLocaleString('en-NZ')} in {source.error_hours} h
                                                                        </StatusBadge>
                                                                        <small className="text-caption">Cable or plug may be damaged</small>
                                                                    </span>
                                                                ) : (
                                                                    'None'
                                                                )
                                                            ) : port.errors_total !== null ? (
                                                                <span className="grid gap-0.5">
                                                                    <span>{port.errors_total === 0 ? 'None' : port.errors_total.toLocaleString('en-NZ')}</span>
                                                                    <small className="text-caption">Since the switch started</small>
                                                                </span>
                                                            ) : (
                                                                NA
                                                            )}
                                                        </TableCell>
                                                    ) : null}
                                                    {showCol('changed') ? (
                                                        <TableCell>
                                                            {port.changed_at ? (
                                                                <span className="grid gap-0.5">
                                                                    <span className="whitespace-nowrap">{seenTime(port.changed_at, now)}</span>
                                                                    {port.change ? <small className="text-caption">{port.change}</small> : null}
                                                                </span>
                                                            ) : (
                                                                NA
                                                            )}
                                                        </TableCell>
                                                    ) : null}
                                                    <TableCell onClick={(event) => event.stopPropagation()}>
                                                        {items.length ? <EntityKebab actions={items} label={`Actions for port ${port.port}`} /> : null}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        </Surface>
                    )}
                </>
            }
            side={
                <>
                    {ports.rows.length > 0 ? (
                        <FactRows
                            title="Front panel"
                            sub={`The ${plural(ports.total, 'port', 'ports')} Oblivion knows`}
                            rows={ports.rows
                                .slice()
                                .sort((a, b) => a.port - b.port)
                                .map((port): [string, string] => [
                                    `Port ${port.port}`,
                                    [portName(port), port.problem ? 'needs a look' : null, port.poe_w && port.poe_w > 0 ? 'PoE' : null].filter(Boolean).join(' · '),
                                ])}
                        />
                    ) : null}
                    <QuietLine
                        title="Turning a port’s power off and on"
                        text={`Oblivion doesn’t do this, or restart the ${noun}. Do it in UniFi Network, after setting quiet time so nobody is alerted.`}
                    />
                    <FactRows
                        title="For IT"
                        rows={[
                            ['Read from', source.checks > 0 ? `SNMP port checks${source.every ? ` ${source.every}` : ''}, from ${source.by ?? 'the Oblivion server'}` : 'No port checks (SNMP) on it'],
                            ['Errors', source.checks > 0 ? `Receive and send errors from the port counters, over the last ${source.error_hours} hours` : 'Not counted without port checks'],
                            [
                                'Power',
                                source.unifi_at
                                    ? `UniFi’s port list${source.unifi_hub ? `, through ${source.unifi_hub}` : ''}, when Obli last checked the ports`
                                    : 'Not read: SNMP doesn’t report power per port',
                            ],
                            ['Plugged in', 'Devices linked to its ports in Oblivion'],
                        ]}
                    />
                    {menu.ctx ? (
                        <EntityContextMenu
                            x={menu.ctx.x}
                            y={menu.ctx.y}
                            icon={EthernetPort}
                            title={`Port ${menu.ctx.record.port}`}
                            items={menuFor(menu.ctx.record)}
                            onClose={menu.close}
                        />
                    ) : null}
                </>
            }
        />
    );
}
