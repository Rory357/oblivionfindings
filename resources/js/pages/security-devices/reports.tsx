import { EntityChip } from '@/components/lists';
import { EvidenceRecords } from '@/components/lists/evidence-records';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { ReviewRow } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import {
    downloadReport,
    reports,
    type ReportKey,
} from '@/lib/security-devices-reports';
import { Head, router, usePage } from '@inertiajs/react';
import { BarChart3, Download, LayoutGrid, List, RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
export type ReportsProps = {
    actor_id: number;
    as_of: string;
    stats: { devices: number; events_90d: number; maintenance: number };
    windowDays: number;
};
type View = 'all' | ReportKey;
const validCount = (value: number) =>
    Number.isSafeInteger(value) && value >= 0 ? value : null;
export default function SecurityDevicesReports(props: ReportsProps) {
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, unknown> };
    }>().props;
    const [view, setView] = useState<'cards' | 'table'>('cards');
    const visible = Boolean(
        auth?.user?.id &&
        props.actor_id === auth.user.id &&
        auth.can?.['securityDevices.reports.view'] === true,
    );
    return (
        <ReportsBody
            key={JSON.stringify([auth, props])}
            data={props}
            actor={auth?.user?.id ?? 0}
            visible={visible}
            view={view}
            setView={setView}
        />
    );
}
function ReportsBody({
    data,
    actor,
    visible: receivedVisible,
    view,
    setView,
}: {
    data: ReportsProps;
    actor: number;
    visible: boolean;
    view: 'cards' | 'table';
    setView: (view: 'cards' | 'table') => void;
}) {
    const [scope, setScope] = useState<View>('all'),
        [search, setSearch] = useState(''),
        [denied, setDenied] = useState(false),
        [notice, setNotice] = useState('');
    const refreshing = useRef(false),
        live = useRef(true);
    const visible = receivedVisible && !denied;
    useEffect(() => {
        live.current = true;
        return () => {
            live.current = false;
        };
    }, []);
    useEffect(
        () =>
            router.on('invalid', (event) => {
                if (!live.current || !refreshing.current) return;
                const response = event.detail.response;
                if (
                    response.config.method?.toLowerCase() !== 'get' ||
                    typeof response.config.url !== 'string'
                )
                    return;
                let target: URL;
                try {
                    target = new URL(
                        response.config.url,
                        window.location.origin,
                    );
                } catch {
                    return;
                }
                if (
                    target.origin !== window.location.origin ||
                    target.pathname !== '/security-devices/reports'
                )
                    return;
                event.preventDefault();
                refreshing.current = false;
                if ([401, 403, 404].includes(response.status)) {
                    setDenied(true);
                    setSearch('');
                    setScope('all');
                } else
                    setNotice(
                        'Reports could not be refreshed. The previous counts are still shown. Try Refresh again.',
                    );
            }),
        [],
    );
    const refresh = () => {
        if (refreshing.current) return;
        refreshing.current = true;
        setNotice('');
        router.reload({
            only: ['stats', 'windowDays', 'actor_id', 'as_of', 'auth'],
            onSuccess: (page) => {
                if (!live.current) return;
                const props = page.props as {
                    actor_id?: number;
                    auth?: {
                        user?: { id?: number };
                        can?: Record<string, unknown>;
                    };
                };
                if (
                    page.component === 'security-devices/reports' &&
                    props.actor_id === actor &&
                    props.auth?.user?.id === actor &&
                    props.auth.can?.['securityDevices.reports.view'] === true
                )
                    setDenied(false);
                else {
                    setDenied(true);
                    setSearch('');
                    setScope('all');
                }
            },
            onFinish: () => {
                if (live.current) refreshing.current = false;
            },
        });
    };
    const windowValid =
        Number.isSafeInteger(data.windowDays) && data.windowDays > 0;
    const rows = reports.map((report) => ({
        ...report,
        count:
            report.key === 'events' && !windowValid
                ? null
                : validCount(
                      report.key === 'events'
                          ? data.stats.events_90d
                          : data.stats[report.key],
                  ),
        available: report.key !== 'events' || windowValid,
        period:
            report.key === 'events'
                ? windowValid
                    ? `Past ${data.windowDays} days through the request time`
                    : 'Not available'
                : 'All retained records in your permitted scope',
    }));
    type Report = (typeof rows)[number];
    const available = (row: Report) => row.available && row.count !== null;
    const select = (key: View) => {
        if (visible) {
            setScope(key);
            setSearch('');
        }
    };
    const filtered = rows.filter(
        (row) =>
            (scope === 'all' || scope === row.key) &&
            [row.title, row.description, ...row.fields]
                .join(' ')
                .toLocaleLowerCase()
                .includes(search.trim().toLocaleLowerCase()),
    );
    const asOf = formatDateTimeLong(data.as_of, 'Time not available');
    const downloadLink = (row: Report) =>
        available(row) ? (
            <Button asChild variant="outline" size="sm">
                <a
                    href={row.href}
                    aria-label={`Download ${row.title.toLowerCase()} CSV`}
                    onClick={(event) => event.stopPropagation()}
                >
                    <Download className="size-4" />
                    Download CSV
                </a>
            </Button>
        ) : (
            'Refresh reports to check availability'
        );
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Reports', href: '/security-devices/reports' },
            ]}
        >
            <Head title="Reports - Security & Devices" />
            <div className="space-y-5">
                <PageHeader
                    icon={BarChart3}
                    title="Reports"
                    subline="Download permitted device, event and maintenance records as CSV files."
                    mobileSummary={
                        visible
                            ? '3 report types · counts at the last refresh'
                            : 'Reports restricted'
                    }
                    actions={
                        <>
                            <PageHeaderSearch
                                ariaLabel="Search reports"
                                placeholder="Search report names or export fields…"
                                value={visible ? search : ''}
                                onChange={(value) =>
                                    visible && setSearch(value)
                                }
                            />
                            <PageHeaderGlassButton onClick={refresh}>
                                <RefreshCw className="size-4" />
                                Refresh
                            </PageHeaderGlassButton>
                        </>
                    }
                    meters={rows.map((row) => (
                        <PageHeaderMeterBlock
                            key={row.key}
                            label={row.title}
                            ariaLabel={`Open ${row.title.toLowerCase()} report`}
                            onClick={() => select(row.key)}
                        >
                            <PageHeaderMeterBig>
                                {visible
                                    ? (row.count ?? 'Not available')
                                    : 'Restricted'}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {visible
                                    ? row.key === 'events' && windowValid
                                        ? `Events in the past ${data.windowDays} days`
                                        : 'Records at the last refresh'
                                    : 'Report access required'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                    filters={
                        <>
                            {visible && (search || scope !== 'all') ? (
                                <PageHeaderFilterButton
                                    onClick={() => select('all')}
                                >
                                    Clear report filters
                                </PageHeaderFilterButton>
                            ) : null}
                            <PageHeaderViewToggle
                                value={view}
                                onChange={setView}
                                options={[
                                    {
                                        value: 'cards',
                                        label: 'Cards',
                                        icon: LayoutGrid,
                                    },
                                    {
                                        value: 'table',
                                        label: 'Table',
                                        icon: List,
                                    },
                                ]}
                            />
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={[
                                { key: 'all', label: 'All reports' },
                                ...reports.map((report) => ({
                                    key: report.key,
                                    label: report.title,
                                })),
                            ]}
                            value={scope}
                            onSelect={select}
                            ariaLabel="Report views"
                        />
                    }
                />
                {!visible ? (
                    <p
                        role="status"
                        className="rounded-xl border bg-card p-5 text-sm"
                    >
                        Reports are restricted. Refresh after your report access
                        has been restored.
                    </p>
                ) : (
                    <>
                        {notice ? (
                            <p
                                role="status"
                                className="text-sm text-muted-foreground"
                            >
                                {notice}
                            </p>
                        ) : null}
                        <EvidenceRecords<Report>
                            title="Available reports"
                            description={`Counts refreshed ${asOf}. A downloaded file reflects the records you can access when you request it, so its row count may change.`}
                            rows={filtered}
                            total={filtered.length}
                            view={view}
                            rowKey={(row) => row.key}
                            icon={BarChart3}
                            identity={(row) => ({
                                name: row.title,
                                subline:
                                    row.count === null
                                        ? 'Count not available'
                                        : `${row.count.toLocaleString('en-NZ')} records`,
                                icon: row.icon,
                            })}
                            articleLabel={(row) => `Report: ${row.title}`}
                            cardFor={(row) => ({
                                name: row.title,
                                icon: row.icon,
                                meridian: 'neutral',
                                subline: row.description,
                                chips: (
                                    <>
                                        <EntityChip>
                                            {row.count === null
                                                ? 'Count not available'
                                                : `${row.count.toLocaleString('en-NZ')} records`}
                                        </EntityChip>
                                        <EntityChip>CSV</EntityChip>
                                    </>
                                ),
                                footer: {
                                    primary:
                                        row.key === 'events'
                                            ? windowValid
                                                ? `Past ${data.windowDays} days`
                                                : 'Period not available'
                                            : 'All retained records',
                                    secondary: 'CSV for spreadsheet apps',
                                    trailing: downloadLink(row),
                                },
                            })}
                            actionsFor={(row) => [
                                {
                                    label: 'Download CSV',
                                    icon: Download,
                                    disabled: available(row)
                                        ? undefined
                                        : 'Refresh reports to check the count and period before downloading.',
                                    onClick: () => {
                                        if (visible && available(row))
                                            downloadReport(row.key);
                                    },
                                },
                            ]}
                            compactColumns={[
                                {
                                    key: 'count',
                                    label: 'Records',
                                    width: '150px',
                                    cell: (row) =>
                                        row.count === null
                                            ? 'Not available'
                                            : row.count.toLocaleString('en-NZ'),
                                },
                                {
                                    key: 'period',
                                    label: 'Period',
                                    width: '180px',
                                    cell: (row) =>
                                        row.key === 'events'
                                            ? windowValid
                                                ? `Past ${data.windowDays} days`
                                                : 'Not available'
                                            : 'All retained records',
                                },
                                {
                                    key: 'download',
                                    label: 'Download',
                                    width: '180px',
                                    cell: downloadLink,
                                },
                            ]}
                            columns={[
                                {
                                    key: 'facts',
                                    label: 'Report details',
                                    width: '100%',
                                    cell: (row) => (
                                        <>
                                            <ReviewRow
                                                label="Report"
                                                value={row.title}
                                            />
                                            <ReviewRow
                                                label="Contents"
                                                value={row.description}
                                            />
                                            <ReviewRow
                                                label="Records at refresh"
                                                value={
                                                    row.count === null
                                                        ? 'Not available'
                                                        : row.count.toLocaleString(
                                                              'en-NZ',
                                                          )
                                                }
                                            />
                                            <ReviewRow
                                                label="Counts refreshed"
                                                value={asOf}
                                            />
                                            <ReviewRow
                                                label="Period"
                                                value={row.period}
                                            />
                                            <ReviewRow
                                                label="Rows"
                                                value={row.rows}
                                            />
                                            {row.key === 'events' ? (
                                                <ReviewRow
                                                    label="Device names"
                                                    value="Included only for devices in your current permitted scope."
                                                />
                                            ) : null}
                                            <ReviewRow
                                                label="Export fields"
                                                value={row.fields.join(', ')}
                                            />
                                            <ReviewRow
                                                label="File"
                                                value="CSV for spreadsheet apps such as Excel"
                                            />
                                            <ReviewRow
                                                label="Text"
                                                value="UTF-8, including te reo characters"
                                            />
                                            {row.key === 'maintenance' ? (
                                                <ReviewRow
                                                    label="Cost"
                                                    value="Recorded maintenance cost; use Finance for accounts."
                                                />
                                            ) : null}
                                            <ReviewRow
                                                label="Download"
                                                value={downloadLink(row)}
                                            />
                                        </>
                                    ),
                                },
                            ]}
                            empty="No matching reports"
                        />
                    </>
                )}
            </div>
        </AppLayout>
    );
}
