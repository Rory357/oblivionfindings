import { HistoricalAuditExportDialog } from '@/components/emar/historical-audit-export-dialog';
import {
    EntityTable,
    type EntityTableColumn,
} from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { RecordPicker } from '@/components/people-locations/record-picker';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { Download, History } from 'lucide-react';
import { useState } from 'react';

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
        from: string | null;
        to: string | null;
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
    const [exportOpen, setExportOpen] = useState(false);
    const query = new URLSearchParams(
        Object.entries(props.filters).filter(
            ([, value]) => value !== null && value !== '',
        ) as [string, string][],
    );
    const visit = (key: 'site_id' | 'client_id', value: string) =>
        router.get(
            '/emar/reports/history/logs',
            {
                ...props.filters,
                [key]: value || null,
                ...(key === 'site_id' ? { client_id: null } : {}),
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
                    subline="Retained changes to medication records. Times use Pacific/Auckland."
                    actions={
                        <>
                            <PageHeaderGlassButton
                                className="min-h-11"
                                onClick={() =>
                                    router.visit('/emar/reports/history')
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
                                onChange={(value) => visit('site_id', value)}
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
                                onChange={(value) => visit('client_id', value)}
                            />
                        </>
                    }
                />
                <p className="text-subtle">
                    Showing the latest {props.logs.length} changes, up to 200.
                    The CSV includes every change in the selected scope.
                    Clinical history and the event ledger remain available from
                    Reports & audit.
                </p>
                <div className="hidden md:block">
                    <EntityTable
                        rows={props.logs}
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
                    {props.logs.map((row) => (
                        <article
                            key={row.id}
                            className="border-border bg-card space-y-3 rounded-xl border p-4"
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
