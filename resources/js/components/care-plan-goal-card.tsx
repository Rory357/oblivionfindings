import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { CheckCircle2, Clock, Circle, Pause, ChevronDown, ChevronUp, Calendar } from 'lucide-react';
import { useState } from 'react';
import { router } from '@inertiajs/react';

interface GoalCardProps {
    goal: {
        id: number;
        title: string;
        description?: string | null;
        category: string;
        status: string;
        priority: string;
        progress_percentage: number;
        target_date?: string | null;
        outcome_notes?: string | null;
    };
    carePlanId: number;
    onProgressUpdate?: (goalId: number, progress: number, status: string) => void;
    compact?: boolean;
}

const PRIORITY_BORDER: Record<string, string> = {
    critical: 'border-l-red-500',
    high: 'border-l-amber-500',
    medium: 'border-l-blue-500',
    low: 'border-l-slate-400',
};

const PRIORITY_TEXT: Record<string, string> = {
    critical: 'text-status-critical dark:text-status-critical',
    high: 'text-status-warning dark:text-status-warning',
    medium: 'text-status-info dark:text-status-info',
    low: 'text-muted-foreground dark:text-muted-foreground',
};

const CATEGORY_BADGE: Record<string, string> = {
    health: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    social: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    independence: 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
    skills: 'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70',
    wellbeing: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
};

const STATUS_ICON: Record<string, { icon: typeof CheckCircle2; className: string }> = {
    completed: { icon: CheckCircle2, className: 'text-status-success' },
    in_progress: { icon: Clock, className: 'text-status-info' },
    not_started: { icon: Circle, className: 'text-muted-foreground' },
    on_hold: { icon: Pause, className: 'text-status-warning' },
};

const PROGRESS_COLOR: Record<string, string> = {
    completed: 'bg-status-success',
    in_progress: 'bg-status-info',
    not_started: 'bg-muted',
    on_hold: 'bg-status-warning',
};

function formatDate(date: string | null | undefined): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function isOverdue(date: string | null | undefined, status: string): boolean {
    if (!date || status === 'completed') return false;
    return new Date(date).getTime() < Date.now();
}

export function CarePlanGoalCard({ goal, carePlanId, onProgressUpdate, compact = false }: GoalCardProps) {
    const [expanded, setExpanded] = useState(false);

    const borderColor = PRIORITY_BORDER[goal.priority] ?? PRIORITY_BORDER.medium;
    const priorityText = PRIORITY_TEXT[goal.priority] ?? PRIORITY_TEXT.medium;
    const categoryBadge = CATEGORY_BADGE[goal.category] ?? 'bg-muted text-foreground dark:bg-muted/40 dark:text-muted-foreground';
    const statusInfo = STATUS_ICON[goal.status] ?? STATUS_ICON.not_started;
    const StatusIcon = statusInfo.icon;
    const progressColor = PROGRESS_COLOR[goal.status] ?? PROGRESS_COLOR.not_started;

    const descriptionLong = (goal.description?.length ?? 0) > 100;
    const overdue = isOverdue(goal.target_date, goal.status);

    function handleMarkAchieved() {
        if (onProgressUpdate) {
            onProgressUpdate(goal.id, 100, 'completed');
        } else {
            router.patch(`/operations/care-plans/${carePlanId}/goals/${goal.id}/progress`, {
                progress_percentage: 100,
                status: 'completed',
            }, { preserveScroll: true });
        }
    }

    function handleStart() {
        if (onProgressUpdate) {
            onProgressUpdate(goal.id, goal.progress_percentage, 'in_progress');
        } else {
            router.patch(`/operations/care-plans/${carePlanId}/goals/${goal.id}/progress`, {
                status: 'in_progress',
            }, { preserveScroll: true });
        }
    }

    return (
        <Card className={`border-l-4 ${borderColor} transition-shadow hover:shadow-md`}>
            <CardContent className={compact ? 'p-3' : 'p-4'}>
                <div className="flex items-start gap-3">
                    {/* Status icon */}
                    <div className="shrink-0 mt-0.5">
                        <StatusIcon className={`h-5 w-5 ${statusInfo.className}`} />
                    </div>

                    {/* Main content */}
                    <div className="min-w-0 flex-1">
                        {/* Header */}
                        <div className="flex items-center gap-2 flex-wrap">
                            <h4 className={`font-semibold text-foreground ${compact ? 'text-sm' : 'text-base'}`}>
                                {goal.title}
                            </h4>
                            <Badge className={`${categoryBadge} border-0 text-[10px] capitalize`}>
                                {goal.category}
                            </Badge>
                            <span className={`text-[10px] font-medium uppercase tracking-wider ${priorityText}`}>
                                {goal.priority}
                            </span>
                        </div>

                        {/* Progress bar */}
                        <div className="mt-2 flex items-center gap-2">
                            <Progress
                                value={goal.progress_percentage}
                                className={`h-1.5 flex-1 ${
                                    goal.status === 'completed'
                                        ? '[&>div]:bg-status-success'
                                        : goal.status === 'in_progress'
                                          ? '[&>div]:bg-status-info'
                                          : goal.status === 'on_hold'
                                            ? '[&>div]:bg-status-warning'
                                            : '[&>div]:bg-muted'
                                }`}
                            />
                            <span className="text-xs tabular-nums text-muted-foreground shrink-0">
                                {goal.progress_percentage}%
                            </span>
                        </div>

                        {/* Target date */}
                        {goal.target_date && (
                            <div className="mt-1.5 flex items-center gap-1">
                                <Calendar className={`h-3 w-3 ${overdue ? 'text-status-critical' : 'text-muted-foreground'}`} />
                                <span className={`text-[11px] ${overdue ? 'text-status-critical dark:text-status-critical font-medium' : 'text-muted-foreground'}`}>
                                    {overdue ? 'Overdue: ' : 'Target: '}
                                    {formatDate(goal.target_date)}
                                </span>
                            </div>
                        )}

                        {/* Description (expandable) */}
                        {!compact && goal.description && (
                            <div className="mt-2">
                                <p className="text-xs text-muted-foreground leading-relaxed">
                                    {descriptionLong && !expanded
                                        ? `${goal.description.slice(0, 100)}...`
                                        : goal.description}
                                </p>
                                {descriptionLong && (
                                    <button
                                        onClick={() => setExpanded(!expanded)}
                                        className="mt-0.5 flex items-center gap-0.5 text-[10px] text-primary dark:text-primary hover:underline"
                                    >
                                        {expanded ? (
                                            <>
                                                <ChevronUp className="h-3 w-3" /> Show less
                                            </>
                                        ) : (
                                            <>
                                                <ChevronDown className="h-3 w-3" /> Show more
                                            </>
                                        )}
                                    </button>
                                )}
                            </div>
                        )}

                        {/* Quick actions */}
                        {!compact && (
                            <div className="mt-2.5 flex items-center gap-2">
                                {goal.status === 'not_started' && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-6 text-[11px] px-2 text-status-info border-status-info/30 hover:bg-status-info-bg dark:text-status-info dark:border-status-info/30 dark:hover:bg-status-info"
                                        onClick={handleStart}
                                    >
                                        Start
                                    </Button>
                                )}
                                {goal.status !== 'completed' && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="h-6 text-[11px] px-2 text-status-success border-status-success/30 hover:bg-status-success-bg dark:text-status-success dark:border-status-success/30 dark:hover:bg-status-success"
                                        onClick={handleMarkAchieved}
                                    >
                                        <CheckCircle2 className="h-3 w-3 mr-0.5" />
                                        Mark Achieved
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
