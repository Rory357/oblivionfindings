/**
 * Stays pane — in-house vs completed, with a live day-N progress bar, a
 * presence dot, inline check-in (admitted → active), and a detail pop-up.
 */
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { CalendarDays, Eye, Home, LogIn, MapPin } from 'lucide-react';
import { useState } from 'react';
import { respiteActions } from '../actions';
import { Avatar, fmtDate, StatusBadge } from '../shared';
import { Empty, FilterChip, PaneHead } from '../pane-kit';
import type { RespiteCan, RespiteStayRow } from '../types';

function dayProgress(s: RespiteStayRow): { dayOf: number; total: number; pct: number } | null {
    if (!s.actualStart || !s.plannedEnd) return null;
    const start = new Date(s.actualStart).getTime();
    const end = new Date(s.plannedEnd).getTime();
    if (Number.isNaN(start) || Number.isNaN(end) || end <= start) return null;
    const total = Math.max(1, Math.round((end - start) / 8.64e7));
    const dayOf = Math.min(total, Math.max(1, Math.floor((Date.now() - start) / 8.64e7) + 1));
    return { dayOf, total, pct: Math.min(100, Math.round((dayOf / total) * 100)) };
}

export function StaysPane({
    stays,
    can,
    onView,
}: {
    stays: RespiteStayRow[];
    can: RespiteCan;
    onView: (row: RespiteStayRow) => void;
}) {
    const [scope, setScope] = useState<'live' | 'completed' | 'all'>('live');

    const rows = stays.filter((s) =>
        scope === 'all' ? true : scope === 'live' ? s.live || s.status === 'admitted' : s.status === 'discharged',
    );
    const inHouse = stays.filter((s) => s.live).length;

    return (
        <div>
            <PaneHead icon={Home} title="Stays" count={`${inHouse} in house`}>
                {(
                    [
                        ['live', 'In house'],
                        ['completed', 'Completed'],
                        ['all', 'All'],
                    ] as const
                ).map(([k, label]) => (
                    <FilterChip key={k} active={scope === k} onClick={() => setScope(k)}>
                        {label}
                    </FilterChip>
                ))}
            </PaneHead>

            <div className="grid gap-2.5">
                {rows.map((s) => (
                    <StayCard key={s.id} s={s} can={can} onView={onView} />
                ))}
                {rows.length === 0 ? <Empty icon={Home} title="No stays here" /> : null}
            </div>
        </div>
    );
}

function StayCard({ s, can, onView }: { s: RespiteStayRow; can: RespiteCan; onView: (row: RespiteStayRow) => void }) {
    const prog = s.live ? dayProgress(s) : null;
    return (
        <div className="rounded-[14px] border border-border bg-card p-4 transition-shadow hover:shadow-sm">
            <div className="flex items-start gap-3.5">
                <div className="relative">
                    <Avatar name={s.client} className="h-11 w-11 text-sm" />
                    {s.live ? <span className="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-card bg-status-success" /> : null}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2.5">
                        <span className="text-[15px] font-bold">{s.client}</span>
                        {prog ? (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-status-success-bg px-2.5 py-0.5 text-[11px] font-semibold text-status-success">
                                Day {prog.dayOf} of {prog.total}
                            </span>
                        ) : (
                            <StatusBadge status={s.status} />
                        )}
                        <span className="ml-auto text-[11.5px] text-muted-foreground">{s.ref}</span>
                    </div>
                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {s.site ? <span className="inline-flex items-center gap-1"><MapPin className="h-3.5 w-3.5" />{s.site}</span> : null}
                        <span className="inline-flex items-center gap-1">
                            <CalendarDays className="h-3.5 w-3.5" />
                            {fmtDate(s.actualStart)} → {fmtDate(s.live ? s.plannedEnd : s.actualEnd)}
                        </span>
                    </div>
                    {prog ? (
                        <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-muted">
                            <div className={cn('h-full rounded-full bg-status-success')} style={{ width: `${prog.pct}%` }} />
                        </div>
                    ) : null}
                </div>
                <div className="flex shrink-0 flex-col justify-center gap-1.5">
                    {can.staysManage && s.status === 'admitted' ? (
                        <Button size="sm" onClick={() => respiteActions.checkInStay(s.id)}>
                            <LogIn className="h-3.5 w-3.5" /> Check in
                        </Button>
                    ) : null}
                    <Button size="sm" variant="outline" onClick={() => onView(s)}>
                        <Eye className="h-3.5 w-3.5" /> View
                    </Button>
                </div>
            </div>
        </div>
    );
}
