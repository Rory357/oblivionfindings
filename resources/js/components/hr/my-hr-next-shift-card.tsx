import { Link } from '@inertiajs/react';
import { CalendarPlus, MapPin } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeInZone, formatDurationMinutes, WORKER_TIMEZONE } from '@/lib/datetime';

export type MyHrScheduledShift = {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    site: string;
    shift_type: string;
};

export type MyHrScheduledColleague = {
    id: number;
    name: string;
    first_name: string;
    initials: string;
};

const instant = (value: string | null): number | null => {
    if (!value || !/(?:Z|[+-]\d{2}:\d{2})$/.test(value)) return null;
    const parsed = Date.parse(value);
    return Number.isFinite(parsed) ? parsed : null;
};

/** Roster context only. Elapsed scheduled time never proves attendance or staffing. */
export function MyHrNextShiftCard({
    shift,
    colleagues,
    colleaguesShiftId = null,
    timezone = WORKER_TIMEZONE,
}: {
    shift: MyHrScheduledShift | null;
    colleagues: MyHrScheduledColleague[];
    colleaguesShiftId?: number | null;
    timezone?: string;
}) {
    const [now, setNow] = useState(() => Date.now());
    const shiftId = shift?.id ?? null;
    useEffect(() => {
        if (shiftId === null) return;
        setNow(Date.now());
        const handle = setInterval(() => setNow(Date.now()), 30_000);
        return () => clearInterval(handle);
    }, [shiftId]);
    const start = instant(shift?.starts_at ?? null);
    const end = instant(shift?.ends_at ?? null);
    const hasWindow = start !== null && end !== null && end > start;
    const inWindow = hasWindow && now >= start && now <= end;
    const elapsed = hasWindow ? Math.max(0, Math.min(100, (now - start) / (end - start) * 100)) : 0;
    const kind = shift?.shift_type === 'sleepover' ? 'Sleepover'
        : shift?.shift_type === 'on_call' ? 'On-call'
        : shift?.shift_type === 'standard' ? 'Support shift' : 'Shift';
    const listedColleagues = shift && colleaguesShiftId === shift.id ? colleagues : [];

    return (
        <section aria-label="Scheduled shift" className="min-w-0 rounded-xl border bg-card p-6 text-card-foreground">
            <div className="flex flex-wrap items-start gap-3">
                <MapPin className="mt-1 size-5 shrink-0 text-primary" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                    <p className="text-caption text-muted-foreground">Your roster</p>
                    <h2 className="text-section-title break-words">{shift ? shift.site : 'No upcoming shift shown'}</h2>
                </div>
                {shift && <StatusBadge variant="neutral">Planned</StatusBadge>}
            </div>
            {shift ? (
                <>
                    {hasWindow ? (
                        <div className="mt-4 space-y-3">
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <div><dt className="text-caption text-muted-foreground">Scheduled start</dt>
                                    <dd className="text-subtle">{formatDateTimeInZone(start, timezone)}</dd></div>
                                <div><dt className="text-caption text-muted-foreground">Scheduled finish</dt>
                                    <dd className="text-subtle">{formatDateTimeInZone(end, timezone)}</dd></div>
                            </dl>
                            <p className="text-caption text-muted-foreground">
                                {kind} · Scheduled duration: {formatDurationMinutes((end - start) / 60_000)} · {timezone}
                            </p>
                            {inWindow && (
                                <div className="space-y-2">
                                    <p className="text-caption text-muted-foreground">Scheduled window elapsed</p>
                                    <div role="progressbar" aria-label="Scheduled window elapsed"
                                        aria-valuenow={Math.round(elapsed)} aria-valuemin={0} aria-valuemax={100}
                                        className="h-2 overflow-hidden rounded-full bg-muted">
                                        <div className="h-full rounded-full bg-primary" style={{ width: `${elapsed}%` }} />
                                    </div>
                                </div>
                            )}
                            <p className="text-caption text-muted-foreground">
                                These are rostered times. Your clock record shows recorded work and breaks.
                            </p>
                        </div>
                    ) : (
                        <p className="mt-4 text-subtle text-muted-foreground">Schedule times are unavailable. Check the roster for this shift.</p>
                    )}
                    <p className="mt-4 break-words text-subtle text-muted-foreground">
                        {listedColleagues.length ? `Listed colleagues: ${listedColleagues.slice(0, 3).map((person) => person.name).join(', ')}${listedColleagues.length > 3 ? ` +${listedColleagues.length - 3}` : ''}`
                            : 'No colleagues are shown for this shift.'}
                    </p>
                </>
            ) : (
                <p className="mt-4 text-subtle text-muted-foreground">Check your roster for the latest scheduled work.</p>
            )}
            <div className="mt-5 flex flex-wrap gap-2">
                {shift && <Button variant="outline" className="frontline-tap" asChild>
                    <a href={`/hr/my/time/shifts/${shift.id}/calendar`}><CalendarPlus aria-hidden="true" />Add to calendar</a>
                </Button>}
                <Button className="frontline-tap" asChild><Link href="/hr/my/time">View roster</Link></Button>
            </div>
        </section>
    );
}
