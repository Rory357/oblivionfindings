import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    CSSProperties,
    ReactElement,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { Button as GuardrailButton } from '@/components/ui/button';

/* ===================================================================== */
/* Types                                                                  */
/* ===================================================================== */

type FlagLevel = 'red' | 'amber' | 'none';

type LatestAction = {
    action: 'acknowledge' | 'snooze' | 'dismiss';
    actor_name: string | null;
    snooze_until: string | null;
    reason: string | null;
    created_at: string | null;
};

type FlaggedStaff = {
    user_id: number;
    name: string | null;
    position_title: string | null;
    site_name: string | null;
    flag_level: FlagLevel;
    triggered_rules: string[];
    metrics: {
        overtime_hours: number;
        consecutive_days_worked: number;
        sick_leave_days_30d: number;
        sick_leave_days_90d: number;
        shifts_worked_7d: number;
        average_shift_length_hours: number;
    };
    last_checkin_at: string | null;
    open_plan_count: number;
    latest_action: LatestAction | null;
    period_start: string | null;
    period_end: string | null;
};

type SurveyQuestion = {
    question_type: 'enps' | 'scale' | 'text' | 'choice' | 'boolean';
    question_text: string;
    options: string[];
    is_required: boolean;
    sort_order: number;
};

type Survey = {
    id: number;
    title: string;
    description: string | null;
    survey_type: 'pulse' | 'enps' | 'engagement';
    status: 'draft' | 'published' | 'closed' | 'archived';
    is_anonymous: boolean;
    audience_type: 'all' | 'site';
    audience_site_ids: number[];
    starts_at: string | null;
    ends_at: string | null;
    window: string;
    closes_in_days: number | null;
    question_count: number;
    questions: SurveyQuestion[];
    response_count: number;
    recipient_count: number;
    response_pct: number;
    enps: number | null;
    has_responded: boolean;
};

type PlanNote = { id: number; author: string; kind: string; body: string; created_human: string | null };

type ActionPlan = {
    id: number;
    title: string;
    description: string | null;
    priority: 'low' | 'medium' | 'high';
    status: 'open' | 'in_progress' | 'completed' | 'cancelled';
    progress_percent: number;
    due_date: string | null;
    days_until_due: number | null;
    is_overdue: boolean;
    is_due_soon: boolean;
    can_update: boolean;
    owner: { id: number; name: string } | null;
    survey: { id: number; title: string } | null;
    staff: { id: number; name: string } | null;
    source_type: string | null;
    link_label: string;
    notes: PlanNote[];
};

type Sla = {
    open_total: number;
    overdue: number;
    due_today: number;
    due_next_7_days: number;
    high_priority_overdue: number;
    avg_progress_open: number;
    completed_last_30_days: number;
};

type OwnerWorkload = {
    owner_user_id: number;
    owner_name: string | null;
    open_count: number;
    overdue_count: number;
    due_next_7_days: number;
    avg_progress_percent: number;
};

type Need = { key: string; label: string; tab: TabKey };
type Template = {
    key: string;
    name: string;
    survey_type: Survey['survey_type'];
    description: string;
    questions: Array<{ question_type: SurveyQuestion['question_type']; question_text: string; is_required: boolean; options: string[]; sort_order: number }>;
};
type SiteOption = { id: number; name: string; staff_count: number };
type OwnerOption = { id: number; name: string };
type MySurvey = { id: number; title: string; is_anonymous: boolean; closes_in_days: number | null; open: boolean; responded: boolean };
type MyCheckin = { id: number; type: string; manager: string | null; notes: string | null; created_human: string | null; acknowledged: boolean };
type My = { name: string; surveys: MySurvey[]; checkins: MyCheckin[] };

type Summary = {
    total_staff: number;
    flagged_red: number;
    flagged_amber: number;
    healthy: number;
    open_plans: number;
    overdue: number;
    enps: number | null;
    needAttention: number;
    greenPct: number;
};

type PageProps = {
    wellbeingSummary: Summary;
    flaggedStaff: FlaggedStaff[];
    surveys: Survey[];
    liveSurvey: Survey | null;
    actionPlans: ActionPlan[];
    slaSummary: Sla;
    ownerWorkload: OwnerWorkload[];
    actionPlanOwners: OwnerOption[];
    staffOptions: OwnerOption[];
    needs: Need[];
    templates: Template[];
    sites: SiteOption[];
    activeStaffCount: number;
    tenantTrend: Array<{ period_end: string | null; red: number; amber: number; total: number }>;
    my: My;
    can: { manage: boolean };
};

type TabKey = 'overview' | 'surveys' | 'plans' | 'signals';
type ModalKind = 'survey' | 'respond' | 'plan' | 'triage' | 'checkin' | 'eap' | 'createPlan' | null;

/* ===================================================================== */
/* Theme + helpers                                                        */
/* ===================================================================== */

const THEME: CSSProperties = {
    // oklch palette ported from the design prototype
    ['--primary' as string]: 'oklch(51.1% 0.262 277)',
    ['--primary-fg' as string]: '#fff',
    ['--bg' as string]: 'oklch(0.98 0.006 277)',
    ['--card' as string]: '#fff',
    ['--fg' as string]: 'oklch(0.15 0.015 277)',
    ['--muted' as string]: 'oklch(0.96 0.006 277)',
    ['--muted-fg' as string]: 'oklch(0.33 0.015 277)',
    ['--border' as string]: 'oklch(0.92 0.010 277)',
    ['--accent' as string]: 'oklch(94% 0.030 277)',
    ['--ok' as string]: 'oklch(45% 0.15 150)',
    ['--ok-bg' as string]: 'oklch(94% 0.05 150)',
    ['--warn' as string]: 'oklch(42% 0.13 85)',
    ['--warn-bg' as string]: 'oklch(95% 0.06 85)',
    ['--crit' as string]: 'oklch(45% 0.22 25)',
    ['--crit-bg' as string]: 'oklch(94% 0.06 25)',
    ['--amber' as string]: 'oklch(0.86 0.13 90)',
    ['--hero' as string]:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    color: 'var(--fg)',
    fontSize: 14,
};

const CSS = `
@keyframes wbIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
@keyframes wbModal{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
@keyframes wbFall{to{transform:translateY(110vh) rotate(720deg);opacity:0}}
@keyframes wbPop{from{opacity:0;transform:scale(.9)}to{opacity:1;transform:none}}
.wbtab:hover{background:var(--accent)}
.wbrow:hover{background:var(--accent)}
.wbrow:hover .wbdots{opacity:1}
.wbqa:hover{background:rgba(255,255,255,.22)}
.wbprimary:hover{transform:scale(1.02)}
.wbcard:hover{box-shadow:0 12px 30px -16px rgba(20,10,50,.4);transform:translateY(-2px)}
.wbmi:hover{background:var(--accent)}
.wbstat:hover{background:rgba(255,255,255,.1)}
.wbghost:hover{background:var(--muted)}
.wbseg:hover{color:var(--fg)}
.wbfield{width:100%;height:38px;border:1px solid var(--border);border-radius:9px;padding:0 12px;font-size:13.5px;font-family:inherit;background:var(--card);color:var(--fg)}
.wbarea{width:100%;min-height:90px;border:1px solid var(--border);border-radius:9px;padding:10px 12px;font-size:13.5px;font-family:inherit;background:var(--card);color:var(--fg);resize:vertical}
`;

const initials = (n: string | null) =>
    (n ?? '?').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();

const avatar = (level: FlagLevel) => {
    if (level === 'red') return { bg: 'var(--crit-bg)', fg: 'var(--crit)' };
    if (level === 'amber') return { bg: 'var(--warn-bg)', fg: 'var(--warn)' };
    return { bg: 'var(--accent)', fg: 'var(--primary)' };
};

const levelPill = (level: FlagLevel) => {
    if (level === 'red')
        return { bg: 'var(--crit-bg)', fg: 'var(--crit)', label: 'Red · needs a kōrero', edge: 'var(--crit)', ruleBg: 'var(--crit-bg)', ruleFg: 'var(--crit)' };
    if (level === 'amber')
        return { bg: 'var(--warn-bg)', fg: 'var(--warn)', label: 'Amber · keep an eye', edge: 'var(--warn)', ruleBg: 'var(--warn-bg)', ruleFg: 'var(--warn)' };
    return { bg: 'var(--ok-bg)', fg: 'var(--ok)', label: 'Green', edge: 'var(--ok)', ruleBg: 'var(--muted)', ruleFg: 'var(--muted-fg)' };
};

const typeStyle = (t: Survey['survey_type']) => {
    if (t === 'enps') return { bg: 'var(--accent)', fg: 'var(--primary)', short: 'eNPS' };
    if (t === 'engagement') return { bg: 'var(--warn-bg)', fg: 'var(--warn)', short: 'ENG' };
    return { bg: 'var(--ok-bg)', fg: 'var(--ok)', short: 'PULSE' };
};

const statusStyle = (s: Survey['status']) => {
    if (s === 'published') return { bg: 'var(--ok-bg)', fg: 'var(--ok)', label: 'Published' };
    if (s === 'draft') return { bg: 'var(--muted)', fg: 'var(--muted-fg)', label: 'Draft' };
    if (s === 'archived') return { bg: 'oklch(0.92 0.01 277)', fg: 'var(--muted-fg)', label: 'Archived' };
    return { bg: 'oklch(0.92 0.01 277)', fg: 'var(--muted-fg)', label: 'Closed' };
};

const prioStyle = (p: ActionPlan['priority']) => {
    if (p === 'high') return { bg: 'var(--crit-bg)', fg: 'var(--crit)' };
    if (p === 'medium') return { bg: 'var(--warn-bg)', fg: 'var(--warn)' };
    return { bg: 'var(--muted)', fg: 'var(--muted-fg)' };
};

const fmtRelDays = (days: number | null) => {
    if (days === null) return '';
    if (days < 0) return `Overdue · ${Math.abs(days)} ${Math.abs(days) === 1 ? 'day' : 'days'}`;
    if (days === 0) return 'Due today';
    return `Due in ${days} ${days === 1 ? 'day' : 'days'}`;
};

const fmtCheckin = (iso: string | null) => {
    if (!iso) return 'No check-in logged yet';
    const d = new Date(iso);
    const days = Math.round((Date.now() - d.getTime()) / 86400000);
    if (days <= 0) return 'Last check-in today';
    return `Last check-in ${days} ${days === 1 ? 'day' : 'days'} ago`;
};

const ICON: Record<string, ReactElement> = {
    heart: <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z" />,
    plus: <path d="M12 5v14M5 12h14" />,
    check: <path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />,
    chat: <><path d="M14 9a2 2 0 0 1-2 2H6l-4 4V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2z" /><path d="M18 9h2a2 2 0 0 1 2 2v11l-4-4h-6a2 2 0 0 1-2-2v-1" /></>,
    grid: <><rect x="3" y="3" width="7" height="9" /><rect x="14" y="3" width="7" height="5" /><rect x="14" y="12" width="7" height="9" /><rect x="3" y="16" width="7" height="5" /></>,
    bars: <><path d="M3 3v18h18" /><rect x="7" y="9" width="3" height="9" /><rect x="14" y="5" width="3" height="13" /></>,
    alert: <><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><path d="M12 9v4M12 17h.01" /></>,
    info: <><circle cx="12" cy="12" r="10" /><path d="M12 16v-4M12 8h.01" /></>,
    lock: <><rect x="3" y="11" width="18" height="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></>,
    star: <path d="M12 2 15 9l7 .5-5.5 4.5L18 21l-6-4-6 4 1.5-7L2 9.5 9 9z" />,
    tick: <path d="M20 6 9 17l-5-5" />,
    dots: <><circle cx="5" cy="12" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="19" cy="12" r="2" /></>,
    x: <path d="M18 6 6 18M6 6l12 12" />,
};

function Svg({ name, size = 16, sw = 2, fill = 'none' }: { name: string; size?: number; sw?: number; fill?: string }) {
    return (
        <svg width={size} height={size} viewBox="0 0 24 24" fill={fill} stroke={fill === 'none' ? 'currentColor' : 'none'} strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round">
            {ICON[name]}
        </svg>
    );
}

/* ===================================================================== */
/* Component                                                              */
/* ===================================================================== */

