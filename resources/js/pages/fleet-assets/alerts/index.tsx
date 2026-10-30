import { FleetPageMenu } from '@/components/fleet-assets/fleet-page-menu';
import { FleetQueueActions } from '@/components/fleet-assets/fleet-queue-actions';
import {
    QueueCriteria,
    QueueEmpty,
    QueuePagination,
    useQueueFilters,
    type PageLinks,
    type QueueFilters,
} from '@/components/fleet-assets/queue-kit';
import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { PageLayout } from '@/components/page';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import {
    countFleetAlertActions,
    fleetAlertNextAction,
    type FleetAlertAction,
} from '@/lib/fleet-alert-workflow';
import { Head, router } from '@inertiajs/react';
import {
    ArrowRight,
    Bell,
    CheckCircle2,
    ExternalLink,
    Eye,
    LayoutGrid,
    List,
    RefreshCw,
    Wrench,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    ACTION_LABELS,
    AlertDetails,
    AlertResponseDialog,
    alertTitle,
    alertTone,
    STATUS_LABELS,
    type FleetAlert,
} from './_dialogs';

type ArchivedAlert = {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    asset: { id: number; name: string } | null;
};
type Props = {
    hero: { total: number; unresolved: number };
    severity_counts: Record<string, number>;
    severity_total: number;
    control_room_alerts: {
        data: FleetAlert[];
        links: PageLinks;
        meta: {
            total: number;
            current_page: number;
            from: number | null;
            to: number | null;
        };
    };
    archived_asset_alerts: ArchivedAlert[];
    sites: Array<{ id: number; name: string }>;
    snapshot_at: string;
    filters: QueueFilters;
    can: { manage: boolean; control_room: boolean };
};
const tabs = [
    { key: 'unresolved', label: 'Unresolved', icon: Bell },
    { key: 'ack', label: 'Acknowledged', icon: Eye },
    { key: 'triaging', label: 'In triage', icon: Wrench },
    { key: 'resolved', label: 'Resolved', icon: CheckCircle2 },
    { key: 'all', label: 'All alerts', icon: List },
    { key: 'archived', label: 'Legacy history', icon: List },
];
const severities = ['critical', 'high', 'medium', 'low'];

