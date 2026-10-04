import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import {
    EntityTable,
    type EntityTableColumn,
} from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    Download,
    Eye,
    FileBarChart,
    FileText,
    Lock,
    ShieldCheck,
    SlidersHorizontal,
    User,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { DowntimePackDialog } from './_downtime-pack';
import { ExportDialog } from './_export-dialog';
import { PersonPicker } from './_person-picker';
import {
    requestJson,
    type ExportOption,
    type Filters,
    type Props,
    type Row,
} from './_types';

const periods = [
    { value: 'today', label: 'Today' },
    { value: 'week', label: 'Last 7 days' },
    { value: 'month', label: 'This month' },
    { value: 'last_month', label: 'Last month' },
    { value: 'custom', label: 'Custom period' },
];
const reportFields: Record<string, [string, string][]> = {
    doses: [
        ['site', 'House'],
        ['due', 'Due'],
        ['given', 'Given'],
        ['refused', 'Refused'],
        ['withheld', 'Withheld'],
        ['missed', 'Recorded missed'],
        ['not_recorded', 'Not recorded'],
        ['away', 'Away'],
        ['given_rate', 'Given rate'],
    ],
    rounds: [
        ['site', 'House'],
        ['date', 'Day'],
        ['status', 'Result'],
        ['due', 'Due'],
        ['recorded', 'Recorded'],
        ['away', 'Away'],
        ['window_ends_at', 'Window ended'],
    ],
    prn: [
        ['site', 'House'],
        ['given', 'Given'],
        ['effect_recorded', 'Effect recorded'],
        ['last_given_at', 'Last given'],
    ],
    syringe_drivers: [
        ['site', 'House'],
        ['commenced_at', 'Started'],
        ['completed_at', 'Completed'],
        ['status', 'Status'],
        ['rate', 'Rate'],
        ['rate_unit', 'Rate unit'],
        ['contents', 'Medicine contents'],
    ],
    controlled: [
        ['date', 'Day'],
        ['medicine', 'Medicine'],
        ['movement', 'Movement'],
        ['quantity', 'Quantity'],
        ['balance', 'Balance'],
        ['witnessed', 'Witnessed'],
    ],
    errors: [
        ['date', 'Day'],
        ['error_type', 'Type'],
        ['reached', 'Reached person'],
        ['harm', 'Harm'],
        ['status', 'Status'],
        ['confirmed_sac', 'Confirmed SAC'],
    ],
    reviews: [
        ['date', 'Due'],
        ['status', 'Status'],
        ['completed_date', 'Completed'],
        ['next_review_date', 'Next review'],
    ],
    stock: [
        ['site', 'House'],
        ['unit', 'Unit'],
        ['on_hand', 'On hand'],
        ['reorder_level', 'Reorder at'],
        ['expiry_date', 'Expires'],
        ['value_on_hand', 'Value'],
    ],
    competency: [
        ['date', 'Assessed'],
        ['status', 'Result'],
        ['expiry_date', 'Expires'],
        ['current', 'Current'],
    ],
};
const meters: Record<string, [string, string, string?][]> = {
    doses: [
        ['due', 'Doses due'],
        ['given', 'Given'],
        ['not_recorded', 'Not recorded'],
        ['away', 'Away', 'Outside the due denominator'],
        ['given_rate', 'Given rate', 'No doses due in this period'],
    ],
    rounds: [
        ['ended', 'Rounds ended'],
        ['on_time', 'On time'],
        ['not_completed', 'Not completed'],
        ['not_started', 'Not started'],
        ['on_time_pct', 'On time', 'No eligible rounds have ended'],
    ],
    prn: [
        ['given', 'As-needed doses'],
        ['effect_recorded', 'Effect recorded'],
        ['effect_pct', 'Effect recorded', 'No as-needed doses were given'],
    ],
    syringe_drivers: [
        ['drivers', 'Drivers'],
        ['running', 'Running'],
        ['completed', 'Completed'],
    ],
    controlled: [
        ['movements', 'Register entries'],
        ['witnessed', 'Witnessed'],
        ['receipts', 'Receipts'],
        ['disposals', 'Disposals'],
    ],
    errors: [
        ['reached', 'Reached person'],
        ['near_misses', 'Near misses'],
        ['with_harm', 'With harm'],
        ['open', 'Open'],
    ],
    reviews: [
        ['due', 'Reviews due'],
        ['done', 'Completed'],
        ['overdue', 'Overdue'],
    ],
    stock: [
        ['lines', 'Stock lines'],
        ['low', 'Low stock'],
        ['expiring', 'Expiring in 30 days'],
    ],
    competency: [
        ['assessed', 'Staff assessed'],
        ['current', 'Current'],
        ['current_pct', 'Current', 'No staff assessments in this scope'],
    ],
};
const auditFields: [string, string][] = [
    ['occurred_at', 'When'],
    ['person', 'Person'],
    ['actor', 'Recorded by'],
    ['kind', 'Event'],
    ['sequence', 'Chain sequence'],
];
function text(value: unknown): string {
    return value === null || value === undefined
        ? '—'
        : typeof value === 'object'
          ? JSON.stringify(value)
          : String(value);
}
function cell(row: Row, key: string) {
    const value = row[key];
    if (key === 'status') return <StatusBadge status={text(value)} />;
    if (key === 'date' || key.endsWith('_date'))
        return formatDateOnly(typeof value === 'string' ? value : null);
    if (key.endsWith('_at'))
        return formatDateTime(typeof value === 'string' ? value : null);
    if (key.endsWith('_rate') || key.endsWith('_pct'))
        return value === null ? 'Not applicable' : `${text(value)}%`;
    if (['current', 'witnessed'].includes(key)) return value ? 'Yes' : 'No';
    if (key === 'value_on_hand' && value === null) return 'Not configured';
    if (key === 'confirmed_sac') return value ? `SAC ${value}` : '—';
    return text(value);
}

