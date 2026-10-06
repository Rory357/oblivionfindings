import { Button } from '@/components/ui/button';
import { formatTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { ExternalLink, Info, Lock, Pill } from 'lucide-react';
import { useRef, useState, type KeyboardEvent } from 'react';

import {
    EntityContextMenu,
    EntityKebab,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

import {
    CELL_META,
    cellKind,
    cellLabel,
    clockLabel,
    idleHint,
    isRecorded,
    recordBlock,
    type CellKind,
} from './dose-cell';
import type { DayDose, DayMedicine, MedicationDay } from './types';

function firstName(name: string | null | undefined): string {
    return (name ?? '').trim().split(/\s+/)[0] ?? '';
}

function cellSubline(dose: DayDose, kind: CellKind): string | null {
    switch (kind) {
        case 'given':
        case 'refused':
        case 'withheld':
        case 'missed':
            return dose.recorded?.by
                ? `by ${firstName(dose.recorded.by)}`
                : null;
        case 'due_now':
            return dose.window_ends_at
                ? `until ${formatTime(dose.window_ends_at)}`
                : null;
        case 'overdue':
            return 'not recorded';
        case 'away':
            return dose.away_reason ?? null;
        default:
            return null;
    }
}

export function MarDayGrid({
    day,
    personName,
    onRecord,
    onOpenDose,
    onMedicineDetails,
}: {
    day: MedicationDay;
    personName: string;
    onRecord: (dose: DayDose) => void;
    onOpenDose: (dose: DayDose, medicine: DayMedicine) => void;
    onMedicineDetails: (medicine: DayMedicine) => void;
}) {
    const isToday = day.date === day.today;
    const { ctx, open, close } = useEntityContextMenu<DayMedicine>();

    // Roving focus over the grid (arrow keys, Home / End).
    const [focus, setFocus] = useState<[number, number]>([0, 0]);
    const cells = useRef(new Map<string, HTMLElement>());
    const move = (row: number, col: number) => {
        const r = Math.max(0, Math.min(day.medicines.length - 1, row));
        const c = Math.max(0, Math.min(day.times.length - 1, col));
        setFocus([r, c]);
        cells.current.get(`${r}:${c}`)?.focus();
    };
    const onKey = (event: KeyboardEvent, row: number, col: number) => {
        const next: Record<string, [number, number]> = {
            ArrowRight: [row, col + 1],
            ArrowLeft: [row, col - 1],
            ArrowDown: [row + 1, col],
            ArrowUp: [row - 1, col],
            Home: [row, 0],
            End: [row, day.times.length - 1],
        };
        const target = next[event.key];
        if (!target) return;
        event.preventDefault();
        move(target[0], target[1]);
    };

    const menuFor = (medicine: DayMedicine): MenuItem[] => {
        const marUrl = Object.values(medicine.cells)
            .flat()
            .find((dose) => dose.mar_url)?.mar_url;
        return compactMenu([
            {
                label: 'Medicine details',
                icon: Info,
                onClick: () => onMedicineDetails(medicine),
            },
            marUrl
                ? {
                      label: 'Open in the MAR chart',
                      icon: ExternalLink,
                      onClick: () => router.visit(marUrl),
                  }
                : null,
        ]);
    };

    return (
        <TooltipProvider delayDuration={150}>
            <div
                role="region"
                aria-label={`Scheduled medication chart for ${personName}. Scroll horizontally to see all dose times.`}
                tabIndex={0}
                className="relative max-w-full min-w-0 overflow-x-auto rounded-lg border border-border outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            >
                <table
                    role="grid"
                    aria-label={`Scheduled doses for ${personName}`}
                    className="w-full border-separate border-spacing-0 text-sm"
                >
                    <thead>
                        <tr>
                            <th
                                scope="col"
                                className="text-caption sticky left-0 z-20 w-[280px] min-w-[240px] border-b border-border bg-card px-4 py-3 text-left font-semibold text-muted-foreground"
                            >
                                Medicine
                            </th>
                            {day.times.map((time) => (
                                <th
                                    key={time}
                                    scope="col"
                                    className="min-w-[144px] border-b border-l border-border bg-muted/40 px-3 py-3 text-center text-sm font-semibold whitespace-nowrap"
                                >
                                    {clockLabel(time)}
                                </th>
                            ))}
                            <th
                                scope="col"
                                className="w-12 border-b border-border bg-muted/40"
                            >
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {day.medicines.map((medicine, row) => (
                            <tr
                                key={medicine.key ?? medicine.id}
                                onContextMenu={(event) => open(event, medicine)}
                                className="group"
                            >
                                <th
                                    scope="row"
                                    className="sticky left-0 z-10 w-[280px] max-w-[360px] min-w-[240px] border-b border-border bg-card px-4 py-3 text-left align-middle font-normal group-hover:bg-muted"
                                >
                                    <Button
                                        unstyled
                                        className="text-left font-semibold text-primary underline-offset-4 hover:underline"
                                        onClick={() =>
                                            onMedicineDetails(medicine)
                                        }
                                        aria-label={`Medicine details for ${medicine.name}`}
                                    >
                                        {medicine.name}
                                    </Button>
                                    <div className="text-caption mt-1 break-words text-muted-foreground">
                                        {[medicine.dose, medicine.route]
                                            .filter(Boolean)
                                            .join(' · ') || '—'}
                                    </div>
                                    {medicine.is_controlled ||
                                    medicine.requires_witness ? (
                                        <div className="mt-1 flex flex-wrap gap-1">
                                            {medicine.is_controlled ? (
                                                <StatusBadge
                                                    variant="critical"
                                                    size="sm"
                                                >
                                                    <Lock className="size-3" />
                                                    Controlled
                                                </StatusBadge>
                                            ) : null}
                                            {medicine.requires_witness ? (
                                                <StatusBadge
                                                    variant="neutral"
                                                    size="sm"
                                                >
                                                    Witness
                                                </StatusBadge>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </th>
                                {day.times.map((time, col) => {
                                    const dose = medicine.cells[time]?.[0];
                                    const tabIndex =
                                        focus[0] === row && focus[1] === col
                                            ? 0
                                            : -1;
                                    const register = (
                                        el: HTMLElement | null,
                                    ) => {
                                        if (el)
                                            cells.current.set(
                                                `${row}:${col}`,
                                                el,
                                            );
                                        else
                                            cells.current.delete(
                                                `${row}:${col}`,
                                            );
                                    };
                                    if (!dose) {
                                        return (
                                            <td
                                                key={time}
                                                role="gridcell"
                                                className={cn(
                                                    'border-b border-l border-border px-3 py-2 text-center group-hover:bg-muted/40',
                                                )}
                                            >
                                                <span
                                                    ref={register}
                                                    tabIndex={tabIndex}
                                                    onFocus={() =>
                                                        setFocus([row, col])
                                                    }
                                                    onKeyDown={(e) =>
                                                        onKey(e, row, col)
                                                    }
                                                    aria-label={`No dose at ${clockLabel(time)}`}
                                                    className="frontline-tap inline-flex w-full items-center justify-center rounded-lg text-muted-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                                >
                                                    ·
                                                </span>
                                            </td>
                                        );
                                    }
                                    const kind = cellKind(dose, isToday);
                                    const label = cellLabel(dose, kind);
                                    const pill =
                                        kind === 'away'
                                            ? CELL_META.away.label
                                            : label;
                                    const blocked = recordBlock(
                                        dose,
                                        kind,
                                        day,
                                        personName,
                                    );
                                    const recordable =
                                        blocked === null &&
                                        (kind === 'due_now' ||
                                            kind === 'due' ||
                                            kind === 'overdue');
                                    const opensRecord = isRecorded(kind);
                                    const hint =
                                        blocked ??
                                        (recordable
                                            ? null
                                            : idleHint(dose, kind));
                                    const sub = cellSubline(dose, kind);
                                    const button = (
                                        // eslint-disable-next-line no-restricted-syntax -- a MAR grid cell: a 44px status tile with its own focus and roving tabindex, not a Button.
                                        <button
                                            ref={register}
                                            type="button"
                                            tabIndex={tabIndex}
                                            onFocus={() => setFocus([row, col])}
                                            onKeyDown={(e) =>
                                                onKey(e, row, col)
                                            }
                                            aria-disabled={
                                                !recordable && !opensRecord
                                                    ? true
                                                    : undefined
                                            }
                                            aria-label={`${medicine.name}, ${clockLabel(time)}: ${label}${
                                                recordable
                                                    ? ' — record this dose'
                                                    : opensRecord
                                                      ? ' — open the record'
                                                      : ''
                                            }`}
                                            data-kind={kind}
                                            onClick={() => {
                                                if (recordable) onRecord(dose);
                                                else if (opensRecord)
                                                    onOpenDose(dose, medicine);
                                            }}
                                            className={cn(
                                                'frontline-tap flex w-full flex-col items-center justify-center gap-1 rounded-lg border px-2 py-1.5 text-center transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                                recordable
                                                    ? 'border-dashed border-primary/60 bg-primary/5 hover:bg-muted'
                                                    : opensRecord
                                                      ? 'border-transparent hover:bg-muted'
                                                      : 'cursor-default border-transparent',
                                            )}
                                        >
                                            <StatusBadge
                                                variant={CELL_META[kind].tone}
                                                size="sm"
                                                className="max-w-full"
                                            >
                                                <span className="truncate">
                                                    {pill}
                                                </span>
                                            </StatusBadge>
                                            {recordable && (
                                                <span className="text-caption font-medium text-primary">
                                                    Record dose
                                                </span>
                                            )}
                                            {sub ? (
                                                <span className="text-caption line-clamp-2 max-w-[160px] text-muted-foreground">
                                                    {sub}
                                                </span>
                                            ) : null}
                                        </button>
                                    );
                                    return (
                                        <td
                                            key={time}
                                            role="gridcell"
                                            className={cn(
                                                'border-b border-l border-border px-3 py-2 align-middle group-hover:bg-muted/40',
                                            )}
                                        >
                                            {hint ? (
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        {button}
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        {hint}
                                                    </TooltipContent>
                                                </Tooltip>
                                            ) : (
                                                button
                                            )}
                                        </td>
                                    );
                                })}
                                <td className="border-b border-border px-1 text-center group-hover:bg-muted/40">
                                    <EntityKebab
                                        actions={menuFor(medicine)}
                                        label={`Actions for ${medicine.name}`}
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {ctx ? (
                <EntityContextMenu
                    x={ctx.x}
                    y={ctx.y}
                    icon={Pill}
                    title={ctx.record.name}
                    items={menuFor(ctx.record)}
                    onClose={close}
                />
            ) : null}
        </TooltipProvider>
    );
}

/** The colour key, in the grid's own words. */
export function MarDayLegend({ kinds }: { kinds: CellKind[] }) {
    return (
        <div
            className="flex flex-wrap items-center gap-x-3 gap-y-1.5"
            aria-label="What the colours mean"
        >
            <span className="text-caption font-semibold text-muted-foreground">
                Key
            </span>
            {kinds.map((kind) => (
                <span
                    key={kind}
                    className="inline-flex items-center gap-1.5"
                    title={CELL_META[kind].legend}
                >
                    <StatusBadge variant={CELL_META[kind].tone} size="sm">
                        {CELL_META[kind].label}
                    </StatusBadge>
                </span>
            ))}
        </div>
    );
}
