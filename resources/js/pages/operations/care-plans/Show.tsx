import { DonutChart, OPS_COLORS } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CalendarDays,
    CheckCircle2,
    Circle,
    Clock,
    FileText,
    MessageSquare,
    Pencil,
    Play,
    Plus,
    Target,
} from 'lucide-react';
import { useState } from 'react';

const ANY = '__ANY__';

type Goal = {
    id: number;
    title: string;
    description: string | null;
    category: string;
    target_date: string | null;
    status: string;
    priority: string;
    progress_percentage: number;
    outcome_notes: string | null;
};

type ProgressNote = {
    id: number;
    content: string;
    note_type: string;
    mood_rating: number | null;
    visibility: string;
    created_at: string;
    user: { id: number; name: string } | null;
};

type ReviewVersion = {
    id: number;
    version: number;
    status: string;
    reviewed_at: string | null;
    reviewer: { id: number; name: string } | null;
    created_at: string;
};

type CarePlan = {
    id: number;
    title: string;
    status: string;
    plan_type: string;
    starts_at: string | null;
    ends_at: string | null;
    next_review_at: string | null;
    reviewed_at: string | null;
    version: number;
    content: any;
    client: { id: number; first_name: string; last_name: string; date_of_birth?: string | null } | null;
    creator: { id: number; name: string } | null;
    reviewer: { id: number; name: string } | null;
    goals: Goal[];
};

type Props = {
    care_plan: CarePlan;
    progressStats: {
        total_goals: number;
        completed: number;
        in_progress: number;
        avg_progress: number;
    };
    progressNotes?: ProgressNote[];
    reviewHistory?: ReviewVersion[];
    staff?: { id: number; name: string }[];
};

const GOAL_STATUS_COLORS: Record<string, string> = {
    not_started: OPS_COLORS.muted,
    in_progress: OPS_COLORS.primary,
    achieved: OPS_COLORS.success,
    discontinued: OPS_COLORS.danger,
};

const PRIORITY_COLORS: Record<string, string> = {
    low: 'text-muted-foreground',
    medium: 'text-status-warning',
    high: 'text-status-critical',
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    active: 'default',
    draft: 'outline',
    review: 'secondary',
    archived: 'secondary',
};

const SUPPORT_NEED_LABELS: Record<string, string> = {
    daily_living: 'Daily Living',
    personal_care: 'Personal Care',
    community_access: 'Community Access',
    health_management: 'Health Management',
    communication: 'Communication',
    behaviour_support: 'Behaviour Support',
    employment: 'Employment',
    education_training: 'Education/Training',
    social_participation: 'Social Participation',
    cultural_needs: 'Cultural Needs',
    spiritual_needs: 'Spiritual Needs',
    financial_management: 'Financial Management',
};

const SUPPORT_NEED_COLORS: Record<string, string> = {
    daily_living: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    personal_care: 'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70',
    community_access: 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
    health_management: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    communication: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    behaviour_support: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    employment: 'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70',
    education_training: 'bg-primary/10 text-primary dark:bg-primary/30 dark:text-primary/70',
    social_participation: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    cultural_needs: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    spiritual_needs: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    financial_management: 'bg-muted text-foreground dark:bg-muted/40 dark:text-muted-foreground',
};

function formatDate(d: string | null | undefined): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

function daysUntil(d: string | null | undefined): string {
    if (!d) return '';
    const diff = Math.ceil((new Date(d).getTime() - Date.now()) / (1000 * 60 * 60 * 24));
    if (diff < 0) return `${Math.abs(diff)}d overdue`;
    if (diff === 0) return 'Today';
    return `${diff}d remaining`;
}

function parseContent(raw: any): any {
    if (!raw) return {};
    if (typeof raw === 'string') {
        try { return JSON.parse(raw); } catch { return {}; }
    }
    return raw;
}

