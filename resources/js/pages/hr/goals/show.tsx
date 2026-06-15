import {
    GoalDialog,
    type GoalOption,
    type ParentGoalOption,
} from '@/components/hr/performance/goal-dialog';
import { PageHero, type PageHeroBadge, type PageHeroMetaItem } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
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
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { ConfirmAction } from '@/pages/sites/_confirm-action';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    BarChart3,
    Calendar,
    ChevronUp,
    History,
    ListChecks,
    Pencil,
    Plus,
    Sprout,
    Target,
    Trash2,
    TrendingUp,
    User,
} from 'lucide-react';
import { FormEvent, useState } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

interface KeyResult {
    id: number;
    title: string;
    target_value: number;
    current_value: number;
    unit: string | null;
    progress_percentage: number;
    status: string;
    due_date: string;
    owner: { id: number; name: string } | null;
}

interface ChildGoal {
    id: number;
    title: string;
    goal_type: string;
    status: string;
    priority: string;
    progress_percentage: number;
    user: { name: string } | null;
    key_results_count: number;
}

interface GoalUpdate {
    id: number;
    user_name: string;
    previous_value: string | null;
    new_value: string | null;
    progress_percentage: number;
    comment: string | null;
    created_at: string;
}

interface DevelopmentPlan {
    id: number;
    title: string;
    status: string;
    progress_percent: number;
    employee: string | null;
}

interface Goal {
    id: number;
    title: string;
    description: string | null;
    goal_type: string;
    category: string | null;
    status: string;
    priority: string;
    progress_percentage: number;
    target_value: number | null;
    current_value: number | null;
    unit: string | null;
    start_date: string;
    due_date: string;
    completed_at: string | null;
    user: { id: number; name: string } | null;
    creator: string | null;
    parent_goal_id: number | null;
    parent_goal: { id: number; title: string; goal_type: string } | null;
    child_goals: ChildGoal[];
    key_results: KeyResult[];
    updates: GoalUpdate[];
    development_goals: DevelopmentPlan[];
}

interface UserItem {
    id: number;
    name: string;
}

interface Props {
    goal: Goal;
    users: UserItem[];
    goalTypes: GoalOption[];
    priorities: GoalOption[];
    parentGoals: ParentGoalOption[];
    can: { manage: boolean; updateProgress: boolean };
}

/* ------------------------------------------------------------------ */
/*  Colour helpers                                                     */
/* ------------------------------------------------------------------ */

const statusColours: Record<string, string> = {
    not_started: 'bg-muted text-foreground border-border',
    in_progress: 'bg-status-info-bg text-status-info border-status-info/30',
    completed:
        'bg-status-success-bg text-status-success border-status-success/30',
    cancelled:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
};

const statusBadgeWhite: Record<string, string> = {
    not_started: 'bg-primary-foreground/10 text-primary-foreground/90 border-primary-foreground/20',
    in_progress: 'bg-primary-foreground/10 text-primary-foreground/90 border-primary-foreground/20',
    completed: 'bg-status-success text-primary-foreground border-status-success/30',
    cancelled: 'bg-status-critical text-primary-foreground border-status-critical/30',
};

const priorityColours: Record<string, string> = {
    low: 'bg-muted text-foreground',
    medium: 'bg-status-warning-bg text-status-warning',
    high: 'bg-status-critical-bg text-status-critical',
    critical: 'bg-status-critical-bg text-status-critical',
};

const priorityBadgeWhite: Record<string, string> = {
    low: 'bg-primary-foreground/10 text-primary-foreground/80 border-primary-foreground/20',
    medium: 'bg-status-warning text-primary-foreground border-status-warning/30',
    high: 'bg-status-critical text-primary-foreground border-status-critical/30',
    critical: 'bg-status-critical text-primary-foreground border-status-critical/40',
};

const typeBadgeWhite: Record<string, string> = {
    individual: 'bg-primary-foreground/10 text-primary-foreground/90 border-primary-foreground/20',
    team: 'bg-primary-foreground/10 text-primary-foreground/90 border-primary-foreground/20',
    company: 'bg-primary-foreground/10 text-primary-foreground/90 border-primary-foreground/20',
    department: 'bg-primary-foreground/10 text-primary-foreground/90 border-primary-foreground/20',
};

