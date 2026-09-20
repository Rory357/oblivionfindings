import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { formatDateOnly } from '@/lib/datetime';
import { CalendarDays } from 'lucide-react';
import { useRef } from 'react';

export function MaintenanceDateRange({ title, hint, start, end, onChange, optional = false }: {
    title: string; hint: string; start: string | null; end: string | null;
    onChange: (start: string | null, end: string | null) => void; optional?: boolean;
}) {
    const calendar = useRef<HTMLDivElement>(null);
    const days = start && end ? Math.round((Date.parse(end) - Date.parse(start)) / 86400000) + 1 : null;
    return <section className="space-y-4 rounded-xl border bg-muted/25 p-5" aria-label={title}>
        <div className="flex items-start gap-3"><span className="rounded-lg bg-primary/10 p-2 text-primary"><CalendarDays className="size-4" /></span>
            <div><h3 className="font-semibold">{title}</h3><p className="mt-1 text-xs text-muted-foreground">{hint}</p></div></div>
        {optional && <div className="flex gap-2"><Button type="button" size="sm" variant={start ? 'default' : 'outline'}
            onClick={() => calendar.current?.querySelector<HTMLButtonElement>('button[aria-pressed]')?.focus()}>Choose dates</Button>
            <Button type="button" size="sm" variant={!start ? 'default' : 'outline'} onClick={() => onChange(null, null)}>Not known yet</Button></div>}
        <div ref={calendar}><LeaveCalendarRange start={start} end={end} onChange={onChange} required={!optional} /></div>
        <Card className="flex-row items-center gap-4 p-4 shadow-none" role="status">
            <div className="min-w-20 border-r pr-4 text-center"><strong className="block text-2xl text-primary">{days ?? '—'}</strong>
                <span className="text-[10px] font-semibold uppercase">Calendar days</span></div>
            <div><strong className="text-sm">{start && end ? start === end ? formatDateOnly(start) : formatDateOnly(start) + ' – ' + formatDateOnly(end)
                : start ? 'Choose the end date' : optional ? 'Not known yet' : 'Pick your dates'}</strong>
                <p className="mt-1 text-xs text-muted-foreground">{start && !end ? 'Tap the same day for a one-day window, or choose a later date.'
                    : start ? 'Tap a day to start a new range.' : optional ? 'Dates are optional. Choose a range or continue without an estimate.' : 'Tap a start and end day on the calendar.'}</p></div>
        </Card>
    </section>;
}
