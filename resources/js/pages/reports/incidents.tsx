import { PageLayout } from '@/components/page';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderRail,
} from '@/components/page/page-header';
import { EntityFilter } from '@/components/rostering';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { AlertOctagon, CalendarDays, Download, List } from 'lucide-react';
import { useState } from 'react';

type Props = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    filters: {
        from?: string;
        to?: string;
        client_id?: string;
        severity?: string;
        reviewed?: string;
    };
};

export default function IncidentReports({ clients, filters }: Props) {
    const can = usePage<SharedData>().props.auth.can?.incidents;
    const [from, setFrom] = useState(filters.from || '');
    const [to, setTo] = useState(filters.to || '');
    const [clientId, setClientId] = useState(filters.client_id || '');
    const [severity, setSeverity] = useState(filters.severity || '');
    const [reviewed, setReviewed] = useState(filters.reviewed || '');
    const invalidRange = Boolean(from && to && from > to);
    const params = new URLSearchParams();
    if (from) params.set('from', from);
    if (to) params.set('to', to);
    if (clientId) params.set('client_id', clientId);
    if (severity) params.set('severity', severity);
    if (reviewed) params.set('reviewed', reviewed);
    const exportUrl = `/reports/incidents/export?${params.toString()}`;
    const period =
        from || to
            ? `${from ? formatDateOnly(from) : 'Earliest'} – ${to ? formatDateOnly(to) : 'Latest'}`
            : 'All dates';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Reports', href: '/reports' },
                { title: 'Incidents', href: '/reports/incidents' },
            ]}
        >
            <Head title="Incident reports" />
            <PageLayout
                hero={
                    <PageHeader
                        icon={AlertOctagon}
                        title="Incident reports"
                        subline="Incident occurrence dates · New Zealand time · Your permitted records"
                        actions={
                            <>
                                {can?.viewAny || can?.viewAssigned ? (
                                    <PageHeaderGlassButton icon={List} asChild>
                                        <Link href="/incidents">
                                            Open register
                                        </Link>
                                    </PageHeaderGlassButton>
                                ) : null}
                                {can?.export ? (
                                    invalidRange ? (
                                        <PageHeaderPrimaryButton
                                            icon={Download}
                                            disabled
                                        >
                                            Download CSV
                                        </PageHeaderPrimaryButton>
                                    ) : (
                                        <PageHeaderPrimaryButton
                                            icon={Download}
                                            asChild
                                        >
                                            <a href={exportUrl}>Download CSV</a>
                                        </PageHeaderPrimaryButton>
                                    )
                                ) : null}
                            </>
                        }
                        filters={
                            <>
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <PageHeaderFilterButton
                                            icon={CalendarDays}
                                            active={Boolean(from || to)}
                                            aria-label="Occurrence date range"
                                        >
                                            {period}
                                        </PageHeaderFilterButton>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align="end"
                                        className="w-72 space-y-3"
                                    >
                                        <div className="space-y-1">
                                            <Label htmlFor="incident-report-from">
                                                From (NZ date)
                                            </Label>
                                            <Input
                                                id="incident-report-from"
                                                type="date"
                                                value={from}
                                                max={to || undefined}
                                                onChange={(event) =>
                                                    setFrom(event.target.value)
                                                }
                                            />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="incident-report-to">
                                                To (NZ date)
                                            </Label>
                                            <Input
                                                id="incident-report-to"
                                                type="date"
                                                value={to}
                                                min={from || undefined}
                                                onChange={(event) =>
                                                    setTo(event.target.value)
                                                }
                                            />
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            Both selected days are included.
                                        </p>
                                    </PopoverContent>
                                </Popover>
                                <EntityFilter
                                    onDark
                                    label="Person"
                                    allLabel="All permitted people"
                                    items={clients.map((client) => ({
                                        id: client.id,
                                        name: `${client.first_name} ${client.last_name}`,
                                    }))}
                                    value={clientId ? Number(clientId) : null}
                                    onChange={(id) =>
                                        setClientId(
                                            id == null ? '' : String(id),
                                        )
                                    }
                                />
                                <PageHeaderFilterSelect
                                    label="Severity"
                                    value={severity || 'all'}
                                    onChange={(value) =>
                                        setSeverity(
                                            value === 'all' ? '' : value,
                                        )
                                    }
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All severities',
                                        },
                                        ...[
                                            'low',
                                            'medium',
                                            'high',
                                            'critical',
                                        ].map((value) => ({
                                            value,
                                            label:
                                                value.charAt(0).toUpperCase() +
                                                value.slice(1),
                                        })),
                                    ]}
                                />
                                <PageHeaderFilterSelect
                                    label="Review status"
                                    value={reviewed || 'all'}
                                    onChange={(value) =>
                                        setReviewed(
                                            value === 'all' ? '' : value,
                                        )
                                    }
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All review states',
                                        },
                                        { value: 'yes', label: 'Reviewed' },
                                        { value: 'no', label: 'Not reviewed' },
                                    ]}
                                />
                            </>
                        }
                        rail={
                            <PageHeaderRail
                                items={[{ key: 'export', label: 'Export' }]}
                                value="export"
                                onSelect={() => {}}
                                ariaLabel="Incident report views"
                            />
                        }
                    />
                }
            >
                {invalidRange ? (
                    <p role="alert" className="text-sm text-destructive">
                        Choose an end date on or after the start date.
                    </p>
                ) : null}
                <div className="space-y-2 text-sm text-muted-foreground">
                    <p>
                        The CSV includes the incident, immediate response,
                        reporter and review details for the selected records.
                        Dates are based on when the incident occurred.
                    </p>
                    <p>
                        Access and confidentiality are checked again when you
                        download. An export does not submit a regulatory
                        notification or complete incident work.
                    </p>
                    {!can?.export ? (
                        <p>
                            You do not have permission to export incident
                            records.
                        </p>
                    ) : null}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
