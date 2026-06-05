import { addDaysWP, ymd } from '@/components/rostering/week-picker';
import { cn } from '@/lib/utils';

import { catColorVar } from '../category';
import { useChecklistConfig, type PaneCtx } from '../context';
import type { ChecklistRun } from '../types';

// Local-date math only — toISOString() would shift the day back in NZ (UTC+12/13).
function addDays(iso: string, n: number): string {
    return ymd(addDaysWP(new Date(`${iso}T00:00:00`), n));
}

export function SchedulePane({ ctx, weekStart }: { ctx: PaneCtx; weekStart: string }) {
    const { categoryMap, openRun } = useChecklistConfig();
    const today = ctx.today;
    const days = Array.from({ length: 7 }, (_, i) => addDays(weekStart, i));
    const weekEnd = days[6];
    const isCurrentWeek = today >= weekStart && today <= weekEnd;
    const overdue = ctx.runs.filter((r) => r.scheduled_date && r.scheduled_date < today);

    const rangeLabel = `${new Date(`${weekStart}T00:00:00`).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    })} – ${new Date(`${weekEnd}T00:00:00`).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}`;

    return (
        <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
            <div className="flex items-center justify-between border-b border-border px-5 py-3.5">
                <div>
                    <h3 className="text-base font-semibold">Schedule</h3>
                    <p className="text-sm text-muted-foreground">{rangeLabel} · scheduled &amp; overdue runs</p>
                </div>
                <p className="text-xs text-muted-foreground">Use the week stepper in the banner to change weeks</p>
            </div>
            <div className="grid grid-cols-1 divide-y divide-border md:grid-cols-7 md:divide-x md:divide-y-0">
                {days.map((ds, i) => {
                    const isToday = ds === today;
                    const items = ctx.runs.filter((r) => r.scheduled_date === ds);
                    const show: ChecklistRun[] = isToday && isCurrentWeek ? [...overdue, ...items] : items;
                    const date = new Date(`${ds}T00:00:00`);
                    return (
                        <div key={i} className={cn('min-h-[200px] p-2.5', isToday && 'bg-primary/5')}>
                            <div className="mb-2 flex items-center justify-between">
                                <div
                                    className={cn(
                                        'text-xs font-semibold uppercase tracking-wide',
                                        isToday ? 'text-primary' : 'text-muted-foreground',
                                    )}
                                >
                                    {date.toLocaleDateString('en-NZ', { weekday: 'short' })}
                                </div>
                                <div
                                    className={cn(
                                        'flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold',
                                        isToday ? 'bg-primary text-primary-foreground' : 'text-foreground',
                                    )}
                                >
                                    {date.getDate()}
                                </div>
                            </div>
                            <div className="space-y-1.5">
                                {show.map((r) => {
                                    const tone = r.template?.category
                                        ? categoryMap[r.template.category]?.tone
                                        : undefined;
                                    const isOverdue =
                                        r.scheduled_date != null && r.scheduled_date < today && r.status !== 'completed';
                                    return (
                                        <button
                                            key={`${r.id}-${ds}`}
                                            type="button"
                                            onClick={() => openRun(r.id)}
                                            className="block w-full rounded-md border-l-2 bg-card px-2 py-1.5 text-left shadow-sm transition hover:bg-accent/40"
                                            style={{ borderLeftColor: catColorVar(tone) }}
                                        >
                                            <div className="flex items-center gap-1">
                                                {isOverdue ? (
                                                    <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-status-critical" />
                                                ) : null}
                                                <span className="truncate text-[11px] font-medium leading-tight">
                                                    {r.template?.name}
                                                </span>
                                            </div>
                                            <span className="truncate text-[10px] text-muted-foreground">
                                                {r.site?.name}
                                            </span>
                                        </button>
                                    );
                                })}
                                {show.length === 0 ? (
                                    <div className="py-3 text-center text-[11px] text-muted-foreground/50">—</div>
                                ) : null}
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
