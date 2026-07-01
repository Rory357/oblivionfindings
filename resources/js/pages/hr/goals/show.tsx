/* eslint-disable no-restricted-syntax -- The detail header, weighted ring, sub-tab
 * strip and KR rows mirror the Goals & OKR design prototype and use styled native
 * controls. Every colour is a semantic design token. */
import {
    Avatar,
    type Confidence,
    type Cycle,
    formatDate,
    type Objective,
    PRIORITY_DOT,
    ProgressBar,
    RAG,
    RagPill,
    STATUS_BADGE,
    TypeBadge,
} from '@/components/hr/goals/okr-shared';
import { CheckinWizard } from '@/components/hr/goals/checkin-wizard';
import { ObjectiveWizard } from '@/components/hr/goals/objective-wizard';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Check,
    CheckCircle2,
    Download,
    ListChecks,
    Pencil,
    Plus,
    Sprout,
    Target,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';

interface DetailKr {
    id: number;
    title: string;
    kr_type: string;
    start_value: number;
    target_value: number;
    current_value: number;
    unit: string | null;
    weight: number;
    progress_percentage: number;
    status: string;
    confidence: Confidence;
    due_date: string | null;
    owner: { id: number; name: string } | null;
}

interface ChildGoal {
    id: number;
    title: string;
    goal_type: 'company' | 'team' | 'individual';
    status: string;
    confidence: Confidence;
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
    confidence: Confidence | null;
    comment: string | null;
    created_at: string;
}

interface LinkedPlan {
    id: number;
    title: string;
    competency_area: string | null;
    status: string;
    progress_percent: number;
    current_level: number | null;
    target_level: number | null;
    employee: string | null;
}

interface Goal {
    id: number;
    title: string;
    description: string | null;
    goal_type: 'company' | 'team' | 'individual';
    category: string | null;
    tags: string[];
    status: string;
    confidence: Confidence;
    priority: string;
    checkin_frequency: string | null;
    progress_percentage: number;
    target_value: number | null;
    current_value: number | null;
    unit: string | null;
    start_date: string;
    due_date: string;
    completed_at: string | null;
    cycle: { id: number; name: string } | null;
    cycle_id: number | null;
    user: { id: number; name: string } | null;
    creator: string | null;
    parent_goal_id: number | null;
    parent_goal: { id: number; title: string; goal_type: string } | null;
    child_goals: ChildGoal[];
    key_results: DetailKr[];
    updates: GoalUpdate[];
    development_goals: LinkedPlan[];
}

interface Props {
    goal: Goal;
    users: Array<{ id: number; name: string }>;
    parentGoals: Array<{ id: number; title: string }>;
    cycles: Cycle[];
    can: { manage: boolean; updateProgress: boolean };
}

type SubTab = 'krs' | 'history' | 'children' | 'plans';

