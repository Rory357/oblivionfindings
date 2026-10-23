import {
    alertReference,
    isActiveAlert,
    plainLabel,
    severityBadge,
    statusBadge,
} from '@/components/control-room/alert-record/alert-labels';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import {
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
} from '@/components/page';
import { PopupCallout, PopupFacts } from '@/components/popup';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { ArrowUpRight, Bell, Copy, Cpu, Radio, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';

interface SignalItem {
    id: number;
    signal_type_code: string | null;
    severity_hint: string | null;
    occurred_at: string | null;
    status: string | null;
    outcome: {
        label: string;
        tone: 'success' | 'critical' | 'warning' | 'muted';
        alert_reference: string | null;
        href: string | null;
    };
}

interface AlertItem {
    id: number;
    reference_number: string | null;
    alert_type: string;
    severity: string;
    status: string;
    health_status: string | null;
    triggered_at: string | null;
}

interface DeviceDetail {
    id: number;
    name: string | null;
    device_uid: string;
    type: string;
    type_label: string;
    vendor: string | null;
    model: string | null;
    reported_battery_level: number | null;
    last_signal_at: string | null;
    signal_activity: {
        state: 'recent' | 'quiet' | 'never';
        label: string;
        tone: 'success' | 'muted';
    };
    latitude: number | null;
    longitude: number | null;
    location_description: string | null;
    identity_source: 'canonical' | 'signal_projection';
    canonical: {
        id: number;
        domain: string;
        category: string;
        subcategory: string | null;
        status: string | null;
        health_status: string | null;
        battery_level: number | null;
        last_seen_at: string | null;
        detail_url: string;
    } | null;
    signal_source: {
        id: number;
        name: string;
        status: string;
        vendor: string | null;
    } | null;
    site: { id: number; name: string } | null;
    client: { id: number; name: string } | null;
    asset: { id: number; name: string; asset_tag: string | null } | null;
}

interface Props {
    device: DeviceDetail;
    signals: SignalItem[];
    alerts: AlertItem[];
}

const OUTCOME_TONE: Record<SignalItem['outcome']['tone'], StatusVariant> = {
    success: 'success',
    critical: 'critical',
    warning: 'warning',
    muted: 'neutral',
};

function sentenceCase(label: string): string {
    return label.charAt(0).toUpperCase() + label.slice(1).toLowerCase();
}

/** The signal source's facts — a fact with no value is left out, never "None". */
function sourceFacts(
    device: DeviceDetail,
    battery: number | null,
): Array<readonly [string, ReactNode]> {
    const rows: Array<readonly [string, ReactNode] | null> = [
        device.client ? ['Person', device.client.name] : null,
        device.site ? ['Site', device.site.name] : null,
        device.asset
            ? [
                  'Asset',
                  `${device.asset.name}${device.asset.asset_tag ? ` (${device.asset.asset_tag})` : ''}`,
              ]
            : null,
        device.last_signal_at
            ? [
                  'Last signal',
                  `${formatRelative(device.last_signal_at)} · ${formatDateTime(device.last_signal_at)}`,
              ]
            : null,
        battery !== null
            ? [
                  device.canonical ? 'Battery (Security & Devices)' : 'Battery (signal report)',
                  `${battery}%`,
              ]
            : null,
        device.signal_source
            ? [
                  'Connected through',
                  `${device.signal_source.name} · ${plainLabel(device.signal_source.status)}`,
              ]
            : null,
        [
            'Source ID',
            <code key="uid" className="font-mono text-xs">
                {device.device_uid}
            </code>,
        ],
    ];
    return rows.filter((row): row is readonly [string, ReactNode] => row !== null);
}

function scrollToSection(id: string) {
    document.getElementById(id)?.scrollIntoView({ block: 'start' });
}

function Panel({
    id,
    title,
    description,
    count,
    children,
}: {
    id?: string;
    title: string;
    description?: string;
    count?: number;
    children: ReactNode;
}) {
    return (
        <Card id={id} className="min-w-0 scroll-mt-5 gap-0 overflow-hidden py-0">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div className="min-w-0">
                    <h2 className="text-section-title">{title}</h2>
                    {description ? <p className="text-subtle mt-0.5">{description}</p> : null}
                </div>
                {count !== undefined ? (
                    <StatusBadge variant="neutral" className="tabular-nums">
                        {count}
                    </StatusBadge>
                ) : null}
            </div>
            {children}
        </Card>
    );
}

/**
 * Monitoring › one signal source — the signals Control Room received from it
 * and the alerts they raised. Identity, assignment, monitoring and management
 * stay with Security & Devices (its record is one click away); raw provider
 * payloads never show here.
 */
export default function DeviceShow({ device, signals, alerts }: Props) {
    const name = device.name || device.device_uid;
    const maker = [device.vendor, device.model].filter(Boolean).join(' ');
    const canonical = device.canonical;
    const battery = canonical ? canonical.battery_level : device.reported_battery_level;
    const openAlerts = alerts.filter((alert) => isActiveAlert(alert.status)).length;
    const latestAlert = alerts[0] ?? null;

    return (
        <ControlRoomPage
            headTitle={`Signal source ${name}`}
            recordCrumb={{ title: name }}
            header={{
                variant: 'profile',
                backHref: '/control-room/devices',
                icon: Cpu,
                title: name,
                wrapTitle: true,
                titleChip: (
                    <PageHeaderStatusChip
                        variant={device.signal_activity.tone === 'success' ? 'success' : 'neutral'}
                    >
                        {device.signal_activity.label}
                    </PageHeaderStatusChip>
                ),
                subline: [
                    sentenceCase(device.type_label),
                    maker || null,
                    device.last_signal_at
                        ? `Last signal ${formatRelative(device.last_signal_at)}`
                        : 'No signal recorded',
                ]
                    .filter(Boolean)
                    .join(' · '),
                actions: (
                    <>
                        {canonical ? (
                            <PageHeaderGlassButton icon={ShieldCheck} asChild>
                                <Link href={canonical.detail_url}>
                                    Open in Security &amp; Devices
                                </Link>
                            </PageHeaderGlassButton>
                        ) : null}
                        {latestAlert ? (
                            <PageHeaderPrimaryButton icon={Bell} asChild>
                                <Link href={`/control-room/alerts/${latestAlert.id}`}>
                                    Open linked alert
                                </Link>
                            </PageHeaderPrimaryButton>
                        ) : null}
                    </>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Recent signals"
                            onClick={() => scrollToSection('signals')}
                            ariaLabel="Go to the recent signals"
                        >
                            <PageHeaderMeterBig>{signals.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>latest 50 shown here</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Linked alerts"
                            tone={openAlerts > 0 ? 'warning' : 'brand'}
                            onClick={() => scrollToSection('alerts')}
                            ariaLabel="Go to the linked alerts"
                        >
                            <PageHeaderMeterBig>{alerts.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {openAlerts > 0 ? `${openAlerts} still open` : 'none open'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        {battery !== null ? (
                            <PageHeaderMeterBlock
                                label="Battery"
                                tone={battery <= 20 ? 'warning' : 'brand'}
                                onClick={() => scrollToSection('source')}
                                ariaLabel="Go to the signal source details"
                            >
                                <PageHeaderMeterBig>{battery}%</PageHeaderMeterBig>
                                <PageHeaderMeterBar percent={battery} />
                                <PageHeaderMeterCaption>
                                    {canonical ? 'from Security & Devices' : 'from the signal report'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        ) : null}
                    </>
                ),
            }}
            rail={null}
        >
            <div className="grid min-w-0 items-start gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                <div className="flex min-w-0 flex-col gap-5">
                    <PopupCallout tone={canonical ? 'success' : 'warning'}>
                        <p className="font-semibold">
                            {canonical
                                ? 'Matched to a device record in Security & Devices'
                                : 'Signal only — no device record'}
                        </p>
                        <p className="mt-0.5">
                            {canonical
                                ? [
                                      'Identity, assignment, monitoring and management stay with Security & Devices.',
                                      canonical.status && canonical.health_status
                                          ? `Device status is ${plainLabel(canonical.status).toLowerCase()} and health is ${plainLabel(canonical.health_status).toLowerCase()}.`
                                          : null,
                                      'This page shows the signals Control Room received.',
                                  ]
                                      .filter(Boolean)
                                      .join(' ')
                                : 'No authorised device record is linked. Reconcile this signal source in Security & Devices before treating it as managed inventory.'}
                        </p>
                    </PopupCallout>

                    <Panel
                        id="signals"
                        title="Recent signals"
                        description="What this source reported and what Control Room did with it."
                        count={signals.length}
                    >
                        {signals.length === 0 ? (
                            <div className="px-5 py-10">
                                <EmptyState
                                    icon={Radio}
                                    title="No signals to show"
                                    description="No signals are recorded for this source, or none you can see."
                                />
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-5">Signal</TableHead>
                                        <TableHead>Severity</TableHead>
                                        <TableHead>Received</TableHead>
                                        <TableHead className="pr-5">Outcome</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {signals.map((signal) => (
                                        <TableRow key={signal.id}>
                                            <TableCell className="pl-5 font-medium">
                                                {signal.signal_type_code
                                                    ? plainLabel(signal.signal_type_code)
                                                    : 'Other signal'}
                                            </TableCell>
                                            <TableCell>
                                                {signal.severity_hint ? (
                                                    <StatusBadge
                                                        variant={severityBadge(signal.severity_hint).tone}
                                                        size="sm"
                                                    >
                                                        {severityBadge(signal.severity_hint).label}
                                                    </StatusBadge>
                                                ) : null}
                                            </TableCell>
                                            <TableCell>
                                                {signal.occurred_at ? (
                                                    <>
                                                        <span className="block">
                                                            {formatRelative(signal.occurred_at)}
                                                        </span>
                                                        <span className="text-caption block tabular-nums">
                                                            {formatDateTime(signal.occurred_at)}
                                                        </span>
                                                    </>
                                                ) : null}
                                            </TableCell>
                                            <TableCell className="pr-5">
                                                <span className="flex flex-wrap items-center gap-2">
                                                    <StatusBadge
                                                        variant={OUTCOME_TONE[signal.outcome.tone]}
                                                        size="sm"
                                                    >
                                                        {signal.outcome.label}
                                                    </StatusBadge>
                                                    {signal.outcome.href ? (
                                                        <Link
                                                            href={signal.outcome.href}
                                                            className="text-sm font-medium text-primary underline-offset-2 hover:underline"
                                                        >
                                                            {signal.outcome.alert_reference ?? 'Open alert'}
                                                        </Link>
                                                    ) : null}
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        <p className="text-caption border-t border-border px-5 py-3">
                            Raw provider payloads are kept out of this view. Use the linked alert
                            or the device record in Security &amp; Devices for evidence.
                        </p>
                    </Panel>

                    <Panel
                        id="alerts"
                        title="Linked alerts"
                        description="Alerts raised from this source, newest first (latest 20)."
                        count={alerts.length}
                    >
                        {alerts.length === 0 ? (
                            <div className="px-5 py-10">
                                <EmptyState
                                    icon={Bell}
                                    title="No linked alerts"
                                    description="No alerts you can see were raised from this source."
                                />
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="pl-5">Alert</TableHead>
                                        <TableHead>Severity</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Raised</TableHead>
                                        <TableHead className="w-12 pr-5">
                                            <span className="sr-only">Actions</span>
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {alerts.map((alert) => (
                                        <LinkedAlertRow key={alert.id} alert={alert} />
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </Panel>
                </div>

                <div className="flex min-w-0 flex-col gap-5">
                    <Panel id="source" title="Signal source">
                        <div className="px-5 py-4">
                            <PopupFacts rows={sourceFacts(device, battery)} />
                        </div>
                    </Panel>

                    {device.location_description || (device.latitude && device.longitude) ? (
                        <Panel title="Location">
                            <div className="px-5 py-4">
                                <PopupFacts
                                    rows={[
                                        ...(device.location_description
                                            ? ([['Location', device.location_description]] as const)
                                            : []),
                                        ...(device.latitude && device.longitude
                                            ? ([
                                                  [
                                                      'Last reported position',
                                                      `${device.latitude.toFixed(6)}, ${device.longitude.toFixed(6)}`,
                                                  ],
                                              ] as const)
                                            : []),
                                    ]}
                                />
                            </div>
                        </Panel>
                    ) : null}
                </div>
            </div>
        </ControlRoomPage>
    );
}

function LinkedAlertRow({ alert }: { alert: AlertItem }) {
    const href = `/control-room/alerts/${alert.id}`;
    const reference = alertReference(alert);
    const severity = severityBadge(alert.severity);
    const status = statusBadge(alert.status);
    const items: ControlRoomRowAction[] = [
        {
            key: 'open',
            label: 'Open alert',
            icon: ArrowUpRight,
            onSelect: () => router.visit(href),
        },
        {
            key: 'copy',
            label: 'Copy alert reference',
            icon: Copy,
            onSelect: () => void navigator.clipboard?.writeText(reference),
        },
    ];

    return (
        <ControlRoomRowActions label={`Actions for ${reference}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <TableRow
                    onContextMenu={rowProps.onContextMenu}
                    onClick={(event) => {
                        if ((event.target as HTMLElement).closest('a,button')) return;
                        router.visit(href);
                    }}
                    className="cursor-pointer"
                >
                    <TableCell className="pl-5 whitespace-normal">
                        <Link
                            href={href}
                            className="font-semibold text-foreground underline-offset-2 hover:underline focus-visible:rounded-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {plainLabel(alert.alert_type)}
                        </Link>
                        <span className="text-caption block">{reference}</span>
                    </TableCell>
                    <TableCell>
                        <StatusBadge variant={severity.tone} size="sm">
                            {severity.label}
                        </StatusBadge>
                    </TableCell>
                    <TableCell>
                        <StatusBadge variant={status.tone} size="sm">
                            {status.label}
                        </StatusBadge>
                    </TableCell>
                    <TableCell>
                        {alert.triggered_at ? (
                            <>
                                <span className="block">{formatRelative(alert.triggered_at)}</span>
                                <span className="text-caption block tabular-nums">
                                    {formatDateTime(alert.triggered_at)}
                                </span>
                            </>
                        ) : null}
                    </TableCell>
                    <TableCell className="pr-5 text-right">{overflowButton}</TableCell>
                </TableRow>
            )}
        </ControlRoomRowActions>
    );
}
