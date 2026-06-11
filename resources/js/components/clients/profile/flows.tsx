/* Declarative workflow configs for the client-profile wizard system.
 * Every flow renders through WorkflowWizardDialog and submits to a REAL
 * endpoint (no stubs — see docs/client-profile-redesign-plan.md for the
 * flow → endpoint table). Flows that already have bespoke dialogs in the
 * codebase (daily/quick/communication notes, edit profile, assign workers,
 * risk CRUD inside the tab) are reused by the dialog host instead. */
import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    Activity,
    AlertOctagon,
    AlertTriangle,
    Bell,
    BookOpen,
    Building2,
    Calendar,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    CheckSquare,
    Circle,
    ClipboardCheck,
    ClipboardList,
    Clock,
    Coffee,
    Compass,
    CornerDownRight,
    DollarSign,
    Droplets,
    FileCheck,
    FileText,
    FileWarning,
    Flag,
    FolderOpen,
    Gauge,
    HeartPulse,
    Home,
    LayoutGrid,
    Leaf,
    ListChecks,
    ListOrdered,
    ListPlus,
    ListTodo,
    MapPin,
    Moon,
    Pencil,
    PenLine,
    Plane,
    RefreshCw,
    Route as RouteIcon,
    Scale,
    Search,
    Send,
    Settings2,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Stethoscope,
    StickyNote,
    Sun,
    Sunrise,
    Thermometer,
    ThumbsUp,
    TimerReset,
    TrendingDown,
    TrendingUp,
    Truck,
    Upload,
    User,
    UserCheck,
    UserPlus,
    Users,
    Utensils,
    Wallet,
    XCircle,
} from 'lucide-react';
import { toast } from 'sonner';
import type {
    WizardSubmitHelpers,
    WizardValues,
    WorkflowConfig,
} from './workflow-wizard';

/* ------------------------------------------------------------------ context */

export type SelectOption = { value: string; label: string };

export type ProfileFlowContext = {
    clientId: number;
    clientLabel: string;
    preferredName: string;
    staffOptions: SelectOption[];
    goalOptions: SelectOption[];
    consentTypeOptions: SelectOption[];
    fundOptions: SelectOption[];
    carePlanId: number | null;
    carePlanTitle: string | null;
    onboardingWorkflowId: number | null;
    /** Per-flow context passed at openDialog() time (e.g. prefill values). */
    dialog?: Record<string, unknown>;
};

/* ------------------------------------------------------------------ helpers */

const str = (v: unknown): string => String(v ?? '').trim();
const opt = (v: unknown): string | undefined => (str(v) ? str(v) : undefined);
const num = (v: unknown): number | undefined => {
    const parsed = parseFloat(String(v ?? '').replace(/[^0-9.-]/g, ''));
    return Number.isFinite(parsed) ? parsed : undefined;
};

/** Inertia POST/PUT with flash-aware completion (see reference_inertia_flash_error). */
function submitInertia(
    method: 'post' | 'put',
    url: string,
    payload: Record<string, FormDataConvertible>,
    helpers: WizardSubmitHelpers,
    successToast: string,
    options: { reloadProps?: string[] } = {},
) {
    router[method](url, payload, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            const flash = (page.props as { flash?: { error?: string } }).flash;
            if (flash?.error) {
                toast.error(flash.error);
                helpers.onError();
                return;
            }
            toast.success(successToast);
            helpers.onDone();
            if (options.reloadProps?.length) {
                router.reload({ only: options.reloadProps });
            }
        },
        onError: (errors) => {
            helpers.onError(errors as Record<string, string>);
            const first = Object.values(errors ?? {})[0];
            if (first) toast.error(String(first));
        },
    });
}

const SEVERITY_PICKER = [
    { key: 'low', label: 'Low', icon: Shield, desc: 'Minor' },
    { key: 'medium', label: 'Medium', icon: ShieldAlert, desc: 'Monitor' },
    { key: 'high', label: 'High', icon: AlertTriangle, desc: 'Serious' },
    {
        key: 'critical',
        label: 'Critical',
        icon: AlertOctagon,
        desc: 'Immediate',
    },
];

/* -------------------------------------------------------------------- flows */

export type FlowFactory = (ctx: ProfileFlowContext) => WorkflowConfig;

