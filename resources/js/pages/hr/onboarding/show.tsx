/* eslint-disable no-restricted-syntax -- The detail workspace mirrors the gold-
 * standard prototype: a compact gradient hero + grouped task rows built from
 * styled native elements. Every colour is a semantic design token. */
import { useLeaveContextMenu } from '@/components/hr/leave-context-menu';
import {
    CompleteTaskDialog,
    type CompleteTaskTarget,
} from '@/components/hr/onboarding/complete-task-dialog';
import {
    ProvisionAssetDialog,
    type ProvisionableAsset,
    type ProvisionTarget,
} from '@/components/hr/onboarding/provision-asset-dialog';
import { ReassignDialog, type ReassignTarget } from '@/components/hr/onboarding/reassign-dialog';
import {
    avatarStyle,
    categoryColor,
    ChecklistStatusBadge,
    formatDate,
    initials,
    prettyLabel,
} from '@/components/hr/onboarding/shared';
import { TaskFormDialog, type TaskFormTarget } from '@/components/hr/onboarding/task-form-dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    ArrowLeft,
    Bell,
    Check,
    CheckCircle2,
    GripVertical,
    Laptop,
    MoreHorizontal,
    Pencil,
    Plus,
    RotateCcw,
    Trash2,
    Upload,
    UserCog,
} from 'lucide-react';

interface Task {
    id: number;
    category: string;
    title: string;
    description: string | null;
    is_required: boolean;
    sign_off_required: boolean;
    status: string;
    is_completed: boolean;
    sort_order: number;
    due_date: string | null;
    is_overdue: boolean;
    assigned_to_user_id: number | null;
    assignee: string | null;
    assigned_to_role: string | null;
    completed_at: string | null;
    completed_by: string | null;
    signed_off_by: string | null;
    evidence_path: string | null;
    notes: string | null;
}

interface Checklist {
    id: number;
    status: string;
    template_key: string;
    started_at: string | null;
    completed_at: string | null;
    due_date: string | null;
    owner: string | null;
    employee: {
        id: number | null;
        name: string;
        email: string | null;
        position_title: string | null;
        position_role: string | null;
        site_name: string | null;
        start_date: string | null;
    };
    tasks: Task[];
}

interface Props {
    checklist: Checklist;
    progress: { total: number; completed: number; pending: number; percent: number };
    owners: Array<{ id: number; name: string | null }>;
    provisionableAssets: ProvisionableAsset[];
    can: { manage: boolean };
}

export default function OnboardingShow({ checklist, owners, provisionableAssets, can }: Props) {
    const authUserId = Number(
        (usePage().props as { auth?: { user?: { id?: number } } }).auth?.user?.id ?? 0,
    );
    const ctx = useLeaveContextMenu();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr' },
        { title: 'Onboarding', href: '/hr/onboarding' },
        { title: checklist.employee.name, href: `/hr/onboarding/${checklist.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Onboarding — ${checklist.employee.name}`} />
            <DetailBody
                checklist={checklist}
                owners={owners}
                assets={provisionableAssets}
                can={can}
                authUserId={authUserId}
                ctx={ctx}
            />
            {ctx.element}
        </AppLayout>
    );
}

