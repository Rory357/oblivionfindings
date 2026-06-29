/* eslint-disable no-restricted-syntax -- The Performance hub wizard engine is a
 * config-driven multi-step form built on the shared MedsWizardDialog + wizard
 * primitives (Add Client reference contract). Each wizard maps to a real
 * controller endpoint; every colour is a semantic design token. */
import { router } from '@inertiajs/react';
import {
    Award,
    Check,
    GitBranch,
    Sparkles,
    Target,
    TrendingUp,
    MessageSquare,
    Gauge,
    Sprout,
    UserCheck,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import { MedsWizardDialog, SummaryRow, type MedsWizardStep } from '@/components/meds/wizard-shell';
import {
    Field,
    Segmented,
    SelectInput,
    StepHead,
    type IconType,
} from '@/components/wizard/primitives';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

export type Opt = { value: number | string; label: string };

export type WizardKind =
    | 'review'
    | 'supervision'
    | 'goal'
    | 'development'
    | 'assess'
    | 'feedback'
    | 'pip'
    | 'succession'
    | 'signoff';

export type WizardContext = {
    reviewId?: number;
    prefill?: Record<string, unknown>;
};

export type WizardState = { kind: WizardKind; context?: WizardContext };

export type WizardSupport = {
    staff: Opt[];
    reviewTypes: Opt[];
    competencyOptions: Opt[];
    successionEmployees: Opt[];
};

type FieldType =
    | 'text'
    | 'textarea'
    | 'lines'
    | 'select'
    | 'date'
    | 'segmented'
    | 'people-single'
    | 'people-multi';

type WizField = {
    key: string;
    label: string;
    type: FieldType;
    required?: boolean;
    hint?: string;
    span?: boolean;
    placeholder?: string;
    options?: Opt[];
    optionsFrom?: 'staff' | 'reviewTypes' | 'competencyOptions' | 'successionEmployees';
};

type WizStep = { label: string; blurb: string; icon: IconType; fields?: WizField[] };

type WizDef = {
    title: string;
    rail: string;
    icon: LucideIcon;
    steps: WizStep[];
    /** Build the request payload from collected field data. */
    build: (d: Record<string, unknown>, ctx?: WizardContext) => { url: string; method: 'post'; data: Record<string, unknown> };
    successNoun: string;
};

const today = () => new Date().toISOString().slice(0, 10);
const lines = (v: unknown): string[] =>
    String(v ?? '')
        .split('\n')
        .map((s) => s.trim())
        .filter(Boolean);

function defs(support: WizardSupport): Record<WizardKind, WizDef> {
    const ratingOpts: Opt[] = [
        { value: 1, label: '1 — Needs improvement' },
        { value: 2, label: '2 — Below' },
        { value: 3, label: '3 — Meets' },
        { value: 4, label: '4 — Exceeds' },
        { value: 5, label: '5 — Outstanding' },
    ];
    const levelOpts: Opt[] = [
        { value: 1, label: '1 — Novice' },
        { value: 2, label: '2 — Developing' },
        { value: 3, label: '3 — Proficient' },
        { value: 4, label: '4 — Advanced' },
        { value: 5, label: '5 — Expert' },
    ];

    return {
        review: {
            title: 'New performance review',
            rail: 'Performance',
            icon: Award,
            successNoun: 'Review',
            steps: [
                {
                    label: 'Details',
                    blurb: 'Who & when',
                    icon: Award,
                    fields: [
                        { key: 'employee', label: 'Staff member', type: 'people-single', optionsFrom: 'staff', required: true, span: true },
                        { key: 'review_type', label: 'Review type', type: 'select', optionsFrom: 'reviewTypes', required: true },
                        { key: 'overall_rating', label: 'Overall rating', type: 'select', hint: 'optional', options: ratingOpts },
                        { key: 'start', label: 'Period start', type: 'date', required: true },
                        { key: 'end', label: 'Period end', type: 'date', required: true },
                    ],
                },
                {
                    label: 'Assessment',
                    blurb: 'Strengths & goals',
                    icon: Sparkles,
                    fields: [
                        { key: 'strengths', label: 'Strengths', type: 'textarea', span: true, placeholder: 'What this person does well…' },
                        { key: 'dev', label: 'Development areas', type: 'textarea', span: true, placeholder: 'Where they can grow…' },
                        { key: 'goals', label: 'Goals', type: 'lines', span: true, hint: 'one per line' },
                    ],
                },
                { label: 'Review', blurb: 'Confirm & save', icon: Check },
            ],
            build: (d) => ({
                url: '/hr/performance/reviews',
                method: 'post',
                data: {
                    employee_user_id: Number(d.employee),
                    review_type: d.review_type,
                    overall_rating: d.overall_rating ? Number(d.overall_rating) : null,
                    review_period_start: d.start,
                    review_period_end: d.end,
                    strengths: d.strengths || null,
                    development_areas: d.dev || null,
                    goals: lines(d.goals),
                },
            }),
        },
        supervision: {
            title: 'Log supervision note',
            rail: 'Supervision',
            icon: UserCheck,
            successNoun: 'Supervision note',
            steps: [
                {
                    label: 'Session',
                    blurb: '1:1 details',
                    icon: UserCheck,
                    fields: [
                        { key: 'employee', label: 'Staff member', type: 'people-single', optionsFrom: 'staff', required: true, span: true },
                        { key: 'session_date', label: 'Session date', type: 'date', required: true },
                        { key: 'next_session_date', label: 'Next session', type: 'date' },
                        {
                            key: 'cadence',
                            label: 'Cadence',
                            type: 'segmented',
                            options: [
                                { value: 'monthly', label: 'Monthly' },
                                { value: 'six_weekly', label: '6-weekly' },
                                { value: 'quarterly', label: 'Quarterly' },
                            ],
                        },
                    ],
                },
                {
                    label: 'Discussion',
                    blurb: 'Notes & actions',
                    icon: Sparkles,
                    fields: [
                        { key: 'topics', label: 'Topics discussed', type: 'textarea', span: true, required: true },
                        { key: 'actions', label: 'Agreed actions', type: 'lines', span: true, hint: 'one per line' },
                        {
                            key: 'visible',
                            label: 'Visible to employee (require acknowledgement)',
                            type: 'segmented',
                            options: [
                                { value: 'yes', label: 'Yes' },
                                { value: 'no', label: 'No' },
                            ],
                        },
                    ],
                },
                { label: 'Review', blurb: 'Confirm & save', icon: Check },
            ],
            build: (d) => ({
                url: '/hr/performance/supervision',
                method: 'post',
                data: {
                    employee_user_id: Number(d.employee),
                    session_date: d.session_date,
                    next_session_date: d.next_session_date || null,
                    session_type: 'supervision',
                    cadence: d.cadence || null,
                    topics_discussed: d.topics,
                    actions_agreed: lines(d.actions),
                    is_visible_to_employee: d.visible !== 'no',
                },
            }),
        },
        goal: {
            title: 'New goal / OKR',
            rail: 'Goals & OKRs',
            icon: Target,
            successNoun: 'Objective',
            steps: [
                {
                    label: 'Objective',
                    blurb: 'What & who',
                    icon: Target,
                    fields: [
                        { key: 'title', label: 'Objective', type: 'text', required: true, span: true, placeholder: 'e.g. Lift medication competency to 95%' },
                        { key: 'owner', label: 'Owner', type: 'people-single', optionsFrom: 'staff', required: true },
                        {
                            key: 'goal_type',
                            label: 'Type',
                            type: 'segmented',
                            options: [
                                { value: 'individual', label: 'Individual' },
                                { value: 'team', label: 'Team' },
                                { value: 'company', label: 'Company' },
                            ],
                        },
                        { key: 'due', label: 'Target date', type: 'date', required: true },
                    ],
                },
                {
                    label: 'Key results',
                    blurb: 'Measures of success',
                    icon: Sparkles,
                    fields: [
                        { key: 'krs', label: 'Key results', type: 'lines', span: true, hint: 'one per line · metric · baseline → target' },
                    ],
                },
                { label: 'Review', blurb: 'Confirm & save', icon: Check },
            ],
            build: (d) => ({
                url: '/hr/goals',
                method: 'post',
                data: {
                    user_id: Number(d.owner),
                    title: d.title,
                    goal_type: d.goal_type || 'individual',
                    priority: 'medium',
                    start_date: today(),
                    due_date: d.due,
                    status: 'active',
                    key_results: lines(d.krs),
                    stay: true,
                },
            }),
        },
        development: {
            title: 'New development goal',
            rail: 'Development',
            icon: Sprout,
            successNoun: 'Development goal',
            steps: [
                {
                    label: 'Focus',
                    blurb: 'Area & levels',
                    icon: Sprout,
                    fields: [
                        { key: 'employee', label: 'Staff member', type: 'people-single', optionsFrom: 'staff', required: true, span: true },
                        { key: 'area', label: 'Competency area', type: 'text', required: true, span: true },
                        { key: 'cur', label: 'Current level', type: 'select', options: levelOpts, required: true },
                        { key: 'tgt', label: 'Target level', type: 'select', options: levelOpts, required: true },
                    ],
                },
                {
                    label: 'Plan',
                    blurb: 'Course & target',
                    icon: Sparkles,
                    fields: [
                        {
                            key: 'category',
                            label: 'Category',
                            type: 'segmented',
                            options: [
                                { value: 'growth', label: 'Growth' },
                                { value: 'capability', label: 'Capability' },
                                { value: 'leadership', label: 'Leadership' },
                                { value: 'compliance', label: 'Compliance' },
                            ],
                        },
                        { key: 'course', label: 'Linked course / notes', type: 'text', span: true },
                        { key: 'due', label: 'Target date', type: 'date' },
                    ],
                },
                { label: 'Review', blurb: 'Confirm & save', icon: Check },
            ],
            build: (d) => ({
                url: '/hr/goals/development',
                method: 'post',
                data: {
                    employee_user_id: Number(d.employee),
                    title: d.area,
                    competency_area: d.area,
                    category: d.category || 'growth',
                    current_level: Number(d.cur),
                    target_level: Number(d.tgt),
                    description: d.course || null,
                    due_date: d.due || null,
                    status: 'in_progress',
                    progress_percent: 0,
                },
            }),
        },
        assess: {
            title: 'Assess competency',
            rail: 'Competencies',
            icon: Gauge,
            successNoun: 'Assessment',
            steps: [
                {
                    label: 'Subject',
                    blurb: 'Who & what',
                    icon: Gauge,
                    fields: [
                        { key: 'employee', label: 'Staff member', type: 'people-single', optionsFrom: 'staff', required: true, span: true },
                        { key: 'competency', label: 'Competency', type: 'select', optionsFrom: 'competencyOptions', required: true, span: true },
                        { key: 'cur', label: 'Assessed level', type: 'select', options: levelOpts, required: true },
                        { key: 'tgt', label: 'Target level', type: 'select', options: levelOpts },
                    ],
                },
                {
                    label: 'Evidence',
                    blurb: 'Proof & notes',
                    icon: Sparkles,
                    fields: [
                        { key: 'evidence', label: 'Evidence notes', type: 'textarea', span: true },
                    ],
                },
                { label: 'Review', blurb: 'Confirm & save', icon: Check },
            ],
            build: (d) => ({
                url: '/hr/performance/competencies/assess',
                method: 'post',
                data: {
                    employee_user_id: Number(d.employee),
                    assessments: [
                        {
                            competency_id: Number(d.competency),
                            proficiency_level: Number(d.cur),
                            target_level: d.tgt ? Number(d.tgt) : null,
                            notes: d.evidence || null,
                        },
                    ],
                },
            }),
        },
        feedback: {
            title: 'Request 360 feedback',
            rail: '360 Feedback',
            icon: MessageSquare,
            successNoun: 'Feedback request',
            steps: [
                {
                    label: 'Template',
                    blurb: 'Type & subject',
                    icon: MessageSquare,
                    fields: [
                        { key: 'subject', label: 'Subject', type: 'people-single', optionsFrom: 'staff', required: true, span: true },
                        {
                            key: 'review_type',
                            label: 'Feedback type',
                            type: 'select',
                            options: [
                                { value: 'peer', label: 'Peer' },
                                { value: 'manager', label: 'Manager' },
                                { value: 'direct_report', label: 'Direct report' },
                                { value: 'self', label: 'Self' },
                            ],
                            required: true,
                        },
                        { key: 'due', label: 'Responses due', type: 'date', required: true },
                    ],
                },
                {
                    label: 'Reviewers',
                    blurb: 'Who gives feedback',
                    icon: UserCheck,
                    fields: [
                        { key: 'reviewers', label: 'Reviewers', type: 'people-multi', optionsFrom: 'staff', required: true, span: true },
                    ],
                },
                { label: 'Review', blurb: 'Confirm & send', icon: Check },
            ],
            build: (d) => ({
                url: '/hr/feedback/bulk-request',
                method: 'post',
                data: {
                    subject_user_id: Number(d.subject),
                    review_type: d.review_type,
                    due_date: d.due,
                    reviewer_user_ids: ((d.reviewers as number[]) ?? []).map(Number),
                },
            }),
        },
        pip: {
            title: 'Start performance improvement plan',
            rail: 'PIPs',
            icon: TrendingUp,
            successNoun: 'PIP',
            steps: [
                {
                    label: 'Plan',
                    blurb: 'Reason & dates',
                    icon: TrendingUp,
                    fields: [
                        { key: 'employee', label: 'Staff member', type: 'people-single', optionsFrom: 'staff', required: true, span: true },
                        { key: 'reason', label: 'Reason / concern', type: 'text', required: true, span: true },
                        { key: 'review', label: 'Review date', type: 'date', required: true },
                    ],
                },
                {
                    label: 'Milestones',
                    blurb: 'Targets & support',
                    icon: Sparkles,
                    fields: [
                        { key: 'milestones', label: 'Milestones', type: 'lines', span: true, hint: 'one per line', required: true },
                        { key: 'support', label: 'Support offered', type: 'textarea', span: true },
                    ],
                },
                { label: 'Review', blurb: 'Confirm & save', icon: Check },
            ],
            build: (d) => {
                const ms = lines(d.milestones);
                const review = String(d.review);
                return {
                    url: '/hr/performance/pips',
                    method: 'post',
                    data: {
                        employee_user_id: Number(d.employee),
                        title: String(d.reason).slice(0, 120),
                        reason: d.reason,
                        expectations: ms.join('\n') || String(d.reason),
                        support_offered: d.support || null,
                        start_date: today(),
                        end_date: review,
                        review_date: review,
                        milestones: ms.map((m) => ({ title: m, due_date: review })),
                        stay: true,
                    },
                };
            },
        },
        succession: {
            title: 'New succession plan',
            rail: 'Succession',
            icon: GitBranch,
            successNoun: 'Succession plan',
            steps: [
                {
                    label: 'Role',
                    blurb: 'Critical position',
                    icon: GitBranch,
                    fields: [
                        { key: 'role', label: 'Critical role', type: 'text', required: true, span: true, placeholder: 'e.g. Team Leader · Kowhai Lodge' },
                        { key: 'incumbent', label: 'Current incumbent', type: 'people-single', optionsFrom: 'staff' },
                        {
                            key: 'risk',
                            label: 'Risk level',
                            type: 'segmented',
                            options: [
                                { value: 'low', label: 'Low' },
                                { value: 'medium', label: 'Medium' },
                                { value: 'high', label: 'High' },
                                { value: 'critical', label: 'Critical' },
                            ],
                        },
                    ],
                },
                {
                    label: 'Candidates',
                    blurb: 'Successors',
                    icon: UserCheck,
                    fields: [
                        { key: 'candidates', label: 'Candidates', type: 'people-multi', optionsFrom: 'successionEmployees', span: true },
                        {
                            key: 'readiness',
                            label: 'Overall readiness',
                            type: 'segmented',
                            options: [
                                { value: 'ready_now', label: 'Ready now' },
                                { value: 'ready_1_year', label: '1 year' },
                                { value: 'ready_2_years', label: '2 years' },
                                { value: 'developing', label: 'Developing' },
                            ],
                        },
                    ],
                },
                { label: 'Review', blurb: 'Confirm & save', icon: Check },
            ],
            build: (d) => ({
                url: '/hr/succession',
                method: 'post',
                data: {
                    role_title: d.role,
                    risk_level: d.risk || 'medium',
                    current_holder_user_id: d.incumbent ? Number(d.incumbent) : null,
                    candidates: ((d.candidates as number[]) ?? []).map((id) => ({
                        employee_profile_id: Number(id),
                        readiness: d.readiness || 'developing',
                    })),
                    stay: true,
                },
            }),
        },
        signoff: {
            title: 'Sign off review',
            rail: 'Reviews',
            icon: Check,
            successNoun: 'Sign-off',
            steps: [
                {
                    label: 'Sign-off',
                    blurb: 'Confirm outcome',
                    icon: Check,
                    fields: [
                        {
                            key: 'decision',
                            label: 'Decision',
                            type: 'segmented',
                            required: true,
                            options: [
                                { value: 'approve', label: 'Approve & lock' },
                                { value: 'return', label: 'Return for edits' },
                            ],
                        },
                        { key: 'comment', label: 'Comment', type: 'textarea', span: true },
                    ],
                },
                { label: 'Review', blurb: 'Confirm', icon: Check },
            ],
            build: (d, ctx) => ({
                url: `/hr/performance/reviews/${ctx?.reviewId}/sign-off`,
                method: 'post',
                data: { decision: d.decision, comment: d.comment || null },
            }),
        },
    };
}

export function PerformanceWizards({
    state,
    support,
    onClose,
}: {
    state: WizardState;
    support: WizardSupport;
    onClose: () => void;
}) {
    const allDefs = useMemo(() => defs(support), [support]);
    const def = allDefs[state.kind];

    const [step, setStep] = useState(0);
    const [data, setData] = useState<Record<string, unknown>>(state.context?.prefill ?? {});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);
    const [saving, setSaving] = useState(false);

    const optionsFor = (f: WizField): Opt[] => f.options ?? (f.optionsFrom ? support[f.optionsFrom] : []);

    const set = (k: string, v: unknown) => {
        setData((d) => ({ ...d, [k]: v }));
        setErrors((e) => ({ ...e, [k]: '' }));
    };

    const isLast = step === def.steps.length - 1;
    const stepFields = def.steps[step].fields ?? [];

    const validateStep = (): boolean => {
        const errs: Record<string, string> = {};
        for (const f of stepFields) {
            if (!f.required) continue;
            const v = data[f.key];
            if (f.type === 'people-multi') {
                if (!Array.isArray(v) || v.length === 0) errs[f.key] = 'Pick at least one';
            } else if (v === undefined || v === null || String(v).trim() === '') {
                errs[f.key] = 'Required';
            }
        }
        setErrors(errs);
        return Object.keys(errs).length === 0;
    };

    const next = () => {
        if (validateStep()) setStep((s) => Math.min(s + 1, def.steps.length - 1));
    };
    const back = () => setStep((s) => Math.max(0, s - 1));

    const completeness = useMemo(() => {
        let req = 0;
        let fill = 0;
        for (const s of def.steps) {
            for (const f of s.fields ?? []) {
                if (!f.required) continue;
                req++;
                const v = data[f.key];
                const ok = f.type === 'people-multi' ? Array.isArray(v) && v.length > 0 : v !== undefined && String(v ?? '').trim() !== '';
                if (ok) fill++;
            }
        }
        return req ? Math.round((fill / req) * 100) : 100;
    }, [def, data]);

    const submit = (another: boolean) => {
        const { url, data: payload } = def.build(data, state.context);
        setSaving(true);
        router.post(url, payload as Record<string, never>, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success(`${def.successNoun} saved`);
                if (another) {
                    setData({});
                    setErrors({});
                    setStep(0);
                } else {
                    setDone(true);
                }
            },
            onError: (errs) => {
                setErrors(errs as Record<string, string>);
                toast.error('Please fix the highlighted fields.');
                // Jump to the first step that has an error.
                const firstBad = Object.keys(errs)[0];
                const idx = def.steps.findIndex((s) => (s.fields ?? []).some((f) => f.key === firstBad || (errs as Record<string, string>)[f.key]));
                if (idx >= 0) setStep(idx);
            },
            onFinish: () => setSaving(false),
        });
    };

    const steps: MedsWizardStep[] = def.steps.map((s, i) => ({
        key: `${s.label}-${i}`,
        label: s.label,
        blurb: s.blurb,
        icon: s.icon,
    }));

    if (done) {
        return (
            <MedsWizardDialog
                open
                onClose={onClose}
                title={def.title}
                description={def.title}
                railIcon={def.icon}
                railTitle={def.rail}
                railSubtitle="Performance hub"
                steps={steps}
                stepIndex={def.steps.length - 1}
                onStepClick={() => {}}
                footer={null}
            >
                <div className="flex h-full flex-col items-center justify-center px-10 py-10 text-center">
                    <div className="relative mb-4">
                        <span className="grid h-[76px] w-[76px] place-items-center rounded-full bg-status-success-bg text-status-success">
                            <Check className="h-10 w-10" />
                        </span>
                        <Sparkles className="absolute -right-3 -top-1.5 h-5 w-5 text-primary" />
                    </div>
                    <h2 className="text-2xl font-bold">All done</h2>
                    <p className="mt-2 max-w-sm text-sm leading-relaxed text-muted-foreground">
                        {def.successNoun} was saved. It now appears in the list and on the hub.
                    </p>
                    <div className="mt-6 flex gap-2.5">
                        <button
                            type="button"
                            onClick={() => {
                                setDone(false);
                                setData({});
                                setErrors({});
                                setStep(0);
                            }}
                            className="rounded-lg border border-border bg-card px-4 py-2 text-sm font-semibold"
                        >
                            Add another
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground shadow-sm"
                        >
                            Done
                        </button>
                    </div>
                </div>
            </MedsWizardDialog>
        );
    }

    const footer = (
        <>
            <div>
                {step > 0 ? (
                    <button type="button" onClick={back} className="rounded-md px-3 py-2 text-[13px] font-semibold text-muted-foreground hover:text-foreground">
                        Back
                    </button>
                ) : null}
            </div>
            <div className="flex gap-2.5">
                <button type="button" onClick={onClose} className="rounded-md px-3 py-2 text-[13px] font-semibold text-muted-foreground hover:text-foreground">
                    Cancel
                </button>
                {isLast ? (
                    <>
                        <button
                            type="button"
                            disabled={saving}
                            onClick={() => submit(true)}
                            className="rounded-lg border border-border bg-card px-3.5 py-2 text-[13px] font-semibold disabled:opacity-50"
                        >
                            Save &amp; add another
                        </button>
                        <button
                            type="button"
                            disabled={saving}
                            onClick={() => submit(false)}
                            className="rounded-lg bg-primary px-4 py-2 text-[13px] font-semibold text-primary-foreground shadow-sm disabled:opacity-50"
                        >
                            {state.kind === 'signoff' ? 'Confirm' : 'Create'}
                        </button>
                    </>
                ) : (
                    <button
                        type="button"
                        onClick={next}
                        className="rounded-lg bg-primary px-4 py-2 text-[13px] font-semibold text-primary-foreground shadow-sm"
                    >
                        Continue
                    </button>
                )}
            </div>
        </>
    );

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title={def.title}
            description={def.title}
            railIcon={def.icon}
            railTitle={def.rail}
            railSubtitle="Performance hub"
            railFooter={
                <div>
                    <div className="mb-1.5 flex justify-between text-[11px] text-muted-foreground">
                        <span>Completeness</span>
                        <span className="font-bold text-primary">{completeness}%</span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div className="h-full rounded-full bg-primary transition-[width] duration-500" style={{ width: `${completeness}%` }} />
                    </div>
                </div>
            }
            steps={steps}
            stepIndex={step}
            onStepClick={(i) => i < step && setStep(i)}
            footer={footer}
        >
            {isLast ? (
                <ReviewStep def={def} data={data} support={support} onEdit={setStep} />
            ) : (
                <div className="motion-safe:animate-in motion-safe:fade-in-0">
                    <StepHead icon={def.steps[step].icon} title={def.steps[step].label} blurb={def.steps[step].blurb} />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {stepFields.map((f) => (
                            <FieldControl
                                key={f.key}
                                field={f}
                                value={data[f.key]}
                                error={errors[f.key]}
                                options={optionsFor(f)}
                                onChange={(v) => set(f.key, v)}
                            />
                        ))}
                    </div>
                </div>
            )}
        </MedsWizardDialog>
    );
}

