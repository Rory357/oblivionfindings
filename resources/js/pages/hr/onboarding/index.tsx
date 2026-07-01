/* eslint-disable no-restricted-syntax -- The onboarding hub mirrors the gold-
 * standard recruitment hub: bespoke table rows, toolbar chips, bulk bar and
 * context-menu triggers built from styled native elements. Every colour is a
 * semantic design token. */
import { HrTabs, useHrTab, type HrTabItem } from '@/components/hr/hr-tabs';
import { useLeaveContextMenu } from '@/components/hr/leave-context-menu';
import {
    OnboardingWizardDialog,
    type NewHireOptions,
    type OnboardingEmailOption,
    type OnboardingEmployee,
    type OnboardingTemplateOption,
} from '@/components/hr/onboarding-wizard-dialog';
import { ChecklistDrawer, type DrawerChecklist, type DrawerTask } from '@/components/hr/onboarding/checklist-drawer';
import {
    CompleteTaskDialog,
    type CompleteTaskTarget,
} from '@/components/hr/onboarding/complete-task-dialog';
import { EmailDialog, type EmailTemplate } from '@/components/hr/onboarding/email-dialog';
import { OnboardingHero, type OnboardingSummary } from '@/components/hr/onboarding/onboarding-hero';
import { ReassignDialog, type ReassignTarget } from '@/components/hr/onboarding/reassign-dialog';
import {
    avatarStyle,
    ChecklistStatusBadge,
    formatShort,
    initials,
    prettyLabel,
} from '@/components/hr/onboarding/shared';
import { TemplateDialog, type CourseOption, type TemplateRow } from '@/components/hr/onboarding/template-dialog';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    Bell,
    CheckCircle2,
    ClipboardCheck,
    Copy,
    Eye,
    LayoutGrid,
    ListChecks,
    Mail,
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    Search,
    Send,
    Trash2,
    UserCog,
} from 'lucide-react';
import { useState } from 'react';

interface ChecklistRow {
    id: number;
    status: string;
    is_overdue: boolean;
    template_key: string;
    due_date: string | null;
    tasks_count: number;
    tasks_completed_count: number;
    owner: string | null;
    employee: { id: number | null; name: string; role: string | null; site: string | null };
}

interface OverviewData {
    overdue_tasks: Array<{ id: number; checklist_id: number; task: string; who: string; employee: string; late: string | null }>;
    signoff_tasks: Array<{ id: number; checklist_id: number; task: string; employee: string }>;
    starters: Array<{ id: number; name: string; role: string | null; date: string | null; done: number; total: number }>;
    activity: Array<{ who: string; action: string; when: string | null; tone: string }>;
}

