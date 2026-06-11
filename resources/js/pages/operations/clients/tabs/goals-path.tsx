import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    CheckCircle2,
    Circle,
    Compass,
    Flag,
    HandHeart,
    Heart,
    PauseCircle,
    Pencil,
    Plus,
    Route as RouteIcon,
    ShieldAlert,
    Sparkles,
    Star,
    Target,
    Trophy,
    Waves,
} from 'lucide-react';

type Goal = {
    id?: number | string;
    title?: string | null;
    status?: string | null;
    category?: string | null;
    priority?: string | null;
    progress_percentage?: number | null;
    target_date?: string | null;
    description?: string | null;
    steps_count?: number | null;
    steps_done_count?: number | null;
    open_hurdles_count?: number | null;
};

type GoalsPathTabProps = {
    clientId: number;
    clientName: string;
    activePlanId?: number | null;
    goals?: Goal[];
    lifeStory?: string | null;
    strengthsAbilities?: string | null;
    interestsHobbies?: string | null;
    pathPlan?: {
        id?: number;
        dream?: string | null;
        north_star?: string | null;
        strengths?: string[] | null;
        action_steps?: string[] | null;
        trusted_people?: string[] | null;
        independence_goals?: string[] | null;
        community?: string | null;
        meaningful_outcomes?: string | null;
        plan_date?: string | null;
        next_review_at?: string | null;
    } | null;
    canEdit?: boolean;
    onAddGoal?: () => void;
    onManageGoal?: (goal: Goal) => void;
    onEditPlan?: () => void;
};

const STATUS_META: Record<
    string,
    { tone: string; label: string; icon: typeof Flag }
> = {
    completed: { tone: 'bg-status-success-bg text-status-success', label: 'Achieved', icon: CheckCircle2 },
    in_progress: { tone: 'bg-status-info-bg text-status-info', label: 'In progress', icon: RouteIcon },
    on_hold: { tone: 'bg-status-warning-bg text-status-warning', label: 'On hold', icon: PauseCircle },
    cancelled: { tone: 'bg-muted text-muted-foreground', label: 'Cancelled', icon: Circle },
    not_started: { tone: 'bg-muted text-muted-foreground', label: 'Not started', icon: Circle },
};

function metaFor(status?: string | null) {
    return STATUS_META[status ?? 'not_started'] ?? STATUS_META.not_started;
}

