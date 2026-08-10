import type {
    DatesSetArg,
    EventClickArg,
    EventInput,
} from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import listPlugin from '@fullcalendar/list';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { CalendarDays, ExternalLink } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import { CalendarView } from '@/components/calendar/calendar-view';
import { formatMoney } from '@/components/finance/money';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem, PageProps } from '@/types';

/** One finance obligation as returned by FinanceCalendarController@events. */
interface FinanceObligation {
    id: string;
    source: string;
    title: string;
    start: string;
    status: string;
    amount: number | null;
    direction: 'inflow' | 'outflow' | null;
    ref: string | null;
    counterparty: string | null;
    link: string | null;
    meta: Record<string, string | null>;
}

interface Props extends PageProps {
    /** URL of the JSON event feed (FinanceCalendarController@events). */
    eventsUrl: string;
    /** Source keys this calendar can emit, for the legend. */
    sources: string[];
}

/**
 * Per-source presentation. Colours are design tokens (complete oklch values), so
 * they track the theme — never hex. Overdue items override to the critical token
 * regardless of source, so a missed deadline always reads red.
 */
const SOURCE_META: Record<
    string,
    { label: string; color: string; text: string }
> = {
    invoice_due: {
        label: 'Invoice due',
        color: 'var(--status-success)',
        text: 'var(--status-success-foreground)',
    },
    bill_due: {
        label: 'Bill due',
        color: 'var(--status-warning)',
        text: 'var(--status-warning-foreground)',
    },
    payment_run: {
        label: 'Payment run',
        color: 'var(--category-finance)',
        text: 'var(--primary-foreground)',
    },
    gst_due: {
        label: 'GST return',
        color: 'var(--status-info)',
        text: 'var(--status-info-foreground)',
    },
};
const OVERDUE = {
    color: 'var(--status-critical)',
    text: 'var(--status-critical-foreground)',
};

function sourceLabel(key: string): string {
    return SOURCE_META[key]?.label ?? key;
}

const STATUS_TONE: Record<string, string> = {
    due: 'bg-status-info-bg text-status-info',
    overdue: 'bg-status-critical-bg text-status-critical',
    paid: 'bg-status-success-bg text-status-success',
    processed: 'bg-status-success-bg text-status-success',
    filed: 'bg-status-success-bg text-status-success',
    scheduled: 'bg-category-finance-bg text-category-finance',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Calendar', href: '/finance/calendar' },
];

