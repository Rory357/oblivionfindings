/* eslint-disable no-restricted-syntax -- The read-only timesheets window uses
 * styled native <button>s for the segmented filter and the inline deep-link to
 * Operations (custom layout surfaces, not shadcn <Button> cases). */
import { router } from '@inertiajs/react';
import { ArrowUpRight, Info } from 'lucide-react';
import { useState } from 'react';

import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';

import {
    avatarStyle,
    statusLabel,
    statusVariant,
    type PaginatedData,
    type TimesheetRow,
} from './types';

function initialsFor(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((w) => w[0]?.toUpperCase() ?? '')
            .join('') || '—'
    );
}

export function TimesheetsPane({
    timesheets,
    canApproveAny,
}: {
    timesheets: PaginatedData<TimesheetRow>;
    canApproveAny: boolean;
}) {
    const [segment, setSegment] = useState<'all' | 'submitted' | 'approved'>(
        'all',
    );

    const rows = timesheets.data.filter((t) =>
        segment === 'all'
            ? true
            : segment === 'submitted'
              ? t.status === 'submitted'
              : t.status === 'approved',
    );

    const segments: { v: 'all' | 'submitted' | 'approved'; l: string }[] = [
        { v: 'all', l: 'All' },
        { v: 'submitted', l: 'Awaiting approval' },
        { v: 'approved', l: 'Recently approved' },
    ];

    return (
        <div className="flex flex-col gap-4">
            {/* honest framing banner */}
            <div className="flex items-start gap-2.5 rounded-xl border border-primary/25 bg-accent px-4 py-3">
                <Info className="mt-0.5 h-[18px] w-[18px] flex-none text-primary" />
                <div className="text-[12.5px] leading-relaxed text-foreground">
                    <span className="font-bold">Read-only.</span> Shift
                    timesheets are owned by Operations. Approve, reject, return
                    and bulk actions happen in the{' '}
                    <button
                        type="button"
                        onClick={() => router.visit('/operations/timesheets')}
                        className="font-bold text-primary underline"
                    >
                        Operations timesheet flow ↗
                    </button>
                    . This is a payroll-readiness view of per-shift hours,
                    bucketed by week.
                </div>
            </div>

            {canApproveAny ? (
                <div className="inline-flex w-fit gap-0.5 rounded-[10px] bg-muted p-[3px]">
                    {segments.map((s) => {
                        const active = segment === s.v;
                        return (
                            <button
                                key={s.v}
                                type="button"
                                onClick={() => setSegment(s.v)}
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

            <div className="overflow-hidden rounded-2xl border border-border bg-card">
                <div className="grid grid-cols-[1.4fr_1.2fr_1fr_0.8fr_1fr_90px] gap-3 border-b border-border px-[18px] py-2.5 text-[10.5px] font-bold tracking-[0.06em] text-muted-foreground uppercase">
                    <span>Staff</span>
                    <span>Period</span>
                    <span>Client</span>
                    <span>Hours</span>
                    <span>Status</span>
                    <span />
                </div>

                {rows.length === 0 ? (
                    <div className="px-5 py-14 text-center text-[13px] font-semibold text-muted-foreground">
                        No timesheets in this view.
                    </div>
                ) : (
                    rows.map((t) => (
                        <div
                            key={t.id}
                            className="group grid grid-cols-[1.4fr_1.2fr_1fr_0.8fr_1fr_90px] items-center gap-3 border-t border-border px-[18px] py-3 transition-colors hover:bg-muted/60"
                        >
                            <div className="flex min-w-0 items-center gap-2.5">
                                <span
                                    className="grid h-8 w-8 flex-none place-items-center rounded-full text-[11.5px] font-bold"
                                    style={avatarStyle(t.user_id)}
                                >
                                    {initialsFor(t.user_name)}
                                </span>
                                <span className="truncate text-[13px] font-semibold">
                                    {t.user_name}
                                </span>
                            </div>
                            <div className="text-[12.5px] text-muted-foreground tabular-nums">
                                {t.period_start} → {t.period_end}
                            </div>
                            <div className="truncate text-[12.5px]">
                                {t.client_name ?? '—'}
                            </div>
                            <div className="text-[13px] font-bold tabular-nums">
                                {t.total_hours != null
                                    ? `${t.total_hours}h`
                                    : '—'}
                            </div>
                            <div>
                                <StatusBadge
                                    variant={statusVariant(t.status)}
                                    size="sm"
                                >
                                    {statusLabel(t.status)}
                                </StatusBadge>
                            </div>
                            <a
                                href={t.module_url}
                                className="inline-flex h-[30px] items-center gap-1 justify-self-end rounded-lg border border-border bg-card px-2.5 text-[12px] font-semibold opacity-0 transition-opacity group-hover:opacity-100 focus-visible:opacity-100 motion-reduce:opacity-100"
                            >
                                Open
                                <ArrowUpRight className="h-3.5 w-3.5" />
                            </a>
                        </div>
                    ))
                )}

                {timesheets.last_page > 1 ? (
                    <div className="flex items-center justify-end border-t border-border px-[18px] py-2.5">
                        <LaravelPagination links={timesheets.links} />
                    </div>
                ) : null}
            </div>
        </div>
    );
}

export default TimesheetsPane;
