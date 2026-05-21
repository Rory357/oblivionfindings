import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { ArrowRight, Sparkles } from 'lucide-react';
import { cn } from '@/lib/utils';
import { PriorityBadge } from './PriorityBadge';
import type { WorkflowAction } from './BoardPriorityCard';
import { resolveActionVerb } from '@/lib/governance-action-verbs';

interface MyNextActionsRailProps {
    actions: WorkflowAction[];
    currentUserName?: string | null;
    /** When the current user has none assigned, we fall back to highest priority items. */
    showFallback?: boolean;
}

function ownerInitials(name: string | null): string {
    if (!name) return '?';
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('') || '?';
}

/**
 * Right-rail of the cockpit. Shows the current user's top 5 owned actions,
 * or falls back to the top 5 critical/overdue actions when nothing is owned.
 */
export function MyNextActionsRail({
    actions,
    currentUserName,
    showFallback = true,
}: MyNextActionsRailProps) {
    const owned = currentUserName
        ? actions.filter((a) => a.owner && a.owner.toLowerCase() === currentUserName.toLowerCase())
        : [];

    const rankPriority = (a: WorkflowAction): number => {
        const pri = { critical: 4, high: 3, medium: 2, low: 1 }[a.priority] ?? 1;
        const status = a.status === 'overdue' ? 2 : a.status === 'due_soon' ? 1 : 0;
        return pri * 10 + status;
    };

    const list = owned.length > 0 ? owned : showFallback ? [...actions].sort((a, b) => rankPriority(b) - rankPriority(a)) : [];
    const top = list.slice(0, 5);
    const showingFallback = owned.length === 0 && top.length > 0;

    return (
        <Card data-dusk="cockpit-my-next-actions">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-2">
                    <div>
                        <CardTitle className="text-base">My Next Actions</CardTitle>
                        <CardDescription>
                            {showingFallback
                                ? 'Top board priorities to consider next.'
                                : 'Items assigned directly to you.'}
                        </CardDescription>
                    </div>
                    <Link
                        href="/governance/actions"
                        className="text-xs font-medium text-primary hover:underline"
                    >
                        View all
                    </Link>
                </div>
            </CardHeader>
            <CardContent className="space-y-2">
                {top.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border p-6 text-center">
                        <Sparkles className="mx-auto h-5 w-5 text-status-success" aria-hidden="true" />
                        <p className="mt-2 text-sm font-medium text-foreground">You’re all caught up</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            No board work is waiting on you right now.
                        </p>
                    </div>
                ) : (
                    top.map((action) => {
                        const verb = resolveActionVerb(action.area, action.status, action.action_label);
                        return (
                            <Link
                                key={action.id}
                                href={action.action_url}
                                className={cn(
                                    'group flex items-start gap-3 rounded-lg border border-border bg-card p-3 transition hover:border-primary/40 hover:bg-muted/50',
                                    action.status === 'overdue' && 'border-status-critical/30',
                                )}
                                data-dusk={`cockpit-my-action-${action.id}`}
                            >
                                <Avatar className="h-9 w-9 shrink-0">
                                    <AvatarFallback className="bg-primary/10 text-xs font-semibold text-primary">
                                        {ownerInitials(action.owner)}
                                    </AvatarFallback>
                                </Avatar>
                                <div className="min-w-0 flex-1 space-y-1">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <Badge variant="outline" className="text-[10px] uppercase">
                                            {action.area}
                                        </Badge>
                                        <PriorityBadge
                                            priority={action.priority}
                                            status={action.status}
                                            showLabel={false}
                                        />
                                    </div>
                                    <p className="text-sm font-medium leading-snug text-foreground">
                                        {action.title}
                                    </p>
                                    <div className="flex items-center justify-between">
                                        <span className="text-xs text-muted-foreground">
                                            {action.due_date ? `Due ${action.due_date}` : 'No due date'}
                                        </span>
                                        <span className="inline-flex items-center gap-1 text-xs font-medium text-primary group-hover:underline">
                                            {verb}
                                            <ArrowRight className="h-3 w-3 transition-transform group-hover:translate-x-0.5" />
                                        </span>
                                    </div>
                                </div>
                            </Link>
                        );
                    })
                )}
            </CardContent>
        </Card>
    );
}

export default MyNextActionsRail;