function FieldControl({
    field: f,
    value,
    error,
    options,
    onChange,
}: {
    field: WizField;
    value: unknown;
    error?: string;
    options: Opt[];
    onChange: (v: unknown) => void;
}) {
    let control: React.ReactNode;
    if (f.type === 'textarea' || f.type === 'lines') {
        control = (
            <Textarea
                rows={3}
                value={(value as string) ?? ''}
                placeholder={f.placeholder}
                onChange={(e) => onChange(e.target.value)}
                className={cn(error && 'border-status-critical')}
            />
        );
    } else if (f.type === 'select' || f.type === 'people-single') {
        control = (
            <SelectInput
                value={value != null ? String(value) : ''}
                onChange={(v) => onChange(v)}
                placeholder="Select…"
                ariaLabel={f.label}
                options={options.map((o) => ({ value: String(o.value), label: o.label }))}
            />
        );
    } else if (f.type === 'date') {
        control = (
            <Input
                type="date"
                value={(value as string) ?? ''}
                onChange={(e) => onChange(e.target.value)}
                className={cn(error && 'border-status-critical')}
            />
        );
    } else if (f.type === 'segmented') {
        control = (
            <Segmented
                value={(value as string) ?? ''}
                onChange={(v) => onChange(v)}
                options={options.map((o) => ({ value: String(o.value), label: o.label }))}
            />
        );
    } else if (f.type === 'people-multi') {
        const arr = (value as (number | string)[]) ?? [];
        control = (
            <div className="flex flex-wrap gap-1.5">
                {options.map((o) => {
                    const on = arr.map(String).includes(String(o.value));
                    return (
                        <button
                            key={String(o.value)}
                            type="button"
                            aria-pressed={on}
                            onClick={() => onChange(on ? arr.filter((x) => String(x) !== String(o.value)) : [...arr, o.value])}
                            className={cn(
                                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                                on ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-foreground hover:border-primary/50',
                            )}
                        >
                            {on ? <Check className="h-3 w-3" /> : null}
                            {o.label}
                        </button>
                    );
                })}
            </div>
        );
    } else {
        control = (
            <Input
                value={(value as string) ?? ''}
                placeholder={f.placeholder}
                onChange={(e) => onChange(e.target.value)}
                className={cn(error && 'border-status-critical')}
            />
        );
    }

    return (
        <Field label={f.label} required={f.required} hint={f.hint} error={error} span={f.span || f.type === 'textarea' || f.type === 'lines' || f.type === 'people-multi'}>
            {control}
        </Field>
    );
}