export default function ReportsHub(props: Props) {
    const [downtimeOpen, setDowntimeOpen] = useState(false);
    const [focusedMetric, setFocusedMetric] = useState<string | null>(null);
    const { filters, data, page, finance, can } = props;
    const [search, setSearch] = useState(filters.q),
        [rangeOpen, setRangeOpen] = useState(false),
        [from, setFrom] = useState<string | null>(filters.date_from),
        [to, setTo] = useState<string | null>(filters.date_to),
        [exportOption, setExportOption] = useState<ExportOption | null>(null),
        [online, setOnline] = useState(
            typeof navigator === 'undefined' || navigator.onLine,
        ),
        [detail, setDetail] = useState<Row | null>(null),
        [detailOpen, setDetailOpen] = useState(false),
        [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [verification, setVerification] = useState<{
            intact: boolean;
            events: number;
            broken_at: number | null;
        } | null>(null);
    const context = useEntityContextMenu<Row>();
    useEffect(() => {
        setSearch(filters.q);
        setFrom(filters.date_from);
        setTo(filters.date_to);
    }, [filters.q, filters.date_from, filters.date_to]);
    useEffect(() => {
        const update = () => setOnline(navigator.onLine);
        window.addEventListener('online', update);
        window.addEventListener('offline', update);
        return () => {
            window.removeEventListener('online', update);
            window.removeEventListener('offline', update);
        };
    }, []);
    const visit = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        const query = Object.fromEntries(
            Object.entries(next).filter(
                ([, value]) => value !== null && value !== '',
            ),
        );
        router.get('/emar/reports', query, {
            preserveState: true,
            preserveScroll: true,
        });
    };
    const rows = page?.data ?? [];
    const audit = filters.view === 'audit' && filters.sub !== 'gaps';
    const fields = useMemo(
        () =>
            audit
                ? auditFields
                : filters.view === 'audit'
                  ? ([
                        ['date', 'Day'],
                        ['medicine', 'Medicine'],
                        ['status', 'Status'],
                        ['due_at', 'Due'],
                        ['window_ends_at', 'Window ended'],
                    ] as [string, string][])
                  : (reportFields[filters.report] ?? []),
        [audit, filters.view, filters.report],
    );
    const columns = useMemo<EntityTableColumn<Row>[]>(
        () =>
            fields
                .filter(
                    ([key]) =>
                        !(
                            finance &&
                            ['reorder_level', 'expiry_date'].includes(key)
                        ),
                )
                .map(([key, label]) => ({
                    key,
                    label,
                    width: key.endsWith('_at')
                        ? '150px'
                        : ['medicine', 'actor', 'kind', 'site'].includes(key)
                          ? '1.3fr'
                          : '100px',
                    cell: (row) => (
                        <span
                            className={
                                key === focusedMetric
                                    ? 'text-subtle font-semibold break-words text-primary'
                                    : 'text-subtle break-words'
                            }
                        >
                            {cell(row, key)}
                        </span>
                    ),
                })),
        [fields, finance, focusedMetric],
    );
    const identity = (row: Row) =>
        audit
            ? text(row.summary)
            : typeof row.week === 'string'
              ? `Week starting ${formatDateOnly(row.week)}`
              : text(row.person ?? row.staff ?? row.medicine ?? row.round);
    async function open(row: Row) {
        if (!audit && typeof row.href === 'string') {
            router.visit(row.href);
            return;
        }
        setDetailOpen(true);
        setDetail(null);
        setError('');
        setBusy(true);
        try {
            setDetail(
                audit
                    ? await requestJson(
                          `/emar/reports/events/${row.id}?${new URLSearchParams({ period: 'custom', date_from: filters.date_from, date_to: filters.date_to, ...(filters.site_id ? { site_id: String(filters.site_id) } : {}) })}`,
                      )
                    : row,
            );
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'This record could not be opened.',
            );
        } finally {
            setBusy(false);
        }
    }
    const actionsFor = (row: Row): MenuItem[] => [
        {
            label: audit
                ? 'View event'
                : typeof row.href === 'string'
                  ? 'Open record'
                  : 'View details',
            icon: Eye,
            onClick: () => open(row),
            disabled:
                !online && audit ? 'Reconnect to load this event.' : undefined,
        },
    ];
    async function verify() {
        if (!filters.site_id || !online) return;
        setBusy(true);
        setError('');
        setVerification(null);
        try {
            setVerification(
                await requestJson('/emar/reports/verify', {
                    site_id: filters.site_id,
                }),
            );
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'The chain check could not be completed.',
            );
        } finally {
            setBusy(false);
        }
    }
    const selectedExport = props.exports.find(
        (e) =>
            e.type ===
            (filters.view === 'audit'
                ? 'audit'
                : ['errors', 'stock', 'syringe_drivers'].includes(
                        filters.report,
                    )
                  ? filters.report
                  : 'doses'),
    );
    const meterItems =
        filters.view === 'standard'
            ? (meters[filters.report] ?? [])
            : filters.view === 'audit'
              ? ([
                    ['events', 'Events'],
                    ['exports', 'Exports'],
                    ['doses', 'Dose events'],
                    ['errors', 'Error events'],
                ] as [string, string, string?][])
              : [];
    const heading =
        filters.view === 'audit'
            ? 'Audit trail'
            : filters.view === 'exports'
              ? 'Print & exports'
              : props.reports[filters.report];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Medication', href: '/emar' },
                { title: 'Reports & audit', href: '/emar/reports' },
            ]}
        >
            <Head title="Medication reports & audit" />
            <div className="min-w-0 space-y-5">
                <PageHeader
                    icon={FileBarChart}
                    title="Reports & audit"
                    subline={`${formatDateOnly(filters.date_from)} – ${formatDateOnly(filters.date_to)} · Pacific/Auckland${finance ? ' · Stock only' : ''}`}
                    actions={
                        <>
                            {can.history && filters.view === 'audit' && (
                                <PageHeaderGlassButton
                                    onClick={() =>
                                        router.visit('/emar/reports/history')
                                    }
                                >
                                    Clinical history
                                </PageHeaderGlassButton>
                            )}
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                onKeyDown={(e) =>
                                    e.key === 'Enter' && visit({ q: search })
                                }
                                placeholder="Search this report…"
                                ariaLabel="Search the selected medication report"
                            />
                            {can.verify && filters.view === 'audit' && (
                                <PageHeaderGlassButton
                                    icon={ShieldCheck}
                                    onClick={verify}
                                    disabled={
                                        busy || !online || !filters.site_id
                                    }
                                    title={
                                        filters.site_id
                                            ? 'Check this house’s event chain'
                                            : 'Choose one house to check its event chain'
                                    }
                                >
                                    Check chain
                                </PageHeaderGlassButton>
                            )}
                            {selectedExport?.allowed ? (
                                <PageHeaderPrimaryButton
                                    icon={Download}
                                    onClick={() =>
                                        setExportOption(selectedExport)
                                    }
                                    disabled={!online}
                                >
                                    Make file
                                </PageHeaderPrimaryButton>
                            ) : (
                                <PageHeaderMeterCaption>
                                    {filters.view === 'audit'
                                        ? 'Audit exports: provider managers, coordinators and auditors'
                                        : 'Exports: provider managers and coordinators'}
                                </PageHeaderMeterCaption>
                            )}
                        </>
                    }
                    meters={
                        !props.locked && meterItems.length
                            ? meterItems
                                  .filter(
                                      ([key]) =>
                                          key in data.totals &&
                                          !(
                                              finance &&
                                              data.totals[key] === null
                                          ),
                                  )
                                  .map(([key, label, reason]) => (
                                      <PageHeaderMeterBlock
                                          key={key}
                                          label={label}
                                          onClick={() => {
                                              if (filters.view === 'audit') {
                                                  visit({
                                                      view: 'audit',
                                                      sub:
                                                          key === 'exports'
                                                              ? 'exports'
                                                              : 'events',
                                                      kind: '',
                                                  });
                                              } else {
                                                  setFocusedMetric(key);
                                                  document
                                                      .getElementById(
                                                          'medication-report-breakdown',
                                                      )
                                                      ?.focus();
                                                  document
                                                      .getElementById(
                                                          'medication-report-breakdown',
                                                      )
                                                      ?.scrollIntoView({
                                                          block: 'start',
                                                          behavior: 'smooth',
                                                      });
                                              }
                                          }}
                                      >
                                          {key.endsWith('_pct') ||
                                          key.endsWith('_rate') ? (
                                              data.totals[key] === null ? (
                                                  <>
                                                      <PageHeaderMeterBig>
                                                          Not applicable
                                                      </PageHeaderMeterBig>
                                                      <PageHeaderMeterCaption>
                                                          {reason}
                                                      </PageHeaderMeterCaption>
                                                  </>
                                              ) : (
                                                  <PageHeaderMeterDonut
                                                      percent={
                                                          data.totals[key] ?? 0
                                                      }
                                                      caption={label}
                                                  />
                                              )
                                          ) : (
                                              <>
                                                  <PageHeaderMeterBig>
                                                      {data.totals[
                                                          key
                                                      ]?.toLocaleString(
                                                          'en-NZ',
                                                      ) ?? 'Not applicable'}
                                                  </PageHeaderMeterBig>
                                                  <PageHeaderMeterCaption>
                                                      {reason ||
                                                          'Selected people, houses and period'}
                                                  </PageHeaderMeterCaption>
                                              </>
                                          )}
                                      </PageHeaderMeterBlock>
                                  ))
                            : undefined
                    }
                    filters={
                        <>
                            {filters.view === 'standard' && (
                                <PageHeaderFilterSelect
                                    label="Report"
                                    value={filters.report}
                                    allValue=""
                                    options={Object.entries(props.reports).map(
                                        ([value, label]) => ({ value, label }),
                                    )}
                                    onChange={(report) =>
                                        visit({ report, q: '' })
                                    }
                                />
                            )}
                            {filters.view === 'audit' && (
                                <PageHeaderFilterSelect
                                    label="Audit view"
                                    value={filters.sub}
                                    allValue=""
                                    options={[
                                        { value: 'events', label: 'Events' },
                                        {
                                            value: 'gaps',
                                            label: 'Not recorded',
                                        },
                                        {
                                            value: 'exports',
                                            label: 'Export log',
                                        },
                                    ]}
                                    onChange={(sub) =>
                                        visit({ sub, kind: '', q: '' })
                                    }
                                />
                            )}
                            <PageHeaderFilterSelect
                                label="Period"
                                value={filters.period}
                                allValue=""
                                options={periods}
                                onChange={(period) =>
                                    period === 'custom'
                                        ? setRangeOpen(true)
                                        : visit({ period })
                                }
                            />
                            <Popover
                                open={rangeOpen}
                                onOpenChange={setRangeOpen}
                            >
                                <PopoverTrigger asChild>
                                    <PageHeaderFilterButton
                                        icon={CalendarDays}
                                        active={filters.period === 'custom'}
                                    >
                                        {formatDateOnly(filters.date_from)} –{' '}
                                        {formatDateOnly(filters.date_to)}
                                    </PageHeaderFilterButton>
                                </PopoverTrigger>
                                <PopoverContent
                                    align="end"
                                    className="w-auto max-w-[90vw]"
                                >
                                    <p className="text-subtle mb-3">
                                        Up to 12 months, ending today or
                                        earlier.
                                    </p>
                                    <LeaveCalendarRange
                                        start={from}
                                        end={to}
                                        onChange={(start, end) => {
                                            setFrom(start);
                                            setTo(end);
                                        }}
                                        required
                                    />
                                    <Button
                                        className="mt-3 w-full"
                                        disabled={!from || !to}
                                        onClick={() => {
                                            if (from && to)
                                                visit({
                                                    period: 'custom',
                                                    date_from: from,
                                                    date_to: to,
                                                });
                                            setRangeOpen(false);
                                        }}
                                    >
                                        Apply period
                                    </Button>
                                </PopoverContent>
                            </Popover>
                            <RecordPicker
                                variant="header"
                                label="House"
                                value={
                                    filters.site_id
                                        ? String(filters.site_id)
                                        : ''
                                }
                                active={filters.site_id !== null}
                                options={[
                                    {
                                        value: '',
                                        label: 'All permitted houses',
                                    },
                                    ...props.sites.map((s) => ({
                                        value: String(s.id),
                                        label: s.name,
                                    })),
                                ]}
                                onChange={(value) =>
                                    visit({
                                        site_id: value ? Number(value) : null,
                                        client_id: null,
                                    })
                                }
                            />
                            {!finance && (
                                <PersonPicker
                                    value={filters.client_id}
                                    onChange={(client_id) =>
                                        visit({ client_id })
                                    }
                                    siteId={filters.site_id}
                                    initial={props.people}
                                    header
                                />
                            )}
                            {filters.view === 'standard' &&
                                filters.report === 'errors' && (
                                    <PageHeaderFilterSelect
                                        label="Reached person"
                                        value={filters.reached ?? ''}
                                        options={[
                                            {
                                                value: '',
                                                label: 'All recorded accounts',
                                            },
                                            {
                                                value: 'yes',
                                                label: 'Reached the person',
                                            },
                                            {
                                                value: 'no',
                                                label: 'Near misses',
                                            },
                                            {
                                                value: 'unknown',
                                                label: 'Not recorded',
                                            },
                                        ]}
                                        onChange={(reached) =>
                                            visit({ reached })
                                        }
                                    />
                                )}
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={[
                                {
                                    key: 'standard',
                                    label: 'Standard reports',
                                    icon: FileBarChart,
                                },
                                {
                                    key: 'builder',
                                    label: 'Report builder',
                                    icon: SlidersHorizontal,
                                },
                                {
                                    key: 'audit',
                                    label: 'Audit trail',
                                    icon: ShieldCheck,
                                },
                                {
                                    key: 'exports',
                                    label: 'Print & exports',
                                    icon: Download,
                                },
                            ]}
                            value={filters.view}
                            onSelect={(key) =>
                                key === 'builder'
                                    ? router.visit('/emar/reports/builder')
                                    : visit({
                                          view: key as Filters['view'],
                                          q: '',
                                      })
                            }
                            ariaLabel="Medication reports views"
                        />
                    }
                />
                {!online && (
                    <p className="text-subtle" role="status">
                        You’re offline. Loaded records remain available;
                        reconnect to refresh or make a file.
                    </p>
                )}
                {error && !detailOpen && (
                    <p
                        className="text-subtle text-status-critical"
                        role="alert"
                    >
                        {error}
                    </p>
                )}
                {verification && (
                    <Card>
                        <CardContent className="space-y-2 pt-5">
                            <StatusBadge
                                variant={
                                    verification.intact ? 'success' : 'critical'
                                }
                            >
                                {verification.intact
                                    ? 'Event chain intact'
                                    : 'Broken link found'}
                            </StatusBadge>
                            <p className="text-subtle">
                                Checked{' '}
                                {verification.events.toLocaleString('en-NZ')}{' '}
                                events
                                {verification.broken_at
                                    ? `; broken link at sequence ${verification.broken_at}`
                                    : ''}
                                . This checks the event chain. It does not
                                verify the accuracy of the clinical account. The
                                check has been recorded.
                            </p>
                        </CardContent>
                    </Card>
                )}
                {props.locked ? (
                    <Card>
                        <CardContent className="flex items-start gap-3 pt-5">
                            <Lock className="size-5 shrink-0" />
                            <div>
                                <h2 className="text-section-title">
                                    {filters.view === 'audit'
                                        ? 'Audit access required'
                                        : 'Controlled medicine access required'}
                                </h2>
                                <p className="text-subtle">{props.locked}</p>
                            </div>
                        </CardContent>
                    </Card>
                ) : filters.view === 'exports' ? (
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {props.exports.map((option) => (
                            <Card key={option.type}>
                                <CardHeader>
                                    <CardTitle className="text-section-title">
                                        {option.label}
                                    </CardTitle>
                                    <CardDescription>
                                        {option.format} · {option.description}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Button
                                        variant="outline"
                                        onClick={() => setExportOption(option)}
                                        disabled={!option.allowed || !online}
                                    >
                                        <Download className="size-4" />
                                        Make file
                                    </Button>
                                    {!option.allowed && (
                                        <p className="text-caption mt-2">
                                            Export access is required for this
                                            file.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        ))}
                        {props.downtime_pack && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-section-title">
                                        Downtime pack
                                    </CardTitle>
                                    <CardDescription>
                                        PDF · One house, today or tomorrow in
                                        New Zealand.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Button
                                        variant="outline"
                                        onClick={() => setDowntimeOpen(true)}
                                        disabled={
                                            !props.downtime_pack.allowed ||
                                            !online
                                        }
                                    >
                                        <Download className="size-4" />
                                        Make pack
                                    </Button>
                                    {!props.downtime_pack.allowed && (
                                        <p className="text-caption mt-2">
                                            Export access is required for this
                                            pack.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                ) : (
                    <section
                        id="medication-report-breakdown"
                        tabIndex={-1}
                        aria-label={heading}
                        className="scroll-mt-6 space-y-3 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        {focusedMetric && (
                            <p role="status" className="text-subtle">
                                {
                                    meterItems.find(
                                        ([key]) => key === focusedMetric,
                                    )?.[1]
                                }
                                :{' '}
                                {data.totals[focusedMetric]?.toLocaleString(
                                    'en-NZ',
                                ) ?? 'Not applicable'}
                                . The breakdown below keeps your selected
                                people, houses and period.
                            </p>
                        )}
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h2 className="text-section-title">
                                    {heading}
                                </h2>
                                {data.notice && (
                                    <p className="text-subtle">{data.notice}</p>
                                )}
                            </div>
                            <p className="text-caption">
                                {page?.total.toLocaleString('en-NZ') ?? 0}{' '}
                                records · As at {formatDateTime(props.as_at)}
                            </p>
                        </div>
                        {rows.length ? (
                            <EntityTable
                                rows={rows}
                                rowKey={(row) =>
                                    text(
                                        row.reference ??
                                            row.id ??
                                            row.client_id ??
                                            row.staff ??
                                            `${row.medicine}|${row.site}|${row.unit}`,
                                    )
                                }
                                identity={(row) => ({
                                    icon: audit ? FileText : User,
                                    name: identity(row),
                                    subline: row.reason
                                        ? text(row.reason)
                                        : row.reference
                                          ? text(row.reference)
                                          : undefined,
                                })}
                                identityLabel={
                                    audit
                                        ? 'Recorded event'
                                        : filters.report === 'stock'
                                          ? 'Medicine'
                                          : filters.report === 'rounds'
                                            ? 'Round'
                                            : filters.report === 'competency'
                                              ? 'Staff member'
                                              : 'Person'
                                }
                                columns={columns}
                                actionsFor={actionsFor}
                                onOpen={open}
                                onRowContextMenu={context.open}
                                footerRows={
                                    filters.view === 'standard' &&
                                    filters.report === 'doses'
                                        ? [
                                              {
                                                  key: 'scope-total',
                                                  label: 'Selected scope total',
                                                  cells: Object.fromEntries(
                                                      columns
                                                          .filter(
                                                              (column) =>
                                                                  column.key in
                                                                  data.totals,
                                                          )
                                                          .map((column) => [
                                                              column.key,
                                                              cell(
                                                                  data.totals,
                                                                  column.key,
                                                              ),
                                                          ]),
                                                  ),
                                                  tone: 'strong',
                                              },
                                          ]
                                        : undefined
                                }
                                rowHeight="content"
                                minWidth={fields.length > 5 ? 1120 : 800}
                            />
                        ) : (
                            <Card>
                                <CardContent className="py-8">
                                    <h3 className="text-section-title">
                                        No records in this scope
                                    </h3>
                                    <p className="text-subtle mt-1">
                                        Try a different period, house or person.
                                        No result has been estimated.
                                    </p>
                                </CardContent>
                            </Card>
                        )}
                        {page && (
                            <LaravelPagination
                                links={page.links}
                                lastPage={page.last_page}
                                preserveScroll
                            />
                        )}
                        {filters.view === 'standard' &&
                            filters.report === 'doses' &&
                            data.weeks &&
                            data.weeks.length > 0 && (
                                <>
                                    <h3 className="text-section-title">
                                        Week by week
                                    </h3>
                                    <p className="text-subtle">
                                        Monday to Sunday, counting only days
                                        within the selected period.
                                    </p>
                                    <EntityTable
                                        rows={data.weeks}
                                        rowKey={(row) => text(row.week)}
                                        identity={(row) => ({
                                            icon: CalendarDays,
                                            name: formatDateOnly(
                                                typeof row.week === 'string'
                                                    ? row.week
                                                    : null,
                                            ),
                                        })}
                                        identityLabel="Week starting"
                                        columns={columns.filter(
                                            (column) => column.key !== 'site',
                                        )}
                                        actionsFor={actionsFor}
                                        onOpen={open}
                                        onRowContextMenu={context.open}
                                        minWidth={1120}
                                    />
                                </>
                            )}
                    </section>
                )}
            </div>
            {context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={identity(context.ctx.record)}
                    items={actionsFor(context.ctx.record)}
                    onClose={context.close}
                />
            )}
            {exportOption && (
                <ExportDialog
                    key={exportOption.type}
                    option={exportOption}
                    props={props}
                    online={online}
                    onClose={() => setExportOption(null)}
                />
            )}
            {downtimeOpen && props.downtime_pack && (
                <DowntimePackDialog
                    props={props}
                    pack={props.downtime_pack}
                    online={online}
                    onClose={() => setDowntimeOpen(false)}
                />
            )}
            {detailOpen && (
                <SettingsModal
                    title="Recorded details"
                    description="Times use Pacific/Auckland. Recorded facts remain separate from clinical interpretation."
                    width={720}
                    onClose={() => setDetailOpen(false)}
                >
                    {busy ? (
                        <p role="status">Loading event…</p>
                    ) : error ? (
                        <p role="alert" className="text-status-critical">
                            {error}
                        </p>
                    ) : (
                        detail && (
                            <dl className="space-y-3">
                                {Object.entries(detail)
                                    .filter(
                                        ([key]) =>
                                            ![
                                                'href',
                                                'subject_id',
                                                'subject_type',
                                            ].includes(key),
                                    )
                                    .map(([key, value]) => (
                                        <div
                                            key={key}
                                            className="border-b border-border pb-2"
                                        >
                                            <dt className="text-caption">
                                                {key.replaceAll('_', ' ')}
                                            </dt>
                                            <dd className="text-subtle mt-1 break-all">
                                                {key.endsWith('_at')
                                                    ? formatDateTime(
                                                          typeof value ===
                                                              'string'
                                                              ? value
                                                              : null,
                                                      )
                                                    : text(value)}
                                            </dd>
                                        </div>
                                    ))}
                            </dl>
                        )
                    )}
                </SettingsModal>
            )}
        </AppLayout>
    );
}
