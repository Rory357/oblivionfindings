import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { PageHero, PageLayout } from '@/components/page';
import { MyHrTabs } from '@/components/hr';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ChevronDown, Target } from 'lucide-react';
import { useState } from 'react';

interface Goal {
    id: number;
    title: string;
    description: string | null;
    category: string;
    competency_area: string | null;
    target_level: number | null;
    current_level: number | null;
    status:
        | 'not_started'
        | 'in_progress'
        | 'blocked'
        | 'completed'
        | 'cancelled';
    progress_percent: number;
    start_date: string | null;
    due_date: string | null;
    completed_at: string | null;
    review_notes: string | null;
    manager: { id: number; name: string } | null;
}

interface Props {
    goals: {
        data: Goal[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/my' },
    { title: 'My HR', href: '/hr/my' },
    { title: 'My Goals', href: '/hr/my/goals' },
];

const statusConfig: Record<string, { className: string; label: string }> = {
    not_started: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Not Started',
    },
    in_progress: {
        className: 'border-status-info/30 text-status-info bg-status-info-bg',
        label: 'In Progress',
    },
    blocked: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical-bg',
        label: 'Blocked',
    },
    completed: {
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'Completed',
    },
    cancelled: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
        label: 'Cancelled',
    },
};

const categoryConfig: Record<string, string> = {
    growth: 'bg-primary/10 text-primary',
    performance: 'bg-status-info-bg text-status-info',
    leadership: 'bg-status-warning-bg text-status-warning',
    compliance: 'bg-status-critical-bg text-status-critical',
    capability: 'bg-status-info-bg text-status-info',
};

function GoalCard({ goal }: { goal: Goal }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        status: goal.status,
        progress_percent: goal.progress_percent,
        review_notes: goal.review_notes ?? '',
        current_level: goal.current_level ?? '',
    });

    const sc = statusConfig[goal.status] || statusConfig.not_started;
    const isActive = !['completed', 'cancelled'].includes(goal.status);
    const isOverdue =
        isActive && goal.due_date && new Date(goal.due_date) < new Date();

    const handleSave = () => {
        form.put(`/hr/my/goals/${goal.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    return (
        <Card className={isOverdue ? 'border-status-critical/30' : undefined}>
            <Collapsible>
                <CollapsibleTrigger className="w-full text-left">
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <Target className="h-4 w-4 text-muted-foreground" />
                                <CardTitle className="text-base">
                                    {goal.title}
                                </CardTitle>
                                <Badge
                                    variant="outline"
                                    className={sc.className}
                                >
                                    {sc.label}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className={
                                        categoryConfig[goal.category] ||
                                        'bg-muted text-foreground'
                                    }
                                >
                                    {goal.category}
                                </Badge>
                                {isOverdue && (
                                    <Badge
                                        variant="destructive"
                                        className="text-xs"
                                    >
                                        Overdue
                                    </Badge>
                                )}
                            </div>
                            <div className="flex items-center gap-3">
                                <span className="text-sm text-muted-foreground">
                                    {goal.progress_percent}%
                                </span>
                                <ChevronDown className="h-4 w-4 text-muted-foreground" />
                            </div>
                        </div>
                        <div className="mt-2 px-0">
                            <Progress
                                value={goal.progress_percent}
                                className="h-2"
                            />
                        </div>
                        <div className="mt-2 flex gap-4 text-sm text-muted-foreground">
                            {goal.due_date && <span>Due: {goal.due_date}</span>}
                            {goal.manager && (
                                <span>Manager: {goal.manager.name}</span>
                            )}
                        </div>
                    </CardHeader>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <CardContent className="space-y-4 pt-0">
                        {goal.description && (
                            <div>
                                <Label className="text-sm font-medium">
                                    Description
                                </Label>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {goal.description}
                                </p>
                            </div>
                        )}

                        {goal.competency_area && (
                            <div className="flex gap-4 text-sm">
                                <span className="text-muted-foreground">
                                    Competency: {goal.competency_area}
                                </span>
                                {goal.target_level && (
                                    <span className="text-muted-foreground">
                                        Target Level: {goal.target_level}
                                    </span>
                                )}
                                {goal.current_level && (
                                    <span className="text-muted-foreground">
                                        Current Level: {goal.current_level}
                                    </span>
                                )}
                            </div>
                        )}

                        {goal.start_date && (
                            <div className="text-sm text-muted-foreground">
                                Started: {goal.start_date}
                            </div>
                        )}

                        {goal.completed_at && (
                            <div className="text-sm text-muted-foreground">
                                Completed: {goal.completed_at}
                            </div>
                        )}

                        {editing ? (
                            <div className="space-y-4 border-t pt-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <Label>Status</Label>
                                        <Select
                                            value={form.data.status}
                                            onValueChange={(val) =>
                                                form.setData(
                                                    'status',
                                                    val as Goal['status'],
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="not_started">
                                                    Not Started
                                                </SelectItem>
                                                <SelectItem value="in_progress">
                                                    In Progress
                                                </SelectItem>
                                                <SelectItem value="blocked">
                                                    Blocked
                                                </SelectItem>
                                                <SelectItem value="completed">
                                                    Completed
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Progress (%)</Label>
                                        <Input
                                            type="number"
                                            min={0}
                                            max={100}
                                            value={form.data.progress_percent}
                                            onChange={(e) =>
                                                form.setData(
                                                    'progress_percent',
                                                    parseInt(e.target.value) ||
                                                        0,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                {goal.competency_area && (
                                    <div>
                                        <Label>Current Level (1-5)</Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            max={5}
                                            value={form.data.current_level}
                                            onChange={(e) =>
                                                form.setData(
                                                    'current_level',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                )}
                                <div>
                                    <Label>Review Notes</Label>
                                    <Textarea
                                        value={form.data.review_notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'review_notes',
                                                e.target.value,
                                            )
                                        }
                                        className="min-h-[80px]"
                                        placeholder="Add your notes on progress..."
                                    />
                                </div>
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        onClick={handleSave}
                                        disabled={form.processing}
                                    >
                                        Save
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setEditing(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <div className="border-t pt-4">
                                <Label className="text-sm font-medium">
                                    Review Notes
                                </Label>
                                <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">
                                    {goal.review_notes || 'No notes yet.'}
                                </p>
                                {isActive && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="mt-2"
                                        onClick={() => setEditing(true)}
                                    >
                                        Update Progress
                                    </Button>
                                )}
                            </div>
                        )}
                    </CardContent>
                </CollapsibleContent>
            </Collapsible>
        </Card>
    );
}

export default function MyGoals({ goals }: Props) {
    const completed = goals.data.filter((g) => g.status === 'completed').length;
    const inProgress = goals.data.filter((g) => g.status === 'in_progress').length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="My Goals" />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        icon={Target}
                        title="My Development Goals"
                        description="Track and update your development and performance goals."
                        stats={[
                            { label: 'Total', value: goals.data.length },
                            { label: 'In Progress', value: inProgress },
                            { label: 'Completed', value: completed },
                        ]}
                    />
                }
            >
                <MyHrTabs active="goals" />

                {goals.data.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No development goals found.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-4">
                        {goals.data.map((goal) => (
                            <GoalCard key={goal.id} goal={goal} />
                        ))}
                    </div>
                )}

                {goals.last_page > 1 && (
                    <LaravelPagination links={goals.links} />
                )}
            </PageLayout>
        </AppLayout>
    );
}