interface Props {
    checklists: {
        data: ChecklistRow[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
    drawerChecklist: DrawerChecklist | null;
    summary: OnboardingSummary;
    overview: OverviewData;
    templates: TemplateRow[];
    emails: { templates: EmailTemplate[]; log: Array<{ id: number; template: string; to: string; status: string; when: string | null }> };
    employees: OnboardingEmployee[];
    emailTemplates: OnboardingEmailOption[];
    owners: Array<{ id: number; name: string | null }>;
    newHireOptions: NewHireOptions;
    templateRoleOptions: string[];
    courseOptions: CourseOption[];
    siteTypeOptions: string[];
    filters: { status: string | null; q: string };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Onboarding', href: '/hr/onboarding' },
];

const TONE_DOT: Record<string, string> = {
    success: 'bg-status-success',
    info: 'bg-primary',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

export default function OnboardingIndex(props: Props) {
    const {
        checklists,
        drawerChecklist,
        summary,
        overview,
        templates,
        emails,
        employees,
        emailTemplates,
        owners,
        newHireOptions,
        templateRoleOptions,
        courseOptions,
        siteTypeOptions,
        filters,
        can,
    } = props;

    const authUserId = Number(
        (usePage().props as { auth?: { user?: { id?: number } } }).auth?.user?.id ?? 0,
    );

    const [tab, setTab] = useHrTab('overview');
    const ctx = useLeaveContextMenu();

    // Dialog + drawer state
    const [wizardOpen, setWizardOpen] = useState(false);
    const [wizardMode, setWizardMode] = useState<'existing' | 'new'>('existing');
    const [templateDialog, setTemplateDialog] = useState<{ open: boolean; row: TemplateRow | null }>({ open: false, row: null });
    const [emailDialog, setEmailDialog] = useState<{ open: boolean; row: EmailTemplate | null }>({ open: false, row: null });
    const [completeTarget, setCompleteTarget] = useState<CompleteTaskTarget | null>(null);
    const [reassign, setReassign] = useState<ReassignTarget | null>(null);
    const [drawerId, setDrawerId] = useState<number | null>(null);
    const [drawerLoading, setDrawerLoading] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);

    /* ---------------- navigation + filters ---------------- */
    const go = (target: string, status?: string | null) =>
        router.get(
            '/hr/onboarding',
            { ...filters, status: status === undefined ? filters.status : status || undefined, tab: target },
            { preserveState: false, replace: true, preserveScroll: false },
        );

    const applyFilter = (key: string, value: string | null) =>
        router.get(
            '/hr/onboarding',
            { ...filters, [key]: value || undefined, tab: 'checklists' },
            { preserveState: true, replace: true, preserveScroll: true },
        );

    /* ---------------- drawer ---------------- */
    const openDrawer = (id: number) => {
        setDrawerId(id);
        setDrawerLoading(true);
        router.reload({
            only: ['drawerChecklist'],
            data: { drawer: id },
            onFinish: () => setDrawerLoading(false),
        });
    };
    const closeDrawer = () => setDrawerId(null);

    /* ---------------- task / checklist actions ---------------- */
    const toggleTask = (task: { id: number; title: string; is_completed: boolean; sign_off_required: boolean }, employee?: string) => {
        if (!can.manage) return;
        if (task.is_completed) {
            router.post(`/hr/onboarding/tasks/${task.id}/uncomplete`, {}, { preserveScroll: true, preserveState: true });
            return;
        }
        if (task.sign_off_required) {
            setCompleteTarget({ id: task.id, title: task.title, sign_off_required: true, employee });
            return;
        }
        router.post(`/hr/onboarding/tasks/${task.id}/complete`, {}, { preserveScroll: true, preserveState: true });
    };

    const remindChecklist = (id: number) =>
        router.post(`/hr/onboarding/${id}/remind`, {}, { preserveScroll: true });
    const completeChecklist = (id: number) =>
        router.post(`/hr/onboarding/${id}/complete`, {}, { preserveScroll: true });
    const archiveChecklist = (id: number) =>
        router.post(`/hr/onboarding/${id}/status`, { status: 'archived' }, { preserveScroll: true });

    /* ---------------- bulk ---------------- */
    const bulk = (action: 'remind' | 'complete' | 'archive') => {
        if (selected.length === 0) return;
        router.post('/hr/onboarding/bulk', { action, checklist_ids: selected }, {
            preserveScroll: true,
            onSuccess: () => setSelected([]),
        });
    };
    const toggleSelect = (id: number) =>
        setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));

    /* ---------------- context menus ---------------- */
    const checklistMenu = (row: ChecklistRow) =>
        ctx.open([
            { kind: 'item', label: 'Open', icon: Eye, onSelect: () => router.visit(`/hr/onboarding/${row.id}`) },
            { kind: 'item', label: 'Quick peek', icon: Eye, onSelect: () => openDrawer(row.id) },
            ...(can.manage
                ? [
                      { kind: 'item' as const, label: 'Reassign owner', icon: UserCog, onSelect: () => setReassign({ kind: 'checklist', id: row.id, current: null, label: row.employee.name }) },
                      { kind: 'item' as const, label: 'Send reminder', icon: Bell, onSelect: () => remindChecklist(row.id) },
                      { kind: 'divider' as const },
                      { kind: 'item' as const, label: 'Mark complete', icon: CheckCircle2, onSelect: () => completeChecklist(row.id) },
                      { kind: 'item' as const, label: 'Archive', icon: Archive, tone: 'critical' as const, onSelect: () => archiveChecklist(row.id) },
                  ]
                : []),
        ]);

    const tabMenu = (id: string) =>
        ctx.open([{ kind: 'item', label: 'Open', icon: Eye, onSelect: () => setTab(id) }]);

    const templateMenu = (t: TemplateRow) =>
        ctx.open([
            { kind: 'item', label: 'Edit', icon: Pencil, onSelect: () => setTemplateDialog({ open: true, row: t }) },
            { kind: 'item', label: 'Duplicate', icon: Copy, onSelect: () => router.post(`/hr/onboarding/templates/${t.id}/duplicate`, {}, { preserveScroll: true }) },
            { kind: 'item', label: t.is_active ? 'Deactivate' : 'Activate', icon: Power, onSelect: () => router.post(`/hr/onboarding/templates/${t.id}/active`, { is_active: !t.is_active }, { preserveScroll: true }) },
            { kind: 'divider' },
            { kind: 'item', label: 'Delete', icon: Trash2, tone: 'critical', onSelect: () => router.delete(`/hr/onboarding/templates/${t.id}`, { preserveScroll: true }) },
        ]);

