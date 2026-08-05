import { AlertWorklistRow } from '@/components/control-room/alert-worklist/alert-worklist-row';
import type { AlertWorklistRow as AlertWorklistRowType } from '@/components/control-room/alert-worklist/types';
import type { ControlRoomRowAction } from '@/components/control-room/control-room-row-actions';
import { Button } from '@/components/ui/button';
import { ArrowDownUp, Inbox } from 'lucide-react';
import { useId } from 'react';

export function AlertWorklist<Row extends AlertWorklistRowType>({
    rows,
    selected,
    onSelectionChange,
    onSort,
    onOpen,
    getActions,
    heading = 'Actionable alerts',
    description = 'Ordered by SLA breach, severity, escalation, next deadline, then oldest.',
    allowSorting = true,
}: {
    rows: Row[];
    selected: Set<number>;
    onSelectionChange: (selected: Set<number>) => void;
    onSort: (field: string) => void;
    onOpen: (id: number) => void;
    getActions?: (row: Row) => readonly ControlRoomRowAction[];
    heading?: string;
    description?: string;
    allowSorting?: boolean;
}) {
    const headingId = useId();
    const toggleRow = (id: number, checked: boolean) => {
        const next = new Set(selected);
        if (checked) next.add(id);
        else next.delete(id);
        onSelectionChange(next);
    };

    return (
        <section
            aria-labelledby={headingId}
            className="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div className="sticky top-0 z-10 flex flex-col gap-3 border-b border-border bg-card/95 px-4 py-3 backdrop-blur-sm sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2
                        id={headingId}
                        className="text-sm font-semibold text-foreground"
                    >
                        {heading}
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                </div>
                {allowSorting ? (
                    <div className="flex flex-wrap items-center gap-1">
                        {['severity', 'status', 'triggered_at'].map((field) => (
                            <Button
                                key={field}
                                type="button"
                                variant="ghost"
                                size="sm"
                                aria-label={`Sort by ${field === 'triggered_at' ? 'raised time' : field}`}
                                onClick={() => onSort(field)}
                            >
                                <ArrowDownUp
                                    className="mr-1.5 h-3.5 w-3.5"
                                    aria-hidden
                                />
                                {field === 'triggered_at'
                                    ? 'Raised'
                                    : field.charAt(0).toUpperCase() +
                                      field.slice(1)}
                            </Button>
                        ))}
                    </div>
                ) : null}
            </div>

            {rows.length ? (
                rows.map((row) => (
                    <AlertWorklistRow
                        key={row.id}
                        row={row}
                        selected={selected.has(row.id)}
                        onSelectionChange={(checked) =>
                            toggleRow(row.id, checked)
                        }
                        onOpen={onOpen}
                        actions={getActions?.(row) ?? []}
                    />
                ))
            ) : (
                <div className="flex min-h-56 flex-col items-center justify-center gap-2 px-6 text-center">
                    <Inbox
                        className="h-8 w-8 text-muted-foreground"
                        aria-hidden
                    />
                    <p className="font-medium text-foreground">
                        No alerts in this view
                    </p>
                    <p className="max-w-md text-sm text-muted-foreground">
                        Try another lens or clear the filters. Completed alerts
                        remain available in History.
                    </p>
                </div>
            )}
        </section>
    );
}