function progressBarColour(pct: number): string {
    if (pct > 66) return 'bg-status-success';
    if (pct >= 33) return 'bg-status-warning';
    return 'bg-status-critical';
}

function krStatusColour(status: string): string {
    switch (status) {
        case 'completed':
            return 'bg-status-success';
        case 'in_progress':
            return 'bg-status-info';
        case 'at_risk':
            return 'bg-status-warning';
        case 'behind':
            return 'bg-status-critical';
        default:
            return 'bg-muted';
    }
}

/* ------------------------------------------------------------------ */
/*  Date formatting (NZ locale)                                        */
/* ------------------------------------------------------------------ */

function formatDate(value?: string | null): string {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });
}

function formatDateTime(value?: string | null): string {
    if (!value) return '-';
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
}

function capitalize(str: string): string {
    return str.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function GoalShow({
    goal,
    users,
    goalTypes,
    priorities,
    parentGoals,
    can,
}: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [addChildOpen, setAddChildOpen] = useState(false);
    const [showKrForm, setShowKrForm] = useState(false);
    const [editingKrId, setEditingKrId] = useState<number | null>(null);
    const [krUpdateValue, setKrUpdateValue] = useState('');
    const [progressOpen, setProgressOpen] = useState(false);

    /* Progress update form */
    const [progressForm, setProgressForm] = useState({
        current_value: '',
        progress_percentage: String(goal.progress_percentage),
        comment: '',
    });

    /* Key Result creation form */
    const krForm = useForm({
        title: '',
        target_value: '',
        unit: '',
        due_date: '',
        owner_id: '',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Goals', href: '/hr/goals' },
        { title: goal.title, href: `/hr/goals/${goal.id}` },
    ];

    /* ---- Actions ---- */

    function submitKeyResult(e: FormEvent) {
        e.preventDefault();
        krForm.post(`/hr/goals/${goal.id}/key-results`, {
            preserveScroll: true,
            onSuccess: () => {
                krForm.reset();
                setShowKrForm(false);
            },
        });
    }

    function updateKeyResultValue(krId: number) {
        router.put(
            `/hr/goals/key-results/${krId}`,
            {
                current_value: krUpdateValue,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingKrId(null);
                    setKrUpdateValue('');
                },
            },
        );
    }

    function deleteKeyResult(krId: number) {
        if (confirm('Are you sure you want to delete this key result?')) {
            router.delete(`/hr/goals/key-results/${krId}`, {
                preserveScroll: true,
            });
        }
    }

    function deleteGoal() {
        router.delete(`/hr/goals/${goal.id}`);
    }

    function submitProgress(e: FormEvent) {
        e.preventDefault();
        router.post(
            `/hr/goals/${goal.id}/progress`,
            {
                current_value: progressForm.current_value || null,
                progress_percentage: progressForm.progress_percentage,
                comment: progressForm.comment || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setProgressOpen(false);
                    setProgressForm({
                        current_value: '',
                        progress_percentage: String(goal.progress_percentage),
                        comment: '',
                    });
                },
            },
        );
    }

    /* ---- Chart data ---- */
    const chartData = (goal.updates ?? [])
        .slice()
        .reverse()
        .map((u) => ({
            date: formatDate(u.created_at),
            progress: u.progress_percentage,
        }));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={goal.title} />

            <div className="flex flex-col gap-6 p-6">
                {/* ============================================================ */}
                {/*  PAGE HERO                                                    */}
                {/* ============================================================ */}
                {(() => {
                    const statusTone: PageHeroBadge['tone'] =
                        goal.status === 'completed'
                            ? 'success'
                            : goal.status === 'cancelled'
                              ? 'critical'
                              : 'default';
                    const priorityTone: PageHeroBadge['tone'] =
                        goal.priority === 'high' || goal.priority === 'critical'
                            ? 'critical'
                            : goal.priority === 'medium'
                              ? 'warning'
                              : 'default';

                    const heroBadges: PageHeroBadge[] = [
                        { label: capitalize(goal.status), tone: statusTone },
                        {
                            label: `${capitalize(goal.priority)} Priority`,
                            tone: priorityTone,
                        },
                        { label: capitalize(goal.goal_type) },
                    ];

                    const heroMeta: PageHeroMetaItem[] = [];
                    if (goal.user) heroMeta.push({ icon: User, label: goal.user.name });
                    heroMeta.push({
                        icon: Calendar,
                        label: `${formatDate(goal.start_date)} – ${formatDate(goal.due_date)}`,
                    });
                    if (goal.category)
                        heroMeta.push({ icon: Target, label: goal.category });
                    if (goal.parent_goal)
                        heroMeta.push({
                            label: `Parent: ${goal.parent_goal.title}`,
                            href: `/hr/goals/${goal.parent_goal.id}`,
                        });

                    const heroStats: { label: string; value: string }[] = [
                        { label: 'Progress', value: `${goal.progress_percentage}%` },
                    ];
                    if (goal.target_value !== null && goal.target_value !== undefined) {
                        heroStats.push({
                            label: goal.unit ?? 'Target',
                            value: `${goal.current_value ?? 0}/${goal.target_value}`,
                        });
                    }

                    return (
                        <PageHero category="hr"
                            icon={Target}
                            backHref="/hr/goals"
                            title={goal.title}
                            description={goal.description ?? undefined}
                            meta={heroMeta}
                            badges={heroBadges}
                            stats={heroStats}
                            actions={
                                can.manage ? (
                                    <div className="flex items-center gap-2">
                                        <Button
                                            size="sm"
                                            onClick={() => setEditOpen(true)}
                                        >
                                            <Pencil className="mr-1.5 h-4 w-4" />
                                            Edit goal
                                        </Button>
                                        <ConfirmAction
                                            title="Delete objective?"
                                            description={`Delete "${goal.title}"? This removes it from the goals list. Key results and history are kept and the objective can be restored by an administrator.`}
                                            confirmLabel="Delete objective"
                                            onConfirm={deleteGoal}
                                        >
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                                            >
                                                <Trash2 className="mr-1.5 h-4 w-4" />
                                                Delete
                                            </Button>
                                        </ConfirmAction>
                                    </div>
                                ) : undefined
                            }
                        />
                    );
                })()}

                {/* ============================================================ */}
                {/*  TABS                                                         */}
                {/* ============================================================ */}
                <Tabs defaultValue="key-results">
                    <TabsList>
                        <TabsTrigger value="key-results" className="gap-1.5">
                            <ListChecks className="h-4 w-4" />
                            Key Results
                        </TabsTrigger>
                        <TabsTrigger value="child-goals" className="gap-1.5">
                            <Target className="h-4 w-4" />
                            Child Goals
                        </TabsTrigger>
                        <TabsTrigger value="history" className="gap-1.5">
                            <History className="h-4 w-4" />
                            Progress History
                        </TabsTrigger>
                        <TabsTrigger value="dev-plans" className="gap-1.5">
                            <Sprout className="h-4 w-4" />
                            Development Plans
                        </TabsTrigger>
                    </TabsList>

                    {/* -------------------------------------------------------- */}
                    {/*  TAB: Key Results                                         */}
                    {/* -------------------------------------------------------- */}
                    <TabsContent value="key-results">
                        <div className="space-y-4">
                            {/* Add Key Result button */}
                            {can.manage && (
                                <div className="flex justify-end">
                                    <Button
                                        size="sm"
                                        variant={
                                            showKrForm ? 'secondary' : 'default'
                                        }
                                        onClick={() => setShowKrForm((p) => !p)}
                                    >
                                        {showKrForm ? (
                                            <>
                                                <ChevronUp className="mr-1.5 h-4 w-4" />
                                                Cancel
                                            </>
                                        ) : (
                                            <>
                                                <Plus className="mr-1.5 h-4 w-4" />
                                                Add Key Result
                                            </>
                                        )}
                                    </Button>
                                </div>
                            )}

                            {/* Add Key Result form (collapsible) */}
                            {showKrForm && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            New Key Result
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <form
                                            onSubmit={submitKeyResult}
                                            className="grid grid-cols-1 gap-4 sm:grid-cols-2"
                                        >
                                            <div className="sm:col-span-2">
                                                <Label htmlFor="kr-title">
                                                    Title
                                                </Label>
                                                <Input
                                                    id="kr-title"
                                                    value={krForm.data.title}
                                                    onChange={(e) =>
                                                        krForm.setData(
                                                            'title',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. Increase customer satisfaction score"
                                                    required
                                                />
                                                {krForm.errors.title && (
                                                    <p className="mt-1 text-xs text-status-critical">
                                                        {krForm.errors.title}
                                                    </p>
                                                )}
                                            </div>
                                            <div>
                                                <Label htmlFor="kr-target">
                                                    Target Value
                                                </Label>
                                                <Input
                                                    id="kr-target"
                                                    type="number"
                                                    step="0.01"
                                                    value={
                                                        krForm.data.target_value
                                                    }
                                                    onChange={(e) =>
                                                        krForm.setData(
                                                            'target_value',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="100"
                                                    required
                                                />
                                                {krForm.errors.target_value && (
                                                    <p className="mt-1 text-xs text-status-critical">
                                                        {
                                                            krForm.errors
                                                                .target_value
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                            <div>
                                                <Label htmlFor="kr-unit">
                                                    Unit
                                                </Label>
                                                <Input
                                                    id="kr-unit"
                                                    value={krForm.data.unit}
                                                    onChange={(e) =>
                                                        krForm.setData(
                                                            'unit',
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder="e.g. %, points, hours"
                                                />
                                                {krForm.errors.unit && (
                                                    <p className="mt-1 text-xs text-status-critical">
                                                        {krForm.errors.unit}
                                                    </p>
                                                )}
                                            </div>
                                            <div>
                                                <Label htmlFor="kr-due">
                                                    Due Date
                                                </Label>
                                                <Input
                                                    id="kr-due"
                                                    type="date"
                                                    value={krForm.data.due_date}
                                                    onChange={(e) =>
                                                        krForm.setData(
                                                            'due_date',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                                {krForm.errors.due_date && (
                                                    <p className="mt-1 text-xs text-status-critical">
                                                        {krForm.errors.due_date}
                                                    </p>
                                                )}
                                            </div>
                                            <div>
                                                <Label htmlFor="kr-owner">
                                                    Owner
                                                </Label>
                                                <Select
                                                    value={krForm.data.owner_id}
                                                    onValueChange={(val) =>
                                                        krForm.setData(
                                                            'owner_id',
                                                            val,
                                                        )
                                                    }
                                                >
                                                    <SelectTrigger id="kr-owner">
                                                        <SelectValue placeholder="Select owner" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {users.map((u) => (
                                                            <SelectItem
                                                                key={u.id}
                                                                value={String(
                                                                    u.id,
                                                                )}
                                                            >
                                                                {u.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                                {krForm.errors.owner_id && (
                                                    <p className="mt-1 text-xs text-status-critical">
                                                        {krForm.errors.owner_id}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="flex justify-end gap-2 sm:col-span-2">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setShowKrForm(false)
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                                <Button
                                                    type="submit"
                                                    disabled={krForm.processing}
                                                >
                                                    {krForm.processing
                                                        ? 'Creating...'
                                                        : 'Create Key Result'}
                                                </Button>
                                            </div>
                                        </form>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Key Results list */}
                            {goal.key_results.length > 0 ? (
                                <div className="space-y-3">
                                    {goal.key_results.map((kr) => (
                                        <Card key={kr.id}>
                                            <CardContent className="pt-5">
                                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2">
                                                            <h3 className="text-sm font-semibold">
                                                                {kr.title}
                                                            </h3>
                                                            <Badge
                                                                className={
                                                                    statusColours[
                                                                        kr
                                                                            .status
                                                                    ] ??
                                                                    'bg-muted text-foreground'
                                                                }
                                                            >
                                                                {capitalize(
                                                                    kr.status,
                                                                )}
                                                            </Badge>
                                                        </div>

                                                        {/* Full-width progress bar */}
                                                        <div className="mt-2">
                                                            <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                                                <span>
                                                                    {
                                                                        kr.current_value
                                                                    }{' '}
                                                                    /{' '}
                                                                    {
                                                                        kr.target_value
                                                                    }{' '}
                                                                    {kr.unit ??
                                                                        ''}
                                                                </span>
                                                                <span>
                                                                    {
                                                                        kr.progress_percentage
                                                                    }
                                                                    %
                                                                </span>
                                                            </div>
                                                            <div className="h-2.5 w-full overflow-hidden rounded-full bg-muted">
                                                                <div
                                                                    className={`h-full rounded-full transition-all ${progressBarColour(kr.progress_percentage)}`}
                                                                    style={{
                                                                        width: `${Math.min(kr.progress_percentage, 100)}%`,
                                                                    }}
                                                                />
                                                            </div>
                                                        </div>

                                                        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                                            {kr.owner && (
                                                                <span className="flex items-center gap-1">
                                                                    <User className="h-3 w-3" />
                                                                    {
                                                                        kr.owner
                                                                            .name
                                                                    }
                                                                </span>
                                                            )}
                                                            {kr.due_date && (
                                                                <span className="flex items-center gap-1">
                                                                    <Calendar className="h-3 w-3" />
                                                                    {formatDate(
                                                                        kr.due_date,
                                                                    )}
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Actions */}
                                                    <div className="flex shrink-0 items-center gap-1.5">
                                                        {can.updateProgress && (
                                                            <>
                                                                {editingKrId ===
                                                                kr.id ? (
                                                                    <div className="flex items-center gap-1.5">
                                                                        <Input
                                                                            type="number"
                                                                            step="0.01"
                                                                            className="h-8 w-24 text-xs"
                                                                            value={
                                                                                krUpdateValue
                                                                            }
                                                                            onChange={(
                                                                                e,
                                                                            ) =>
                                                                                setKrUpdateValue(
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                                )
                                                                            }
                                                                            placeholder={String(
                                                                                kr.current_value,
                                                                            )}
                                                                            autoFocus
                                                                        />
                                                                        <Button
                                                                            size="sm"
                                                                            className="h-8 text-xs"
                                                                            onClick={() =>
                                                                                updateKeyResultValue(
                                                                                    kr.id,
                                                                                )
                                                                            }
                                                                            disabled={
                                                                                !krUpdateValue
                                                                            }
                                                                        >
                                                                            Save
                                                                        </Button>
                                                                        <Button
                                                                            size="sm"
                                                                            variant="ghost"
                                                                            className="h-8 text-xs"
                                                                            onClick={() => {
                                                                                setEditingKrId(
                                                                                    null,
                                                                                );
                                                                                setKrUpdateValue(
                                                                                    '',
                                                                                );
                                                                            }}
                                                                        >
                                                                            Cancel
                                                                        </Button>
                                                                    </div>
                                                                ) : (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="outline"
                                                                        className="h-8 text-xs"
                                                                        onClick={() => {
                                                                            setEditingKrId(
                                                                                kr.id,
                                                                            );
                                                                            setKrUpdateValue(
                                                                                String(
                                                                                    kr.current_value,
                                                                                ),
                                                                            );
                                                                        }}
                                                                    >
                                                                        <Pencil className="mr-1 h-3 w-3" />
                                                                        Update
                                                                    </Button>
                                                                )}
                                                            </>
                                                        )}
                                                        {can.manage && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                className="h-8 w-8 p-0 text-status-critical hover:text-status-critical"
                                                                onClick={() =>
                                                                    deleteKeyResult(
                                                                        kr.id,
                                                                    )
                                                                }
                                                            >
                                                                <Trash2 className="h-3.5 w-3.5" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ))}
                                </div>
                            ) : (
                                <Card>
                                    <CardContent className="py-10 text-center">
                                        <ListChecks className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            No key results yet.
                                        </p>
                                        {can.manage && (
                                            <Button
                                                size="sm"
                                                className="mt-3"
                                                onClick={() =>
                                                    setShowKrForm(true)
                                                }
                                            >
                                                <Plus className="mr-1.5 h-4 w-4" />
                                                Add First Key Result
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </TabsContent>

                    {/* -------------------------------------------------------- */}
                    {/*  TAB: Child Goals                                         */}
                    {/* -------------------------------------------------------- */}
                    <TabsContent value="child-goals">
                        <div className="space-y-4">
                            {can.manage && (
                                <div className="flex justify-end">
                                    <Button
                                        size="sm"
                                        onClick={() => setAddChildOpen(true)}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Add Child Goal
                                    </Button>
                                </div>
                            )}

                            {goal.child_goals.length > 0 ? (
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    {goal.child_goals.map((child) => (
                                        <Link
                                            key={child.id}
                                            href={`/hr/goals/${child.id}`}
                                            className="block"
                                        >
                                            <Card className="h-full transition-shadow hover:shadow-md">
                                                <CardContent className="pt-5">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <h3 className="text-sm leading-snug font-semibold">
                                                            {child.title}
                                                        </h3>
                                                        <Badge
                                                            variant="outline"
                                                            className="shrink-0 text-xs capitalize"
                                                        >
                                                            {capitalize(
                                                                child.goal_type,
                                                            )}
                                                        </Badge>
                                                    </div>

                                                    {/* Progress bar */}
                                                    <div className="mt-3">
                                                        <div className="mb-1 flex items-center justify-between text-xs text-muted-foreground">
                                                            <span>
                                                                {
                                                                    child.progress_percentage
                                                                }
                                                                %
                                                            </span>
                                                            <Badge
                                                                className={`px-1.5 py-0 text-[10px] ${statusColours[child.status] ?? 'bg-muted text-foreground'}`}
                                                            >
                                                                {capitalize(
                                                                    child.status,
                                                                )}
                                                            </Badge>
                                                        </div>
                                                        <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                                            <div
                                                                className={`h-full rounded-full transition-all ${progressBarColour(child.progress_percentage)}`}
                                                                style={{
                                                                    width: `${Math.min(child.progress_percentage, 100)}%`,
                                                                }}
                                                            />
                                                        </div>
                                                    </div>

                                                    <div className="mt-3 flex items-center justify-between text-xs text-muted-foreground">
                                                        <span className="flex items-center gap-1">
                                                            <User className="h-3 w-3" />
                                                            {child.user?.name ??
                                                                'Unassigned'}
                                                        </span>
                                                        <span className="flex items-center gap-1">
                                                            <ListChecks className="h-3 w-3" />
                                                            {
                                                                child.key_results_count
                                                            }{' '}
                                                            KR
                                                            {child.key_results_count !==
                                                            1
                                                                ? 's'
                                                                : ''}
                                                        </span>
                                                    </div>

                                                    <div className="mt-2">
                                                        <Badge
                                                            className={`px-1.5 py-0 text-[10px] ${priorityColours[child.priority] ?? 'bg-muted text-foreground'}`}
                                                        >
                                                            {capitalize(
                                                                child.priority,
                                                            )}
                                                        </Badge>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <Card>
                                    <CardContent className="py-10 text-center">
                                        <Target className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            No child goals yet.
                                        </p>
                                        {can.manage && (
                                            <Button
                                                size="sm"
                                                className="mt-3"
                                                onClick={() =>
                                                    setAddChildOpen(true)
                                                }
                                            >
                                                <Plus className="mr-1.5 h-4 w-4" />
                                                Add First Child Goal
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </TabsContent>

                    {/* -------------------------------------------------------- */}
                    {/*  TAB: Development Plans                                    */}
                    {/* -------------------------------------------------------- */}
                    <TabsContent value="dev-plans">
                        <div className="space-y-4">
                            {goal.development_goals.length > 0 ? (
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                                    {goal.development_goals.map((plan) => (
                                        <Link
                                            key={plan.id}
                                            href="/hr/goals/development"
                                            className="block"
                                        >
                                            <Card className="h-full transition-shadow hover:shadow-md">
                                                <CardContent className="pt-5">
                                                    <div className="flex items-start justify-between gap-2">
                                                        <h3 className="text-sm leading-snug font-semibold">
                                                            {plan.title}
                                                        </h3>
                                                        <Badge
                                                            className={`shrink-0 px-1.5 py-0 text-[10px] ${statusColours[plan.status] ?? 'bg-muted text-foreground'}`}
                                                        >
                                                            {capitalize(
                                                                plan.status.replace(
                                                                    '_',
                                                                    ' ',
                                                                ),
                                                            )}
                                                        </Badge>
                                                    </div>
                                                    <div className="mt-3 flex items-center justify-between text-xs text-muted-foreground">
                                                        <span className="flex items-center gap-1">
                                                            <User className="h-3 w-3" />
                                                            {plan.employee ??
                                                                'Unassigned'}
                                                        </span>
                                                        <span>
                                                            {
                                                                plan.progress_percent
                                                            }
                                                            %
                                                        </span>
                                                    </div>
                                                </CardContent>
                                            </Card>
                                        </Link>
                                    ))}
                                </div>
                            ) : (
                                <Card>
                                    <CardContent className="py-10 text-center">
                                        <Sprout className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                        <p className="mt-2 text-sm text-muted-foreground">
                                            No development plans roll up into this
                                            objective yet.
                                        </p>
                                        {can.manage && (
                                            <Button
                                                size="sm"
                                                className="mt-3"
                                                asChild
                                            >
                                                <Link href="/hr/goals/development">
                                                    <Plus className="mr-1.5 h-4 w-4" />
                                                    Manage development plans
                                                </Link>
                                            </Button>
                                        )}
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    </TabsContent>

                    {/* -------------------------------------------------------- */}
                    {/*  TAB: Progress History                                    */}
                    {/* -------------------------------------------------------- */}
                    <TabsContent value="history">
                        <div className="space-y-4">
                            {/* Update Progress button */}
                            {can.updateProgress && (
                                <div className="flex justify-end">
                                    <Button
                                        size="sm"
                                        onClick={() => setProgressOpen(true)}
                                    >
                                        <TrendingUp className="mr-1.5 h-4 w-4" />
                                        Update Progress
                                    </Button>
                                </div>
                            )}

                            {/* Progress Chart */}
                            {chartData.length >= 2 && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <BarChart3 className="h-4 w-4" />
                                            Progress Over Time
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="h-64">
                                            <ResponsiveContainer
                                                width="100%"
                                                height="100%"
                                            >
                                                <LineChart
                                                    data={chartData}
                                                    margin={{
                                                        top: 5,
                                                        right: 20,
                                                        left: 0,
                                                        bottom: 5,
                                                    }}
                                                >
                                                    <CartesianGrid
                                                        strokeDasharray="3 3"
                                                        className="stroke-muted"
                                                    />
                                                    <XAxis
                                                        dataKey="date"
                                                        tick={{ fontSize: 11 }}
                                                        className="text-muted-foreground"
                                                    />
                                                    <YAxis
                                                        domain={[0, 100]}
                                                        tick={{ fontSize: 11 }}
                                                        className="text-muted-foreground"
                                                        tickFormatter={(v) =>
                                                            `${v}%`
                                                        }
                                                    />
                                                    <Tooltip
                                                        formatter={(
                                                            value?: number,
                                                        ) => [
                                                            `${value ?? 0}%`,
                                                            'Progress',
                                                        ]}
                                                        contentStyle={{
                                                            borderRadius: '8px',
                                                            border: '1px solid hsl(var(--border))',
                                                            backgroundColor:
                                                                'hsl(var(--background))',
                                                            fontSize: '12px',
                                                        }}
                                                    />
                                                    <Line
                                                        type="monotone"
                                                        dataKey="progress"
                                                        stroke="hsl(var(--primary))"
                                                        strokeWidth={2}
                                                        dot={{
                                                            r: 4,
                                                            fill: 'hsl(var(--primary))',
                                                        }}
                                                        activeDot={{ r: 6 }}
                                                    />
                                                </LineChart>
                                            </ResponsiveContainer>
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            {/* Timeline */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <History className="h-4 w-4" />
                                        Update Timeline
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {goal.updates.length > 0 ? (
                                        <div className="relative space-y-0">
                                            {goal.updates.map((update, idx) => (
                                                <div
                                                    key={update.id}
                                                    className="relative flex gap-4 pb-6 last:pb-0"
                                                >
                                                    {/* Timeline line */}
                                                    {idx <
                                                        goal.updates.length -
                                                            1 && (
                                                        <div className="absolute top-6 left-[11px] h-full w-0.5 bg-border" />
                                                    )}
                                                    {/* Timeline dot */}
                                                    <div className="relative z-10 mt-1 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 border-primary bg-background">
                                                        <div className="h-2 w-2 rounded-full bg-primary" />
                                                    </div>
                                                    {/* Content */}
                                                    <div className="min-w-0 flex-1 rounded-lg border p-3">
                                                        <div className="flex items-center justify-between gap-2">
                                                            <span className="text-sm font-medium">
                                                                {
                                                                    update.user_name
                                                                }
                                                            </span>
                                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                                {formatDateTime(
                                                                    update.created_at,
                                                                )}
                                                            </span>
                                                        </div>
                                                        <div className="mt-1 flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                                                            <span className="flex items-center gap-1">
                                                                <TrendingUp className="h-3 w-3" />
                                                                {
                                                                    update.progress_percentage
                                                                }
                                                                %
                                                            </span>
                                                            {update.previous_value !==
                                                                null &&
                                                                update.new_value !==
                                                                    null && (
                                                                    <span>
                                                                        Value:{' '}
                                                                        {
                                                                            update.previous_value
                                                                        }{' '}
                                                                        &rarr;{' '}
                                                                        {
                                                                            update.new_value
                                                                        }
                                                                    </span>
                                                                )}
                                                        </div>
                                                        {update.comment && (
                                                            <p className="mt-1.5 text-sm text-foreground/80">
                                                                {update.comment}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="py-8 text-center">
                                            <History className="mx-auto h-10 w-10 text-muted-foreground/40" />
                                            <p className="mt-2 text-sm text-muted-foreground">
                                                No progress updates yet.
                                            </p>
                                            {can.updateProgress && (
                                                <Button
                                                    size="sm"
                                                    className="mt-3"
                                                    onClick={() =>
                                                        setProgressOpen(true)
                                                    }
                                                >
                                                    <TrendingUp className="mr-1.5 h-4 w-4" />
                                                    Record First Update
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>

            {/* ============================================================ */}
            {/*  Progress Update Dialog                                       */}
            {/* ============================================================ */}
            <Dialog open={progressOpen} onOpenChange={setProgressOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Update Progress</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitProgress} className="space-y-4">
                        {goal.target_value !== null &&
                            goal.target_value !== undefined && (
                                <div>
                                    <Label htmlFor="progress-value">
                                        Current Value{' '}
                                        {goal.unit ? `(${goal.unit})` : ''}
                                    </Label>
                                    <Input
                                        id="progress-value"
                                        type="number"
                                        step="0.01"
                                        value={progressForm.current_value}
                                        onChange={(e) =>
                                            setProgressForm((p) => ({
                                                ...p,
                                                current_value: e.target.value,
                                            }))
                                        }
                                        placeholder={`Target: ${goal.target_value}`}
                                    />
                                </div>
                            )}
                        <div>
                            <Label htmlFor="progress-pct">
                                Progress Percentage
                            </Label>
                            <div className="flex items-center gap-3">
                                <input
                                    id="progress-pct"
                                    type="range"
                                    min="0"
                                    max="100"
                                    value={progressForm.progress_percentage}
                                    onChange={(e) =>
                                        setProgressForm((p) => ({
                                            ...p,
                                            progress_percentage: e.target.value,
                                        }))
                                    }
                                    className="h-2 flex-1 cursor-pointer appearance-none rounded-lg bg-muted accent-primary"
                                />
                                <span className="w-12 text-right text-sm font-medium">
                                    {progressForm.progress_percentage}%
                                </span>
                            </div>
                        </div>
                        <div>
                            <Label htmlFor="progress-comment">Comment</Label>
                            <Textarea
                                id="progress-comment"
                                value={progressForm.comment}
                                onChange={(e) =>
                                    setProgressForm((p) => ({
                                        ...p,
                                        comment: e.target.value,
                                    }))
                                }
                                placeholder="Describe what was accomplished..."
                                rows={3}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setProgressOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit">Save Update</Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {can.manage && (
                <GoalDialog
                    open={editOpen}
                    onClose={() => setEditOpen(false)}
                    owners={users}
                    goalTypes={goalTypes}
                    priorities={priorities}
                    parentGoals={parentGoals}
                    goal={{
                        id: goal.id,
                        user: goal.user,
                        title: goal.title,
                        description: goal.description,
                        goal_type: goal.goal_type,
                        category: goal.category,
                        priority: goal.priority,
                        parent_goal_id: goal.parent_goal_id,
                        target_value: goal.target_value,
                        unit: goal.unit,
                        start_date: goal.start_date,
                        due_date: goal.due_date,
                    }}
                />
            )}

            {can.manage && (
                <GoalDialog
                    open={addChildOpen}
                    onClose={() => setAddChildOpen(false)}
                    owners={users}
                    goalTypes={goalTypes}
                    priorities={priorities}
                    parentGoals={parentGoals}
                    prefillParentId={goal.id}
                />
            )}
        </AppLayout>
    );
}
