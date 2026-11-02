/**
 * Site › Alerts (S&D drawing pg-sdr-12h): one Site's alerts, open and snoozed first, then the last
 * 30 days, with who was told and how each ended; the rules that decide who's told here, its quiet
 * times, and the rule that quiet time never pauses safety. Alerts are still acted on in Alerts.
 */
import { EntityContextMenu, EntityTable, useEntityContextMenu, type EntityTableColumn, type MenuItem } from '@/components/lists';
import { iconForKind } from '@/components/security-devices/device-kind-icon';
import { FactRows, Log, Note, QuietLine, SideBySide, SideColumn } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import { BellRing, Cpu, TriangleAlert } from 'lucide-react';
import { BlockHead, ColumnsMenu, useColumns, type ColumnChoice } from '../devices/_parts';
import type { SiteAlertRow, SiteAlerts } from './_types';

const COLUMNS: ColumnChoice[] = [
    { key: 'when', label: 'When' },
    { key: 'told', label: 'Who was told' },
    { key: 'ended', label: 'How it ended' },
];

export function AlertsTab({ alerts, siteId, siteName, now }: { alerts: SiteAlerts; siteId: number; siteName: string; now: Date }) {
    const menu = useEntityContextMenu<SiteAlertRow>();
    const columns = useColumns('sd.site.alerts.columns', COLUMNS, COLUMNS.map((column) => column.key));
    const actionsFor = (row: SiteAlertRow): MenuItem[] => [
        { label: 'Open in Alerts', icon: BellRing, onClick: () => router.visit(row.href) },
        ...(row.device ? [{ label: `Open ${row.device}`, icon: Cpu, onClick: () => router.visit(`/security-devices/devices/${row.device_id}`) }] : []),
    ];
    const all: Record<string, EntityTableColumn<SiteAlertRow>> = {
        when: { key: 'when', label: 'When', width: '150px', cell: (row) => <span className="whitespace-nowrap">{seenTime(row.at, now)}</span> },
        told: { key: 'told', label: 'Who was told', width: '1.1fr', cell: (row) => <span className="py-1 whitespace-normal">{row.told}</span> },
        ended: {
            key: 'ended',
            label: 'How it ended',
            width: '1.4fr',
            cell: (row) => (
                <span className="flex min-w-0 flex-col items-start gap-1 py-1">
                    <StatusBadge variant={row.ended.tone} size="sm">
                        {row.ended.badge}
                    </StatusBadge>
                    <span className="max-w-full text-xs whitespace-normal text-muted-foreground">{row.ended.note}</span>
                </span>
            ),
        },
    };

    return (
        <SideBySide cols="minmax(0, 1fr) 360px">
            <div className="flex flex-col gap-5">
                {alerts.rows.length === 0 ? (
                    <QuietLine title={`Alerts at ${siteName}`} text={`Nothing is open here, and nothing has been in the last ${alerts.days} days.`} />
                ) : (
                    <section className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                        <div className="border-b border-border px-4 py-3.5">
                            <BlockHead
                                title={`Alerts at ${siteName}`}
                                sub={`Open first, then the last ${alerts.days} days · click one to open it in Alerts · ⋮ or right-click for its menu`}
                                right={<ColumnsMenu all={COLUMNS} shown={columns.shown} onToggle={columns.toggle} />}
                            />
                        </div>
                        <EntityTable<SiteAlertRow>
                            className="rounded-none border-0 shadow-none"
                            rows={alerts.rows}
                            rowKey={(row) => row.id}
                            identityLabel="Alert"
                            identity={(row) => ({ icon: row.device_kind ? iconForKind(row.device_kind) : TriangleAlert, name: row.title, subline: row.sub, linkLabel: `Open ${row.title} in Alerts` })}
                            hrefFor={(row) => row.href}
                            columns={columns.shown.map((key) => all[key]).filter(Boolean)}
                            actionsFor={actionsFor}
                            onOpen={(row) => router.visit(row.href)}
                            onRowContextMenu={(event, row) => menu.open(event, row)}
                            rowHeight="content"
                            minWidth={760}
                        />
                    </section>
                )}
                <QuietLine
                    title="Older alerts"
                    text={`This tab and Alerts › Done keep the last ${alerts.days} days. Alerts lists them with your other Sites.`}
                    action={{ label: 'Open in Alerts', href: `/security-devices/alerts?site=${siteId}` }}
                />
            </div>
            <SideColumn>
                <FactRows title="Who’s told here" sub={`From the rules that cover ${siteName}`} rows={alerts.told.map((rule) => [rule.when, rule.then])} />
                {alerts.quiet.length === 0 ? (
                    <QuietLine title="Quiet times here" text="No quiet time is planned." action={{ label: 'All quiet times', href: `/security-devices/alerts?tab=quiet&site=${siteId}` }} />
                ) : (
                    <Log
                        title="Quiet times here"
                        action={{ label: 'All quiet times', href: `/security-devices/alerts?tab=quiet&site=${siteId}` }}
                        items={alerts.quiet.map((window) => ({ key: window.id, time: seenTime(window.at, now), tone: 'info' as const, title: window.title, sub: window.sub || undefined }))}
                    />
                )}
                <Note tone="warning" icon={TriangleAlert}>
                    <b>Safety alerts never pause.</b> Quiet time only quietens network gear and computers. Panic buttons, the alarm,
                    cameras, doors and sensors always get through, even in quiet time.
                </Note>
            </SideColumn>

            {menu.ctx ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={menu.ctx.record.device_kind ? iconForKind(menu.ctx.record.device_kind) : TriangleAlert}
                    title={menu.ctx.record.title}
                    items={actionsFor(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}
        </SideBySide>
    );
}