    const emailMenu = (e: EmailTemplate) =>
        ctx.open([
            { kind: 'item', label: 'Edit', icon: Pencil, onSelect: () => setEmailDialog({ open: true, row: e }) },
            { kind: 'item', label: 'Send test', icon: Send, onSelect: () => router.post(`/hr/onboarding/emails/${e.id}/test`, {}, { preserveScroll: true }) },
            { kind: 'item', label: e.is_active ? 'Deactivate' : 'Activate', icon: Power, onSelect: () => router.put(`/hr/onboarding/emails/${e.id}`, { is_active: !e.is_active }, { preserveScroll: true }) },
            { kind: 'divider' },
            { kind: 'item', label: 'Delete', icon: Trash2, tone: 'critical', onSelect: () => router.delete(`/hr/onboarding/emails/${e.id}`, { preserveScroll: true }) },
        ]);

    /* ---------------- tabs ---------------- */
    const tabItems: HrTabItem[] = [
        { id: 'overview', label: 'Overview', icon: LayoutGrid, tone: 'primary' },
        { id: 'checklists', label: 'Checklists', icon: ListChecks, tone: 'primary', badge: summary.active || undefined },
        { id: 'templates', label: 'Templates', icon: ClipboardCheck, tone: 'violet', badge: templates.length || undefined },
        { id: 'emails', label: 'Emails', icon: Mail, tone: 'info' },
    ];

    const needs = [
        ...(summary.overdue > 0 ? [{ label: `${summary.overdue} overdue`, onClick: () => go('checklists', 'overdue') }] : []),
        ...(overview.signoff_tasks.length > 0 ? [{ label: `${overview.signoff_tasks.length} awaiting sign-off`, onClick: () => setTab('overview') }] : []),
        ...(overview.starters.length > 0 ? [{ label: `${overview.starters.length} starters soon`, onClick: () => setTab('overview') }] : []),
    ];

    const openWizard = (mode: 'existing' | 'new') => {
        setWizardMode(mode);
        setWizardOpen(true);
    };

    const drawerData = drawerId && drawerChecklist?.id === drawerId ? drawerChecklist : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Onboarding" />
            <div className="flex flex-col gap-5 p-4 sm:p-6">
                <OnboardingHero
                    summary={summary}
                    canManage={can.manage}
                    needs={needs}
                    onStart={() => openWizard('existing')}
                    onNewTemplate={() => setTemplateDialog({ open: true, row: null })}
                    onEmails={() => setTab('emails')}
                    onExport={() => { window.location.href = '/hr/onboarding/export'; }}
                    onStat={(key) => {
                        if (key === 'avg') return setTab('overview');
                        if (key === 'in_progress') return go('checklists', 'in_progress');
                        if (key === 'overdue') return go('checklists', 'overdue');
                        return go('checklists', null);
                    }}
                />

                <HrTabs value={tab} onChange={setTab} items={tabItems} onItemContextMenu={tabMenu} ariaLabel="Onboarding views" />

                <div className="motion-safe:animate-in motion-safe:fade-in-0">
                    {tab === 'overview' && (
                        <OverviewTab
                            summary={summary}
                            overview={overview}
                            canManage={can.manage}
                            onOpenChecklist={(id) => router.visit(`/hr/onboarding/${id}`)}
                            onPeek={openDrawer}
                            onSignOff={(t) => setCompleteTarget({ id: t.id, title: t.task, sign_off_required: true, employee: t.employee })}
                            onGoOverdue={() => go('checklists', 'overdue')}
                        />
                    )}

                    {tab === 'checklists' && (
                        <ChecklistsTab
                            checklists={checklists}
                            filters={filters}
                            canManage={can.manage}
                            selected={selected}
                            onToggleSelect={toggleSelect}
                            onClearSelection={() => setSelected([])}
                            onBulk={bulk}
                            onApplyFilter={applyFilter}
                            onRowOpen={openDrawer}
                            onRowMenu={checklistMenu}
                            onStartOnboarding={() => openWizard('existing')}
                        />
                    )}

                    {tab === 'templates' && (
                        <TemplatesTab
                            templates={templates}
                            canManage={can.manage}
                            onNew={() => setTemplateDialog({ open: true, row: null })}
                            onEdit={(t) => setTemplateDialog({ open: true, row: t })}
                            onDuplicate={(t) => router.post(`/hr/onboarding/templates/${t.id}/duplicate`, {}, { preserveScroll: true })}
                            onMenu={templateMenu}
                        />
                    )}

                    {tab === 'emails' && (
                        <EmailsTab
                            emails={emails}
                            canManage={can.manage}
                            onNew={() => setEmailDialog({ open: true, row: null })}
                            onEdit={(e) => setEmailDialog({ open: true, row: e })}
                            onMenu={emailMenu}
                        />
                    )}
                </div>
            </div>