export default function WellbeingIndex(props: PageProps) {
    const {
        wellbeingSummary: summary,
        flaggedStaff,
        surveys,
        liveSurvey,
        actionPlans,
        slaSummary,
        ownerWorkload,
        staffOptions,
        needs,
        templates,
        sites,
        activeStaffCount,
        my,
        can,
    } = props;

    const canManage = can.manage;
    const [employee, setEmployee] = useState(false);
    const [tab, setTab] = useState<TabKey>('overview');
    const [surveyFilter, setSurveyFilter] = useState<'all' | 'published' | 'draft' | 'closed'>('all');
    const [planFilter, setPlanFilter] = useState<'all' | 'open' | 'overdue' | 'completed'>('all');

    const [toast, setToast] = useState<{ msg: string; undo?: () => void } | null>(null);
    const toastTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const flash = useCallback((msg: string, undo?: () => void) => {
        setToast({ msg, undo });
        if (toastTimer.current) clearTimeout(toastTimer.current);
        toastTimer.current = setTimeout(() => setToast(null), 4000);
    }, []);
    useEffect(() => () => { if (toastTimer.current) clearTimeout(toastTimer.current); }, []);

    const [ctx, setCtx] = useState<{ x: number; y: number; items: CtxItem[] } | null>(null);
    const showCtx = (e: React.MouseEvent, items: CtxItem[]) => {
        e.preventDefault();
        e.stopPropagation();
        setCtx({ x: e.clientX, y: e.clientY, items });
    };

    const confetti = useCallback(() => {
        const cols = ['oklch(51.1% 0.262 277)', 'oklch(0.86 0.13 90)', 'oklch(45% 0.15 150)', 'oklch(0.7 0.22 25)'];
        for (let i = 0; i < 60; i++) {
            const d = document.createElement('div');
            const s = 6 + Math.random() * 7;
            d.style.cssText = `position:fixed;z-index:200;top:-12px;left:${Math.random() * 100}vw;width:${s}px;height:${s}px;background:${cols[i % 4]};border-radius:${Math.random() > 0.5 ? '50%' : '2px'};pointer-events:none;animation:wbFall ${1.6 + Math.random() * 1.3}s linear ${Math.random() * 0.3}s forwards`;
            document.body.appendChild(d);
            setTimeout(() => d.remove(), 3400);
        }
    }, []);

    // ---- modal state ----
    const [modal, setModal] = useState<ModalKind>(null);
    const [step, setStep] = useState(0);
    const [subject, setSubject] = useState<FlaggedStaff | ActionPlan | Survey | null>(null);
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState<Record<string, unknown>>({});
    const setF = (patch: Record<string, unknown>) => setForm((f) => ({ ...f, ...patch }));

    const openModal = (kind: ModalKind, subj: typeof subject = null, initial: Record<string, unknown> = {}) => {
        setModal(kind);
        setSubject(subj);
        setStep(0);
        setForm(initial);
    };
    const closeModal = () => { setModal(null); setSubject(null); setStep(0); setBusy(false); };

    const go = (t: TabKey) => { setEmployee(false); setTab(t); };

    /* ---- Inertia helpers ---- */
    const post = (url: string, data: Record<string, unknown>, okMsg: string, opts: { celebrate?: boolean; onDone?: () => void } = {}) => {
        setBusy(true);
        router.post(url, data as never, {
            preserveScroll: true,
            onSuccess: () => {
                if (opts.celebrate) confetti();
                flash(okMsg);
                closeModal();
                opts.onDone?.();
            },
            onError: () => flash('Something went wrong — please check the form.'),
            onFinish: () => setBusy(false),
        });
    };
    const put = (url: string, data: Record<string, unknown>, okMsg: string, opts: { celebrate?: boolean } = {}) => {
        setBusy(true);
        router.put(url, data as never, {
            preserveScroll: true,
            onSuccess: () => { if (opts.celebrate) confetti(); flash(okMsg); closeModal(); },
            onError: () => flash('Something went wrong — please check the form.'),
            onFinish: () => setBusy(false),
        });
    };
    const simplePost = (url: string, data: Record<string, unknown>, okMsg: string, undo?: () => void) => {
        router.post(url, data as never, { preserveScroll: true, onSuccess: () => flash(okMsg, undo) });
    };

    /* ---- derived ---- */
    const atRisk = useMemo(
        () => flaggedStaff.filter((p) => p.flag_level === 'red' || p.latest_action === null).slice(0, 3),
        [flaggedStaff],
    );
    const surveyTabCount = useMemo(() => surveys.filter((s) => s.status === 'published').length, [surveys]);
    const filteredSurveys = useMemo(() => {
        if (surveyFilter === 'all') return surveys;
        return surveys.filter((s) => s.status === surveyFilter);
    }, [surveys, surveyFilter]);
    const filteredPlans = useMemo(() => {
        if (planFilter === 'all') return actionPlans;
        if (planFilter === 'open') return actionPlans.filter((p) => p.status === 'open' || p.status === 'in_progress');
        if (planFilter === 'overdue') return actionPlans.filter((p) => p.is_overdue);
        return actionPlans.filter((p) => p.status === 'completed');
    }, [actionPlans, planFilter]);

    const ringC = 2 * Math.PI * 42;
    const ringDash = `${((summary.greenPct / 100) * ringC).toFixed(1)} ${ringC.toFixed(1)}`;

    /* ---- context menu builders ---- */
    const surveyMenu = (s: Survey): CtxItem[] => {
        const items: CtxItem[] = [{ label: 'Open results', onClick: () => router.visit(`/hr/wellbeing/surveys/${s.id}`) }];
        if (canManage) {
            if (s.status === 'draft') {
                items.push({ label: 'Edit', onClick: () => openSurveyBuilder(s) });
                items.push({ label: 'Publish now', onClick: () => simplePost(`/hr/wellbeing/surveys/${s.id}/publish`, {}, 'Published — invitations sent') });
            }
            if (s.status === 'published') {
                items.push({ label: 'Close survey', onClick: () => simplePost(`/hr/wellbeing/surveys/${s.id}/close`, {}, 'Survey closed') });
                items.push({ label: 'Nudge non-responders', onClick: () => simplePost(`/hr/wellbeing/surveys/${s.id}/nudge`, {}, 'Nudging non-responders…') });
            }
            items.push({ label: 'Duplicate', onClick: () => simplePost(`/hr/wellbeing/surveys/${s.id}/duplicate`, {}, 'Duplicated as draft') });
            items.push({ label: 'Export results (CSV)', onClick: () => window.open(`/hr/wellbeing/surveys/${s.id}/export`, '_blank') });
            if (s.status === 'closed') items.push({ label: 'Archive', onClick: () => simplePost(`/hr/wellbeing/surveys/${s.id}/archive`, {}, 'Survey archived') });
            if (s.status === 'draft') items.push({ label: 'Archive draft', onClick: () => router.delete(`/hr/wellbeing/surveys/${s.id}`, { preserveScroll: true, onSuccess: () => flash('Draft archived') }) });
        }
        return items;
    };

    const planMenu = (p: ActionPlan): CtxItem[] => {
        const items: CtxItem[] = [{ label: 'Open / update', onClick: () => openPlanUpdate(p) }];
        if (p.can_update) {
            if (p.status !== 'completed') {
                items.push({ label: 'Mark complete', onClick: () => put(`/hr/wellbeing/action-plans/${p.id}`, { status: 'completed' }, 'Action plan completed', { celebrate: true }) });
            } else if (canManage) {
                items.push({ label: 'Reopen', onClick: () => simplePost(`/hr/wellbeing/action-plans/${p.id}/reopen`, {}, 'Plan reopened') });
            }
            if (canManage && p.status !== 'cancelled') {
                items.push({ label: 'Cancel plan', color: 'var(--crit)', onClick: () => simplePost(`/hr/wellbeing/action-plans/${p.id}/cancel`, {}, 'Plan cancelled') });
            }
        }
        return items;
    };

    const flagMenu = (p: FlaggedStaff): CtxItem[] => [
        { label: 'Log check-in', onClick: () => openModal('checkin', p) },
        { label: 'Create action plan', onClick: () => openModal('createPlan', p) },
        { label: 'Refer to EAP', onClick: () => openModal('eap', p) },
        { label: 'Acknowledge', onClick: () => doTriage(p, 'acknowledge') },
        { label: 'Snooze…', onClick: () => openModal('triage', p, { triage: 'snooze' }) },
        { label: 'Dismiss…', color: 'var(--crit)', onClick: () => openModal('triage', p, { triage: 'dismiss' }) },
    ];

    /* ---- openers that prefill forms ---- */
    const openSurveyBuilder = (s?: Survey) => {
        openModal('survey', s ?? null, {
            editId: s?.id ?? null,
            title: s?.title ?? '',
            description: s?.description ?? '',
            surveyType: s?.survey_type ?? 'pulse',
            anon: s?.is_anonymous ?? true,
            audienceType: s?.audience_type ?? 'all',
            siteIds: s?.audience_site_ids ?? [],
            startsAt: s?.starts_at ?? '',
            endsAt: s?.ends_at ?? '',
            questions: s?.questions?.length
                ? s.questions.map((q) => ({ question_type: q.question_type, question_text: q.question_text, is_required: q.is_required, options: q.options }))
                : [{ question_type: 'scale', question_text: 'How are you feeling about work this week?', is_required: true, options: [] }],
        });
    };
    const openPlanUpdate = (p: ActionPlan) => {
        openModal('plan', p, { planStatus: p.status, planProgress: p.progress_percent, planNote: '' });
    };

    const doTriage = (p: FlaggedStaff, action: 'acknowledge' | 'snooze' | 'dismiss', extra: Record<string, unknown> = {}) => {
        simplePost(
            `/hr/wellbeing/signals/${p.user_id}/${action}`,
            extra,
            action === 'acknowledge' ? `Acknowledged — ${p.name}` : action === 'snooze' ? `Snoozed — ${p.name}` : `Dismissed — ${p.name}`,
            action === 'acknowledge' ? () => simplePost(`/hr/wellbeing/signals/${p.user_id}/undo`, {}, 'Undone') : undefined,
        );
    };

    /* ---- modal submit ---- */
    const SURVEY_STEPS = ['Basics', 'Questions', 'Audience', 'Review'];
    const stepCount = modal === 'survey' ? SURVEY_STEPS.length
        : modal === 'checkin' ? 3
        : modal === 'eap' ? 3
        : modal === 'createPlan' ? 3
        : 1;

    const submitModal = () => {
        const f = form;
        if (modal === 'survey') {
            const questions = (f.questions as Array<Record<string, unknown>> ?? []).map((q, i) => ({ ...q, sort_order: i + 1 }));
            const payload = {
                title: f.title,
                description: f.description,
                survey_type: f.surveyType,
                is_anonymous: f.anon,
                audience_type: f.audienceType,
                audience_site_ids: f.audienceType === 'site' ? f.siteIds : [],
                starts_at: f.startsAt || null,
                ends_at: f.endsAt || null,
                questions,
            };
            if (f.editId) {
                put(`/hr/wellbeing/surveys/${f.editId}`, payload, 'Survey saved');
            } else {
                post('/hr/wellbeing/surveys', { ...payload, publish: true }, 'Survey published — invitations sent', { celebrate: true });
            }
        } else if (modal === 'respond') {
            const s = subject as Survey;
            post(`/hr/wellbeing/surveys/${s.id}/responses`, { answers: f.answers ?? {} }, 'Response submitted — anonymously', { celebrate: true });
        } else if (modal === 'plan') {
            const p = subject as ActionPlan;
            put(`/hr/wellbeing/action-plans/${p.id}`, {
                status: f.planStatus,
                progress_percent: f.planProgress,
                note: f.planNote || null,
            }, 'Action plan updated', { celebrate: f.planStatus === 'completed' });
        } else if (modal === 'triage') {
            const p = subject as FlaggedStaff;
            const action = f.triage as 'acknowledge' | 'snooze' | 'dismiss';
            const extra = action === 'snooze' ? { snooze_until: f.snoozeUntil } : action === 'dismiss' ? { reason: f.triageReason } : {};
            doTriage(p, action, extra);
            closeModal();
        } else if (modal === 'checkin') {
            const p = subject as FlaggedStaff | null;
            post('/hr/wellbeing/checkins', {
                staff_user_id: p ? p.user_id : f.staffId,
                type: f.checkinType ?? 'welfare',
                notes: f.checkinNotes ?? '',
                mood: f.mood ?? null,
                follow_up_date: f.followUp || null,
                is_private: f.isPrivate ?? true,
            }, 'Check-in logged');
        } else if (modal === 'eap') {
            const p = subject as FlaggedStaff | null;
            post('/hr/wellbeing/eap-referrals', {
                staff_user_id: p ? p.user_id : f.staffId,
                reason_category: f.eapReason ?? 'wellbeing',
                provider: f.eapProvider ?? '',
                consent_given: f.eapConsent ?? false,
                notes: f.eapNotes ?? '',
            }, 'EAP referral submitted — confidential');
        } else if (modal === 'createPlan') {
            const p = subject as FlaggedStaff | null;
            post('/hr/wellbeing/action-plans', {
                owner_user_id: f.planOwner,
                staff_user_id: p ? p.user_id : null,
                source_type: p ? 'flag' : 'manual',
                title: f.planTitle,
                description: f.planDesc ?? '',
                priority: f.planPriority ?? 'medium',
                due_date: f.planDue || null,
            }, 'Action plan created');
        }
    };

    const nextStep = () => {
        if (step >= stepCount - 1) { submitModal(); return; }
        setStep((s) => s + 1);
    };

    /* ===================================================================== */
    return (
        <AppLayout breadcrumbs={[{ title: 'HR', href: '/hr' }, { title: 'Wellbeing', href: '/hr/wellbeing' }]}>
            <Head title="Wellbeing & Engagement" />
            <style>{CSS}</style>
            <div style={{ ...THEME, padding: '6px 4px 60px' }}>
                {/* top bar */}
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, color: 'var(--muted-fg)', fontWeight: 500 }}>
                        <span>HR</span><span style={{ opacity: 0.5 }}>/</span><span style={{ color: 'var(--fg)', fontWeight: 600 }}>Wellbeing &amp; engagement</span>
                    </div>
                    <div style={{ display: 'inline-flex', gap: 2, background: 'var(--muted)', border: '1px solid var(--border)', borderRadius: 10, padding: 3 }}>
                        <GuardrailButton unstyled className="wbseg" onClick={() => setEmployee(false)} style={seg(!employee)}>Manager</GuardrailButton>
                        <GuardrailButton unstyled className="wbseg" onClick={() => setEmployee(true)} style={seg(employee)}>My HR (employee)</GuardrailButton>
                    </div>
                </div>

                {!employee && (
                    <>
                        <ManagerHero summary={summary} ringDash={ringDash} needs={needs} canManage={canManage}
                            onStat={go} onNewSurvey={() => openSurveyBuilder()} onCheckin={() => openModal('checkin')} onNewPlan={() => openModal('createPlan')} />

                        {/* tabs */}
                        <div role="tablist" style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 4, borderRadius: 14, border: '1px solid var(--border)', background: 'var(--card)', padding: 6, boxShadow: '0 1px 2px rgba(0,0,0,.04)', margin: '16px 0 18px' }}>
                            <TabBtn active={tab === 'overview'} onClick={() => setTab('overview')} icon="grid" label="Overview" />
                            <TabBtn active={tab === 'surveys'} onClick={() => setTab('surveys')} icon="check" label="Surveys" badge={String(surveyTabCount)} />
                            <TabBtn active={tab === 'plans'} onClick={() => setTab('plans')} icon="bars" label="Action plans" badge={slaSummary.overdue > 0 ? `${slaSummary.overdue} overdue` : undefined} badgeTone="warn" />
                            <TabBtn active={tab === 'signals'} onClick={() => setTab('signals')} icon="alert" label="Wellbeing signals" badge={String(summary.flagged_red)} badgeTone="crit" />
                        </div>

                        {tab === 'overview' && (
                            <Overview atRisk={atRisk} sla={slaSummary} liveSurvey={liveSurvey} workload={ownerWorkload}
                                onTriage={(p) => openModal('triage', p)} onAllSignals={() => setTab('signals')} onPlans={() => setTab('plans')}
                                onNudge={(s) => simplePost(`/hr/wellbeing/surveys/${s.id}/nudge`, {}, 'Nudging non-responders…')} />
                        )}
                        {tab === 'surveys' && (
                            <SurveysTab surveys={filteredSurveys} filter={surveyFilter} setFilter={setSurveyFilter} canManage={canManage}
                                onNew={() => openSurveyBuilder()} onPrimary={(s) => s.status === 'draft' ? openSurveyBuilder(s) : router.visit(`/hr/wellbeing/surveys/${s.id}`)} onCtx={(e, s) => showCtx(e, surveyMenu(s))} />
                        )}
                        {tab === 'plans' && (
                            <PlansTab plans={filteredPlans} filter={planFilter} setFilter={setPlanFilter} canManage={canManage}
                                onNew={() => openModal('createPlan')} onUpdate={openPlanUpdate} onCtx={(e, p) => showCtx(e, planMenu(p))} />
                        )}
                        {tab === 'signals' && (
                            <SignalsTab flagged={flaggedStaff} canManage={canManage}
                                onCheckin={(p) => openModal('checkin', p)} onPlan={(p) => openModal('createPlan', p)} onEap={(p) => openModal('eap', p)} onTriage={(p) => openModal('triage', p)} onCtx={(e, p) => showCtx(e, flagMenu(p))} />
                        )}
                    </>
                )}

                {employee && (
                    <EmployeeView my={my} onRespond={(s) => router.visit(`/hr/wellbeing/surveys/${s.id}`)}
                        onAck={(c) => simplePost(`/hr/wellbeing/checkins/${c.id}/acknowledge`, {}, 'Check-in acknowledged')}
                        onKudos={() => { confetti(); router.visit('/hr/feed'); }} />
                )}
            </div>

            {/* toast */}
            {toast && (
                <div style={{ position: 'fixed', bottom: 24, left: '50%', transform: 'translateX(-50%)', zIndex: 120, display: 'flex', alignItems: 'center', gap: 11, background: 'var(--fg)', color: 'var(--bg)', borderRadius: 11, padding: '12px 16px', boxShadow: '0 16px 40px -12px rgba(0,0,0,.5)', animation: 'wbPop .25s', fontSize: 13, fontWeight: 500 }}>
                    <span style={{ display: 'grid', placeItems: 'center', width: 20, height: 20, borderRadius: '50%', background: 'var(--ok)', color: '#fff' }}><Svg name="tick" size={13} sw={3} /></span>
                    {toast.msg}
                    {toast.undo && <GuardrailButton unstyled onClick={() => { toast.undo?.(); setToast(null); }} style={{ border: 'none', background: 'transparent', color: 'var(--amber)', fontWeight: 700, fontSize: 13, cursor: 'pointer', marginLeft: 4 }}>Undo</GuardrailButton>}
                </div>
            )}

            {/* context menu */}
            {ctx && (
                <div onClick={() => setCtx(null)} style={{ position: 'fixed', inset: 0, zIndex: 130 }}>
                    <div style={{ ...THEME, position: 'absolute', top: ctx.y, left: ctx.x, minWidth: 210, background: 'var(--card)', border: '1px solid var(--border)', borderRadius: 11, boxShadow: '0 18px 50px -14px rgba(20,10,50,.4)', padding: 5, animation: 'wbPop .12s' }}>
                        {ctx.items.map((it, i) => (
                            <GuardrailButton unstyled key={i} className="wbmi" onClick={() => { setCtx(null); it.onClick(); }} style={{ display: 'flex', alignItems: 'center', gap: 9, width: '100%', border: 'none', background: 'transparent', borderRadius: 7, padding: '8px 10px', fontSize: 13, fontWeight: 500, cursor: 'pointer', textAlign: 'left', color: it.color ?? 'var(--fg)' }}>{it.label}</GuardrailButton>
                        ))}
                    </div>
                </div>
            )}

            {/* modal */}
            {modal && (
                <Modal
                    kind={modal}
                    step={step}
                    stepCount={stepCount}
                    busy={busy}
                    subject={subject}
                    form={form}
                    setF={setF}
                    surveySteps={SURVEY_STEPS}
                    templates={templates}
                    sites={sites}
                    owners={staffOptions}
                    activeStaffCount={activeStaffCount}
                    onClose={closeModal}
                    onBack={() => setStep((s) => Math.max(0, s - 1))}
                    onNext={nextStep}
                />
            )}
        </AppLayout>
    );
}

