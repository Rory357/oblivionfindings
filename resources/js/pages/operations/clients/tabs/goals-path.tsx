import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    Compass,
    HandHeart,
    Heart,
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
    progress_percentage?: number | null;
    target_date?: string | null;
    description?: string | null;
};

type GoalsPathTabProps = {
    clientName: string;
    activePlanId?: number | null;
    goals?: Goal[];
    lifeStory?: string | null;
    strengthsAbilities?: string | null;
    interestsHobbies?: string | null;
    pathPlan?: {
        dream?: string | null;
        north_star?: string | null;
        strengths?: string[] | null;
        action_steps?: string[] | null;
        trusted_people?: string[] | null;
        independence_goals?: string[] | null;
        community?: string | null;
        meaningful_outcomes?: string | null;
    } | null;
};

function statusTone(status?: string | null) {
    const s = (status ?? '').toLowerCase();
    if (s === 'completed') return 'bg-status-success-bg text-status-success';
    if (s === 'in_progress') return 'bg-status-info-bg text-status-info';
    if (s === 'blocked') return 'bg-status-critical-bg text-status-critical';
    if (s === 'paused' || s === 'on_hold')
        return 'bg-status-warning-bg text-status-warning';
    return 'bg-muted text-muted-foreground';
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
                        <p className="mt-2 text-sm leading-6 whitespace-pre-wrap">
                            {body}
                        </p>
                    ) : (
                        <p className="mt-2 text-sm italic text-muted-foreground">
                            {placeholder}
                        </p>
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
                    <p className="text-sm italic text-muted-foreground">
                        {placeholder}
                    </p>
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
}: GoalsPathTabProps) {
    const sortedGoals = [...goals].sort((a, b) => {
        if ((a.status === 'completed') !== (b.status === 'completed')) {
            return a.status === 'completed' ? 1 : -1;
        }
        return (b.progress_percentage ?? 0) - (a.progress_percentage ?? 0);
    });
    const inProgress = goals.filter((g) =>
        ['in_progress', 'open', null, undefined, ''].includes(g.status ?? null),
    );
    const completed = goals.filter((g) => g.status === 'completed');

    return (
        <div className="space-y-6" data-test="client-goals-path-tab">
            <div className="rounded-lg border bg-card p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold">
                            Goals, dreams & whole of life for {clientName}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Person-centred planning surfaces alongside concrete
                            goal progress and the wider PATH framework.
                        </p>
                    </div>
                    {activePlanId ? (
                        <Button asChild size="sm" variant="outline">
                            <Link href={`/operations/care-plans/${activePlanId}`}>
                                Open care plan
                            </Link>
                        </Button>
                    ) : null}
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Target className="h-4 w-4 text-primary" />
                        Active goals
                        <Badge variant="outline" className="ml-auto">
                            {inProgress.length} in progress · {completed.length}{' '}
                            done
                        </Badge>
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {sortedGoals.length > 0 ? (
                        sortedGoals.map((goal, idx) => (
                            <div
                                key={goal.id ?? idx}
                                className={cn(
                                    'rounded-lg border p-4',
                                    goal.status === 'completed' &&
                                        'bg-status-success-bg/30',
                                )}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {goal.title ?? 'Untitled goal'}
                                        </p>
                                        {goal.description ? (
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {goal.description}
                                            </p>
                                        ) : null}
                                    </div>
                                    <Badge
                                        className={cn(
                                            statusTone(goal.status),
                                            'shrink-0 capitalize',
                                        )}
                                    >
                                        {String(
                                            goal.status ?? 'open',
                                        ).replace(/_/g, ' ')}
                                    </Badge>
                                </div>
                                {goal.progress_percentage != null ? (
                                    <div className="mt-3">
                                        <Progress
                                            value={goal.progress_percentage}
                                            className="h-2"
                                        />
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {goal.progress_percentage}%
                                            complete
                                            {goal.target_date
                                                ? ` · target ${goal.target_date}`
                                                : ''}
                                        </p>
                                    </div>
                                ) : null}
                            </div>
                        ))
                    ) : (
                        <EmptyState
                            icon={Target}
                            title="No goals captured yet"
                            description="Add goals to the active care plan to make day-to-day support intentional."
                        />
                    )}
                </CardContent>
            </Card>

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
                    <h3 className="text-base font-semibold">
                        PATH planning framework
                    </h3>
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
                        items={
                            pathPlan?.community
                                ? [pathPlan.community]
                                : null
                        }
                        placeholder="Activities, groups, or relationships that build belonging."
                        tone="info"
                    />
                    <PathPillar
                        icon={Target}
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
