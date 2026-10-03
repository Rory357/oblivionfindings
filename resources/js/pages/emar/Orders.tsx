import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBlock,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page';
import { PageHeaderPrimaryButton } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { WizardShell } from '@/components/wizard/shell';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowLeftRight,
    CheckCheck,
    ClipboardCheck,
    Eye,
    FileText,
    History,
    Pause,
    Pencil,
    Pill,
    Play,
    Plus,
    ShieldCheck,
    Stethoscope,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { CovertWizard, StopCovert, type Covert } from './orders/_covert';
import { OrderDetail } from './orders/_detail';
import { OrderEntry } from './orders/_entry';
import { Note, OrderStatus } from './orders/_parts';
import {
    ReconcileMedicines,
    StartReconciliation,
} from './orders/_reconciliation';
import type {
    ClientChoice,
    Detail,
    Order,
    Reconciliation,
    RespiteStayChoice,
    ReviewHandoff,
} from './orders/_types';

type View = 'orders' | 'to_check' | 'covert' | 'reconciliation' | 'reviews';
type OrderAction =
    | 'view'
    | 'check'
    | 'allergy'
    | 'written'
    | 'stop'
    | 'hold'
    | 'resume'
    | 'entry';
type Modal =
    | {
          type: 'detail' | 'entry';
          detail: Detail;
          action: OrderAction;
          review?: ReviewHandoff;
      }
    | { type: 'new'; clientId?: number; review?: ReviewHandoff }
    | {
          type: 'opening';
          id: number;
          action: OrderAction;
          error?: string;
          review?: ReviewHandoff;
      }
    | { type: 'start_reconciliation' }
    | { type: 'reconciliation'; record: Reconciliation }
    | {
          type: 'covert';
          orderId: number;
          medicine: string;
          person: string;
          existing?: Covert;
      }
    | { type: 'revoke'; record: Covert }
    | null;
type Props = {
    orders: {
        data: Order[];
        links: { url: string | null; label: string; active: boolean }[];
        last_page: number;
        total: number;
    };
    counts: {
        current: number;
        to_check: number;
        written: number;
        ending: number;
        covert: number;
        reconciliations: number;
    };
    houses: { id: number; name: string }[];
    clients: ClientChoice[];
    covert: Covert[];
    reconciliations: Reconciliation[];
    can: {
        manage: boolean;
        verify: boolean;
        controlled_view: boolean;
        controlled_record: boolean;
    };
    me: { id: number; name: string };
    loaded_at: string;
    review_default: string;
    review_handoff: ReviewHandoff | null;
    prefill_client_id: number | null;
    filters: { view?: View; show?: string; search?: string; site_id?: string };
    respite_stays: RespiteStayChoice[];
    open_order_id: number | null;
};