const logIncident: FlowFactory = (ctx) => ({
    key: 'log_incident',
    icon: AlertTriangle,
    title: 'Log incident',
    sub: 'Incident & accident report',
    submitLabel: 'Log incident',
    steps: [
        {
            key: 'what',
            label: 'What happened',
            icon: AlertTriangle,
            blurb: 'Type, severity & time',
            heading: 'What happened?',
            desc: 'Severity drives who gets notified automatically.',
            picker: {
                key: 'severity',
                label: 'Severity',
                options: SEVERITY_PICKER,
                cols: 2,
            },
            fields: [
                {
                    key: 'type',
                    label: 'Type',
                    type: 'select',
                    required: true,
                    options: [
                        'Near miss',
                        'Accident',
                        'Behaviour',
                        'Medication',
                        'Property',
                        'Absconding',
                    ],
                },
                {
                    key: 'occurred_at',
                    label: 'Date & time',
                    type: 'datetime-local',
                    required: true,
                },
                {
                    key: 'description',
                    label: 'What happened',
                    type: 'textarea',
                    required: true,
                    rows: 4,
                    full: true,
                    placeholder: 'Factual description — what you saw and did…',
                },
            ],
        },
        {
            key: 'people',
            label: 'People involved',
            icon: Users,
            blurb: 'Witnesses & injuries',
            heading: 'Who was involved?',
            fields: [
                {
                    key: 'witnesses',
                    label: 'Witnesses',
                    full: true,
                    placeholder: 'Staff, residents, visitors…',
                },
                {
                    key: 'injured_person_name',
                    label: 'Injured person (if any)',
                },
                {
                    key: 'medical_treatment_type',
                    label: 'Injuries / treatment',
                    type: 'select',
                    options: [
                        { value: 'none', label: 'None' },
                        { value: 'first_aid', label: 'First aid given' },
                        {
                            value: 'medical_centre',
                            label: 'Medical attention required',
                        },
                        { value: 'hospital', label: 'Hospitalisation' },
                    ],
                },
            ],
        },
        {
            key: 'response',
            label: 'Response',
            icon: ListChecks,
            blurb: 'Actions & follow-up',
            heading: 'What was done?',
            fields: [
                {
                    key: 'immediate_action_taken',
                    label: 'Immediate actions taken',
                    type: 'textarea',
                    required: true,
                    rows: 3,
                    full: true,
                },
                {
                    key: 'potential_consequence',
                    label: 'Prevention / learning',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                    placeholder:
                        'What reduces the chance of this happening again?',
                },
                {
                    key: 'requires_followup',
                    label: 'Requires follow-up',
                    desc: 'Opens a follow-up so the incident is reviewed.',
                    type: 'checkbox',
                    full: true,
                },
            ],
            info: 'High and critical incidents are escalated for review automatically.',
            infoIcon: Bell,
            infoTone: 'warn',
        },
    ],
    submit: (values, helpers) => {
        const severity = str(values.severity) || 'medium';
        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/incidents`,
            {
                type: str(values.type),
                severity: severity === 'critical' ? 'high' : severity,
                potential_severity:
                    severity === 'critical' ? 'critical' : undefined,
                occurred_at: opt(values.occurred_at),
                description: str(values.description),
                witnesses: opt(values.witnesses),
                injured_person_name: opt(values.injured_person_name),
                injured_person_role: opt(values.injured_person_name)
                    ? 'client'
                    : undefined,
                medical_treatment_type: opt(values.medical_treatment_type),
                immediate_action_taken: opt(values.immediate_action_taken),
                potential_consequence: opt(values.potential_consequence),
                requires_followup: Boolean(values.requires_followup),
            },
            helpers,
            'Incident logged',
        );
    },
});

const addRisk: FlowFactory = (ctx) => {
    const prefill = (ctx.dialog?.risk ?? null) as {
        id?: number;
        label?: string;
        severity?: string;
        controls?: string;
        review_date?: string;
        active?: boolean;
    } | null;
    const editing = Boolean(prefill?.id);

    return {
        key: editing ? 'edit_risk' : 'add_risk',
        icon: ShieldAlert,
        title: editing ? 'Edit risk' : 'Add risk',
        sub: 'Risk register',
        submitLabel: editing ? 'Save risk' : 'Add to register',
        initialValues: prefill
            ? {
                  severity: prefill.severity,
                  label: prefill.label,
                  controls: prefill.controls,
                  review_date: prefill.review_date?.slice(0, 10),
                  active: prefill.active ?? true,
              }
            : undefined,
        steps: [
            {
                key: 'risk',
                label: 'The risk',
                icon: ShieldAlert,
                blurb: 'What & how severe',
                heading: 'What is the risk?',
                picker: {
                    key: 'severity',
                    label: 'Severity',
                    options: SEVERITY_PICKER,
                    cols: 2,
                },
                fields: [
                    {
                        key: 'label',
                        label: 'Risk',
                        required: true,
                        full: true,
                        placeholder: 'e.g. Risk of falls during transfers',
                    },
                ],
            },
            {
                key: 'controls',
                label: 'Controls',
                icon: ShieldCheck,
                blurb: 'Mitigation & review',
                heading: 'Controls in place',
                desc: 'What keeps this risk managed, and when it gets re-checked.',
                fields: [
                    {
                        key: 'controls',
                        label: 'Controls',
                        type: 'textarea',
                        required: true,
                        rows: 4,
                        full: true,
                        placeholder:
                            'Two-person assist… non-slip mat… hourly checks…',
                    },
                    {
                        key: 'review_date',
                        label: 'Next review date',
                        type: 'date',
                        required: true,
                    },
                    {
                        key: 'active',
                        label: 'Risk is active',
                        type: 'checkbox',
                        desc: 'Uncheck to retire this risk from the active register.',
                        full: true,
                        when: () => editing,
                    },
                ],
                info: 'The review date feeds the Actions & Reviews queue — overdue reviews surface on the Overview.',
                infoIcon: CalendarClock,
            },
        ],
        submit: (values, helpers) => {
            const payload = {
                label: str(values.label),
                severity: str(values.severity) || 'medium',
                controls: opt(values.controls),
                review_date: opt(values.review_date),
                active: editing ? Boolean(values.active) : true,
            };
            if (editing && prefill?.id) {
                submitInertia(
                    'put',
                    `/operations/clients/${ctx.clientId}/risks/${prefill.id}`,
                    payload,
                    helpers,
                    'Risk updated',
                );
            } else {
                submitInertia(
                    'post',
                    `/operations/clients/${ctx.clientId}/risks`,
                    payload,
                    helpers,
                    'Risk added to register',
                );
            }
        },
    };
};

const recordObs: FlowFactory = (ctx) => ({
    key: 'record_obs',
    icon: Stethoscope,
    title: 'Record observation',
    sub: 'Clinical measurement',
    submitLabel: 'Save observation',
    again: true,
    steps: [
        {
            key: 'measure',
            label: 'Measurement',
            icon: Activity,
            blurb: 'Type, value & time',
            heading: 'Record a measurement',
            picker: {
                key: 'obs_type',
                label: 'Observation',
                cols: 3,
                options: [
                    { key: 'vitals', label: 'Vitals / BP', icon: Activity },
                    { key: 'temp', label: 'Temperature', icon: Thermometer },
                    { key: 'weight', label: 'Weight', icon: Scale },
                    { key: 'fluid', label: 'Fluid intake', icon: Droplets },
                    { key: 'bowel', label: 'Bowel', icon: Stethoscope },
                    { key: 'seizure', label: 'Seizure', icon: HeartPulse },
                    { key: 'sleep', label: 'Sleep', icon: Moon },
                ],
            },
            fields: [
                {
                    key: 'bp',
                    label: 'Blood pressure',
                    placeholder: 'e.g. 124/78',
                    when: (v) => v.obs_type === 'vitals',
                },
                {
                    key: 'pulse',
                    label: 'Pulse (bpm)',
                    type: 'number',
                    when: (v) => v.obs_type === 'vitals',
                },
                {
                    key: 'temperature',
                    label: 'Temperature (°C)',
                    required: true,
                    placeholder: 'e.g. 36.7',
                    when: (v) => v.obs_type === 'temp',
                },
                {
                    key: 'weight_kg',
                    label: 'Weight (kg)',
                    required: true,
                    placeholder: 'e.g. 77.0',
                    when: (v) => v.obs_type === 'weight',
                },
                {
                    key: 'volume_ml',
                    label: 'Volume (ml)',
                    type: 'number',
                    required: true,
                    when: (v) => v.obs_type === 'fluid',
                },
                {
                    key: 'fluid_type',
                    label: 'Fluid',
                    placeholder: 'e.g. Water',
                    when: (v) => v.obs_type === 'fluid',
                },
                {
                    key: 'bristol_type',
                    label: 'Bristol type',
                    type: 'select',
                    required: true,
                    options: ['1', '2', '3', '4', '5', '6', '7'],
                    when: (v) => v.obs_type === 'bowel',
                },
                {
                    key: 'duration_seconds',
                    label: 'Duration (seconds)',
                    type: 'number',
                    when: (v) => v.obs_type === 'seizure',
                },
                {
                    key: 'seizure_type',
                    label: 'Seizure type',
                    placeholder: 'e.g. Tonic-clonic',
                    when: (v) => v.obs_type === 'seizure',
                },
                {
                    key: 'response_taken',
                    label: 'Response taken',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                    when: (v) => v.obs_type === 'seizure',
                },
                {
                    key: 'slept_at',
                    label: 'Night',
                    type: 'date',
                    required: true,
                    when: (v) => v.obs_type === 'sleep',
                },
                {
                    key: 'hours_slept',
                    label: 'Hours slept',
                    type: 'number',
                    required: true,
                    when: (v) => v.obs_type === 'sleep',
                },
                {
                    key: 'quality',
                    label: 'Quality',
                    type: 'select',
                    options: ['good', 'fair', 'poor'],
                    when: (v) => v.obs_type === 'sleep',
                },
                {
                    key: 'interruptions',
                    label: 'Interruptions',
                    type: 'number',
                    when: (v) => v.obs_type === 'sleep',
                },
                { key: 'occurred_at', label: 'Time', type: 'datetime-local' },
                {
                    key: 'notes',
                    label: 'Notes',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
        },
    ],
    submit: (values, helpers) => {
        const type = str(values.obs_type);
        const when = opt(values.occurred_at);
        const notes = opt(values.notes);

        if (type === 'fluid') {
            submitInertia(
                'post',
                `/operations/clients/${ctx.clientId}/health/fluid`,
                {
                    occurred_at: when,
                    direction: 'in',
                    fluid_type: opt(values.fluid_type),
                    volume_ml: num(values.volume_ml),
                    notes,
                },
                helpers,
                'Fluid entry recorded',
            );
            return;
        }
        if (type === 'bowel') {
            submitInertia(
                'post',
                `/operations/clients/${ctx.clientId}/health/bowel`,
                {
                    occurred_at: when,
                    bristol_type: num(values.bristol_type),
                    notes,
                },
                helpers,
                'Bowel entry recorded',
            );
            return;
        }
        if (type === 'seizure') {
            submitInertia(
                'post',
                `/operations/clients/${ctx.clientId}/health/seizure`,
                {
                    occurred_at: when,
                    duration_seconds: num(values.duration_seconds),
                    seizure_type: opt(values.seizure_type),
                    response_taken: opt(values.response_taken),
                    notes,
                },
                helpers,
                'Seizure recorded',
            );
            return;
        }
        if (type === 'sleep') {
            submitInertia(
                'post',
                `/operations/clients/${ctx.clientId}/health/sleep`,
                {
                    slept_at:
                        opt(values.slept_at) ??
                        opt(values.occurred_at)?.slice(0, 10),
                    hours_slept: num(values.hours_slept),
                    quality: opt(values.quality),
                    interruptions: num(values.interruptions),
                    notes,
                },
                helpers,
                'Sleep entry recorded',
            );
            return;
        }

        const observation =
            type === 'vitals'
                ? {
                      observation_type: 'vitals',
                      data: {
                          systolic: str(values.bp).split('/')[0] || undefined,
                          diastolic: str(values.bp).split('/')[1] || undefined,
                          pulse: num(values.pulse),
                          temperature: undefined,
                      },
                  }
                : type === 'temp'
                  ? {
                        observation_type: 'vitals',
                        data: { temperature: num(values.temperature) },
                    }
                  : {
                        observation_type: 'weight',
                        data: { weight_kg: num(values.weight_kg) },
                    };

        submitInertia(
            'post',
            `/clients/${ctx.clientId}/clinical/observations`,
            { ...observation, notes, recorded_at: when },
            helpers,
            'Observation recorded',
        );
    },
});

const abcEntry: FlowFactory = (ctx) => ({
    key: 'abc_entry',
    icon: Stethoscope,
    title: 'New ABC entry',
    sub: 'Behaviour observation',
    submitLabel: 'Save ABC entry',
    steps: [
        {
            key: 'context',
            label: 'Context',
            icon: MapPin,
            blurb: 'When, where, who',
            heading: 'Setting the scene',
            fields: [
                {
                    key: 'occurred_at',
                    label: 'Date & time',
                    type: 'datetime-local',
                    required: true,
                },
                {
                    key: 'setting',
                    label: 'Setting',
                    placeholder: 'e.g. Dining room at dinner',
                },
            ],
        },
        {
            key: 'abc',
            label: 'A · B · C',
            icon: ListOrdered,
            blurb: 'Antecedent → Behaviour → Consequence',
            heading: 'Antecedent → Behaviour → Consequence',
            desc: 'Factual, specific, no interpretation.',
            fields: [
                {
                    key: 'antecedent',
                    label: 'A — What happened before',
                    type: 'textarea',
                    required: true,
                    rows: 2,
                    full: true,
                },
                {
                    key: 'behaviour',
                    label: `B — What ${ctx.preferredName} did`,
                    type: 'textarea',
                    required: true,
                    rows: 2,
                    full: true,
                },
                {
                    key: 'consequence',
                    label: 'C — What happened after',
                    type: 'textarea',
                    required: true,
                    rows: 2,
                    full: true,
                },
            ],
        },
        {
            key: 'impact',
            label: 'Impact',
            icon: Gauge,
            blurb: 'Intensity & duration',
            heading: 'Impact',
            picker: {
                key: 'intensity',
                label: 'Intensity',
                options: [
                    {
                        key: 'low',
                        label: 'Low',
                        icon: Shield,
                        desc: 'Settled quickly',
                    },
                    {
                        key: 'medium',
                        label: 'Moderate',
                        icon: ShieldAlert,
                        desc: 'Needed support',
                    },
                    {
                        key: 'high',
                        label: 'High',
                        icon: AlertTriangle,
                        desc: 'Safety affected',
                    },
                ],
            },
            fields: [
                {
                    key: 'duration',
                    label: 'Duration',
                    placeholder: 'e.g. 6 minutes',
                },
                {
                    key: 'escalated',
                    label: 'Escalated to on-call',
                    desc: 'Manager or on-call was contacted — flags this for follow-up.',
                    type: 'checkbox',
                    full: true,
                },
            ],
        },
    ],
    submit: (values, helpers) => {
        const description = [
            opt(values.setting) ? `Setting: ${str(values.setting)}` : null,
            `A — ${str(values.antecedent)}`,
            `B — ${str(values.behaviour)}`,
            `C — ${str(values.consequence)}`,
            opt(values.duration) ? `Duration: ${str(values.duration)}` : null,
        ]
            .filter(Boolean)
            .join('\n');

        submitInertia(
            'post',
            `/clients/${ctx.clientId}/clinical/events`,
            {
                event_type: 'behavioural_crisis',
                severity: str(values.intensity) || 'low',
                occurred_at: str(values.occurred_at),
                description,
                immediate_action_taken: opt(values.consequence),
                requires_followup: Boolean(values.escalated),
            },
            helpers,
            'ABC entry saved',
        );
    },
});

/** Person-centred PATH + whole-of-life narrative editor (Goals Path secondary
 * section). Replaces the inline PathPlanEditor dialog with the shared wizard UX.
 * One-per-line list fields are split on submit; narrative fields persist onto
 * the client. Submits to ClientPathPlanController@upsert. */
const lines = (v: unknown): string[] =>
    String(v ?? '')
        .split('\n')
        .map((l) => l.trim())
        .filter(Boolean);

const editPathPlan: FlowFactory = (ctx) => ({
    key: 'edit_path_plan',
    icon: Compass,
    title: 'Person-centred planning',
    sub: 'PATH & whole-of-life',
    submitLabel: 'Save planning',
    initialValues: ctx.dialog?.values as WizardValues | undefined,
    steps: [
        {
            key: 'dream',
            label: 'The dream',
            icon: Compass,
            blurb: 'Hopes & north star',
            heading: 'The dream',
            desc: `What is ${ctx.preferredName}'s biggest hope for the future? In their words.`,
            fields: [
                {
                    key: 'dream',
                    label: 'The dream',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
                { key: 'north_star', label: 'North star (short statement)', full: true },
            ],
        },
        {
            key: 'strengths',
            label: 'Strengths & people',
            icon: Users,
            blurb: 'What helps them thrive',
            heading: 'Strengths & trusted people',
            desc: 'One per line.',
            fields: [
                {
                    key: 'strengths',
                    label: 'Strengths (one per line)',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
                {
                    key: 'trusted_people',
                    label: 'Trusted people (one per line)',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
                {
                    key: 'independence_goals',
                    label: 'Independence goals (one per line)',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
            ],
        },
        {
            key: 'belonging',
            label: 'Belonging & next steps',
            icon: Leaf,
            blurb: 'Community & actions',
            heading: 'Belonging & next steps',
            fields: [
                {
                    key: 'community',
                    label: 'Community & belonging',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
                {
                    key: 'action_steps',
                    label: 'Action steps (one per line)',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
                {
                    key: 'meaningful_outcomes',
                    label: 'Meaningful outcomes',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
        },
        {
            key: 'whole_life',
            label: 'Whole of life',
            icon: BookOpen,
            blurb: 'Story, strengths, interests',
            heading: 'Whole of life',
            desc: 'Background the team should know — shown on the Goals Path tab.',
            fields: [
                {
                    key: 'life_story',
                    label: 'Life story',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
                {
                    key: 'strengths_abilities',
                    label: 'Strengths & abilities',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
                {
                    key: 'interests_hobbies',
                    label: 'Interests & hobbies',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
        },
        {
            key: 'dates',
            label: 'Dates',
            icon: CalendarClock,
            blurb: 'Plan & review',
            heading: 'Dates',
            fields: [
                { key: 'plan_date', label: 'Plan date', type: 'date' },
                { key: 'next_review_at', label: 'Next review', type: 'date' },
            ],
            info: 'The review date feeds the Actions & Reviews queue.',
            infoIcon: CalendarClock,
        },
    ],
    submit: (values, helpers) => {
        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/path-plan`,
            {
                dream: opt(values.dream),
                north_star: opt(values.north_star),
                strengths: lines(values.strengths),
                trusted_people: lines(values.trusted_people),
                independence_goals: lines(values.independence_goals),
                community: opt(values.community),
                action_steps: lines(values.action_steps),
                meaningful_outcomes: opt(values.meaningful_outcomes),
                life_story: opt(values.life_story),
                strengths_abilities: opt(values.strengths_abilities),
                interests_hobbies: opt(values.interests_hobbies),
                plan_date: opt(values.plan_date),
                next_review_at: opt(values.next_review_at),
            },
            helpers,
            'Person-centred plan saved',
        );
    },
});

const planReview: FlowFactory = (ctx) => ({
    key: 'plan_review',
    icon: CalendarClock,
    title: 'Care plan review',
    sub: ctx.carePlanTitle ?? 'Care & support plan',
    submitLabel: 'Complete review',
    steps: [
        {
            key: 'scope',
            label: 'Scope',
            icon: Compass,
            blurb: 'Type & date',
            heading: 'What kind of review?',
            picker: {
                key: 'kind',
                label: 'Review type',
                options: [
                    {
                        key: 'scheduled',
                        label: 'Scheduled',
                        icon: CalendarClock,
                        desc: 'Annual / planned',
                    },
                    {
                        key: 'triggered',
                        label: 'Triggered',
                        icon: AlertTriangle,
                        desc: 'After incident / change',
                    },
                    {
                        key: 'whanau',
                        label: 'Whānau requested',
                        icon: Users,
                        desc: 'Family-initiated',
                    },
                ],
            },
            fields: [
                {
                    key: 'facilitator',
                    label: 'Facilitator',
                    type: 'select',
                    options: ctx.staffOptions.map((o) => o.label),
                },
            ],
        },
        {
            key: 'findings',
            label: 'Findings',
            icon: Search,
            blurb: 'Working / not working',
            heading: 'What did the review find?',
            fields: [
                {
                    key: 'working',
                    label: "What's working",
                    type: 'textarea',
                    required: true,
                    rows: 3,
                    full: true,
                },
                {
                    key: 'changes',
                    label: 'What needs to change',
                    type: 'textarea',
                    required: true,
                    rows: 3,
                    full: true,
                },
            ],
        },
        {
            key: 'signoff',
            label: 'Sign-off',
            icon: PenLine,
            blurb: 'Attendees & actions',
            heading: 'Sign-off',
            fields: [
                {
                    key: 'attendees',
                    label: 'Attendees',
                    type: 'chips',
                    options: [
                        ctx.preferredName,
                        'Whānau',
                        'Key worker',
                        'GP',
                        'NASC',
                        'Behaviour support',
                    ],
                },
                {
                    key: 'actions',
                    label: 'Agreed actions',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
            ],
            info: 'Completing the review archives the current version and activates the reviewed plan.',
            infoIcon: CheckCircle2,
        },
    ],
    submit: (values, helpers) => {
        if (!ctx.carePlanId) {
            toast.error('No active care plan to review.');
            helpers.onError();
            return;
        }
        const reviewNotes = [
            `Review type: ${str(values.kind) || 'scheduled'}`,
            opt(values.facilitator)
                ? `Facilitator: ${str(values.facilitator)}`
                : null,
            `What's working: ${str(values.working)}`,
            `What needs to change: ${str(values.changes)}`,
            Array.isArray(values.attendees) && values.attendees.length
                ? `Attendees: ${(values.attendees as string[]).join(', ')}`
                : null,
            opt(values.actions)
                ? `Agreed actions: ${str(values.actions)}`
                : null,
        ]
            .filter(Boolean)
            .join('\n');

        // Two-step backend contract: start a review version, then complete it
        // with the recorded findings.
        router.post(
            `/operations/care-plans/${ctx.carePlanId}/start-review`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    submitInertia(
                        'post',
                        `/operations/care-plans/${ctx.carePlanId}/complete-review`,
                        { review_notes: reviewNotes.slice(0, 2000) },
                        helpers,
                        'Plan review recorded',
                    );
                },
                onError: (errors) =>
                    helpers.onError(errors as Record<string, string>),
            },
        );
    },
});

const uploadDoc: FlowFactory = (ctx) => {
    const title = str(ctx.dialog?.title) || 'Upload document';
    return {
        key: 'upload_doc',
        icon: Upload,
        title,
        sub: 'Client record',
        submitLabel: 'Upload',
        steps: [
            {
                key: 'file',
                label: 'File & category',
                icon: FileText,
                blurb: 'Pick & classify',
                heading: title,
                picker: {
                    key: 'category',
                    label: 'Category',
                    cols: 3,
                    options: [
                        {
                            key: 'care_plan',
                            label: 'Care plan',
                            icon: FileText,
                        },
                        {
                            key: 'clinical',
                            label: 'Clinical',
                            icon: Stethoscope,
                        },
                        { key: 'consent', label: 'Consent', icon: Shield },
                        {
                            key: 'agreement',
                            label: 'Agreement',
                            icon: FileCheck,
                        },
                        { key: 'finance', label: 'Finance', icon: DollarSign },
                        { key: 'admin', label: 'Admin', icon: FolderOpen },
                    ],
                },
                fields: [
                    {
                        key: 'file',
                        label: 'File',
                        type: 'file',
                        required: true,
                        full: true,
                    },
                    { key: 'title', label: 'Display name', full: true },
                ],
            },
            {
                key: 'meta',
                label: 'Details',
                icon: Settings2,
                blurb: 'Folder, expiry & visibility',
                heading: 'Where does it live?',
                fields: [
                    {
                        key: 'folder',
                        label: 'Folder',
                        type: 'select',
                        options: [
                            'Care planning',
                            'Clinical',
                            'Governance',
                            'Agreements',
                            'Admin',
                        ],
                    },
                    {
                        key: 'expiry_date',
                        label: 'Expiry / review date',
                        type: 'date',
                    },
                    {
                        key: 'portal_visible',
                        label: 'Visible on family portal',
                        desc: 'Whānau can open this from the portal.',
                        type: 'checkbox',
                        full: true,
                    },
                    {
                        key: 'notes',
                        label: 'Notes',
                        type: 'textarea',
                        rows: 2,
                        full: true,
                    },
                ],
            },
        ],
        submit: (values, helpers) => {
            submitInertia(
                'post',
                `/operations/clients/${ctx.clientId}/documents`,
                {
                    file: values.file as File,
                    title: opt(values.title),
                    category: str(values.category) || 'admin',
                    folder: opt(values.folder),
                    expiry_date: opt(values.expiry_date),
                    portal_visible: Boolean(values.portal_visible),
                    notes: opt(values.notes),
                },
                helpers,
                'Document uploaded',
            );
        },
    };
};

const transaction: FlowFactory = (ctx) => ({
    key: 'transaction',
    icon: DollarSign,
    title: 'New transaction',
    sub: 'Personal funds',
    submitLabel: 'Save transaction',
    steps: [
        {
            key: 'details',
            label: 'Details',
            icon: DollarSign,
            blurb: 'Type & amount',
            heading: 'Record the movement',
            picker: {
                key: 'type',
                label: 'Type',
                cols: 2,
                options: [
                    {
                        key: 'credit',
                        label: 'Credit',
                        icon: TrendingUp,
                        desc: 'Money in',
                    },
                    {
                        key: 'debit',
                        label: 'Debit',
                        icon: TrendingDown,
                        desc: 'Money out',
                    },
                ],
            },
            fields: [
                ...(ctx.fundOptions.length > 1
                    ? [
                          {
                              key: 'fund_id',
                              label: 'Fund',
                              type: 'select' as const,
                              required: true,
                              options: ctx.fundOptions,
                          },
                      ]
                    : []),
                {
                    key: 'amount',
                    label: 'Amount (NZD)',
                    required: true,
                    placeholder: '0.00',
                },
                {
                    key: 'description',
                    label: 'Description',
                    required: true,
                    full: true,
                },
            ],
        },
        {
            key: 'evidence',
            label: 'Evidence',
            icon: Wallet,
            blurb: 'Reference & witness',
            heading: 'Evidence',
            desc: 'A reference keeps the next reconciliation painless.',
            fields: [
                {
                    key: 'reference',
                    label: 'Reference / receipt no.',
                    full: true,
                    placeholder: 'e.g. Receipt #1042',
                },
            ],
        },
    ],
    submit: (values, helpers) => {
        const fundId = str(values.fund_id) || ctx.fundOptions[0]?.value;
        if (!fundId) {
            toast.error(
                'No personal fund exists for this client yet — create one under Client Funds.',
            );
            helpers.onError();
            return;
        }
        submitInertia(
            'post',
            `/operations/client-funds/${fundId}/transactions`,
            {
                type: str(values.type) || 'debit',
                amount: num(values.amount),
                description: str(values.description),
                reference: opt(values.reference),
            },
            helpers,
            'Transaction saved',
        );
    },
});

const addOnboardingStep: FlowFactory = (ctx) => ({
    key: 'add_onboarding_step',
    icon: CheckCircle2,
    title: 'Add onboarding step',
    sub: 'Onboarding checklist',
    submitLabel: 'Add step',
    again: true,
    steps: [
        {
            key: 'step',
            label: 'The step',
            icon: ListPlus,
            blurb: 'Category & name',
            heading: 'What needs to happen?',
            picker: {
                key: 'category',
                label: 'Category',
                cols: 2,
                options: [
                    {
                        key: 'documentation',
                        label: 'Documentation',
                        icon: FileText,
                        desc: 'Agreements, forms',
                    },
                    {
                        key: 'assessment',
                        label: 'Assessment',
                        icon: ClipboardCheck,
                        desc: 'Risk, medical, needs',
                    },
                    {
                        key: 'clinical',
                        label: 'Clinical',
                        icon: Stethoscope,
                        desc: 'GP, meds, SLT',
                    },
                    {
                        key: 'governance',
                        label: 'Governance',
                        icon: Shield,
                        desc: 'Consents, contacts',
                    },
                ],
            },
            fields: [
                {
                    key: 'step_name',
                    label: 'Step',
                    required: true,
                    full: true,
                    placeholder: 'e.g. Emergency contacts confirmed',
                },
                {
                    key: 'notes',
                    label: 'Notes',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
        },
        {
            key: 'assign',
            label: 'Assign',
            icon: UserCheck,
            blurb: 'Owner & due date',
            heading: 'Who owns it?',
            fields: [
                {
                    key: 'assigned_to',
                    label: 'Assign to',
                    type: 'select',
                    options: ctx.staffOptions,
                },
                { key: 'due_date', label: 'Due date', type: 'date' },
                {
                    key: 'is_required',
                    label: 'Required to complete onboarding',
                    desc: "Onboarding can't be closed while this step is open.",
                    type: 'checkbox',
                    full: true,
                },
            ],
        },
    ],
    initialValues: { is_required: true },
    submit: (values, helpers) => {
        if (!ctx.onboardingWorkflowId) {
            toast.error('Start the onboarding workflow first.');
            helpers.onError();
            return;
        }
        submitInertia(
            'post',
            `/operations/onboarding/${ctx.onboardingWorkflowId}/steps`,
            {
                step_name: str(values.step_name),
                category: str(values.category) || undefined,
                assigned_to: num(values.assigned_to),
                due_date: opt(values.due_date),
                is_required: Boolean(values.is_required),
                notes: opt(values.notes),
            },
            helpers,
            'Step added to onboarding',
        );
    },
});

const addAssessment: FlowFactory = (ctx) => ({
    key: 'add_assessment',
    icon: BookOpen,
    title: 'Add assessment',
    sub: 'Assessments',
    submitLabel: 'Save assessment',
    steps: [
        {
            key: 'what',
            label: 'Assessment',
            icon: BookOpen,
            blurb: 'Type & assessor',
            heading: 'Which assessment?',
            fields: [
                {
                    key: 'type',
                    label: 'Type',
                    type: 'select',
                    required: true,
                    options: [
                        'InterRAI',
                        'WHODAS 2.0',
                        'HoNOS',
                        'Needs Assessment (NASC)',
                        'Behaviour Support',
                        'Medication Review',
                        'Functional Assessment',
                        'SLT swallowing',
                        'Other',
                    ],
                },
                {
                    key: 'assessed_at',
                    label: 'Completed date',
                    type: 'date',
                    required: true,
                },
            ],
        },
        {
            key: 'outcome',
            label: 'Outcome',
            icon: FileCheck,
            blurb: 'Result & next review',
            heading: 'Outcome',
            fields: [
                { key: 'score', label: 'Score / result' },
                { key: 'next_review_at', label: 'Next review', type: 'date' },
                {
                    key: 'notes',
                    label: 'Summary',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
            ],
        },
    ],
    submit: (values, helpers) => {
        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/assessments`,
            {
                type: str(values.type),
                score: opt(values.score),
                assessed_at: opt(values.assessed_at),
                next_review_at: opt(values.next_review_at),
                notes: opt(values.notes),
            },
            helpers,
            'Assessment recorded',
        );
    },
});

const addAsset: FlowFactory = (ctx) => ({
    key: 'add_asset',
    icon: ListTodo,
    title: 'Add personal item',
    sub: 'Personal inventory',
    submitLabel: 'Add item',
    again: true,
    steps: [
        {
            key: 'item',
            label: 'The item',
            icon: ListTodo,
            blurb: 'Name, category & ownership',
            heading: 'What is the item?',
            picker: {
                key: 'ownership',
                label: 'Ownership',
                cols: 2,
                options: [
                    {
                        key: 'client',
                        label: 'Client owned',
                        icon: User,
                        desc: `Belongs to ${ctx.preferredName}`,
                    },
                    {
                        key: 'provider',
                        label: 'Provider owned',
                        icon: Building2,
                        desc: 'Service equipment',
                    },
                    {
                        key: 'funded',
                        label: 'Funded',
                        icon: Wallet,
                        desc: 'MoH / funder purchased',
                    },
                    {
                        key: 'loaned',
                        label: 'On loan',
                        icon: RefreshCw,
                        desc: 'Temporary',
                    },
                ],
            },
            fields: [
                { key: 'name', label: 'Item', required: true, full: true },
                {
                    key: 'category',
                    label: 'Category',
                    type: 'select',
                    required: true,
                    options: [
                        'Mobility Aid',
                        'Electronics',
                        'Furniture',
                        'Clothing',
                        'Medical Equipment',
                        'Personal Care',
                        'Entertainment',
                        'Transport',
                        'Other',
                    ],
                },
                { key: 'serial_number', label: 'Serial / identifier' },
            ],
        },
        {
            key: 'value',
            label: 'Value & condition',
            icon: Wallet,
            blurb: 'Worth, state & location',
            heading: 'Value & condition',
            fields: [
                {
                    key: 'estimated_value',
                    label: 'Value (NZD)',
                    placeholder: '0.00',
                },
                {
                    key: 'condition',
                    label: 'Condition',
                    type: 'select',
                    options: [
                        { value: 'new', label: 'New' },
                        { value: 'good', label: 'Good' },
                        { value: 'fair', label: 'Fair' },
                        { value: 'poor', label: 'Needs repair' },
                    ],
                },
                {
                    key: 'location',
                    label: 'Kept at',
                    placeholder: 'e.g. Bedroom, West Wing',
                },
                { key: 'acquired_at', label: 'Acquired on', type: 'date' },
                {
                    key: 'notes',
                    label: 'Notes',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
        },
    ],
    submit: (values, helpers) => {
        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/personal-assets`,
            {
                name: str(values.name),
                category: opt(values.category),
                ownership: str(values.ownership) || 'client',
                serial_number: opt(values.serial_number),
                estimated_value: num(values.estimated_value),
                condition: opt(values.condition),
                location: opt(values.location),
                acquired_at: opt(values.acquired_at),
                notes: opt(values.notes),
            },
            helpers,
            'Item added to inventory',
        );
    },
});

const appointment: FlowFactory = (ctx) => ({
    key: 'appointment',
    icon: Calendar,
    title: 'Schedule appointment',
    sub: 'Client calendar',
    submitLabel: 'Schedule',
    steps: [
        {
            key: 'what',
            label: 'What & who',
            icon: Calendar,
            blurb: 'Type & provider',
            heading: 'What kind of appointment?',
            picker: {
                key: 'appointment_type',
                label: 'Type',
                cols: 3,
                options: [
                    { key: 'gp_visit', label: 'GP visit', icon: Stethoscope },
                    {
                        key: 'specialist',
                        label: 'Specialist',
                        icon: HeartPulse,
                    },
                    { key: 'therapy', label: 'Therapy', icon: Activity },
                    { key: 'activity', label: 'Activity', icon: Calendar },
                    { key: 'reminder', label: 'Reminder', icon: Bell },
                    { key: 'other', label: 'Other', icon: Circle },
                ],
            },
            fields: [
                { key: 'title', label: 'Title', required: true, full: true },
                {
                    key: 'provider_name',
                    label: 'With',
                    placeholder: 'e.g. Dr. Lena Fox',
                },
            ],
        },
        {
            key: 'when',
            label: 'When & logistics',
            icon: Clock,
            blurb: 'Time, place & transport',
            heading: 'When and how?',
            fields: [
                {
                    key: 'starts_at',
                    label: 'Date & time',
                    type: 'datetime-local',
                    required: true,
                },
                { key: 'location', label: 'Location' },
                {
                    key: 'description',
                    label: 'Notes',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
                {
                    key: 'book_transport',
                    label: 'Book transport',
                    desc: 'Adds a transport booking linked to this appointment.',
                    type: 'checkbox',
                    full: true,
                },
                {
                    key: 'share_with_family',
                    label: 'Share with whānau',
                    desc: 'Shows on the family-portal calendar.',
                    type: 'checkbox',
                    full: true,
                },
            ],
        },
    ],
    initialValues: { share_with_family: true },
    submit: async (values, helpers) => {
        try {
            const token =
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '';
            const res = await fetch(
                `/clients/${ctx.clientId}/calendar/appointments`,
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        title: str(values.title),
                        appointment_type:
                            str(values.appointment_type) || 'other',
                        starts_at: str(values.starts_at),
                        location: opt(values.location),
                        provider_name: opt(values.provider_name),
                        description: opt(values.description),
                        share_with_family: Boolean(values.share_with_family),
                    }),
                },
            );
            if (!res.ok) throw new Error('Failed to schedule appointment');

            if (values.book_transport) {
                router.post(
                    `/operations/clients/${ctx.clientId}/transport-bookings`,
                    {
                        purpose: str(values.title),
                        destination: opt(values.location),
                        scheduled_at: str(values.starts_at),
                    },
                    { preserveScroll: true, preserveState: true },
                );
            }
            toast.success('Appointment scheduled');
            helpers.onDone();
            router.reload({ only: ['calendar_events'] });
        } catch {
            toast.error('Could not schedule the appointment.');
            helpers.onError();
        }
    },
});

const requestLeave: FlowFactory = (ctx) => ({
    key: 'request_leave',
    icon: CalendarDays,
    title: 'Request leave',
    sub: 'Leave & excursions',
    submitLabel: 'Submit request',
    steps: [
        {
            key: 'dates',
            label: 'Dates & type',
            icon: CalendarDays,
            blurb: 'When & with whom',
            heading: 'When is the leave?',
            picker: {
                key: 'leave_type',
                label: 'Leave type',
                cols: 2,
                options: [
                    {
                        key: 'Whānau stay',
                        label: 'Whānau stay',
                        icon: Home,
                        desc: 'Overnight with family',
                    },
                    {
                        key: 'Holiday',
                        label: 'Holiday',
                        icon: Plane,
                        desc: 'Trip away',
                    },
                    {
                        key: 'Hospital',
                        label: 'Hospital',
                        icon: Stethoscope,
                        desc: 'Planned admission',
                    },
                    { key: 'Other', label: 'Other', icon: Calendar },
                ],
            },
            fields: [
                {
                    key: 'starts_on',
                    label: 'Start',
                    type: 'date',
                    required: true,
                },
                {
                    key: 'ends_on',
                    label: 'Return',
                    type: 'date',
                    required: true,
                },
                {
                    key: 'emergency_contact',
                    label: 'With / contact while away',
                    required: true,
                    placeholder: 'e.g. Hana Wineera · 021 555 0871',
                },
                { key: 'destination', label: 'Location' },
            ],
        },
        {
            key: 'prep',
            label: 'Preparation',
            icon: ListChecks,
            blurb: 'Meds, diet & contacts',
            heading: 'Going-away checklist',
            fields: [
                {
                    key: 'checklist',
                    label: 'Prepared',
                    type: 'chips',
                    options: [
                        'Meds pack',
                        'Dietary guidance sent',
                        'Emergency contacts card',
                        'Seizure plan copy',
                        'Spending money',
                    ],
                },
                {
                    key: 'support_required',
                    label: 'Notes for whānau / support required',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
                {
                    key: 'risks_and_mitigations',
                    label: 'Risks & mitigations',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
            info: 'Approved leave pauses rostered shifts for these dates and notifies the coordinator.',
            infoIcon: CalendarClock,
        },
    ],
    submit: (values, helpers) => {
        const support = [
            `Leave type: ${str(values.leave_type) || 'Other'}`,
            Array.isArray(values.checklist) && values.checklist.length
                ? `Prepared: ${(values.checklist as string[]).join(', ')}`
                : null,
            opt(values.support_required),
        ]
            .filter(Boolean)
            .join('\n');

        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/leave`,
            {
                starts_on: str(values.starts_on),
                ends_on: str(values.ends_on),
                destination: opt(values.destination),
                support_required: support,
                risks_and_mitigations: opt(values.risks_and_mitigations),
                emergency_contact: str(values.emergency_contact),
                status: 'requested',
            },
            helpers,
            'Leave request submitted',
        );
    },
});

const planExcursion: FlowFactory = (ctx) => ({
    key: 'plan_excursion',
    icon: MapPin,
    title: 'Plan an excursion',
    sub: 'Leave & excursions',
    submitLabel: 'Plan excursion',
    steps: [
        {
            key: 'outing',
            label: 'The outing',
            icon: MapPin,
            blurb: 'Where & when',
            heading: 'Where are we going?',
            fields: [
                {
                    key: 'destination',
                    label: 'Destination',
                    required: true,
                    full: true,
                },
                {
                    key: 'starts_at',
                    label: 'Date & time',
                    type: 'datetime-local',
                    required: true,
                },
                { key: 'ends_at', label: 'Return', type: 'datetime-local' },
                {
                    key: 'activity_description',
                    label: 'Purpose',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
        },
        {
            key: 'safety',
            label: 'Staffing & safety',
            icon: ShieldCheck,
            blurb: 'Transport & risk checklist',
            heading: 'Staffing & safety',
            fields: [
                {
                    key: 'transport_method',
                    label: 'Transport',
                    type: 'select',
                    options: [
                        'House van',
                        'Taxi / Total Mobility',
                        'Public bus',
                        'Walking',
                        'Other',
                    ],
                },
                {
                    key: 'checklist',
                    label: 'Risk checklist',
                    type: 'chips',
                    options: [
                        'Transport plan',
                        'Meds carried',
                        'Dietary plan',
                        'Communication card',
                        'Quiet-space plan',
                    ],
                },
                {
                    key: 'risk_assessment',
                    label: 'Risk notes',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
        },
    ],
    submit: (values, helpers) => {
        const risk = [
            Array.isArray(values.checklist) && values.checklist.length
                ? `Checklist: ${(values.checklist as string[]).join(', ')}`
                : null,
            opt(values.risk_assessment),
        ]
            .filter(Boolean)
            .join('\n');

        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/excursions`,
            {
                starts_at: str(values.starts_at),
                ends_at: opt(values.ends_at),
                destination: str(values.destination),
                activity_description: opt(values.activity_description),
                transport_method: opt(values.transport_method),
                risk_assessment: risk || undefined,
                status: 'proposed',
            },
            helpers,
            'Excursion planned',
        );
    },
});

const transportBooking: FlowFactory = (ctx) => ({
    key: 'transport_booking',
    icon: Truck,
    title: 'Book transport',
    sub: 'Transport',
    submitLabel: 'Book',
    steps: [
        {
            key: 'trip',
            label: 'The trip',
            icon: RouteIcon,
            blurb: 'Where & when',
            heading: 'Trip details',
            fields: [
                {
                    key: 'purpose',
                    label: 'Purpose',
                    required: true,
                    full: true,
                    placeholder: 'e.g. GP appointment',
                },
                { key: 'destination', label: 'Destination', required: true },
                {
                    key: 'scheduled_at',
                    label: 'Date & time',
                    type: 'datetime-local',
                    required: true,
                },
            ],
        },
        {
            key: 'vehicle',
            label: 'Vehicle & driver',
            icon: Truck,
            blurb: 'Van, driver & escort',
            heading: 'Vehicle & driver',
            fields: [
                {
                    key: 'vehicle',
                    label: 'Vehicle',
                    type: 'select',
                    required: true,
                    options: [
                        'House van',
                        'Taxi / Total Mobility',
                        'Public bus',
                        'Staff car',
                    ],
                },
                {
                    key: 'driver_id',
                    label: 'Driver',
                    type: 'select',
                    options: ctx.staffOptions,
                },
                {
                    key: 'escort_required',
                    label: 'Escort travels',
                    desc: 'A second staff member rides along.',
                    type: 'checkbox',
                    full: true,
                },
                {
                    key: 'return_trip',
                    label: 'Book return trip',
                    desc: 'Same vehicle waits or returns.',
                    type: 'checkbox',
                    full: true,
                },
                {
                    key: 'notes',
                    label: 'Notes',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
        },
    ],
    submit: (values, helpers) => {
        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/transport-bookings`,
            {
                purpose: str(values.purpose),
                destination: opt(values.destination),
                scheduled_at: str(values.scheduled_at),
                vehicle: opt(values.vehicle),
                driver_id: num(values.driver_id),
                escort_required: Boolean(values.escort_required),
                return_trip: Boolean(values.return_trip),
                notes: opt(values.notes),
            },
            helpers,
            'Transport booked',
            { reloadProps: ['transport'] },
        );
    },
});

const respiteBooking: FlowFactory = (ctx) => ({
    key: 'respite_booking',
    icon: CalendarDays,
    title: 'New respite booking',
    sub: 'Respite',
    submitLabel: 'Request booking',
    steps: [
        {
            key: 'booking',
            label: 'Booking',
            icon: CalendarDays,
            blurb: 'Dates & requirements',
            heading: 'Booking details',
            fields: [
                {
                    key: 'requested_start',
                    label: 'Start',
                    type: 'date',
                    required: true,
                },
                {
                    key: 'requested_end',
                    label: 'End',
                    type: 'date',
                    required: true,
                },
            ],
        },
        {
            key: 'prep',
            label: 'Preparation',
            icon: ListChecks,
            blurb: `What goes with ${ctx.preferredName}`,
            heading: 'Preparation',
            fields: [
                {
                    key: 'requirements',
                    label: 'Prepared',
                    type: 'chips',
                    options: [
                        'Meds pack',
                        'Dietary guidance',
                        'Rhythms & routines summary',
                        'Seizure plan',
                        'Comfort items',
                    ],
                },
                {
                    key: 'preference_notes',
                    label: 'Notes for provider',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
            ],
            info: 'The respite coordinator confirms the booking from the Respite workspace.',
            infoIcon: CalendarClock,
        },
    ],
    submit: (values, helpers) => {
        submitInertia(
            'post',
            '/respite/requests',
            {
                client_id: ctx.clientId,
                requested_start: str(values.requested_start),
                requested_end: str(values.requested_end),
                requirements: Array.isArray(values.requirements)
                    ? values.requirements
                    : [],
                preference_notes: opt(values.preference_notes),
            },
            helpers,
            'Respite booking requested',
        );
    },
});

const consentRecord: FlowFactory = (ctx) => ({
    key: 'consent_record',
    icon: Shield,
    title: 'Record consent',
    sub: 'Consents',
    submitLabel: 'Record consent',
    steps: [
        {
            key: 'decision',
            label: 'Type & decision',
            icon: Shield,
            blurb: 'What is being consented',
            heading: 'What is being consented to?',
            picker: {
                key: 'status',
                label: 'Decision',
                cols: 2,
                options: [
                    {
                        key: 'given',
                        label: 'Given',
                        icon: CheckCircle2,
                        desc: 'Consent given',
                    },
                    {
                        key: 'refused',
                        label: 'Refused',
                        icon: XCircle,
                        desc: 'Consent refused',
                    },
                ],
            },
            fields: [
                {
                    key: 'consent_type_id',
                    label: 'Consent type',
                    type: 'select',
                    required: true,
                    options: ctx.consentTypeOptions,
                },
                {
                    key: 'given_by_relationship',
                    label: 'Decision by',
                    required: true,
                    placeholder: 'e.g. Hana Wineera (sister, EPOA)',
                },
                {
                    key: 'given_method',
                    label: 'Method',
                    type: 'select',
                    required: true,
                    options: [
                        { value: 'written', label: 'Written' },
                        { value: 'verbal', label: 'Verbal · witnessed' },
                        { value: 'electronic', label: 'Electronic / portal' },
                    ],
                },
            ],
        },
        {
            key: 'evidence',
            label: 'Evidence & capacity',
            icon: FileCheck,
            blurb: 'Capacity & paperwork',
            heading: 'Evidence & capacity',
            fields: [
                {
                    key: 'capacity_assessed',
                    label: 'Capacity assessed',
                    desc: `${ctx.preferredName}'s capacity to consent was considered and recorded.`,
                    type: 'checkbox',
                    full: true,
                },
                {
                    key: 'signed_document',
                    label: 'Signed form / evidence',
                    type: 'file',
                    full: true,
                },
                {
                    key: 'special_conditions',
                    label: 'Conditions or limits',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                    placeholder: 'e.g. Photos for internal use only',
                },
            ],
        },
        {
            key: 'dates',
            label: 'Dates',
            icon: CalendarClock,
            blurb: 'Given & expiry',
            heading: 'Dates',
            fields: [
                {
                    key: 'given_at',
                    label: 'Given on',
                    type: 'date',
                    required: true,
                },
                { key: 'expires_at', label: 'Expires / review', type: 'date' },
                {
                    key: 'given_notes',
                    label: 'Notes',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
        },
    ],
    submit: (values, helpers) => {
        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/consents`,
            {
                consent_type_id: num(values.consent_type_id),
                status: str(values.status) || 'given',
                given_method: str(values.given_method) || 'written',
                given_at: str(values.given_at),
                given_by_relationship: opt(values.given_by_relationship),
                given_notes: opt(values.given_notes),
                special_conditions: opt(values.special_conditions),
                expires_at: opt(values.expires_at),
                capacity_assessed: Boolean(values.capacity_assessed),
                signed_document:
                    values.signed_document instanceof File
                        ? values.signed_document
                        : undefined,
            },
            helpers,
            'Consent recorded',
        );
    },
});

const addRelationship: FlowFactory = (ctx) => ({
    key: 'add_relationship',
    icon: Users,
    title: 'Add relationship',
    sub: 'Family tree',
    submitLabel: 'Add person',
    again: true,
    steps: [
        {
            key: 'person',
            label: 'The person',
            icon: User,
            blurb: 'Who they are',
            heading: 'Who is this person?',
            fields: [
                { key: 'name', label: 'Full name', required: true },
                {
                    key: 'relationship',
                    label: 'Relationship',
                    type: 'select',
                    required: true,
                    options: [
                        'Mother',
                        'Father',
                        'Sister',
                        'Brother',
                        'Grandparent',
                        'Aunt/Uncle',
                        'Cousin',
                        'Legal Guardian',
                        'Friend',
                        'Advocate',
                        'Other',
                    ],
                },
                { key: 'phone', label: 'Phone' },
                { key: 'email', label: 'Email' },
                { key: 'address', label: 'Address', full: true },
                {
                    key: 'preferred_method',
                    label: 'Preferred contact method',
                    type: 'select',
                    options: [
                        { value: 'phone', label: 'Call' },
                        { value: 'text', label: 'Text' },
                        { value: 'email', label: 'Email' },
                    ],
                },
            ],
        },
        {
            key: 'role',
            label: 'Role & permissions',
            icon: Shield,
            blurb: 'Contact status & visibility',
            heading: 'Role & permissions',
            fields: [
                {
                    key: 'is_primary_contact',
                    label: 'Primary contact',
                    desc: 'First person we call for decisions.',
                    type: 'checkbox',
                    full: true,
                },
                {
                    key: 'can_view_medical',
                    label: 'Can view medical',
                    desc: 'Medical profile on the portal.',
                    type: 'checkbox',
                },
                {
                    key: 'can_view_medications',
                    label: 'Can view medications',
                    desc: 'Medication list.',
                    type: 'checkbox',
                },
                {
                    key: 'can_view_incidents',
                    label: 'Can view incidents',
                    desc: 'Closed incident summaries.',
                    type: 'checkbox',
                },
                {
                    key: 'can_receive_updates',
                    label: 'Receives updates',
                    desc: 'Summary emails and notices.',
                    type: 'checkbox',
                },
                {
                    key: 'notes',
                    label: 'Notes',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                },
            ],
        },
    ],
    submit: (values, helpers) => {
        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/medical/emergency-contacts`,
            {
                name: str(values.name),
                relationship: opt(values.relationship),
                phone: opt(values.phone),
                email: opt(values.email),
                address: opt(values.address),
                preferred_method: opt(values.preferred_method),
                is_primary_contact: Boolean(values.is_primary_contact),
                can_view_medical: Boolean(values.can_view_medical),
                can_view_medications: Boolean(values.can_view_medications),
                can_view_incidents: Boolean(values.can_view_incidents),
                can_receive_updates: Boolean(values.can_receive_updates),
                notes: opt(values.notes),
            },
            helpers,
            'Relationship added',
        );
    },
});

const portalInvite: FlowFactory = (ctx) => ({
    key: 'portal_invite',
    icon: Send,
    title: 'Invite to family portal',
    sub: 'Family portal',
    submitLabel: 'Send invite',
    steps: [
        {
            key: 'person',
            label: 'The person',
            icon: UserPlus,
            blurb: 'Who gets access',
            heading: 'Who are we inviting?',
            fields: [
                { key: 'name', label: 'Name', required: true },
                {
                    key: 'relation',
                    label: 'Relationship',
                    type: 'select',
                    required: true,
                    options: [
                        'mother',
                        'father',
                        'brother',
                        'sister',
                        'aunt',
                        'uncle',
                        'grandmother',
                        'grandfather',
                        'daughter',
                        'son',
                        'spouse',
                        'partner',
                        'guardian',
                        'carer',
                        'friend',
                        'other',
                    ].map((value) => ({
                        value,
                        label: value.charAt(0).toUpperCase() + value.slice(1),
                    })),
                },
                {
                    key: 'email',
                    label: 'Email',
                    required: true,
                    full: true,
                    placeholder: 'The invite is sent here',
                },
            ],
            info: 'They receive a set-password email and sign in to the family portal. What they can see is governed by consents and the per-contact permissions on the Family Tree.',
            infoIcon: Shield,
        },
    ],
    submit: (values, helpers) => {
        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/portal-users`,
            {
                email: str(values.email),
                name: str(values.name),
                relation: str(values.relation),
                portal_role: 'next_of_kin',
                action: 'create_user',
            },
            helpers,
            'Portal invite sent',
        );
    },
});

const addAction: FlowFactory = (ctx) => ({
    key: 'add_action',
    icon: ListTodo,
    title: 'Add action / review',
    sub: 'Actions & reviews',
    submitLabel: 'Create action',
    steps: [
        {
            key: 'action',
            label: 'The action',
            icon: ListTodo,
            blurb: 'What needs doing',
            heading: 'What needs doing?',
            picker: {
                key: 'kind',
                label: 'Type',
                options: [
                    {
                        key: 'follow_up',
                        label: 'Follow-up',
                        icon: CornerDownRight,
                        desc: 'From a note / event',
                    },
                    {
                        key: 'review',
                        label: 'Scheduled review',
                        icon: CalendarClock,
                        desc: 'Risk, plan, document',
                    },
                    {
                        key: 'task',
                        label: 'Task',
                        icon: CheckSquare,
                        desc: 'One-off to-do',
                    },
                ],
            },
            fields: [
                {
                    key: 'summary',
                    label: 'Summary',
                    required: true,
                    full: true,
                },
                {
                    key: 'detail',
                    label: 'Detail',
                    type: 'textarea',
                    rows: 3,
                    full: true,
                },
            ],
        },
        {
            key: 'schedule',
            label: 'Schedule',
            icon: CalendarClock,
            blurb: 'Due date & priority',
            heading: 'When is it due?',
            picker: {
                key: 'priority',
                label: 'Priority',
                options: [
                    {
                        key: 'normal',
                        label: 'Normal',
                        icon: Circle,
                        desc: 'Routine',
                    },
                    {
                        key: 'important',
                        label: 'Important',
                        icon: FileWarning,
                        desc: 'Due this week',
                    },
                    {
                        key: 'critical',
                        label: 'Critical',
                        icon: AlertTriangle,
                        desc: 'Safety-related',
                    },
                ],
            },
            fields: [
                { key: 'due', label: 'Due date', type: 'date', required: true },
            ],
            info: 'Actions appear in Actions & Reviews and on the Overview until completed.',
            infoIcon: ListTodo,
        },
    ],
    submit: (values, helpers) => {
        const critical = values.priority === 'critical';
        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/daily-notes`,
            {
                type: 'note',
                category: str(values.kind) || 'task',
                subject: str(values.summary),
                body: opt(values.detail) ?? str(values.summary),
                follow_up_action: str(values.summary),
                follow_up_due_at: str(values.due),
                is_flagged: critical,
                flagged_reason: critical
                    ? 'Critical action raised from Actions & Reviews'
                    : undefined,
                appears_on_timeline: false,
                visibility: 'internal',
            },
            helpers,
            'Action created',
        );
    },
});

const addTimelineNote: FlowFactory = (ctx) => {
    const title = str(ctx.dialog?.title) || 'Add note';
    return {
        key: 'add_note',
        icon: ClipboardList,
        title,
        sub: 'Timeline entry',
        submitLabel: 'Save note',
        steps: [
            {
                key: 'type',
                label: 'Type',
                icon: StickyNote,
                blurb: 'Note, progress or handover',
                heading: title,
                desc: "Added to this client's timeline.",
                picker: {
                    key: 'type',
                    label: 'Type',
                    cols: 2,
                    options: [
                        {
                            key: 'note',
                            label: 'Note',
                            icon: StickyNote,
                            desc: 'General note',
                        },
                        {
                            key: 'progress_note',
                            label: 'Progress',
                            icon: TrendingUp,
                            desc: 'Progress update',
                        },
                        {
                            key: 'shift_note',
                            label: 'Shift',
                            icon: Clock,
                            desc: 'Shift note',
                        },
                        {
                            key: 'handover',
                            label: 'Handover',
                            icon: RefreshCw,
                            desc: 'Brief the next shift',
                        },
                    ],
                },
                fields: [
                    { key: 'subject', label: 'Short heading', full: true },
                    {
                        key: 'body',
                        label: 'Detail',
                        type: 'textarea',
                        required: true,
                        rows: 4,
                        full: true,
                    },
                ],
            },
            {
                key: 'route',
                label: 'Routing',
                icon: Send,
                blurb: 'Timing & visibility',
                heading: 'Routing',
                desc: 'When it happened and who can see it.',
                fields: [
                    {
                        key: 'occurred_at',
                        label: 'Occurred at',
                        type: 'datetime-local',
                    },
                    {
                        key: 'visibility',
                        label: 'Visibility',
                        type: 'select',
                        options: [
                            {
                                value: 'internal',
                                label: 'Internal (staff only)',
                            },
                            { value: 'portal', label: 'Family portal' },
                        ],
                    },
                    {
                        key: 'pin',
                        label: 'Pin to handover',
                        desc: 'Keep at the top for the next shift (handover notes only).',
                        type: 'checkbox',
                        full: true,
                        when: (v) => v.type === 'handover',
                    },
                ],
            },
        ],
        submit: (values, helpers) => {
            submitInertia(
                'post',
                `/operations/clients/${ctx.clientId}/notes`,
                {
                    type: str(values.type) || 'note',
                    subject: opt(values.subject),
                    body: str(values.body),
                    occurred_at: opt(values.occurred_at),
                    visibility: str(values.visibility) || 'internal',
                    pin: Boolean(values.pin),
                },
                helpers,
                'Note added',
            );
        },
    };
};

const RHYTHM_BLOCK_OPTIONS = [
    {
        key: 'morning',
        label: 'Morning',
        icon: Sunrise,
        desc: 'Wake-up, hygiene, meds, breakfast',
    },
    {
        key: 'day',
        label: 'Day',
        icon: Sun,
        desc: 'Activities, appointments, meals',
    },
    {
        key: 'evening',
        label: 'Evening',
        icon: Coffee,
        desc: 'Dinner, wind-down, bedtime prep',
    },
    {
        key: 'overnight',
        label: 'Overnight',
        icon: Moon,
        desc: 'Sleep, checks, escalation',
    },
    {
        key: 'preferences',
        label: 'Preferences',
        icon: ThumbsUp,
        desc: 'How support is offered & paced',
    },
    {
        key: 'triggers',
        label: 'Triggers',
        icon: ShieldAlert,
        desc: 'Stressors & early warning signs',
    },
    {
        key: 'calming',
        label: 'Calming',
        icon: TimerReset,
        desc: 'What settles & reassures',
    },
    {
        key: 'what_works',
        label: 'What works',
        icon: CheckCircle2,
        desc: 'Reliable language & routines',
    },
    {
        key: 'avoid',
        label: 'Avoid',
        icon: XCircle,
        desc: 'What makes support harder',
    },
];

const editRhythms: FlowFactory = (ctx) => ({
    key: 'edit_rhythms',
    icon: Clock,
    title: 'Update rhythms & routines',
    sub: 'Daily guidance',
    submitLabel: 'Save guidance',
    again: true,
    initialValues: ctx.dialog?.values as WizardValues | undefined,
    steps: [
        {
            key: 'block',
            label: 'Which block',
            icon: LayoutGrid,
            blurb: 'Time of day or guidance',
            heading: 'Which part of the day?',
            desc: 'Each block is a living document — staff read these at handover.',
            picker: {
                key: 'block',
                label: 'Block',
                options: RHYTHM_BLOCK_OPTIONS,
                cols: 3,
            },
        },
        {
            key: 'guidance',
            label: 'The guidance',
            icon: Pencil,
            blurb: 'What staff should know',
            heading: 'What should staff know?',
            desc: 'Concrete and observable — times, signs, exact phrases that work.',
            fields: [
                {
                    key: 'body',
                    label: 'Guidance',
                    type: 'textarea',
                    required: true,
                    rows: 6,
                    full: true,
                    placeholder:
                        'e.g. Wakes naturally ~7:30 — watch for early waking before 6…',
                },
            ],
            info: 'Updates are stamped with your name and date, and show in the audit history.',
            infoIcon: Clock,
        },
    ],
    submit: (values, helpers) => {
        submitInertia(
            'post',
            `/operations/clients/${ctx.clientId}/routines/${str(values.block) || 'morning'}`,
            { body: str(values.body) },
            helpers,
            'Routine guidance updated',
        );
    },
});

const mealPref: FlowFactory = (ctx) => ({
    key: 'meal_pref',
    icon: Utensils,
    title: 'Add food & meal preference',
    sub: 'Food & meal',
    submitLabel: 'Save preference',
    again: true,
    steps: [
        {
            key: 'pref',
            label: 'Preference',
            icon: Utensils,
            blurb: 'Kind & detail',
            heading: 'Add a preference',
            picker: {
                key: 'kind',
                label: 'Kind',
                cols: 2,
                options: [
                    {
                        key: 'dislike',
                        label: 'Dislike',
                        icon: XCircle,
                        desc: 'Avoid serving',
                    },
                    {
                        key: 'note',
                        label: 'Dietary note',
                        icon: Leaf,
                        desc: 'Preference / need',
                    },
                ],
            },
            fields: [
                {
                    key: 'free_text_name',
                    label: 'Food / detail',
                    required: true,
                    full: true,
                },
                {
                    key: 'notes',
                    label: 'Notes',
                    type: 'textarea',
                    rows: 2,
                    full: true,
                    placeholder: 'Severity, context, alternatives…',
                },
            ],
            info: 'Safety-critical allergies live on the Medical tab so they surface in the hero safety strip.',
            infoIcon: AlertTriangle,
            infoTone: 'warn',
        },
    ],
    submit: (values, helpers) => {
        submitInertia(
            'post',
            `/clients/${ctx.clientId}/meal-preferences/dislikes`,
            {
                free_text_name: str(values.free_text_name),
                notes:
                    values.kind === 'note'
                        ? `Dietary note: ${opt(values.notes) ?? ''}`.trim()
                        : opt(values.notes),
            },
            helpers,
            'Preference saved',
        );
    },
});

/* ------------------------------------------------------------------ registry */

export const PROFILE_FLOWS: Record<string, FlowFactory> = {
    log_incident: logIncident,
    add_risk: addRisk,
    edit_risk: addRisk,
    record_obs: recordObs,
    abc_entry: abcEntry,
    plan_review: planReview,
    upload_doc: uploadDoc,
    transaction,
    add_onboarding_step: addOnboardingStep,
    add_assessment: addAssessment,
    add_asset: addAsset,
    appointment,
    request_leave: requestLeave,
    plan_excursion: planExcursion,
    transport_booking: transportBooking,
    respite_booking: respiteBooking,
    consent_record: consentRecord,
    add_relationship: addRelationship,
    portal_invite: portalInvite,
    add_action: addAction,
    add_note: addTimelineNote,
    edit_rhythms: editRhythms,
    meal_pref: mealPref,
    edit_path_plan: editPathPlan,
};
