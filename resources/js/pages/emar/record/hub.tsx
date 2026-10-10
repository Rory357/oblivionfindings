import { MarReportDialog } from '@/components/clients/profile/mar-day/report-dialog';
import { AdministrationFollowupDialog } from '@/components/emar/followups/administration-followup-dialog';
import { MedicationJourneyReturn } from '@/components/emar/medication-journey-return';
import { DosePicker } from '@/components/emar/record/record-dose-launch';
import { RecordTable } from '@/components/emar/record/record-table';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { EmptyValue, ProgressValue } from '@/components/lists/entity-cells';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime, formatTime } from '@/lib/datetime';
import { emarScopedHref } from '@/lib/emar-navigation';
import {
    secondPersonDisplay,
    type SecondPersonEvidence,
} from '@/lib/medication-second-person';
import { ConnectedServicesMenu } from '@/pages/emar/connected/_entry-points';
import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    CircleHelp,
    ClipboardList,
    History,
    Pill,
    Printer,
    UserRound,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

type View = 'charts' | 'medicines' | 'asneeded';
type Row = SecondPersonEvidence & {
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
    /** EA-111: doses that can't be recorded yet, and why. */
    help?: number;
    help_reason?: string | null;
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
        work?: 'all' | 'due' | 'overdue' | 'help';
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
        links?: { url: string | null; label: string; active: boolean }[];
    };
    meters: {
        people: number;
        medicines: number;
        due: number;
        overdue: number;
        to_check?: number;
        controlled_hidden?: number;
        due_people?: number;
        due_until?: string | null;
        oldest_late?: string | null;
        help?: number;
        recorded?: number;
        so_far?: number;
        as_needed?: number;
        effect_overdue?: number;
    };
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
    const { url: pageUrl } = usePage();
    const [search, setSearch] = useState(props.filters.q);
    useEffect(() => setSearch(props.filters.q), [props.filters.q]);
    const [effectDose, setEffectDose] = useState<number | null>(null);
    const [dosePerson, setDosePerson] = useState<{
        id: number;
        name: string;
    } | null>(null);
    const [printPerson, setPrintPerson] = useState<{
        id: number;
        name: string;
    } | null>(null);
    const m = props.meters;
    const visit = (
        extra: Record<string, string | number | null>,
        view = props.view,
    ) =>
        router.get(
            PATHS[view],
            {
                ...props.filters,
                ...(view !== props.view
                    ? { q: '', work: 'all', page: null }
                    : {}),
                // EA-108 (Lane C8): the MAR charts tab is everyone's charts;
                // it never carries one person's filter back into their record.
                ...(view === 'charts' && view !== props.view
                    ? { client_id: null }
                    : {}),
                ...extra,
            },
            { preserveScroll: true },
        );
    // Today's charts, filtered by the Show choice.
    const charts = (work: 'all' | 'due' | 'overdue' | 'help') =>
        visit({ date: props.today, work, q: '', page: null }, 'charts');
    const title = RAIL.find((item) => item.key === props.view)!.label;
    const chartActions = (row: Row) => [
        {
            label: `Open ${row.person}’s medication record`,
            icon: ClipboardList,
            onClick: () => router.visit(row.href),
        },
        ...(row.can_record && ((row.due ?? 0) > 0 || (row.overdue ?? 0) > 0)
            ? [
                  {
                      label: 'Record a dose',
                      icon: Pill,
                      onClick: () =>
                          setDosePerson({
                              id: row.client_id,
                              name: row.person,
                          }),
                  },
              ]
            : []),
        ...(row.help
            ? [
                  {
                      label: 'Why can’t these be recorded?',
                      icon: CircleHelp,
                      onClick: () =>
                          toast.info(
                              `${row.help_reason ?? 'These doses can’t be recorded yet'}. Open the record to see who can unblock it.`,
                          ),
                  },
              ]
            : []),
        { separator: true },
        {
            label: 'Open the client profile',
            icon: UserRound,
            onClick: () =>
                router.visit(`/operations/clients/${row.client_id}?tab=mar`),
        },
        ...(props.can_report
            ? [
                  {
                      label: 'Print MAR',
                      icon: Printer,
                      onClick: () =>
                          setPrintPerson({
                              id: row.client_id,
                              name: row.person,
                          }),
                  },
              ]
            : []),
    ];
    const rows = props.page.data;
    // EA-108: a person filter is always shown, and removable.
    const personFilterName =
        rows.find((row) => row.client_id === props.filters.client_id)?.person ??
        'One person';
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
                    actions={
                        <>
                            <MedicationJourneyReturn />
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
                                    ariaLabel="Search MAR and medicines"
                                />
                            </form>
                            <ConnectedServicesMenu />
                        </>
                    }
                    meters={
                        <>
                            {/* EA-111 (Lane C9): the approved P02 meters, for today. */}
                            <PageHeaderMeterBlock
                                label="Due now"
                                onClick={() => charts('due')}
                                ariaLabel="View people with doses due now"
                            >
                                <PageHeaderMeterBig>{m.due}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {m.due
                                        ? `${m.due_people ?? 0} ${m.due_people === 1 ? 'person' : 'people'}${m.due_until ? ` · until ${formatTime(m.due_until)}` : ''}`
                                        : 'Nothing due right now'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Late"
                                tone={m.overdue ? 'warning' : 'brand'}
                                onClick={() => charts('overdue')}
                                ariaLabel="View people with late doses"
                            >
                                <PageHeaderMeterBig>
                                    {m.overdue}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {m.overdue && m.oldest_late
                                        ? `Oldest due ${formatTime(m.oldest_late)}`
                                        : 'None late'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Needs help"
                                tone={m.help ? 'critical' : 'brand'}
                                onClick={() => charts('help')}
                                ariaLabel="View doses that can’t be recorded yet"
                            >
                                <PageHeaderMeterBig>
                                    {m.help ?? 0}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {m.help
                                        ? 'Blocked — see why'
                                        : 'Nothing blocked'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Recorded today"
                                value={
                                    m.so_far
                                        ? `${m.recorded ?? 0}/${m.so_far}`
                                        : undefined
                                }
                                onClick={() => charts('all')}
                                ariaLabel="View today’s charts"
                            >
                                {m.so_far ? (
                                    <PageHeaderMeterDonut
                                        percent={
                                            (100 * (m.recorded ?? 0)) / m.so_far
                                        }
                                        caption="of doses due so far"
                                    />
                                ) : (
                                    <>
                                        <PageHeaderMeterBig>
                                            n/a
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            No doses due yet
                                        </PageHeaderMeterCaption>
                                    </>
                                )}
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Medicines"
                                onClick={() =>
                                    visit({ status: 'current' }, 'medicines')
                                }
                                ariaLabel="View medicines"
                            >
                                <PageHeaderMeterBig>
                                    {m.medicines}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {m.controlled_hidden
                                        ? `+${m.controlled_hidden} controlled hidden`
                                        : m.to_check
                                          ? `${m.to_check} ${m.to_check === 1 ? 'order' : 'orders'} to check`
                                          : 'All checked'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="As needed · 24 h"
                                onClick={() => visit({}, 'asneeded')}
                                ariaLabel="View as-needed history"
                            >
                                <PageHeaderMeterBig>
                                    {m.as_needed ?? 0}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {m.effect_overdue
                                        ? `${m.effect_overdue} effect ${m.effect_overdue === 1 ? 'check' : 'checks'} overdue`
                                        : 'No effect checks overdue'}
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
                            {props.view === 'charts' && (
                                <PageHeaderFilterSelect
                                    label="Show"
                                    value={props.filters.work ?? 'all'}
                                    allValue="all"
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'Everyone with doses',
                                        },
                                        { value: 'due', label: 'Due now' },
                                        { value: 'overdue', label: 'Late' },
                                        { value: 'help', label: 'Needs help' },
                                    ]}
                                    onChange={(work) =>
                                        visit({ work, page: null })
                                    }
                                />
                            )}
                            <DatePicker
                                compact
                                id="medication-hub-day"
                                label="NZ calendar day"
                                value={props.filters.date}
                                onChange={(date) => {
                                    if (date) visit({ date });
                                }}
                                trigger={
                                    <PageHeaderFilterButton
                                        id="medication-hub-day"
                                        icon={CalendarDays}
                                        className="shrink-0"
                                        aria-label={`NZ calendar day: ${formatDateOnly(props.filters.date)}`}
                                    >
                                        Day ·{' '}
                                        {formatDateOnly(props.filters.date)}
                                    </PageHeaderFilterButton>
                                }
                            />
                            {props.filters.client_id !== null && (
                                <PageHeaderFilterButton
                                    active
                                    icon={X}
                                    className="shrink-0"
                                    aria-label={`Show everyone, not only ${personFilterName}`}
                                    onClick={() => visit({ client_id: null })}
                                >
                                    {personFilterName}
                                </PageHeaderFilterButton>
                            )}
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
                            <PageHeaderMeterCaption>
                                As at {formatTime(props.as_at)} ·
                                Pacific/Auckland
                            </PageHeaderMeterCaption>
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={RAIL}
                            value={props.view}
                            ariaLabel="MAR and medicines views"
                            onSelect={(view) =>
                                view === 'selfadmin'
                                    ? router.visit(
                                          emarScopedHref(
                                              PATHS.selfadmin,
                                              pageUrl,
                                          ),
                                      )
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
                    {props.view === 'charts' && !props.coverage.complete && (
                        <p className="text-subtle mb-4" role="status">
                            {props.coverage.notice ??
                                'Scheduled-dose coverage is not available for this day. Open the person’s record for retained dose history.'}
                        </p>
                    )}
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
                        <RecordTable
                            rows={rows}
                            rowKey={(row) => row.key}
                            identityLabel={
                                props.view === 'charts' ? 'Person' : 'Medicine'
                            }
                            minWidth={props.view === 'charts' ? 850 : 1000}
                            rowHeight="content"
                            wrapIdentity
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
                            hrefFor={(row) => row.href}
                            onOpen={(row) => router.visit(row.href)}
                            columns={
                                props.view === 'charts'
                                    ? [
                                          {
                                              key: 'due',
                                              label: 'Due now',
                                              width: '0.7fr',
                                              cell: (row) =>
                                                  row.due ? (
                                                      <StatusBadge variant="neutral">
                                                          {row.due}
                                                      </StatusBadge>
                                                  ) : (
                                                      <EmptyValue />
                                                  ),
                                          },
                                          {
                                              key: 'late',
                                              label: 'Late',
                                              width: '0.6fr',
                                              cell: (row) =>
                                                  row.overdue ? (
                                                      <StatusBadge variant="warning">
                                                          {row.overdue}
                                                      </StatusBadge>
                                                  ) : (
                                                      <EmptyValue />
                                                  ),
                                          },
                                          {
                                              key: 'help',
                                              label: 'Needs help',
                                              width: '1.7fr',
                                              cell: (row) =>
                                                  row.help ? (
                                                      <span className="flex items-center gap-2">
                                                          <StatusBadge variant="critical">
                                                              {row.help}
                                                          </StatusBadge>
                                                          <span className="text-caption whitespace-normal">
                                                              {row.help_reason}
                                                          </span>
                                                      </span>
                                                  ) : (
                                                      <EmptyValue />
                                                  ),
                                          },
                                          {
                                              key: 'recorded',
                                              label:
                                                  props.filters.date ===
                                                  props.today
                                                      ? 'Recorded today'
                                                      : 'Recorded',
                                              width: '1.1fr',
                                              cell: (row) =>
                                                  row.so_far ? (
                                                      <ProgressValue
                                                          percent={
                                                              (100 *
                                                                  (row.recorded ??
                                                                      0)) /
                                                              row.so_far
                                                          }
                                                          tone={
                                                              row.recorded ===
                                                              row.so_far
                                                                  ? 'success'
                                                                  : 'brand'
                                                          }
                                                      >
                                                          {row.recorded ?? 0} of{' '}
                                                          {row.so_far}
                                                      </ProgressValue>
                                                  ) : (
                                                      <span className="text-caption text-muted-foreground">
                                                          n/a — none due yet
                                                      </span>
                                                  ),
                                          },
                                          {
                                              key: 'next',
                                              label: 'Next due',
                                              width: '1.1fr',
                                              cell: (row) =>
                                                  row.next ?? <EmptyValue />,
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
                                                key: 'second-person',
                                                label: 'Second-person check',
                                                width: '1.5fr',
                                                cell: (row) => {
                                                    const check =
                                                        secondPersonDisplay(
                                                            row,
                                                        );
                                                    return check ? (
                                                        <span>
                                                            <StatusBadge
                                                                variant={
                                                                    check.tone
                                                                }
                                                            >
                                                                {check.label}
                                                            </StatusBadge>
                                                            <span className="text-caption mt-1 block">
                                                                {check.detail}
                                                            </span>
                                                        </span>
                                                    ) : (
                                                        '—'
                                                    );
                                                },
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
                            actionsFor={(row) =>
                                props.view === 'charts'
                                    ? chartActions(row)
                                    : [
                                          {
                                              label: 'Open medication record',
                                              icon: ClipboardList,
                                              onClick: () =>
                                                  router.visit(row.href),
                                          },
                                          ...(props.view === 'asneeded' &&
                                          row.can_record
                                              ? [
                                                    {
                                                        label: 'Record effect check',
                                                        icon: History,
                                                        onClick: () =>
                                                            setEffectDose(
                                                                row.id,
                                                            ),
                                                    },
                                                ]
                                              : []),
                                      ]
                            }
                        />
                    ) : (
                        <EmptyState
                            icon={Pill}
                            title="No medication records match"
                            description="Change the house, date or search to see permitted medication work."
                        />
                    )}
                    {/* EA-207 (Lane C9): the shared paginator, filters kept. */}
                    <LaravelPagination
                        className="mt-4"
                        links={props.page.links ?? []}
                        lastPage={props.page.last_page}
                        preserveScroll
                    />
                </Card>
            </div>
            {dosePerson && (
                <DosePicker
                    key={dosePerson.id}
                    clientId={dosePerson.id}
                    personName={dosePerson.name}
                    asNeeded={false}
                    onClose={() => setDosePerson(null)}
                    returnFocus={() => null}
                />
            )}
            {printPerson && (
                <MarReportDialog
                    open
                    onClose={() => setPrintPerson(null)}
                    clientId={printPerson.id}
                    personName={printPerson.name}
                    today={props.today}
                    controlledLeftOut={props.controlled_left_out}
                />
            )}
            {effectDose !== null && (
                <AdministrationFollowupDialog
                    key={effectDose}
                    administrationId={effectDose}
                    onClose={() => setEffectDose(null)}
                />
            )}
        </AppLayout>
    );
}