export default function CarePlanShow({
    care_plan,
    progressStats = { total_goals: 0, completed: 0, in_progress: 0, avg_progress: 0 } as any,
    progressNotes = [],
    reviewHistory = [],
    staff = [],
}: Props) {
    const plan = care_plan ?? ({} as CarePlan);
    const goals = plan.goals ?? [];
    const safeProgressStats = {
        total_goals: progressStats?.total_goals ?? 0,
        completed: progressStats?.completed ?? 0,
        in_progress: progressStats?.in_progress ?? 0,
        avg_progress: progressStats?.avg_progress ?? 0,
    };
    const content = parseContent(plan.content);

    // Goal filters
    const [goalStatusFilter, setGoalStatusFilter] = useState(ANY);
    const [goalPriorityFilter, setGoalPriorityFilter] = useState(ANY);
    const [goalCategoryFilter, setGoalCategoryFilter] = useState(ANY);

    // Note filters
    const [noteVisibilityFilter, setNoteVisibilityFilter] = useState(ANY);

    // Goal form
    const [showGoalForm, setShowGoalForm] = useState(false);
    const [editingGoal, setEditingGoal] = useState<Goal | null>(null);
    const [editProgress, setEditProgress] = useState(0);
    const [editNotes, setEditNotes] = useState('');
    const [editStatus, setEditStatus] = useState('');
    const [editTitle, setEditTitle] = useState('');
    const [editDescription, setEditDescription] = useState('');

    // Quick note for a goal
    const [noteGoalId, setNoteGoalId] = useState<number | null>(null);
    const [quickNote, setQuickNote] = useState('');
    const [expandedGoalNotes, setExpandedGoalNotes] = useState<Record<number, boolean>>({});

    const submitQuickNote = () => {
        if (!noteGoalId || !quickNote.trim()) return;
        router.post('/operations/progress-notes', {
            client_id: plan.client?.id,
            care_plan_goal_id: noteGoalId,
            content: quickNote,
            note_type: 'goal_update',
            visibility: 'staff_only',
        }, {
            preserveScroll: true,
            onSuccess: () => { setQuickNote(''); setNoteGoalId(null); },
        });
    };

    const toggleGoalNotes = (goalId: number) => {
        setExpandedGoalNotes(prev => ({ ...prev, [goalId]: !prev[goalId] }));
    };

    const getGoalNotes = (goalId: number) => {
        return (progressNotes ?? []).filter((n: any) => n.care_plan_goal_id === goalId);
    };

    const openGoalEditor = (goal: Goal) => {
        setEditingGoal(goal);
        setEditProgress(goal.progress_percentage);
        setEditNotes(goal.outcome_notes ?? '');
        setEditStatus(goal.status);
        setEditTitle(goal.title);
        setEditDescription(goal.description ?? '');
    };

    const saveGoalEdit = () => {
        if (!editingGoal) return;
        // Update goal details (title, description, outcome_notes)
        router.put(`/operations/care-plans/${plan.id}/goals/${editingGoal.id}`, {
            title: editTitle,
            description: editDescription,
            outcome_notes: editNotes,
            status: editStatus,
            progress_percentage: editProgress,
        }, {
            preserveScroll: true,
            onSuccess: () => setEditingGoal(null),
        });
    };
    const goalForm = useForm({
        title: '',
        description: '',
        category: 'health',
        priority: 'medium',
        target_date: '',
    });

    // Note form
    const [showNoteForm, setShowNoteForm] = useState(false);
    const noteForm = useForm({
        content: '',
        note_type: 'progress',
        mood_rating: '',
        visibility: 'team',
    });

    const handleAddGoal = (e: React.FormEvent) => {
        e.preventDefault();
        goalForm.post(`/operations/care-plans/${plan.id}/goals`, {
            preserveScroll: true,
            onSuccess: () => { goalForm.reset(); setShowGoalForm(false); },
        });
    };

    const handleAddNote = (e: React.FormEvent) => {
        e.preventDefault();
        noteForm.post(`/operations/care-plans/${plan.id}/notes`, {
            preserveScroll: true,
            onSuccess: () => { noteForm.reset(); setShowNoteForm(false); },
        });
    };

    const updateGoalProgress = (goalId: number, progress: number, status: string) => {
        router.patch(`/operations/care-plans/${plan.id}/goals/${goalId}/progress`, {
            progress_percentage: progress,
            status,
        }, { preserveScroll: true });
    };

    const handleStartReview = () => {
        router.post(`/operations/care-plans/${plan.id}/start-review`, {}, { preserveScroll: true });
    };

    const handleCompleteReview = () => {
        router.post(`/operations/care-plans/${plan.id}/complete-review`, {}, { preserveScroll: true });
    };

    // Goal status counts for donut
    const goalStatusCounts = {
        achieved: goals.filter((g) => g.status === 'achieved').length,
        in_progress: goals.filter((g) => g.status === 'in_progress').length,
        not_started: goals.filter((g) => g.status === 'not_started').length,
        discontinued: goals.filter((g) => g.status === 'discontinued').length,
    };

    // Filtered goals
    const filteredGoals = goals.filter((g) => {
        if (goalStatusFilter !== ANY && g.status !== goalStatusFilter) return false;
        if (goalPriorityFilter !== ANY && g.priority !== goalPriorityFilter) return false;
        if (goalCategoryFilter !== ANY && g.category !== goalCategoryFilter) return false;
        return true;
    });

    // Filtered notes
    const filteredNotes = (progressNotes ?? []).filter((n) => {
        if (noteVisibilityFilter !== ANY && n.visibility !== noteVisibilityFilter) return false;
        return true;
    });

    // Support needs from content
    const supportNeeds = content.support_needs ?? {};
    const activeNeeds = Object.entries(supportNeeds).filter(([, v]) => v);

    // ---------- Tab 1: Overview ----------
    const overviewTab = (
        <div className="space-y-6">
            {/* Client Info */}
            <div className="grid gap-4 lg:grid-cols-3">
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">Client Information</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p className="text-sm font-semibold">
                            {plan.client?.first_name ?? ''} {plan.client?.last_name ?? ''}
                        </p>
                        {plan.client?.date_of_birth && (
                            <p className="mt-1 text-xs text-muted-foreground">DOB: {formatDate(plan.client.date_of_birth)}</p>
                        )}
                        {plan.creator && (
                            <p className="mt-1 text-xs text-muted-foreground">Created by: {plan.creator.name}</p>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">Goal Completion</CardTitle>
                    </CardHeader>
                    <CardContent className="flex justify-center">
                        <DonutChart
                            segments={[
                                { label: 'Achieved', value: goalStatusCounts.achieved, color: OPS_COLORS.success },
                                { label: 'In Progress', value: goalStatusCounts.in_progress, color: OPS_COLORS.primary },
                                { label: 'Not Started', value: goalStatusCounts.not_started, color: OPS_COLORS.muted },
                                { label: 'Discontinued', value: goalStatusCounts.discontinued, color: OPS_COLORS.danger },
                            ]}
                            centerValue={goals.length}
                            centerLabel="Goals"
                            size={200}
                            strokeWidth={20}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">Key Dates</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-muted-foreground">Start</span>
                            <span className="text-sm font-medium">{formatDate(plan.starts_at)}</span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-muted-foreground">End</span>
                            <span className="text-sm font-medium">{formatDate(plan.ends_at)}</span>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-xs text-muted-foreground">Next Review</span>
                            <div className="text-right">
                                <span className="text-sm font-medium">{formatDate(plan.next_review_at)}</span>
                                {plan.next_review_at && (
                                    <p className={`text-[10px] ${new Date(plan.next_review_at) <= new Date() ? 'font-medium text-status-warning' : 'text-muted-foreground'}`}>
                                        {daysUntil(plan.next_review_at)}
                                    </p>
                                )}
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Quick Stats */}
            <div className="flex flex-wrap gap-2">
                <Badge variant="outline" className="gap-1.5 px-3 py-1">
                    <Target className="h-3 w-3" /> {safeProgressStats.total_goals} Total Goals
                </Badge>
                <Badge variant="outline" className="gap-1.5 border-status-success/30 bg-status-success-bg px-3 py-1 text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success">
                    <CheckCircle2 className="h-3 w-3" /> {safeProgressStats.completed} Completed
                </Badge>
                <Badge variant="outline" className="gap-1.5 border-primary bg-primary/10 px-3 py-1 text-primary dark:border-primary/30 dark:bg-primary/30 dark:text-primary/70">
                    <Play className="h-3 w-3" /> {safeProgressStats.in_progress} In Progress
                </Badge>
                <Badge variant="outline" className="gap-1.5 px-3 py-1">
                    <Circle className="h-3 w-3" /> {safeProgressStats.total_goals - safeProgressStats.completed - safeProgressStats.in_progress} Not Started
                </Badge>
            </div>

            {/* Support Needs Chips */}
            {activeNeeds.length > 0 && (
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-sm font-medium">Support Needs</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {activeNeeds.map(([key]) => (
                                <span
                                    key={key}
                                    className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ${SUPPORT_NEED_COLORS[key] ?? 'bg-muted text-foreground'}`}
                                >
                                    {SUPPORT_NEED_LABELS[key] ?? key.replace(/_/g, ' ')}
                                </span>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            )}

            {/* Content Sections */}
            <div className="grid gap-4 lg:grid-cols-2">
                {content.risk_factors && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Risk Factors</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="whitespace-pre-wrap text-sm">{content.risk_factors}</p>
                        </CardContent>
                    </Card>
                )}
                {content.support_strategies && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Support Strategies</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="whitespace-pre-wrap text-sm">{content.support_strategies}</p>
                        </CardContent>
                    </Card>
                )}
                {content.communication_preferences && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Communication Preferences</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="whitespace-pre-wrap text-sm">{content.communication_preferences}</p>
                        </CardContent>
                    </Card>
                )}
                {content.review_schedule && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">Review Schedule</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm">
                                Frequency: Every {content.review_schedule.frequency_months} month{content.review_schedule.frequency_months !== 1 ? 's' : ''}
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </div>
    );

    // ---------- Tab 2: Goals & Progress ----------
    const goalsTab = (
        <div className="space-y-4">
            {/* Filter Row */}
            <div className="flex flex-wrap items-center gap-2">
                <Select value={goalStatusFilter} onValueChange={setGoalStatusFilter}>
                    <SelectTrigger className="h-8 w-[130px] text-xs">
                        <SelectValue placeholder="Status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ANY}>All Status</SelectItem>
                        <SelectItem value="not_started">Not Started</SelectItem>
                        <SelectItem value="in_progress">In Progress</SelectItem>
                        <SelectItem value="achieved">Achieved</SelectItem>
                        <SelectItem value="discontinued">Discontinued</SelectItem>
                    </SelectContent>
                </Select>
                <Select value={goalPriorityFilter} onValueChange={setGoalPriorityFilter}>
                    <SelectTrigger className="h-8 w-[120px] text-xs">
                        <SelectValue placeholder="Priority" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ANY}>All Priority</SelectItem>
                        <SelectItem value="low">Low</SelectItem>
                        <SelectItem value="medium">Medium</SelectItem>
                        <SelectItem value="high">High</SelectItem>
                    </SelectContent>
                </Select>
                <Select value={goalCategoryFilter} onValueChange={setGoalCategoryFilter}>
                    <SelectTrigger className="h-8 w-[130px] text-xs">
                        <SelectValue placeholder="Category" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ANY}>All Categories</SelectItem>
                        {['health', 'social', 'independence', 'skills', 'wellbeing'].map((c) => (
                            <SelectItem key={c} value={c} className="capitalize">{c}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <div className="ml-auto">
                    <Button size="sm" variant="outline" onClick={() => setShowGoalForm(!showGoalForm)}>
                        <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Goal
                    </Button>
                </div>
            </div>

            {/* Add Goal Form */}
            {showGoalForm && (
                <Card className="border-dashed border-primary bg-primary/10/50 dark:border-primary/30 dark:bg-primary/20">
                    <CardContent className="p-4">
                        <form onSubmit={handleAddGoal} className="space-y-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <Label className="text-xs">Goal Title *</Label>
                                    <Input
                                        value={goalForm.data.title}
                                        onChange={(e) => goalForm.setData('title', e.target.value)}
                                        placeholder="e.g. Improve social participation"
                                        className="h-8 text-sm"
                                    />
                                </div>
                                <div className="grid grid-cols-3 gap-2">
                                    <div className="space-y-1">
                                        <Label className="text-xs">Category</Label>
                                        <Select value={goalForm.data.category} onValueChange={(v) => goalForm.setData('category', v)}>
                                            <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {['health', 'social', 'independence', 'skills', 'wellbeing'].map((c) => (
                                                    <SelectItem key={c} value={c} className="capitalize">{c}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">Priority</Label>
                                        <Select value={goalForm.data.priority} onValueChange={(v) => goalForm.setData('priority', v)}>
                                            <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                            <SelectContent>
                                                {['low', 'medium', 'high'].map((p) => (
                                                    <SelectItem key={p} value={p} className="capitalize">{p}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="space-y-1">
                                        <Label className="text-xs">Target Date</Label>
                                        <Input type="date" value={goalForm.data.target_date} onChange={(e) => goalForm.setData('target_date', e.target.value)} className="h-8 text-xs" />
                                    </div>
                                </div>
                            </div>
                            <div className="space-y-1">
                                <Label className="text-xs">Description</Label>
                                <Textarea value={goalForm.data.description} onChange={(e) => goalForm.setData('description', e.target.value)} rows={2} className="text-sm" />
                            </div>
                            <div className="flex gap-2">
                                <Button type="submit" size="sm" disabled={goalForm.processing}>Add Goal</Button>
                                <Button type="button" size="sm" variant="ghost" onClick={() => setShowGoalForm(false)}>Cancel</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}

            {/* Goals List */}
            <div className="space-y-2">
                {filteredGoals.length === 0 && (
                    <Card>
                        <CardContent className="py-8 text-center">
                            <Target className="mx-auto mb-2 h-8 w-8 text-muted-foreground/30" />
                            <p className="text-sm text-muted-foreground">No goals match the current filters.</p>
                        </CardContent>
                    </Card>
                )}
                {filteredGoals.map((goal) => (
                    <Card key={goal.id} className="transition-all hover:shadow-sm">
                        <CardContent className="p-4">
                            <div className="flex items-start gap-3">
                                <div className="mt-0.5">
                                    {goal.status === 'completed' ? (
                                        <CheckCircle2 className="h-5 w-5 text-status-success" />
                                    ) : goal.status === 'in_progress' ? (
                                        <Circle className="h-5 w-5 text-primary" />
                                    ) : (
                                        <Circle className="h-5 w-5 text-muted-foreground/40" />
                                    )}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-medium">{goal.title}</span>
                                        {goal.status !== 'completed' && (
                                            <Button variant="outline" size="sm" className="h-6 gap-1 px-2 text-[10px] text-primary border-primary hover:bg-primary/10"
                                                onClick={() => openGoalEditor(goal)} title="Edit goal">
                                                <Pencil className="h-3 w-3" />
                                                Edit
                                            </Button>
                                        )}
                                        <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">{goal.category}</Badge>
                                        <span className={`text-[10px] font-medium capitalize ${PRIORITY_COLORS[goal.priority] ?? ''}`}>
                                            {goal.priority}
                                        </span>
                                    </div>
                                    {goal.description && (
                                        <p className="mt-0.5 text-xs text-muted-foreground">{goal.description}</p>
                                    )}
                                    {goal.outcome_notes && (
                                        <div className="mt-1.5 rounded-md border border-status-warning/30 bg-status-warning-bg px-2.5 py-1.5">
                                            <p className="text-[10px] font-medium uppercase tracking-wide text-status-warning">Notes</p>
                                            <p className="mt-0.5 text-xs text-status-warning">{goal.outcome_notes}</p>
                                        </div>
                                    )}
                                    {/* Progress bar + slider */}
                                    <div className="mt-2 flex items-center gap-3">
                                        <div className="relative h-2 flex-1 cursor-pointer rounded-full bg-muted"
                                            onClick={(e) => {
                                                if (goal.status === 'completed') return;
                                                const rect = e.currentTarget.getBoundingClientRect();
                                                const pct = Math.min(100, Math.max(0, Math.round(((e.clientX - rect.left) / rect.width) * 100)));
                                                updateGoalProgress(goal.id, pct, pct >= 100 ? 'completed' : pct > 0 ? 'in_progress' : 'not_started');
                                            }}
                                            title={goal.status !== 'completed' ? 'Click to set progress' : ''}
                                        >
                                            <div
                                                className="h-2 rounded-full transition-all"
                                                style={{
                                                    width: `${goal.progress_percentage}%`,
                                                    backgroundColor: GOAL_STATUS_COLORS[goal.status] ?? OPS_COLORS.muted,
                                                }}
                                            />
                                        </div>
                                        <span className="w-10 text-right text-xs font-semibold tabular-nums">{goal.progress_percentage}%</span>
                                    </div>
                                    {/* Actions row */}
                                    <div className="mt-2.5 flex items-center gap-2">
                                        {goal.status !== 'completed' && (
                                            <Button size="sm" className="h-7 gap-1 bg-status-success px-3 text-xs text-white hover:bg-status-success"
                                                onClick={() => updateGoalProgress(goal.id, 100, 'completed')}>
                                                <CheckCircle2 className="h-3.5 w-3.5" />
                                                Mark Achieved
                                            </Button>
                                        )}
                                        {goal.status === 'not_started' && (
                                            <Button size="sm" variant="outline" className="h-7 gap-1 border-primary px-3 text-xs text-primary hover:bg-primary/10"
                                                onClick={() => updateGoalProgress(goal.id, 10, 'in_progress')}>
                                                Start
                                            </Button>
                                        )}
                                        {goal.status === 'completed' && (
                                            <span className="flex items-center gap-1 text-xs font-medium text-status-success">
                                                <CheckCircle2 className="h-4 w-4" />
                                                Completed
                                            </span>
                                        )}
                                        {/* Add Note button (hidden when completed) */}
                                        {goal.status !== 'completed' && (
                                            <Button size="sm" variant="outline" className="h-7 gap-1 px-3 text-xs"
                                                onClick={() => { setNoteGoalId(goal.id); setQuickNote(''); }}>
                                                <MessageSquare className="h-3.5 w-3.5" />
                                                Add Note
                                            </Button>
                                        )}
                                        {/* Show/Hide Notes History */}
                                        {getGoalNotes(goal.id).length > 0 && (
                                            <Button size="sm" variant="ghost" className="h-7 gap-1 px-2 text-xs text-muted-foreground"
                                                onClick={() => toggleGoalNotes(goal.id)}>
                                                <Clock className="h-3 w-3" />
                                                {expandedGoalNotes[goal.id] ? 'Hide' : 'Show'} Notes ({getGoalNotes(goal.id).length})
                                            </Button>
                                        )}
                                        {goal.target_date && (
                                            <span className="ml-auto text-[11px] text-muted-foreground">
                                                Target: {formatDate(goal.target_date)}
                                            </span>
                                        )}
                                    </div>

                                    {/* Quick Note Input (inline) */}
                                    {noteGoalId === goal.id && (
                                        <div className="mt-2 rounded-lg border border-primary bg-primary/10/30 p-3">
                                            <Textarea
                                                className="min-h-[60px] bg-background text-sm"
                                                value={quickNote}
                                                onChange={(e) => setQuickNote(e.target.value)}
                                                placeholder="Add a progress note for this goal..."
                                                autoFocus
                                            />
                                            <div className="mt-2 flex justify-end gap-2">
                                                <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={() => setNoteGoalId(null)}>Cancel</Button>
                                                <Button size="sm" className="h-7 text-xs" onClick={submitQuickNote} disabled={!quickNote.trim()}>Save Note</Button>
                                            </div>
                                        </div>
                                    )}

                                    {/* Notes History (expandable) */}
                                    {expandedGoalNotes[goal.id] && getGoalNotes(goal.id).length > 0 && (
                                        <div className="mt-2 space-y-1.5 rounded-lg border bg-muted/50 p-2.5">
                                            <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Note History</p>
                                            {getGoalNotes(goal.id).map((note: any) => (
                                                <div key={note.id} className="rounded border bg-card p-2 text-xs">
                                                    <div className="flex items-center justify-between">
                                                        <span className="font-medium">{note.author?.name ?? 'Unknown'}</span>
                                                        <span className="text-[10px] text-muted-foreground">{formatDate(note.created_at)}</span>
                                                    </div>
                                                    <p className="mt-0.5 text-muted-foreground">{note.content}</p>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>

            {/* Edit Goal Dialog */}
            <Dialog open={!!editingGoal} onOpenChange={(open) => !open && setEditingGoal(null)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Edit Goal</DialogTitle>
                        <DialogDescription>Update progress, add notes, and modify goal details.</DialogDescription>
                    </DialogHeader>
                    {editingGoal && (
                        <div className="space-y-5 py-2">
                            <div className="space-y-1.5">
                                <Label className="text-sm font-medium">Goal Title</Label>
                                <Input value={editTitle} onChange={(e) => setEditTitle(e.target.value)} />
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-sm font-medium">Description</Label>
                                <Textarea className="min-h-[60px]" value={editDescription} onChange={(e) => setEditDescription(e.target.value)} placeholder="Describe the goal..." />
                            </div>

                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <Label className="text-sm font-medium">Progress</Label>
                                    <span className="text-lg font-bold tabular-nums text-primary">{editProgress}%</span>
                                </div>
                                <input
                                    type="range" min={0} max={100} step={5} value={editProgress}
                                    onChange={(e) => {
                                        const val = Number(e.target.value);
                                        setEditProgress(val);
                                        if (val >= 100) setEditStatus('completed');
                                        else if (val > 0) setEditStatus('in_progress');
                                        else setEditStatus('not_started');
                                    }}
                                    className="h-2 w-full cursor-pointer appearance-none rounded-full bg-muted accent-indigo-600 [&::-webkit-slider-thumb]:h-5 [&::-webkit-slider-thumb]:w-5 [&::-webkit-slider-thumb]:appearance-none [&::-webkit-slider-thumb]:rounded-full [&::-webkit-slider-thumb]:bg-primary [&::-webkit-slider-thumb]:shadow"
                                />
                                <div className="flex justify-between text-[10px] text-muted-foreground">
                                    <span>Not Started</span>
                                    <span>In Progress</span>
                                    <span>Completed</span>
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-sm font-medium">Status</Label>
                                <Select value={editStatus} onValueChange={setEditStatus}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="not_started">Not Started</SelectItem>
                                        <SelectItem value="in_progress">In Progress</SelectItem>
                                        <SelectItem value="completed">Completed</SelectItem>
                                        <SelectItem value="on_hold">On Hold</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="text-sm font-medium">Outcome Notes</Label>
                                <Textarea className="min-h-[80px]" value={editNotes} onChange={(e) => setEditNotes(e.target.value)} placeholder="Add notes about progress, observations, or outcomes..." />
                            </div>
                        </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setEditingGoal(null)}>Cancel</Button>
                        <Button onClick={saveGoalEdit}>Save Changes</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );

    // ---------- Tab 3: Notes & Activity ----------
    const notesTab = (
        <div className="space-y-4">
            {/* Filter Row */}
            <div className="flex flex-wrap items-center gap-2">
                <Select value={noteVisibilityFilter} onValueChange={setNoteVisibilityFilter}>
                    <SelectTrigger className="h-8 w-[140px] text-xs">
                        <SelectValue placeholder="Visibility" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ANY}>All Visibility</SelectItem>
                        <SelectItem value="team">Team</SelectItem>
                        <SelectItem value="private">Private</SelectItem>
                        <SelectItem value="client">Client Visible</SelectItem>
                    </SelectContent>
                </Select>
                <div className="ml-auto">
                    <Button size="sm" variant="outline" onClick={() => setShowNoteForm(!showNoteForm)}>
                        <Plus className="mr-1.5 h-3.5 w-3.5" /> Add Note
                    </Button>
                </div>
            </div>

            {/* Add Note Form */}
            {showNoteForm && (
                <Card className="border-dashed border-primary bg-primary/10/50 dark:border-primary/30 dark:bg-primary/20">
                    <CardContent className="p-4">
                        <form onSubmit={handleAddNote} className="space-y-3">
                            <div className="space-y-1">
                                <Label className="text-xs">Note Content *</Label>
                                <Textarea
                                    value={noteForm.data.content}
                                    onChange={(e) => noteForm.setData('content', e.target.value)}
                                    placeholder="Write your progress note..."
                                    rows={3}
                                    className="text-sm"
                                />
                            </div>
                            <div className="grid grid-cols-3 gap-3">
                                <div className="space-y-1">
                                    <Label className="text-xs">Note Type</Label>
                                    <Select value={noteForm.data.note_type} onValueChange={(v) => noteForm.setData('note_type', v)}>
                                        <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="progress">Progress</SelectItem>
                                            <SelectItem value="observation">Observation</SelectItem>
                                            <SelectItem value="incident">Incident</SelectItem>
                                            <SelectItem value="review">Review</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Mood Rating (1-10)</Label>
                                    <Select value={noteForm.data.mood_rating} onValueChange={(v) => noteForm.setData('mood_rating', v)}>
                                        <SelectTrigger className="h-8 text-xs"><SelectValue placeholder="Select..." /></SelectTrigger>
                                        <SelectContent>
                                            {Array.from({ length: 10 }, (_, i) => i + 1).map((n) => (
                                                <SelectItem key={n} value={String(n)}>{n}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1">
                                    <Label className="text-xs">Visibility</Label>
                                    <Select value={noteForm.data.visibility} onValueChange={(v) => noteForm.setData('visibility', v)}>
                                        <SelectTrigger className="h-8 text-xs"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="team">Team</SelectItem>
                                            <SelectItem value="private">Private</SelectItem>
                                            <SelectItem value="client">Client Visible</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <Button type="submit" size="sm" disabled={noteForm.processing}>Add Note</Button>
                                <Button type="button" size="sm" variant="ghost" onClick={() => setShowNoteForm(false)}>Cancel</Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            )}

            {/* Notes List */}
            <div className="space-y-2">
                {filteredNotes.length === 0 && (
                    <Card>
                        <CardContent className="py-8 text-center">
                            <MessageSquare className="mx-auto mb-2 h-8 w-8 text-muted-foreground/30" />
                            <p className="text-sm text-muted-foreground">No progress notes yet.</p>
                        </CardContent>
                    </Card>
                )}
                {filteredNotes.map((note) => (
                    <Card key={note.id}>
                        <CardContent className="p-4">
                            <div className="flex items-start justify-between">
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-medium">{note.user?.name ?? 'Unknown'}</span>
                                    <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">{note.note_type}</Badge>
                                    <Badge variant="outline" className="h-4 px-1.5 text-[9px] capitalize">{note.visibility}</Badge>
                                    {note.mood_rating != null && (
                                        <span className="text-[10px] text-muted-foreground">Mood: {note.mood_rating}/10</span>
                                    )}
                                </div>
                                <span className="text-xs text-muted-foreground">{formatDate(note.created_at)}</span>
                            </div>
                            <p className="mt-2 whitespace-pre-wrap text-sm">{note.content}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </div>
    );

    // ---------- Tab 4: Review History ----------
    const reviewTab = (
        <div className="space-y-4">
            {/* Review Actions */}
            <div className="flex items-center gap-2">
                {plan.status === 'active' && (
                    <Button size="sm" variant="outline" onClick={handleStartReview} className="gap-1.5">
                        <Play className="h-3.5 w-3.5" />
                        Start Review
                    </Button>
                )}
                {plan.status === 'review' && (
                    <Button size="sm" onClick={handleCompleteReview} className="gap-1.5">
                        <CheckCircle2 className="h-3.5 w-3.5" />
                        Complete Review
                    </Button>
                )}
            </div>

            {/* Review History List */}
            <div className="space-y-2">
                {(reviewHistory ?? []).length === 0 && (
                    <Card>
                        <CardContent className="py-8 text-center">
                            <FileText className="mx-auto mb-2 h-8 w-8 text-muted-foreground/30" />
                            <p className="text-sm text-muted-foreground">No review history available.</p>
                        </CardContent>
                    </Card>
                )}
                {(reviewHistory ?? []).map((version) => (
                    <Card key={version.id}>
                        <CardContent className="flex items-center gap-4 p-4">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground dark:bg-muted dark:text-muted-foreground">
                                <span className="text-sm font-bold">v{version.version}</span>
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-medium">Version {version.version}</span>
                                    <Badge variant={STATUS_VARIANTS[version.status] ?? 'outline'} className="h-4 px-1.5 text-[9px] capitalize">
                                        {version.status}
                                    </Badge>
                                </div>
                                <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                    {version.reviewer && <span>Reviewed by {version.reviewer.name}</span>}
                                    {version.reviewed_at && (
                                        <span className="flex items-center gap-1">
                                            <Clock className="h-3 w-3" />
                                            {formatDate(version.reviewed_at)}
                                        </span>
                                    )}
                                    <span>Created {formatDate(version.created_at)}</span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                ))}
            </div>
        </div>
    );

    return (
        <AppLayout>
            <Head title={plan.title ?? 'Care Plan'} />
            <PageHeader
                title={plan.title ?? 'Care Plan'}
                description={`${plan.client?.first_name ?? ''} ${plan.client?.last_name ?? ''} — Version ${plan.version ?? 1}`}
                backHref="/operations/care-plans"
            />
            <PageShell>
                {/* Header info bar */}
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={STATUS_VARIANTS[plan.status] ?? 'outline'} className="capitalize">
                        {plan.status ?? 'unknown'}
                    </Badge>
                    <Badge variant="outline">{(plan.plan_type ?? '').replace(/_/g, ' ')}</Badge>
                    {plan.starts_at && (
                        <span className="flex items-center gap-1 text-xs text-muted-foreground">
                            <CalendarDays className="h-3 w-3" />
                            {formatDate(plan.starts_at)} — {formatDate(plan.ends_at)}
                        </span>
                    )}
                    {plan.next_review_at && (
                        <span className={`text-xs ${new Date(plan.next_review_at) <= new Date() ? 'font-medium text-status-warning' : 'text-muted-foreground'}`}>
                            Next review: {formatDate(plan.next_review_at)}
                        </span>
                    )}
                    <div className="ml-auto flex gap-1">
                        <Button asChild size="sm" variant="outline">
                            <Link href={`/operations/care-plans/${plan.id}/edit`}>
                                <Pencil className="mr-1.5 h-3.5 w-3.5" /> Edit
                            </Link>
                        </Button>
                        {plan.status === 'active' && (
                            <Button size="sm" variant="outline" onClick={handleStartReview} className="gap-1.5">
                                <Play className="h-3.5 w-3.5" /> Start Review
                            </Button>
                        )}
                        {plan.status === 'review' && (
                            <Button size="sm" onClick={handleCompleteReview} className="gap-1.5">
                                <CheckCircle2 className="h-3.5 w-3.5" /> Complete Review
                            </Button>
                        )}
                    </div>
                </div>

                {/* Tabbed Content */}
                <div className="mt-6">
                    <Tabs
                        tabs={[
                            {
                                key: 'overview',
                                label: 'Overview',
                                icon: <FileText className="h-4 w-4" />,
                                content: overviewTab,
                            },
                            {
                                key: 'goals',
                                label: `Goals (${goals.length})`,
                                icon: <Target className="h-4 w-4" />,
                                content: goalsTab,
                            },
                            {
                                key: 'notes',
                                label: `Notes (${(progressNotes ?? []).length})`,
                                icon: <MessageSquare className="h-4 w-4" />,
                                content: notesTab,
                            },
                            {
                                key: 'reviews',
                                label: `Reviews (${(reviewHistory ?? []).length})`,
                                icon: <Clock className="h-4 w-4" />,
                                content: reviewTab,
                            },
                        ]}
                        persistKey="care-plan-show"
                    />
                </div>
            </PageShell>
        </AppLayout>
    );
}
