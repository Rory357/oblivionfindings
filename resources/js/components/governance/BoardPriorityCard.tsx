import { CalendarClock, Info } from 'lucide-react';

import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { formatDateOnly } from '@/lib/datetime';
import { refSuffix } from '@/lib/governance-labels';
import { cn } from '@/lib/utils';

import { NextActionButton } from './NextActionButton';
import {
    PriorityBadge,
    type Priority,
    type WorkflowStatus,
} from './PriorityBadge';

export interface WorkflowAction {
    id: string;
    area: string;
    area_key?: string;
    title: string;
    detail: string;
    priority: Priority;
    status: WorkflowStatus;
    due_date: string | null;
    due_at?: string | null;
    action_label: string;
    action_url: string;
    owner: string | null;
    assignee_user_id?: number | null;
    board_member_id?: number | null;
    kind?: 'vote' | 'read' | 'act' | 'know';
    source?: {
        type: string;
        id: number;
        reference: string;
        href: string;
    };
}

interface BoardPriorityCardProps {
    action: WorkflowAction;
    whyItMatters?: string;
    className?: string;
}

const AREA_LABELS: Record<string, string> = {
    meetings: 'Meeting',
    resolutions: 'Resolution',
    risks: 'Risk',
    risk_register: 'Risk',
    compliance: 'Requirement',
    budgets: 'Budget',
    spend_approvals: 'Spend request',
    action_items: 'Action',
    actions: 'Action',
    policies: 'Policy',
    // Legacy area names
    Meetings: 'Meeting',
    Resolutions: 'Resolution',
    Risks: 'Risk',
    'Risk Register': 'Risk',
    Compliance: 'Requirement',
    Budgets: 'Budget',
    'Spend Approvals': 'Spend request',
    'Action Items': 'Action',
    Policies: 'Policy',
    'CEO Reports': 'CEO report',
};

const WHY_BY_AREA: Record<string, string> = {
    Meeting:
        'Meetings work when the agenda is ready, members have read the board pack, and the minutes are written and approved afterwards.',
    Resolution:
        "The chair or secretary opens and closes voting, so the board's decision is properly recorded.",
    Risk: 'The board has agreed the most risk it will accept for this kind of risk. This one is above that limit, so the board needs to see what is being done about it.',
    Requirement:
        'Requirements come from the law, standards or funding contracts. Missing one can lead to penalties or put funding at risk.',
    Budget: "Budgets and budget changes need the board's approval before the money is committed.",
    'Spend request':
        'Spending above the set limit needs approval before it goes ahead.',
    Action: "Actions are follow-up work the board asked for. When they slip, a board decision isn't being carried out on time.",
    Policy: 'Policies are reviewed regularly so they stay accurate, legal and useful.',
};

const WHY_BY_STATUS: Record<string, string> = {
    overdue: "It's past its due date.",
    due_soon: "It's due in the next 7 days.",
    blocked: "It's blocked, so it can't move until something else happens.",
};

export function priorityAreaLabel(action: Pick<WorkflowAction, 'area' | 'area_key'>): string {
    return (
        (action.area_key ? AREA_LABELS[action.area_key] : undefined) ??
        AREA_LABELS[action.area] ??
        action.area
    );
}

/** A real explanation: why this kind of item matters, and why it's urgent now. */
export function priorityExplanation(action: WorkflowAction): string {
    return [
        WHY_BY_AREA[priorityAreaLabel(action)] ??
            'This needs the board to know about it or act on it.',
        WHY_BY_STATUS[action.status],
    ]
        .filter(Boolean)
        .join(' ');
}

/**
 * One board priority. Every card answers: what is this, why does it matter,
 * who owns it, when is it due, and what do I do next? The record title leads;
 * any reference sits last and muted.
 */
export function BoardPriorityCard({
    action,
    whyItMatters,
    className,
}: BoardPriorityCardProps) {
    const areaLabel = priorityAreaLabel(action);
    const why = whyItMatters ?? priorityExplanation(action);
    const reference = refSuffix(action.source?.reference);

    return (
        <div
            className={cn(
                'group flex flex-col gap-3 rounded-lg border border-border bg-card p-4 transition-colors hover:border-primary/40 lg:flex-row lg:items-center lg:justify-between',
                action.status === 'overdue' && 'border-status-critical/30',
                className,
            )}
            data-dusk={`cockpit-priority-${action.id}`}
        >
            <div className="min-w-0 flex-1 space-y-1.5">
                <div className="flex flex-wrap items-center gap-2 text-caption">
                    <span className="font-medium text-foreground">
                        {areaLabel}
                    </span>
                    <PriorityBadge
                        priority={action.priority}
                        status={action.status}
                    />
                    {action.due_date ? (
                        <span className="inline-flex items-center gap-1">
                            <CalendarClock
                                className="size-3"
                                aria-hidden="true"
                            />
                            Due {formatDateOnly(action.due_date)}
                        </span>
                    ) : null}
                </div>

                <p className="leading-snug font-medium text-foreground">
                    {action.title}
                    {reference ? (
                        <span className="ml-1.5 text-caption font-normal">
                            {reference}
                        </span>
                    ) : null}
                </p>

                {action.detail ? (
                    <p className="text-sm leading-snug text-muted-foreground">
                        {action.detail}
                    </p>
                ) : null}

                <div className="flex flex-wrap items-center gap-3 text-caption">
                    <span>
                        {action.owner ? `Owner: ${action.owner}` : 'No owner yet'}
                    </span>
                    <Popover>
                        <PopoverTrigger asChild>
                            <button
                                type="button"
                                className="inline-flex items-center gap-1 rounded-sm underline decoration-dotted underline-offset-4 transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <Info className="size-3" aria-hidden="true" />
                                Why this matters
                            </button>
                        </PopoverTrigger>
                        <PopoverContent align="start" className="w-72">
                            <p className="text-subtle">{why}</p>
                        </PopoverContent>
                    </Popover>
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