function DetailBody({
    checklist,
    owners,
    assets,
    can,
    authUserId,
    ctx,
}: {
    checklist: Checklist;
    owners: Array<{ id: number; name: string | null }>;
    assets: ProvisionableAsset[];
    can: { manage: boolean };
    authUserId: number;
    ctx: ReturnType<typeof useLeaveContextMenu>;
}) {
    const [completeTarget, setCompleteTarget] = useState<CompleteTaskTarget | null>(null);
    const [taskForm, setTaskForm] = useState<{ open: boolean; task: TaskFormTarget | null }>({ open: false, task: null });
    const [reassign, setReassign] = useState<ReassignTarget | null>(null);
    const [provision, setProvision] = useState<ProvisionTarget | null>(null);
    const [dragId, setDragId] = useState<number | null>(null);

    // Drag-to-reorder: drop the dragged task at the target's position and
    // persist the full task order (the endpoint rewrites sort_order 1..n).
    const reorderTo = (targetId: number) => {
        if (dragId === null || dragId === targetId) {
            setDragId(null);
            return;
        }
        const ids = checklist.tasks.map((t) => t.id);
        const from = ids.indexOf(dragId);
        const to = ids.indexOf(targetId);
        setDragId(null);
        if (from < 0 || to < 0) return;
        ids.splice(to, 0, ids.splice(from, 1)[0]);
        router.post(`/hr/onboarding/${checklist.id}/tasks/reorder`, { task_ids: ids }, { preserveScroll: true });
    };

    const done = checklist.tasks.filter((t) => t.is_completed).length;
    const total = checklist.tasks.length;
    const pct = total > 0 ? Math.round((done / total) * 100) : 0;
    const reqLeft = checklist.tasks.filter((t) => t.is_required && !t.is_completed).length;

    const groups = useMemo(() => {
        const map = new Map<string, Task[]>();
        for (const t of checklist.tasks) {
            const key = t.category || 'general';
            if (!map.has(key)) map.set(key, []);
            map.get(key)!.push(t);
        }
        return Array.from(map.entries()).map(([name, tasks]) => ({
            name,
            tasks,
            done: tasks.filter((t) => t.is_completed).length,
        }));
    }, [checklist.tasks]);

    const toggle = (t: Task) => {
        if (!can.manage) return;
        if (t.is_completed) {
            router.post(`/hr/onboarding/tasks/${t.id}/uncomplete`, {}, { preserveScroll: true });
            return;
        }
        if (t.sign_off_required) {
            setCompleteTarget({ id: t.id, title: t.title, sign_off_required: true, employee: checklist.employee.name });
            return;
        }
        router.post(`/hr/onboarding/tasks/${t.id}/complete`, {}, { preserveScroll: true });
    };

    const taskMenu = (t: Task) =>
        ctx.open([
            {
                kind: 'item',
                label: t.is_completed ? 'Reopen' : 'Complete',
                icon: t.is_completed ? RotateCcw : Check,
                onSelect: () => toggle(t),
            },
            ...(!t.is_completed
                ? [
                      {
                          kind: 'item' as const,
                          label: 'Complete with evidence',
                          icon: Upload,
                          onSelect: () =>
                              setCompleteTarget({ id: t.id, title: t.title, sign_off_required: t.sign_off_required, employee: checklist.employee.name }),
                      },
                  ]
                : []),
            ...(!t.is_completed && (t.category || '') === 'it'
                ? [
                      {
                          kind: 'item' as const,
                          label: 'Provision asset',
                          icon: Laptop,
                          onSelect: () =>
                              setProvision({ id: t.id, title: t.title, sign_off_required: t.sign_off_required }),
                      },
                  ]
                : []),
            { kind: 'item', label: 'Reassign', icon: UserCog, onSelect: () => setReassign({ kind: 'task', id: t.id, current: t.assigned_to_user_id, label: t.title }) },
            { kind: 'item', label: 'Edit', icon: Pencil, onSelect: () => setTaskForm({ open: true, task: toFormTarget(t) }) },
            { kind: 'divider' },
            { kind: 'item', label: 'Delete task', icon: Trash2, tone: 'critical', onSelect: () => router.delete(`/hr/onboarding/tasks/${t.id}`, { preserveScroll: true }) },
        ]);

    const av = avatarStyle(checklist.employee.name);
    const employeeMeta = [
        checklist.employee.position_title ?? prettyLabel(checklist.employee.position_role),
        checklist.employee.site_name,
        checklist.employee.start_date ? `starts ${formatDate(checklist.employee.start_date)}` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    const heroBtn =
        'inline-flex h-8 items-center gap-1.5 rounded-[9px] border border-white/28 bg-white/12 px-3 text-[12px] font-semibold text-primary-foreground hover:bg-white/20';

    return (
        <div className="mx-auto flex max-w-[1100px] flex-col gap-4.5 p-4 sm:p-6">
            <button
                type="button"
                onClick={() => router.visit('/hr/onboarding')}
                className="inline-flex items-center gap-1.5 self-start text-[13px] font-semibold text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft className="h-4 w-4" /> Back to onboarding
            </button>

            {/* Compact hero */}
            <div
                className="relative overflow-hidden rounded-2xl px-7 py-6 text-primary-foreground"
                style={{
                    background:
                        'linear-gradient(120deg, color-mix(in oklch, var(--category-hr) 72%, black 22%), var(--category-hr) 58%, color-mix(in oklch, var(--category-hr) 90%, white 8%))',
                    boxShadow: 'var(--shadow-hero, 0 24px 60px -22px rgba(60,40,10,.45))',
                }}
            >
                <div className="flex flex-wrap items-center gap-4.5">
                    <span className="grid h-14 w-14 flex-none place-items-center rounded-full border border-white/25 bg-white/18 text-lg font-bold">
                        {initials(checklist.employee.name)}
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2.5">
                            <h1 className="text-2xl font-bold tracking-tight">{checklist.employee.name}</h1>
                            <ChecklistStatusBadge status={checklist.status} />
                        </div>
                        <p className="mt-1 text-[13px] text-white/80">{employeeMeta || '—'}</p>
                    </div>
                    <div className="flex-none text-right">
                        <div className="text-[30px] leading-none font-extrabold tabular-nums">{pct}%</div>
                        <div className="mt-0.5 text-[11.5px] text-white/70">
                            {done} of {total} tasks · {reqLeft} required left
                        </div>
                    </div>
                </div>
                <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-white/20">
                    <div className="h-full rounded-full bg-primary-foreground transition-[width] duration-300" style={{ width: `${pct}%` }} />
                </div>
                {can.manage && (
                    <div className="mt-4 flex flex-wrap gap-2">
                        <button type="button" onClick={() => setTaskForm({ open: true, task: null })} className="inline-flex h-8 items-center gap-1.5 rounded-[9px] bg-primary-foreground px-3 text-[12px] font-bold text-primary">
                            <Plus className="h-3.5 w-3.5" /> Add task
                        </button>
                        <button type="button" onClick={() => setReassign({ kind: 'checklist', id: checklist.id, current: null, label: checklist.employee.name })} className={heroBtn}>
                            <UserCog className="h-3.5 w-3.5" /> Reassign owner
                        </button>
                        <button type="button" onClick={() => router.post(`/hr/onboarding/${checklist.id}/remind`, {}, { preserveScroll: true })} className={heroBtn}>
                            <Bell className="h-3.5 w-3.5" /> Send reminder
                        </button>
                        <button type="button" onClick={() => router.post(`/hr/onboarding/${checklist.id}/complete`, {}, { preserveScroll: true })} className={heroBtn}>
                            <CheckCircle2 className="h-3.5 w-3.5" /> Mark complete
                        </button>
                    </div>
                )}
            </div>

            {/* Grouped tasks */}
            {groups.map((g) => {
                const gpct = g.tasks.length > 0 ? Math.round((g.done / g.tasks.length) * 100) : 0;
                const color = categoryColor(g.name);
                return (
                    <div key={g.name} className="overflow-hidden rounded-2xl border border-border bg-card">
                        <div className="flex items-center gap-2.5 border-b border-border px-4.5 py-3">
                            <span className="h-2.5 w-2.5 rounded-[3px]" style={{ background: color }} />
                            <span className="text-[13.5px] font-bold">{prettyLabel(g.name)}</span>
                            <span className="text-[11.5px] text-muted-foreground">{g.done}/{g.tasks.length}</span>
                            <div className="flex-1" />
                            <div className="h-1.5 w-20 overflow-hidden rounded-full bg-muted">
                                <div className="h-full rounded-full" style={{ width: `${gpct}%`, background: color }} />
                            </div>
                        </div>
                        {g.tasks.map((t) => {
                            const tav = avatarStyle(t.assignee ?? t.title);
                            return (
                                <div
                                    key={t.id}
                                    onContextMenu={can.manage ? taskMenu(t) : undefined}
                                    onDragOver={can.manage ? (e) => e.preventDefault() : undefined}
                                    onDrop={can.manage ? () => reorderTo(t.id) : undefined}
                                    className={`group flex items-start gap-2 border-b border-border/55 px-4.5 py-3 last:border-0 ${
                                        dragId === t.id ? 'opacity-50' : ''
                                    }`}
                                    style={t.is_overdue ? { background: 'color-mix(in oklch, var(--status-critical-bg) 40%, transparent)' } : undefined}
                                >
                                    {can.manage && (
                                        <span
                                            draggable
                                            onDragStart={() => setDragId(t.id)}
                                            onDragEnd={() => setDragId(null)}
                                            aria-label="Drag to reorder"
                                            className="mt-1 cursor-grab text-muted-foreground/40 opacity-0 transition-opacity group-hover:opacity-100 hover:text-muted-foreground active:cursor-grabbing"
                                        >
                                            <GripVertical className="h-4 w-4" />
                                        </span>
                                    )}
                                    <button
                                        type="button"
                                        disabled={!can.manage}
                                        onClick={() => toggle(t)}
                                        aria-label={t.is_completed ? 'Reopen task' : 'Complete task'}
                                        className={`mt-0.5 grid h-[21px] w-[21px] flex-none place-items-center rounded-md border-[1.5px] ${
                                            t.is_completed
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : t.is_overdue
                                                  ? 'border-status-critical'
                                                  : 'border-border'
                                        } ${can.manage ? 'cursor-pointer' : 'cursor-default'}`}
                                    >
                                        {t.is_completed && <Check className="h-3.5 w-3.5" strokeWidth={3} />}
                                    </button>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className={`text-[13.5px] font-semibold ${t.is_completed ? 'text-muted-foreground line-through' : ''}`}>
                                                {t.title}
                                            </span>
                                            {t.is_required && (
                                                <span className="rounded bg-status-critical-bg px-1.5 py-px text-[9.5px] font-bold tracking-wide text-status-critical uppercase">
                                                    Required
                                                </span>
                                            )}
                                            {t.sign_off_required && (
                                                <span className="rounded bg-accent px-1.5 py-px text-[9.5px] font-bold tracking-wide text-primary uppercase">
                                                    Sign-off
                                                </span>
                                            )}
                                            {t.is_overdue && (
                                                <span className="text-[9.5px] font-bold tracking-wide text-status-critical uppercase">· Overdue</span>
                                            )}
                                        </div>
                                        {t.description && <div className="mt-0.5 text-[12px] text-muted-foreground">{t.description}</div>}
                                        <div className="mt-1.5 flex flex-wrap items-center gap-3.5">
                                            <span className="inline-flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
                                                <span className="grid h-5 w-5 place-items-center rounded-full text-[8.5px] font-bold" style={tav}>
                                                    {initials(t.assignee ?? t.assigned_to_role)}
                                                </span>
                                                {t.assignee ?? prettyLabel(t.assigned_to_role) ?? 'Unassigned'}
                                            </span>
                                            {t.is_completed ? (
                                                <span className="text-[11.5px] text-muted-foreground">
                                                    Done {formatDate(t.completed_at)}
                                                    {t.completed_by ? ` · ${t.completed_by}` : ''}
                                                    {t.signed_off_by ? ` · signed off ${t.signed_off_by}` : ''}
                                                </span>
                                            ) : (
                                                t.due_date && (
                                                    <span className={`text-[11.5px] ${t.is_overdue ? 'font-bold text-status-critical' : 'text-muted-foreground'}`}>
                                                        Due {formatDate(t.due_date)}
                                                    </span>
                                                )
                                            )}
                                        </div>
                                    </div>
                                    {can.manage && (
                                        <div className="flex flex-none items-center gap-1">
                                            <button
                                                type="button"
                                                onClick={() => setReassign({ kind: 'task', id: t.id, current: t.assigned_to_user_id, label: t.title })}
                                                aria-label="Reassign"
                                                className="grid h-7.5 w-7.5 place-items-center rounded-lg text-muted-foreground hover:bg-muted"
                                            >
                                                <UserCog className="h-3.5 w-3.5" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={taskMenu(t)}
                                                aria-label="Task actions"
                                                className="grid h-7.5 w-7.5 place-items-center rounded-lg text-muted-foreground hover:bg-muted"
                                            >
                                                <MoreHorizontal className="h-3.5 w-3.5" />
                                            </button>
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                );
            })}

            {can.manage && (
                <>
                    <CompleteTaskDialog
                        open={completeTarget !== null}
                        onClose={() => setCompleteTarget(null)}
                        task={completeTarget}
                        currentUserId={authUserId}
                    />
                    <TaskFormDialog
                        open={taskForm.open}
                        onClose={() => setTaskForm({ open: false, task: null })}
                        checklistId={checklist.id}
                        task={taskForm.task}
                        owners={owners}
                    />
                    <ReassignDialog
                        open={reassign !== null}
                        onClose={() => setReassign(null)}
                        target={reassign}
                        owners={owners}
                    />
                    <ProvisionAssetDialog
                        open={provision !== null}
                        onClose={() => setProvision(null)}
                        task={provision}
                        assets={assets}
                        currentUserId={authUserId}
                    />
                </>
            )}
        </div>
    );
}

function toFormTarget(t: Task): TaskFormTarget {
    return {
        id: t.id,
        title: t.title,
        description: t.description,
        category: t.category,
        due_date: t.due_date,
        is_required: t.is_required,
        sign_off_required: t.sign_off_required,
        assigned_to_user_id: t.assigned_to_user_id,
    };
}
