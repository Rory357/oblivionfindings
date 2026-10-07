import {
    CELL_META,
    cellKind,
    clockLabel,
} from '@/components/clients/profile/mar-day/dose-cell';
import { DoseRecordDialog } from '@/components/clients/profile/mar-day/mar-day-dialogs';
import type {
    DayDose,
    DayMedicine,
    MedicationDay,
} from '@/components/clients/profile/mar-day/types';
import {
    dayLabel,
    shiftDay,
} from '@/components/clients/profile/mar-day/use-medication-day';
import { useDoseRecorder } from '@/components/emar/recording/use-dose-recorder';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDateOnly } from '@/lib/datetime';
import { MarTab } from '@/pages/operations/clients/tabs/mar';
import {
    CalendarDays,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    Pill,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ReadingState } from './reading';
import { SectionCard } from './ui';
import { useRecordJson } from './use-record-json';

export function ChartViewSwitch({
    week,
    onChange,
}: {
    week: boolean;
    onChange: (week: boolean) => void;
}) {
    return (
        <ToggleGroup
            type="single"
            size="sm"
            variant="outline"
            value={week ? 'week' : 'day'}
            onValueChange={(value) => {
                if (value) onChange(value === 'week');
            }}
            aria-label="Chart period"
            className="shrink-0 bg-card"
        >
            <ToggleGroupItem value="day" aria-label="Day">
                <CalendarDays className="size-4" />
                Day
            </ToggleGroupItem>
            <ToggleGroupItem value="week" aria-label="Week">
                <CalendarRange className="size-4" />
                Week
            </ToggleGroupItem>
        </ToggleGroup>
    );
}

export function ChartSection({
    clientId,
    personName,
    canViewControlled,
    view,
    date,
    week,
    onChange,
}: {
    clientId: number;
    personName: string;
    canViewControlled: boolean;
    view: string;
    date: string | null;
    week: boolean;
    onChange: (date: string | null, week: boolean) => void;
}) {
    return (
        <div className="max-w-full min-w-0 space-y-5">
            {week && view !== 'asneeded' ? (
                <WeekChart
                    clientId={clientId}
                    personName={personName}
                    date={date}
                    onChange={onChange}
                />
            ) : (
                <MarTab
                    key={`${clientId}:${date}`}
                    clientId={clientId}
                    personName={personName}
                    canViewControlled={canViewControlled}
                    embedded
                    view={view === 'asneeded' ? 'asneeded' : 'scheduled'}
                    initialDate={date}
                    onDateChange={(next) => onChange(next, false)}
                />
            )}
        </div>
    );
}

