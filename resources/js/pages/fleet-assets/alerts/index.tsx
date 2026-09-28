import { ConfirmDialog } from '@/components/confirm-dialog';
import { FleetEmptyState } from '@/components/fleet-empty-state';
import PageShell from '@/components/page-shell';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import {
    countFleetAlertActions,
    fleetAlertNextAction,
    isFleetAlertActionEligible,
    type FleetAlertAction,
} from '@/lib/fleet-alert-workflow';
import { formatDateTime } from '@/lib/fleet-utils';
import { FleetResponsiveTable } from '@/pages/fleet-assets/components/fleet-responsive-list';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    CheckCircle,
    ChevronDown,
    ChevronsUpDown,
    ChevronUp,
    ExternalLink,
    Eye,
    X,
} from 'lucide-react';
import { useCallback, useState } from 'react';

type ControlRoomAlert = {
    id: number;
    source: 'control_room';
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
    context: unknown;
    notes: string | null;
    asset: { id: number; name: string; asset_tag?: string } | null;
    assigned_to: { id: number; name: string } | null;
};
type AssetAlert = {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    acknowledged_at: string | null;
    resolved_at: string | null;
    context: unknown;
    asset: { id: number; name: string; asset_tag?: string } | null;
    tracker: { id: number; vendor: string; device_uid: string } | null;
};