function ReviewStep({
    def,
    data,
    support,
    onEdit,
}: {
    def: WizDef;
    data: Record<string, unknown>;
    support: WizardSupport;
    onEdit: (i: number) => void;
}) {
    const labelFor = (f: WizField): string => {
        const v = data[f.key];
        if (v == null || v === '') return '—';
        if (f.type === 'people-multi') {
            const opts = f.optionsFrom ? support[f.optionsFrom] : f.options ?? [];
            const arr = (v as (number | string)[]).map(String);
            return opts.filter((o) => arr.includes(String(o.value))).map((o) => o.label).join(', ') || '—';
        }
        const opts = f.options ?? (f.optionsFrom ? support[f.optionsFrom] : []);
        if (opts.length) {
            const found = opts.find((o) => String(o.value) === String(v));
            if (found) return found.label;
        }
        if (f.type === 'lines') return lines(v).join(' · ') || '—';
        return String(v);
    };

    return (
        <div className="motion-safe:animate-in motion-safe:fade-in-0">
            <StepHead icon={Check} title="Review & save" blurb="Confirm the details below — you can edit any step." />
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {def.steps
                    .filter((s) => s.fields)
                    .map((s, i) => (
                        <div key={s.label} className="rounded-xl border border-border bg-card/60 p-3.5">
                            <div className="mb-1.5 flex items-center justify-between">
                                <span className="text-[13px] font-bold">{s.label}</span>
                                <button
                                    type="button"
                                    onClick={() => onEdit(i)}
                                    className="text-[12.5px] font-semibold text-primary"
                                >
                                    Edit
                                </button>
                            </div>
                            {(s.fields ?? []).map((f) => (
                                <SummaryRow key={f.key} label={f.label} value={labelFor(f)} />
                            ))}
                        </div>
                    ))}
            </div>
        </div>
    );
}

export default PerformanceWizards;
