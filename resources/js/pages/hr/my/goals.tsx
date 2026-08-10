import { MyHrShell, type MyHrShellData } from '@/components/hr';
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
import { useForm } from '@inertiajs/react';
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

interface ObjectiveKeyResult {
    id: number;
    title: string;
    current_value: number | null;
    target_value: number | null;
    unit: string | null;
}

interface Objective {
    id: number;
    title: string;
    status: string;
    confidence: 'on_track' | 'at_risk' | 'off_track';
    progress_percentage: number;
    due_date: string | null;
    last_checkin_at: string | null;
    cycle: string | null;
    key_results: ObjectiveKeyResult[];
}

interface Props {
    myHr: MyHrShellData;
    goals: {
        data: Goal[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
    objectives: Objective[];
}

const statusConfig: Record<string, { className: string; label: string }> = {
    not_started: {
        className:
            'border-border/30 text-muted-foreground bg-muted-foreground/10',
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
            'border-border/30 text-muted-foreground bg-muted-foreground/10',
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

const confidenceConfig: Record<string, { className: string; label: string }> = {
    on_track: {
        className:
            'border-status-success/30 text-status-success bg-status-success-bg',
        label: 'On track',
    },
    at_risk: {
        className:
            'border-status-warning/30 text-status-warning bg-status-warning-bg',
        label: 'At risk',
    },
    off_track: {
        className:
            'border-status-critical/30 text-status-critical bg-status-critical-bg',
        label: 'Off track',
    },
};

function ObjectiveCard({ objective }: { objective: Objective }) {
    const [checkingIn, setCheckingIn] = useState(false);
    const hasKeyResults = objective.key_results.length > 0;
    const form = useForm<{
        confidence: string;
        comment: string;
        manual_progress: number;
        key_results: Array<{ id: number; current_value: number }>;
    }>({
        confidence: objective.confidence,
        comment: '',
        manual_progress: objective.progress_percentage,
        key_results: objective.key_results.map((kr) => ({
            id: kr.id,
            current_value: kr.current_value ?? 0,
        })),
    });

    const cc =
        confidenceConfig[objective.confidence] ?? confidenceConfig.on_track;

    const submitCheckin = () => {
        form.transform((data) => ({
            confidence: data.confidence,
            comment: data.comment || null,
            ...(hasKeyResults
                ? { key_results: data.key_results }
                : { manual_progress: data.manual_progress }),
        }));
        form.post(`/hr/my/goals/${objective.id}/checkin`, {
            preserveScroll: true,
            onSuccess: () => setCheckingIn(false),
        });
    };

    return (
        <Card>
            <CardHeader className="pb-3">
                <div className="flex items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                        <Target className="h-4 w-4 shrink-0 text-muted-foreground" />
                        <CardTitle className="truncate text-base">
                            {objective.title}
                        </CardTitle>
                        <Badge variant="outline" className={cc.className}>
                            {cc.label}
                        </Badge>
                        {objective.cycle && (
                            <Badge
                                variant="outline"
                                className="bg-muted text-foreground"
                            >
                                {objective.cycle}
                            </Badge>
                        )}
                    </div>
                    <div className="flex shrink-0 items-center gap-3">
                        <span className="text-sm text-muted-foreground">
                            {objective.progress_percentage}%
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setCheckingIn((v) => !v)}
                        >
                            Check in
                        </Button>
                    </div>
                </div>
                <div className="mt-2">
                    <Progress
                        value={objective.progress_percentage}
                        className="h-2"
                    />
                </div>
                <div className="mt-2 flex gap-4 text-sm text-muted-foreground">
                    {objective.due_date && (
                        <span>Due: {objective.due_date}</span>
                    )}
                    {objective.last_checkin_at && (
                        <span>Last check-in: {objective.last_checkin_at}</span>
                    )}
                </div>
            </CardHeader>
            {checkingIn && (
                <CardContent className="space-y-4 border-t pt-4">
                    {hasKeyResults ? (
                        <div className="space-y-3">
                            <Label className="text-sm font-medium">
                                Key results
                            </Label>
                            {objective.key_results.map((kr, index) => (
                                <div
                                    key={kr.id}
                                    className="flex items-center gap-3"
                                >
                                    <span className="min-w-0 flex-1 truncate text-sm">
                                        {kr.title}
                                    </span>
                                    <Input
                                        type="number"
                                        className="w-28"
                                        value={
                                            form.data.key_results[index]
                                                ?.current_value ?? 0
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                'key_results',
                                                form.data.key_results.map(
                                                    (row, i) =>
                                                        i === index
                                                            ? {
                                                                  ...row,
                                                                  current_value:
                                                                      parseFloat(
                                                                          e
                                                                              .target
                                                                              .value,
                                                                      ) || 0,
                                                              }
                                                            : row,
                                                ),
                                            )
                                        }
                                    />
                                    <span className="w-24 text-xs text-muted-foreground">
                                        of {kr.target_value ?? '—'}
                                        {kr.unit ? ` ${kr.unit}` : ''}
                                    </span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div>
                            <Label>Progress (%)</Label>
                            <Input
                                type="number"
                                min={0}
                                max={100}
                                value={form.data.manual_progress}
                                onChange={(e) =>
                                    form.setData(
                                        'manual_progress',
                                        parseInt(e.target.value) || 0,
                                    )
                                }
                            />
                        </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <Label>Confidence</Label>
                            <Select
                                value={form.data.confidence}
                                onValueChange={(val) =>
                                    form.setData('confidence', val)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="on_track">
                                        On track
                                    </SelectItem>
                                    <SelectItem value="at_risk">
                                        At risk
                                    </SelectItem>
                                    <SelectItem value="off_track">
                                        Off track
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div>
                        <Label>Comment</Label>
                        <Textarea
                            value={form.data.comment}
                            onChange={(e) =>
                                form.setData('comment', e.target.value)
                            }
                            className="min-h-[60px]"
                            placeholder="What changed since your last check-in?"
                        />
                    </div>
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            onClick={submitCheckin}
                            disabled={form.processing}
                        >
                            Save check-in
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setCheckingIn(false)}
                        >
                            Cancel
                        </Button>
                    </div>
                </CardContent>
            )}
        </Card>
    );
}

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

export default function MyGoals({ myHr, goals, objectives = [] }: Props) {
    return (
        <MyHrShell active="goals" myHr={myHr} title="Goals · My HR">
            {objectives.length > 0 && (
                <div className="space-y-4">
                    <h2 className="text-base font-semibold">
                        My objectives (OKRs)
                    </h2>
                    {objectives.map((objective) => (
                        <ObjectiveCard
                            key={objective.id}
                            objective={objective}
                        />
                    ))}
                </div>
            )}

            {(objectives.length > 0 || goals.data.length > 0) && (
                <h2 className="text-base font-semibold">Development goals</h2>
            )}

            {goals.data.length === 0 ? (
                <Card>
                    <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                        <Target className="h-8 w-8 text-muted-foreground/40" />
                        <div className="text-sm font-semibold">
                            No development goals yet
                        </div>
                        <p className="max-w-sm text-[13px] text-muted-foreground">
                            Development goals are set with your manager —
                            usually during a review or 1:1. Once one is created
                            for you, you can track and update it here.
                        </p>
                    </CardContent>
                </Card>
            ) : (
                <div className="space-y-4">
                    {goals.data.map((goal) => (
                        <GoalCard key={goal.id} goal={goal} />
                    ))}
                </div>
            )}

            {goals.last_page > 1 && <LaravelPagination links={goals.links} />}
        </MyHrShell>
    );
}