function WeekChart({
    clientId,
    personName,
    date,
    onChange,
}: {
    clientId: number;
    personName: string;
    date: string | null;
    onChange: (date: string | null, week: boolean) => void;
}) {
    const { data, load, reload } = useRecordJson<{ days: MedicationDay[] }>(
        `/emar/clients/${clientId}/record/week${date ? `?date=${date}` : ''}`,
    );
    const [detail, setDetail] = useState<{
        day: MedicationDay;
        medicine: DayMedicine;
        dose: DayDose;
    } | null>(null);
    const today = data?.days.find((day) => day.date === day.today);
    const context = useMemo(
        () =>
            today?.recorder?.client
                ? {
                      client: today.recorder.client,
                      date: today.date,
                      witnesses: today.recorder.witnesses,
                      notGivenReasons: today.recorder.not_given_reasons,
                      signedAs: today.recorder.signed_as,
                      prnMedications: today.prn.rows,
                  }
                : null,
        [today],
    );
    const recorder = useDoseRecorder(context, reload);
    if (!data || load !== 'ready')
        return <ReadingState load={load} reload={reload} />;
    const last = data.days.at(-1)!;
    const clinicalKey = (medicine: DayMedicine) =>
        JSON.stringify([
            medicine.id,
            medicine.name,
            medicine.dose,
            medicine.route,
            medicine.is_controlled,
            medicine.requires_witness,
        ]);
    const orders = [
        ...new Map(
            data.days.flatMap((day) =>
                day.medicines.map(
                    (medicine) => [clinicalKey(medicine), medicine] as const,
                ),
            ),
        ).values(),
    ];
    const hidden = data.days.reduce(
        (count, day) => count + day.hidden_controlled.total,
        0,
    );
    return (
        <SectionCard
            title="Seven-day chart"
            className="max-w-full min-w-0"
            right={
                <>
                    <Button
                        variant="outline"
                        aria-label="Previous week"
                        onClick={() => onChange(shiftDay(last.date, -7), true)}
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Button
                        variant="outline"
                        disabled={last.date >= last.today}
                        aria-label="Next week"
                        onClick={() =>
                            onChange(
                                shiftDay(last.date, 7) > last.today
                                    ? last.today
                                    : shiftDay(last.date, 7),
                                true,
                            )
                        }
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => onChange(null, true)}
                    >
                        Today
                    </Button>
                </>
            }
        >
            <p className="text-caption text-muted-foreground">
                {formatDateOnly(data.days[0].date)} –{' '}
                {formatDateOnly(last.date)} · Pacific/Auckland
                {hidden
                    ? ` · ${hidden} controlled doses — details need controlled-medicine access`
                    : ''}
            </p>
            {data.days.some((day) => !day.coverage.complete) && (
                <p role="status" className="text-sm text-status-warning">
                    Some earlier days are outside the chart’s coverage. Their
                    dose records remain in History.
                </p>
            )}
            {orders.length ? (
                <div
                    role="region"
                    aria-label={`Seven-day medication chart for ${personName}. Scroll horizontally to see all days.`}
                    tabIndex={0}
                    className="max-w-full min-w-0 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                    onKeyDown={(event) => {
                        if (event.target !== event.currentTarget) return;
                        const scroller =
                            event.currentTarget.querySelector<HTMLElement>(
                                '.overflow-x-auto',
                            );
                        if (!scroller) return;
                        if (
                            event.key === 'ArrowLeft' ||
                            event.key === 'ArrowRight'
                        ) {
                            event.preventDefault();
                            scroller.scrollBy({
                                left:
                                    scroller.clientWidth *
                                    (event.key === 'ArrowRight' ? 0.8 : -0.8),
                            });
                        } else if (
                            event.key === 'Home' ||
                            event.key === 'End'
                        ) {
                            event.preventDefault();
                            scroller.scrollTo({
                                left:
                                    event.key === 'Home'
                                        ? 0
                                        : scroller.scrollWidth,
                            });
                        }
                    }}
                >
                    <EntityTable
                        className="max-w-full min-w-0"
                        rows={orders}
                        rowKey={clinicalKey}
                        identityLabel="Medicine"
                        identity={(medicine) => ({
                            icon: Pill,
                            name: medicine.name,
                            subline: `${medicine.dose ?? ''} · ${medicine.route ?? ''}`,
                        })}
                        minWidth={1200}
                        rowHeight="content"
                        wrapIdentity
                        actionsFor={() => []}
                        columns={data.days.map((day) => ({
                            key: day.date,
                            label: dayLabel(day.date, day.today, day.tomorrow),
                            width: '1fr',
                            cell: (medicine: DayMedicine) => {
                                const current = day.medicines.find(
                                    (row) =>
                                        clinicalKey(row) ===
                                        clinicalKey(medicine),
                                );
                                const doses = current
                                    ? Object.values(current.cells).flat()
                                    : [];
                                return (
                                    <div className="space-y-1">
                                        {doses.map((dose) => {
                                            const kind = cellKind(
                                                dose,
                                                day.date === day.today,
                                            );
                                            return (
                                                <Button
                                                    key={dose.key}
                                                    variant="outline"
                                                    className="min-h-11 w-full flex-col items-start px-2 py-2 whitespace-normal"
                                                    onClick={() => {
                                                        if (
                                                            day.date ===
                                                                day.today &&
                                                            day.can.record &&
                                                            [
                                                                'due_now',
                                                                'overdue',
                                                            ].includes(kind)
                                                        )
                                                            recorder.recordScheduled(
                                                                dose,
                                                            );
                                                        else if (current)
                                                            setDetail({
                                                                day,
                                                                dose,
                                                                medicine:
                                                                    current,
                                                            });
                                                    }}
                                                >
                                                    <span className="text-caption">
                                                        {clockLabel(dose.time)}
                                                    </span>
                                                    <StatusBadge
                                                        variant={
                                                            CELL_META[kind].tone
                                                        }
                                                    >
                                                        {CELL_META[kind].label}
                                                    </StatusBadge>
                                                </Button>
                                            );
                                        })}
                                        {!doses.length && (
                                            <span className="text-muted-foreground">
                                                {day.coverage.complete
                                                    ? '—'
                                                    : 'Not available'}
                                            </span>
                                        )}
                                    </div>
                                );
                            },
                        }))}
                    />
                </div>
            ) : (
                <p className="text-sm">
                    {hidden
                        ? 'Controlled medicines are present; their details need controlled-medicine access.'
                        : 'No scheduled medicines in this covered period.'}
                </p>
            )}
            {detail && (
                <DoseRecordDialog
                    dose={detail.dose}
                    medicine={detail.medicine}
                    dayLabel={dayLabel(
                        detail.day.date,
                        detail.day.today,
                        detail.day.tomorrow,
                    )}
                    isToday={detail.day.date === detail.day.today}
                    onClose={() => setDetail(null)}
                />
            )}
            {recorder.element}
        </SectionCard>
    );
}
