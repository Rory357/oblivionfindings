import { HistoricalAuditExportDialog } from '@/components/emar/historical-audit-export-dialog';
import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import {
    EntityTable,
    type EntityTableColumn,
} from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
} from '@/components/page/page-header';
import { RecordPicker } from '@/components/people-locations/record-picker';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { CalendarDays, Download, History } from 'lucide-react';
import { useEffect, useState } from 'react';

type Log = {
    id: number;
    created_at: string;
    action: string;
    auditable_type: string;
    auditable_id: number;
    client: { id: number; name: string } | null;
    user: { id: number; name: string } | null;
    meta: Record<string, unknown>;
};
type Props = {
    logs: Log[];
    clients: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    filters: {
        site_id: string | null;
        client_id: string | null;
        user_id: string | null;
        period: string;
        date_from: string;
        date_to: string;
    };
    can_export_history: boolean;
    export_purposes: Record<string, string>;
};
const columns: EntityTableColumn<Log>[] = [
    {
        key: 'created_at',
        label: 'When',
        width: '160px',
        cell: (row) => formatDateTime(row.created_at),
    },
    {
        key: 'user',
        label: 'Recorded by',
        width: '1fr',
        cell: (row) => row.user?.name ?? 'Not recorded',
    },
    {
        key: 'record',
        label: 'Record',
        width: '1fr',
        cell: recordLabel,
    },
    {
        key: 'meta',
        label: 'Changed fields',
        width: '1.4fr',
        cell: changedFields,
    },
];
function words(value: string): string {
    const label = value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/[_.-]+/g, ' ')
        .trim();
    return label ? label[0].toUpperCase() + label.slice(1) : 'Not recorded';
}
function recordLabel(row: Log): string {
    const labels: Record<string, string> = {
        ClientMedication: 'Medicine order',
        ClientMedicationAdministration: 'Dose record',
        ClientControlledDrugEntry: 'Controlled register entry',
        ClientControlledDrugDiscrepancy: 'Controlled discrepancy',
        ClientBreakGlassAccess: 'Emergency access record',
    };
    return `${labels[row.auditable_type] ?? words(row.auditable_type)} ${row.auditable_id}`;
}
function changedFields(row: Log): string {
    const fields = Array.isArray(row.meta.fields)
        ? row.meta.fields
              .filter((field): field is string => typeof field === 'string')
              .map(words)
        : [];
    return fields.length ? fields.join(', ') : 'Fields not recorded';
}
export default function HistoricalChangeLogs(props: Props) {
    const [search, setSearch] = useState('');
    const visibleLogs = props.logs.filter((row) =>
        [
            row.action,
            row.client?.name,
            row.user?.name,
            recordLabel(row),
            changedFields(row),
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(search.trim().toLowerCase()),
    );
    const [exportOpen, setExportOpen] = useState(false);
    const [rangeOpen, setRangeOpen] = useState(false);
    const [from, setFrom] = useState<string | null>(props.filters.date_from);
    const [to, setTo] = useState<string | null>(props.filters.date_to);
    useEffect(() => {
        setFrom(props.filters.date_from);
        setTo(props.filters.date_to);
    }, [props.filters.date_from, props.filters.date_to]);
    const query = new URLSearchParams(
        Object.entries({ ...props.filters, period: 'custom' }).filter(
            ([, value]) => value !== null && value !== '',
        ) as [string, string][],
    );
    const visit = (changes: Partial<Props['filters']>) =>
        router.get(
            '/emar/reports/history/logs',
            {
                ...props.filters,
                ...changes,
            },
            { preserveScroll: true },
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Reports & audit', href: '/emar/reports' },
                { title: 'Clinical history', href: '/emar/reports/history' },
                { title: 'Change log', href: '/emar/reports/history/logs' },
            ]}
        >
            <Head title="Medication change log" />
            <div className="space-y-5">
                <PageHeader
                    title="Medication change log"
                    icon={History}
                    subline={`${formatDateOnly(props.filters.date_from)} – ${formatDateOnly(props.filters.date_to)} · Pacific/Auckland · Retained changes to medication records.`}
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder="Search loaded changes…"
                                ariaLabel="Search loaded medication changes"
                            />
                            <PageHeaderGlassButton
                                onClick={() =>
                                    router.get('/emar/reports/history', {
                                        date_from: props.filters.date_from,
                                        date_to: props.filters.date_to,
                                        site_id: props.filters.site_id,
                                        client_id: props.filters.client_id,
                                    })
                                }
                            >
                                Clinical history
                            </PageHeaderGlassButton>
                            {props.can_export_history && (
                                <PageHeaderPrimaryButton
                                    className="min-h-11"
                                    icon={Download}
                                    onClick={() => setExportOpen(true)}
                                >
                                    Export change log
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    filters={
                        <>
                            <RecordPicker
                                variant="header"
                                label="House"
                                value={props.filters.site_id ?? ''}
                                options={[
                                    {
                                        value: '',
                                        label: 'All permitted houses',
                                    },
                                    ...props.sites.map((site) => ({
                                        value: String(site.id),
                                        label: site.name,
                                    })),
                                ]}
                                onChange={(value) =>
                                    visit({
                                        site_id: value || null,
                                        client_id: null,
                                    })
                                }
                            />
                            <RecordPicker
                                variant="header"
                                label="Person"
                                value={props.filters.client_id ?? ''}
                                options={[
                                    {
                                        value: '',
                                        label: 'All permitted people',
                                    },
                                    ...props.clients.map((person) => ({
                                        value: String(person.id),
                                        label: person.name,
                                    })),
                                ]}
                                onChange={(value) =>
                                    visit({ client_id: value || null })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Period"
                                value={props.filters.period}
                                allValue=""
                                options={[
                                    { value: 'today', label: 'Today' },
                                    { value: 'week', label: 'Last 7 days' },
                                    { value: 'month', label: 'This month' },
                                    {
                                        value: 'last_month',
                                        label: 'Last month',
                                    },
                                    { value: 'custom', label: 'Custom period' },
                                ]}
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
                                        active={
                                            props.filters.period === 'custom'
                                        }
                                    >
                                        {formatDateOnly(
                                            props.filters.date_from,
                                        )}{' '}
                                        –{' '}
                                        {formatDateOnly(props.filters.date_to)}
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
                                        className="mt-3 min-h-11 w-full"
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
                        </>
                    }
                />
                <p className="text-subtle">
                    Showing {visibleLogs.length} of the latest{' '}
                    {props.logs.length} loaded changes, up to 200. The CSV
                    includes every change in the selected period, house and
                    person, up to 100,000 rows. Larger results ask you to narrow
                    the selection before a file is made. Clinical history and
                    the event ledger remain available from Reports & audit.
                </p>
                {visibleLogs.length === 0 && (
                    <p role="status" className="text-subtle py-6">
                        {search
                            ? 'No loaded changes match this search.'
                            : 'No changes recorded in this scope.'}
                    </p>
                )}
                <div className="hidden md:block">
                    <EntityTable
                        rows={visibleLogs}
                        rowKey={(row) => String(row.id)}
                        identity={(row) => ({
                            name: words(row.action),
                            subline: row.client?.name ?? 'Person not recorded',
                        })}
                        identityLabel="Change"
                        columns={columns}
                        actionsFor={() => []}
                        rowHeight="content"
                        minWidth={800}
                    />
                </div>
                <div className="space-y-3 md:hidden">
                    {visibleLogs.map((row) => (
                        <article
                            key={row.id}
                            className="space-y-3 rounded-xl border border-border bg-card p-4"
                            aria-label={`${words(row.action)} for ${row.client?.name ?? 'person not recorded'}`}
                        >
                            <div>
                                <h2 className="text-section-title">
                                    {words(row.action)}
                                </h2>
                                <p className="text-subtle">
                                    {row.client?.name ?? 'Person not recorded'}
                                </p>
                            </div>
                            <dl className="space-y-2">
                                {[
                                    ['When', formatDateTime(row.created_at)],
                                    [
                                        'Recorded by',
                                        row.user?.name ?? 'Not recorded',
                                    ],
                                    ['Record', recordLabel(row)],
                                    ['Changed fields', changedFields(row)],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt className="text-caption">
                                            {label}
                                        </dt>
                                        <dd className="text-subtle break-words">
                                            {value}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </article>
                    ))}
                </div>
            </div>
            {exportOpen && (
                <HistoricalAuditExportDialog
                    url={`/medications/audit/export?${query}`}
                    purposes={props.export_purposes}
                    onClose={() => setExportOpen(false)}
                />
            )}
        </AppLayout>
    );
}
