/* eslint-disable no-restricted-syntax -- The entries register uses styled native
 * <button>s for the scope segmented control, table-row action affordances and the
 * Add-entry CTA (custom layout surfaces, not shadcn <Button> cases). Colours stay
 * token-based. */
import { History, Plus, Search } from 'lucide-react';
import { type MouseEvent, useEffect, useState } from 'react';

import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';

import {
    avatarStyle,
    PAY_TYPE_OPTIONS,
    payTypeLabel,
    statusLabel,
    statusVariant,
    type NamedOption,
    type PaginatedData,
    type TimeCan,
    type TimeEntry,
    type TimeFilters,
} from './types';

const NONE = '__none__';

const FLAG_TONE: Record<string, string> = {
    Sleepover: 'border-primary/30 bg-primary/10 text-primary',
    'Break fail': 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    Overtime: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    Manual: 'border-border bg-muted text-muted-foreground',
    'On behalf': 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    Unlinked: 'border-border bg-muted text-muted-foreground',
    Mileage: 'border-status-info/30 bg-status-info-bg text-status-info',
    PH: 'border-status-info/30 bg-status-info-bg text-status-info',
    'On-call': 'border-primary/30 bg-primary/10 text-primary',
};

function entryFlags(e: TimeEntry): string[] {
    const flags: string[] = [];
    if (e.is_sleepover) flags.push('Sleepover');
    if (e.is_on_call) flags.push('On-call');
    if (e.is_public_holiday) flags.push('PH');
    if (e.break_compliance_met === false) flags.push('Break fail');
    if (e.entry_type === 'manual') flags.push('Manual');
    if (e.entry_type === 'admin_clock') flags.push('On behalf');
    if (e.entry_type === 'clock' && !e.shift) flags.push('Unlinked');
    if (e.mileage_km && e.mileage_km > 0) flags.push('Mileage');
    return flags;
}