/* ===================================================================== */
/* Shared little bits                                                     */
/* ===================================================================== */

type CtxItem = { label: string; onClick: () => void; color?: string };

const seg = (on: boolean): CSSProperties => ({
    borderRadius: 7, padding: '6px 13px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer', border: 'none', transition: '.15s',
    background: on ? 'var(--card)' : 'transparent', color: on ? 'var(--fg)' : 'var(--muted-fg)', boxShadow: on ? '0 1px 2px rgba(0,0,0,.08)' : 'none',
});

function TabBtn({ active, onClick, icon, label, badge, badgeTone }: { active: boolean; onClick: () => void; icon: string; label: string; badge?: string; badgeTone?: 'warn' | 'crit' }) {
    const base: CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 8, borderRadius: 9, padding: '8px 12px', fontSize: 13, fontWeight: 600, cursor: 'pointer', border: 'none', transition: '.15s', background: active ? 'color-mix(in oklch, var(--primary) 10%, transparent)' : 'transparent', color: active ? 'var(--primary)' : 'var(--muted-fg)' };
    const badgeBg = badgeTone === 'warn' ? 'var(--warn-bg)' : badgeTone === 'crit' ? 'var(--crit-bg)' : 'var(--muted)';
    const badgeFg = badgeTone === 'warn' ? 'var(--warn)' : badgeTone === 'crit' ? 'var(--crit)' : 'var(--fg)';
    return (
        <GuardrailButton unstyled className="wbtab" role="tab" onClick={onClick} style={base}>
            <Svg name={icon} size={14} />
            {label}
            {badge !== undefined && <span style={{ display: 'inline-flex', alignItems: 'center', borderRadius: 999, background: badgeBg, color: badgeFg, padding: '2px 7px', fontSize: 10, fontWeight: 700 }}>{badge}</span>}
        </GuardrailButton>
    );
}

function Ring({ size, r, sw, dash, track, color }: { size: number; r: number; sw: number; dash: string; track: string; color: string }) {
    const c = size / 2;
    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} style={{ transform: 'rotate(-90deg)' }}>
            <circle cx={c} cy={c} r={r} fill="none" stroke={track} strokeWidth={sw} />
            <circle cx={c} cy={c} r={r} fill="none" stroke={color} strokeWidth={sw} strokeLinecap="round" strokeDasharray={dash} />
        </svg>
    );
}

/* ===================================================================== */
/* Hero                                                                   */
/* ===================================================================== */

function ManagerHero({ summary, ringDash, needs, canManage, onStat, onNewSurvey, onCheckin, onNewPlan }: {
    summary: Summary; ringDash: string; needs: Need[]; canManage: boolean;
    onStat: (t: TabKey) => void; onNewSurvey: () => void; onCheckin: () => void; onNewPlan: () => void;
}) {
    const statBtn = (label: string, value: ReactElement | string, onClick: () => void, valueColor?: string) => (
        <GuardrailButton unstyled className="wbstat" onClick={onClick} style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start', gap: 2, border: 'none', background: 'transparent', color: 'inherit', cursor: 'pointer', borderRadius: 10, padding: '8px 12px', textAlign: 'left' }}>
            <span style={{ fontSize: 10, fontWeight: 700, letterSpacing: '.09em', textTransform: 'uppercase', color: 'rgba(255,255,255,.6)' }}>{label}</span>
            <span style={{ fontSize: 22, fontWeight: 700, fontVariantNumeric: 'tabular-nums', color: valueColor ?? 'inherit' }}>{value}</span>
        </GuardrailButton>
    );
    return (
        <div style={{ position: 'relative', overflow: 'hidden', borderRadius: 24, background: 'var(--hero)', color: 'var(--primary-fg)', boxShadow: '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)' }}>
            <div style={{ position: 'absolute', top: -80, right: '22%', width: 240, height: 240, borderRadius: '50%', background: 'rgba(255,255,255,.05)', pointerEvents: 'none' }} />
            <div style={{ position: 'relative', display: 'flex', flexWrap: 'wrap', alignItems: 'stretch' }}>
                <div style={{ flex: 1, minWidth: 0, flexBasis: 440, padding: '30px 34px' }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                        <span style={{ display: 'grid', placeItems: 'center', width: 54, height: 54, flex: 'none', borderRadius: 16, border: '1px solid rgba(255,255,255,.2)', background: 'rgba(255,255,255,.15)' }}><Svg name="heart" size={26} /></span>
                        <div style={{ minWidth: 0 }}>
                            <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '.12em', textTransform: 'uppercase', color: 'rgba(255,255,255,.6)' }}>Wellbeing &amp; engagement</div>
                            <h1 style={{ margin: '3px 0 0', fontSize: 28, fontWeight: 800, letterSpacing: '-.02em', lineHeight: 1.05 }}>Looking after the team</h1>
                            <p style={{ margin: '7px 0 0', fontSize: 13, fontWeight: 500, color: 'rgba(255,255,255,.78)', display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap' }}>
                                <span>{summary.total_staff} people</span>
                                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><span style={{ width: 7, height: 7, borderRadius: '50%', background: 'var(--amber)', display: 'inline-block' }} />{summary.needAttention} need a check-in</span>
                                <span>{summary.open_plans} active plans</span>
                            </p>
                        </div>
                    </div>

                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 2, margin: '18px 0 0 -12px' }}>
                        {statBtn('Total staff', String(summary.total_staff), () => onStat('overview'))}
                        {statBtn('Red flags', String(summary.flagged_red), () => onStat('signals'), 'var(--amber)')}
                        {statBtn('Amber flags', String(summary.flagged_amber), () => onStat('signals'))}
                        {statBtn('Open plans', <>{summary.open_plans} <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--amber)' }}>· {summary.overdue} overdue</span></>, () => onStat('plans'))}
                        {statBtn('Latest eNPS', summary.enps === null ? '—' : `${summary.enps > 0 ? '+' : ''}${summary.enps}`, () => onStat('surveys'))}
                    </div>

                    {canManage && (
                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 18 }}>
                            <GuardrailButton unstyled className="wbprimary" onClick={onNewSurvey} style={{ display: 'inline-flex', alignItems: 'center', gap: 8, height: 34, borderRadius: 9, border: 'none', background: 'var(--primary-fg)', color: 'var(--primary)', padding: '0 14px', fontSize: 12.5, fontWeight: 700, cursor: 'pointer', boxShadow: '0 1px 2px rgba(0,0,0,.1)' }}><Svg name="plus" size={15} sw={2.2} />New survey</GuardrailButton>
                            <GuardrailButton unstyled className="wbqa" onClick={onCheckin} style={qaBtn}><Svg name="chat" size={15} />Log check-in</GuardrailButton>
                            <GuardrailButton unstyled className="wbqa" onClick={onNewPlan} style={qaBtn}><Svg name="check" size={15} />New action plan</GuardrailButton>
                        </div>
                    )}

                    {needs.length > 0 && (
                        <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 8, marginTop: 18 }}>
                            <span style={{ fontSize: 10, fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: 'rgba(255,255,255,.5)' }}>Needs you</span>
                            {needs.map((n) => (
                                <GuardrailButton unstyled key={n.key} className="wbqa" onClick={() => onStat(n.tab)} style={{ ...qaBtn, padding: '6px 12px 6px 10px', fontSize: 12 }}>
                                    <span style={{ width: 6, height: 6, flex: 'none', borderRadius: '50%', background: 'var(--amber)', boxShadow: '0 0 0 3px color-mix(in oklch, var(--amber) 32%, transparent)' }} />{n.label}
                                </GuardrailButton>
                            ))}
                        </div>
                    )}
                </div>

                {/* right rail: green ring */}
                <div style={{ width: 300, flex: 'none', display: 'flex', flexDirection: 'column', borderLeft: '1px solid rgba(255,255,255,.15)', background: 'rgba(0,0,0,.08)', padding: '22px 24px', justifyContent: 'center' }}>
                    <div style={{ fontSize: 10, fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: 'rgba(255,255,255,.55)', marginBottom: 6 }}>Team wellbeing</div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 18 }}>
                        <div style={{ position: 'relative', flex: 'none' }}>
                            <Ring size={112} r={42} sw={11} dash={ringDash} track="rgba(255,255,255,.16)" color="var(--amber)" />
                            <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}>
                                <span style={{ fontSize: 24, fontWeight: 800, lineHeight: 1, fontVariantNumeric: 'tabular-nums' }}>{summary.greenPct}%</span>
                                <span style={{ fontSize: 9, fontWeight: 600, color: 'rgba(255,255,255,.6)' }}>no flags</span>
                            </div>
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 8, minWidth: 0 }}>
                            <LegendRow color="var(--ok)" label="Green" value={summary.healthy} />
                            <LegendRow color="var(--amber)" label="Amber" value={summary.flagged_amber} />
                            <LegendRow color="oklch(0.7 0.22 25)" label="Red" value={summary.flagged_red} />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

