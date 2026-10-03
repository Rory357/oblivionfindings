import { RecordDoseLaunch } from '@/components/emar/record/record-dose-launch';
import { AdministrationFollowupDialog } from '@/components/emar/followups/administration-followup-dialog';
import { MedicationExportButton } from '@/components/emar/medication-export-button';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { EntityTable } from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { ClipboardList, History, Pill, Users } from 'lucide-react';
import { useState } from 'react';

type View = 'charts' | 'medicines' | 'asneeded';
type Row = {
    key: string;
    id: number;
    client_id: number;
    person: string;
    house: string | null;
    href: string;
    name?: string;
    dosage?: string | null;
    route?: string | null;
    when?: string;
    support?: string;
    state?: string;
    due?: number;
    overdue?: number;
    recorded?: number;
    so_far?: number;
    waiting?: number;
    next?: string | null;
    at?: string | null;
    by?: string | null;
    dose?: string | null;
    reason?: string | null;
    effect?: string | null;
    effect_status?: string;
    can_record?: boolean;
};
type Props = {
    view: View;
    filters: {
        q: string;
        site_id: number | null;
        client_id: number | null;
        date: string;
        status: string;
        range: number;
    };
    today: string;
    as_at: string;
    sites: { id: number; name: string }[];
    page: {
        data: Row[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
    };
    meters: { people: number; medicines: number; due: number; overdue: number };
    controlled_left_out: boolean;
    can_report: boolean;
    coverage: { complete: boolean; notice: string | null };
};
const PATHS = {
    charts: '/emar/mar',
    medicines: '/emar/medications',
    asneeded: '/emar/prn',
    selfadmin: '/emar/self-admin',
};
const RAIL = [
    { key: 'charts', label: 'MAR charts', icon: ClipboardList },
    { key: 'medicines', label: 'Medicines', icon: Pill },
    { key: 'asneeded', label: 'As-needed history', icon: History },
    { key: 'selfadmin', label: 'Support & self-administration', icon: Users },
];

export default function MedicationRecordHub(props: Props) {
    const breadcrumbs = useEmarBreadcrumbs();
    const [search, setSearch] = useState(props.filters.q);
    const [effectDose, setEffectDose] = useState<number | null>(null);
    const visit = (
        extra: Record<string, string | number | null>,
        view = props.view,
    ) =>
        router.get(
            PATHS[view],
            { ...props.filters, ...extra },
            { preserveScroll: true },
        );
    const title = RAIL.find((item) => item.key === props.view)!.label;
    const rows = props.page.data;
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${title} · MAR & medicines`} />
            <div className="flex min-w-0 flex-col gap-5">
                <PageHeader
                    variant="index"
                    icon={Pill}
                    title="MAR & medicines"
                    wrapTitle
                    subline="Open a person’s medication record from their chart, medicine or as-needed dose."
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="People with medicines"
                                onClick={() => visit({}, 'charts')}
                                ariaLabel="View medication charts"
                            >
                                <PageHeaderMeterBig>
                                    {props.meters.people}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Permitted people · visible medicines
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Current medicines"
                                onClick={() =>
                                    visit({ status: 'current' }, 'medicines')
                                }
                                ariaLabel="View current medicines"
                            >
                                <PageHeaderMeterBig>
                                    {props.meters.medicines}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Scheduled and as needed
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Due now"
                                onClick={() =>
                                    visit({ date: props.today }, 'charts')
                                }
                                ariaLabel="View doses due now"
                            >
                                <PageHeaderMeterBig>
                                    {props.meters.due}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Today · inside their time windows
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Overdue"
                                tone={
                                    props.meters.overdue
                                        ? 'critical'
                                        : 'neutral'
                                }
                                onClick={() =>
                                    visit({ date: props.today }, 'charts')
                                }
                                ariaLabel="View overdue doses"
                            >
                                <PageHeaderMeterBig>
                                    {props.meters.overdue}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Today · visible staff doses
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="House"
                                value={String(props.filters.site_id ?? 'all')}
                                onChange={(value) =>
                                    visit({
                                        site_id: value === 'all' ? null : value,
                                    })
                                }
                                options={[
                                    {
                                        value: 'all',
                                        label: 'All permitted houses',
                                    },
                                    ...props.sites.map((site) => ({
                                        value: String(site.id),
                                        label: site.name,
                                    })),
                                ]}
                            />
                            <DatePicker
                                value={props.filters.date}
                                onChange={(date) => date && visit({ date })}
                            />
                            {props.view === 'medicines' && (
                                <PageHeaderFilterSelect
                                    label="Medicines"
                                    value={props.filters.status}
                                    allValue="current"
                                    onChange={(status) => visit({ status })}
                                    options={[
                                        { value: 'current', label: 'Current' },
                                        {
                                            value: 'stopped',
                                            label: 'Stopped and replaced',
                                        },
                                    ]}
                                />
                            )}
                            {props.view === 'asneeded' && (
                                <PageHeaderFilterSelect
                                    label="Period"
                                    value={String(props.filters.range)}
                                    allValue="30"
                                    onChange={(range) => visit({ range })}
                                    options={[
                                        { value: '7', label: 'Last 7 days' },
                                        { value: '30', label: 'Last 30 days' },
                                        { value: '90', label: 'Last 90 days' },
                                    ]}
                                />
                            )}
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    visit({ q: search });
                                }}
                            >
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder={
                                        props.view === 'charts'
                                            ? 'Find a person…'
                                            : 'Find a person or medicine…'
                                    }
                                />
                            </form>
                            <span className="text-caption">
                                As at {formatTime(props.as_at)} ·
                                Pacific/Auckland
                            </span>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={RAIL}
                            value={props.view}
                            ariaLabel="MAR and medicines views"
                            onSelect={(view) =>
                                view === 'selfadmin'
                                    ? router.visit(PATHS.selfadmin)
                                    : visit({}, view as View)
                            }
                        />
                    }
                />
                {props.controlled_left_out && (
                    <p className="text-caption text-muted-foreground">
                        Controlled medicines are left out of this cross-person
                        list. The counts cover visible medicines. Open an
                        authorised person’s record for their controlled-access
                        notice.
                    </p>
                )}
                <Card className="min-w-0 p-4">
                    {props.view === 'charts' && !props.coverage.complete && <p className="mb-4 text-subtle" role="status">{props.coverage.notice ?? 'Scheduled-dose coverage is not available for this day. Open the person’s record for retained dose history.'}</p>}
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <h2 className="text-section-title">{title}</h2>
                        <p className="text-caption text-muted-foreground">
                            {props.page.from ?? 0}–{props.page.to ?? 0} of{' '}
                            {props.page.total} ·{' '}
                            {props.view === 'asneeded'
                                ? 'newest first'
                                : 'alphabetical'}
                        </p>
                    </div>
                    {rows.length ? (
                        <EntityTable
                            rows={rows}
                            rowKey={(row) => row.key}
                            identityLabel={
                                props.view === 'charts' ? 'Person' : 'Medicine'
                            }
                            minWidth={props.view === 'charts' ? 850 : 1000}
                            rowHeight="content"
                            identity={(row) => ({
                                icon: props.view === 'charts' ? Users : Pill,
                                name:
                                    props.view === 'charts'
                                        ? row.person
                                        : (row.name ?? 'Recorded medicine'),
                                subline:
                                    props.view === 'charts'
                                        ? (row.house ?? 'No house recorded')
                                        : `${row.person} · ${row.house ?? 'No house recorded'}`,
                                extra:
                                    row.state === 'awaiting' ? (
                                        <StatusBadge variant="info">
                                            Waiting for order check
                                        </StatusBadge>
                                    ) : undefined,
                            })}
                            onOpen={(row) => router.visit(row.href)}
                            columns={
                                props.view === 'charts'
                                    ? [
                                          {
                                              key: 'due',
                                              label: 'Due now',
                                              width: '1fr',
                                              cell: (row) => row.due ?? 0,
                                          },
                                          {
                                              key: 'overdue',
                                              label: 'Overdue / not recorded',
                                              width: '1fr',
                                              cell: (row) => row.overdue ?? 0,
                                          },
                                          {
                                              key: 'recorded',
                                              label: 'Staff doses so far',
                                              width: '1.5fr',
                                              cell: (row) => (
                                                  <span>
                                                      {row.recorded ?? 0} of{' '}
                                                      {row.so_far ?? 0} recorded
                                                      {row.waiting
                                                          ? ` · ${row.waiting} waiting for an order check`
                                                          : ''}
                                                  </span>
                                              ),
                                          },
                                          {
                                              key: 'next',
                                              label: 'Next dose',
                                              width: '1fr',
                                              cell: (row) =>
                                                  row.next ?? 'None listed',
                                          },
                                          {
                                              key: 'report',
                                              label: 'Report',
                                              width: '180px',
                                              cell: (row) => props.can_report ? <MedicationExportButton clientId={row.client_id} dateFrom={props.filters.date} dateTo={props.filters.date} /> : <span className="text-caption text-muted-foreground">Report access needed</span>,
                                          },
                                          {
                                              key: 'record',
                                              label: 'Record',
                                              width: '180px',
                                              cell: (row) =>
                                                  row.can_record ? (
                                                      <RecordDoseLaunch
                                                          clientId={
                                                              row.client_id
                                                          }
                                                          personName={
                                                              row.person
                                                          }
                                                      />
                                                  ) : (
                                                      <span className="text-caption text-muted-foreground">
                                                          Open record
                                                      </span>
                                                  ),
                                          },
                                      ]
                                    : props.view === 'medicines'
                                      ? [
                                            {
                                                key: 'dose',
                                                label: 'Dose',
                                                width: '1fr',
                                                cell: (row) =>
                                                    `${row.dosage ?? 'Not recorded'} · ${row.route ?? 'route not recorded'}`,
                                            },
                                            {
                                                key: 'when',
                                                label: 'When',
                                                width: '1.5fr',
                                                cell: (row) =>
                                                    row.when ?? 'As needed',
                                            },
                                            {
                                                key: 'support',
                                                label: 'Staff support',
                                                width: '1fr',
                                                cell: (row) =>
                                                    row.support ?? 'Administer',
                                            },
                                            {
                                                key: 'state',
                                                label: 'Order',
                                                width: '1fr',
                                                cell: (row) => (
                                                    <StatusBadge
                                                        variant={
                                                            row.state ===
                                                            'awaiting'
                                                                ? 'info'
                                                                : 'neutral'
                                                        }
                                                    >
                                                        {row.state ===
                                                        'awaiting'
                                                            ? 'Waiting for order check'
                                                            : (row.state ??
                                                              'Recorded')}
                                                    </StatusBadge>
                                                ),
                                            },
                                        ]
                                      : [
                                            {
                                                key: 'at',
                                                label: 'Given at',
                                                width: '1.5fr',
                                                cell: (row) =>
                                                    formatDateTime(row.at),
                                            },
                                            {
                                                key: 'dose',
                                                label: 'Dose / reason',
                                                width: '2fr',
                                                cell: (row) =>
                                                    `${row.dose ?? 'Not recorded'} · ${row.reason ?? 'reason not recorded'}`,
                                            },
                                            {
                                                key: 'by',
                                                label: 'Recorded by',
                                                width: '1fr',
                                                cell: (row) =>
                                                    row.by ?? 'Not recorded',
                                            },
                                            {
                                                key: 'effect',
                                                label: 'Effect check',
                                                width: '2fr',
                                                cell: (row) => (
                                                    <>
                                                        <StatusBadge
                                                            variant={
                                                                row.effect_status ===
                                                                'recorded'
                                                                    ? 'success'
                                                                    : 'warning'
                                                            }
                                                        >
                                                            {row.effect_status ===
                                                            'recorded'
                                                                ? 'Recorded'
                                                                : 'No effect check recorded'}
                                                        </StatusBadge>
                                                        {row.effect && (
                                                            <p className="text-caption mt-1">
                                                                {row.effect}
                                                            </p>
                                                        )}
                                                    </>
                                                ),
                                            },
                                        ]
                            }
                            actionsFor={(row) => [
                                {
                                    label: 'Open medication record',
                                    icon: ClipboardList,
                                    onClick: () => router.visit(row.href),
                                },
                                ...(props.view === 'asneeded' && row.can_record ? [{ label: 'Record effect check', icon: History, onClick: () => setEffectDose(row.id) }] : []),
                            ]}
                        />
                    ) : (
                        <EmptyState
                            icon={Pill}
                            title="No medication records match"
                            description="Change the house, date or search to see permitted medication work."
                        />
                    )}
                    <div className="mt-4 flex items-center justify-between gap-3">
                        <Button
                            variant="outline"
                            disabled={props.page.current_page <= 1}
                            onClick={() =>
                                visit({ page: props.page.current_page - 1 })
                            }
                        >
                            Previous
                        </Button>
                        <span className="text-caption">
                            Page {props.page.current_page} of{' '}
                            {props.page.last_page}
                        </span>
                        <Button
                            variant="outline"
                            disabled={
                                props.page.current_page >= props.page.last_page
                            }
                            onClick={() =>
                                visit({ page: props.page.current_page + 1 })
                            }
                        >
                            Next
                        </Button>
                    </div>
                </Card>
            </div>
            {effectDose !== null && <AdministrationFollowupDialog key={effectDose} administrationId={effectDose} onClose={() => setEffectDose(null)} />}
        </AppLayout>
    );
}
