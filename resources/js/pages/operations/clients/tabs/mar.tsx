/* MAR tab — the client profile's medication day (eMAR P02-1b, Stephan's
 * amendment to the approved P02 tab): one NZ day as a paper MAR, medicines ×
 * dose times, each dose in Meds today's words; the as-needed medicines under
 * it; the allergy and chart-alert lines, Open medication record, Record dose
 * and a Report for this person. Recording goes through the one P02 seam
 * (use-dose-recorder). The tab loads its own day (the Fleet profile pattern). */
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BellRing,
    ChevronLeft,
    ChevronRight,
    FileText,
    Pill,
    Plus,
} from 'lucide-react';
import { useCallback, useMemo, useState, type ReactNode } from 'react';

import {
    CELL_META,
    LEGEND_ORDER,
    cellKind,
    clockLabel,
    recordBlock,
} from '@/components/clients/profile/mar-day/dose-cell';
import {
    DoseRecordDialog,
    MedicineDetailsDialog,
} from '@/components/clients/profile/mar-day/mar-day-dialogs';
import {
    MarDayGrid,
    MarDayLegend,
} from '@/components/clients/profile/mar-day/mar-day-grid';
import { PrnStrip } from '@/components/clients/profile/mar-day/prn-strip';
import { MarReportDialog } from '@/components/clients/profile/mar-day/report-dialog';
import type {
    DayDose,
    DayMedicine,
    MedicationDay,
} from '@/components/clients/profile/mar-day/types';
import {
    dayLabel,
    shiftDay,
    useMedicationDay,
} from '@/components/clients/profile/mar-day/use-medication-day';
import { useDoseRecorder } from '@/components/emar/recording/use-dose-recorder';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';

export type MarTabProps = {
    clientId: number;
    /** The name staff use for the person (preferred, else first). */
    personName: string;
    /** The reader sees controlled medicines; otherwise exports leave them out. */
    canViewControlled: boolean;
    /** The record provides the heading and dose launcher; Report stays on the chart. */
    embedded?: boolean;
    view?: 'scheduled' | 'asneeded';
    initialDate?: string | null;
    onDateChange?: (date: string | null) => void;
};

const SEVERITY_WORD: Record<string, string> = {
    severe: 'severe',
    moderate: 'moderate',
    mild: 'mild',
};