            {/* Drawer */}
            <ChecklistDrawer
                open={drawerId !== null}
                data={drawerData}
                loading={drawerLoading}
                canManage={can.manage}
                onClose={closeDrawer}
                onToggleTask={(t: DrawerTask) => toggleTask(t, drawerData?.name)}
                onReassign={() => drawerData && setReassign({ kind: 'checklist', id: drawerData.id, current: null, label: drawerData.name })}
                onReminder={() => drawerData && remindChecklist(drawerData.id)}
                onOpenFull={() => drawerData && router.visit(`/hr/onboarding/${drawerData.id}`)}
            />

            {/* Dialogs */}
            {can.manage && (
                <>
                    <OnboardingWizardDialog
                        open={wizardOpen}
                        onClose={() => setWizardOpen(false)}
                        employees={employees}
                        templates={templates as unknown as OnboardingTemplateOption[]}
                        emailTemplates={emailTemplates}
                        newHireOptions={newHireOptions}
                        initialMode={wizardMode}
                    />
                    <TemplateDialog
                        open={templateDialog.open}
                        onClose={() => setTemplateDialog({ open: false, row: null })}
                        template={templateDialog.row}
                        roleOptions={templateRoleOptions}
                        siteTypeOptions={siteTypeOptions}
                        courseOptions={courseOptions}
                    />
                    <EmailDialog
                        open={emailDialog.open}
                        onClose={() => setEmailDialog({ open: false, row: null })}
                        email={emailDialog.row}
                    />
                    <CompleteTaskDialog
                        open={completeTarget !== null}
                        onClose={() => setCompleteTarget(null)}
                        task={completeTarget}
                        currentUserId={authUserId}
                    />
                    <ReassignDialog
                        open={reassign !== null}
                        onClose={() => setReassign(null)}
                        target={reassign}
                        owners={owners}
                    />
                </>
            )}

            {ctx.element}
        </AppLayout>
    );
}

/* ====================================================================== */
/*  Overview tab                                                          */
/* ====================================================================== */

function Kpi({ label, value, sub, tone }: { label: string; value: React.ReactNode; sub: string; tone?: string }) {
    return (
        <div className="rounded-[14px] border border-border bg-card px-4 py-3.5 shadow-sm">
            <div className="text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">{label}</div>
            <div className="mt-1.5 text-[26px] font-bold tabular-nums" style={tone ? { color: tone } : undefined}>
                {value}
            </div>
            <div className="mt-0.5 text-[11.5px] text-muted-foreground">{sub}</div>
        </div>
    );
}