function GoalCardTile({ goal, onClick }: { goal: Goal; onClick?: () => void }) {
    const meta = metaFor(goal.status);
    const Icon = meta.icon;
    const pct = goal.progress_percentage ?? 0;
    const stepsTotal = goal.steps_count ?? 0;
    const hurdles = goal.open_hurdles_count ?? 0;
    const subline = [
        goal.category ?? 'Goal',
        stepsTotal > 0 ? `${goal.steps_done_count ?? 0}/${stepsTotal} steps` : null,
        hurdles > 0 ? `${hurdles} open hurdle${hurdles === 1 ? '' : 's'}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        /* eslint-disable-next-line no-restricted-syntax -- clickable goal card opens the manage wizard */
        <button
            type="button"
            onClick={onClick}
            disabled={!onClick}
            data-test="goal-card"
            className={cn(
                'rounded-xl border bg-card p-4 text-left transition-colors',
                onClick
                    ? 'cursor-pointer hover:border-primary/50 hover:bg-accent/40'
                    : 'cursor-default',
            )}
        >
            <div className="flex items-start gap-3">
                <span className={cn('mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg', meta.tone)}>
                    <Icon className="h-[18px] w-[18px]" />
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-2">
                        <span className="truncate text-sm font-semibold">{goal.title ?? 'Untitled goal'}</span>
                        <Badge className={cn(meta.tone, 'shrink-0')}>{meta.label}</Badge>
                    </div>
                    <div className="mt-1 text-[11px] text-muted-foreground">{subline}</div>
                    <div className="mt-2.5 flex items-center gap-2">
                        <Progress value={pct} className="h-2" />
                        <span className="w-9 shrink-0 text-right text-xs font-medium text-muted-foreground">{pct}%</span>
                    </div>
                </div>
            </div>
        </button>
    );
}

function StorySection({
    icon: Icon,
    label,
    body,
    placeholder,
}: {
    icon: typeof Heart;
    label: string;
    body?: string | null;
    placeholder: string;
}) {
    return (
        /* eslint-disable-next-line no-restricted-syntax -- compact story tile rendered in a 3-column grid. */
        <div className="rounded-lg border bg-card p-4">
            <div className="flex items-start gap-3">
                <span className="mt-0.5 rounded-md bg-primary/10 p-2 text-primary">
                    <Icon className="h-4 w-4" />
                </span>
                <div className="min-w-0 flex-1">
                    <p className="font-medium">{label}</p>
                    {body?.trim() ? (
                        <p className="mt-2 text-sm leading-6 whitespace-pre-wrap">{body}</p>
                    ) : (
                        <p className="mt-2 text-sm italic text-muted-foreground">{placeholder}</p>
                    )}
                </div>
            </div>
        </div>
    );
}

function PathPillar({
    icon: Icon,
    title,
    items,
    placeholder,
    tone,
}: {
    icon: typeof Compass;
    title: string;
    items?: string[] | null;
    placeholder: string;
    tone: 'primary' | 'info' | 'success' | 'warning';
}) {
    const toneClass = {
        primary: 'bg-primary/10 text-primary',
        info: 'bg-status-info-bg text-status-info',
        success: 'bg-status-success-bg text-status-success',
        warning: 'bg-status-warning-bg text-status-warning',
    }[tone];

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <span className={cn('rounded-md p-1.5', toneClass)}>
                        <Icon className="h-4 w-4" />
                    </span>
                    {title}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {items && items.length > 0 ? (
                    <ul className="space-y-2 text-sm">
                        {items.map((item, idx) => (
                            <li key={idx} className="flex items-start gap-2">
                                <span className="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
                                <span>{item}</span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm italic text-muted-foreground">{placeholder}</p>
                )}
            </CardContent>
        </Card>
    );
}

export function GoalsPathTab({
    clientName,
    activePlanId,
    goals = [],
    lifeStory,
    strengthsAbilities,
    interestsHobbies,
    pathPlan,
    canEdit = false,
    onAddGoal,
    onManageGoal,
    onEditPlan,
}: GoalsPathTabProps) {
    const sortedGoals = [...goals].sort((a, b) => {
        if ((a.status === 'completed') !== (b.status === 'completed')) {
            return a.status === 'completed' ? 1 : -1;
        }
        return (b.progress_percentage ?? 0) - (a.progress_percentage ?? 0);
    });
    const achieved = goals.filter((g) => g.status === 'completed').length;
    const inProgress = goals.length - achieved;

    return (
        <div className="space-y-6" data-test="client-goals-path-tab">
            {/* ── Goals path (design card grid) ── */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <Flag className="h-[19px] w-[19px]" />
                    </span>
                    <div>
                        <h2 className="text-lg font-semibold leading-tight">Goals path</h2>
                        <p className="text-sm text-muted-foreground">
                            {achieved} achieved · {inProgress} in progress
                        </p>
                    </div>
                </div>
                {canEdit && onAddGoal ? (
                    <Button onClick={onAddGoal} data-test="goals-add-goal">
                        <Plus className="mr-1.5 h-4 w-4" />
                        Add goal
                    </Button>
                ) : null}
            </div>

            {sortedGoals.length > 0 ? (
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                    {sortedGoals.map((goal, idx) => (
                        <GoalCardTile
                            key={goal.id ?? idx}
                            goal={goal}
                            onClick={
                                canEdit && onManageGoal
                                    ? () => onManageGoal(goal)
                                    : undefined
                            }
                        />
                    ))}
                </div>
            ) : (
                <EmptyState
                    icon={Target}
                    title="No goals captured yet"
                    description="Add goals to the active care plan to make day-to-day support intentional."
                    action={
                        canEdit && onAddGoal ? (
                            <Button onClick={onAddGoal}>
                                <Plus className="mr-1.5 h-4 w-4" />
                                Add the first goal
                            </Button>
                        ) : undefined
                    }
                />
            )}

            {/* ── Person-centred planning (secondary) ── */}
            {/* eslint-disable-next-line no-restricted-syntax -- section header band, not a content card */}
            <div className="rounded-lg border bg-card p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="text-base font-semibold">
                            Person-centred planning for {clientName}
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            The whole-of-life story and PATH framework behind the goals above.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canEdit && onEditPlan ? (
                            <Button size="sm" variant="outline" onClick={onEditPlan} data-test="goals-edit-plan">
                                <Pencil className="mr-1.5 h-3.5 w-3.5" />
                                Edit planning
                            </Button>
                        ) : null}
                        {activePlanId ? (
                            <Button asChild size="sm" variant="outline">
                                <Link href={`/operations/care-plans/${activePlanId}`}>Open care plan</Link>
                            </Button>
                        ) : null}
                    </div>
                </div>
                {pathPlan?.next_review_at ? (
                    <p className="mt-3 text-xs text-muted-foreground">
                        Next PATH review:{' '}
                        <span className="font-medium">
                            {new Date(pathPlan.next_review_at).toLocaleDateString('en-NZ', {
                                day: 'numeric',
                                month: 'long',
                                year: 'numeric',
                            })}
                        </span>
                    </p>
                ) : null}
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                <StorySection
                    icon={Heart}
                    label="Life story"
                    body={lifeStory}
                    placeholder="No life story captured. Add background, key moments, and what this client wants the team to know."
                />
                <StorySection
                    icon={HandHeart}
                    label="Strengths & abilities"
                    body={strengthsAbilities}
                    placeholder="No strengths captured yet. Recording what this client does well grounds person-centred support."
                />
                <StorySection
                    icon={Sparkles}
                    label="Interests & hobbies"
                    body={interestsHobbies}
                    placeholder="No interests captured yet. Hobbies inform meaningful activities and community connection."
                />
            </div>

            <div>
                <div className="mb-3 flex items-center gap-2">
                    <Compass className="h-4 w-4 text-primary" />
                    <h3 className="text-base font-semibold">PATH planning framework</h3>
                </div>
                <div className="grid gap-4 md:grid-cols-2">
                    <PathPillar
                        icon={Star}
                        title="The dream"
                        items={
                            pathPlan?.dream
                                ? [pathPlan.dream]
                                : pathPlan?.north_star
                                  ? [pathPlan.north_star]
                                  : null
                        }
                        placeholder="What is this client's biggest hope for their future? Capture it in their own words."
                        tone="primary"
                    />
                    <PathPillar
                        icon={Trophy}
                        title="Strengths to build on"
                        items={pathPlan?.strengths}
                        placeholder="List concrete strengths from PATH meetings or family input."
                        tone="success"
                    />
                    <PathPillar
                        icon={Compass}
                        title="Trusted people"
                        items={pathPlan?.trusted_people}
                        placeholder="Who supports this client to move toward their dream?"
                        tone="info"
                    />
                    <PathPillar
                        icon={Target}
                        title="Independence goals"
                        items={pathPlan?.independence_goals}
                        placeholder="What independence steps is this client working toward?"
                        tone="warning"
                    />
                    <PathPillar
                        icon={Waves}
                        title="Community & belonging"
                        items={pathPlan?.community ? [pathPlan.community] : null}
                        placeholder="Activities, groups, or relationships that build belonging."
                        tone="info"
                    />
                    <PathPillar
                        icon={ShieldAlert}
                        title="Next action steps"
                        items={pathPlan?.action_steps}
                        placeholder="Short concrete steps the team will work on next."
                        tone="primary"
                    />
                </div>
            </div>
        </div>
    );
}
