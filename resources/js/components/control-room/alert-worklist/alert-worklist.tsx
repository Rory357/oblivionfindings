import { AlertWorklistRow } from '@/components/control-room/alert-worklist/alert-worklist-row';
import type { AlertWorklistRow as AlertWorklistRowType } from '@/components/control-room/alert-worklist/types';
import { Button } from '@/components/ui/button';
import { ArrowDownUp, Inbox } from 'lucide-react';

export function AlertWorklist({
    rows,
    selected,
    onSelectionChange,
    onSort,
    onOpen,
}: {
    rows: AlertWorklistRowType[];
    selected: Set<number>;
    onSelectionChange: (selected: Set<number>) => void;
    onSort: (field: string) => void;
    onOpen: (id: number) => void;
}) {
    const toggleRow = (id: number, checked: boolean) => {
        const next = new Set(selected);
        if (checked) next.add(id);
        else next.delete(id);
        onSelectionChange(next);
    };

    return (
        <section className="overflow-hidden rounded-xl border border-border bg-card">
            <div className="flex items-center justify-between gap-4 border-b border-border bg-muted/30 px-4 py-3">
                <div>
                    <h2 className="text-sm font-semibold text-foreground">
                        Actionable alerts
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        Ordered by SLA breach, severity, escalation, next
                        deadline, then oldest.
                    </p>
                </div>
                <div className="flex items-center gap-1">
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
