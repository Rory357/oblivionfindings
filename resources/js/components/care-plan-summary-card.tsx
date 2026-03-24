import { Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Calendar, CheckCircle2, AlertTriangle, Eye, Pencil } from 'lucide-react';

interface CarePlanSummaryCardProps {
    plan: {
        id: number;
        title: string;
        status: string;
        plan_type: string;
        starts_at?: string | null;
        ends_at?: string | null;
        next_review_at?: string | null;
        goals_count?: number;
        goals_achieved_count?: number;
        client?: { id: number; first_name: string; last_name: string } | null;
    };
    showClient?: boolean;
    compact?: boolean;
}

const STATUS_BORDER: Record<string, string> = {
    active: 'border-l-emerald-500',
    draft: 'border-l-slate-400',
    review: 'border-l-amber-500',
    archived: 'border-l-neutral-400',
};

const STATUS_BADGE: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300',
    draft: 'bg-slate-100 text-slate-700 dark:bg-slate-800/40 dark:text-slate-300',
    review: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
    archived: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800/40 dark:text-neutral-400',
};

const PLAN_TYPE_BADGE: Record<string, string> = {
    standard: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300',
    emergency: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300',
    interim: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300',
    review: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300',
};

function formatDate(date: string | null | undefined): string {
    if (!date) return '—';
    return new Date(date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

function daysUntil(date: string | null | undefined): number | null {
    if (!date) return null;
    const diff = new Date(date).getTime() - Date.now();
    return Math.ceil(diff / (1000 * 60 * 60 * 24));
}

function MiniProgressRing({ achieved, total, size = 36 }: { achieved: number; total: number; size?: number }) {
    const strokeWidth = 4;
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const pct = total > 0 ? achieved / total : 0;
    const dashLength = pct * circumference;
    const dashGap = circumference - dashLength;

    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} className="shrink-0">
            <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke="currentColor"
                strokeWidth={strokeWidth}
                className="text-muted/20"
            />
            {total > 0 && (
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={radius}
                    fill="none"
                    stroke="#10b981"
                    strokeWidth={strokeWidth}
                    strokeDasharray={`${dashLength} ${dashGap}`}
                    strokeLinecap="round"
                    transform={`rotate(-90 ${size / 2} ${size / 2})`}
                />
            )}
            {total > 0 && pct >= 1 && (
                <text
                    x="50%"
                    y="50%"
                    textAnchor="middle"
                    dominantBaseline="central"
                    className="fill-emerald-600 dark:fill-emerald-400"
                    style={{ fontSize: 10, fontWeight: 700 }}
                >
                    ✓
                </text>
            )}
        </svg>
    );
}

export function CarePlanSummaryCard({ plan, showClient = false, compact = false }: CarePlanSummaryCardProps) {
    const borderColor = STATUS_BORDER[plan.status] ?? STATUS_BORDER.draft;
    const statusBadge = STATUS_BADGE[plan.status] ?? STATUS_BADGE.draft;
    const typeBadge = PLAN_TYPE_BADGE[plan.plan_type] ?? PLAN_TYPE_BADGE.standard;

    const goalsCount = plan.goals_count ?? 0;
    const goalsAchieved = plan.goals_achieved_count ?? 0;

    const reviewDays = daysUntil(plan.next_review_at);
    const isReviewOverdue = reviewDays !== null && reviewDays < 0;

    return (
        <Card className={`border-l-4 ${borderColor} transition-shadow hover:shadow-md`}>
            <CardContent className={compact ? 'p-3' : 'p-4'}>
                <div className="flex items-start gap-3">
                    {/* Progress ring */}
                    <MiniProgressRing achieved={goalsAchieved} total={goalsCount} size={compact ? 32 : 36} />

                    {/* Main content */}
                    <div className="min-w-0 flex-1">
                        {/* Header row */}
                        <div className="flex items-center gap-2 flex-wrap">
                            <h3 className={`font-semibold text-foreground truncate ${compact ? 'text-sm' : 'text-base'}`}>
                                {plan.title}
                            </h3>
                            <Badge className={`${statusBadge} border-0 text-[10px] capitalize`}>
                                {plan.status}
                            </Badge>
                            <Badge className={`${typeBadge} border-0 text-[10px] capitalize`}>
                                {plan.plan_type.replace(/_/g, ' ')}
                            </Badge>
                            {isReviewOverdue && (
                                <Badge className="border-0 bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300 text-[10px]">
                                    <AlertTriangle className="h-3 w-3 mr-0.5" />
                                    Review overdue
                                </Badge>
                            )}
                        </div>

                        {/* Client name */}
                        {showClient && plan.client && (
                            <p className="text-xs text-muted-foreground mt-0.5">
                                {plan.client.first_name} {plan.client.last_name}
                            </p>
                        )}

                        {/* Goals summary */}
                        <div className="flex items-center gap-3 mt-1.5 flex-wrap">
                            <span className="text-xs text-muted-foreground flex items-center gap-1">
                                <CheckCircle2 className="h-3 w-3 text-emerald-500" />
                                {goalsAchieved}/{goalsCount} goals completed
                            </span>
                        </div>

                        {/* Dates */}
                        {!compact && (
                            <div className="flex items-center gap-4 mt-2 text-[11px] text-muted-foreground flex-wrap">
                                {(plan.starts_at || plan.ends_at) && (
                                    <span className="flex items-center gap-1">
                                        <Calendar className="h-3 w-3" />
                                        {formatDate(plan.starts_at)} — {formatDate(plan.ends_at)}
                                    </span>
                                )}
                                {plan.next_review_at && (
                                    <span className={`flex items-center gap-1 ${isReviewOverdue ? 'text-amber-600 dark:text-amber-400 font-medium' : ''}`}>
                                        <Calendar className="h-3 w-3" />
                                        Review: {formatDate(plan.next_review_at)}
                                        {reviewDays !== null && !isReviewOverdue && (
                                            <span className="text-muted-foreground">({reviewDays}d)</span>
                                        )}
                                    </span>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Quick actions */}
                    {!compact && (
                        <div className="flex items-center gap-1 shrink-0">
                            <Button variant="ghost" size="sm" asChild className="h-7 w-7 p-0">
                                <Link href={`/operations/care-plans/${plan.id}`}>
                                    <Eye className="h-3.5 w-3.5" />
                                </Link>
                            </Button>
                            {plan.status !== 'archived' && (
                                <Button variant="ghost" size="sm" asChild className="h-7 w-7 p-0">
                                    <Link href={`/operations/care-plans/${plan.id}/edit`}>
                                        <Pencil className="h-3.5 w-3.5" />
                                    </Link>
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
