import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { CalendarClock, Info } from 'lucide-react';
import { NextActionButton } from './NextActionButton';
import {
    PriorityBadge,
    type Priority,
    type WorkflowStatus,
} from './PriorityBadge';

export interface WorkflowAction {
    id: string;
    area: string;
    title: string;
    detail: string;
    priority: Priority;
    status: WorkflowStatus;
    due_date: string | null;
    action_label: string;
    action_url: string;
    owner: string | null;
}

interface BoardPriorityCardProps {
    action: WorkflowAction;
    whyItMatters?: string;
    className?: string;
    dense?: boolean;
}

/**
 * Initials for the owner avatar (e.g. "Jane Smith" → "JS").
 */
function ownerInitials(name: string | null): string {
    if (!name) return '?';
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

const AREA_LABELS: Record<string, string> = {
    Meetings: 'Meeting',
    Resolutions: 'Resolution',
    Risks: 'Risk',
    Compliance: 'Compliance',
    Budgets: 'Budget',
    'Spend Approvals': 'Spend',
    'Action Items': 'Action',
    Policies: 'Policy',
    'CEO Reports': 'CEO Report',
};

const STATUS_HINT: Record<WorkflowStatus, string> = {
    overdue: 'Past its due date — needs board attention now.',
    due_soon: 'Due within the next 7 days.',
    pending: 'Open and awaiting board action.',
};

/**
 * Single priority row in the cockpit. Every card answers:
 *   what is this? why does it matter? who owns it? when is it due?
 *   what should I do next?
 */
export function BoardPriorityCard({
    action,
    whyItMatters,
    className,
    dense = false,
}: BoardPriorityCardProps) {
    const areaLabel = AREA_LABELS[action.area] ?? action.area;
    const why = whyItMatters ?? STATUS_HINT[action.status];

    return (
        <div
            className={cn(
                'group flex flex-col gap-3 rounded-lg border border-border bg-card p-4 transition hover:border-primary/40 hover:shadow-sm lg:flex-row lg:items-center lg:justify-between',
                action.status === 'overdue' &&
                    'border-status-critical/30 bg-status-critical-bg/10',
                className,
            )}
            data-dusk={`cockpit-priority-${action.id}`}
        >
            <div className="flex min-w-0 flex-1 items-start gap-3">
                {!dense && (
                    <Avatar
                        className="hidden h-9 w-9 shrink-0 md:flex"
                        title={action.owner ?? 'Unassigned'}
                    >
                        <AvatarFallback className="bg-muted text-xs font-semibold text-muted-foreground">
                            {ownerInitials(action.owner)}
                        </AvatarFallback>
                    </Avatar>
                )}

                <div className="min-w-0 flex-1 space-y-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge
                            variant="outline"
                            className="text-[10px] tracking-wide uppercase"
                        >
                            {areaLabel}
                        </Badge>
                        <PriorityBadge
                            priority={action.priority}
                            status={action.status}
                        />
                        {action.due_date ? (
                            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                <CalendarClock
                                    className="h-3 w-3"
                                    aria-hidden="true"
                                />
                                Due {action.due_date}
                            </span>
                        ) : null}
                    </div>

                    <p className="leading-snug font-medium text-foreground">
                        {action.title}
                    </p>

                    {action.detail ? (
                        <p className="text-sm leading-snug text-muted-foreground">
                            {action.detail}
                        </p>
                    ) : null}

                    <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                        {action.owner ? (
                            <span>
                                <span className="text-muted-foreground/70">
                                    Owner:
                                </span>{' '}
                                {action.owner}
                            </span>
                        ) : (
                            <span className="italic">Unassigned</span>
                        )}
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span className="inline-flex cursor-help items-center gap-1 text-muted-foreground hover:text-foreground">
                                    <Info
                                        className="h-3 w-3"
                                        aria-hidden="true"
                                    />
                                    Why this matters
                                </span>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-xs text-xs">
                                {why}
                            </TooltipContent>
                        </Tooltip>
                    </div>
                </div>
            </div>

            <div className="shrink-0 self-stretch lg:self-center">
                <NextActionButton
                    area={action.area}
                    status={action.status}
                    actionLabel={action.action_label}
                    href={action.action_url}
                    data-dusk={`cockpit-priority-action-${action.id}`}
                />
            </div>
        </div>
    );
}

export default BoardPriorityCard;