export default function AlertsIndex({
    hero,
    severity_counts,
    severity_total,
    control_room_alerts: queue,
    archived_asset_alerts,
    sites,
    snapshot_at,
    filters,
    can,
}: Props) {
    const { url, search, onSearch, patch } = useQueueFilters(
        '/fleet-assets/alerts',
        filters,
    );
    const [detail, setDetail] = useState<FleetAlert | null>(null),
        [response, setResponse] = useState<{
            alerts: FleetAlert[];
            action: FleetAlertAction;
        } | null>(null),
        [legacy, setLegacy] = useState(false),
        [selected, setSelected] = useState<Set<string | number>>(new Set());
    const context = useEntityContextMenu<FleetAlert>();
    useEffect(() => {
        setSelected(new Set());
    }, [url]);
    const layout = filters.layout === 'cards' ? 'cards' : 'table';
    const status = String(filters.status ?? 'unresolved');
    const severity = String(filters.severity ?? 'all');
    const scope = `${sites.find((site) => String(site.id) === String(filters.site_id))?.name ?? 'All permitted sites'} · ${filters.entity === 'vehicle' ? 'Vehicles' : filters.entity === 'asset' ? 'Assets' : 'Vehicles & assets'} · ${STATUS_LABELS[status] ?? status}`;
    const criteria = [
        ...(filters.search
            ? [{ key: 'search', label: `Search: ${filters.search}` }]
            : []),
        ...(severity !== 'all'
            ? [{ key: 'severity', label: `Severity: ${alertTitle(severity)}` }]
            : []),
        ...(filters.activity
            ? [
                  {
                      key: 'activity',
                      label:
                          filters.activity === 'acknowledged_today'
                              ? 'Acknowledged today'
                              : 'Resolved in past 7 days',
                  },
              ]
            : []),
        ...(filters.asset_id
            ? [{ key: 'asset_id', label: 'Selected asset' }]
            : []),
    ];
    const clear = () =>
        patch({
            search: '',
            severity: undefined,
            activity: undefined,
            asset_id: undefined,
        });
    const openSource = (alert: FleetAlert) => {
        if (alert.asset?.href)
            router.visit(
                `${alert.asset.href}${alert.asset.href.includes('?') ? '&' : '?'}return_to=${encodeURIComponent(url)}`,
            );
    };
    const respond = (alert: FleetAlert) => {
        const action = fleetAlertNextAction(alert.status);
        if (action && can.manage && alert.can_respond) {
            setDetail(null);
            setResponse({ alerts: [alert], action });
        }
    };
    const actions = (alert: FleetAlert): MenuItem[] => [
        {
            label: 'View alert details',
            icon: Bell,
            onClick: () => setDetail(alert),
        },
        ...(alert.asset?.href
            ? [
                  {
                      label: 'Open source record',
                      icon: ArrowRight,
                      onClick: () => openSource(alert),
                  },
              ]
            : []),
        ...(can.control_room && alert.can_open_control_room
            ? [
                  {
                      label: 'View in Control Room',
                      icon: ExternalLink,
                      onClick: () =>
                          router.visit(
                              `/control-room/alerts?alert=${alert.id}`,
                          ),
                  },
              ]
            : []),
        ...(can.manage &&
        alert.can_respond &&
        fleetAlertNextAction(alert.status)
            ? [
                  {
                      label: ACTION_LABELS[fleetAlertNextAction(alert.status)!],
                      icon: CheckCircle2,
                      onClick: () => respond(alert),
                  },
              ]
            : []),
    ];
    const statusCell = (alert: FleetAlert) => (
        <div className="fleet-queue-cell">
            <StatusBadge variant={alertTone(alert.severity)}>
                {alertTitle(alert.severity)}
            </StatusBadge>
            <StatusBadge
                variant={
                    ['resolved', 'closed', 'dismissed'].includes(alert.status)
                        ? 'success'
                        : 'info'
                }
            >
                {STATUS_LABELS[alert.status] ?? alertTitle(alert.status)}
            </StatusBadge>
        </div>
    );
    const selectedRows = queue.data.filter((alert) => selected.has(alert.id));
    const counts = countFleetAlertActions(selectedRows);
    const toggle = (alert: FleetAlert, checked: boolean) =>
        setSelected((current) => {
            const next = new Set(current);
            if (checked) next.add(alert.id);
            else next.delete(alert.id);
            return next;
        });
    const cards = (
        <EntityCardGrid>
            {queue.data.map((alert) => (
                <EntityCard
                    key={alert.id}
                    icon={Bell}
                    name={alertTitle(alert.alert_type)}
                    subline={`${alert.reference} · ${alertTitle(alert.source)}`}
                    meridian={alertTone(alert.severity)}
                    onOpen={() => setDetail(alert)}
                    actions={actions(alert)}
                    onContextMenu={(event) => context.open(event, alert)}
                    chips={statusCell(alert)}
                    alerts={
                        <div className="fleet-queue-cell">
                            <strong>
                                {alert.asset?.name ?? 'Resource unavailable'}
                            </strong>
                            <span className="text-caption">
                                {alert.triggered_at
                                    ? formatDateTime(alert.triggered_at)
                                    : 'Time not recorded'}
                            </span>
                        </div>
                    }
                    footer={{
                        personName: alert.assigned_to?.name,
                        primary: alert.assigned_to?.name ?? 'Unassigned',
                        secondary: alert.site?.name ?? 'Site not recorded',
                    }}
                    selection={
                        can.manage &&
                        alert.can_respond &&
                        fleetAlertNextAction(alert.status)
                            ? {
                                  checked: selected.has(alert.id),
                                  label: `Select ${alert.reference}`,
                                  onToggle: (checked) => toggle(alert, checked),
                              }
                            : undefined
                    }
                />
            ))}
        </EntityCardGrid>
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Fleet alerts', href: '/fleet-assets/alerts' },
            ]}
        >
            <Head title="Fleet alerts" />
            <PageLayout
                hero={
                    <PageHeader
                        className="fleet-queue-header"
                        title="Fleet alerts"
                        wrapTitle
                        icon={Bell}
                        subline="Permitted Fleet-source alerts · vehicles and assets · Control Room owns responses"
                        actions={
                            <>
                                <FleetPageMenu />
                                {
                                    <>
                                        <FleetQueueActions
                                            siteId={filters.site_id}
                                        />
                                        <PageHeaderSearch
                                            value={search}
                                            onChange={onSearch}
                                            placeholder="Search alerts or resources…"
                                        />
                                        <PageHeaderGlassButton
                                            onClick={() => router.reload()}
                                        >
                                            <RefreshCw className="size-4" />
                                            Refresh
                                        </PageHeaderGlassButton>
                                    </>
                                }
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Unresolved Fleet alerts"
                                    tone="warning"
                                    onClick={() => {
                                        setLegacy(false);
                                        patch({
                                            status: 'unresolved',
                                            activity: undefined,
                                            severity: undefined,
                                            search: '',
                                        });
                                    }}
                                >
                                    <PageHeaderMeterBig>
                                        {hero.unresolved}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Of {hero.total} permitted Fleet alerts
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                {severities.map((key) => (
                                    <PageHeaderMeterBlock
                                        key={key}
                                        label={alertTitle(key)}
                                        value={severity_counts[key] ?? 0}
                                        tone={
                                            key === 'critical'
                                                ? 'critical'
                                                : key === 'high'
                                                  ? 'warning'
                                                  : 'brand'
                                        }
                                        className={`queue-severity-${key}${severity === key ? 'queue-severity-active' : ''}`}
                                        ariaLabel={`Filter ${key} alerts: ${severity_counts[key] ?? 0}${severity === key ? ', selected — click to clear' : ''}`}
                                        onClick={() => {
                                            setLegacy(false);
                                            patch({
                                                severity:
                                                    severity === key
                                                        ? undefined
                                                        : key,
                                            });
                                        }}
                                    >
                                        {severity_total ? (
                                            <PageHeaderMeterDonut
                                                percent={
                                                    ((severity_counts[key] ??
                                                        0) /
                                                        severity_total) *
                                                    100
                                                }
                                                caption={`${severity_counts[key] ?? 0} of ${severity_total} alerts`}
                                            />
                                        ) : (
                                            <PageHeaderMeterBig>
                                                0
                                            </PageHeaderMeterBig>
                                        )}
                                        <PageHeaderMeterCaption>
                                            {severity === key
                                                ? 'Selected · click to clear'
                                                : severity_total
                                                  ? `${STATUS_LABELS[status] ?? status}${filters.search ? ' · search results' : ''} · all severities`
                                                  : 'No matching alerts'}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                ))}
                            </>
                        }
                        filters={
                            <>
                                <PageHeaderFilterSelect
                                    label="All permitted sites"
                                    value={String(filters.site_id ?? 'all')}
                                    options={sites.map((site) => ({
                                        value: String(site.id),
                                        label: site.name,
                                    }))}
                                    onChange={(site) =>
                                        patch({
                                            site_id:
                                                site === 'all'
                                                    ? undefined
                                                    : site,
                                        })
                                    }
                                />
                                <PageHeaderFilterSelect
                                    label="Vehicles & assets"
                                    value={String(filters.entity ?? 'all')}
                                    options={[
                                        { value: 'vehicle', label: 'Vehicles' },
                                        { value: 'asset', label: 'Assets' },
                                    ]}
                                    onChange={(entity) => patch({ entity })}
                                />
                                <PageHeaderFilterSelect
                                    label="All severities"
                                    value={severity}
                                    options={severities.map((key) => ({
                                        value: key,
                                        label: alertTitle(key),
                                    }))}
                                    onChange={(value) =>
                                        patch({ severity: value })
                                    }
                                />
                                <PageHeaderFilterSelect
                                    label="Newest first"
                                    value={String(filters.direction ?? 'desc')}
                                    allValue="desc"
                                    options={[
                                        { value: 'asc', label: 'Oldest first' },
                                    ]}
                                    onChange={(value) =>
                                        patch({
                                            direction: value,
                                            sort: 'triggered_at',
                                        })
                                    }
                                />
                                <PageHeaderViewToggle
                                    value={layout}
                                    onChange={(value) =>
                                        patch({ layout: value })
                                    }
                                    options={[
                                        {
                                            value: 'table',
                                            label: 'Table',
                                            icon: List,
                                        },
                                        {
                                            value: 'cards',
                                            label: 'Cards',
                                            icon: LayoutGrid,
                                        },
                                    ]}
                                />
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={tabs}
                                value={legacy ? 'archived' : status}
                                onSelect={(value) => {
                                    setLegacy(value === 'archived');
                                    if (value !== 'archived')
                                        patch({
                                            status: value,
                                            activity: undefined,
                                        });
                                }}
                            />
                        }
                    />
                }
            >
                <div className="fleet-queue-stack">
                    <ListCaption
                        title={
                            legacy
                                ? 'Legacy asset-alert history'
                                : 'Fleet alert queue'
                        }
                        caption={
                            legacy
                                ? `Latest ${archived_asset_alerts.length} records · read only`
                                : `${queue.meta.from ?? 0}–${queue.meta.to ?? 0} of ${queue.meta.total} matching alerts`
                        }
                        right={
                            criteria.length ? (
                                <Button variant="ghost" onClick={clear}>
                                    Clear list filters
                                </Button>
                            ) : undefined
                        }
                    />
                    <QueueCriteria
                        scope={scope}
                        criteria={criteria}
                        onRemove={(key) => patch({ [key]: undefined })}
                    />
                    {legacy ? (
                        <Card>
                            <CardContent className="space-y-4 py-5">
                                <p className="text-subtle">
                                    Archived AssetAlert records are separate
                                    from the operational Control Room queue.
                                    This list is limited to the latest 25;
                                    operational search and resource-type filters
                                    do not apply.
                                </p>
                                {archived_asset_alerts.length ? (
                                    archived_asset_alerts.map((alert) => (
                                        <div
                                            key={alert.id}
                                            className="flex flex-wrap justify-between gap-2 border-b pb-3"
                                        >
                                            <div>
                                                <strong>
                                                    {alertTitle(
                                                        alert.alert_type,
                                                    )}
                                                </strong>
                                                <p className="text-caption">
                                                    {alert.asset?.name ??
                                                        'Resource unavailable'}{' '}
                                                    ·{' '}
                                                    {alert.triggered_at
                                                        ? formatDateTime(
                                                              alert.triggered_at,
                                                          )
                                                        : 'Time not recorded'}
                                                </p>
                                            </div>
                                            <StatusBadge variant="neutral">
                                                {alertTitle(alert.status)}
                                            </StatusBadge>
                                        </div>
                                    ))
                                ) : (
                                    <p>No legacy records in this scope.</p>
                                )}
                            </CardContent>
                        </Card>
                    ) : (
                        <>
                            {selectedRows.length > 0 && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <span>
                                        {selectedRows.length} selected on this
                                        page
                                    </span>
                                    {(
                                        [
                                            'acknowledge',
                                            'triage',
                                            'resolve',
                                        ] as const
                                    ).map(
                                        (action) =>
                                            counts[action] > 0 && (
                                                <Button
                                                    key={action}
                                                    variant="outline"
                                                    onClick={() =>
                                                        setResponse({
                                                            alerts: selectedRows.filter(
                                                                (alert) =>
                                                                    fleetAlertNextAction(
                                                                        alert.status,
                                                                    ) ===
                                                                    action,
                                                            ),
                                                            action,
                                                        })
                                                    }
                                                >
                                                    {ACTION_LABELS[action]} (
                                                    {counts[action]})
                                                </Button>
                                            ),
                                    )}
                                    <Button
                                        variant="ghost"
                                        onClick={() => setSelected(new Set())}
                                    >
                                        Clear selection
                                    </Button>
                                </div>
                            )}
                            {!queue.data.length ? (
                                <QueueEmpty
                                    title="No matching alerts"
                                    hasCriteria={criteria.length > 0}
                                    onClear={
                                        criteria.length
                                            ? clear
                                            : () => patch({ status: 'all' })
                                    }
                                />
                            ) : (
                                <>
                                    {layout === 'table' && (
                                        <div className="hidden md:block">
                                            <EntityTable
                                                rowHeight="content"
                                                rows={queue.data}
                                                rowKey={(alert) => alert.id}
                                                identityLabel="Alert / source"
                                                identity={(alert) => ({
                                                    icon: Bell,
                                                    name: alertTitle(
                                                        alert.alert_type,
                                                    ),
                                                    subline: `${alert.reference} · ${alertTitle(alert.source)}`,
                                                })}
                                                columns={[
                                                    {
                                                        key: 'resource',
                                                        label: 'Resource',
                                                        width: '1.2fr',
                                                        cell: (alert) => (
                                                            <div className="fleet-queue-cell">
                                                                {alert.asset
                                                                    ?.href ? (
                                                                    <Button
                                                                        variant="link"
                                                                        className="fleet-queue-link h-auto p-0 text-left whitespace-normal"
                                                                        onClick={(
                                                                            event,
                                                                        ) => {
                                                                            event.stopPropagation();
                                                                            openSource(
                                                                                alert,
                                                                            );
                                                                        }}
                                                                    >
                                                                        {
                                                                            alert
                                                                                .asset
                                                                                .name
                                                                        }
                                                                    </Button>
                                                                ) : (
                                                                    <span>
                                                                        {alert
                                                                            .asset
                                                                            ?.name ??
                                                                            'Resource unavailable'}
                                                                    </span>
                                                                )}
                                                                <span className="text-caption">
                                                                    {
                                                                        alert
                                                                            .asset
                                                                            ?.asset_tag
                                                                    }{' '}
                                                                    ·{' '}
                                                                    {alert.site
                                                                        ?.name ??
                                                                        'Site not recorded'}
                                                                </span>
                                                            </div>
                                                        ),
                                                    },
                                                    {
                                                        key: 'state',
                                                        label: 'Severity / response',
                                                        width: '1fr',
                                                        cell: statusCell,
                                                    },
                                                    {
                                                        key: 'time',
                                                        label: 'Triggered / updated',
                                                        width: '1.25fr',
                                                        cell: (alert) => (
                                                            <div className="fleet-queue-cell">
                                                                <span>
                                                                    {alert.triggered_at
                                                                        ? formatDateTime(
                                                                              alert.triggered_at,
                                                                          )
                                                                        : 'Time not recorded'}
                                                                </span>
                                                                <span className="text-caption">
                                                                    {alert.updated_at
                                                                        ? `Updated ${formatDateTime(alert.updated_at)}`
                                                                        : 'Freshness not recorded'}
                                                                </span>
                                                            </div>
                                                        ),
                                                    },
                                                    {
                                                        key: 'owner',
                                                        label: 'Owner / next action',
                                                        width: '1.15fr',
                                                        cell: (alert) => (
                                                            <div className="fleet-queue-cell">
                                                                <span>
                                                                    {alert
                                                                        .assigned_to
                                                                        ?.name ??
                                                                        'Unassigned'}
                                                                </span>
                                                                {can.manage &&
                                                                    alert.can_respond &&
                                                                    fleetAlertNextAction(
                                                                        alert.status,
                                                                    ) && (
                                                                        <Button
                                                                            variant="link"
                                                                            className="fleet-queue-link h-auto p-0 text-left whitespace-normal"
                                                                            onClick={(
                                                                                event,
                                                                            ) => {
                                                                                event.stopPropagation();
                                                                                respond(
                                                                                    alert,
                                                                                );
                                                                            }}
                                                                        >
                                                                            {
                                                                                ACTION_LABELS[
                                                                                    fleetAlertNextAction(
                                                                                        alert.status,
                                                                                    )!
                                                                                ]
                                                                            }{' '}
                                                                            →
                                                                        </Button>
                                                                    )}
                                                            </div>
                                                        ),
                                                    },
                                                ]}
                                                onOpen={setDetail}
                                                actionsFor={actions}
                                                onRowContextMenu={context.open}
                                                selection={
                                                    can.manage
                                                        ? {
                                                              keys: selected,
                                                              onToggle: toggle,
                                                              labelFor: (
                                                                  alert,
                                                              ) =>
                                                                  `Select ${alert.reference}`,
                                                              canSelect: (
                                                                  alert,
                                                              ) =>
                                                                  !!fleetAlertNextAction(
                                                                      alert.status,
                                                                  ),
                                                          }
                                                        : undefined
                                                }
                                            />
                                        </div>
                                    )}
                                    <div
                                        className={
                                            layout === 'table'
                                                ? 'md:hidden'
                                                : ''
                                        }
                                    >
                                        {cards}
                                    </div>
                                    <QueuePagination links={queue.links} />
                                </>
                            )}
                            <p className="text-caption">
                                Snapshot {formatDateTime(snapshot_at)} · header
                                unresolved count uses the selected site/resource
                                scope. Severity instruments also apply status
                                and search; pagination never changes totals.
                            </p>
                        </>
                    )}
                </div>
            </PageLayout>
            {context.ctx && (
                <EntityContextMenu
                    {...context.ctx}
                    title={context.ctx.record.reference}
                    icon={Bell}
                    items={actions(context.ctx.record)}
                    onClose={context.close}
                />
            )}{' '}
            {detail && (
                <AlertDetails
                    alert={detail}
                    canManage={can.manage && detail.can_respond}
                    canControlRoom={can.control_room}
                    onClose={() => setDetail(null)}
                    onRespond={() => respond(detail)}
                    onSource={() => openSource(detail)}
                />
            )}{' '}
            {response && (
                <AlertResponseDialog
                    {...response}
                    onClose={() => setResponse(null)}
                    onSaved={() => {
                        setSelected(new Set());
                        router.reload();
                    }}
                    onRefresh={() => router.reload()}
                />
            )}
        </AppLayout>
    );
}
