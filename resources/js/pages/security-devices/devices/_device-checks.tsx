/**
 * The Checks & alerts tab every new device page shares (drawing pg-sdr-3c): each check in plain
 * words, any quiet time, who's told, what it depends on, its alerts in the last 90 days and, for
 * network gear, its settings backups. Setting quiet time and alert rules comes with Phase D.
 */
import { EntityContextMenu, EntityKebab, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { NextStep } from '@/components/security-devices/device-blocks';
import { FactRows, KnowList, Log, QuietLine, Surface } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { seenTime, timedText } from '@/lib/security-devices/device-time';
import {
    Activity,
    Cloud,
    EthernetPort,
    FileDiff,
    Gauge,
    Globe,
    HardDriveDownload,
    ListChecks,
    Monitor,
    Plug,
    Save,
    Search,
    Server,
    ShieldCheck,
    type LucideIcon,
} from 'lucide-react';

/** Icons tell the kinds of check apart (page kit block rule 6). */
const CHECK_ICON: Record<string, LucideIcon> = {
    icmp: Activity,
    tcp: Plug,
    http: Globe,
    tls: ShieldCheck,
    dns: Search,
    snmp: Gauge,
    snmp_interface: EthernetPort,
    ssh_inventory: Save,
    winrm_inventory: Monitor,
    provider: Cloud,
    collector: Server,
};

function CheckIcon({ kind }: { kind: string }) {
    const Icon = CHECK_ICON[kind] ?? ListChecks;
    return <Icon aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />;
}
import type { CheckRow, ChecksTab, DevicePageProps } from './_device-page-types';
import { logItems, MainSide, plural } from './_device-page-parts';

export function ChecksTabView({
    props,
    checks,
    now,
    onPing,
    onBackup,
    onCompare,
    onWatch,
}: {
    props: DevicePageProps;
    checks: ChecksTab;
    now: Date;
    onPing: () => void;
    onBackup: () => void;
    onCompare: (snapshotId: number) => void;
    onWatch: (() => void) | null;
}) {
    const menu = useEntityContextMenu<CheckRow>();
    const noun = checks.noun;
    const latestCompare = checks.backups.find((copy) => copy.compare && (copy.changes ?? 0) > 0);

    const menuFor = (row: CheckRow): MenuItem[] => {
        const items: MenuItem[] = [];
        if (row.kind === 'icmp' && props.actions.ping) items.push({ label: 'Ping now', icon: Activity, onClick: onPing });
        if (row.kind === 'ssh_inventory' && props.actions.backup) {
            items.push({ label: 'Back up settings now', icon: HardDriveDownload, onClick: onBackup });
            if (latestCompare) items.push({ label: 'See the last change', icon: FileDiff, onClick: () => onCompare(latestCompare.id) });
        }
        return items;
    };

    const quiet = checks.quiet_time;
    const depends = checks.depends;
    const knowItems = [
        ...(depends.upstream.length > 0
            ? [
                  { tone: 'info' as const, text: <><b className="font-semibold">Depends on</b> {depends.upstream.join(', ')}</> },
                  { tone: 'none' as const, text: <>If {depends.upstream.length === 1 ? 'it' : 'one of them'} goes offline, this {noun} shows <b className="font-semibold">Can’t check</b> in grey, not a second alert</> },
              ]
            : []),
        ...(depends.downstream > 0
            ? [{ tone: 'info' as const, text: <><b className="font-semibold">{plural(depends.downstream, 'device depends', 'devices depend')} on it.</b> If it goes offline, they go grey and you get one alert</> }]
            : []),
    ];

    return (
        <MainSide
            main={
                <>
                    {checks.rows.length === 0 ? (
                        <QuietLine
                            title={`Checks on this ${noun}`}
                            text={`Nothing is checked on this ${noun} yet, so nobody is told if it stops working.`}
                            action={onWatch ? { label: 'Watch it', onClick: onWatch } : null}
                        />
                    ) : (
                        <Surface title={`Checks on this ${noun}`} sub="What Oblivion checks, how, and when it alerts">
                            <div className="mt-3 overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Check</TableHead>
                                            <TableHead>How</TableHead>
                                            <TableHead>How often</TableHead>
                                            <TableHead>Last result</TableHead>
                                            <TableHead>Alert when</TableHead>
                                            <TableHead className="w-10"><span className="sr-only">Actions</span></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {checks.rows.map((row) => {
                                            const items = menuFor(row);
                                            return (
                                                <TableRow key={row.id} onContextMenu={items.length ? (event) => menu.open(event, row) : undefined}>
                                                    <TableCell>
                                                        <span className="flex items-center gap-2.5">
                                                            <CheckIcon kind={row.kind} />
                                                            <span className="grid min-w-0">
                                                                <b className="text-sm font-semibold">{row.title}</b>
                                                                <small className="text-caption">{row.question}</small>
                                                            </span>
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>{row.how}</TableCell>
                                                    <TableCell className="whitespace-nowrap">{row.every}</TableCell>
                                                    <TableCell className="whitespace-nowrap">
                                                        <span className="grid justify-items-start gap-0.5">
                                                            <StatusBadge variant={row.result.tone}>{row.result.label}</StatusBadge>
                                                            {row.result.at ? <small className="text-caption">{timedText({ text: '{ago}', at: row.result.at }, now)}</small> : null}
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>{row.alert_when}</TableCell>
                                                    <TableCell>{items.length ? <EntityKebab actions={items} label={`Actions for ${row.title}`} /> : null}</TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        </Surface>
                    )}
                    {quiet ? (
                        <NextStep
                            label={`Quiet time · ${quiet.starts_at ? seenTime(quiet.starts_at, now) : '—'} – ${quiet.ends_at ? seenTime(quiet.ends_at, now) : '—'}${quiet.now ? ' · now' : ''}`}
                            title={quiet.whole_site ? `Alerts at ${props.device.site ?? 'this Site'} pause for ${quiet.name}` : `Alerts for this ${noun} pause for ${quiet.name}`}
                            sub={[quiet.by ? `Set by ${quiet.by}.` : null, quiet.reason].filter(Boolean).join(' ') || undefined}
                        />
                    ) : null}
                    {props.actions.backup || checks.backups.length > 0 ? (
                        checks.backups.length > 0 ? (
                            <Log
                                title="Settings backups"
                                sub="Copies kept at this Site, newest first"
                                action={props.actions.backup ? { label: 'Back up now', onClick: onBackup } : null}
                                limit={12}
                                items={logItems(
                                    checks.backups.map((copy) => ({
                                        key: `copy-${copy.id}`,
                                        at: copy.at,
                                        tone: (copy.changes ?? 0) > 0 ? 'info' : 'success',
                                        title: copy.first ? 'First copy kept' : (copy.changes ?? 0) > 0 ? `${plural(copy.changes ?? 0, 'line', 'lines')} changed` : 'No changes',
                                        sub: copy.first ? null : 'Compared with the copy before',
                                        compare_id: copy.compare && (copy.changes ?? 0) > 0 ? copy.id : null,
                                    })),
                                    now,
                                    (entry) => (entry.compare_id ? { label: 'Compare', onClick: () => onCompare(entry.compare_id as number) } : undefined),
                                )}
                            />
                        ) : (
                            <QuietLine title="Settings backups" text="No copy of its settings is kept yet." action={{ label: 'Back up now', onClick: onBackup }} />
                        )
                    ) : null}
                </>
            }
            side={
                <>
                    {checks.told ? (
                        <FactRows title="Who’s told" sub={checks.told.sub} rows={checks.told.rows} />
                    ) : (
                        <QuietLine title="Who’s told" text="Put it at a Site so an alert rule covers it." />
                    )}
                    {knowItems.length > 0 ? (
                        <KnowList title="What it depends on" items={knowItems} />
                    ) : (
                        <QuietLine title="What it depends on" text="No dependencies are set for it." />
                    )}
                    {checks.alerts.length > 0 ? (
                        <Log title="Alerts in the last 90 days" items={logItems(checks.alerts, now)} />
                    ) : (
                        <QuietLine title="Alerts in the last 90 days" text="No alerts in the last 90 days." />
                    )}
                    {menu.ctx ? (
                        <EntityContextMenu
                            x={menu.ctx.x}
                            y={menu.ctx.y}
                            icon={ListChecks}
                            title={menu.ctx.record.title}
                            items={menuFor(menu.ctx.record)}
                            onClose={menu.close}
                        />
                    ) : null}
                </>
            }
        />
    );
}
