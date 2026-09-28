import { FleetQueueActions } from '@/components/fleet-assets/fleet-queue-actions';
import {
    QueueCriteria,
    QueueEmpty,
    QueuePagination,
    useQueueFilters,
} from '@/components/fleet-assets/queue-kit';
import { CatalogueProvider } from '@/components/fleet-assets/vehicle-workspace/choice-picker';
import { ComplianceDialog } from '@/components/fleet-assets/vehicle-workspace/compliance-dialog';
import { PlanAppointmentDialog } from '@/components/fleet-assets/vehicle-workspace/studio-kit';
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
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    Car,
    CheckCircle2,
    FileText,
    LayoutGrid,
    List,
    Loader2,
    RefreshCw,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { ComplianceSourceDialog } from './_dialogs';
import {
    kindLabels,
    stateLabels,
    stateTone,
    type EvidenceContext,
    type OpenEvidence,
    type Props,
    type QueueRow,
} from './_types';

const tabs = [
    { key: 'attention', label: 'Needs attention', icon: ShieldAlert },
    { key: 'all', label: 'All records', icon: List },
    { key: 'current', label: 'Current evidence', icon: CheckCircle2 },
    { key: 'not_applicable', label: 'Not applicable', icon: ShieldCheck },
];
const actionLabel = (row: QueueRow) =>
    ({
        evidence:
            row.state === 'not_recorded'
                ? 'Assess applicability'
                : 'Record evidence',
        maintenance: 'Open maintenance',
        source: 'View source evidence',
        documents: 'View vehicle documents',
        mileage: 'Record odometer',
    })[row.action] ?? 'Open vehicle profile';
const km = (value: number | null) =>
    value === null
        ? 'Not recorded'
        : `${Number(value).toLocaleString('en-NZ')} km`;
function due(row: QueueRow) {
    return row.state === 'not_applicable'
        ? 'Not required · basis recorded'
        : row.kind === 'ruc' && row.ruc_end_km !== null
          ? `${km(row.ruc_start_km)}–${km(row.ruc_end_km)}`
          : row.expires_on
            ? formatDateOnly(row.expires_on)
            : 'Not recorded';
}