function OverviewTab({
    summary,
    overview,
    canManage,
    onOpenChecklist,
    onPeek,
    onSignOff,
    onGoOverdue,
}: {
    summary: OnboardingSummary;
    overview: OverviewData;
    canManage: boolean;
    onOpenChecklist: (id: number) => void;
    onPeek: (id: number) => void;
    onSignOff: (t: { id: number; task: string; employee: string }) => void;
    onGoOverdue: () => void;
}) {
    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <Kpi label="Active" value={summary.active} sub="in onboarding" />
                <Kpi label="In progress" value={summary.in_progress} sub="started" />
                <Kpi label="Overdue" value={summary.overdue} sub="checklists" tone={summary.overdue ? 'var(--status-critical)' : undefined} />
                <Kpi label="Due this week" value={summary.due_this_week} sub="by start" tone={summary.due_this_week ? 'var(--status-warning)' : undefined} />
                <Kpi label="Avg completion" value={`${summary.avg_completion}%`} sub="across active" />
                <Kpi label="Completed" value={summary.completed_30d} sub="last 30 days" tone={summary.completed_30d ? 'var(--status-success)' : undefined} />
            </div>

            <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                <div className="flex flex-col gap-4">
                    <LanePanel
                        dot="bg-status-critical"
                        title="Overdue tasks"
                        count={overview.overdue_tasks.length}
                        action={overview.overdue_tasks.length > 0 ? { label: 'View all →', onClick: onGoOverdue } : undefined}
                        empty="No overdue tasks — nice."
                    >
                        {overview.overdue_tasks.map((o) => (
                            <div key={o.id} className="flex items-center gap-3 border-b border-border/60 px-4.5 py-3 last:border-0">
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13px] font-semibold">{o.task}</div>
                                    <div className="text-[11.5px] text-muted-foreground">{o.who} · {o.employee}</div>
                                </div>
                                {o.late && (
                                    <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-bold whitespace-nowrap text-status-critical">
                                        {o.late}
                                    </span>
                                )}
                                <button type="button" onClick={() => onPeek(o.checklist_id)} className="text-[12px] font-semibold text-primary">
                                    Open
                                </button>
                            </div>
                        ))}
                    </LanePanel>

                    <LanePanel dot="bg-status-warning" title="Awaiting sign-off" count={overview.signoff_tasks.length} empty="Nothing awaiting sign-off.">
                        {overview.signoff_tasks.map((s) => (
                            <div key={s.id} className="flex items-center gap-3 border-b border-border/60 px-4.5 py-3 last:border-0">
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13px] font-semibold">{s.task}</div>
                                    <div className="text-[11.5px] text-muted-foreground">{s.employee}</div>
                                </div>
                                {canManage && (
                                    <button
                                        type="button"
                                        onClick={() => onSignOff(s)}
                                        className="rounded-lg bg-primary px-2.5 py-1.5 text-[12px] font-semibold text-primary-foreground"
                                    >
                                        Sign off
                                    </button>
                                )}
                            </div>
                        ))}
                    </LanePanel>
                </div>

                <div className="flex flex-col gap-4">
                    <div className="overflow-hidden rounded-2xl border border-border bg-card">
                        <div className="border-b border-border px-4.5 py-3.5 text-[14px] font-bold">Starters · next 14 days</div>
                        {overview.starters.length === 0 ? (
                            <div className="px-4.5 py-6 text-center text-sm text-muted-foreground">No upcoming starters.</div>
                        ) : (
                            overview.starters.map((st) => {
                                const pct = st.total > 0 ? Math.round((st.done / st.total) * 100) : 0;
                                const av = avatarStyle(st.name);
                                return (
                                    <button
                                        key={st.id}
                                        type="button"
                                        onClick={() => onOpenChecklist(st.id)}
                                        className="block w-full border-b border-border/60 px-4.5 py-3 text-left last:border-0 hover:bg-muted"
                                    >
                                        <div className="flex items-center justify-between gap-2.5">
                                            <div className="flex min-w-0 items-center gap-2.5">
                                                <span className="grid h-8 w-8 flex-none place-items-center rounded-full text-[12px] font-bold" style={av}>
                                                    {initials(st.name)}
                                                </span>
                                                <div className="min-w-0">
                                                    <div className="truncate text-[13px] font-semibold">{st.name}</div>
                                                    <div className="truncate text-[11px] text-muted-foreground">{st.role ?? '—'}</div>
                                                </div>
                                            </div>
                                            <div className="flex-none text-right whitespace-nowrap">
                                                <div className="text-[12px] font-bold">{formatShort(st.date)}</div>
                                                <div className="text-[10.5px] text-muted-foreground">{st.done}/{st.total} done</div>
                                            </div>
                                        </div>
                                        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                                            <div className="h-full rounded-full bg-primary" style={{ width: `${pct}%` }} />
                                        </div>
                                    </button>
                                );
                            })
                        )}
                    </div>

                    <div className="overflow-hidden rounded-2xl border border-border bg-card">
                        <div className="border-b border-border px-4.5 py-3.5 text-[14px] font-bold">Recent activity</div>
                        <div className="py-1.5">
                            {overview.activity.length === 0 ? (
                                <div className="px-4.5 py-5 text-center text-sm text-muted-foreground">No recent activity.</div>
                            ) : (
                                overview.activity.map((a, i) => (
                                    <div key={i} className="flex gap-3 px-4.5 py-2">
                                        <span className={`mt-1.5 h-1.5 w-1.5 flex-none rounded-full ${TONE_DOT[a.tone] ?? TONE_DOT.neutral}`} />
                                        <div className="text-[12.5px] leading-snug">
                                            <span className="font-semibold">{a.who}</span> {a.action}{' '}
                                            <span className="text-muted-foreground">· {a.when}</span>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

function LanePanel({
    dot,
    title,
    count,
    action,
    empty,
    children,
}: {
    dot: string;
    title: string;
    count: number;
    action?: { label: string; onClick: () => void };
    empty: string;
    children: React.ReactNode;
}) {
    return (
        <div className="overflow-hidden rounded-2xl border border-border bg-card">
            <div className="flex items-center justify-between border-b border-border px-4.5 py-3.5">
                <div className="flex items-center gap-2.5">
                    <span className={`h-2 w-2 rounded-full ${dot}`} />
                    <span className="text-[14px] font-bold">{title}</span>
                    <span className="text-[11px] text-muted-foreground">{count}</span>
                </div>
                {action && (
                    <button type="button" onClick={action.onClick} className="text-[12px] font-semibold text-primary">
                        {action.label}
                    </button>
                )}
            </div>
            {count === 0 ? <div className="px-4.5 py-6 text-center text-sm text-muted-foreground">{empty}</div> : children}
        </div>
    );
}

/* ====================================================================== */
/*  Checklists tab                                                        */
/* ====================================================================== */

function ChecklistsTab({
    checklists,
    filters,
    canManage,
    selected,
    onToggleSelect,
    onClearSelection,
    onBulk,
    onApplyFilter,
    onRowOpen,
    onRowMenu,
    onStartOnboarding,
}: {
    checklists: Props['checklists'];
    filters: Props['filters'];
    canManage: boolean;
    selected: number[];
    onToggleSelect: (id: number) => void;
    onClearSelection: () => void;
    onBulk: (action: 'remind' | 'complete' | 'archive') => void;
    onApplyFilter: (key: string, value: string | null) => void;
    onRowOpen: (id: number) => void;
    onRowMenu: (row: ChecklistRow) => (e: React.MouseEvent) => void;
    onStartOnboarding: () => void;
}) {
    const grid = 'grid grid-cols-[36px_2.1fr_1.3fr_1fr_1.4fr_1.1fr_0.9fr_40px] items-center gap-3';
    const statuses = ['pending', 'in_progress', 'completed', 'overdue'];

    return (
        <div className="flex flex-col gap-3.5">
            <div className="flex flex-wrap items-center gap-2.5">
                <div className="relative min-w-[220px] flex-1 sm:max-w-[340px]">
                    <Search className="absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                    <input
                        defaultValue={filters.q}
                        placeholder="Search employees…"
                        className="h-9.5 w-full rounded-[10px] border border-border bg-card pr-3 pl-9 text-[13px] outline-none focus:border-ring"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') onApplyFilter('q', (e.target as HTMLInputElement).value);
                        }}
                    />
                </div>
                {statuses.map((s) => {
                    const active = filters.status === s;
                    return (
                        <button
                            key={s}
                            type="button"
                            onClick={() => onApplyFilter('status', active ? null : s)}
                            className={`inline-flex h-9.5 items-center gap-1.5 rounded-[10px] border px-3 text-[12.5px] font-semibold ${
                                active ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-foreground hover:border-primary/50'
                            }`}
                        >
                            {s === 'overdue' && <span className="h-1.5 w-1.5 rounded-full bg-status-critical" />}
                            {prettyLabel(s)}
                        </button>
                    );
                })}
            </div>

            {canManage && selected.length > 0 && (
                <div className="flex items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-2.5 motion-safe:animate-in motion-safe:fade-in-0">
                    <span className="text-[13px] font-bold text-primary">{selected.length} selected</span>
                    <div className="h-4.5 w-px bg-primary/30" />
                    <BulkBtn onClick={() => onBulk('remind')}>Send reminder</BulkBtn>
                    <BulkBtn onClick={() => onBulk('complete')}>Mark complete</BulkBtn>
                    <BulkBtn onClick={() => onBulk('archive')}>Archive</BulkBtn>
                    <button type="button" onClick={onClearSelection} className="ml-auto text-[12px] text-muted-foreground hover:text-foreground">
                        Clear
                    </button>
                </div>
            )}

            <div className="overflow-hidden rounded-2xl border border-border bg-card">
                <div className={`${grid} border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase`}>
                    <span />
                    <span>Employee</span>
                    <span>Template</span>
                    <span>Status</span>
                    <span>Progress</span>
                    <span>Owner</span>
                    <span>Due</span>
                    <span />
                </div>

                {checklists.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 px-4 py-16 text-center">
                        <ListChecks className="h-10 w-10 text-muted-foreground/40" />
                        <p className="font-medium text-muted-foreground">No onboarding checklists found</p>
                        {canManage && (
                            <button type="button" onClick={onStartOnboarding} className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground">
                                <Plus className="h-4 w-4" /> Start onboarding
                            </button>
                        )}
                    </div>
                ) : (
                    checklists.data.map((r) => {
                        const pct = r.tasks_count > 0 ? Math.round((r.tasks_completed_count / r.tasks_count) * 100) : 0;
                        const av = avatarStyle(r.employee.name);
                        const checked = selected.includes(r.id);
                        return (
                            <div
                                key={r.id}
                                onContextMenu={onRowMenu(r)}
                                onClick={() => onRowOpen(r.id)}
                                className={`${grid} cursor-pointer border-b border-border/55 px-4.5 py-3 transition-colors last:border-0 hover:bg-muted ${checked ? 'bg-primary/5' : ''}`}
                            >
                                {canManage ? (
                                    <button
                                        type="button"
                                        onClick={(e) => { e.stopPropagation(); onToggleSelect(r.id); }}
                                        aria-label="Select"
                                        className={`grid h-[18px] w-[18px] place-items-center rounded-[5px] border-[1.5px] ${checked ? 'border-primary bg-primary text-primary-foreground' : 'border-border'}`}
                                    >
                                        {checked && <CheckCircle2 className="h-3 w-3" />}
                                    </button>
                                ) : (
                                    <span />
                                )}
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <span className="grid h-9 w-9 flex-none place-items-center rounded-full text-[12.5px] font-bold" style={av}>
                                        {initials(r.employee.name)}
                                    </span>
                                    <div className="min-w-0">
                                        <div className="truncate text-[13.5px] font-semibold">{r.employee.name}</div>
                                        <div className="truncate text-[11.5px] text-muted-foreground">
                                            {[r.employee.role, r.employee.site].filter(Boolean).join(' · ') || '—'}
                                        </div>
                                    </div>
                                </div>
                                <div className="truncate text-[12.5px]">{prettyLabel(r.template_key)}</div>
                                <div>
                                    <ChecklistStatusBadge status={r.status} isOverdue={r.is_overdue} />
                                </div>
                                <div className="flex items-center gap-2">
                                    <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full"
                                            style={{ width: `${pct}%`, background: r.is_overdue ? 'var(--status-critical)' : 'var(--primary)' }}
                                        />
                                    </div>
                                    <span className="text-[11px] font-semibold whitespace-nowrap text-muted-foreground">
                                        {r.tasks_completed_count}/{r.tasks_count}
                                    </span>
                                </div>
                                <div className="flex min-w-0 items-center gap-2">
                                    <span className="grid h-6 w-6 flex-none place-items-center rounded-full bg-muted text-[9px] font-bold text-muted-foreground">
                                        {initials(r.owner)}
                                    </span>
                                    <span className="truncate text-[12px]">{r.owner ?? '—'}</span>
                                </div>
                                <div className={`text-[12px] whitespace-nowrap ${r.is_overdue ? 'font-bold text-status-critical' : 'text-muted-foreground'}`}>
                                    {formatShort(r.due_date)}
                                </div>
                                <button
                                    type="button"
                                    onClick={(e) => { e.stopPropagation(); onRowMenu(r)(e); }}
                                    aria-label="Row actions"
                                    className="grid h-7 w-7 place-items-center rounded-lg text-muted-foreground hover:bg-accent"
                                >
                                    <MoreHorizontal className="h-4 w-4" />
                                </button>
                            </div>
                        );
                    })
                )}
            </div>

            {checklists.last_page > 1 && (
                <div className="flex justify-end">
                    <LaravelPagination links={checklists.links} />
                </div>
            )}
        </div>
    );
}

function BulkBtn({ onClick, children }: { onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded-lg border border-border bg-card px-2.5 py-1.5 text-[12.5px] font-semibold hover:bg-muted"
        >
            {children}
        </button>
    );
}

/* ====================================================================== */
/*  Templates tab                                                         */
/* ====================================================================== */

function TemplatesTab({
    templates,
    canManage,
    onNew,
    onEdit,
    onDuplicate,
    onMenu,
}: {
    templates: TemplateRow[];
    canManage: boolean;
    onNew: () => void;
    onEdit: (t: TemplateRow) => void;
    onDuplicate: (t: TemplateRow) => void;
    onMenu: (t: TemplateRow) => (e: React.MouseEvent) => void;
}) {
    return (
        <div className="flex flex-col gap-3.5">
            <div className="flex items-center justify-between">
                <p className="text-[13px] text-muted-foreground">Reusable task sets matched to a role when you start an onboarding.</p>
                {canManage && (
                    <button type="button" onClick={onNew} className="inline-flex h-9 items-center gap-1.5 rounded-[10px] bg-primary px-3.5 text-[13px] font-semibold text-primary-foreground">
                        <Plus className="h-4 w-4" /> New template
                    </button>
                )}
            </div>

            {templates.length === 0 ? (
                <div className="rounded-2xl border border-dashed border-border py-16 text-center text-sm text-muted-foreground">
                    No onboarding templates configured.
                </div>
            ) : (
                <div className="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3">
                    {templates.map((t) => (
                        <div
                            key={t.id}
                            onContextMenu={canManage ? onMenu(t) : undefined}
                            className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4.5 transition-shadow hover:shadow-md"
                        >
                            <div className="flex items-start justify-between gap-2.5">
                                <div>
                                    <div className="text-[15px] font-bold">{prettyLabel(t.role)}</div>
                                    <div className="mt-0.5 text-[12px] text-muted-foreground">{prettyLabel(t.site_type)}</div>
                                </div>
                                <StatusBadge variant={t.is_active ? 'success' : 'neutral'} size="sm">
                                    {t.is_active ? 'Active' : 'Inactive'}
                                </StatusBadge>
                            </div>
                            <div className="flex items-center gap-4 text-[12px] text-muted-foreground">
                                <span>
                                    <b className="text-[14px] text-foreground">{t.task_count}</b> tasks
                                </span>
                                <span>Updated {formatShort(t.updated_at)}</span>
                            </div>
                            <div className="flex flex-wrap gap-1.5">
                                {t.chips.map((c) => (
                                    <span key={c} className="rounded-full bg-muted px-2 py-0.5 text-[10.5px] font-semibold text-muted-foreground">
                                        {c}
                                    </span>
                                ))}
                            </div>
                            {canManage && (
                                <div className="mt-1 flex gap-2 border-t border-border pt-3">
                                    <button type="button" onClick={() => onEdit(t)} className="flex-1 rounded-lg bg-muted py-1.5 text-[12.5px] font-semibold hover:bg-accent">
                                        Edit
                                    </button>
                                    <button type="button" onClick={() => onDuplicate(t)} className="flex-1 rounded-lg bg-muted py-1.5 text-[12.5px] font-semibold hover:bg-accent">
                                        Duplicate
                                    </button>
                                    <button type="button" onClick={onMenu(t)} aria-label="Template actions" className="grid w-9 place-items-center rounded-lg bg-muted text-muted-foreground hover:bg-accent">
                                        <MoreHorizontal className="h-4 w-4" />
                                    </button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ====================================================================== */
/*  Emails tab                                                            */
/* ====================================================================== */

function EmailsTab({
    emails,
    canManage,
    onNew,
    onEdit,
    onMenu,
}: {
    emails: Props['emails'];
    canManage: boolean;
    onNew: () => void;
    onEdit: (e: EmailTemplate) => void;
    onMenu: (e: EmailTemplate) => (ev: React.MouseEvent) => void;
}) {
    const logBadge: Record<string, { variant: 'success' | 'warning' | 'critical' | 'neutral'; label: string }> = {
        sent: { variant: 'success', label: 'Sent' },
        scheduled: { variant: 'warning', label: 'Scheduled' },
        failed: { variant: 'critical', label: 'Failed' },
    };
    return (
        <div className="grid gap-4 lg:grid-cols-[1.1fr_1fr]">
            <div className="flex flex-col gap-3.5">
                <div className="flex items-center justify-between">
                    <span className="text-[14px] font-bold">Email templates</span>
                    {canManage && (
                        <button type="button" onClick={onNew} className="inline-flex h-8.5 items-center gap-1.5 rounded-[9px] bg-primary px-3 text-[12.5px] font-semibold text-primary-foreground">
                            <Plus className="h-3.5 w-3.5" /> New
                        </button>
                    )}
                </div>
                <div className="overflow-hidden rounded-2xl border border-border bg-card">
                    {emails.templates.length === 0 ? (
                        <div className="px-4 py-8 text-center text-sm text-muted-foreground">No email templates yet.</div>
                    ) : (
                        emails.templates.map((e) => (
                            <div
                                key={e.id}
                                onContextMenu={canManage ? onMenu(e) : undefined}
                                className="flex items-center gap-3 border-b border-border/55 px-4 py-3 last:border-0 hover:bg-muted"
                            >
                                <span className="grid h-8.5 w-8.5 flex-none place-items-center rounded-[9px] bg-accent text-primary">
                                    <Mail className="h-4 w-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13.5px] font-semibold">{e.template_name}</div>
                                    <div className="truncate text-[11.5px] text-muted-foreground">
                                        Sent {e.send_days_before_start} day{e.send_days_before_start === 1 ? '' : 's'} before start
                                    </div>
                                </div>
                                <StatusBadge variant={e.is_active ? 'success' : 'neutral'} size="sm">
                                    {e.is_active ? 'Active' : 'Inactive'}
                                </StatusBadge>
                                {canManage && (
                                    <>
                                        <button type="button" onClick={() => onEdit(e)} className="text-[12px] font-semibold text-primary">
                                            Edit
                                        </button>
                                        <button type="button" onClick={onMenu(e)} aria-label="Email actions" className="grid h-6.5 w-6.5 place-items-center rounded-lg text-muted-foreground hover:bg-accent">
                                            <MoreHorizontal className="h-3.5 w-3.5" />
                                        </button>
                                    </>
                                )}
                            </div>
                        ))
                    )}
                </div>
            </div>

            <div className="flex flex-col gap-3.5">
                <span className="text-[14px] font-bold">Sent log</span>
                <div className="overflow-hidden rounded-2xl border border-border bg-card">
                    {emails.log.length === 0 ? (
                        <div className="px-4 py-8 text-center text-sm text-muted-foreground">No emails sent yet.</div>
                    ) : (
                        emails.log.map((l) => (
                            <div key={l.id} className="flex items-center gap-3 border-b border-border/55 px-4 py-3 last:border-0">
                                <div className="min-w-0 flex-1">
                                    <div className="text-[13px] font-semibold">{l.template}</div>
                                    <div className="text-[11.5px] text-muted-foreground">to {l.to} · {l.when}</div>
                                </div>
                                <StatusBadge
                                    variant={logBadge[l.status]?.variant ?? 'neutral'}
                                    size="sm"
                                >
                                    {logBadge[l.status]?.label ?? prettyLabel(l.status)}
                                </StatusBadge>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </div>
    );
}