type Props = {
    hero?: {
        unresolved: number;
        critical: number;
        acknowledged_today: number;
        resolved_7d: number;
    };
    control_room_alerts: {
        data: ControlRoomAlert[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
        meta?: { current_page: number; last_page: number; total: number };
    };
    archived_asset_alerts: AssetAlert[];
    filters: {
        severity?: string;
        status?: string;
        asset_id?: string;
        activity?: string;
        sort?: string;
        direction?: 'asc' | 'desc';
    };
    can: {
        manage: boolean;
        controlRoomView?: boolean;
    };
};

const SEVERITY_BORDER: Record<string, string> = {
    critical: 'border-l-4 border-l-red-600',
    high: 'border-l-4 border-l-orange-500',
    medium: 'border-l-4 border-l-yellow-500',
    low: 'border-l-4 border-l-blue-400',
};

const ACTIVE_ALERT_STATUSES = new Set(['open', 'ack', 'triaging', 'confirmed']);

const STATUS_LABELS: Record<string, string> = {
    open: 'Open',
    ack: 'Acknowledged',
    triaging: 'In triage',
    confirmed: 'Confirmed',
    resolved: 'Resolved',
    closed: 'Closed',
    dismissed: 'Dismissed',
};

function severityVariant(
    severity: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (severity) {
        case 'critical':
            return 'destructive';
        case 'high':
            return 'destructive';
        case 'medium':
            return 'default';
        case 'low':
            return 'secondary';
        default:
            return 'outline';
    }
}

const resolveAlertSteps = [
    {
        key: 'resolution',
        label: 'Resolution',
        blurb: 'Record what resolved the alert',
        icon: AlertTriangle,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm before closing',
        icon: CheckCircle,
    },
] as const;

export function ResolveAlertWizard({
    open,
    notes,
    onNotesChange,
    onClose,
    onSubmit,
}: {
    open: boolean;
    notes: string;
    onNotesChange: (notes: string) => void;
    onClose: () => void;
    onSubmit: () => void;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const hasNotes = notes.trim().length > 0;

    const close = () => {
        setStepIndex(0);
        onClose();
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Resolve alert"
            description="Add resolution notes and review them before closing the active alert workflow."
            railIcon={AlertTriangle}
            railTitle="Resolve alert"
            railSub="Fleet operations"
            steps={resolveAlertSteps}
            stepIndex={stepIndex}
            onStepClick={(index) => {
                if (index === 0 || hasNotes) setStepIndex(index);
            }}
            footerStart={
                <Button type="button" variant="outline" onClick={close}>
                    Cancel
                </Button>
            }
            footerEnd={
                stepIndex === 0 ? (
                    <Button
                        type="button"
                        disabled={!hasNotes}
                        onClick={() => setStepIndex(1)}
                    >
                        Continue
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStepIndex(0)}
                        >
                            Back
                        </Button>
                        <Button type="button" onClick={onSubmit}>
                            Resolve alert
                        </Button>
                    </>
                )
            }
        >
            {stepIndex === 0 ? (
                <WizardStepPane>
                    <div className="space-y-2">
                        <label
                            htmlFor="resolution-notes"
                            className="text-sm font-medium"
                        >
                            Resolution notes
                        </label>
                        <Textarea
                            id="resolution-notes"
                            value={notes}
                            onChange={(event) =>
                                onNotesChange(event.target.value)
                            }
                            rows={8}
                            required
                        />
                        <p className="text-sm text-muted-foreground">
                            Explain what happened, what was checked, and why the
                            alert can be closed.
                        </p>
                    </div>
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    {/* eslint-disable-next-line no-restricted-syntax -- Custom wizard review surface, not a standalone content card. */}
                    <div className="space-y-3 rounded-xl border border-border bg-card/70 p-4">
                        <h3 className="font-semibold">Resolution notes</h3>
                        <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                            {notes.trim()}
                        </p>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'open':
            return 'destructive';
        case 'ack':
            return 'default';
        case 'triaging':
            return 'outline';
        case 'resolved':
            return 'secondary';
        case 'closed':
            return 'secondary';
        default:
            return 'outline';
    }
}

function AlertStatusBadge({ status }: { status: string }) {
    const StatusIcon =
        status === 'open'
            ? AlertTriangle
            : status === 'ack'
              ? Bell
              : status === 'triaging'
                ? Eye
                : status === 'dismissed'
                  ? X
                  : CheckCircle;

    return (
        <Badge variant={statusVariant(status)} className="gap-1.5">
            <StatusIcon className="h-3 w-3" aria-hidden="true" />
            {STATUS_LABELS[status] ?? status.replace(/_/g, ' ')}
        </Badge>
    );
}

export default function AlertsIndex({
    hero: rawHero,
    control_room_alerts: rawCrAlerts,
    archived_asset_alerts: rawArchivedAssetAlerts,
    filters: rawFilters,
    can,
}: Props) {
    const hero = rawHero ?? {
        unresolved: 0,
        critical: 0,
        acknowledged_today: 0,
        resolved_7d: 0,
    };
    const crAlerts = rawCrAlerts?.data ?? [];
    const crMeta = rawCrAlerts?.meta ?? {
        current_page: 1,
        last_page: 1,
        total: 0,
    };
    const crLinks = rawCrAlerts?.links ?? [];
    const archivedAssetAlerts = rawArchivedAssetAlerts ?? [];
    const filters = rawFilters ?? {};
    const canManage = can.manage;

    const [selectedIds, setSelectedIds] = useState<string[]>([]);
    const [bulkAction, setBulkAction] = useState<FleetAlertAction | null>(null);
    const [resolveAlertId, setResolveAlertId] = useState<number | null>(null);
    const [resolveBulkOpen, setResolveBulkOpen] = useState(false);
    const [resolutionNotes, setResolutionNotes] = useState('');
    const sortField = filters.sort ?? 'triggered_at';
    const sortDir = filters.direction ?? 'desc';

    function handleSort(field: string) {
        const newDir =
            sortField === field && sortDir === 'asc' ? 'desc' : 'asc';

        router.get(
            window.location.pathname,
            { ...filters, sort: field, direction: newDir },
            { preserveState: true },
        );
    }

    const renderSortHeader = (
        field: string,
        children: React.ReactNode,
        className?: string,
    ) => {
        const active = sortField === field;
        return (
            <th
                aria-sort={
                    active
                        ? sortDir === 'asc'
                            ? 'ascending'
                            : 'descending'
                        : 'none'
                }
                className={`cursor-pointer px-4 py-3 font-medium select-none hover:bg-muted/50 ${className ?? 'text-left'}`}
            >
                <Button
                    variant="ghost"
                    type="button"
                    onClick={() => handleSort(field)}
                    className="flex min-h-9 items-center gap-1 rounded focus-visible:outline-2 focus-visible:outline-primary"
                >
                    {children}
                    {active ? (
                        sortDir === 'asc' ? (
                            <ChevronUp className="h-3 w-3" />
                        ) : (
                            <ChevronDown className="h-3 w-3" />
                        )
                    ) : (
                        <ChevronsUpDown className="h-3 w-3 text-muted-foreground/50" />
                    )}
                </Button>
            </th>
        );
    };

    const operationalAlerts = crAlerts;
    const actionableOperationalAlerts = operationalAlerts.filter(
        (alert) => fleetAlertNextAction(alert.status) !== null,
    );
    const selectedAlerts = operationalAlerts.filter((alert) =>
        selectedIds.includes(`cr-${alert.id}`),
    );
    const selectedActionCounts = countFleetAlertActions(selectedAlerts);
    const unresolvedOperationalAlerts = operationalAlerts.filter((a) =>
        ACTIVE_ALERT_STATUSES.has(a.status),
    );
    const criticalCount = unresolvedOperationalAlerts.filter(
        (a) => a.severity === 'critical',
    ).length;
    const highCount = unresolvedOperationalAlerts.filter(
        (a) => a.severity === 'high',
    ).length;
    const mediumCount = unresolvedOperationalAlerts.filter(
        (a) => a.severity === 'medium',
    ).length;
    const lowAlertCount = unresolvedOperationalAlerts.filter(
        (a) => a.severity === 'low',
    ).length;

    const severityTotal =
        criticalCount + highCount + mediumCount + lowAlertCount;
    const severityBars = [
        {
            label: 'Critical',
            count: criticalCount,
            color: 'bg-status-critical',
        },
        { label: 'High', count: highCount, color: 'bg-status-warning' },
        { label: 'Medium', count: mediumCount, color: 'bg-status-warning' },
        { label: 'Low', count: lowAlertCount, color: 'bg-status-info' },
    ];

    const applyFilters = (newFilters: Partial<typeof filters>) => {
        router.get(
            '/fleet-assets/alerts',
            {
                ...filters,
                ...newFilters,
                cr_page: 1,
            },
            { preserveState: true },
        );
    };

    const toggleSelect = useCallback((id: string) => {
        setSelectedIds((prev) =>
            prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id],
        );
    }, []);

    const toggleSelectAll = useCallback(() => {
        const crIds = actionableOperationalAlerts.map((a) => `cr-${a.id}`);
        if (crIds.every((id) => selectedIds.includes(id))) {
            setSelectedIds([]);
        } else {
            setSelectedIds(crIds);
        }
    }, [actionableOperationalAlerts, selectedIds]);

    const handleBulkAction = useCallback(
        (action: FleetAlertAction) => {
            if (selectedIds.length === 0) return;
            const numericIds = operationalAlerts
                .filter(
                    (alert) =>
                        selectedIds.includes(`cr-${alert.id}`) &&
                        isFleetAlertActionEligible(alert.status, action),
                )
                .map((alert) => alert.id);
            if (numericIds.length === 0) return;

            router.post(
                '/fleet-assets/alerts/bulk-action',
                { action, ids: numericIds } as any,
                {
                    preserveState: true,
                    onSuccess: () => setSelectedIds([]),
                },
            );
        },
        [operationalAlerts, selectedIds],
    );

    const openSingleResolve = useCallback((alertId: number) => {
        setResolveAlertId(alertId);
        setResolveBulkOpen(false);
        setResolutionNotes('');
    }, []);

    const openBulkResolve = useCallback(() => {
        setResolveAlertId(null);
        setResolveBulkOpen(true);
        setResolutionNotes('');
    }, []);

    const closeResolveDialog = useCallback(() => {
        setResolveAlertId(null);
        setResolveBulkOpen(false);
        setResolutionNotes('');
    }, []);

    const submitResolve = useCallback(() => {
        const notes = resolutionNotes.trim();
        if (!notes) return;

        if (resolveAlertId) {
            router.post(
                `/fleet-assets/alerts/${resolveAlertId}/resolve`,
                { resolution_notes: notes },
                {
                    preserveState: true,
                    onSuccess: closeResolveDialog,
                },
            );

            return;
        }

        const numericIds = operationalAlerts
            .filter(
                (alert) =>
                    selectedIds.includes(`cr-${alert.id}`) &&
                    isFleetAlertActionEligible(alert.status, 'resolve'),
            )
            .map((alert) => alert.id);

        router.post(
            '/fleet-assets/alerts/bulk-action',
            { action: 'resolve', ids: numericIds, resolution_notes: notes },
            {
                preserveState: true,
                onSuccess: () => {
                    setSelectedIds([]);
                    closeResolveDialog();
                },
            },
        );
    }, [
        closeResolveDialog,
        operationalAlerts,
        resolutionNotes,
        resolveAlertId,
        selectedIds,
    ]);

    const resolveDialogOpen = resolveAlertId !== null || resolveBulkOpen;
    const meterHref = (parameters: Record<string, string> = {}) => {
        const query = new URLSearchParams(parameters);
        if (filters.asset_id) query.set('asset_id', filters.asset_id);
        return (
            '/fleet-assets/alerts' + (query.size ? '?' + query.toString() : '')
        );
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Alerts', href: '/fleet-assets/alerts' },
            ]}
        >
            <Head title="Alerts" />
            <PageShell>
                <PageHeader
                    icon={Bell}
                    title="Alerts"
                    subline="Fleet alerts · Shared with Control Room"
                    actions={
                        can.controlRoomView ? (
                            <PageHeaderGlassButton
                                icon={ExternalLink}
                                onClick={() => router.visit('/control-room')}
                            >
                                Control Room
                            </PageHeaderGlassButton>
                        ) : undefined
                    }
                    meters={
                        <>
                            {[
                                {
                                    label: 'Unresolved',
                                    value: hero.unresolved,
                                    caption: 'Need action',
                                    href: meterHref(),
                                    tone:
                                        hero.unresolved > 0
                                            ? ('warning' as const)
                                            : ('brand' as const),
                                },
                                {
                                    label: 'Critical',
                                    value: hero.critical,
                                    caption: 'Unresolved · immediate attention',
                                    href: meterHref({ severity: 'critical' }),
                                    tone:
                                        hero.critical > 0
                                            ? ('critical' as const)
                                            : ('brand' as const),
                                },
                                {
                                    label: 'Acknowledged today',
                                    value: hero.acknowledged_today,
                                    caption: 'Including alerts since resolved',
                                    href: meterHref({
                                        activity: 'acknowledged_today',
                                    }),
                                    tone: 'brand' as const,
                                },
                                {
                                    label: 'Resolved in 7 days',
                                    value: hero.resolved_7d,
                                    caption:
                                        'Recorded resolution · past 7 days',
                                    href: meterHref({
                                        activity: 'resolved_7d',
                                    }),
                                    tone: 'brand' as const,
                                },
                            ].map((meter) => (
                                <PageHeaderMeterBlock
                                    key={meter.label}
                                    label={meter.label}
                                    href={meter.href}
                                    tone={meter.tone}
                                >
                                    <PageHeaderMeterBig>
                                        {rawHero ? meter.value : '—'}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {meter.caption}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </>
                    }
                    filters={
                        <>
                            <span className="mr-auto text-xs text-primary-foreground/70">
                                Meters:{' '}
                                {filters.asset_id
                                    ? 'selected asset'
                                    : 'permitted assets'}{' '}
                                · all statuses and severities
                            </span>
                            <PageHeaderFilterSelect
                                label="All severities"
                                value={filters.severity || 'all'}
                                options={[
                                    { value: 'all', label: 'All severities' },
                                    { value: 'critical', label: 'Critical' },
                                    { value: 'high', label: 'High' },
                                    { value: 'medium', label: 'Medium' },
                                    { value: 'low', label: 'Low' },
                                ]}
                                onChange={(value) =>
                                    applyFilters({
                                        severity: value === 'all' ? '' : value,
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Status · Unresolved"
                                allValue="unresolved"
                                value={
                                    filters.status ||
                                    (filters.activity ? 'all' : 'unresolved')
                                }
                                options={[
                                    {
                                        value: 'unresolved',
                                        label: 'Unresolved',
                                    },
                                    { value: 'all', label: 'All statuses' },
                                    ...Object.entries(STATUS_LABELS).map(
                                        ([value, label]) => ({ value, label }),
                                    ),
                                ]}
                                onChange={(value) =>
                                    applyFilters({
                                        status:
                                            value === 'unresolved' ? '' : value,
                                        activity: '',
                                    })
                                }
                            />
                            {filters.activity && (
                                <PageHeaderFilterButton
                                    onClick={() =>
                                        applyFilters({ activity: '' })
                                    }
                                >
                                    {filters.activity === 'acknowledged_today'
                                        ? 'Acknowledged today'
                                        : 'Resolved in past 7 days'}{' '}
                                    <X className="size-3" />{' '}
                                    <span className="sr-only">
                                        Clear activity filter
                                    </span>
                                </PageHeaderFilterButton>
                            )}
                            {filters.asset_id && (
                                <PageHeaderFilterButton
                                    onClick={() =>
                                        applyFilters({ asset_id: '' })
                                    }
                                >
                                    Selected asset <X className="size-3" />
                                    <span className="sr-only">
                                        Clear asset filter
                                    </span>
                                </PageHeaderFilterButton>
                            )}
                        </>
                    }
                />

                {/* Severity distribution (current page of results) */}
                <div className="grid gap-3">
                    {severityTotal > 0 && (
                        <Card className="border bg-primary/10 sm:col-span-2 md:col-span-3 lg:col-span-4 dark:bg-primary/20">
                            <CardContent className="p-4">
                                <p className="mb-3 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                    Unresolved severity · current page
                                </p>
                                <div className="flex h-3 w-full overflow-hidden rounded-full">
                                    {severityBars.map(
                                        (bar) =>
                                            bar.count > 0 && (
                                                <div
                                                    key={bar.label}
                                                    className={`${bar.color} transition-all duration-300`}
                                                    style={{
                                                        width: `${(bar.count / severityTotal) * 100}%`,
                                                    }}
                                                    title={`${bar.label}: ${bar.count}`}
                                                />
                                            ),
                                    )}
                                </div>
                                <div className="mt-2 flex flex-wrap gap-4 text-xs">
                                    {severityBars.map((bar) => (
                                        <div
                                            key={bar.label}
                                            className="flex items-center gap-1.5"
                                        >
                                            <span
                                                className={`inline-block h-2.5 w-2.5 rounded-sm ${bar.color}`}
                                            />
                                            <span className="text-muted-foreground">
                                                {bar.label}
                                            </span>
                                            <span className="font-medium text-foreground">
                                                {bar.count}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <Card className="border-primary/20 bg-primary/5">
                    <CardContent className="flex flex-wrap items-center justify-between gap-4 p-4">
                        <div>
                            <p className="text-sm font-semibold">
                                One Control Room workflow
                            </p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Fleet and Control Room update the same alert, so
                                the next person can see exactly where the
                                response is up to.
                            </p>
                        </div>
                        <ol className="flex flex-wrap items-center gap-2 text-xs font-medium">
                            <li className="rounded-full border bg-background px-3 py-1.5">
                                1 · Acknowledge
                            </li>
                            <li
                                aria-hidden="true"
                                className="text-muted-foreground"
                            >
                                →
                            </li>
                            <li className="rounded-full border bg-background px-3 py-1.5">
                                2 · Start triage
                            </li>
                            <li
                                aria-hidden="true"
                                className="text-muted-foreground"
                            >
                                →
                            </li>
                            <li className="rounded-full border bg-background px-3 py-1.5">
                                3 · Resolve with notes
                            </li>
                        </ol>
                    </CardContent>
                </Card>

                {/* Table with severity left borders */}
                <div className="overflow-hidden rounded-lg border">
                    <FleetResponsiveTable>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="bg-muted/50 text-xs tracking-wider text-muted-foreground uppercase">
                                    {canManage && (
                                        <th className="w-8 px-4 py-3 text-left font-medium">
                                            <input
                                                type="checkbox"
                                                checked={
                                                    actionableOperationalAlerts.length >
                                                        0 &&
                                                    actionableOperationalAlerts.every(
                                                        (alert) =>
                                                            selectedIds.includes(
                                                                `cr-${alert.id}`,
                                                            ),
                                                    )
                                                }
                                                onChange={toggleSelectAll}
                                                className="h-3.5 w-3.5 rounded border-border"
                                            />
                                        </th>
                                    )}
                                    <th className="px-4 py-3 text-left font-medium">
                                        Type
                                    </th>
                                    {renderSortHeader('severity', 'Severity')}
                                    {renderSortHeader('status', 'Status')}
                                    <th className="px-4 py-3 text-left font-medium">
                                        Asset
                                    </th>
                                    {renderSortHeader(
                                        'triggered_at',
                                        'Triggered',
                                    )}
                                    <th className="px-4 py-3 text-left font-medium">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {operationalAlerts.length > 0 ? (
                                    operationalAlerts.map((alert) => {
                                        const nextAction = fleetAlertNextAction(
                                            alert.status,
                                        );

                                        return (
                                            <tr
                                                key={alert.id}
                                                className={`border-b transition-colors hover:bg-muted/30 ${SEVERITY_BORDER[alert.severity] ?? ''}`}
                                            >
                                                {canManage && (
                                                    <td className="px-4 py-3">
                                                        {nextAction ? (
                                                            <input
                                                                type="checkbox"
                                                                aria-label={`Select alert ${alert.id}`}
                                                                checked={selectedIds.includes(
                                                                    `cr-${alert.id}`,
                                                                )}
                                                                onChange={() =>
                                                                    toggleSelect(
                                                                        `cr-${alert.id}`,
                                                                    )
                                                                }
                                                                className="h-3.5 w-3.5 rounded border-border"
                                                            />
                                                        ) : null}
                                                    </td>
                                                )}
                                                <td
                                                    data-fleet-row-identity
                                                    className="px-4 py-3"
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <AlertTriangle
                                                            className={`h-4 w-4 ${alert.severity === 'critical' ? 'text-status-critical' : 'text-status-warning'}`}
                                                        />
                                                        <span className="font-medium">
                                                            {(
                                                                alert.alert_type ??
                                                                ''
                                                            ).replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge
                                                        variant={severityVariant(
                                                            alert.severity,
                                                        )}
                                                        className="text-xs font-bold uppercase"
                                                    >
                                                        {alert.severity}
                                                    </Badge>
                                                </td>
                                                <td
                                                    data-fleet-row-status
                                                    className="px-4 py-3"
                                                >
                                                    <AlertStatusBadge
                                                        status={alert.status}
                                                    />
                                                </td>
                                                <td className="px-4 py-3">
                                                    {alert.asset ? (
                                                        <Link
                                                            href={`/fleet-assets/assets/${alert.asset.id}`}
                                                            className="text-primary hover:underline"
                                                        >
                                                            {alert.asset.name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            ---
                                                        </span>
                                                    )}
                                                </td>
                                                <td
                                                    data-fleet-row-time
                                                    className="px-4 py-3 text-muted-foreground"
                                                >
                                                    {alert.triggered_at
                                                        ? formatDateTime(
                                                              alert.triggered_at,
                                                          )
                                                        : '---'}
                                                </td>
                                                <td
                                                    data-fleet-row-action
                                                    className="px-4 py-3"
                                                >
                                                    {canManage ? (
                                                        <div className="flex gap-1">
                                                            {nextAction ===
                                                                'acknowledge' && (
                                                                <Button
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            `/fleet-assets/alerts/${alert.id}/acknowledge`,
                                                                        )
                                                                    }
                                                                >
                                                                    <Bell className="mr-1 h-3.5 w-3.5" />
                                                                    Acknowledge
                                                                </Button>
                                                            )}
                                                            {nextAction ===
                                                                'triage' && (
                                                                <Button
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            `/fleet-assets/alerts/${alert.id}/triage`,
                                                                        )
                                                                    }
                                                                >
                                                                    <Eye className="mr-1 h-3.5 w-3.5" />
                                                                    Start triage
                                                                </Button>
                                                            )}
                                                            {nextAction ===
                                                                'resolve' && (
                                                                <Button
                                                                    variant="outline"
                                                                    size="sm"
                                                                    onClick={() =>
                                                                        openSingleResolve(
                                                                            alert.id,
                                                                        )
                                                                    }
                                                                >
                                                                    <CheckCircle className="mr-1 h-3 w-3" />
                                                                    Resolve
                                                                </Button>
                                                            )}
                                                            {!nextAction && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    No action
                                                                    needed
                                                                </span>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <span className="text-xs text-muted-foreground">
                                                            View only
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={canManage ? 7 : 6}
                                            className="px-4 py-12"
                                        >
                                            <FleetEmptyState
                                                icon={Bell}
                                                title="No alerts match this view"
                                                description="Try another status, severity or activity filter to see other permitted alerts."
                                            />
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </FleetResponsiveTable>
                </div>

                <Card>
                    <CardContent className="space-y-3 p-4">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                                    Earlier alert history
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    Earlier alerts are kept here for reference.
                                    Respond to current alerts in the list above.
                                </p>
                            </div>
                        </div>

                        {archivedAssetAlerts.length > 0 ? (
                            <div className="space-y-2">
                                {archivedAssetAlerts.map((alert) => (
                                    <div
                                        key={alert.id}
                                        className="flex items-start justify-between gap-3 rounded-md border p-3 text-sm"
                                    >
                                        <div className="min-w-0">
                                            <div className="font-medium">
                                                {alert.alert_type.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {alert.asset ? (
                                                    <Link
                                                        href={`/fleet-assets/assets/${alert.asset.id}`}
                                                        className="text-primary hover:underline"
                                                    >
                                                        {alert.asset.name}
                                                    </Link>
                                                ) : (
                                                    'Unknown asset'
                                                )}
                                                {alert.tracker
                                                    ? ` • ${alert.tracker.vendor} ${alert.tracker.device_uid}`
                                                    : ''}
                                            </div>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                Triggered{' '}
                                                {alert.triggered_at
                                                    ? formatDateTime(
                                                          alert.triggered_at,
                                                      )
                                                    : '---'}
                                                {alert.acknowledged_at
                                                    ? ` • Acknowledged ${formatDateTime(alert.acknowledged_at)}`
                                                    : ''}
                                                {alert.resolved_at
                                                    ? ` • Resolved ${formatDateTime(alert.resolved_at)}`
                                                    : ''}
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Badge
                                                variant={severityVariant(
                                                    alert.severity,
                                                )}
                                                className="text-xs font-bold uppercase"
                                            >
                                                {alert.severity}
                                            </Badge>
                                            <Badge
                                                variant={statusVariant(
                                                    alert.status,
                                                )}
                                            >
                                                {alert.status}
                                            </Badge>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No archived asset alerts matched the current
                                filters.
                            </p>
                        )}
                    </CardContent>
                </Card>

                {/* Bulk Action Bar */}
                {canManage && selectedIds.length > 0 && (
                    // eslint-disable-next-line no-restricted-syntax -- Fixed bulk action bar needs sticky overlay positioning rather than Card spacing.
                    <div className="fixed bottom-4 left-1/2 z-50 flex -translate-x-1/2 items-center gap-3 rounded-lg border bg-background px-4 py-3 shadow-lg">
                        <span className="text-sm font-medium">
                            {selectedIds.length} alert
                            {selectedIds.length !== 1 ? 's' : ''} selected
                        </span>
                        <div className="flex items-center gap-2">
                            {selectedActionCounts.acknowledge > 0 && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setBulkAction('acknowledge')}
                                >
                                    Acknowledge open (
                                    {selectedActionCounts.acknowledge})
                                </Button>
                            )}
                            {selectedActionCounts.triage > 0 && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setBulkAction('triage')}
                                >
                                    Start triage ({selectedActionCounts.triage})
                                </Button>
                            )}
                            {selectedActionCounts.resolve > 0 && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={openBulkResolve}
                                >
                                    Resolve ready (
                                    {selectedActionCounts.resolve})
                                </Button>
                            )}
                        </div>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setSelectedIds([])}
                        >
                            <X className="h-4 w-4" />
                        </Button>
                    </div>
                )}

                {/* Pagination */}
                {(crMeta.last_page ?? 1) > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {crLinks.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
                <ResolveAlertWizard
                    open={canManage && resolveDialogOpen}
                    notes={resolutionNotes}
                    onNotesChange={setResolutionNotes}
                    onClose={closeResolveDialog}
                    onSubmit={submitResolve}
                />
                <ConfirmDialog
                    open={canManage && bulkAction !== null}
                    onClose={() => setBulkAction(null)}
                    onConfirm={() => {
                        if (bulkAction) handleBulkAction(bulkAction);
                    }}
                    title={
                        bulkAction === 'acknowledge'
                            ? 'Acknowledge alerts'
                            : 'Start triage'
                    }
                    description={
                        bulkAction === 'acknowledge'
                            ? `Confirm that the team has seen ${selectedActionCounts.acknowledge} open alert${selectedActionCounts.acknowledge !== 1 ? 's' : ''}.`
                            : `Mark ${selectedActionCounts.triage} acknowledged alert${selectedActionCounts.triage !== 1 ? 's' : ''} as actively being worked.`
                    }
                    confirmText={
                        bulkAction === 'acknowledge'
                            ? 'Acknowledge alerts'
                            : 'Start triage'
                    }
                    variant="default"
                />
            </PageShell>
        </AppLayout>
    );
}
