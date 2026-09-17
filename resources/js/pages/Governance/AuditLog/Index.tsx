import { Head, Link, router } from '@inertiajs/react';
import {
    Boxes,
    CalendarRange,
    Download,
    ExternalLink,
    History,
    ListTree,
    Lock,
    PenLine,
    X,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { InfoCard } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { PageProps } from '@/types';

/** One audit entry, already written as a sentence and masked by the server. */
export interface AuditEntry {
    key: string;
    kind: 'action' | 'change';
    id: number;
    type: string;
    entity_type: string;
    entity_id: number;
    created_at: string | null;
    user: { id: number; name: string } | null;
    actor: string;
    activity: string;
    sentence: string;
    record_type: string;
    /** Only when the viewer can open the record. */
    record_title: string | null;
    record_url: string | null;
    can_see_details: boolean;
    details_withheld_reason: string | null;
    changes: { label: string; from: string; to: string }[];
    details: { label: string; value: string }[];
    description: string | null;
    ip_address: string | null;
}

interface Filters {
    user_id: number | null;
    entity_type: string | null;
    action: string | null;
    change_type: string | null;
    from: string | null;
    to: string | null;
}

interface Option {
    value: string;
    label: string;
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
    recordTypeOptions?: Option[];
    activityOptions?: (Option & { kind: 'action' | 'change' })[];
}

type FilterKey = 'entity_type' | 'action' | 'change_type' | 'from' | 'to';

/** "action:resolution.voted" → the query the server expects. */
export function activityQuery(
    value: string,
): Pick<Record<FilterKey, string | null>, 'action' | 'change_type'> {
    if (value.startsWith('action:')) {
        return { action: value.slice('action:'.length), change_type: null };
    }
    if (value.startsWith('change:')) {
        return { action: null, change_type: value.slice('change:'.length) };
    }
    return { action: null, change_type: null };
}

/** Whether an entry has anything to show under "What changed". */
export function hasChangeDetails(entry: AuditEntry): boolean {
    return (
        entry.changes.length > 0 ||
        entry.details.length > 0 ||
        Boolean(entry.description)
    );
}

function AuditEntryDialog({
    entry,
    onClose,
}: {
    entry: AuditEntry | null;
    onClose: () => void;
}) {
    return (
        <Dialog open={entry !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent style={{ maxWidth: 'min(92vw, 620px)' }}>
                {entry ? (
                    <>
                        <DialogHeader>
                            <DialogTitle>What changed</DialogTitle>
                            <DialogDescription>{entry.sentence}</DialogDescription>
                        </DialogHeader>

                        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                            <dt className="text-muted-foreground">When</dt>
                            <dd>{formatDateTimeLong(entry.created_at)}</dd>
                            <dt className="text-muted-foreground">Who</dt>
                            <dd>{entry.actor}</dd>
                            <dt className="text-muted-foreground">Record</dt>
                            <dd>
                                {entry.record_url && entry.record_title ? (
                                    <Link href={entry.record_url} className="font-medium text-primary hover:underline">
                                        {entry.record_title}
                                    </Link>
                                ) : (
                                    entry.record_type
                                )}
                            </dd>
                            <dt className="text-muted-foreground">IP address</dt>
                            <dd className="font-mono text-xs">{entry.ip_address ?? 'Not recorded'}</dd>
                        </dl>

                        {!entry.can_see_details ? (
                            <InfoCard icon={Lock}>
                                {entry.details_withheld_reason ??
                                    "You can't open this record, so its details are hidden."}
                            </InfoCard>
                        ) : hasChangeDetails(entry) ? (
                            <div className="flex flex-col gap-3">
                                {entry.changes.length > 0 ? (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="text-left text-xs text-muted-foreground">
                                                    <th className="py-1 pr-3 font-medium">Detail</th>
                                                    <th className="py-1 pr-3 font-medium">Before</th>
                                                    <th className="py-1 font-medium">After</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {entry.changes.map((change) => (
                                                    <tr key={change.label} className="border-t border-border align-top">
                                                        <td className="py-1.5 pr-3 font-medium">{change.label}</td>
                                                        <td className="py-1.5 pr-3 text-muted-foreground">{change.from}</td>
                                                        <td className="py-1.5">{change.to}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : null}
                                {entry.details.length > 0 ? (
                                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                                        {entry.details.map((detail) => (
                                            <div key={detail.label} className="contents">
                                                <dt className="text-muted-foreground">{detail.label}</dt>
                                                <dd className="break-words">{detail.value}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                ) : null}
                                {entry.description ? (
                                    <p className="text-sm whitespace-pre-line">{entry.description}</p>
                                ) : null}
                            </div>
                        ) : (
                            <p className="text-caption">No other details were recorded.</p>
                        )}

                        <DialogFooter>
                            {entry.record_url ? (
                                <Button variant="outline" asChild>
                                    <Link href={entry.record_url}>
                                        <ExternalLink className="h-4 w-4" />
                                        Open record
                                    </Link>
                                </Button>
                            ) : null}
                            <Button onClick={onClose}>Close</Button>
                        </DialogFooter>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

export default function GovernanceAuditLogIndex({
    auth,
    entries,
    filters,
    summary,
    recordTypeOptions = [],
    activityOptions = [],
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
    const [openEntry, setOpenEntry] = useState<AuditEntry | null>(null);
    const ctxMenu = useEntityContextMenu<AuditEntry>();

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

    const activeFilterCount = [
        values.entity_type,
        values.action || values.change_type,
        values.from || values.to,
    ].filter(Boolean).length;
    const clearFilters = () => {
        setRange({ from: '', to: '' });
        router.get('/governance/audit-log', {}, { preserveScroll: true });
    };

    const query = (() => {
        const params = new URLSearchParams();
        Object.entries(values).forEach(([k, v]) => {
            if (v) params.set(k, v);
        });
        return params.toString();
    })();
    const exportUrl = `/governance/audit-log/export${query ? `?${query}` : ''}`;
    const listUrl = `/governance/audit-log${query ? `?${query}` : ''}`;

    const rangeLabel =
        values.from || values.to
            ? `${values.from ? formatDateOnly(values.from) : 'Start'} – ${values.to ? formatDateOnly(values.to) : 'Today'}`
            : 'Any date';

    const activityValue = values.action
        ? `action:${values.action}`
        : values.change_type
          ? `change:${values.change_type}`
          : 'all';

    const actionsFor = (entry: AuditEntry): MenuItem[] =>
        compactMenu([
            {
                label: 'What changed',
                icon: ListTree,
                onClick: () => setOpenEntry(entry),
            },
            entry.record_url
                ? {
                      label: 'Open record',
                      icon: ExternalLink,
                      onClick: () => router.visit(entry.record_url as string),
                  }
                : null,
        ]);

    const columns: EntityTableColumn<AuditEntry>[] = [
        {
            key: 'record',
            label: 'Record',
            width: '1.3fr',
            cell: (entry) => (
                <span className="flex min-w-0 flex-col">
                    <span className="text-xs text-muted-foreground">{entry.record_type}</span>
                    {entry.record_url && entry.record_title ? (
                        <Link
                            href={entry.record_url}
                            onClick={(event) => event.stopPropagation()}
                            className="truncate font-medium text-primary hover:underline"
                            title={entry.record_title}
                        >
                            {entry.record_title}
                        </Link>
                    ) : entry.record_title ? (
                        <span className="truncate">{entry.record_title}</span>
                    ) : (
                        <EmptyValue />
                    )}
                </span>
            ),
        },
        {
            key: 'changed',
            label: 'What changed',
            width: '0.8fr',
            cell: (entry) =>
                !entry.can_see_details ? (
                    <EntityChip icon={Lock}>Hidden</EntityChip>
                ) : hasChangeDetails(entry) ? (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2 text-xs"
                        onClick={(event) => {
                            event.stopPropagation();
                            setOpenEntry(entry);
                        }}
                    >
                        <ListTree className="h-3.5 w-3.5" />
                        {entry.changes.length > 0
                            ? `${entry.changes.length} ${entry.changes.length === 1 ? 'change' : 'changes'}`
                            : 'Details'}
                    </Button>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'when',
            label: 'When',
            width: '1fr',
            cell: (entry) => formatDateTimeLong(entry.created_at),
        },
    ];

    const header = (
        <PageHeader
            icon={History}
            title="Audit log"
            subline="Who did what in Governance, and when"
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
                        label="Matching activity"
                        ariaLabel="View the matching activity"
                        href={listUrl}
                    >
                        <PageHeaderMeterBig>{entries.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {activeFilterCount > 0
                                ? `${activeFilterCount} ${activeFilterCount === 1 ? 'filter' : 'filters'} applied`
                                : 'No filters applied'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Last 7 days"
                        ariaLabel="View activity from the last 7 days"
                        href={`/governance/audit-log?from=${summary.last_7_days_from}`}
                    >
                        <PageHeaderMeterBig>{summary.last_7_days}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            since {formatDateOnly(summary.last_7_days_from)}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="All time"
                        ariaLabel="View all activity"
                        href="/governance/audit-log"
                    >
                        <PageHeaderMeterBig>{summary.all_time}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {recordTypeOptions.length === 1
                                ? 'across 1 record type'
                                : `across ${recordTypeOptions.length} record types`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Boxes}
                        label="Record type"
                        value={values.entity_type ?? 'all'}
                        options={[
                            { value: 'all', label: 'Any record type' },
                            ...recordTypeOptions,
                        ]}
                        onChange={(v) => applyFilters({ entity_type: v })}
                    />
                    <PageHeaderFilterSelect
                        icon={Zap}
                        label="Activity"
                        value={activityValue}
                        options={[
                            { value: 'all', label: 'Any activity' },
                            ...activityOptions.map((option) => ({
                                value: `${option.kind}:${option.value}`,
                                label: option.label,
                            })),
                        ]}
                        onChange={(v) => applyFilters(activityQuery(v))}
                    />
                    <Popover open={rangeOpen} onOpenChange={setRangeOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderFilterButton
                                icon={CalendarRange}
                                active={Boolean(values.from || values.to)}
                                aria-label="Filter by date"
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
                                        Show these dates
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
            <Head title="Audit log" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Activity"
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
                                    ? 'No activity matches your filters'
                                    : 'No activity recorded yet'
                            }
                            description={
                                activeFilterCount > 0
                                    ? 'Try clearing a filter, or choose a wider date range.'
                                    : 'Votes, approvals, downloads and changes to Governance records are listed here.'
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={entries.data}
                            rowKey={(entry) => entry.key}
                            identityLabel="What happened"
                            identityWidth="2.2fr"
                            identity={(entry) => ({
                                icon: entry.kind === 'change' ? PenLine : Zap,
                                name: entry.sentence,
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            onOpen={(entry) => setOpenEntry(entry)}
                            onRowContextMenu={(event, entry) => ctxMenu.open(event, entry)}
                            minWidth={900}
                        />
                    )}

                    <LaravelPagination
                        links={entries.links}
                        lastPage={entries.last_page}
                        preserveScroll
                    />
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={History}
                    title={ctxMenu.ctx.record.sentence}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            <AuditEntryDialog entry={openEntry} onClose={() => setOpenEntry(null)} />
        </AppLayout>
    );
}