export default function Orders(props: Props) {
    const [modal, setModal] = useState<Modal>(null);
    const [search, setSearch] = useState(props.filters.search ?? '');
    const request = useRef<AbortController | null>(null);
    const handledRecommendation = useRef<number | null>(null);
    const context = useEntityContextMenu<Order>();
    const covertContext = useEntityContextMenu<Covert>();
    const reconciliationContext = useEntityContextMenu<Reconciliation>();
    const view = props.filters.view ?? 'orders';
    const close = () => {
        request.current?.abort();
        setModal(null);
    };
    useEffect(() => () => request.current?.abort(), []);
    const filter = (change: Partial<Props['filters']>) =>
        router.get(
            '/emar/prescriptions',
            { ...props.filters, search, ...change },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const openOrder = (
        id: number,
        action: OrderAction = 'view',
        review?: ReviewHandoff,
    ) => {
        request.current?.abort();
        const controller = new AbortController();
        request.current = controller;
        setModal({ type: 'opening', id, action, review });
        axios
            .get<Detail>(`/emar/orders/${id}`, { signal: controller.signal })
            .then(({ data }) => {
                if (!controller.signal.aborted)
                    setModal({
                        type: action === 'entry' ? 'entry' : 'detail',
                        detail: data,
                        action,
                        review,
                    });
            })
            .catch(() => {
                if (!controller.signal.aborted)
                    setModal({
                        type: 'opening',
                        id,
                        action,
                        review,
                        error: 'This order could not be opened. Your access or the record may have changed.',
                    });
            });
    };
    useEffect(() => {
        if (props.open_order_id) openOrder(props.open_order_id);
    }, [props.open_order_id]);
    useEffect(() => {
        const recommendation = props.review_handoff;
        if (
            !recommendation ||
            recommendation.entered ||
            handledRecommendation.current === recommendation.id
        )
            return;
        handledRecommendation.current = recommendation.id;
        if (
            ['stop', 'change'].includes(recommendation.outcome) &&
            recommendation.client_medication_id
        )
            openOrder(
                recommendation.client_medication_id,
                recommendation.outcome === 'stop' ? 'stop' : 'entry',
                recommendation,
            );
        else
            setModal({
                type: 'new',
                clientId: recommendation.client_id,
                review: recommendation,
            });
    }, [props.review_handoff]);
    const actionsFor = (order: Order): MenuItem[] => {
        const managed = order.can_manage
            ? undefined
            : (order.blocked_reason ??
              'Order management permission is required.');
        const verified = order.can_verify
            ? undefined
            : (order.blocked_reason ?? 'Order-check permission is required.');
        return [
            {
                label: 'Open order',
                icon: Eye,
                onClick: () => openOrder(order.id),
            },
            ...(order.state === 'ceased'
                ? []
                : [
                      {
                          label: 'Enter a change',
                          icon: Pencil,
                          disabled: managed,
                          onClick: () => openOrder(order.id, 'entry'),
                      },
                      ...(order.pending?.status === 'pending' ||
                      (order.current?.second_due_at &&
                          !order.current.second_checked_at)
                          ? [
                                {
                                    label: 'Check this version',
                                    icon: CheckCheck,
                                    disabled: verified,
                                    onClick: () => openOrder(order.id, 'check'),
                                },
                            ]
                          : []),
                      ...((order.current?.written_due_at &&
                          !order.current.written_confirmation) ||
                      (order.pending?.written_due_at &&
                          !order.pending.written_confirmation)
                          ? [
                                {
                                    label: 'Add written confirmation',
                                    icon: FileText,
                                    disabled: managed,
                                    onClick: () =>
                                        openOrder(order.id, 'written'),
                                },
                            ]
                          : []),
                      {
                          label:
                              order.state === 'paused'
                                  ? 'Resume order'
                                  : 'Hold order',
                          icon: order.state === 'paused' ? Play : Pause,
                          disabled: managed,
                          onClick: () =>
                              openOrder(
                                  order.id,
                                  order.state === 'paused' ? 'resume' : 'hold',
                              ),
                      },
                      {
                          label: 'Authorise covert giving',
                          icon: ShieldCheck,
                          disabled:
                              managed ??
                              (order.approval_status !== 'verified'
                                  ? 'Check this order first.'
                                  : undefined),
                          onClick: () =>
                              setModal({
                                  type: 'covert',
                                  orderId: order.id,
                                  medicine: order.name,
                                  person: order.person,
                              }),
                      },
                      {
                          label: 'Stop order',
                          icon: XCircle,
                          danger: true,
                          disabled: managed,
                          onClick: () => openOrder(order.id, 'stop'),
                      },
                  ]),
        ];
    };
    const covertActions = (record: Covert): MenuItem[] => [
        {
            label: 'Open order',
            icon: Eye,
            onClick: () => openOrder(record.client_medication_id),
        },
        {
            label: 'Review authorisation',
            icon: ShieldCheck,
            disabled: record.can_manage
                ? undefined
                : 'Order management permission is required.',
            onClick: () =>
                setModal({
                    type: 'covert',
                    orderId: record.client_medication_id,
                    medicine: record.medication.name,
                    person: `${record.client.first_name} ${record.client.last_name}`,
                    existing: record,
                }),
        },
        ...(record.status === 'active'
            ? [
                  {
                      label: 'Stop covert giving',
                      icon: XCircle,
                      danger: true,
                      disabled: record.can_manage
                          ? undefined
                          : 'Order management permission is required.',
                      onClick: () => setModal({ type: 'revoke', record }),
                  },
              ]
            : []),
    ];
    const reconciliationActions = (record: Reconciliation): MenuItem[] => [
        {
            label: record.signed_off_at
                ? 'Open reconciliation'
                : 'Continue matching',
            icon: ArrowLeftRight,
            onClick: () => setModal({ type: 'reconciliation', record }),
        },
    ];
    const clientSite = (clientId: number) =>
        props.clients.find((client) => client.id === clientId)?.site_id;
    const matches = (clientId: number, text: string) =>
        (!props.filters.site_id ||
            String(clientSite(clientId)) === props.filters.site_id) &&
        text.toLowerCase().includes(search.toLowerCase());
    const covert = props.covert.filter((record) =>
        matches(
            record.client_id,
            `${record.medication.name} ${record.client.first_name} ${record.client.last_name}`,
        ),
    );
    const reconciliations = props.reconciliations.filter((record) =>
        matches(
            record.client_id,
            `${record.client.first_name} ${record.client.last_name} ${record.reason}`,
        ),
    );
    const enterFromReconciliation = (id: number | null, clientId: number) =>
        id ? openOrder(id, 'entry') : setModal({ type: 'new', clientId });
    return (
        <AppLayout breadcrumbs={useEmarBreadcrumbs()}>
            <Head title="Medication orders" />
            <div className="flex flex-col gap-5">
                <PageHeader
                    icon={Pill}
                    title="Medication orders"
                    subline={`Prescriber’s instructions, checks and transitions · loaded ${formatDateTime(props.loaded_at)}`}
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter')
                                        filter({ search });
                                }}
                                placeholder="Search medicine or person"
                            />
                            <PageHeaderPrimaryButton
                                onClick={() =>
                                    setModal(
                                        view === 'reconciliation'
                                            ? { type: 'start_reconciliation' }
                                            : { type: 'new' },
                                    )
                                }
                                disabled={
                                    !props.can.manage ||
                                    !props.clients.some(
                                        (client) => client.can_enter,
                                    )
                                }
                            >
                                <Plus className="size-4" />
                                {view === 'reconciliation'
                                    ? 'Reconcile medicines'
                                    : 'Enter an order'}
                            </PageHeaderPrimaryButton>
                        </>
                    }
                    meters={
                        <>
                            {[
                                {
                                    key: 'to_check',
                                    label: 'To check',
                                    value: props.counts.to_check,
                                    change: {
                                        view: 'to_check' as View,
                                        show: '',
                                    },
                                    caption: 'Waiting order versions',
                                },
                                {
                                    key: 'written',
                                    label: 'Written due',
                                    value: props.counts.written,
                                    change: {
                                        view: 'to_check' as View,
                                        show: 'attention',
                                    },
                                    caption: 'Prescriber’s confirmation',
                                },
                                {
                                    key: 'ending',
                                    label: 'Ending in 14 days',
                                    value: props.counts.ending,
                                    change: {
                                        view: 'orders' as View,
                                        show: 'ending',
                                    },
                                    caption: 'Review the final day',
                                },
                                {
                                    key: 'covert',
                                    label: 'Covert',
                                    value: props.counts.covert,
                                    change: {
                                        view: 'covert' as View,
                                        show: '',
                                    },
                                    caption: 'Active authorisations',
                                },
                                {
                                    key: 'reconciliation',
                                    label: 'Reconciliation',
                                    value: props.counts.reconciliations,
                                    change: {
                                        view: 'reconciliation' as View,
                                        show: '',
                                    },
                                    caption: 'Not yet signed off',
                                },
                                {
                                    key: 'current',
                                    label: 'Current orders',
                                    value: props.counts.current,
                                    change: {
                                        view: 'orders' as View,
                                        show: '',
                                    },
                                    caption: 'On the current chart',
                                },
                            ].map((meter) => (
                                <PageHeaderMeterBlock
                                    key={meter.key}
                                    label={meter.label}
                                    value={meter.value}
                                    onClick={() => filter(meter.change)}
                                >
                                    <span className="text-caption">
                                        {meter.caption}
                                    </span>
                                </PageHeaderMeterBlock>
                            ))}
                        </>
                    }
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="All houses"
                                value={props.filters.site_id ?? 'all'}
                                options={props.houses.map((house) => ({
                                    value: String(house.id),
                                    label: house.name,
                                }))}
                                onChange={(value) =>
                                    filter({
                                        site_id: value === 'all' ? '' : value,
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Current orders"
                                value={props.filters.show || 'all'}
                                options={[
                                    { value: 'all', label: 'Current orders' },
                                    {
                                        value: 'attention',
                                        label: 'Needs attention',
                                    },
                                    {
                                        value: 'ending',
                                        label: 'Ending in 14 days',
                                    },
                                    {
                                        value: 'stopped',
                                        label: 'Stopped orders',
                                    },
                                ]}
                                onChange={(value) =>
                                    filter({
                                        show: value === 'all' ? '' : value,
                                    })
                                }
                            />
                        </>
                    }
                    rail={
                        <PageHeaderRail<View>
                            value={view}
                            items={[
                                { key: 'orders', label: 'Orders', icon: Pill },
                                {
                                    key: 'to_check',
                                    label: 'To check',
                                    icon: ClipboardCheck,
                                    count: props.counts.to_check,
                                },
                                {
                                    key: 'covert',
                                    label: 'Covert giving',
                                    icon: ShieldCheck,
                                },
                                {
                                    key: 'reconciliation',
                                    label: 'Reconciliation',
                                    icon: ArrowLeftRight,
                                },
                                {
                                    key: 'reviews',
                                    label: 'Medication reviews',
                                    icon: Stethoscope,
                                },
                            ]}
                            onSelect={(value) =>
                                value === 'reviews'
                                    ? router.get('/emar/reviews')
                                    : filter({ view: value, show: '' })
                            }
                        />
                    }
                />
                {(view === 'orders' || view === 'to_check') && (
                    <>
                        {props.orders.data.length ? (
                            <EntityTable
                                rows={props.orders.data}
                                rowKey={(order) => order.id}
                                identityLabel="Medicine"
                                identityWidth="1.4fr"
                                identity={(order) => ({
                                    icon: Pill,
                                    name: order.name,
                                    subline: `${order.is_prn ? 'As needed' : 'Scheduled'}${order.controlled ? ' · Controlled' : ''}`,
                                })}
                                rowHeight="content"
                                minWidth={820}
                                columns={[
                                    {
                                        key: 'person',
                                        label: 'Person',
                                        width: '1fr',
                                        cell: (order) => (
                                            <span className="text-sm">
                                                {order.person}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'dose',
                                        label: 'Dose and times',
                                        width: '1.2fr',
                                        cell: (order) => (
                                            <div>
                                                <p className="text-sm">
                                                    {order.dosage}
                                                </p>
                                                <p className="text-caption">
                                                    {order.is_prn
                                                        ? order.frequency
                                                        : order.dose_times?.join(
                                                              ' · ',
                                                          ) || order.frequency}
                                                </p>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'version',
                                        label: 'Current version',
                                        width: '0.7fr',
                                        cell: (order) => (
                                            <div>
                                                <p className="text-sm">
                                                    Version {order.version}
                                                </p>
                                                <p className="text-caption">
                                                    {order.approval_status ===
                                                    'verified'
                                                        ? 'Checked'
                                                        : 'Cannot give yet'}
                                                </p>
                                            </div>
                                        ),
                                    },
                                    {
                                        key: 'state',
                                        label: 'State',
                                        width: '1.7fr',
                                        cell: (order) => (
                                            <OrderStatus order={order} />
                                        ),
                                    },
                                ]}
                                onOpen={(order) => openOrder(order.id)}
                                actionsFor={actionsFor}
                                onRowContextMenu={context.open}
                            />
                        ) : (
                            <EmptyState
                                icon={Pill}
                                title="No orders in this view"
                                description="Change the filters or enter an order from the prescriber’s source."
                            />
                        )}
                        <LaravelPagination
                            links={props.orders.links}
                            lastPage={props.orders.last_page}
                        />
                        <p className="text-caption">
                            <Link
                                href="/emar/prescriptions/legacy"
                                className="underline"
                            >
                                Open supply and dispensing records
                            </Link>
                        </p>
                    </>
                )}
                {view === 'covert' &&
                    (covert.length ? (
                        <EntityTable
                            rows={covert}
                            rowKey={(record) => record.id}
                            identityLabel="Medicine"
                            identity={(record) => ({
                                icon: ShieldCheck,
                                name: record.medication.name,
                                subline: `${record.client.first_name} ${record.client.last_name}`,
                            })}
                            columns={[
                                {
                                    key: 'gp',
                                    label: 'Prescriber',
                                    width: '1fr',
                                    cell: (record) => (
                                        <span className="text-sm">
                                            {record.authorised_by_name}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'review',
                                    label: 'Review by',
                                    width: '1fr',
                                    cell: (record) => (
                                        <span className="text-sm">
                                            {formatDateOnly(record.review_date)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'state',
                                    label: 'State',
                                    width: '1fr',
                                    cell: (record) => (
                                        <StatusBadge
                                            variant={
                                                record.status === 'active'
                                                    ? 'warning'
                                                    : 'neutral'
                                            }
                                        >
                                            {record.status === 'active'
                                                ? 'Active — review required'
                                                : 'Stopped'}
                                        </StatusBadge>
                                    ),
                                },
                            ]}
                            actionsFor={covertActions}
                            onOpen={(record) =>
                                openOrder(record.client_medication_id)
                            }
                            onRowContextMenu={covertContext.open}
                        />
                    ) : (
                        <EmptyState
                            icon={ShieldCheck}
                            title="No covert authorisations"
                            description="Authorise from an order after recording the required assessments and signed evidence."
                        />
                    ))}
                {view === 'reconciliation' &&
                    (reconciliations.length ? (
                        <EntityTable
                            rows={reconciliations}
                            rowKey={(record) => record.id}
                            identityLabel="Person"
                            identity={(record) => ({
                                icon: ArrowLeftRight,
                                name: `${record.client.first_name} ${record.client.last_name}`,
                                subline: record.reason.replaceAll('_', ' '),
                            })}
                            columns={[
                                {
                                    key: 'medicines',
                                    label: 'Medicines',
                                    width: '1fr',
                                    cell: (record) => (
                                        <span className="text-sm">
                                            {record.items.length}
                                            {record.restricted_medicines
                                                ? ' visible · restricted items'
                                                : ' to match'}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'support',
                                    label: 'Support',
                                    width: '1fr',
                                    cell: (record) => (
                                        <span className="text-caption">
                                            {record.support_reassessment_required
                                                ? 'Reassessment required'
                                                : 'Changes not yet applied'}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'state',
                                    label: 'State',
                                    width: '1.2fr',
                                    cell: (record) => (
                                        <StatusBadge
                                            variant={
                                                record.signed_off_at
                                                    ? 'success'
                                                    : 'warning'
                                            }
                                        >
                                            {record.signed_off_at
                                                ? 'Signed off'
                                                : record.status ===
                                                    'changes_applied'
                                                  ? 'Ready to sign off'
                                                  : 'Matching medicines'}
                                        </StatusBadge>
                                    ),
                                },
                            ]}
                            actionsFor={reconciliationActions}
                            onOpen={(record) =>
                                setModal({ type: 'reconciliation', record })
                            }
                            onRowContextMenu={reconciliationContext.open}
                        />
                    ) : (
                        <EmptyState
                            icon={ArrowLeftRight}
                            title="No reconciliations"
                            description="Start one when medicines need to be compared during a transition of care."
                        />
                    ))}
                {context.ctx && (
                    <EntityContextMenu
                        {...context.ctx}
                        title={context.ctx.record.name}
                        items={actionsFor(context.ctx.record)}
                        onClose={context.close}
                    />
                )}
                {covertContext.ctx && (
                    <EntityContextMenu
                        {...covertContext.ctx}
                        title={covertContext.ctx.record.medication.name}
                        items={covertActions(covertContext.ctx.record)}
                        onClose={covertContext.close}
                    />
                )}
                {reconciliationContext.ctx && (
                    <EntityContextMenu
                        {...reconciliationContext.ctx}
                        title="Reconciliation"
                        items={reconciliationActions(
                            reconciliationContext.ctx.record,
                        )}
                        onClose={reconciliationContext.close}
                    />
                )}
                {modal?.type === 'opening' && (
                    <WizardShell
                        open
                        onClose={close}
                        title="Medication order"
                        description="Opening the current record and its evidence."
                        railIcon={History}
                        railTitle="Order evidence"
                        railSub="Current record"
                        steps={[
                            { key: 'open', label: 'Open order', icon: Eye },
                        ]}
                        stepIndex={0}
                        onStepClick={() => {}}
                        footerEnd={
                            <Button variant="outline" onClick={close}>
                                Close
                            </Button>
                        }
                    >
                        <Note>
                            {modal.error ??
                                'Loading the order and its versions…'}
                        </Note>
                        {modal.error && (
                            <Button
                                variant="outline"
                                onClick={() =>
                                    openOrder(
                                        modal.id,
                                        modal.action,
                                        modal.review,
                                    )
                                }
                            >
                                Try again
                            </Button>
                        )}
                    </WizardShell>
                )}
                {modal?.type === 'new' && (
                    <OrderEntry
                        clients={props.clients}
                        me={props.me.id}
                        canControlled={props.can.controlled_record}
                        existing={props.orders.data}
                        prefillClientId={modal.clientId}
                        review={modal.review}
                        onUseExisting={(order) => openOrder(order.id, 'entry')}
                        onClose={close}
                    />
                )}
                {modal?.type === 'entry' && (
                    <OrderEntry
                        clients={props.clients}
                        me={props.me.id}
                        canControlled={props.can.controlled_record}
                        existing={props.orders.data}
                        order={modal.detail.summary}
                        detail={modal.detail}
                        review={modal.review}
                        onClose={close}
                    />
                )}
                {modal?.type === 'detail' && (
                    <OrderDetail
                        order={modal.detail.summary}
                        detail={modal.detail}
                        me={props.me.id}
                        canManage={props.can.manage}
                        review={modal.review}
                        initialAction={
                            modal.action === 'entry' ? 'view' : modal.action
                        }
                        onEnter={() =>
                            setModal({
                                ...modal,
                                type: 'entry',
                                action: 'entry',
                            })
                        }
                        onClose={close}
                    />
                )}
                {modal?.type === 'start_reconciliation' && (
                    <StartReconciliation
                        clients={props.clients}
                        stays={props.respite_stays}
                        canControlled={props.can.controlled_record}
                        onClose={close}
                    />
                )}
                {modal?.type === 'reconciliation' && (
                    <ReconcileMedicines
                        record={modal.record}
                        orders={props.orders.data}
                        onEnter={enterFromReconciliation}
                        onClose={close}
                    />
                )}
                {modal?.type === 'covert' && (
                    <CovertWizard
                        orderId={modal.orderId}
                        medicine={modal.medicine}
                        person={modal.person}
                        existing={modal.existing}
                        reviewDefault={props.review_default}
                        onClose={close}
                        onStop={() =>
                            modal.existing &&
                            setModal({ type: 'revoke', record: modal.existing })
                        }
                    />
                )}
                {modal?.type === 'revoke' && (
                    <StopCovert record={modal.record} onClose={close} />
                )}
            </div>
        </AppLayout>
    );
}