export default function ComplianceIndex({
    queue,
    summary,
    filters,
    sites,
    can,
}: Props) {
    const { url, search, onSearch, patch } = useQueueFilters(
        '/fleet-assets/compliance',
        filters,
    );
    const contextMenu = useEntityContextMenu<QueueRow>();
    const [opened, setOpened] = useState<OpenEvidence[]>([]),
        [loading, setLoading] = useState<{
            row: QueueRow;
            mode: OpenEvidence['mode'];
            error?: string;
        } | null>(null);
    const request = useRef(0);
    const layout = filters.layout === 'cards' ? 'cards' : 'table';
    const view = String(filters.view ?? 'attention');
    const profileHref = (row: QueueRow, action = false) => {
        const location =
            row.kind === 'insurance'
                ? 'tab=overview&view=documents'
                : row.kind === 'restriction' ||
                    (action && row.action === 'maintenance')
                  ? 'tab=maintenance&view=open'
                  : action && row.action === 'mileage'
                    ? 'tab=service&view=mileage'
                    : `tab=service&view=evidence&focus=${row.kind}`;
        return `/fleet-assets/vehicles/${row.vehicle.id}?${location}&return_to=${encodeURIComponent(url)}`;
    };
    const open = async (row: QueueRow, mode: OpenEvidence['mode']) => {
        if (['insurance', 'restriction'].includes(row.kind)) {
            router.visit(profileHref(row));
            return;
        }
        const key = `${row.id}-${mode}`;
        if (opened.some((item) => item.key === key)) {
            setOpened((items) =>
                items.map((item) => ({ ...item, hidden: item.key !== key })),
            );
            return;
        }
        const sequence = ++request.current;
        setLoading({ row, mode });
        try {
            const response = await fetch(
                `/fleet-assets/compliance/vehicles/${row.vehicle.id}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                },
            );
            if (!response.ok)
                throw new Error(
                    [403, 404].includes(response.status)
                        ? 'This vehicle is no longer available in your permitted scope.'
                        : 'The source could not be loaded. Your queue context is retained.',
                );
            const context: EvidenceContext = await response.json();
            const record = context.compliance.find(
                (record) => record.kind === row.kind,
            );
            if (!record)
                throw new Error('The source evidence could not be found.');
            if (
                (mode === 'evidence' && !context.can.manage) ||
                (mode === 'plan' && !context.can.schedule_service)
            )
                throw new Error(
                    'You can view this record but cannot perform this action.',
                );
            if (sequence !== request.current) return;
            setOpened((items) => [
                ...items.map((item) => ({ ...item, hidden: true })),
                { key, mode, context, record, hidden: false },
            ]);
            setLoading(null);
        } catch (error) {
            if (sequence === request.current)
                setLoading({
                    row,
                    mode,
                    error:
                        error instanceof Error
                            ? error.message
                            : 'The source could not be loaded.',
                });
        }
    };
    const act = (row: QueueRow) => {
        if (row.action === 'evidence' && can.manage) void open(row, 'evidence');
        else if (row.action === 'source') void open(row, 'source');
        else router.visit(profileHref(row, true));
    };
    const actions = (row: QueueRow): MenuItem[] => [
        {
            label: 'Open profile evidence',
            icon: Car,
            onClick: () => router.visit(profileHref(row)),
        },
        ...(!['insurance', 'restriction'].includes(row.kind)
            ? [
                  {
                      label: 'View source & version',
                      icon: FileText,
                      onClick: () => void open(row, 'source'),
                  },
              ]
            : []),
        ...(can.manage
            ? [
                  {
                      label: actionLabel(row),
                      icon: ArrowRight,
                      onClick: () => act(row),
                  },
              ]
            : []),
        ...(row.can_plan && ['wof', 'cof', 'registration'].includes(row.kind)
            ? [
                  {
                      label: 'Plan inspection',
                      icon: CalendarDays,
                      onClick: () => void open(row, 'plan'),
                  },
              ]
            : []),
    ];
    const criteria = [
        ...(filters.search
            ? [{ key: 'search', label: `Search: ${filters.search}` }]
            : []),
        ...(filters.state && filters.state !== 'all'
            ? [
                  {
                      key: 'state',
                      label: `State: ${stateLabels[String(filters.state)]}`,
                  },
              ]
            : []),
        ...(filters.kind && filters.kind !== 'all'
            ? [
                  {
                      key: 'kind',
                      label: `Requirement: ${kindLabels[String(filters.kind)]}`,
                  },
              ]
            : []),
    ];
    const clear = () =>
        patch({ search: '', state: undefined, kind: undefined });
    const chooseMeter = (nextView: string, state?: string) =>
        patch({ view: nextView, state, search: '', kind: undefined });
    const close = (key: string) =>
        setOpened((items) => items.filter((item) => item.key !== key));
    const cards = (
        <EntityCardGrid>
            {queue.data.map((row) => (
                <EntityCard
                    key={row.id}
                    name={row.vehicle.name}
                    icon={Car}
                    subline={`${row.vehicle.registration_number ?? row.vehicle.asset_tag} · ${row.label}`}
                    meridian={stateTone(row.state)}
                    actions={actions(row)}
                    onOpen={() => router.visit(profileHref(row))}
                    onContextMenu={(event) => contextMenu.open(event, row)}
                    chips={
                        <StatusBadge variant={stateTone(row.state)}>
                            {stateLabels[row.state]}
                        </StatusBadge>
                    }
                    alerts={
                        <div className="fleet-queue-cell">
                            <strong>{due(row)}</strong>
                            <p className="text-caption">{row.reason}</p>
                        </div>
                    }
                    footer={{
                        personName: row.vehicle.responsible,
                        primary:
                            row.vehicle.responsible ?? 'No responsible person',
                        secondary:
                            row.vehicle.site?.name ?? 'Site not recorded',
                    }}
                />
            ))}
        </EntityCardGrid>
    );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                {
                    title: 'Compliance & renewals',
                    href: '/fleet-assets/compliance',
                },
            ]}
        >
            <Head title="Compliance & renewals" />
            <PageLayout
                hero={
                    <PageHeader
                        className="fleet-queue-header"
                        title="Compliance & renewals"
                        wrapTitle
                        icon={ShieldCheck}
                        subline="Vehicle evidence, applicability and next actions · one requirement per row"
                        actions={
                            <>
                                <FleetQueueActions siteId={filters.site_id} />
                                <PageHeaderSearch
                                    value={search}
                                    onChange={onSearch}
                                    placeholder="Search vehicles or evidence…"
                                />
                                <PageHeaderGlassButton
                                    onClick={() => router.reload()}
                                >
                                    <RefreshCw className="size-4" />
                                    Refresh
                                </PageHeaderGlassButton>
                            </>
                        }
                        meters={
                            <>
                                <PageHeaderMeterBlock
                                    label="Permitted vehicles"
                                    onClick={() => chooseMeter('all')}
                                >
                                    <PageHeaderMeterBig>
                                        {summary.vehicles}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Unique vehicles · current site scope
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Need attention"
                                    tone="warning"
                                    onClick={() => chooseMeter('attention')}
                                >
                                    <PageHeaderMeterBig>
                                        {summary.attention}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Vehicles with one or more queue items
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Evidence not recorded"
                                    tone="warning"
                                    onClick={() =>
                                        chooseMeter('all', 'not_recorded')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {summary.not_recorded}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Unknown is never current
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Due within 30 days"
                                    tone="warning"
                                    onClick={() =>
                                        chooseMeter('all', 'due_soon')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {summary.due_soon}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Recorded dates approaching expiry
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                                <PageHeaderMeterBlock
                                    label="Failed / restricted"
                                    tone="critical"
                                    onClick={() =>
                                        chooseMeter('all', 'failed_restricted')
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {summary.failed_restricted}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Review the stated source reason
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
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
                                    label="All requirements"
                                    value={String(filters.kind ?? 'all')}
                                    options={Object.entries(kindLabels).map(
                                        ([value, label]) => ({ value, label }),
                                    )}
                                    onChange={(kind) => patch({ kind })}
                                />
                                <PageHeaderFilterSelect
                                    label="All states"
                                    value={String(filters.state ?? 'all')}
                                    options={Object.entries(stateLabels).map(
                                        ([value, label]) => ({ value, label }),
                                    )}
                                    onChange={(state) => patch({ state })}
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
                                value={view}
                                onSelect={(value) => patch({ view: value })}
                            />
                        }
                    />
                }
            >
                <div className="fleet-queue-stack">
                    {opened
                        .filter((item) => item.hidden && item.mode !== 'source')
                        .map((item) => (
                            <div
                                key={item.key}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary/30 bg-primary/5 p-3"
                            >
                                <span>
                                    Unsent{' '}
                                    {item.mode === 'plan'
                                        ? 'appointment'
                                        : 'evidence'}{' '}
                                    draft · {item.context.vehicle.name} ·{' '}
                                    {item.record.label} evidence
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setOpened((items) =>
                                                items.map((draft) => ({
                                                    ...draft,
                                                    hidden:
                                                        draft.key !== item.key,
                                                })),
                                            )
                                        }
                                    >
                                        Resume draft
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        onClick={() => close(item.key)}
                                    >
                                        Discard draft
                                    </Button>
                                </div>
                            </div>
                        ))}
                    <ListCaption
                        title="Compliance queue"
                        caption={`${queue.from ?? 0}–${queue.to ?? 0} of ${queue.total} requirement records`}
                        right={
                            criteria.length ? (
                                <Button variant="ghost" onClick={clear}>
                                    Clear list filters
                                </Button>
                            ) : undefined
                        }
                    />
                    <QueueCriteria
                        scope={`${sites.find((site) => String(site.id) === String(filters.site_id))?.name ?? 'All permitted sites'} · ${tabs.find((tab) => tab.key === view)?.label ?? 'All records'} · ${summary.vehicles} unique vehicles`}
                        criteria={criteria}
                        onRemove={(key) => patch({ [key]: undefined })}
                    />
                    {!queue.data.length ? (
                        <QueueEmpty
                            title={
                                summary.vehicles
                                    ? 'No matching requirement records'
                                    : 'No vehicles in this permitted scope'
                            }
                            hasCriteria={criteria.length > 0}
                            onClear={
                                criteria.length
                                    ? clear
                                    : () => chooseMeter('all')
                            }
                        />
                    ) : (
                        <>
                            {layout === 'table' && (
                                <div className="hidden md:block">
                                    <EntityTable
                                        rows={queue.data}
                                        rowKey={(row) => row.id}
                                        identityLabel="Vehicle / requirement"
                                        identity={(row) => ({
                                            icon: Car,
                                            name: row.vehicle.name,
                                            subline: `${row.vehicle.registration_number ?? row.vehicle.asset_tag} · ${row.label}`,
                                        })}
                                        columns={[
                                            {
                                                key: 'state',
                                                label: 'State / reason',
                                                width: '1.7fr',
                                                cell: (row) => (
                                                    <div className="fleet-queue-cell">
                                                        <StatusBadge
                                                            variant={stateTone(
                                                                row.state,
                                                            )}
                                                        >
                                                            {
                                                                stateLabels[
                                                                    row.state
                                                                ]
                                                            }
                                                        </StatusBadge>
                                                        <span className="text-caption">
                                                            {row.reason}
                                                        </span>
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'due',
                                                label: 'Due / recorded coverage',
                                                width: '1.1fr',
                                                cell: (row) => (
                                                    <div className="fleet-queue-cell">
                                                        <strong>
                                                            {due(row)}
                                                        </strong>
                                                        {row.kind === 'ruc' && (
                                                            <span className="text-caption">
                                                                Recorded
                                                                odometer:{' '}
                                                                {km(
                                                                    row.odometer_km,
                                                                )}
                                                            </span>
                                                        )}
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'source',
                                                label: 'Source / version',
                                                width: '1fr',
                                                cell: (row) => (
                                                    <div className="fleet-queue-cell">
                                                        {row.version ? (
                                                            <Button
                                                                variant="link"
                                                                className="fleet-queue-link h-auto p-0 text-left whitespace-normal"
                                                                onClick={(
                                                                    event,
                                                                ) => {
                                                                    event.stopPropagation();
                                                                    void open(
                                                                        row,
                                                                        'source',
                                                                    );
                                                                }}
                                                            >
                                                                {row.reference ??
                                                                    'View evidence'}{' '}
                                                                · v{row.version}
                                                            </Button>
                                                        ) : (
                                                            <span className="text-caption">
                                                                No evidence
                                                                version
                                                            </span>
                                                        )}
                                                        <span className="text-caption">
                                                            {row.recorded_by ??
                                                                'Recorder not recorded'}
                                                            {row.recorded_at
                                                                ? ` · ${formatDateTime(row.recorded_at)}`
                                                                : ''}
                                                        </span>
                                                    </div>
                                                ),
                                            },
                                            {
                                                key: 'owner',
                                                label: 'Owner / next action',
                                                width: '1.2fr',
                                                cell: (row) => (
                                                    <div className="fleet-queue-cell">
                                                        <span>
                                                            {row.vehicle
                                                                .responsible ??
                                                                'No responsible person'}
                                                        </span>
                                                        <Button
                                                            variant="link"
                                                            className="fleet-queue-link h-auto p-0 text-left whitespace-normal"
                                                            onClick={(
                                                                event,
                                                            ) => {
                                                                event.stopPropagation();
                                                                if (
                                                                    can.manage ||
                                                                    row.action ===
                                                                        'source'
                                                                )
                                                                    act(row);
                                                                else
                                                                    router.visit(
                                                                        profileHref(
                                                                            row,
                                                                        ),
                                                                    );
                                                            }}
                                                        >
                                                            {can.manage ||
                                                            row.action ===
                                                                'source'
                                                                ? actionLabel(
                                                                      row,
                                                                  )
                                                                : 'Open source record'}{' '}
                                                            →
                                                        </Button>
                                                        <span className="text-caption">
                                                            {row.vehicle.site
                                                                ?.name ??
                                                                'Site not recorded'}
                                                        </span>
                                                    </div>
                                                ),
                                            },
                                        ]}
                                        actionsFor={actions}
                                        onOpen={(row) =>
                                            router.visit(profileHref(row))
                                        }
                                        onRowContextMenu={contextMenu.open}
                                    />
                                </div>
                            )}
                            <div
                                className={
                                    layout === 'table' ? 'md:hidden' : ''
                                }
                            >
                                {cards}
                            </div>
                            <QueuePagination links={queue.links} />
                        </>
                    )}
                    <p className="text-caption">
                        Header groups count unique vehicles and may overlap. The
                        list counts requirement records. Insurance dates are
                        context only; missing evidence remains unknown.
                    </p>
                </div>
            </PageLayout>
            {contextMenu.ctx && (
                <EntityContextMenu
                    {...contextMenu.ctx}
                    title={`${contextMenu.ctx.record.vehicle.name} · ${contextMenu.ctx.record.label}`}
                    icon={ShieldCheck}
                    items={actions(contextMenu.ctx.record)}
                    onClose={contextMenu.close}
                />
            )}
            <Dialog
                open={!!loading}
                onOpenChange={(next) => {
                    if (!next) {
                        ++request.current;
                        setLoading(null);
                    }
                }}
            >
                <DialogContent
                    style={{
                        width: 'min(92vw, 480px)',
                        maxWidth: 'min(92vw, 480px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <FileText className="size-4" />
                            {loading?.error
                                ? 'Source unavailable'
                                : 'Loading source evidence'}
                        </DialogTitle>
                        <DialogDescription>
                            {loading?.error ??
                                'Checking the current evidence and your permitted actions.'}
                        </DialogDescription>
                    </DialogHeader>
                    {loading?.error ? (
                        <Button
                            onClick={() => void open(loading.row, loading.mode)}
                        >
                            Try again
                        </Button>
                    ) : (
                        <Loader2 className="size-5 animate-spin" />
                    )}
                </DialogContent>
            </Dialog>
            {opened.map((item) => (
                <CatalogueProvider
                    key={item.key}
                    entries={item.context.catalogues}
                    canAdd={item.context.can.add_catalogue}
                >
                    {item.mode === 'evidence' ? (
                        <ComplianceDialog
                            open={!item.hidden}
                            vehicle={item.context.vehicle}
                            record={item.record}
                            onClose={() => close(item.key)}
                            onKeepDraft={() =>
                                setOpened((items) =>
                                    items.map((draft) =>
                                        draft.key === item.key
                                            ? { ...draft, hidden: true }
                                            : draft,
                                    ),
                                )
                            }
                            onSaved={() =>
                                router.reload({ only: ['queue', 'summary'] })
                            }
                        />
                    ) : item.mode === 'plan' ? (
                        <PlanAppointmentDialog
                            open={!item.hidden}
                            onKeepDraft={() =>
                                setOpened((items) =>
                                    items.map((draft) =>
                                        draft.key === item.key
                                            ? { ...draft, hidden: true }
                                            : draft,
                                    ),
                                )
                            }
                            vehicle={item.context.vehicle}
                            presetType={`${item.record.label} inspection`}
                            source={
                                item.record.record_id
                                    ? {
                                          type: 'compliance_record',
                                          id: item.record.record_id,
                                      }
                                    : undefined
                            }
                            onClose={() => close(item.key)}
                            onSaved={() =>
                                router.reload({ only: ['queue', 'summary'] })
                            }
                        />
                    ) : !item.hidden ? (
                        <ComplianceSourceDialog
                            vehicle={item.context.vehicle}
                            record={item.record}
                            onClose={() => close(item.key)}
                            onProfile={() =>
                                router.visit(
                                    `/fleet-assets/vehicles/${item.context.vehicle.id}?tab=service&view=evidence&focus=${item.record.kind}&return_to=${encodeURIComponent(url)}`,
                                )
                            }
                        />
                    ) : null}
                </CatalogueProvider>
            ))}
        </AppLayout>
    );
}