const qaBtn: CSSProperties = { display: 'inline-flex', alignItems: 'center', gap: 8, height: 34, borderRadius: 9, border: '1px solid rgba(255,255,255,.28)', background: 'rgba(255,255,255,.12)', color: 'var(--primary-fg)', padding: '0 14px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' };

function LegendRow({ color, label, value }: { color: string; label: string; value: number }) {
    return (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12 }}>
            <span style={{ width: 9, height: 9, borderRadius: 3, background: color }} /><span style={{ flex: 1 }}>{label}</span><b style={{ fontVariantNumeric: 'tabular-nums' }}>{value}</b>
        </div>
    );
}

/* ===================================================================== */
/* Cards / panels                                                         */
/* ===================================================================== */

const cardStyle: CSSProperties = { border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 16, padding: '18px 20px' };

function Overview({ atRisk, sla, liveSurvey, workload, onTriage, onAllSignals, onPlans, onNudge }: {
    atRisk: FlaggedStaff[]; sla: Sla; liveSurvey: Survey | null; workload: OwnerWorkload[];
    onTriage: (p: FlaggedStaff) => void; onAllSignals: () => void; onPlans: () => void; onNudge: (s: Survey) => void;
}) {
    const maxOpen = Math.max(1, ...workload.map((w) => w.open_count));
    const respDash = liveSurvey ? `${((liveSurvey.response_pct / 100) * 2 * Math.PI * 31).toFixed(1)} ${(2 * Math.PI * 31).toFixed(1)}` : '0 1';
    return (
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16, animation: 'wbIn .3s' }}>
            <div style={{ ...cardStyle, gridColumn: 'span 2' }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
                        <span style={{ display: 'grid', placeItems: 'center', width: 30, height: 30, borderRadius: 8, background: 'var(--crit-bg)', color: 'var(--crit)' }}><Svg name="alert" size={16} /></span>
                        <h3 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>People who need a check-in</h3>
                    </div>
                    <GuardrailButton unstyled className="wbghost" onClick={onAllSignals} style={{ border: 'none', background: 'transparent', color: 'var(--primary)', fontSize: 12.5, fontWeight: 600, cursor: 'pointer', borderRadius: 7, padding: '5px 9px' }}>View all signals →</GuardrailButton>
                </div>
                {atRisk.length === 0 ? (
                    <Empty text="No one needs a check-in right now. Ka pai!" />
                ) : (
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(280px,1fr))', gap: 12 }}>
                        {atRisk.map((p) => {
                            const a = avatar(p.flag_level), pl = levelPill(p.flag_level);
                            return (
                                <div key={p.user_id} className="wbcard" style={{ border: '1px solid var(--border)', borderRadius: 13, padding: '13px 14px', transition: '.18s' }}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 8 }}>
                                        <span style={{ display: 'grid', placeItems: 'center', width: 36, height: 36, flex: 'none', borderRadius: '50%', fontSize: 13, fontWeight: 700, background: a.bg, color: a.fg }}>{initials(p.name)}</span>
                                        <div style={{ minWidth: 0, flex: 1 }}>
                                            <div style={{ fontSize: 13.5, fontWeight: 700, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{p.name}</div>
                                            <div style={{ fontSize: 11.5, color: 'var(--muted-fg)' }}>{[p.position_title, p.site_name].filter(Boolean).join(' · ')}</div>
                                        </div>
                                        <span style={pillStyle(pl.bg, pl.fg)}>{p.flag_level === 'red' ? 'Red' : 'Amber'}</span>
                                    </div>
                                    <div style={{ fontSize: 12, color: 'var(--muted-fg)', marginBottom: 10, lineHeight: 1.4 }}>{p.triggered_rules[0] ?? 'Flagged'}</div>
                                    <GuardrailButton unstyled className="wbprimary" onClick={() => onTriage(p)} style={{ width: '100%', height: 32, border: 'none', borderRadius: 8, background: 'var(--primary)', color: '#fff', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>Triage</GuardrailButton>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>

            <div style={cardStyle}>
                <h3 style={{ margin: '0 0 14px', fontSize: 15, fontWeight: 700 }}>Action SLA</h3>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr 1fr', gap: 10 }}>
                    <SlaCell value={sla.open_total - sla.overdue - sla.due_next_7_days < 0 ? 0 : sla.open_total - sla.overdue - sla.due_next_7_days} label="On track" color="var(--ok)" onClick={onPlans} />
                    <SlaCell value={sla.due_next_7_days} label="Due soon" color="var(--warn)" onClick={onPlans} />
                    <SlaCell value={sla.overdue} label="Overdue" color="var(--crit)" onClick={onPlans} />
                </div>
                <div style={{ marginTop: 14, fontSize: 11.5, color: 'var(--muted-fg)' }}>{sla.completed_last_30_days} plans completed in the last 30 days.</div>
            </div>

            <div style={cardStyle}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8 }}>
                    <h3 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>Live survey</h3>
                    {liveSurvey?.closes_in_days != null && liveSurvey.closes_in_days >= 0 && (
                        <span style={{ fontSize: 11, fontWeight: 700, color: 'var(--warn)', background: 'var(--warn-bg)', borderRadius: 999, padding: '3px 9px' }}>{liveSurvey.closes_in_days === 0 ? 'Closes today' : `Closes in ${liveSurvey.closes_in_days} ${liveSurvey.closes_in_days === 1 ? 'day' : 'days'}`}</span>
                    )}
                </div>
                {liveSurvey ? (
                    <>
                        <div style={{ fontSize: 13.5, fontWeight: 600, marginBottom: 10 }}>{liveSurvey.title}</div>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                            <div style={{ position: 'relative', flex: 'none' }}>
                                <Ring size={78} r={31} sw={9} dash={respDash} track="var(--muted)" color="var(--primary)" />
                                <div style={{ position: 'absolute', inset: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center' }}><span style={{ fontSize: 17, fontWeight: 800, fontVariantNumeric: 'tabular-nums' }}>{liveSurvey.response_pct}%</span><span style={{ fontSize: 8.5, color: 'var(--muted-fg)' }}>response</span></div>
                            </div>
                            <div style={{ flex: 1, minWidth: 0 }}>
                                <div style={{ fontSize: 12, color: 'var(--muted-fg)' }}>{liveSurvey.response_count} of {liveSurvey.recipient_count} responded</div>
                                <div style={{ fontSize: 12, color: 'var(--muted-fg)', marginTop: 3 }}>{liveSurvey.is_anonymous ? 'Anonymous' : 'Named'} · {liveSurvey.question_count} questions</div>
                                <GuardrailButton unstyled className="wbghost" onClick={() => onNudge(liveSurvey)} style={{ marginTop: 9, border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 8, padding: '6px 11px', fontSize: 12, fontWeight: 600, cursor: 'pointer' }}>Nudge non-responders</GuardrailButton>
                            </div>
                        </div>
                    </>
                ) : <Empty text="No live survey running." />}
            </div>

            <div style={{ ...cardStyle, gridColumn: 'span 2' }}>
                <h3 style={{ margin: '0 0 14px', fontSize: 15, fontWeight: 700 }}>Owner workload</h3>
                {workload.length === 0 ? <Empty text="No open action plans assigned." /> : (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 11 }}>
                        {workload.map((w) => (
                            <div key={w.owner_user_id} style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                <span style={{ display: 'grid', placeItems: 'center', width: 30, height: 30, flex: 'none', borderRadius: '50%', fontSize: 11, fontWeight: 700, background: 'var(--accent)', color: 'var(--primary)' }}>{initials(w.owner_name)}</span>
                                <div style={{ width: 140, flex: 'none', fontSize: 13, fontWeight: 600, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{w.owner_name}</div>
                                <div style={{ flex: 1, height: 9, borderRadius: 999, background: 'var(--muted)', overflow: 'hidden' }}><div style={{ height: '100%', borderRadius: 999, background: 'var(--primary)', width: `${(w.open_count / maxOpen) * 100}%` }} /></div>
                                <div style={{ width: 120, flex: 'none', textAlign: 'right', fontSize: 11.5, color: 'var(--muted-fg)' }}>{w.open_count} open · {w.overdue_count} overdue</div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

function SlaCell({ value, label, color, onClick }: { value: number; label: string; color: string; onClick: () => void }) {
    return (
        <GuardrailButton unstyled className="wbcard" onClick={onClick} style={{ textAlign: 'left', border: '1px solid var(--border)', borderRadius: 11, padding: 12, cursor: 'pointer', background: 'var(--card)', transition: '.18s' }}>
            <div style={{ fontSize: 24, fontWeight: 800, color, fontVariantNumeric: 'tabular-nums' }}>{value}</div>
            <div style={{ fontSize: 11, color: 'var(--muted-fg)', fontWeight: 600, marginTop: 2 }}>{label}</div>
        </GuardrailButton>
    );
}

const pillStyle = (bg: string, fg: string): CSSProperties => ({ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.04em', borderRadius: 999, padding: '3px 9px', background: bg, color: fg });

function Empty({ text }: { text: string }) {
    return <div style={{ fontSize: 13, color: 'var(--muted-fg)', padding: '18px 4px' }}>{text}</div>;
}

function FilterChips<T extends string>({ options, value, onChange }: { options: { v: T; l: string }[]; value: T; onChange: (v: T) => void }) {
    return (
        <div style={{ display: 'inline-flex', gap: 2, background: 'var(--muted)', border: '1px solid var(--border)', borderRadius: 10, padding: 3 }}>
            {options.map((o) => (
                <GuardrailButton unstyled key={o.v} onClick={() => onChange(o.v)} style={{ borderRadius: 7, padding: '6px 12px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer', border: 'none', background: value === o.v ? 'var(--card)' : 'transparent', color: value === o.v ? 'var(--fg)' : 'var(--muted-fg)', boxShadow: value === o.v ? '0 1px 2px rgba(0,0,0,.06)' : 'none' }}>{o.l}</GuardrailButton>
            ))}
        </div>
    );
}

function PrimaryBtn({ onClick, children }: { onClick: () => void; children: React.ReactNode }) {
    return <GuardrailButton unstyled className="wbprimary" onClick={onClick} style={{ display: 'inline-flex', alignItems: 'center', gap: 7, height: 36, borderRadius: 9, border: 'none', background: 'var(--primary)', color: '#fff', padding: '0 15px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}><Svg name="plus" size={15} sw={2.2} />{children}</GuardrailButton>;
}

/* ---- Surveys tab ---- */
function SurveysTab({ surveys, filter, setFilter, canManage, onNew, onPrimary, onCtx }: {
    surveys: Survey[]; filter: 'all' | 'published' | 'draft' | 'closed'; setFilter: (v: 'all' | 'published' | 'draft' | 'closed') => void;
    canManage: boolean; onNew: () => void; onPrimary: (s: Survey) => void; onCtx: (e: React.MouseEvent, s: Survey) => void;
}) {
    return (
        <div style={{ animation: 'wbIn .3s' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
                <FilterChips value={filter} onChange={setFilter} options={[{ v: 'all', l: 'All' }, { v: 'published', l: 'Published' }, { v: 'draft', l: 'Draft' }, { v: 'closed', l: 'Closed' }]} />
                {canManage && <PrimaryBtn onClick={onNew}>New survey</PrimaryBtn>}
            </div>
            <div style={{ border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 16, overflow: 'hidden' }}>
                {surveys.length === 0 ? <Empty text="No surveys yet." /> : surveys.map((s) => {
                    const ts = typeStyle(s.survey_type), ss = statusStyle(s.status);
                    return (
                        <div key={s.id} className="wbrow" onContextMenu={(e) => onCtx(e, s)} style={{ display: 'flex', alignItems: 'center', gap: 14, padding: '15px 18px', borderBottom: '1px solid var(--border)', transition: '.12s', position: 'relative' }}>
                            <span style={{ display: 'grid', placeItems: 'center', width: 38, height: 38, flex: 'none', borderRadius: 10, background: ts.bg, color: ts.fg, fontSize: 10, fontWeight: 800, textTransform: 'uppercase' }}>{ts.short}</span>
                            <div style={{ minWidth: 0, flex: 1 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                                    <span style={{ fontSize: 14, fontWeight: 700 }}>{s.title}</span>
                                    <span style={pillStyle(ss.bg, ss.fg)}>{ss.label}</span>
                                    {s.is_anonymous && <span style={{ fontSize: 11, color: 'var(--muted-fg)', display: 'inline-flex', alignItems: 'center', gap: 3 }}><Svg name="lock" size={11} />Anonymous</span>}
                                </div>
                                <div style={{ fontSize: 11.5, color: 'var(--muted-fg)', marginTop: 3 }}>{s.window} · {s.question_count} questions</div>
                            </div>
                            <div style={{ width: 170, flex: 'none' }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11, color: 'var(--muted-fg)', marginBottom: 4 }}><span>{s.recipient_count ? `${s.response_count}/${s.recipient_count}` : '—'}</span><b style={{ color: 'var(--fg)' }}>{s.response_pct}%</b></div>
                                <div style={{ height: 7, borderRadius: 999, background: 'var(--muted)', overflow: 'hidden' }}><div style={{ height: '100%', borderRadius: 999, background: 'var(--primary)', width: `${s.response_pct}%` }} /></div>
                            </div>
                            <div className="wbdots" style={{ display: 'flex', gap: 4, opacity: 0, transition: '.12s' }}>
                                <GuardrailButton unstyled onClick={() => onPrimary(s)} className="wbghost" style={ghostSm}>{s.status === 'draft' ? 'Edit' : 'Results'}</GuardrailButton>
                                <GuardrailButton unstyled onClick={(e) => onCtx(e, s)} className="wbghost" style={{ ...ghostSm, width: 30, padding: 0, display: 'grid', placeItems: 'center' }}><Svg name="dots" size={16} fill="currentColor" /></GuardrailButton>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

const ghostSm: CSSProperties = { height: 30, border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 8, padding: '0 11px', fontSize: 12, fontWeight: 600, cursor: 'pointer' };

/* ---- Plans tab ---- */
function PlansTab({ plans, filter, setFilter, canManage, onNew, onUpdate, onCtx }: {
    plans: ActionPlan[]; filter: 'all' | 'open' | 'overdue' | 'completed'; setFilter: (v: 'all' | 'open' | 'overdue' | 'completed') => void;
    canManage: boolean; onNew: () => void; onUpdate: (p: ActionPlan) => void; onCtx: (e: React.MouseEvent, p: ActionPlan) => void;
}) {
    return (
        <div style={{ animation: 'wbIn .3s' }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 14 }}>
                <FilterChips value={filter} onChange={setFilter} options={[{ v: 'all', l: 'All' }, { v: 'open', l: 'Open' }, { v: 'overdue', l: 'Overdue' }, { v: 'completed', l: 'Completed' }]} />
                {canManage && <PrimaryBtn onClick={onNew}>New action plan</PrimaryBtn>}
            </div>
            {plans.length === 0 ? <div style={cardStyle}><Empty text="No action plans here." /></div> : (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(330px,1fr))', gap: 14 }}>
                    {plans.map((p) => {
                        const ps = prioStyle(p.priority);
                        const dueFg = p.is_overdue ? 'var(--crit)' : p.is_due_soon ? 'var(--warn)' : p.status === 'completed' ? 'var(--ok)' : 'var(--muted-fg)';
                        const barColor = p.status === 'completed' ? 'var(--ok)' : p.is_overdue ? 'var(--crit)' : 'var(--primary)';
                        const dueText = p.status === 'completed' ? 'Completed' : p.status === 'cancelled' ? 'Cancelled' : fmtRelDays(p.days_until_due);
                        return (
                            <div key={p.id} className="wbcard" onContextMenu={(e) => onCtx(e, p)} style={{ border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 14, padding: 16, transition: '.18s' }}>
                                <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 10, marginBottom: 10 }}>
                                    <div style={{ fontSize: 14, fontWeight: 700, lineHeight: 1.3 }}>{p.title}</div>
                                    <span style={pillStyle(ps.bg, ps.fg)}>{p.priority}</span>
                                </div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 12 }}>
                                    <span style={{ display: 'grid', placeItems: 'center', width: 26, height: 26, borderRadius: '50%', fontSize: 10, fontWeight: 700, background: 'var(--accent)', color: 'var(--primary)' }}>{initials(p.owner?.name ?? null)}</span>
                                    <span style={{ fontSize: 12, color: 'var(--muted-fg)' }}>{p.owner?.name ?? 'Unassigned'}</span>
                                    <span style={{ marginLeft: 'auto', fontSize: 11.5, fontWeight: 600, color: dueFg }}>{dueText}</span>
                                </div>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
                                    <div style={{ flex: 1, height: 8, borderRadius: 999, background: 'var(--muted)', overflow: 'hidden' }}><div style={{ height: '100%', borderRadius: 999, background: barColor, width: `${p.progress_percent}%` }} /></div>
                                    <span style={{ fontSize: 12, fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>{p.progress_percent}%</span>
                                </div>
                                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                                    <span style={{ fontSize: 11, color: 'var(--muted-fg)' }}>{p.link_label}</span>
                                    {p.can_update && <GuardrailButton unstyled className="wbghost" onClick={() => onUpdate(p)} style={ghostSm}>Update</GuardrailButton>}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/* ---- Signals tab ---- */
function SignalsTab({ flagged, canManage, onCheckin, onPlan, onEap, onTriage, onCtx }: {
    flagged: FlaggedStaff[]; canManage: boolean;
    onCheckin: (p: FlaggedStaff) => void; onPlan: (p: FlaggedStaff) => void; onEap: (p: FlaggedStaff) => void; onTriage: (p: FlaggedStaff) => void; onCtx: (e: React.MouseEvent, p: FlaggedStaff) => void;
}) {
    const metricChips = (p: FlaggedStaff) => [
        { value: String(p.metrics.consecutive_days_worked), label: 'consecutive days' },
        { value: `${p.metrics.overtime_hours}h`, label: 'overtime' },
        { value: String(p.metrics.sick_leave_days_90d), label: 'sick days · 90d' },
        { value: `${p.metrics.average_shift_length_hours}h`, label: 'avg shift' },
    ];
    return (
        <div style={{ animation: 'wbIn .3s' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14, fontSize: 12.5, color: 'var(--muted-fg)' }}>
                <Svg name="info" size={15} />
                Flags are computed from roster &amp; leave data. They mean &ldquo;needs a kōrero&rdquo;, not a violation. Sorted by risk.
            </div>
            {flagged.length === 0 ? <div style={cardStyle}><Empty text="No active wellbeing flags. Ka pai!" /></div> : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                    {flagged.map((p) => {
                        const a = avatar(p.flag_level), pl = levelPill(p.flag_level);
                        const acked = p.latest_action !== null;
                        const ackLabel = p.latest_action?.action === 'snooze'
                            ? `Snoozed${p.latest_action.snooze_until ? ` to ${new Date(p.latest_action.snooze_until).toLocaleDateString(undefined, { weekday: 'short' })}` : ''}`
                            : p.latest_action?.action === 'acknowledge' ? `Acknowledged${p.latest_action.actor_name ? ` · ${p.latest_action.actor_name}` : ''}` : '';
                        return (
                            <div key={p.user_id} onContextMenu={(e) => onCtx(e, p)} style={{ border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 16, padding: '18px 20px', borderLeft: `4px solid ${pl.edge}`, opacity: acked ? 0.66 : 1 }}>
                                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 14 }}>
                                    <span style={{ display: 'grid', placeItems: 'center', width: 46, height: 46, flex: 'none', borderRadius: '50%', fontSize: 15, fontWeight: 700, background: a.bg, color: a.fg }}>{initials(p.name)}</span>
                                    <div style={{ flex: 1, minWidth: 0 }}>
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 9, flexWrap: 'wrap' }}>
                                            <span style={{ fontSize: 15.5, fontWeight: 700 }}>{p.name}</span>
                                            <span style={{ fontSize: 12, color: 'var(--muted-fg)' }}>{[p.position_title, p.site_name].filter(Boolean).join(' · ')}</span>
                                            <span style={pillStyle(pl.bg, pl.fg)}>{pl.label}</span>
                                            {acked && <span style={{ fontSize: 10.5, fontWeight: 600, color: 'var(--muted-fg)', display: 'inline-flex', alignItems: 'center', gap: 4 }}><Svg name="tick" size={12} sw={2.5} />{ackLabel}</span>}
                                        </div>
                                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 7, marginTop: 11 }}>
                                            {p.triggered_rules.map((r, i) => (
                                                <span key={i} style={{ fontSize: 11.5, fontWeight: 600, borderRadius: 8, padding: '5px 10px', background: pl.ruleBg, color: pl.ruleFg }}>{r}</span>
                                            ))}
                                        </div>
                                        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 14, marginTop: 13 }}>
                                            {metricChips(p).map((m, i) => (
                                                <div key={i} style={{ display: 'flex', flexDirection: 'column' }}><span style={{ fontSize: 16, fontWeight: 800, fontVariantNumeric: 'tabular-nums' }}>{m.value}</span><span style={{ fontSize: 10.5, color: 'var(--muted-fg)', fontWeight: 500 }}>{m.label}</span></div>
                                            ))}
                                        </div>
                                        <div style={{ fontSize: 11, color: 'var(--muted-fg)', marginTop: 12 }}>{fmtCheckin(p.last_checkin_at)} · {p.open_plan_count} open action {p.open_plan_count === 1 ? 'plan' : 'plans'}</div>
                                    </div>
                                </div>
                                {canManage && (
                                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 14, paddingTop: 14, borderTop: '1px solid var(--border)' }}>
                                        <GuardrailButton unstyled className="wbprimary" onClick={() => onCheckin(p)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, height: 33, border: 'none', borderRadius: 8, background: 'var(--primary)', color: '#fff', padding: '0 13px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>Log check-in</GuardrailButton>
                                        <GuardrailButton unstyled className="wbghost" onClick={() => onPlan(p)} style={ghostMd}>Create action plan</GuardrailButton>
                                        <GuardrailButton unstyled className="wbghost" onClick={() => onEap(p)} style={ghostMd}>Refer to EAP</GuardrailButton>
                                        <GuardrailButton unstyled className="wbghost" onClick={() => onTriage(p)} style={ghostMd}>Acknowledge / snooze</GuardrailButton>
                                        <GuardrailButton unstyled className="wbghost" onClick={(e) => onCtx(e, p)} style={{ ...ghostMd, marginLeft: 'auto', width: 33, padding: 0, display: 'grid', placeItems: 'center' }}><Svg name="dots" size={16} fill="currentColor" /></GuardrailButton>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

const ghostMd: CSSProperties = { height: 33, border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 8, padding: '0 13px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' };

/* ---- Employee (My HR) ---- */
function EmployeeView({ my, onRespond, onAck, onKudos }: { my: My; onRespond: (s: MySurvey) => void; onAck: (c: MyCheckin) => void; onKudos: () => void }) {
    const needs: { key: string; label: string; onClick: () => void }[] = [];
    const openSurvey = my.surveys.find((s) => s.open);
    if (openSurvey) needs.push({ key: 'resp', label: `Respond to ${openSurvey.title}`, onClick: () => onRespond(openSurvey) });
    const unack = my.checkins.find((c) => !c.acknowledged);
    if (unack) needs.push({ key: 'ack', label: `Acknowledge check-in${unack.manager ? ` from ${unack.manager}` : ''}`, onClick: () => onAck(unack) });
    return (
        <div style={{ animation: 'wbIn .3s' }}>
            <div style={{ position: 'relative', overflow: 'hidden', borderRadius: 24, background: 'var(--hero)', color: 'var(--primary-fg)', padding: '28px 32px', marginBottom: 18 }}>
                <div style={{ fontSize: 11, fontWeight: 700, letterSpacing: '.12em', textTransform: 'uppercase', color: 'rgba(255,255,255,.6)' }}>My HR · Wellbeing</div>
                <h1 style={{ margin: '4px 0 0', fontSize: 25, fontWeight: 800 }}>Kia ora, {my.name}</h1>
                {needs.length > 0 && (
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 16 }}>
                        <span style={{ fontSize: 10, fontWeight: 700, letterSpacing: '.1em', textTransform: 'uppercase', color: 'rgba(255,255,255,.5)', alignSelf: 'center' }}>Needs you</span>
                        {needs.map((n) => (
                            <GuardrailButton unstyled key={n.key} className="wbqa" onClick={n.onClick} style={{ ...qaBtn, padding: '6px 12px 6px 10px', fontSize: 12 }}>
                                <span style={{ width: 6, height: 6, borderRadius: '50%', background: 'var(--amber)', boxShadow: '0 0 0 3px color-mix(in oklch, var(--amber) 32%, transparent)' }} />{n.label}
                            </GuardrailButton>
                        ))}
                    </div>
                )}
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
                <div style={cardStyle}>
                    <h3 style={{ margin: '0 0 13px', fontSize: 15, fontWeight: 700 }}>Your surveys</h3>
                    {my.surveys.length === 0 ? <Empty text="No surveys for you right now." /> : (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 11 }}>
                            {my.surveys.map((s) => (
                                <div key={s.id} style={{ display: 'flex', alignItems: 'center', gap: 12, border: '1px solid var(--border)', borderRadius: 12, padding: '13px 14px' }}>
                                    <div style={{ flex: 1, minWidth: 0 }}>
                                        <div style={{ fontSize: 13.5, fontWeight: 700 }}>{s.title}</div>
                                        <div style={{ fontSize: 11.5, color: 'var(--muted-fg)', marginTop: 2 }}>{s.is_anonymous ? 'Anonymous' : 'Named'}{s.open && s.closes_in_days != null && s.closes_in_days >= 0 ? ` · closes in ${s.closes_in_days} ${s.closes_in_days === 1 ? 'day' : 'days'}` : ''}</div>
                                    </div>
                                    {s.open ? (
                                        <GuardrailButton unstyled className="wbprimary" onClick={() => onRespond(s)} style={{ height: 32, border: 'none', borderRadius: 8, background: 'var(--primary)', color: '#fff', padding: '0 14px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer' }}>Respond</GuardrailButton>
                                    ) : (
                                        <span style={{ fontSize: 12, fontWeight: 600, color: 'var(--ok)', display: 'inline-flex', alignItems: 'center', gap: 5 }}><Svg name="tick" size={14} sw={2.5} />Submitted</span>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </div>
                <div style={cardStyle}>
                    <h3 style={{ margin: '0 0 13px', fontSize: 15, fontWeight: 700 }}>Your check-ins</h3>
                    {my.checkins.length === 0 ? <Empty text="No check-ins shared with you." /> : (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 11 }}>
                            {my.checkins.map((c) => (
                                <div key={c.id} style={{ border: '1px solid var(--border)', borderRadius: 12, padding: '13px 14px', opacity: c.acknowledged ? 0.75 : 1 }}>
                                    <div style={{ display: 'flex', alignItems: 'center', gap: 9, marginBottom: c.notes ? 6 : 0 }}>
                                        <span style={{ display: 'grid', placeItems: 'center', width: 28, height: 28, borderRadius: '50%', fontSize: 10, fontWeight: 700, background: 'var(--accent)', color: 'var(--primary)' }}>{initials(c.manager)}</span>
                                        <div style={{ fontSize: 13, fontWeight: 600 }}>{labelForCheckin(c.type)}{c.manager ? ` · ${c.manager}` : ''}</div>
                                        <span style={{ marginLeft: 'auto', fontSize: 11, color: c.acknowledged ? 'var(--ok)' : 'var(--muted-fg)', fontWeight: c.acknowledged ? 600 : 400 }}>{c.acknowledged ? 'Acknowledged' : c.created_human}</span>
                                    </div>
                                    {c.notes && <div style={{ fontSize: 12, color: 'var(--muted-fg)', lineHeight: 1.45, marginBottom: c.acknowledged ? 0 : 10 }}>{c.notes}</div>}
                                    {!c.acknowledged && <GuardrailButton unstyled className="wbprimary" onClick={() => onAck(c)} style={{ height: 30, border: 'none', borderRadius: 8, background: 'var(--primary)', color: '#fff', padding: '0 13px', fontSize: 12, fontWeight: 600, cursor: 'pointer' }}>Acknowledge</GuardrailButton>}
                                </div>
                            ))}
                        </div>
                    )}
                    <GuardrailButton unstyled className="wbghost" onClick={onKudos} style={{ marginTop: 13, border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 8, padding: '7px 12px', fontSize: 12, fontWeight: 600, cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 6 }}><Svg name="star" size={14} />Send kudos</GuardrailButton>
                </div>
            </div>
        </div>
    );
}

const labelForCheckin = (t: string) => (t === '1on1' ? '1:1' : t === 'return_to_work' ? 'Return to work' : 'Welfare check-in');

/* ===================================================================== */
/* Modal                                                                  */
/* ===================================================================== */

function Modal(props: {
    kind: Exclude<ModalKind, null>; step: number; stepCount: number; busy: boolean;
    subject: FlaggedStaff | ActionPlan | Survey | null; form: Record<string, unknown>; setF: (p: Record<string, unknown>) => void;
    surveySteps: string[]; templates: Template[]; sites: SiteOption[]; owners: OwnerOption[]; activeStaffCount: number;
    onClose: () => void; onBack: () => void; onNext: () => void;
}) {
    const { kind, step, stepCount, busy, subject, form, setF, surveySteps, templates, sites, owners, activeStaffCount, onClose, onBack, onNext } = props;

    const config: Record<string, { w: number; rail: boolean; icon?: string; railTitle?: string; railSub?: string; steps: string[] }> = {
        survey: { w: 980, rail: true, icon: '📋', railTitle: 'Survey builder', railSub: 'Pulse · eNPS · engagement', steps: surveySteps },
        respond: { w: 720, rail: false, steps: ['Respond'] },
        plan: { w: 640, rail: false, steps: ['Update action plan'] },
        triage: { w: 560, rail: false, steps: ['Triage flag'] },
        checkin: { w: 940, rail: true, icon: '💬', railTitle: 'Wellbeing check-in', railSub: subjName(subject), steps: ['Who & type', 'Notes & mood', 'Review'] },
        eap: { w: 940, rail: true, icon: '🤝', railTitle: 'EAP referral', railSub: 'Confidential', steps: ['Staff & reason', 'Provider & consent', 'Review'] },
        createPlan: { w: 940, rail: true, icon: '✓', railTitle: 'New action plan', railSub: subject ? `For ${subjName(subject)}` : 'Standalone', steps: ['Context', 'Plan', 'Review'] },
    };
    const cfg = config[kind];
    const headerLabel = cfg.steps.length > 1 ? `Step ${step + 1} of ${cfg.steps.length} · ${cfg.steps[step]}` : cfg.steps[step];
    const progress = `${(((step + 1) / cfg.steps.length) * 100).toFixed(0)}%`;
    const lastStep = step >= stepCount - 1;
    const nextLabel = lastStep
        ? (kind === 'survey' ? (form.editId ? 'Save' : 'Publish now') : kind === 'respond' ? 'Submit' : kind === 'triage' ? 'Apply' : kind === 'plan' ? 'Save' : 'Confirm')
        : 'Continue';

    return (
        <div onClick={onClose} style={{ position: 'fixed', inset: 0, zIndex: 100, background: 'rgba(20,12,40,.45)', backdropFilter: 'blur(2px)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
            <div onClick={(e) => e.stopPropagation()} style={{ ...THEME, width: cfg.w, maxWidth: '94vw', maxHeight: '92vh', display: 'flex', flexDirection: 'column', background: 'var(--card)', borderRadius: 18, overflow: 'hidden', boxShadow: '0 40px 90px -30px rgba(20,10,50,.6)', animation: 'wbModal .28s' }}>
                <div style={{ display: 'flex', minHeight: 0, flex: 1 }}>
                    {cfg.rail && (
                        <aside style={{ width: 248, flex: 'none', display: 'flex', flexDirection: 'column', gap: 4, borderRight: '1px solid var(--border)', background: 'oklch(0.97 0.005 277)', padding: 16, overflowY: 'auto' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 12 }}>
                                <span style={{ display: 'grid', placeItems: 'center', width: 36, height: 36, borderRadius: 10, background: 'var(--primary)', color: '#fff', fontSize: 18 }}>{cfg.icon}</span>
                                <div><div style={{ fontSize: 13.5, fontWeight: 700, lineHeight: 1.1 }}>{cfg.railTitle}</div><div style={{ fontSize: 11, color: 'var(--muted-fg)' }}>{cfg.railSub}</div></div>
                            </div>
                            {cfg.steps.map((st, i) => (
                                <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, background: i === step ? 'color-mix(in oklch, var(--primary) 10%, transparent)' : 'transparent', borderRadius: 8, padding: 8 }}>
                                    <span style={{ display: 'grid', placeItems: 'center', width: 26, height: 26, flex: 'none', borderRadius: '50%', fontSize: 11, fontWeight: 700, background: i === step ? 'var(--primary)' : i < step ? 'var(--ok-bg)' : 'var(--muted)', color: i === step ? '#fff' : i < step ? 'var(--ok)' : 'var(--muted-fg)' }}>{i < step ? '✓' : i + 1}</span>
                                    <span style={{ fontSize: 13, fontWeight: i === step ? 700 : 600, color: i <= step ? 'var(--fg)' : 'var(--muted-fg)' }}>{st}</span>
                                </div>
                            ))}
                            {kind === 'survey' && (
                                <div style={{ marginTop: 'auto', paddingTop: 16 }}>
                                    <div style={{ border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 11, padding: 12 }}>
                                        <div style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.06em', color: 'var(--muted-fg)', marginBottom: 6 }}>Recipients</div>
                                        <div style={{ fontSize: 20, fontWeight: 800, color: 'var(--primary)', fontVariantNumeric: 'tabular-nums' }}>{recipientCount(form, sites, activeStaffCount)}</div>
                                        <div style={{ fontSize: 11.5, color: 'var(--muted-fg)', marginTop: 2 }}>{form.audienceType === 'site' ? 'selected sites' : 'active staff · all sites'}</div>
                                    </div>
                                </div>
                            )}
                        </aside>
                    )}

                    <div style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
                        <header style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: '1px solid var(--border)', padding: '14px 20px', flex: 'none' }}>
                            <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--muted-fg)' }}>{headerLabel}</div>
                            <GuardrailButton unstyled className="wbghost" onClick={onClose} style={{ width: 32, height: 32, display: 'grid', placeItems: 'center', border: 'none', background: 'transparent', borderRadius: 8, cursor: 'pointer', color: 'var(--muted-fg)' }}><Svg name="x" size={18} /></GuardrailButton>
                        </header>
                        <div style={{ height: 3, background: 'var(--muted)', flex: 'none' }}><div style={{ height: '100%', background: 'var(--primary)', width: progress, transition: 'width .3s' }} /></div>
                        <div style={{ flex: 1, minHeight: 0, overflowY: 'auto', padding: '22px 24px' }}>
                            <ModalBody kind={kind} step={step} subject={subject} form={form} setF={setF} templates={templates} sites={sites} owners={owners} activeStaffCount={activeStaffCount} />
                        </div>
                        <footer style={{ display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: 10, borderTop: '1px solid var(--border)', background: 'var(--muted)', padding: '13px 20px', flex: 'none' }}>
                            {step > 0 && <GuardrailButton unstyled className="wbghost" onClick={onBack} style={{ height: 36, border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 9, padding: '0 15px', fontSize: 13, fontWeight: 600, cursor: 'pointer' }}>Back</GuardrailButton>}
                            <GuardrailButton unstyled className="wbprimary" disabled={busy} onClick={onNext} style={{ height: 36, border: 'none', borderRadius: 9, background: 'var(--primary)', color: '#fff', padding: '0 18px', fontSize: 13, fontWeight: 600, cursor: busy ? 'wait' : 'pointer', opacity: busy ? 0.7 : 1 }}>{busy ? 'Saving…' : nextLabel}</GuardrailButton>
                        </footer>
                    </div>
                </div>
            </div>
        </div>
    );
}

const subjName = (s: FlaggedStaff | ActionPlan | Survey | null): string => {
    if (!s) return '';
    if ('user_id' in s) return s.name ?? '';
    if ('title' in s) return s.title;
    return '';
};

function recipientCount(form: Record<string, unknown>, sites: SiteOption[], activeStaffCount: number): number {
    if (form.audienceType === 'site') {
        const ids = (form.siteIds as number[]) ?? [];
        return sites.filter((s) => ids.includes(s.id)).reduce((sum, s) => sum + s.staff_count, 0);
    }
    return activeStaffCount;
}

/* ---- Modal bodies ---- */

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
    return (
        <div style={{ marginBottom: 18 }}>
            <label style={{ display: 'block', fontSize: 12.5, fontWeight: 600, marginBottom: 7 }}>{label}</label>
            {children}
            {hint && <div style={{ fontSize: 11.5, color: 'var(--muted-fg)', marginTop: 6 }}>{hint}</div>}
        </div>
    );
}

function Head2({ t, s }: { t: string; s: string }) {
    return <div style={{ marginBottom: 18 }}><h2 style={{ margin: 0, fontSize: 19, fontWeight: 800 }}>{t}</h2><p style={{ margin: '5px 0 0', fontSize: 13, color: 'var(--muted-fg)' }}>{s}</p></div>;
}

function Seg<T extends string>({ options, value, onChange }: { options: { v: T; l: string }[]; value: T; onChange: (v: T) => void }) {
    return (
        <div style={{ display: 'inline-flex', gap: 3, background: 'var(--muted)', border: '1px solid var(--border)', borderRadius: 10, padding: 3 }}>
            {options.map((o) => (
                <GuardrailButton unstyled key={o.v} onClick={() => onChange(o.v)} style={{ border: 'none', cursor: 'pointer', borderRadius: 7, padding: '7px 14px', fontSize: 13, fontWeight: 600, background: value === o.v ? 'var(--card)' : 'transparent', color: value === o.v ? 'var(--fg)' : 'var(--muted-fg)', boxShadow: value === o.v ? '0 1px 2px rgba(0,0,0,.08)' : 'none' }}>{o.l}</GuardrailButton>
            ))}
        </div>
    );
}

function Review({ rows }: { rows: [string, string][] }) {
    return (
        <div style={{ border: '1px solid var(--border)', background: 'color-mix(in oklch,var(--card) 70%,transparent)', borderRadius: 13, padding: '6px 16px' }}>
            {rows.map((r, i) => (
                <div key={i} style={{ display: 'flex', justifyContent: 'space-between', gap: 16, padding: '11px 0', borderBottom: i < rows.length - 1 ? '1px solid var(--border)' : 'none' }}>
                    <span style={{ fontSize: 13, color: 'var(--muted-fg)' }}>{r[0]}</span><span style={{ fontSize: 13, fontWeight: 600, textAlign: 'right' }}>{r[1]}</span>
                </div>
            ))}
        </div>
    );
}

function SubjectChip({ subject }: { subject: FlaggedStaff | ActionPlan | Survey | null }) {
    if (!subject || !('user_id' in subject)) return null;
    return (
        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 9, border: '1px solid var(--border)', background: 'var(--muted)', borderRadius: 999, padding: '6px 14px 6px 7px', marginBottom: 18 }}>
            <span style={{ display: 'grid', placeItems: 'center', width: 28, height: 28, borderRadius: '50%', fontSize: 11, fontWeight: 700, background: 'var(--crit-bg)', color: 'var(--crit)' }}>{initials(subject.name)}</span>
            <span><b style={{ fontSize: 13 }}>{subject.name}</b> <span style={{ fontSize: 12, color: 'var(--muted-fg)' }}>· {[subject.position_title, subject.site_name].filter(Boolean).join(' · ')}</span></span>
        </div>
    );
}

function ModalBody({ kind, step, subject, form, setF, templates, sites, owners }: {
    kind: Exclude<ModalKind, null>; step: number; subject: FlaggedStaff | ActionPlan | Survey | null;
    form: Record<string, unknown>; setF: (p: Record<string, unknown>) => void; templates: Template[]; sites: SiteOption[]; owners: OwnerOption[]; activeStaffCount: number;
}) {
    const f = form;

    /* ---- SURVEY BUILDER ---- */
    if (kind === 'survey') {
        const questions = (f.questions as Array<{ question_type: SurveyQuestion['question_type']; question_text: string; is_required: boolean; options: string[] }>) ?? [];
        if (step === 0) return (
            <>
                <Head2 t="Survey basics" s="Give it a clear, plain-English title and choose the type." />
                <Field label="Title"><input className="wbfield" value={String(f.title ?? '')} onChange={(e) => setF({ title: e.target.value })} placeholder="e.g. June pulse — how are you tracking?" /></Field>
                <Field label="Survey type" hint="Pulse = quick mood check · eNPS = recommend score · Engagement = deeper set.">
                    <Seg value={(f.surveyType as string) ?? 'pulse'} onChange={(v) => setF({ surveyType: v })} options={[{ v: 'pulse', l: 'Pulse' }, { v: 'enps', l: 'eNPS' }, { v: 'engagement', l: 'Engagement' }]} />
                </Field>
                <Field label="Anonymity" hint={f.anon ? 'No one — including you — can see who said what. Best for honest answers.' : 'You will see who answered.'}>
                    <GuardrailButton unstyled onClick={() => setF({ anon: !f.anon })} style={{ display: 'inline-flex', alignItems: 'center', gap: 10, border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 10, padding: '10px 14px', cursor: 'pointer', width: '100%', textAlign: 'left' }}>
                        <span style={{ width: 38, height: 22, borderRadius: 999, flex: 'none', background: f.anon ? 'var(--primary)' : 'var(--muted)', position: 'relative', transition: '.2s' }}><span style={{ position: 'absolute', top: 2, left: f.anon ? 18 : 2, width: 18, height: 18, borderRadius: '50%', background: '#fff', transition: '.2s' }} /></span>
                        <span style={{ fontSize: 13, fontWeight: 600 }}>{f.anon ? 'Anonymous responses' : 'Named responses'}</span>
                    </GuardrailButton>
                </Field>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
                    <Field label="Opens"><input className="wbfield" type="date" value={String(f.startsAt ?? '')} onChange={(e) => setF({ startsAt: e.target.value })} /></Field>
                    <Field label="Closes"><input className="wbfield" type="date" value={String(f.endsAt ?? '')} onChange={(e) => setF({ endsAt: e.target.value })} /></Field>
                </div>
            </>
        );
        if (step === 1) return (
            <>
                <Head2 t="Questions" s="Add, reorder and set required. Start from a template if you like." />
                <div style={{ display: 'flex', gap: 8, marginBottom: 16, flexWrap: 'wrap' }}>
                    {templates.map((t) => (
                        <GuardrailButton unstyled key={t.key} onClick={() => setF({ surveyType: t.survey_type, questions: t.questions.map((q) => ({ question_type: q.question_type, question_text: q.question_text, is_required: q.is_required, options: q.options })) })} style={{ border: '1px dashed var(--border)', background: 'var(--card)', borderRadius: 9, padding: '8px 13px', fontSize: 12.5, fontWeight: 600, cursor: 'pointer', color: 'var(--primary)' }}>+ {t.name} template</GuardrailButton>
                    ))}
                </div>
                {questions.map((q, i) => (
                    <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 11, border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 11, padding: '10px 12px', marginBottom: 9 }}>
                        <span style={{ display: 'grid', placeItems: 'center', width: 24, height: 24, borderRadius: 7, background: 'var(--accent)', color: 'var(--primary)', fontSize: 11, fontWeight: 700, flex: 'none' }}>{i + 1}</span>
                        <input className="wbfield" style={{ flex: 1, height: 34, border: 'none', background: 'transparent', padding: 0 }} value={q.question_text} onChange={(e) => updateQuestion(setF, questions, i, { question_text: e.target.value })} placeholder="Question text" />
                        <select className="wbfield" style={{ width: 110, height: 32 }} value={q.question_type} onChange={(e) => updateQuestion(setF, questions, i, { question_type: e.target.value as SurveyQuestion['question_type'] })}>
                            <option value="scale">Scale 1–5</option><option value="enps">eNPS 0–10</option><option value="text">Text</option><option value="boolean">Yes/No</option><option value="choice">Choice</option>
                        </select>
                        <GuardrailButton unstyled onClick={() => setF({ questions: questions.filter((_, j) => j !== i) })} style={{ border: 'none', background: 'transparent', color: 'var(--muted-fg)', cursor: 'pointer' }}><Svg name="x" size={16} /></GuardrailButton>
                    </div>
                ))}
                <GuardrailButton unstyled onClick={() => setF({ questions: [...questions, { question_type: 'scale', question_text: '', is_required: true, options: [] }] })} style={{ width: '100%', border: '1px dashed var(--border)', background: 'transparent', borderRadius: 11, padding: 12, fontSize: 13, fontWeight: 600, color: 'var(--primary)', cursor: 'pointer', marginTop: 4 }}>+ Add question</GuardrailButton>
            </>
        );
        if (step === 2) return (
            <>
                <Head2 t="Audience" s="Who should receive this survey? Recipient count updates live in the rail." />
                <Field label="Send to"><Seg value={(f.audienceType as string) ?? 'all'} onChange={(v) => setF({ audienceType: v })} options={[{ v: 'all', l: 'All staff' }, { v: 'site', l: 'By site' }]} /></Field>
                {f.audienceType === 'site' && (
                    <div style={{ border: '1px solid var(--border)', borderRadius: 12, overflow: 'hidden' }}>
                        {sites.map((s) => {
                            const ids = (f.siteIds as number[]) ?? [];
                            const on = ids.includes(s.id);
                            return (
                                <label key={s.id} style={{ display: 'flex', alignItems: 'center', gap: 11, padding: '12px 14px', borderBottom: '1px solid var(--border)', cursor: 'pointer' }}>
                                    <input type="checkbox" checked={on} onChange={() => setF({ siteIds: on ? ids.filter((x) => x !== s.id) : [...ids, s.id] })} style={{ width: 17, height: 17, accentColor: 'var(--primary)' }} />
                                    <span style={{ flex: 1, fontSize: 13, fontWeight: 500 }}>{s.name}</span>
                                    <span style={{ fontSize: 12, color: 'var(--muted-fg)' }}>{s.staff_count} staff</span>
                                </label>
                            );
                        })}
                        {sites.length === 0 && <Empty text="No sites available." />}
                    </div>
                )}
            </>
        );
        return (
            <>
                <Head2 t="Review & publish" s="Check it over. Publish sends invitations now, or save as a draft." />
                <Review rows={[
                    ['Title', String(f.title || '—')],
                    ['Type', f.surveyType === 'enps' ? 'eNPS' : f.surveyType === 'engagement' ? 'Engagement' : 'Pulse'],
                    ['Anonymous', f.anon ? 'Yes' : 'No'],
                    ['Window', `${f.startsAt || '—'} → ${f.endsAt || '—'}`],
                    ['Questions', String(questions.length)],
                    ['Audience', f.audienceType === 'site' ? `${((f.siteIds as number[]) ?? []).length} sites` : 'All staff'],
                ]} />
            </>
        );
    }

    /* ---- RESPOND ---- */
    if (kind === 'respond') {
        const s = subject as Survey;
        const answers = (f.answers as Record<string, unknown>) ?? {};
        const setAns = (qid: number, val: unknown) => setF({ answers: { ...answers, [qid]: val } });
        return (
            <>
                {s?.is_anonymous && (
                    <div style={{ border: '1px solid color-mix(in oklch,var(--ok) 30%,transparent)', background: 'var(--ok-bg)', color: 'var(--ok)', borderRadius: 12, padding: '12px 14px', fontSize: 12.5, fontWeight: 500, marginBottom: 20, display: 'flex', gap: 9, alignItems: 'flex-start' }}>
                        <Svg name="lock" size={16} /><span>This survey is <b>anonymous</b>. Your answers are never linked to your name — answer honestly.</span>
                    </div>
                )}
                {(s?.questions ?? []).map((q, i) => (
                    <Field key={i} label={`${i + 1} · ${q.question_text}`}>
                        <RespondInput q={q} value={answers[i] as never} onChange={(v) => setAns(i, v)} />
                    </Field>
                ))}
                {(!s?.questions || s.questions.length === 0) && <Empty text="Open the survey page to respond." />}
            </>
        );
    }

    /* ---- PLAN UPDATE ---- */
    if (kind === 'plan') {
        const p = subject as ActionPlan;
        return (
            <>
                <div style={{ fontSize: 16, fontWeight: 700, marginBottom: 4 }}>{p.title}</div>
                <div style={{ fontSize: 12.5, color: 'var(--muted-fg)', marginBottom: 20 }}>Owner: {p.owner?.name ?? 'Unassigned'} · {p.link_label}</div>
                <Field label="Status"><Seg value={(f.planStatus as string) ?? p.status} onChange={(v) => setF({ planStatus: v })} options={[{ v: 'open', l: 'Open' }, { v: 'in_progress', l: 'In progress' }, { v: 'completed', l: 'Completed' }]} /></Field>
                <Field label={`Progress · ${f.planProgress ?? p.progress_percent}%`}>
                    <input type="range" min={0} max={100} value={Number(f.planProgress ?? p.progress_percent)} onChange={(e) => setF({ planProgress: Number(e.target.value) })} style={{ width: '100%', accentColor: 'var(--primary)' }} />
                    <div style={{ height: 9, borderRadius: 999, background: 'var(--muted)', overflow: 'hidden', marginTop: 8 }}><div style={{ height: '100%', borderRadius: 999, background: 'var(--primary)', width: `${f.planProgress ?? p.progress_percent}%` }} /></div>
                </Field>
                <Field label="Add a note"><textarea className="wbarea" value={String(f.planNote ?? '')} onChange={(e) => setF({ planNote: e.target.value })} placeholder="What changed? This shows on the timeline." /></Field>
                <div style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.05em', color: 'var(--muted-fg)', marginBottom: 11 }}>Timeline</div>
                {p.notes.length === 0 ? <Empty text="No activity yet." /> : p.notes.map((n) => (
                    <div key={n.id} style={{ display: 'flex', gap: 11, marginBottom: 13 }}>
                        <span style={{ display: 'grid', placeItems: 'center', width: 28, height: 28, flex: 'none', borderRadius: '50%', fontSize: 10, fontWeight: 700, background: 'var(--accent)', color: 'var(--primary)' }}>{n.kind === 'system' ? '⚙' : initials(n.author)}</span>
                        <div style={{ flex: 1 }}><div style={{ fontSize: 12.5 }}><b>{n.author}</b> <span style={{ color: 'var(--muted-fg)' }}>· {n.created_human}</span></div><div style={{ fontSize: 12.5, color: 'var(--muted-fg)', marginTop: 2 }}>{n.body}</div></div>
                    </div>
                ))}
            </>
        );
    }

    /* ---- TRIAGE ---- */
    if (kind === 'triage') {
        const opt = (v: string, l: string, d: string) => (
            <GuardrailButton unstyled onClick={() => setF({ triage: v })} style={{ display: 'block', width: '100%', textAlign: 'left', border: `1px solid ${f.triage === v ? 'var(--primary)' : 'var(--border)'}`, background: f.triage === v ? 'color-mix(in oklch,var(--primary) 8%,var(--card))' : 'var(--card)', borderRadius: 11, padding: '13px 15px', marginBottom: 10, cursor: 'pointer' }}>
                <div style={{ fontSize: 13.5, fontWeight: 700 }}>{l}</div><div style={{ fontSize: 12, color: 'var(--muted-fg)', marginTop: 2 }}>{d}</div>
            </GuardrailButton>
        );
        return (
            <>
                <SubjectChip subject={subject} />
                {opt('acknowledge', 'Acknowledge', 'Mark as “seen, I’m handling it”. Stays visible but quietened.')}
                {opt('snooze', 'Snooze', 'Hide until a date you choose — e.g. after their rest days.')}
                {opt('dismiss', 'Dismiss', 'Clear the flag with a reason (false alarm, already resolved).')}
                {f.triage === 'snooze' && <Field label="Snooze until"><input className="wbfield" type="date" value={String(f.snoozeUntil ?? '')} onChange={(e) => setF({ snoozeUntil: e.target.value })} /></Field>}
                {f.triage === 'dismiss' && <Field label="Reason"><textarea className="wbarea" value={String(f.triageReason ?? '')} onChange={(e) => setF({ triageReason: e.target.value })} placeholder="Why is this flag being cleared?" /></Field>}
            </>
        );
    }

    /* ---- CHECK-IN ---- */
    if (kind === 'checkin') {
        if (step === 0) return (
            <>
                <Head2 t="Who & what kind" s="Log a wellbeing check-in. This is part of our duty of care." />
                {subject && 'user_id' in subject ? <SubjectChip subject={subject} /> : (
                    <Field label="Staff member"><StaffSelect owners={owners} value={f.staffId as number | undefined} onChange={(v) => setF({ staffId: v })} /></Field>
                )}
                <Field label="Type"><Seg value={(f.checkinType as string) ?? 'welfare'} onChange={(v) => setF({ checkinType: v })} options={[{ v: '1on1', l: '1:1' }, { v: 'welfare', l: 'Welfare' }, { v: 'return_to_work', l: 'Return to work' }]} /></Field>
            </>
        );
        if (step === 1) return (
            <>
                <Head2 t="Notes & mood" s="Keep it warm and plain. Mark private if it shouldn’t be shared back." />
                <Field label="What did you discuss?"><textarea className="wbarea" value={String(f.checkinNotes ?? '')} onChange={(e) => setF({ checkinNotes: e.target.value })} placeholder="Kept it about the long shifts…" /></Field>
                <Field label="How did they seem?"><Seg value={(f.mood as string) ?? 'mixed'} onChange={(v) => setF({ mood: v })} options={[{ v: 'good', l: '🙂 Okay' }, { v: 'mixed', l: '😐 Mixed' }, { v: 'low', l: '😟 Low' }]} /></Field>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
                    <Field label="Follow-up date"><input className="wbfield" type="date" value={String(f.followUp ?? '')} onChange={(e) => setF({ followUp: e.target.value })} /></Field>
                    <Field label="Visibility">
                        <GuardrailButton unstyled onClick={() => setF({ isPrivate: !(f.isPrivate ?? true) })} style={{ display: 'flex', alignItems: 'center', gap: 9, width: '100%', border: '1px solid var(--border)', background: 'var(--card)', borderRadius: 9, padding: '9px 12px', cursor: 'pointer', fontSize: 13, fontWeight: 600 }}>{(f.isPrivate ?? true) ? '🔒 Private to managers' : '👀 Shared with staff'}</GuardrailButton>
                    </Field>
                </div>
            </>
        );
        return <><Head2 t="Review" s="Save the check-in. It links to this person’s wellbeing record." /><Review rows={[['Staff', subject && 'user_id' in subject ? (subject.name ?? '—') : (owners.find((o) => o.id === f.staffId)?.name ?? '—')], ['Type', labelForCheckin((f.checkinType as string) ?? 'welfare')], ['Follow-up', String(f.followUp || '—')], ['Visibility', (f.isPrivate ?? true) ? 'Private to managers' : 'Shared with staff']]} /></>;
    }

    /* ---- EAP ---- */
    if (kind === 'eap') {
        if (step === 0) return (
            <>
                <div style={{ border: '1px solid color-mix(in oklch,var(--warn) 30%,transparent)', background: 'var(--warn-bg)', color: 'var(--warn)', borderRadius: 11, padding: '11px 13px', fontSize: 12, fontWeight: 500, marginBottom: 18 }}>Confidential — only you and the EAP coordinator can see this referral.</div>
                <Head2 t="Staff & reason" s="Refer to the Employee Assistance Programme." />
                {subject && 'user_id' in subject ? <SubjectChip subject={subject} /> : <Field label="Staff member"><StaffSelect owners={owners} value={f.staffId as number | undefined} onChange={(v) => setF({ staffId: v })} /></Field>}
                <Field label="Reason category"><Seg value={(f.eapReason as string) ?? 'workload'} onChange={(v) => setF({ eapReason: v })} options={[{ v: 'workload', l: 'Workload' }, { v: 'personal', l: 'Personal' }, { v: 'wellbeing', l: 'Wellbeing' }]} /></Field>
            </>
        );
        if (step === 1) return (
            <>
                <Head2 t="Provider & consent" s="Choose a provider and confirm consent was given." />
                <Field label="Provider"><select className="wbfield" value={String(f.eapProvider ?? '')} onChange={(e) => setF({ eapProvider: e.target.value })}><option value="">Select…</option><option>Vitae · 0508 664 981</option><option>EAP Services</option><option>Benestar</option></select></Field>
                <Field label="Consent"><label style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 13, cursor: 'pointer' }}><input type="checkbox" checked={Boolean(f.eapConsent)} onChange={(e) => setF({ eapConsent: e.target.checked })} style={{ width: 17, height: 17, accentColor: 'var(--primary)' }} /> The staff member has consented to this referral</label></Field>
                <Field label="Notes"><textarea className="wbarea" value={String(f.eapNotes ?? '')} onChange={(e) => setF({ eapNotes: e.target.value })} placeholder="Context for the coordinator (kept confidential)" /></Field>
            </>
        );
        return <><Head2 t="Review" s="Submit privately to the EAP coordinator." /><Review rows={[['Staff', subject && 'user_id' in subject ? (subject.name ?? '—') : (owners.find((o) => o.id === f.staffId)?.name ?? '—')], ['Reason', String(f.eapReason ?? 'workload')], ['Provider', String(f.eapProvider || '—')], ['Consent', f.eapConsent ? 'Given' : 'Not given'], ['Visibility', 'Confidential']]} /></>;
    }

    /* ---- CREATE PLAN ---- */
    if (kind === 'createPlan') {
        if (step === 0) return (
            <>
                <Head2 t="Context" s="Link this plan to a person and pick an owner." />
                {subject && 'user_id' in subject ? <SubjectChip subject={subject} /> : <Field label="About (optional)"><div style={{ fontSize: 12.5, color: 'var(--muted-fg)' }}>Standalone plan — not linked to a specific person.</div></Field>}
                <Field label="Owner"><StaffSelect owners={owners} value={f.planOwner as number | undefined} onChange={(v) => setF({ planOwner: v })} /></Field>
            </>
        );
        if (step === 1) return (
            <>
                <Head2 t="The plan" s="What needs to happen, and how urgent is it?" />
                <Field label="Title"><input className="wbfield" value={String(f.planTitle ?? '')} onChange={(e) => setF({ planTitle: e.target.value })} placeholder="e.g. Reduce consecutive-day stretches" /></Field>
                <Field label="Description"><textarea className="wbarea" value={String(f.planDesc ?? '')} onChange={(e) => setF({ planDesc: e.target.value })} placeholder="What we’ll do and why" /></Field>
                <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
                    <Field label="Priority"><Seg value={(f.planPriority as string) ?? 'high'} onChange={(v) => setF({ planPriority: v })} options={[{ v: 'low', l: 'Low' }, { v: 'medium', l: 'Medium' }, { v: 'high', l: 'High' }]} /></Field>
                    <Field label="Due date"><input className="wbfield" type="date" value={String(f.planDue ?? '')} onChange={(e) => setF({ planDue: e.target.value })} /></Field>
                </div>
            </>
        );
        return <><Head2 t="Review" s="Create and assign this action plan." /><Review rows={[['About', subject && 'user_id' in subject ? (subject.name ?? '—') : 'Standalone'], ['Owner', owners.find((o) => o.id === f.planOwner)?.name ?? '—'], ['Priority', String(f.planPriority ?? 'high')], ['Due', String(f.planDue || '—')]]} /></>;
    }

    return null;
}

function updateQuestion(setF: (p: Record<string, unknown>) => void, questions: Array<Record<string, unknown>>, i: number, patch: Record<string, unknown>) {
    setF({ questions: questions.map((q, j) => (j === i ? { ...q, ...patch } : q)) });
}

function StaffSelect({ owners, value, onChange }: { owners: OwnerOption[]; value: number | undefined; onChange: (v: number) => void }) {
    return (
        <select className="wbfield" value={value ?? ''} onChange={(e) => onChange(Number(e.target.value))}>
            <option value="">Select staff…</option>
            {owners.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
        </select>
    );
}

function RespondInput({ q, value, onChange }: { q: SurveyQuestion; value: never; onChange: (v: unknown) => void }) {
    if (q.question_type === 'enps') {
        return (
            <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap' }}>
                {Array.from({ length: 11 }, (_, i) => {
                    const col = i <= 6 ? 'var(--crit)' : i <= 8 ? 'var(--warn)' : 'var(--ok)';
                    const on = Number(value) === i;
                    return <GuardrailButton unstyled key={i} onClick={() => onChange(i)} style={{ width: 42, height: 42, borderRadius: 10, border: `1px solid ${on ? col : 'var(--border)'}`, background: on ? col : 'var(--card)', color: on ? '#fff' : 'var(--fg)', fontSize: 14, fontWeight: 700, cursor: 'pointer' }}>{i}</GuardrailButton>;
                })}
            </div>
        );
    }
    if (q.question_type === 'scale') {
        return (
            <div style={{ display: 'flex', gap: 8 }}>
                {[1, 2, 3, 4, 5].map((n) => {
                    const on = Number(value) === n;
                    return <GuardrailButton unstyled key={n} onClick={() => onChange(n)} style={{ flex: 1, height: 46, borderRadius: 10, border: `1px solid ${on ? 'var(--primary)' : 'var(--border)'}`, background: on ? 'var(--primary)' : 'var(--card)', color: on ? '#fff' : 'var(--fg)', fontSize: 15, fontWeight: 700, cursor: 'pointer' }}>{n}</GuardrailButton>;
                })}
            </div>
        );
    }
    if (q.question_type === 'boolean') {
        return (
            <div style={{ display: 'flex', gap: 8 }}>
                {[{ v: 'yes', l: 'Yes' }, { v: 'no', l: 'No' }].map((o) => {
                    const on = value === o.v;
                    return <GuardrailButton unstyled key={o.v} onClick={() => onChange(o.v)} style={{ flex: 1, height: 42, borderRadius: 10, border: `1px solid ${on ? 'var(--primary)' : 'var(--border)'}`, background: on ? 'var(--primary)' : 'var(--card)', color: on ? '#fff' : 'var(--fg)', fontSize: 14, fontWeight: 700, cursor: 'pointer' }}>{o.l}</GuardrailButton>;
                })}
            </div>
        );
    }
    if (q.question_type === 'choice') {
        return (
            <select className="wbfield" value={String(value ?? '')} onChange={(e) => onChange(e.target.value)}>
                <option value="">Select…</option>
                {(q.options ?? []).map((o, i) => <option key={i} value={o}>{o}</option>)}
            </select>
        );
    }
    return <textarea className="wbarea" value={String(value ?? '')} onChange={(e) => onChange(e.target.value)} placeholder="Optional — anything on your mind" />;
}
