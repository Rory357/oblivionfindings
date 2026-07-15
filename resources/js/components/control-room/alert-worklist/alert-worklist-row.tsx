import { AlertStatus } from '@/components/control-room/alert-worklist/alert-status';
import type { AlertWorklistRow as AlertWorklistRowType } from '@/components/control-room/alert-worklist/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { ArrowRight, BookOpen, Clock3, MapPin, UserRound } from 'lucide-react';

export function AlertWorklistRow({
    row,
    selected,
    onSelectionChange,
    onOpen,
}: {
    row: AlertWorklistRowType;
    selected: boolean;
    onSelectionChange: (selected: boolean) => void;
    onOpen: (id: number) => void;
}) {
    const reference = row.reference_number ?? `Alert ${row.id}`;

    return (
        <div className="grid grid-cols-[2.5rem_minmax(0,2fr)_minmax(14rem,1fr)_minmax(12rem,0.8fr)_auto] items-center gap-4 border-b border-border px-4 py-4 last:border-b-0 hover:bg-muted/25">
            <Checkbox
                aria-label={`Select ${reference}`}
                checked={selected}
                onCheckedChange={(checked) =>
                    onSelectionChange(checked === true)
                }
            />

            <div className="min-w-0 space-y-2">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-xs font-semibold text-primary">
                        {reference}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {row.source.label}
                    </span>
                </div>
                <p className="truncate text-sm font-semibold text-foreground">
                    {row.summary}
                </p>
                <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    {row.site ? (
                        <span className="inline-flex items-center gap-1">
                            <MapPin className="h-3.5 w-3.5" aria-hidden />
                            {row.site.name}
                        </span>
                    ) : null}
                    {row.person ? (
                        <span className="inline-flex items-center gap-1">
                            <UserRound className="h-3.5 w-3.5" aria-hidden />
                            {row.person.name}
                        </span>
                    ) : null}
                    {row.triggered_at ? (
                        <span title={formatDateTime(row.triggered_at)}>
                            Raised {formatRelative(row.triggered_at)}
                        </span>
                    ) : null}
                </div>
            </div>

            <div className="space-y-2">
                <AlertStatus
                    status={row.status}
                    severity={row.severity}
                    slaStatus={row.sla.status}
                />
                <p className="text-xs font-medium text-foreground">
                    {row.priority.reason}
                </p>
                {row.next_deadline_at ? (
                    <p
                        className="inline-flex items-center gap-1 text-xs text-muted-foreground"
                        title={formatDateTime(row.next_deadline_at)}
                    >
                        <Clock3 className="h-3.5 w-3.5" aria-hidden />
                        Deadline {formatRelative(row.next_deadline_at)}
                    </p>
                ) : null}
            </div>

            <div className="space-y-2 text-xs">
                {row.playbook ? (
                    <p className="inline-flex items-center gap-1.5 text-foreground">
                        <BookOpen
                            className="h-3.5 w-3.5 text-primary"
                            aria-hidden
                        />
                        {row.playbook.name ?? 'Response playbook'} ·{' '}
                        {row.playbook.completed_steps}/
                        {row.playbook.total_steps}
                    </p>
                ) : (
                    <p className="text-muted-foreground">No playbook started</p>
                )}
                <p className="text-muted-foreground">
                    {row.assignee?.name ?? 'Unassigned'}
                    {row.queue ? ` · ${row.queue.name}` : ''}
                </p>
                {row.journey.incident_reference ||
                row.journey.health_safety_reference ? (
                    <p className="font-mono text-[11px] text-muted-foreground">
                        {[
                            row.journey.incident_reference,
                            row.journey.health_safety_reference,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                ) : null}
            </div>

            <Button
                size="sm"
                onClick={() => onOpen(row.id)}
                aria-label={`${row.next_action.label} for ${reference}`}
            >
                {row.next_action.label}
                <ArrowRight className="ml-1.5 h-4 w-4" aria-hidden />
            </Button>
        </div>
    );
}