export function EntriesPane({
    entries,
    filters,
    sites,
    can,
    onAdd,
    onFilter,
    onRowContext,
    onAmendments,
    onClearFilters,
}: {
    entries: PaginatedData<TimeEntry>;
    filters: TimeFilters;
    sites: NamedOption[];
    can: TimeCan;
    onAdd?: () => void;
    onFilter: (key: string, value: string | null) => void;
    onRowContext: (e: TimeEntry, ev: MouseEvent) => void;
    onAmendments: (e: TimeEntry) => void;
    onClearFilters: () => void;
}) {
    const [searchValue, setSearchValue] = useState(filters.q ?? '');
    useEffect(() => setSearchValue(filters.q ?? ''), [filters.q]);

    const hasFilters =
        !!filters.status || !!filters.pay_type || !!filters.site_id || !!filters.q;

    return (
        <div className="flex flex-col gap-3.5">
            {/* toolbar */}
            <div className="flex flex-wrap items-center gap-2.5">
                {can.approveAny ? (
                    <div className="inline-flex gap-0.5 rounded-[10px] bg-muted p-[3px]">
                        {(
                            [
                                { v: 'mine', l: 'Mine' },
                                { v: 'team', l: 'Team' },
                                ...(can.manage ? [{ v: 'all', l: 'All' }] : []),
                            ] as { v: string; l: string }[]
                        ).map((s) => {
                            const active = (filters.scope ?? 'team') === s.v;
                            return (
                                <button
                                    key={s.v}
                                    type="button"
                                    onClick={() => onFilter('scope', s.v)}
                                    aria-pressed={active}
                                    className={cn(
                                        'rounded-[7px] px-3 py-1.5 text-[12.5px] font-semibold transition-colors',
                                        active
                                            ? 'bg-card text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {s.l}
                                </button>
                            );
                        })}
                    </div>
                ) : null}

                <div className="relative min-w-[180px] max-w-[300px] flex-1">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        type="text"
                        aria-label="Search staff or note"
                        placeholder="Search staff or note…"
                        value={searchValue}
                        onChange={(e) => setSearchValue(e.target.value)}
                        onKeyDown={(e) =>
                            e.key === 'Enter' &&
                            onFilter('q', searchValue.trim() || null)
                        }
                        className="h-9 w-full rounded-[9px] border border-border bg-card pl-9 pr-3 text-[13px] outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    />
                </div>

                <Select
                    value={filters.status ?? NONE}
                    onValueChange={(v) => onFilter('status', v === NONE ? null : v)}
                >
                    <SelectTrigger className="h-9 w-32 text-xs" aria-label="Status filter">
                        <SelectValue placeholder="All statuses" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>All statuses</SelectItem>
                        <SelectItem value="active">Active</SelectItem>
                        <SelectItem value="submitted">Submitted</SelectItem>
                        <SelectItem value="approved">Approved</SelectItem>
                        <SelectItem value="voided">Voided</SelectItem>
                    </SelectContent>
                </Select>

                <Select
                    value={filters.pay_type ?? NONE}
                    onValueChange={(v) => onFilter('pay_type', v === NONE ? null : v)}
                >
                    <SelectTrigger className="h-9 w-32 text-xs" aria-label="Pay type filter">
                        <SelectValue placeholder="All pay types" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={NONE}>All pay types</SelectItem>
                        {PAY_TYPE_OPTIONS.map((o) => (
                            <SelectItem key={o.value} value={o.value}>
                                {o.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {sites.length > 0 ? (
                    <Select
                        value={filters.site_id ?? NONE}
                        onValueChange={(v) => onFilter('site_id', v === NONE ? null : v)}
                    >
                        <SelectTrigger className="h-9 w-32 text-xs" aria-label="Site filter">
                            <SelectValue placeholder="All sites" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>All sites</SelectItem>
                            {sites.map((s) => (
                                <SelectItem key={s.id} value={String(s.id)}>
                                    {s.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                ) : null}

                <div className="flex-1" />

                {onAdd ? (
                    <button
                        type="button"
                        onClick={onAdd}
                        className="inline-flex h-9 items-center gap-1.5 rounded-[9px] bg-primary px-3.5 text-[13px] font-semibold text-primary-foreground hover:brightness-95"
                    >
                        <Plus className="h-[15px] w-[15px]" />
                        Add entry
                    </button>
                ) : null}
            </div>

            {/* table */}
            <div className="overflow-hidden rounded-2xl border border-border bg-card">
                <div className="grid grid-cols-[1.4fr_1fr_1fr_0.9fr_1.3fr] gap-3 border-b border-border px-[18px] py-2.5 text-[10.5px] font-bold uppercase tracking-[0.06em] text-muted-foreground">
                    <span>Staff</span>
                    <span>Date</span>
                    <span>Clock in / out</span>
                    <span>Hours</span>
                    <span>Pay &amp; status</span>
                </div>

                {entries.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-2 px-5 py-14 text-center">
                        <span className="grid h-[46px] w-[46px] place-items-center rounded-[14px] bg-muted text-muted-foreground">
                            <Search className="h-[22px] w-[22px]" />
                        </span>
                        <div className="text-[14px] font-bold">No entries match</div>
                        <div className="text-[12.5px] text-muted-foreground">
                            Try clearing a filter or widening the date range.
                        </div>
                        {hasFilters ? (
                            <button
                                type="button"
                                onClick={onClearFilters}
                                className="mt-1 h-8 rounded-lg border border-border bg-card px-3.5 text-[12.5px] font-semibold hover:bg-muted"
                            >
                                Clear filters
                            </button>
                        ) : null}
                    </div>
                ) : (
                    entries.data.map((e) => {
                        const flags = entryFlags(e);
                        return (
                            <div
                                key={e.id}
                                onContextMenu={(ev) => onRowContext(e, ev)}
                                className="grid grid-cols-[1.4fr_1fr_1fr_0.9fr_1.3fr] items-center gap-3 border-t border-border px-[18px] py-3 transition-colors hover:bg-muted/60"
                            >
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <span
                                        className="grid h-8 w-8 flex-none place-items-center rounded-full text-[11.5px] font-bold"
                                        style={avatarStyle(e.user_id)}
                                    >
                                        {e.initials}
                                    </span>
                                    <div className="min-w-0">
                                        <div className="truncate text-[13px] font-semibold">
                                            {e.user_name}
                                        </div>
                                        <div className="truncate text-[11.5px] text-muted-foreground">
                                            {e.site_name ?? e.client_name ?? '—'}
                                        </div>
                                    </div>
                                </div>
                                <div className="text-[12.5px]">
                                    <div className="font-semibold">{e.entry_date}</div>
                                    {flags.length > 0 ? (
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            {flags.map((f) => (
                                                <span
                                                    key={f}
                                                    className={cn(
                                                        'rounded border px-1.5 py-0.5 text-[9.5px] font-semibold',
                                                        FLAG_TONE[f] ??
                                                            'border-border bg-muted text-muted-foreground',
                                                    )}
                                                >
                                                    {f}
                                                </span>
                                            ))}
                                        </div>
                                    ) : null}
                                </div>
                                <div className="text-[12.5px] tabular-nums">
                                    {e.clock_in_short} – {e.clock_out_short ?? '·'}
                                    {e.break_minutes > 0 ? (
                                        <div className="text-[11px] text-muted-foreground">
                                            {e.break_minutes}m break
                                        </div>
                                    ) : null}
                                </div>
                                <div className="text-[13px] font-bold tabular-nums">
                                    {e.total_hours != null ? `${e.total_hours}h` : '—'}
                                </div>
                                <div className="flex flex-wrap items-center gap-1.5">
                                    {e.pay_type !== 'standard' ? (
                                        <span className="rounded-full border border-primary/30 bg-primary/10 px-2 py-0.5 text-[10.5px] font-semibold text-primary">
                                            {payTypeLabel(e.pay_type)}
                                        </span>
                                    ) : null}
                                    <StatusBadge
                                        variant={statusVariant(e.status)}
                                        size="sm"
                                    >
                                        {statusLabel(e.status)}
                                    </StatusBadge>
                                    {e.amendment_count > 0 ? (
                                        <button
                                            type="button"
                                            onClick={() => onAmendments(e)}
                                            title="View amendment history"
                                            className="inline-flex items-center gap-1 rounded-full border border-border bg-card px-1.5 py-0.5 text-[10.5px] font-semibold text-muted-foreground hover:bg-muted"
                                        >
                                            <History className="h-[11px] w-[11px]" />
                                            {e.amendment_count}
                                        </button>
                                    ) : null}
                                </div>
                            </div>
                        );
                    })
                )}

                <div className="flex items-center justify-between border-t border-border px-[18px] py-2.5 text-[12px] text-muted-foreground">
                    <span>
                        Showing {entries.data.length} of {entries.total} entries
                    </span>
                    {entries.last_page > 1 ? (
                        <LaravelPagination links={entries.links} />
                    ) : null}
                </div>
            </div>
        </div>
    );
}

export default EntriesPane;
