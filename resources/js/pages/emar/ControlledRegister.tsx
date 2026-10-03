import { useControlledProduct } from '@/components/emar/controlled/product-client';
import type {
    ControlledMedicine,
    ControlledProductPayload,
} from '@/components/emar/controlled/product-types';
import {
    dateTime,
    medicineLabel,
    Notice,
    quantity,
    RecordList,
    StateBadge,
} from '@/components/emar/controlled/product-ui';
import { EntryList } from '@/components/emar/controlled/record-views';
import { useControlledDialogs } from '@/components/emar/controlled/workspace-dialogs';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    ClipboardCheck,
    FileWarning,
    Home,
    PackageX,
    RefreshCw,
    Scale,
    ShieldCheck,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';

type View = 'register' | 'discrepancies' | 'losses' | 'destructions';
const VIEWS = [
    { key: 'register' as const, label: 'Register', icon: BookOpen },
    { key: 'discrepancies' as const, label: 'Discrepancies', icon: Scale },
    { key: 'losses' as const, label: 'Losses', icon: FileWarning },
    { key: 'destructions' as const, label: 'Destructions', icon: PackageX },
];
function initialFilters() {
    if (typeof window === 'undefined')
        return {
            view: 'register' as View,
            site: 'all',
            search: '',
            classReview: false,
        };
    const params = new URLSearchParams(window.location.search);
    const selected = params.get('view');
    return {
        view: VIEWS.some((item) => item.key === selected)
            ? (selected as View)
            : ('register' as View),
        site: params.get('site_id') ?? 'all',
        search: params.get('q') ?? '',
        classReview: params.get('class_review') === '1',
    };
}
export default function ControlledRegister({
    product,
    initialPayload,
}: {
    product?: ControlledProductPayload;
    initialPayload?: ControlledProductPayload;
}) {
    const workspace = useControlledProduct(product ?? initialPayload);
    const dialogs = useControlledDialogs(workspace);
    const [filters, setFilters] = useState(initialFilters);
    const payload = workspace.payload;
    const change = (patch: Partial<typeof filters>) => {
        const next = { ...filters, ...patch };
        setFilters(next);
        const url = new URL(window.location.href);
        url.searchParams.set('view', next.view);
        for (const [key, value] of [
            ['site_id', next.site === 'all' ? '' : next.site],
            ['q', next.search],
            ['class_review', next.classReview ? '1' : ''],
        ])
            if (value) url.searchParams.set(key, value);
            else url.searchParams.delete(key);
        router.replace({
            url: url.pathname + url.search,
            preserveState: true,
            preserveScroll: true,
        });
    };
    const q = filters.search.trim().toLowerCase();
    const medicines = (payload?.medicines ?? []).filter(
        (medicine) =>
            (filters.site === 'all' ||
                String(medicine.site_id) === filters.site) &&
            (!q ||
                `${medicine.name} ${medicine.client_name} ${medicine.site_name}`
                    .toLowerCase()
                    .includes(q)) &&
            (!filters.classReview || medicine.class_review_required),
    );
    const shown = new Set(medicines.map((medicine) => medicine.id));
    const entryRows = (payload?.entries ?? []).filter((entry) =>
        shown.has(entry.client_medication_id),
    );
    const discrepancies = (payload?.discrepancies ?? []).filter((record) =>
        shown.has(record.client_medication_id),
    );
    const losses = (payload?.losses ?? []).filter((record) =>
        shown.has(record.client_medication_id),
    );
    const destructions = (payload?.destructions ?? []).filter((record) =>
        shown.has(record.client_medication_id),
    );
    const actions = (medicine: ControlledMedicine): MenuItem[] =>
        compactMenu([
            {
                label: 'Open the register',
                icon: BookOpen,
                onClick: () =>
                    dialogs.detail({ kind: 'medicine', id: medicine.id }),
            },
            payload?.can.record && {
                label: 'Breakage, spillage or movement',
                icon: Undo2,
                disabled: medicine.can_record
                    ? undefined
                    : (medicine.record_reason ??
                      'You cannot record at this house'),
                onClick: () =>
                    dialogs.action({
                        action: 'movement',
                        medicineId: medicine.id,
                    }),
            },
            payload?.can.record && {
                label: 'Report a loss',
                icon: FileWarning,
                disabled: medicine.can_record
                    ? undefined
                    : (medicine.record_reason ??
                      'You cannot record at this house'),
                onClick: () =>
                    dialogs.action({
                        action: 'loss_report',
                        medicineId: medicine.id,
                    }),
            },
            payload?.can.record && {
                label: 'Return for destruction',
                icon: PackageX,
                disabled: medicine.can_record
                    ? undefined
                    : (medicine.record_reason ??
                      'You cannot record at this house'),
                onClick: () =>
                    dialogs.action({
                        action: 'destruction',
                        medicineId: medicine.id,
                    }),
            },
            payload?.can.manage && {
                label: 'Review NZ class',
                icon: Scale,
                onClick: () =>
                    dialogs.action({
                        action: 'class_review',
                        medicineId: medicine.id,
                    }),
            },
        ]);
    const meters = payload?.meters;
    const instrument = (
        label: string,
        total: number | undefined,
        caption: string,
        onClick: () => void,
        warning = false,
    ) => (
        <PageHeaderMeterBlock
            label={label}
            onClick={onClick}
            tone={warning && !!total ? 'warning' : 'brand'}
        >
            <PageHeaderMeterBig>{total ?? '—'}</PageHeaderMeterBig>
            <PageHeaderMeterCaption>{caption}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );
    const header = (
        <PageHeader
            icon={ShieldCheck}
            title="Controlled register"
            subline="Controlled medicines across your approved houses · Pacific/Auckland"
            actions={
                <>
                    <PageHeaderSearch
                        value={filters.search}
                        onChange={(search) => change({ search })}
                        placeholder="Search medicines or people"
                    />
                    <PageHeaderGlassButton
                        icon={RefreshCw}
                        disabled={workspace.loading}
                        onClick={() => void workspace.refresh()}
                    >
                        Refresh
                    </PageHeaderGlassButton>
                    {payload?.can.record ? (
                        <PageHeaderPrimaryButton
                            icon={PackageX}
                            onClick={() =>
                                dialogs.action({ action: 'destruction' })
                            }
                        >
                            Return for destruction
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    {instrument(
                        'Discrepancies',
                        meters?.total_open_discrepancies,
                        'Independent follow-up',
                        () =>
                            change({
                                view: 'discrepancies',
                                classReview: false,
                            }),
                        true,
                    )}
                    {instrument(
                        'Losses',
                        meters?.total_open_losses,
                        'Investigation and closure',
                        () => change({ view: 'losses', classReview: false }),
                        true,
                    )}
                    {instrument(
                        'Pharmacy receipt',
                        meters?.total_awaiting_receipts,
                        'Returns awaiting receipt',
                        () =>
                            change({
                                view: 'destructions',
                                classReview: false,
                            }),
                        true,
                    )}
                    {instrument(
                        'Class to review',
                        meters?.total_class_reviews,
                        'Reviewed NZ source required',
                        () => change({ view: 'register', classReview: true }),
                        true,
                    )}
                    {instrument(
                        'Counts overdue',
                        meters?.total_overdue_counts,
                        'Controlled checks',
                        () => router.visit('/meds/today?view=controlled'),
                        true,
                    )}
                    {instrument(
                        'In the register',
                        meters?.total_medicines,
                        'Controlled medicines',
                        () => change({ view: 'register', classReview: false }),
                    )}
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Home}
                        label="House"
                        value={filters.site}
                        allValue="all"
                        options={[
                            { value: 'all', label: 'All your houses' },
                            ...(payload?.sites ?? []).map((site) => ({
                                value: String(site.id),
                                label: site.name,
                            })),
                        ]}
                        onChange={(site) => change({ site })}
                    />
                    <PageHeaderFilterCheck
                        label="Class to review"
                        checked={filters.classReview}
                        onChange={(classReview) => change({ classReview })}
                    />
                    <span className="text-caption text-band-foreground">
                        As at {dateTime(payload?.as_at)}
                    </span>
                </>
            }
            rail={
                <PageHeaderRail
                    value={filters.view}
                    items={VIEWS}
                    onSelect={(view) => change({ view })}
                    ariaLabel="Controlled register views"
                />
            }
        />
    );
    let content;
    if (workspace.loading && !payload)
        content = (
            <Card aria-busy="true" className="p-5">
                <SkeletonTable />
                <span className="sr-only">Loading controlled register…</span>
            </Card>
        );
    else if (!payload)
        content = (
            <ErrorState
                title={
                    workspace.error?.status === 403
                        ? 'You can’t view controlled medicines'
                        : 'Couldn’t load the controlled register'
                }
                message={workspace.error?.message}
                onRetry={() => void workspace.refresh()}
            />
        );
    else if (payload.can.view === false)
        content = (
            <EmptyState
                icon={ShieldCheck}
                title="You can’t view controlled medicines"
                description="Ask your manager if you need controlled-medicine access for your work."
            />
        );
    else if (filters.view === 'register')
        content = (
            <>
                <ListCaption
                    title="Controlled medicines"
                    caption={`${medicines.length} of ${payload.medicines.length} shown`}
                />
                <RecordList
                    rows={medicines}
                    rowKey={(medicine) => medicine.id}
                    identity={(medicine) => ({
                        name: medicine.name,
                        icon: ShieldCheck,
                        subline: `${medicine.client_name} · ${medicine.site_name}`,
                    })}
                    columns={[
                        {
                            key: 'class',
                            label: 'NZ class',
                            width: '0.8fr',
                            cell: (medicine) => (
                                <StatusBadge
                                    variant={
                                        medicine.class_review_required
                                            ? 'warning'
                                            : 'neutral'
                                    }
                                >
                                    {medicine.class_review_required
                                        ? 'Class to review'
                                        : medicine.nz_class
                                          ? `Class ${medicine.nz_class}`
                                          : 'Not configured'}
                                </StatusBadge>
                            ),
                        },
                        {
                            key: 'balance',
                            label: 'Balance',
                            width: '0.8fr',
                            cell: (medicine) =>
                                quantity(medicine.balance, medicine.unit),
                        },
                        {
                            key: 'count',
                            label: 'Last count',
                            width: '1.3fr',
                            cell: (medicine) => (
                                <div>
                                    <p>{dateTime(medicine.count.last_at)}</p>
                                    <p className="text-caption">
                                        {medicine.count.title}
                                    </p>
                                </div>
                            ),
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={(medicine) =>
                        dialogs.detail({ kind: 'medicine', id: medicine.id })
                    }
                    emptyTitle="No controlled medicines to show"
                    emptyDescription="Try another house or clear the filters."
                />
                <ListCaption
                    title="Recent entries"
                    caption={
                        payload.history_has_more?.entries
                            ? `Latest ${payload.history_limit ?? 500} records · more records exist`
                            : `${entryRows.length} shown`
                    }
                />
                <EntryList
                    entries={entryRows}
                    payload={payload}
                    onDetail={dialogs.detail}
                    onAction={dialogs.action}
                />
                <p className="text-caption">
                    Entries are never edited or deleted. Wrong entries are
                    voided with a reason and witness, and stay visible in the
                    history.
                </p>
            </>
        );
    else if (filters.view === 'discrepancies')
        content = (
            <>
                {[
                    ['Open', ['open', 'reported']],
                    ['With a manager', ['under_review']],
                    ['Closed', ['resolved', 'closed']],
                ].map(([label, states]) => {
                    const rows = discrepancies.filter((record) =>
                        (states as string[]).includes(record.status),
                    );
                    return (
                        <section key={label as string} className="space-y-3">
                            <ListCaption
                                title={label as string}
                                caption={`${rows.length} shown · All outstanding work and latest ${payload.history_limit ?? 500} completed records`}
                            />
                            <RecordList
                                rows={rows}
                                rowKey={(record) => record.id}
                                identity={(record) => ({
                                    name: medicineLabel(
                                        payload,
                                        record.client_medication_id,
                                    ),
                                    icon: Scale,
                                    subline: `Discrepancy ${record.id} · ${dateTime(record.reported_at)}`,
                                })}
                                columns={[
                                    {
                                        key: 'difference',
                                        label: 'Expected / counted',
                                        width: '1fr',
                                        cell: (record) =>
                                            `${record.expected_balance} / ${record.actual_balance}`,
                                    },
                                    {
                                        key: 'owner',
                                        label: 'Owner',
                                        width: '1fr',
                                        cell: (record) =>
                                            record.owner_name ??
                                            'Not configured',
                                    },
                                    {
                                        key: 'outcome',
                                        label: 'Status / outcome',
                                        width: '1.5fr',
                                        cell: (record) => (
                                            <div>
                                                <StateBadge
                                                    status={record.status}
                                                />
                                                <p className="text-caption">
                                                    {record.outcome?.replace(
                                                        /_/g,
                                                        ' ',
                                                    ) ?? '—'}
                                                </p>
                                            </div>
                                        ),
                                    },
                                ]}
                                actionsFor={(record) =>
                                    compactMenu([
                                        {
                                            label: 'View discrepancy',
                                            icon: BookOpen,
                                            onClick: () =>
                                                dialogs.detail({
                                                    kind: 'discrepancy',
                                                    id: record.id,
                                                }),
                                        },
                                        payload.can.manage &&
                                            !['resolved', 'closed'].includes(
                                                record.status,
                                            ) && {
                                                label: 'Resolve discrepancy',
                                                icon: Scale,
                                                disabled: record.can_resolve
                                                    ? undefined
                                                    : (record.resolve_reason ??
                                                      'You cannot resolve this discrepancy'),
                                                onClick: () =>
                                                    dialogs.action({
                                                        action: 'resolve',
                                                        targetId: record.id,
                                                        medicineId:
                                                            record.client_medication_id,
                                                    }),
                                            },
                                    ])
                                }
                                onOpen={(record) =>
                                    dialogs.detail({
                                        kind: 'discrepancy',
                                        id: record.id,
                                    })
                                }
                                emptyTitle={`No ${String(label).toLowerCase()} discrepancies in these records`}
                            />
                        </section>
                    );
                })}
            </>
        );
    else if (filters.view === 'losses')
        content = (
            <>
                <ListCaption
                    title="Losses"
                    caption={`${losses.length} shown · All outstanding work and latest ${payload.history_limit ?? 500} completed records`}
                    right={
                        payload.can.record ? (
                            <Button
                                className="min-h-11"
                                onClick={() =>
                                    dialogs.action({ action: 'loss_report' })
                                }
                            >
                                <FileWarning className="size-4" />
                                Report a loss
                            </Button>
                        ) : null
                    }
                />
                <RecordList
                    rows={losses}
                    rowKey={(record) => record.id}
                    identity={(record) => ({
                        name: medicineLabel(
                            payload,
                            record.client_medication_id,
                        ),
                        icon: FileWarning,
                        subline: `Loss ${record.id} · ${dateTime(record.discovered_at)}`,
                    })}
                    columns={[
                        {
                            key: 'quantity',
                            label: 'Missing',
                            width: '0.6fr',
                            cell: (record) => record.quantity,
                        },
                        {
                            key: 'state',
                            label: 'Investigation',
                            width: '1.2fr',
                            cell: (record) => (
                                <StateBadge status={record.status} />
                            ),
                        },
                        {
                            key: 'notifications',
                            label: 'Notifications recorded',
                            width: '1.5fr',
                            cell: (record) => (
                                <div>
                                    <p>
                                        Police:{' '}
                                        {record.reported_to_police
                                            ? (record.police_reference ??
                                              'Told')
                                            : 'Not told'}
                                    </p>
                                    <p className="text-caption">
                                        Medicines Control:{' '}
                                        {record.reported_to_regulator
                                            ? 'Told'
                                            : 'Not told'}
                                    </p>
                                </div>
                            ),
                        },
                    ]}
                    actionsFor={(record) =>
                        compactMenu([
                            {
                                label: 'View investigation',
                                icon: BookOpen,
                                onClick: () =>
                                    dialogs.detail({
                                        kind: 'loss',
                                        id: record.id,
                                    }),
                            },
                            payload.can.record &&
                                !record.closed_at && {
                                    label: 'Add investigation note',
                                    icon: FileWarning,
                                    onClick: () =>
                                        dialogs.action({
                                            action: 'loss_note',
                                            targetId: record.id,
                                            medicineId:
                                                record.client_medication_id,
                                        }),
                                },
                            payload.can.record &&
                                !record.closed_at && {
                                    label: 'Record notification',
                                    icon: FileWarning,
                                    onClick: () =>
                                        dialogs.action({
                                            action: 'loss_notify',
                                            targetId: record.id,
                                            medicineId:
                                                record.client_medication_id,
                                        }),
                                },
                            payload.can.close_loss &&
                                !record.closed_at && {
                                    label: 'Close loss',
                                    icon: ShieldCheck,
                                    onClick: () =>
                                        dialogs.action({
                                            action: 'loss_close',
                                            targetId: record.id,
                                            medicineId:
                                                record.client_medication_id,
                                        }),
                                },
                        ])
                    }
                    onOpen={(record) =>
                        dialogs.detail({ kind: 'loss', id: record.id })
                    }
                    emptyTitle="No losses in these records"
                    emptyDescription="Loss reports and their append-only investigations appear here."
                />
            </>
        );
    else
        content = (
            <>
                <ListCaption
                    title="Destructions and pharmacy returns"
                    caption={`${destructions.length} shown · All outstanding work and latest ${payload.history_limit ?? 500} completed records`}
                />
                <RecordList
                    rows={destructions}
                    rowKey={(record) => record.id}
                    identity={(record) => ({
                        name: medicineLabel(
                            payload,
                            record.client_medication_id,
                        ),
                        icon: PackageX,
                        subline: `Record ${record.id} · ${dateTime(record.recorded_at)}`,
                    })}
                    columns={[
                        {
                            key: 'quantity',
                            label: 'Amount',
                            width: '0.7fr',
                            cell: (record) => (
                                <span
                                    className={
                                        record.voided_at ? 'line-through' : ''
                                    }
                                >
                                    {record.quantity}
                                </span>
                            ),
                        },
                        {
                            key: 'reason',
                            label: 'Reason / method',
                            width: '1.3fr',
                            cell: (record) => (
                                <div>
                                    <p>{record.reason}</p>
                                    <p className="text-caption">
                                        {record.method === 'pharmacy_return'
                                            ? 'Returned to pharmacy'
                                            : 'Denatured on site'}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            key: 'receipt',
                            label: 'Receipt',
                            width: '1.4fr',
                            cell: (record) => (
                                <StatusBadge
                                    variant={
                                        record.voided_at
                                            ? 'neutral'
                                            : record.method ===
                                                    'pharmacy_return' &&
                                                !record.received_at
                                              ? 'warning'
                                              : 'neutral'
                                    }
                                >
                                    {record.voided_at
                                        ? 'Voided'
                                        : record.method === 'denaturing'
                                          ? 'Destroyed on site'
                                          : record.received_at
                                            ? 'Received by the pharmacy'
                                            : 'Waiting for the pharmacist’s receipt'}
                                </StatusBadge>
                            ),
                        },
                    ]}
                    actionsFor={(record) =>
                        compactMenu([
                            {
                                label: 'View record',
                                icon: BookOpen,
                                onClick: () =>
                                    dialogs.detail({
                                        kind: 'destruction',
                                        id: record.id,
                                    }),
                            },
                            payload.can.manage &&
                                !record.voided_at &&
                                record.method === 'pharmacy_return' &&
                                !record.received_at && {
                                    label: 'Record pharmacy receipt',
                                    icon: ClipboardCheck,
                                    onClick: () =>
                                        dialogs.action({
                                            action: 'destruction_receipt',
                                            targetId: record.id,
                                            medicineId:
                                                record.client_medication_id,
                                        }),
                                },
                            payload.can.manage &&
                                !record.voided_at && {
                                    label: 'Void destruction',
                                    icon: Undo2,
                                    danger: true,
                                    onClick: () =>
                                        dialogs.action({
                                            action: 'destruction_void',
                                            targetId: record.id,
                                            medicineId:
                                                record.client_medication_id,
                                        }),
                                },
                        ])
                    }
                    onOpen={(record) =>
                        dialogs.detail({ kind: 'destruction', id: record.id })
                    }
                    emptyTitle="No destructions or returns in these records"
                    emptyDescription="Witnessed pharmacy returns and on-site destructions appear here."
                />
            </>
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Medication', href: '/meds/today' },
                { title: 'Stock & controlled drugs', href: '/emar/stock' },
                { title: 'Controlled register', href: '/emar/controlled' },
            ]}
        >
            <Head title="Controlled register" />
            <div className="space-y-5">
                {header}
                {workspace.offline ? (
                    <Notice title="You’re offline">
                        Witnessed register changes cannot be saved offline.
                        Entered details stay in the open form; witness PINs are
                        never stored.
                    </Notice>
                ) : null}
                {workspace.error && payload ? (
                    <Notice title="These records may be out of date">
                        {workspace.error.message} Refresh before recording.
                    </Notice>
                ) : null}
                {content}
                {dialogs.node}
            </div>
        </AppLayout>
    );
}