export default function FinanceCalendar({ eventsUrl, sources }: Props) {
    const [obligations, setObligations] = useState<FinanceObligation[]>([]);
    const [activeSources, setActiveSources] = useState<string[]>(sources);
    const [loading, setLoading] = useState(false);
    const [selected, setSelected] = useState<FinanceObligation | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);

    // FullCalendar fires datesSet on first render and on every month nav, so the
    // initial load and subsequent fetches both flow through here.
    const handleDatesSet = useCallback(
        async (arg: DatesSetArg) => {
            setLoading(true);
            try {
                const { data } = await axios.get<{
                    events: FinanceObligation[];
                }>(eventsUrl, {
                    params: {
                        start: arg.startStr.slice(0, 10),
                        end: arg.endStr.slice(0, 10),
                    },
                });
                setObligations(data.events ?? []);
            } finally {
                setLoading(false);
            }
        },
        [eventsUrl],
    );

    const calendarEvents = useMemo<EventInput[]>(
        () =>
            obligations
                .filter((o) => activeSources.includes(o.source))
                .map((o) => {
                    const meta = SOURCE_META[o.source];
                    const overdue = o.status === 'overdue';
                    const color = overdue
                        ? OVERDUE.color
                        : (meta?.color ?? 'var(--primary)');
                    const text = overdue
                        ? OVERDUE.text
                        : (meta?.text ?? 'var(--primary-foreground)');
                    return {
                        id: o.id,
                        title:
                            o.amount != null
                                ? `${o.title} · ${formatMoney(o.amount)}`
                                : o.title,
                        start: o.start,
                        allDay: true,
                        backgroundColor: color,
                        borderColor: color,
                        textColor: text,
                        extendedProps: { obligation: o },
                    };
                }),
        [obligations, activeSources],
    );

    // Hero stats for the loaded range.
    const stats = useMemo(() => {
        const visible = obligations.filter((o) =>
            activeSources.includes(o.source),
        );
        const overdue = visible.filter((o) => o.status === 'overdue').length;
        const inflow = visible
            .filter((o) => o.direction === 'inflow')
            .reduce((sum, o) => sum + (o.amount ?? 0), 0);
        const outflow = visible
            .filter((o) => o.direction === 'outflow')
            .reduce((sum, o) => sum + (o.amount ?? 0), 0);
        return [
            { label: 'Obligations', value: String(visible.length) },
            { label: 'Overdue', value: String(overdue) },
            { label: 'Money in', value: formatMoney(inflow) },
            { label: 'Money out', value: formatMoney(outflow) },
        ];
    }, [obligations, activeSources]);

    function handleEventClick(info: EventClickArg) {
        const obligation = info.event.extendedProps.obligation as
            | FinanceObligation
            | undefined;
        if (obligation) {
            setSelected(obligation);
            setDetailOpen(true);
        }
    }

    function toggleSource(key: string) {
        setActiveSources((prev) =>
            prev.includes(key) ? prev.filter((s) => s !== key) : [...prev, key],
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Finance Calendar" />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        icon={CalendarDays}
                        title="Finance Calendar"
                        description="Upcoming money obligations — invoice and bill due dates, scheduled payment runs, and GST filing deadlines."
                        stats={stats}
                    />
                }
            >
                {/* Source legend / filter */}
                <div className="flex flex-wrap items-center gap-2">
                    {sources.map((key) => {
                        const meta = SOURCE_META[key];
                        const on = activeSources.includes(key);
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- intentional legend filter chip (selector), not a standard action button
                            <button
                                key={key}
                                type="button"
                                onClick={() => toggleSource(key)}
                                aria-pressed={on}
                                className={cn(
                                    'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition',
                                    on
                                        ? 'border-border bg-card text-foreground'
                                        : 'border-transparent bg-muted/40 text-muted-foreground opacity-60',
                                )}
                            >
                                <span
                                    className="h-2.5 w-2.5 rounded-full"
                                    style={{
                                        backgroundColor:
                                            meta?.color ?? 'var(--primary)',
                                    }}
                                />
                                {sourceLabel(key)}
                            </button>
                        );
                    })}
                    <span className="ml-auto inline-flex items-center gap-2 text-sm text-muted-foreground">
                        <span
                            className="h-2.5 w-2.5 rounded-full"
                            style={{ backgroundColor: OVERDUE.color }}
                        />
                        Overdue
                        {loading ? (
                            <span className="ml-2 animate-pulse">
                                · loading…
                            </span>
                        ) : null}
                    </span>
                </div>

                <div className="mt-4 rounded-2xl border border-border/40 bg-card p-3 sm:p-5">
                    <CalendarView
                        plugins={[dayGridPlugin, listPlugin, interactionPlugin]}
                        initialView="dayGridMonth"
                        events={calendarEvents}
                        datesSet={handleDatesSet}
                        eventClick={handleEventClick}
                        eventDisplay="block"
                        headerToolbar={{
                            left: 'prev,next today',
                            center: 'title',
                            right: 'dayGridMonth,listMonth',
                        }}
                        buttonText={{
                            today: 'Today',
                            month: 'Month',
                            list: 'List',
                        }}
                        noEventsContent="No obligations in this period"
                    />
                </div>
            </PageLayout>

            <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{selected?.title}</DialogTitle>
                    </DialogHeader>

                    {selected && (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline">
                                    {sourceLabel(selected.source)}
                                </Badge>
                                <span
                                    className={cn(
                                        'rounded-full px-2.5 py-0.5 text-xs font-medium capitalize',
                                        STATUS_TONE[selected.status] ??
                                            'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {selected.status}
                                </span>
                                {selected.direction && (
                                    <span className="text-xs text-muted-foreground">
                                        {selected.direction === 'inflow'
                                            ? 'Money in'
                                            : 'Money out'}
                                    </span>
                                )}
                            </div>

                            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                                {selected.ref && (
                                    <Field
                                        label="Reference"
                                        value={selected.ref}
                                    />
                                )}
                                {selected.counterparty && (
                                    <Field
                                        label="Counterparty"
                                        value={selected.counterparty}
                                    />
                                )}
                                {selected.amount != null && (
                                    <Field
                                        label="Amount"
                                        value={formatMoney(selected.amount)}
                                        strong
                                    />
                                )}
                                <Field label="Date" value={selected.start} />
                                {selected.meta?.period_start &&
                                    selected.meta?.period_end && (
                                        <Field
                                            label="Period"
                                            value={`${selected.meta.period_start} – ${selected.meta.period_end}`}
                                        />
                                    )}
                            </dl>
                        </div>
                    )}

                    <DialogFooter className="gap-2 sm:justify-between">
                        {selected?.link ? (
                            <Button asChild variant="default" size="sm">
                                <Link href={selected.link}>
                                    <ExternalLink className="mr-1.5 h-4 w-4" />
                                    Open record
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setDetailOpen(false)}
                        >
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function Field({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: string;
    strong?: boolean;
}) {
    return (
        <div>
            <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd
                className={cn(
                    'mt-0.5',
                    strong ? 'font-semibold' : 'text-foreground',
                )}
            >
                {value}
            </dd>
        </div>
    );
}