export default function GoalShow({ goal, users, parentGoals, cycles, can }: Props) {
    const [subtab, setSubtab] = useState<SubTab>('krs');
    const [editOpen, setEditOpen] = useState(false);
    const [addChildOpen, setAddChildOpen] = useState(false);
    const [checkinOpen, setCheckinOpen] = useState(false);
    const [krDialog, setKrDialog] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Goals & OKRs', href: '/hr/goals' },
        { title: goal.title, href: `/hr/goals/${goal.id}` },
    ];

    const rag = RAG[goal.confidence];

    // Construct an Objective for the wizards / check-in from the detail payload.
    const asObjective: Objective = {
        ...goal,
        last_checkin_at: null,
        last_checkin_days: null,
        development_count: goal.development_goals.length,
        key_results_count: goal.key_results.length,
        key_results: goal.key_results,
    } as unknown as Objective;

    const markStatus = (status: string) => router.put(`/hr/goals/${goal.id}`, { status }, { preserveScroll: true });
    const deleteKr = (id: number) => {
        if (confirm('Delete this key result?')) router.delete(`/hr/goals/key-results/${id}`, { preserveScroll: true });
    };
    const archive = () => {
        if (confirm(`Archive “${goal.title}”?`)) markStatus('cancelled');
    };
    const exportCsv = () => {
        window.location.href = goal.cycle_id ? `/hr/goals/export?cycle=${goal.cycle_id}` : '/hr/goals/export';
    };

    const subTabs: { key: SubTab; label: string; count: number }[] = [
        { key: 'krs', label: 'Key results', count: goal.key_results.length },
        { key: 'history', label: 'History', count: goal.updates.length },
        { key: 'children', label: 'Children', count: goal.child_goals.length },
        { key: 'plans', label: 'Linked plans', count: goal.development_goals.length },
    ];

    const detailBtn =
        'inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-2 text-[12.5px] font-semibold hover:bg-muted';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={goal.title} />

            <div className="mx-auto flex w-full max-w-[1100px] flex-col gap-4 p-6">
                <Link href="/hr/goals" className="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="h-4 w-4" /> Back to hub
                </Link>

                {goal.parent_goal && (
                    <Link href={`/hr/goals/${goal.parent_goal.id}`} className="-mt-2 text-[11.5px] font-semibold text-primary">
                        ↑ {goal.parent_goal.title}
                    </Link>
                )}

                {/* header card */}
                <div className="rounded-2xl border border-border bg-card p-6 shadow-sm">
                    <div className="flex flex-wrap items-start gap-6">
                        <div
                            className="relative h-24 w-24 shrink-0 rounded-full"
                            style={{ background: `conic-gradient(${ragVar(goal.confidence)} ${goal.progress_percentage}%, var(--muted) 0)` }}
                        >
                            <div className="absolute inset-[9px] grid place-items-center rounded-full bg-card">
                                <span className="text-2xl font-extrabold tabular-nums">
                                    {goal.progress_percentage}
                                    <span className="text-sm">%</span>
                                </span>
                                <span className="text-[8.5px] font-bold uppercase tracking-wide text-muted-foreground">
                                    {goal.key_results.length ? 'Weighted' : 'Manual'}
                                </span>
                            </div>
                        </div>
                        <div className="min-w-0 flex-1">
                            <div className="mb-2 flex flex-wrap items-center gap-2">
                                <TypeBadge type={goal.goal_type} />
                                <span className={cn('inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold capitalize', STATUS_BADGE[goal.status])}>{goal.status}</span>
                                <span className={cn('inline-flex items-center gap-1.5 rounded-md px-1.5 py-0.5 text-[10px] font-bold', rag.chip)}>
                                    <span className={cn('h-1.5 w-1.5 rounded-full', rag.dot)} /> {rag.label}
                                </span>
                                {goal.cycle && <span className="text-[11.5px] text-muted-foreground">{goal.cycle.name}</span>}
                                <span className={cn('h-2 w-2 rounded-full', PRIORITY_DOT[goal.priority])} title={`${goal.priority} priority`} />
                            </div>
                            <h1 className="text-[22px] font-extrabold leading-tight tracking-tight">{goal.title}</h1>
                            {goal.description && <p className="mt-2 max-w-2xl text-[13px] text-muted-foreground">{goal.description}</p>}
                            <div className="mt-3 flex flex-wrap items-center gap-4 text-[12px] text-muted-foreground">
                                <span className="inline-flex items-center gap-1.5">
                                    <Avatar name={goal.user?.name} /> {goal.user?.name ?? '—'}
                                </span>
                                {goal.category && <span>· {goal.category}</span>}
                                <span>· {formatDate(goal.start_date, true)} → {formatDate(goal.due_date, true)}</span>
                            </div>
                            {goal.tags.length > 0 && (
                                <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                                    {goal.tags.map((t) => (
                                        <span key={t} className="inline-flex items-center rounded-md bg-muted px-1.5 py-0.5 text-[11px] font-semibold text-muted-foreground">
                                            {t}
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    <div className="mt-5 flex flex-wrap gap-2">
                        {can.updateProgress && (
                            <button type="button" onClick={() => setCheckinOpen(true)} className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-[12.5px] font-semibold text-primary-foreground">
                                <CheckCircle2 className="h-4 w-4" /> Log check-in
                            </button>
                        )}
                        {can.manage && (
                            <>
                                <button type="button" onClick={() => setEditOpen(true)} className={detailBtn}>
                                    <Pencil className="h-3.5 w-3.5" /> Edit
                                </button>
                                <button type="button" onClick={() => setKrDialog(true)} className={detailBtn}>
                                    <Plus className="h-3.5 w-3.5" /> Add KR
                                </button>
                                <button type="button" onClick={() => setAddChildOpen(true)} className={detailBtn}>
                                    Add child
                                </button>
                                {goal.status !== 'completed' && (
                                    <button type="button" onClick={() => markStatus('completed')} className={detailBtn}>
                                        <Check className="h-3.5 w-3.5" /> Complete
                                    </button>
                                )}
                            </>
                        )}
                        <button type="button" onClick={exportCsv} className={detailBtn}>
                            <Download className="h-3.5 w-3.5" /> Export
                        </button>
                        {can.manage && (
                            <button type="button" onClick={archive} className="inline-flex items-center gap-1.5 rounded-lg border border-status-critical/30 bg-card px-3 py-2 text-[12.5px] font-semibold text-status-critical hover:bg-status-critical-bg">
                                <Trash2 className="h-3.5 w-3.5" /> Archive
                            </button>
                        )}
                    </div>
                </div>

                {/* sub-tabs */}
                <div className="inline-flex gap-1 self-start rounded-xl border border-border bg-card p-1.5 shadow-sm">
                    {subTabs.map((t) => (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => setSubtab(t.key)}
                            className={cn('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[12.5px] font-semibold', subtab === t.key ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:text-foreground')}
                        >
                            {t.label}
                            <span className="inline-flex items-center rounded-full bg-muted/70 px-1.5 text-[10px] font-bold tabular-nums">{t.count}</span>
                        </button>
                    ))}
                </div>

                {/* KEY RESULTS */}
                {subtab === 'krs' && (
                    <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                        {goal.key_results.length === 0 ? (
                            <p className="px-5 py-10 text-center text-[13px] text-muted-foreground">No key results — this objective uses manual progress.</p>
                        ) : (
                            goal.key_results.map((k) => (
                                <div key={k.id} className="flex items-center gap-3.5 border-b border-border/60 px-4 py-3.5 last:border-0">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-[13.5px] font-semibold">{k.title}</span>
                                            <span className="inline-flex items-center rounded-md bg-muted px-1.5 py-0.5 text-[10px] font-bold capitalize text-muted-foreground">{k.kr_type}</span>
                                            <span className="inline-flex items-center rounded-md bg-primary/10 px-1.5 py-0.5 text-[10px] font-bold text-primary">w{k.weight}</span>
                                        </div>
                                        <div className="mt-1.5 flex flex-wrap items-center gap-2.5 text-[12px] text-muted-foreground">
                                            <span>Baseline <strong className="text-foreground">{k.start_value}</strong></span>
                                            <span>→ Now <strong className="text-foreground">{k.current_value}</strong></span>
                                            <span>→ Target <strong className="text-foreground">{k.target_value}</strong></span>
                                            {k.owner && <span>· {k.owner.name}</span>}
                                        </div>
                                    </div>
                                    <RagPill confidence={k.confidence} />
                                    <div className="flex w-[140px] items-center gap-2">
                                        <ProgressBar pct={k.progress_percentage} className="h-1.5" />
                                        <span className="w-8 text-right text-xs font-bold tabular-nums">{k.progress_percentage}%</span>
                                    </div>
                                    {can.updateProgress && (
                                        <button type="button" onClick={() => setCheckinOpen(true)} className="rounded-md border border-border bg-card px-2.5 py-1.5 text-[11px] font-semibold hover:bg-muted">
                                            Check in
                                        </button>
                                    )}
                                    {can.manage && (
                                        <button type="button" onClick={() => deleteKr(k.id)} aria-label="Delete key result" className="grid h-7 w-7 place-items-center rounded-md border border-border bg-card text-muted-foreground hover:bg-muted">
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </button>
                                    )}
                                </div>
                            ))
                        )}
                        {can.manage && (
                            <button type="button" onClick={() => setKrDialog(true)} className="flex w-full items-center gap-2 px-4 py-3.5 text-[12.5px] font-semibold text-primary hover:bg-muted/40">
                                <Plus className="h-4 w-4" /> Add key result
                            </button>
                        )}
                    </div>
                )}

                {/* HISTORY */}
                {subtab === 'history' && (
                    <div className="rounded-xl border border-border bg-card px-5 py-2 shadow-sm">
                        {goal.updates.length === 0 ? (
                            <p className="py-8 text-center text-[13px] text-muted-foreground">No check-ins logged yet.</p>
                        ) : (
                            goal.updates.map((h) => (
                                <div key={h.id} className="flex gap-3.5 border-b border-border/60 py-3.5 last:border-0">
                                    <Avatar name={h.user_name} className="h-8 w-8 text-[11px]" />
                                    <div className="flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-[13px] font-semibold">{h.user_name}</span>
                                            <span className="text-[11.5px] text-muted-foreground">{h.created_at}</span>
                                            {h.confidence && <RagPill confidence={h.confidence} />}
                                            <span className="ml-auto text-[13px] font-extrabold tabular-nums">{h.progress_percentage}%</span>
                                        </div>
                                        {h.comment && <p className="mt-1 text-[12.5px] text-muted-foreground">{h.comment}</p>}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                )}

                {/* CHILDREN */}
                {subtab === 'children' && (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {goal.child_goals.length === 0 ? (
                            <div className="col-span-full rounded-xl border border-dashed border-border px-5 py-10 text-center text-[13px] text-muted-foreground">
                                No child objectives.{' '}
                                {can.manage && (
                                    <button type="button" onClick={() => setAddChildOpen(true)} className="font-semibold text-primary">
                                        Add one ›
                                    </button>
                                )}
                            </div>
                        ) : (
                            goal.child_goals.map((c) => (
                                <Link key={c.id} href={`/hr/goals/${c.id}`} className="block rounded-xl border border-border bg-card p-4 shadow-sm transition-shadow hover:shadow-md">
                                    <div className="mb-2 flex items-center gap-2">
                                        <TypeBadge type={c.goal_type} />
                                        <span className={cn('h-1.5 w-1.5 rounded-full', RAG[c.confidence].dot)} />
                                    </div>
                                    <div className="mb-2.5 text-[13.5px] font-semibold leading-snug">{c.title}</div>
                                    <div className="flex items-center gap-2">
                                        <ProgressBar pct={c.progress_percentage} className="h-1.5" />
                                        <span className="text-xs font-bold tabular-nums">{c.progress_percentage}%</span>
                                    </div>
                                    <div className="mt-2 text-[11.5px] text-muted-foreground">{c.user?.name ?? '—'} · {c.key_results_count} KR</div>
                                </Link>
                            ))
                        )}
                    </div>
                )}

                {/* LINKED PLANS */}
                {subtab === 'plans' && (
                    <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                        {goal.development_goals.length === 0 ? (
                            <p className="px-5 py-10 text-center text-[13px] text-muted-foreground">No linked development plans.</p>
                        ) : (
                            goal.development_goals.map((p) => (
                                <div key={p.id} className="flex items-center gap-3 border-b border-border/60 px-4 py-3 last:border-0">
                                    <Avatar name={p.employee} className="h-9 w-9 text-[12px]" />
                                    <div className="flex-1">
                                        <div className="text-[13px] font-semibold">{p.employee ?? '—'}</div>
                                        <div className="text-[11.5px] text-muted-foreground">
                                            {p.competency_area ?? p.title} · level {p.current_level ?? 0}→{p.target_level ?? 0}
                                        </div>
                                    </div>
                                    <span className={cn('inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold capitalize', STATUS_BADGE[p.status] ?? 'bg-muted text-muted-foreground')}>{p.status.replace('_', ' ')}</span>
                                </div>
                            ))
                        )}
                    </div>
                )}
            </div>

            {/* wizards */}
            {can.manage && (
                <ObjectiveWizard
                    open={editOpen}
                    onClose={() => setEditOpen(false)}
                    owners={users}
                    parentGoals={parentGoals}
                    cycles={cycles}
                    defaultCycleId={goal.cycle_id}
                    goal={asObjective}
                />
            )}
            {can.manage && (
                <ObjectiveWizard
                    open={addChildOpen}
                    onClose={() => setAddChildOpen(false)}
                    owners={users}
                    parentGoals={parentGoals}
                    cycles={cycles}
                    defaultCycleId={goal.cycle_id}
                    prefillParentId={goal.id}
                />
            )}
            <CheckinWizard open={checkinOpen} onClose={() => setCheckinOpen(false)} objective={asObjective} />
            {can.manage && krDialog && <AddKrDialog goalId={goal.id} owners={users} onClose={() => setKrDialog(false)} />}
        </AppLayout>
    );
}

function ragVar(c: Confidence) {
    return c === 'on_track' ? 'var(--status-success, #16a34a)' : c === 'at_risk' ? 'var(--status-warning, #d97706)' : 'var(--status-critical, #dc2626)';
}

/* ------------------------------------------------------------------ */
/*  Add key result dialog                                             */
/* ------------------------------------------------------------------ */

const KR_TYPES = [
    { value: 'number', label: 'Number' },
    { value: 'percent', label: 'Percent' },
    { value: 'currency', label: 'Currency' },
    { value: 'milestone', label: 'Milestone' },
    { value: 'boolean', label: 'Yes / No' },
];

function AddKrDialog({ goalId, owners, onClose }: { goalId: number; owners: { id: number; name: string }[]; onClose: () => void }) {
    const form = useForm({
        title: '',
        kr_type: 'percent',
        start_value: '0',
        target_value: '100',
        unit: '%',
        weight: '1',
        owner_id: '',
    });

    const submit = () => {
        form.transform((d) => ({
            ...d,
            start_value: Number(d.start_value) || 0,
            target_value: Number(d.target_value) || 0,
            weight: Number(d.weight) || 1,
            owner_id: d.owner_id || null,
        }));
        form.post(`/hr/goals/${goalId}/key-results`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-[100] grid place-items-center bg-black/40 p-6" onClick={onClose}>
            <div className="w-full max-w-lg rounded-2xl border border-border bg-card p-5 shadow-2xl" onClick={(e) => e.stopPropagation()}>
                <h3 className="flex items-center gap-2 text-base font-bold">
                    <ListChecks className="h-4 w-4 text-primary" /> Add key result
                </h3>
                <div className="mt-4 flex flex-col gap-3">
                    <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Key result title" />
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <Select value={form.data.kr_type} onValueChange={(v) => form.setData('kr_type', v)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {KR_TYPES.map((t) => (
                                    <SelectItem key={t.value} value={t.value}>
                                        {t.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Input value={form.data.start_value} onChange={(e) => form.setData('start_value', e.target.value)} placeholder="Baseline" />
                        <Input value={form.data.target_value} onChange={(e) => form.setData('target_value', e.target.value)} placeholder="Target" />
                        <Input value={form.data.unit} onChange={(e) => form.setData('unit', e.target.value)} placeholder="Unit" />
                        <Input value={form.data.weight} onChange={(e) => form.setData('weight', e.target.value)} placeholder="Weight" />
                        <Select value={form.data.owner_id} onValueChange={(v) => form.setData('owner_id', v)}>
                            <SelectTrigger>
                                <SelectValue placeholder="Owner" />
                            </SelectTrigger>
                            <SelectContent>
                                {owners.map((o) => (
                                    <SelectItem key={o.id} value={String(o.id)}>
                                        {o.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="rounded-lg px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted">
                        Cancel
                    </button>
                    <button type="button" onClick={submit} disabled={form.processing || !form.data.title.trim()} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground disabled:opacity-50">
                        {form.processing ? 'Saving…' : 'Add key result'}
                    </button>
                </div>
            </div>
        </div>
    );
}
