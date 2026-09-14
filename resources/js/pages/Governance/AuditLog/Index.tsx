import { Head, router } from '@inertiajs/react';
import {
    Boxes,
    CalendarRange,
    Download,
    History,
    PenLine,
    X,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    PersonCell,
    type EntityTableColumn,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { PageProps } from '@/types';

interface AuditEntry {
    kind: 'action' | 'change';
    id: number;
    user_id: number | null;
    type: string;
    entity_type: string;
    entity_id: number;
    description: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
    ip_address: string | null;
    created_at: string;
    user: { id: number; name: string; email: string } | null;
}

interface Filters {
    user_id: number | null;
    entity_type: string | null;
    action: string | null;
    change_type: string | null;
    from: string | null;
    to: string | null;
}

interface Props extends PageProps {
    entries: {
        data: AuditEntry[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
    };
    filters: Filters;
    summary: {
        all_time: number;
        last_7_days: number;
        last_7_days_from: string;
    };
    entityTypes: string[];
    actionTypes: string[];
    changeTypes: string[];
}

type FilterKey = 'entity_type' | 'action' | 'change_type' | 'from' | 'to';

/** "App\\Domain\\Governance\\Models\\BoardPack" → "BoardPack". */
const shortEntity = (value: string) => value.split('\\').pop() ?? value;
const humanise = (value: string) => value.replace(/[._]/g, ' ');

export default function GovernanceAuditLogIndex({
    auth,
    entries,
    filters,
    summary,
    entityTypes,
    actionTypes,
    changeTypes,
}: Props) {
    const values: Record<FilterKey, string | null> = {
        entity_type: filters.entity_type,
        action: filters.action,
        change_type: filters.change_type,
        from: filters.from ? filters.from.slice(0, 10) : null,
        to: filters.to ? filters.to.slice(0, 10) : null,
    };
    const [range, setRange] = useState({
        from: values.from ?? '',
        to: values.to ?? '',
    });
    const [rangeOpen, setRangeOpen] = useState(false);

    const applyFilters = (patch: Partial<Record<FilterKey, string | null>>) => {
        const next = { ...values, ...patch };
        router.get(
            '/governance/audit-log',
            Object.fromEntries(
                Object.entries(next).filter(([, v]) => v && v !== 'all'),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const activeFilterCount = Object.values(values).filter(Boolean).length;
    const clearFilters = () => {
        setRange({ from: '', to: '' });
        router.get('/governance/audit-log', {}, { preserveScroll: true });
    };

    const exportUrl = (() => {
        const params = new URLSearchParams();
        Object.entries(values).forEach(([k, v]) => {
            if (v) params.set(k, v);
        });
        const query = params.toString();
        return `/governance/audit-log/export${query ? `?${query}` : ''}`;
    })();

    const rangeLabel =
        values.from || values.to
            ? `${values.from ? formatDateOnly(values.from) : 'Start'} – ${values.to ? formatDateOnly(values.to) : 'Today'}`
            : 'Any date';

    const columns: EntityTableColumn<AuditEntry>[] = [
        {
            key: 'kind',
            label: 'Kind',
            width: '0.6fr',
            cell: (e) => (
                <EntityStatusChip variant={e.kind === 'action' ? 'info' : 'neutral'}>
                    {e.kind === 'action' ? 'Action' : 'Change'}
                </EntityStatusChip>
            ),
        },
        {
            key: 'description',
            label: 'Details',
            width: '1.6fr',
            cell: (e) =>
                e.description ? (
                    <span className="truncate" title={e.description}>
                        {e.description}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'actor',
            label: 'Actor',
            width: '1fr',
            cell: (e) => <PersonCell name={e.user ? e.user.name : 'System'} />,
        },
        {
            key: 'ip',
            label: 'IP address',
            width: '0.8fr',
            cell: (e) =>
                e.ip_address ? (
                    <span className="font-mono text-xs text-muted-foreground">
                        {e.ip_address}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'when',
            label: 'When',
            width: '1.1fr',
            cell: (e) => formatDateTimeLong(e.created_at),
        },
    ];

    const header = (
        <PageHeader
            icon={History}
            title="Governance audit log"
            subline="Action events and entity changes across governance records"
            actions={
                <PageHeaderGlassButton
                    icon={Download}
                    onClick={() => {
                        window.location.href = exportUrl;
                    }}
                >
                    Export CSV
                </PageHeaderGlassButton>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Matching events"
                        ariaLabel="View matching audit events"
                        href={exportUrl.replace('/export', '')}
                    >
                        <PageHeaderMeterBig>{entries.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {activeFilterCount > 0
                                ? `${activeFilterCount} filter${activeFilterCount === 1 ? '' : 's'} applied`
                                : 'no filters applied'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Last 7 days"
                        ariaLabel="View audit events from the last 7 days"
                        href={`/governance/audit-log?from=${summary.last_7_days_from}`}
                    >
                        <PageHeaderMeterBig>{summary.last_7_days}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            since {formatDateOnly(summary.last_7_days_from)}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="All time"
                        ariaLabel="View every audit event"
                        href="/governance/audit-log"
                    >
                        <PageHeaderMeterBig>{summary.all_time}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {entityTypes.length} entity types recorded
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Boxes}
                        label="Any entity"
                        value={values.entity_type ?? 'all'}
                        options={[
                            { value: 'all', label: 'Any entity' },
                            ...entityTypes.map((t) => ({
                                value: t,
                                label: shortEntity(t),
                            })),
                        ]}
                        onChange={(v) => applyFilters({ entity_type: v })}
                    />
                    <PageHeaderFilterSelect
                        icon={Zap}
                        label="Any action"
                        value={values.action ?? 'all'}
                        options={[
                            { value: 'all', label: 'Any action' },
                            ...actionTypes.map((t) => ({
                                value: t,
                                label: humanise(t),
                            })),
                        ]}
                        onChange={(v) => applyFilters({ action: v })}
                    />
                    <PageHeaderFilterSelect
                        icon={PenLine}
                        label="Any change"
                        value={values.change_type ?? 'all'}
                        options={[
                            { value: 'all', label: 'Any change' },
                            ...changeTypes.map((t) => ({
                                value: t,
                                label: humanise(t),
                            })),
                        ]}
                        onChange={(v) => applyFilters({ change_type: v })}
                    />
                    <Popover open={rangeOpen} onOpenChange={setRangeOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderFilterButton
                                icon={CalendarRange}
                                active={Boolean(values.from || values.to)}
                                aria-label="Filter by date range"
                            >
                                {rangeLabel}
                            </PageHeaderFilterButton>
                        </PopoverTrigger>
                        <PopoverContent align="end" className="w-64">
                            <form
                                className="flex flex-col gap-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    setRangeOpen(false);
                                    applyFilters({
                                        from: range.from || null,
                                        to: range.to || null,
                                    });
                                }}
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="audit-from">From</Label>
                                    <Input
                                        id="audit-from"
                                        type="date"
                                        value={range.from}
                                        onChange={(e) =>
                                            setRange((r) => ({
                                                ...r,
                                                from: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="audit-to">To</Label>
                                    <Input
                                        id="audit-to"
                                        type="date"
                                        value={range.to}
                                        onChange={(e) =>
                                            setRange((r) => ({
                                                ...r,
                                                to: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="flex justify-between gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setRange({ from: '', to: '' });
                                            setRangeOpen(false);
                                            applyFilters({ from: null, to: null });
                                        }}
                                    >
                                        Clear
                                    </Button>
                                    <Button type="submit" size="sm">
                                        Apply range
                                    </Button>
                                </div>
                            </form>
                        </PopoverContent>
                    </Popover>
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Audit log', href: '/governance/audit-log' },
            ]}
        >
            <Head title="Governance audit log" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Recent activity"
                        caption={`${entries.data.length} of ${entries.total} shown · page ${entries.current_page} of ${Math.max(1, entries.last_page)}`}
                        right={
                            activeFilterCount > 0 ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={clearFilters}
                                    className="text-xs text-muted-foreground"
                                >
                                    <X className="h-3.5 w-3.5" />
                                    Clear filters
                                </Button>
                            ) : null
                        }
                    />

                    {entries.data.length === 0 ? (
                        <EmptyState
                            icon={History}
                            title={
                                activeFilterCount > 0
                                    ? 'No audit events match your filters'
                                    : 'No audit events recorded yet'
                            }
                            description={
                                activeFilterCount > 0
                                    ? 'Try clearing filters, or widen the date range.'
                                    : 'Governance actions and record changes will be logged here.'
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={entries.data}
                            rowKey={(e) => `${e.kind}-${e.id}`}
                            identityLabel="Event"
                            identity={(e) => ({
                                icon: e.kind === 'action' ? Zap : PenLine,
                                name: humanise(e.type),
                                subline: `${shortEntity(e.entity_type)} #${e.entity_id}`,
                            })}
                            columns={columns}
                            actionsFor={() => []}
                            minWidth={980}
                        />
                    )}

                    <LaravelPagination
                        links={entries.links}
                        lastPage={entries.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>
        </AppLayout>
    );
}