/** A safety line in the fixed status pairs (critical / warning). */
function SafetyLine({
    tone,
    icon: Icon,
    title,
    children,
    alert,
}: {
    tone: 'critical' | 'warning';
    icon: typeof AlertTriangle;
    title: ReactNode;
    children?: ReactNode;
    alert?: boolean;
}) {
    return (
        <div
            role={alert ? 'alert' : undefined}
            className={cn(
                'flex items-start gap-3 rounded-lg border px-3 py-2.5',
                tone === 'critical'
                    ? 'border-status-critical/30 bg-status-critical-bg'
                    : 'border-status-warning/30 bg-status-warning-bg',
            )}
        >
            <Icon
                className={cn(
                    'mt-0.5 size-4 shrink-0',
                    tone === 'critical'
                        ? 'text-status-critical'
                        : 'text-status-warning',
                )}
            />
            <div className="min-w-0 text-sm">
                <div
                    className={cn(
                        'font-semibold',
                        tone === 'critical'
                            ? 'text-status-critical'
                            : 'text-status-warning',
                    )}
                >
                    {title}
                </div>
                {children ? (
                    <div className="text-caption text-foreground/80">
                        {children}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

function AllergyLine({
    allergies,
    personName,
}: {
    allergies: MedicationDay['allergies'];
    personName: string;
}) {
    // The review stamp arrives with P02-6 (leads confirm the list); until
    // then every list reads "Not reviewed", honestly.
    const review = allergies.reviewed
        ? `Reviewed by ${allergies.reviewed.by ?? 'recorded reviewer'} · ${formatDateTime(allergies.reviewed.at)} · checked with ${allergies.reviewed.how}`
        : 'Not reviewed — a house lead or clinical lead confirms the list.';
    if (allergies.status === 'no_known')
        return (
            <div className="rounded-lg border p-3 text-sm">
                No known allergies — confirmed after review.
                <p className="text-caption text-muted-foreground">{review}</p>
            </div>
        );
    if (allergies.status === 'unavailable') {
        return (
            <SafetyLine
                tone="warning"
                icon={AlertTriangle}
                alert
                title={`Allergy record couldn’t be loaded for ${personName}`}
            >
                Don’t assume {personName} has none — check the health profile
                before giving anything.
            </SafetyLine>
        );
    }
    if (allergies.status === 'none') {
        return (
            <SafetyLine
                tone="warning"
                icon={AlertTriangle}
                title={`No allergies recorded for ${personName}`}
            >
                This doesn’t mean {personName} has none. {review}
            </SafetyLine>
        );
    }
    const list = allergies.entries
        .map((entry) => {
            const severity = entry.severity
                ? (SEVERITY_WORD[entry.severity.toLowerCase()] ??
                  entry.severity.toLowerCase())
                : null;
            const details = [severity, entry.reaction]
                .filter(Boolean)
                .join(', ');
            return details ? `${entry.allergen} (${details})` : entry.allergen;
        })
        .join(' · ');
    return (
        <SafetyLine
            tone="critical"
            icon={AlertTriangle}
            title={`Allergies: ${list}`}
        >
            Check the label against the allergy list before giving. {review}
        </SafetyLine>
    );
}

export function MarTab({
    clientId,
    personName,
    canViewControlled,
    embedded = false,
    view,
    initialDate = null,
    onDateChange,
}: MarTabProps) {
    const [date, setDate] = useState<string | null>(initialDate);
    const chooseDate = (next: string | null) => {
        setDate(next);
        onDateChange?.(next);
    };
    const { load, reload } = useMedicationDay(clientId, date);
    const day = load.data;
    const [openDose, setOpenDose] = useState<{
        dose: DayDose;
        medicine: DayMedicine;
    } | null>(null);
    const [details, setDetails] = useState<DayMedicine | null>(null);
    const [reportOpen, setReportOpen] = useState(false);

    const recorderContext = useMemo(
        () =>
            day?.recorder?.client
                ? {
                      client: day.recorder.client,
                      date: day.date,
                      witnesses: day.recorder.witnesses,
                      notGivenReasons: day.recorder.not_given_reasons,
                      signedAs: day.recorder.signed_as,
                      prnMedications: day.prn.rows,
                  }
                : null,
        [day],
    );
    const onRecorded = useCallback(() => void reload(), [reload]);
    const recorder = useDoseRecorder(recorderContext, onRecorded);

    const isToday = day ? day.date === day.today : true;
    const recordable = useMemo(() => {
        if (!day || day.date !== day.today) return [];
        return day.medicines.flatMap((medicine) =>
            Object.values(medicine.cells)
                .flat()
                .filter((dose) => {
                    const kind = cellKind(dose, true);
                    return (
                        (kind === 'due_now' ||
                            kind === 'due' ||
                            kind === 'overdue') &&
                        recordBlock(dose, kind, day, personName) === null
                    );
                })
                .map((dose) => ({ dose, medicine })),
        );
    }, [day, personName]);
    const recordReason = day
        ? day.can.record_reason === 'no_shift'
            ? `Clock in to a shift covering ${personName} to record their doses.`
            : null
        : null;
    const prnBlocked =
        day?.can.record_reason === 'no_permission'
            ? 'Recording needs medication recording access.'
            : recordReason;
    const legendKinds = useMemo(() => {
        if (!day) return LEGEND_ORDER;
        const present = new Set(
            day.medicines.flatMap((medicine) =>
                Object.values(medicine.cells)
                    .flat()
                    .map((dose) => cellKind(dose, isToday)),
            ),
        );
        return LEGEND_ORDER.filter((kind) => present.has(kind));
    }, [day, isToday]);

    const label = day ? dayLabel(day.date, day.today, day.tomorrow) : 'Today';
    const canGoForward = day ? day.date < day.tomorrow : false;
    const canGoBack = day ? day.date > day.coverage.available_from : false;
    const medicineCount = new Set(
        day?.medicines.map((medicine) => medicine.id),
    ).size;

    return (
        <div className="space-y-5">
            {/* Profile launch point and allergy warnings */}
            {!embedded || day ? (
                <Card>
                    <CardContent className="space-y-3 p-5">
                        {!embedded ? (
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <span className="flex size-10 items-center justify-center rounded-xl bg-muted text-primary">
                                        <Pill className="size-5" />
                                    </span>
                                    <div>
                                        <h2 className="text-section-title">
                                            Medication
                                        </h2>
                                        <p className="text-caption text-muted-foreground">
                                            The full chart, history, INR and alerts are
                                            in the medication record
                                        </p>
                                    </div>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    {day?.can.report ? (
                                        <Button
                                            variant="outline"
                                            onClick={() => setReportOpen(true)}
                                        >
                                            <FileText className="size-4" />
                                            Report
                                        </Button>
                                    ) : null}
                                    {!embedded && (
                                        <Button variant="outline" asChild>
                                            <Link
                                                href={`/emar/mar?client_id=${clientId}`}
                                            >
                                                Open medication record
                                            </Link>
                                        </Button>
                                    )}
                                    {day &&
                                    day.can.record_reason !== 'no_permission' ? (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    disabled={
                                                        !day.can.record ||
                                                        recordable.length === 0
                                                    }
                                                    title={
                                                        recordReason ??
                                                        (recordable.length === 0
                                                            ? 'Nothing due right now'
                                                            : undefined)
                                                    }
                                                >
                                                    <Plus className="size-4" />
                                                    Record dose
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                className="w-72"
                                            >
                                                <DropdownMenuLabel>
                                                    Due and overdue now
                                                </DropdownMenuLabel>
                                                {recordable.map(
                                                    ({ dose, medicine }) => {
                                                        const kind = cellKind(
                                                            dose,
                                                            true,
                                                        );
                                                        return (
                                                            <DropdownMenuItem
                                                                key={dose.key}
                                                                onSelect={() =>
                                                                    recorder.recordScheduled(
                                                                        dose,
                                                                    )
                                                                }
                                                                className="flex items-start gap-2"
                                                            >
                                                                <span className="min-w-0 flex-1">
                                                                    <span className="block truncate font-medium">
                                                                        {medicine.name}
                                                                    </span>
                                                                    <span className="text-caption text-muted-foreground">
                                                                        {clockLabel(
                                                                            dose.time,
                                                                        )}{' '}
                                                                        ·{' '}
                                                                        {
                                                                            CELL_META[
                                                                                kind
                                                                            ].label
                                                                        }
                                                                    </span>
                                                                </span>
                                                            </DropdownMenuItem>
                                                        );
                                                    },
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    ) : null}
                                </div>
                            </div>
                        ) : null}

                        {day ? (
                            <AllergyLine
                                allergies={day.allergies}
                                personName={personName}
                            />
                        ) : null}
                        {day && day.chart_alerts.length > 0 ? (
                            <SafetyLine
                                tone="warning"
                                icon={BellRing}
                                title={`Chart alerts: ${day.chart_alerts
                                    .map((alert) => alert.title)
                                    .join(' · ')}`}
                            >
                                {embedded
                                    ? 'Read them in Allergies & alerts before recording.'
                                    : 'Read them on the medication record before recording.'}
                            </SafetyLine>
                        ) : null}
                    </CardContent>
                </Card>
            ) : null}

            {/* The day */}
            <Card>
                <CardContent className="space-y-3 p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-1.5">
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Earlier day"
                                disabled={!day || !canGoBack}
                                onClick={() =>
                                    day && chooseDate(shiftDay(day.date, -1))
                                }
                            >
                                <ChevronLeft className="size-4" />
                            </Button>
                            <h3
                                className="text-section-title min-w-[12rem] text-center"
                                aria-live="polite"
                            >
                                {label}
                            </h3>
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="Later day"
                                disabled={!day || !canGoForward}
                                onClick={() =>
                                    day && chooseDate(shiftDay(day.date, 1))
                                }
                            >
                                <ChevronRight className="size-4" />
                            </Button>
                            {day && !isToday ? (
                                <Button
                                    variant="ghost"
                                    onClick={() => chooseDate(null)}
                                >
                                    Today
                                </Button>
                            ) : null}
                        </div>
                        <div className="flex flex-wrap items-center justify-end gap-3">
                            {view !== 'asneeded' &&
                            day &&
                            day.medicines.length > 0 ? (
                                <MarDayLegend kinds={legendKinds} />
                            ) : null}
                            {embedded && day?.can.report ? (
                                <Button
                                    variant="outline"
                                    onClick={() => setReportOpen(true)}
                                >
                                    <FileText className="size-4" />
                                    Report
                                </Button>
                            ) : null}
                        </div>
                    </div>

                    {view === 'asneeded' && day ? null : load.status ===
                          'error' && !day ? (
                        <ErrorState
                            title="We couldn’t load medication"
                            message="The rest of the profile is fine. Try again, or open the medication record."
                            onRetry={() => void reload()}
                        />
                    ) : !day ? (
                        <SkeletonTable rows={5} columns={5} />
                    ) : !day.coverage.complete ? (
                        <EmptyState
                            title={`Not available before ${dayLabel(day.coverage.available_from, day.today, day.tomorrow)}`}
                            description={
                                day.coverage.notice ??
                                'Earlier days were recorded before this chart could show them. Use the medication record’s history for those doses.'
                            }
                        />
                    ) : day.medicines.length === 0 ? (
                        <EmptyState
                            title={
                                day.hidden_controlled.total
                                    ? 'Scheduled doses include controlled medicines'
                                    : `No scheduled medicines for ${personName} ${isToday ? 'today' : 'this day'}`
                            }
                            description={
                                day.hidden_controlled.total
                                    ? 'Details need controlled-medicine access. The house lead can tell you more.'
                                    : 'New orders are added and checked in Orders & reviews.'
                            }
                        />
                    ) : (
                        <div
                            className={cn(
                                'space-y-2 transition-opacity',
                                load.status === 'loading' && 'opacity-60',
                            )}
                            aria-busy={load.status === 'loading'}
                        >
                            {load.status === 'error' ? (
                                <SafetyLine
                                    tone="warning"
                                    icon={AlertTriangle}
                                    alert
                                    title="This day couldn’t be refreshed"
                                >
                                    It shows what was known earlier.{' '}
                                    <Button
                                        variant="link"
                                        className="h-auto p-0"
                                        onClick={() => void reload()}
                                    >
                                        Try again
                                    </Button>
                                </SafetyLine>
                            ) : null}
                            <MarDayGrid
                                day={day}
                                personName={personName}
                                onRecord={(dose) =>
                                    recorder.recordScheduled(dose)
                                }
                                onOpenDose={(dose, medicine) =>
                                    setOpenDose({ dose, medicine })
                                }
                                onMedicineDetails={setDetails}
                            />
                            <p className="text-caption text-muted-foreground">
                                {medicineCount}{' '}
                                {medicineCount === 1
                                    ? 'medicine'
                                    : 'medicines'}{' '}
                                · times in NZ time
                                {day.hidden_controlled.total > 0
                                    ? ` · ${day.hidden_controlled.total} controlled ${day.hidden_controlled.total === 1 ? 'dose' : 'doses'} not shown — needs controlled-medicine access`
                                    : ''}
                            </p>
                        </div>
                    )}
                </CardContent>
            </Card>

            {view !== 'scheduled' && day && day.coverage.complete ? (
                <Card>
                    <CardContent className="p-5">
                        <PrnStrip
                            rows={day.prn.rows}
                            hidden={day.prn.hidden}
                            recordBlocked={prnBlocked}
                            canRecordControlled={day.can.record_controlled}
                            onRecord={(id) => recorder.recordAsNeeded(id)}
                        />
                        {day.prn.rows.length === 0 && day.prn.hidden === 0 ? (
                            <p className="text-caption text-muted-foreground">
                                No as-needed medicines for {personName}.
                            </p>
                        ) : null}
                    </CardContent>
                </Card>
            ) : null}

            <DoseRecordDialog
                dose={openDose?.dose ?? null}
                medicine={openDose?.medicine ?? null}
                dayLabel={label}
                isToday={isToday}
                onClose={() => setOpenDose(null)}
            />
            <MedicineDetailsDialog
                medicine={details}
                isToday={isToday}
                onClose={() => setDetails(null)}
            />
            {day ? (
                <MarReportDialog
                    open={reportOpen}
                    onClose={() => setReportOpen(false)}
                    clientId={clientId}
                    personName={personName}
                    today={day.today}
                    controlledLeftOut={!canViewControlled}
                />
            ) : null}
            {recorder.element}
        </div>
    );
}
