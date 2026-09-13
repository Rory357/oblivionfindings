import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime, formatTime, toDateInput } from '@/lib/datetime';
import {
    CalendarWorkRows,
    WorkSchedule,
    type CalendarWorkEntry,
} from '@/pages/sites/calendar/_parts';
import { Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    Pill,
    Plus,
    StickyNote,
} from 'lucide-react';
import type { StreamItem } from '../lib/stream-grouping';
import type { MyDayResident, MyDayShift } from '../lib/types';
import { groupWork, workDueAt, workIsDone } from '../lib/work-priority';

interface Props {
    items: StreamItem[];
    residents: MyDayResident[];
    now: number;
    canAdd: boolean;
    hasShift: boolean;
    shift?: MyDayShift | null;
    filtered: boolean;
    onClearFilters: () => void;
    onAdd: (at?: number) => void;
    onOpenTask: (id: number) => void;
    onAddNote: (clientId: number) => void;
}

export function DayWorkList(p: Props) {
    const groups = groupWork(p.items, p.now);
    const entry = (item: StreamItem): CalendarWorkEntry => {
        const done = workIsDone(item);
        const at = workDueAt(item);
        const steps = item.kind === 'task' ? (item.data.steps ?? []) : [];
        const help = item.kind === 'task' ? item.data.help : null;
        const status = done
            ? item.kind === 'task'
                ? 'Done'
                : { given: 'Given', refused: 'Refused', withheld: 'Withheld' }[
                      item.data.status as 'given' | 'refused' | 'withheld'
                  ]
            : item.kind === 'med' && item.data.status === 'overdue'
              ? 'Overdue'
              : at <= p.now
                ? 'Due now'
                : 'To do';
        return {
            key: `${item.kind}-${item.data.id}`,
            at: Number.isFinite(at) ? at : null,
            title:
                item.kind === 'task'
                    ? item.data.label
                    : `${item.data.medication_name} · ${item.data.dose}`,
            person:
                p.residents.find((person) => person.id === item.clientId)
                    ?.name ??
                (item.kind === 'med' ? item.data.client_name : 'Whole site'),
            icon: item.kind === 'task' ? ClipboardList : Pill,
            source: item.kind === 'task' ? 'checklist' : 'medication',
            meta: [
                item.kind === 'task'
                    ? (item.data.source_label ?? 'Shift task')
                    : 'Medication schedule',
                steps.length
                    ? `${steps.filter((step) => step.is_completed).length}/${steps.length} steps done`
                    : '',
                help
                    ? `${help.recipient_name} · ${help.status === 'accepted' ? 'accepted' : 'help requested'}`
                    : '',
            ]
                .filter(Boolean)
                .join(' · '),
            status: (
                <StatusBadge
                    variant={
                        done ? 'success' : at <= p.now ? 'warning' : 'neutral'
                    }
                >
                    {status}
                </StatusBadge>
            ),
            openLabel: item.kind === 'task' ? 'Open task' : 'Open meds',
            onOpen: () =>
                item.kind === 'task'
                    ? p.onOpenTask(item.data.id)
                    : router.visit(
                          `/meds/today?client_id=${item.data.client_id}`,
                      ),
            detail: steps.length ? (
                <ul className="space-y-2 pb-2">
                    {steps.map((step) => (
                        <li
                            key={step.id}
                            className="flex items-center gap-2 text-sm"
                        >
                            <CheckCircle2
                                className={`size-4 ${step.is_completed ? 'text-status-success' : 'text-muted-foreground'}`}
                            />
                            {step.label}
                            <span className="text-subtle">
                                {step.is_completed ? 'Done' : 'To do'}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : undefined,
            actions: [
                {
                    label: item.kind === 'task' ? 'Open task' : 'Open meds',
                    icon: ArrowRight,
                    onClick: () =>
                        item.kind === 'task'
                            ? p.onOpenTask(item.data.id)
                            : router.visit(
                                  `/meds/today?client_id=${item.data.client_id}`,
                              ),
                },
                ...(item.clientId
                    ? [
                          {
                              label: 'Add daily note',
                              icon: StickyNote,
                              onClick: () => p.onAddNote(item.clientId!),
                          },
                          {
                              label: 'Open care plan',
                              icon: ClipboardList,
                              onClick: () =>
                                  router.visit(
                                      `/clients/${item.clientId}?tab=care_plans`,
                                  ),
                          },
                      ]
                    : []),
            ],
        };
    };
    const startsAt = p.shift ? Date.parse(p.shift.starts_at) : p.now;
    const endsAt = p.shift ? Date.parse(p.shift.ends_at) : p.now + 3_600_000;
    const range =
        toDateInput(startsAt) === toDateInput(endsAt)
            ? `${formatTime(startsAt)}–${formatTime(endsAt)}`
            : `${formatDateTime(startsAt)} – ${formatDateTime(endsAt)}`;
    return (
        <section
            aria-labelledby="day-work-title"
            className="space-y-4"
            id="shift-tasks"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="day-work-title" className="text-section-title">
                        What needs to happen today
                    </h2>
                    <p className="text-subtle mt-1">
                        Open an item to see what to do and record the outcome.
                    </p>
                </div>
                {p.canAdd && (
                    <Button className="frontline-tap" onClick={() => p.onAdd()}>
                        <Plus className="size-4" />
                        Add task
                    </Button>
                )}
            </div>
            {p.filtered && (
                <Card
                    unstyled
                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-card p-3"
                >
                    <p className="text-sm">
                        Showing matching work. Shift alerts stay visible above.
                    </p>
                    <Button
                        variant="ghost"
                        className="frontline-tap"
                        onClick={p.onClearFilters}
                    >
                        Clear filters
                    </Button>
                </Card>
            )}
            {!p.hasShift ? (
                <Card>
                    <CardContent className="space-y-3 p-6">
                        <ClipboardList className="size-6 text-primary" />
                        <h3 className="text-section-title">
                            No rostered shift to show
                        </h3>
                        <p className="text-subtle">
                            This page shows your own rostered work, including
                            when you are an administrator.
                        </p>
                        <Button
                            asChild
                            variant="outline"
                            className="frontline-tap"
                        >
                            <Link href="/my-calendar">
                                <CalendarDays className="size-4" />
                                View my roster
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            ) : (
                <>
                    {groups.due.length > 0 && (
                        <Card
                            unstyled
                            className="overflow-hidden rounded-xl border bg-card"
                        >
                            <h3 className="border-b bg-status-warning-bg px-4 py-3 font-semibold text-status-warning">
                                Due now ({groups.due.length})
                            </h3>
                            <CalendarWorkRows
                                entries={groups.due.map(entry)}
                                now={p.now}
                            />
                        </Card>
                    )}
                    <WorkSchedule
                        entries={groups.later.map(entry)}
                        anytime={groups.anytime.map(entry)}
                        now={p.now}
                        startsAt={startsAt}
                        endsAt={endsAt}
                        caption={`${p.shift?.site?.name ?? p.shift?.location ?? 'Your shift'} · ${range}`}
                        onAddAt={p.canAdd ? p.onAdd : undefined}
                        onAddAnytime={p.canAdd ? () => p.onAdd() : undefined}
                    />
                    {groups.followedUp.length > 0 && (
                        <Card
                            unstyled
                            className="overflow-hidden rounded-xl border bg-card"
                        >
                            <div className="border-b p-4">
                                <h3 className="text-section-title">
                                    Being followed up (
                                    {groups.followedUp.length})
                                </h3>
                                <p className="text-subtle mt-1">
                                    A colleague has accepted responsibility.
                                    These tasks are still open.
                                </p>
                            </div>
                            <CalendarWorkRows
                                entries={groups.followedUp.map(entry)}
                                now={p.now}
                            />
                        </Card>
                    )}
                    {groups.completed.length > 0 && (
                        <details className="overflow-hidden rounded-xl border bg-card">
                            <summary className="frontline-focus min-h-11 cursor-pointer rounded-md p-4 font-semibold">
                                Completed / recorded ({groups.completed.length})
                            </summary>
                            <CalendarWorkRows
                                entries={groups.completed.map(entry)}
                                now={p.now}
                            />
                        </details>
                    )}
                    {p.items.length > 0 && p.items.every(workIsDone) && (
                        <p className="flex items-center gap-2 text-sm text-status-success">
                            <CheckCircle2 className="size-5" />
                            All displayed work has an outcome recorded.
                        </p>
                    )}
                </>
            )}
        </section>
    );
}
