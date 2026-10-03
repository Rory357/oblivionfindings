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
        cell: (row) => `${row.auditable_type} · ${row.auditable_id}`,
    },
    {
        key: 'meta',
        label: 'Changed fields',
        width: '1.4fr',
        cell: (row) => JSON.stringify(row.meta),
    },
];
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
                                onClick={() =>
                                    router.visit('/emar/reports/history')
                                }
                            >
                                Clinical history
                            </PageHeaderGlassButton>
                            {props.can_export_history && (
                                <PageHeaderPrimaryButton
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
                <EntityTable
                    rows={props.logs}
                    rowKey={(row) => String(row.id)}
                    identity={(row) => ({
                        name: row.action,
                        subline: row.client?.name ?? 'Person not recorded',
                    })}
                    identityLabel="Change"
                    columns={columns}
                    actionsFor={() => []}
                    rowHeight="content"
                    minWidth={800}
                />
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
