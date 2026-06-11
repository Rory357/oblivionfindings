/* Care & Support Plan wizard for the client-profile Care & Support Plan tab. ONE
 * dialog, two modes, both rendered through the shared WizardShell so they match
 * the Add Client / goal wizard UX:
 *   - create  (no plan passed)  → 6 steps: basics → about me → needs → domains →
 *                                 EGL & funding → review & save
 *   - edit    (plan passed)     → same 5 working panes, persistent "Save changes"
 * The plan "owns structure" (domains, person-centred content, EGL, funding); goals
 * live in the Goals Path tab. Submits map to CarePlanController store()/update(),
 * which now redirect back to the profile's Care & Support Plan tab. The whole
 * `content` object is sent on every save because update() overwrites content
 * wholesale. */
import { CarePlanDomainsBuilder, type CarePlanDomainDraft } from '@/components/care-plan-domains-builder';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ChipMulti,
    Field,
    InfoCard,
    Ring,
    SelectInput,
    Segmented,
    StepHead,
    TilePicker,
    type IconType,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import {
    Activity,
    Briefcase,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Compass,
    Globe,
    GraduationCap,
    Heart,
    Home,
    LayoutGrid,
    Loader2,
    MapPin,
    MessageSquare,
    Shield,
    Sparkle,
    Sparkles,
    Target,
    User,
    Users,
    Wallet,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

/* ------------------------------------------------------------------ types */

type SelectOption = { value: string; label: string };

type AboutMe = {
    dreams: string;
    important_to_me: string;
    important_for_me: string;
    ideal_day: string;
    likes: string;
    dislikes: string;
    how_to_support: string;
};

type FundingState = {
    nasc_organisation: string;
    needs_assessment_ref: string;
    needs_assessment_date: string;
    service_agreement_id: string;
    allocated_hours: string;
    funding_notes: string;
};

export type CarePlanForEdit = {
    id: number;
    title?: string | null;
    plan_type?: string | null;
    status?: string | null;
    starts_at?: string | null;
    ends_at?: string | null;
    next_review_at?: string | null;
    content?: unknown;
};

/* ------------------------------------------------------------------ constants */

const PLAN_TYPE_OPTIONS: SelectOption[] = [
    { value: 'support_plan', label: 'Support plan' },
    { value: 'behaviour_plan', label: 'Behaviour plan' },
    { value: 'health_plan', label: 'Health plan' },
    { value: 'transition_plan', label: 'Transition plan' },
];

type TemplateDef = {
    key: string;
    label: string;
    description: string;
    icon: IconType;
    plan_type: string;
    support_needs: Record<string, boolean>;
    support_strategies: string;
    frequency_months: number;
};

const TEMPLATES: TemplateDef[] = [
    {
        key: 'standard_support',
        label: 'Support Plan',
        description: '4 support areas · quarterly review · independence & community focus',
        icon: ClipboardList,
        plan_type: 'support_plan',
        support_needs: { daily_living: true, personal_care: true, community_access: true, social_participation: true },
        support_strategies: 'Goals-based support focused on independence and community participation.',
        frequency_months: 3,
    },
    {
        key: 'behaviour_support',
        label: 'Behaviour Support Plan',
        description: '3 support areas · quarterly review · positive behaviour support',
        icon: Shield,
        plan_type: 'behaviour_plan',
        support_needs: { behaviour_support: true, communication: true, health_management: true },
        support_strategies: 'Positive behaviour support framework with proactive and reactive strategies.',
        frequency_months: 3,
    },
    {
        key: 'health_wellbeing',
        label: 'Health & Wellbeing Plan',
        description: '3 support areas · 6-monthly review · allied-health coordination',
        icon: Activity,
        plan_type: 'health_plan',
        support_needs: { health_management: true, personal_care: true, daily_living: true },
        support_strategies: 'Health monitoring and wellbeing support with allied health coordination.',
        frequency_months: 6,
    },
    {
        key: 'transition',
        label: 'Transition Plan',
        description: '4 support areas · monthly review · milestone-based independence',
        icon: Compass,
        plan_type: 'transition_plan',
        support_needs: { daily_living: true, employment: true, education_training: true, community_access: true },
        support_strategies: 'Structured transition support with milestone-based goals and gradual independence building.',
        frequency_months: 1,
    },
    {
        key: 'blank',
        label: 'Start blank',
        description: 'A fully custom plan — choose everything yourself',
        icon: Sparkles,
        plan_type: 'support_plan',
        support_needs: {},
        support_strategies: '',
        frequency_months: 3,
    },
];

const SUPPORT_NEED_OPTIONS: { key: string; label: string; icon: IconType }[] = [
    { key: 'daily_living', label: 'Daily living', icon: Home },
    { key: 'personal_care', label: 'Personal care', icon: User },
    { key: 'community_access', label: 'Community access', icon: MapPin },
    { key: 'health_management', label: 'Health management', icon: Activity },
    { key: 'communication', label: 'Communication', icon: MessageSquare },
    { key: 'behaviour_support', label: 'Behaviour support', icon: Shield },
    { key: 'employment', label: 'Employment', icon: Briefcase },
    { key: 'education_training', label: 'Education / training', icon: GraduationCap },
    { key: 'social_participation', label: 'Social participation', icon: Users },
    { key: 'cultural_needs', label: 'Cultural needs', icon: Globe },
    { key: 'spiritual_needs', label: 'Spiritual needs', icon: Sparkle },
    { key: 'financial_management', label: 'Financial management', icon: Wallet },
];

const FREQUENCY_OPTIONS: SelectOption[] = [
    { value: '1', label: 'Monthly' },
    { value: '3', label: 'Quarterly' },
    { value: '6', label: '6-monthly' },
    { value: '12', label: 'Annually' },
];

const EGL_PRINCIPLES = [
    'Self-determination',
    'Beginning early',
    'Person-centred',
    'Ordinary life outcomes',
    'Mainstream first',
    'Mana-enhancing',
    'Easy to use',
    'Relationship building',
];

const ABOUT_FIELDS: { key: keyof AboutMe; label: string; placeholder: string }[] = [
    { key: 'dreams', label: 'My dreams & aspirations', placeholder: 'Big hopes and goals for the future…' },
    { key: 'important_to_me', label: "What's important TO me", placeholder: 'Relationships, routines, interests, passions…' },
    { key: 'important_for_me', label: "What's important FOR me", placeholder: 'Health, safety and wellbeing needs to maintain…' },
    { key: 'ideal_day', label: 'My ideal day', placeholder: 'What a good day looks like — routines, activities, social time…' },
    { key: 'likes', label: 'Things I like', placeholder: 'Favourite foods, activities, music, places, people…' },
    { key: 'dislikes', label: "Things I don't like", placeholder: 'Things to avoid, triggers, dislikes…' },
    { key: 'how_to_support', label: 'How to support me best', placeholder: 'Tips for support workers — communication style, motivation, boundaries…' },
];

const EMPTY_ABOUT: AboutMe = {
    dreams: '', important_to_me: '', important_for_me: '', ideal_day: '', likes: '', dislikes: '', how_to_support: '',
};
const EMPTY_FUNDING: FundingState = {
    nasc_organisation: '', needs_assessment_ref: '', needs_assessment_date: '', service_agreement_id: '', allocated_hours: '', funding_notes: '',
};

const STATUS_CREATE: { value: string; label: string }[] = [
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
];
const STATUS_EDIT: { value: string; label: string }[] = [
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
    { value: 'review', label: 'In review' },
    { value: 'archived', label: 'Archived' },
];

/* ------------------------------------------------------------------ helpers */

const str = (v: unknown): string => String(v ?? '').trim();
const opt = (v: unknown): string | undefined => (str(v) ? str(v) : undefined);
const num = (v: unknown): number | undefined => {
    const p = parseFloat(String(v ?? '').replace(/[^0-9.-]/g, ''));
    return Number.isFinite(p) ? p : undefined;
};
const day = (v: unknown): string => (typeof v === 'string' ? v.slice(0, 10) : '');

/** Add months to a YYYY-MM-DD string without any timezone/DST drift. */
function addMonths(dateStr: string, months: number): string {
    if (!dateStr) return '';
    const [y, m, d] = dateStr.split('-').map(Number);
    if (!y || !m || !d) return '';
    const base = m - 1 + months;
    const ny = y + Math.floor(base / 12);
    const nm = ((base % 12) + 12) % 12;
    const lastDay = new Date(ny, nm + 1, 0).getDate();
    const nd = Math.min(d, lastDay);
    return `${ny}-${String(nm + 1).padStart(2, '0')}-${String(nd).padStart(2, '0')}`;
}

function parseContent(raw: unknown): Record<string, unknown> {
    if (!raw) return {};
    if (typeof raw === 'string') {
        try {
            return JSON.parse(raw) as Record<string, unknown>;
        } catch {
            return {};
        }
    }
    return raw as Record<string, unknown>;
}

function normaliseDomains(arr: unknown): CarePlanDomainDraft[] {
    if (!Array.isArray(arr)) return [];
    return arr
        .filter((d) => d && typeof d === 'object')
        .map((raw, i) => {
            const d = raw as Record<string, unknown>;
            const strategies = Array.isArray(d.strategies) ? d.strategies : [];
            return {
                key: (d.key as string) || `domain_${i + 1}`,
                label: (d.label as string) ?? '',
                status: ((d.status as string) ?? 'active') as CarePlanDomainDraft['status'],
                strategies: (strategies.length ? strategies : [{ text: '', owner: '' }]).map((s) => {
                    const strat = s as Record<string, unknown>;
                    return { text: (strat.text as string) ?? '', owner: (strat.owner as string) ?? '' };
                }),
            };
        });
}

/* --------------------------------------------------------------- component */

export function CarePlanWizardDialog({
    open,
    onClose,
    clientId,
    clientLabel,
    preferredName,
    plan,
    staffOptions,
    serviceAgreementOptions,
    fromOnboarding,
}: {
    open: boolean;
    onClose: () => void;
    clientId: number;
    clientLabel?: string;
    preferredName?: string;
    /** Present → edit an existing plan; absent → create a new one. */
    plan?: CarePlanForEdit | null;
    staffOptions: SelectOption[];
    serviceAgreementOptions: SelectOption[];
    fromOnboarding?: boolean;
}) {
    const managing = Boolean(plan);

    const [stepIndex, setStepIndex] = useState(0);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const [template, setTemplate] = useState('');
    const [title, setTitle] = useState('');
    const [planType, setPlanType] = useState('support_plan');
    const [status, setStatus] = useState('draft');
    const [startsAt, setStartsAt] = useState('');
    const [endsAt, setEndsAt] = useState('');
    const [nextReviewAt, setNextReviewAt] = useState('');
    const [aboutMe, setAboutMe] = useState<AboutMe>(EMPTY_ABOUT);
    const [supportNeeds, setSupportNeeds] = useState<Record<string, boolean>>({});
    const [riskFactors, setRiskFactors] = useState('');
    const [supportStrategies, setSupportStrategies] = useState('');
    const [communicationPreferences, setCommunicationPreferences] = useState('');
    const [frequencyMonths, setFrequencyMonths] = useState(3);
    const [domains, setDomains] = useState<CarePlanDomainDraft[]>([]);
    const [eglVision, setEglVision] = useState('');
    const [eglPrinciples, setEglPrinciples] = useState<string[]>([]);
    const [funding, setFunding] = useState<FundingState>(EMPTY_FUNDING);

    // Re-seed whenever the dialog (re)opens.
    useEffect(() => {
        if (!open) return;
        setStepIndex(0);
        setBusy(false);
        setErrors({});
        setTemplate('');
        if (managing && plan) {
            const c = parseContent(plan.content);
            const f = (c.funding ?? {}) as Record<string, unknown>;
            setTitle(plan.title ?? '');
            setPlanType(plan.plan_type ?? 'support_plan');
            setStatus(plan.status ?? 'draft');
            setStartsAt(day(plan.starts_at));
            setEndsAt(day(plan.ends_at));
            setNextReviewAt(day(plan.next_review_at));
            setAboutMe({ ...EMPTY_ABOUT, ...((c.about_me as Partial<AboutMe>) ?? {}) });
            setSupportNeeds((c.support_needs as Record<string, boolean>) ?? {});
            setRiskFactors((c.risk_factors as string) ?? '');
            setSupportStrategies((c.support_strategies as string) ?? '');
            setCommunicationPreferences((c.communication_preferences as string) ?? '');
            setFrequencyMonths(((c.review_schedule as { frequency_months?: number })?.frequency_months) ?? 3);
            setDomains(normaliseDomains(c.domains));
            setEglVision(((c.egl as { vision?: string })?.vision) ?? '');
            setEglPrinciples(((c.egl as { principles?: string[] })?.principles) ?? []);
            setFunding({
                nasc_organisation: (f.nasc_organisation as string) ?? '',
                needs_assessment_ref: (f.needs_assessment_ref as string) ?? '',
                needs_assessment_date: day(f.needs_assessment_date),
                service_agreement_id: f.service_agreement_id != null ? String(f.service_agreement_id) : '',
                allocated_hours: f.allocated_hours != null ? String(f.allocated_hours) : '',
                funding_notes: (f.funding_notes as string) ?? '',
            });
        } else {
            setTitle('');
            setPlanType('support_plan');
            setStatus('draft');
            setStartsAt('');
            setEndsAt('');
            setNextReviewAt('');
            setAboutMe(EMPTY_ABOUT);
            setSupportNeeds({});
            setRiskFactors('');
            setSupportStrategies('');
            setCommunicationPreferences('');
            setFrequencyMonths(3);
            setDomains([]);
            setEglVision('');
            setEglPrinciples([]);
            setFunding(EMPTY_FUNDING);
        }
    }, [open, plan, managing]);

    const staffList = useMemo(
        () => staffOptions.map((o) => ({ id: Number(o.value), name: o.label })),
        [staffOptions],
    );
    const agreementOptions = useMemo<SelectOption[]>(
        () => [{ value: '__none', label: 'No linked agreement' }, ...serviceAgreementOptions],
        [serviceAgreementOptions],
    );

    /* ---------------------------------------------------------- rail + steps */

    const railSteps: WizardStep[] = useMemo(() => {
        const base: WizardStep[] = [
            { key: 'basics', label: 'Plan basics', blurb: 'Type, dates & status', icon: ClipboardList },
            { key: 'about_me', label: 'About me', blurb: "The person's voice", icon: Heart },
            { key: 'needs', label: 'Needs & risks', blurb: 'Support areas', icon: LayoutGrid },
            { key: 'domains', label: 'Domains', blurb: 'Strategies & owners', icon: Target },
            { key: 'egl_funding', label: 'EGL & funding', blurb: 'Vision & NASC', icon: Compass },
        ];
        return managing
            ? base
            : [...base, { key: '__review', label: 'Review & save', blurb: 'Confirm and create', icon: CheckCircle2 }];
    }, [managing]);

    const lastIndex = railSteps.length - 1;
    const stepKey = railSteps[stepIndex]?.key;
    const domainsStepIndex = railSteps.findIndex((s) => s.key === 'domains');

    const completeness = useMemo(() => {
        const signals = [
            Boolean(str(title) && planType),
            Object.values(aboutMe).some((v) => str(v)),
            Object.values(supportNeeds).some(Boolean) || Boolean(str(riskFactors)) || Boolean(str(supportStrategies)),
            domains.some((d) => str(d.label)),
            Boolean(str(eglVision)) || eglPrinciples.length > 0 || Boolean(str(funding.nasc_organisation)) || Boolean(funding.service_agreement_id),
        ];
        return Math.round((signals.filter(Boolean).length / signals.length) * 100);
    }, [title, planType, aboutMe, supportNeeds, riskFactors, supportStrategies, domains, eglVision, eglPrinciples, funding]);

    const basicsValid = Boolean(str(title) && planType);

    /* ---------------------------------------------------------- actions */

    const applyTemplate = (key: string) => {
        setTemplate(key);
        const t = TEMPLATES.find((x) => x.key === key);
        if (!t || key === 'blank') return;
        setPlanType(t.plan_type);
        if (!str(title)) setTitle(preferredName ? `${preferredName}'s ${t.label}` : t.label);
        setSupportNeeds((prev) => ({ ...prev, ...t.support_needs }));
        setSupportStrategies((prev) => prev || t.support_strategies);
        setFrequencyMonths(t.frequency_months);
        if (startsAt) setNextReviewAt(addMonths(startsAt, t.frequency_months));
    };

    const changeFrequency = (months: number) => {
        setFrequencyMonths(months);
        if (startsAt) setNextReviewAt(addMonths(startsAt, months));
    };

    const toggleSupportNeed = (key: string) =>
        setSupportNeeds((prev) => ({ ...prev, [key]: !prev[key] }));

    const buildContent = () => ({
        about_me: aboutMe,
        support_needs: supportNeeds,
        risk_factors: opt(riskFactors),
        support_strategies: opt(supportStrategies),
        communication_preferences: opt(communicationPreferences),
        review_schedule: { frequency_months: frequencyMonths },
        domains: domains
            .filter((d) => str(d.label))
            .map((d) => ({
                key: d.key,
                label: str(d.label),
                status: d.status,
                strategies: d.strategies
                    .filter((s) => str(s.text))
                    .map((s) => ({ text: str(s.text), owner: str(s.owner) })),
            })),
        egl: { vision: opt(eglVision), principles: eglPrinciples },
        funding: {
            nasc_organisation: opt(funding.nasc_organisation),
            needs_assessment_ref: opt(funding.needs_assessment_ref),
            needs_assessment_date: opt(funding.needs_assessment_date),
            service_agreement_id: funding.service_agreement_id ? Number(funding.service_agreement_id) : undefined,
            allocated_hours: funding.allocated_hours !== '' ? num(funding.allocated_hours) : undefined,
            funding_notes: opt(funding.funding_notes),
        },
    });

    const submit = () => {
        if (!basicsValid) {
            toast.error('A title and plan type are required.');
            setStepIndex(0);
            return;
        }
        setBusy(true);
        const payload: Record<string, FormDataConvertible> = {
            client_id: clientId,
            title: str(title),
            plan_type: planType,
            status: status || 'draft',
            starts_at: opt(startsAt) ?? '',
            ends_at: opt(endsAt) ?? '',
            next_review_at: opt(nextReviewAt) ?? '',
            content: buildContent() as unknown as FormDataConvertible,
        };
        if (!managing && fromOnboarding) payload.from_onboarding = '1';

        const options = {
            preserveScroll: true,
            onSuccess: (page: { props: Record<string, unknown> }) => {
                setBusy(false);
                const flash = (page.props as { flash?: { error?: string } }).flash;
                if (flash?.error) {
                    toast.error(flash.error);
                    return;
                }
                toast.success(managing ? 'Care plan updated' : 'Care plan created');
                onClose();
            },
            onError: (errs: Record<string, string>) => {
                setBusy(false);
                setErrors(errs ?? {});
                const first = Object.values(errs ?? {})[0];
                if (first) toast.error(String(first));
                if (Object.keys(errs ?? {}).some((k) => k.startsWith('content.domains'))) {
                    if (domainsStepIndex >= 0) setStepIndex(domainsStepIndex);
                } else if (errs?.title || errs?.plan_type || errs?.client_id || errs?.goals) {
                    setStepIndex(0);
                }
            },
        };

        if (managing && plan) {
            router.put(`/operations/care-plans/${plan.id}`, payload, options);
        } else {
            router.post('/operations/care-plans', payload, options);
        }
    };

    /* ---------------------------------------------------------- footer */

    const goBack = () => setStepIndex((i) => Math.max(0, i - 1));
    const goNext = () => setStepIndex((i) => Math.min(lastIndex, i + 1));

    const backBtn =
        stepIndex > 0 ? (
            <Button type="button" variant="ghost" onClick={goBack} disabled={busy}>
                <ChevronLeft className="mr-1 h-4 w-4" /> Back
            </Button>
        ) : null;

    let footerStart: React.ReactNode = backBtn;
    let footerEnd: React.ReactNode;

    if (managing) {
        footerStart = (
            <div className="flex items-center gap-2">
                {backBtn}
                {stepIndex < lastIndex ? (
                    <Button type="button" variant="ghost" onClick={goNext} disabled={busy}>
                        Next <ChevronRight className="ml-1 h-4 w-4" />
                    </Button>
                ) : null}
            </div>
        );
        footerEnd = (
            <>
                <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
                    Cancel
                </Button>
                <Button type="button" onClick={submit} disabled={busy || !basicsValid} data-test="careplan-save">
                    {busy ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <Check className="mr-1.5 h-4 w-4" />}
                    {busy ? 'Saving…' : 'Save changes'}
                </Button>
            </>
        );
    } else {
        const reviewing = stepIndex === lastIndex;
        footerEnd = (
            <>
                <Button type="button" variant="outline" onClick={onClose} disabled={busy}>
                    Cancel
                </Button>
                {reviewing ? (
                    <Button type="button" onClick={submit} disabled={busy || !basicsValid} data-test="careplan-create-submit">
                        {busy ? <Loader2 className="mr-1.5 h-4 w-4 animate-spin" /> : <Check className="mr-1.5 h-4 w-4" />}
                        {busy ? 'Saving…' : 'Create care plan'}
                    </Button>
                ) : (
                    <Button
                        type="button"
                        onClick={goNext}
                        disabled={stepIndex === 0 && !basicsValid}
                        data-test="careplan-continue"
                    >
                        Continue <ChevronRight className="ml-1 h-4 w-4" />
                    </Button>
                )}
            </>
        );
    }

    /* ---------------------------------------------------------- panes */

    return (
        <WizardShell
            open={open}
            onClose={() => !busy && onClose()}
            title={managing ? 'Edit care plan' : 'Create care plan'}
            description={managing ? 'Update the support plan' : 'Create a new care & support plan'}
            railIcon={Target}
            railTitle={managing ? (plan?.title ?? 'Care plan') : 'Create care plan'}
            railSub="Care & support plan"
            steps={railSteps}
            stepIndex={stepIndex}
            onStepClick={(i) => setStepIndex(i)}
            pct={completeness}
            pctLabel="Completeness"
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {/* ----------------------------------------------------- basics */}
            {stepKey === 'basics' ? (
                <WizardStepPane key="basics">
                    <StepHead
                        icon={ClipboardList}
                        title={managing ? 'Plan basics' : 'Start the plan'}
                        blurb="Name the plan, choose its type, and set the review rhythm."
                    />
                    {clientLabel ? <ClientChip label={clientLabel} /> : null}
                    {!managing ? (
                        <>
                            <p className="mb-1.5 text-sm font-medium">Start from a template</p>
                            <TilePicker
                                value={template}
                                onChange={applyTemplate}
                                cols={2}
                                options={TEMPLATES.map((t) => ({
                                    key: t.key,
                                    label: t.label,
                                    description: t.description,
                                    icon: t.icon,
                                }))}
                            />
                        </>
                    ) : null}
                    <div className="mt-4 grid gap-3.5 sm:grid-cols-2">
                        <Field label="Plan title" required span error={errors.title}>
                            <Input
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                placeholder="e.g. Tane's Support Plan 2026"
                            />
                        </Field>
                        <Field label="Plan type" required error={errors.plan_type}>
                            <SelectInput value={planType} onChange={setPlanType} placeholder="Select type…" options={PLAN_TYPE_OPTIONS} />
                        </Field>
                        <Field label="Status">
                            <Segmented value={status} onChange={setStatus} options={managing ? STATUS_EDIT : STATUS_CREATE} />
                        </Field>
                        <Field label="Start date">
                            <Input
                                type="date"
                                value={startsAt}
                                onChange={(e) => {
                                    setStartsAt(e.target.value);
                                    if (e.target.value && frequencyMonths) setNextReviewAt(addMonths(e.target.value, frequencyMonths));
                                }}
                            />
                        </Field>
                        <Field label="End date" error={errors.ends_at}>
                            <Input type="date" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} />
                        </Field>
                        <Field label="Review frequency">
                            <SelectInput
                                value={String(frequencyMonths)}
                                onChange={(v) => changeFrequency(Number(v))}
                                placeholder="Frequency…"
                                options={FREQUENCY_OPTIONS}
                            />
                        </Field>
                        <Field label="Next review" hint="auto-set from start + frequency">
                            <Input type="date" value={nextReviewAt} onChange={(e) => setNextReviewAt(e.target.value)} />
                        </Field>
                    </div>
                    <InfoCard icon={Compass} tone="info">
                        This plan follows the <strong>Enabling Good Lives</strong> approach — keep the person's voice and choices at the centre.
                    </InfoCard>
                </WizardStepPane>
            ) : null}

            {/* ----------------------------------------------------- about me */}
            {stepKey === 'about_me' ? (
                <WizardStepPane key="about_me">
                    <StepHead
                        icon={Heart}
                        title="About me — what matters most"
                        blurb="Capture the person's voice: dreams, preferences, and what a good day looks like."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        {ABOUT_FIELDS.map((f) => (
                            <Field key={f.key} label={f.label} span={f.key === 'dreams' || f.key === 'ideal_day' || f.key === 'how_to_support'}>
                                <Textarea
                                    value={aboutMe[f.key]}
                                    rows={3}
                                    onChange={(e) => setAboutMe((prev) => ({ ...prev, [f.key]: e.target.value }))}
                                    placeholder={f.placeholder}
                                />
                            </Field>
                        ))}
                    </div>
                </WizardStepPane>
            ) : null}

            {/* ----------------------------------------------------- needs & risks */}
            {stepKey === 'needs' ? (
                <WizardStepPane key="needs">
                    <StepHead
                        icon={LayoutGrid}
                        title="Support needs & risks"
                        blurb="Select the areas where support is needed, then note risks and strategies."
                    />
                    <p className="mb-2 text-sm font-medium">
                        Support areas
                        {Object.values(supportNeeds).filter(Boolean).length > 0
                            ? ` · ${Object.values(supportNeeds).filter(Boolean).length} selected`
                            : ''}
                    </p>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {SUPPORT_NEED_OPTIONS.map((need) => {
                            const active = !!supportNeeds[need.key];
                            const Icon = need.icon;
                            return (
                                /* eslint-disable-next-line no-restricted-syntax -- multi-select support-need tile */
                                <button
                                    key={need.key}
                                    type="button"
                                    aria-pressed={active}
                                    onClick={() => toggleSupportNeed(need.key)}
                                    className={cn(
                                        'flex items-center gap-2.5 rounded-lg border p-2.5 text-left text-sm font-medium transition-colors',
                                        active
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-border bg-card text-foreground hover:border-primary/50',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'grid h-7 w-7 shrink-0 place-items-center rounded-full',
                                            active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {active ? <Check className="h-3.5 w-3.5" /> : <Icon className="h-3.5 w-3.5" />}
                                    </span>
                                    {need.label}
                                </button>
                            );
                        })}
                    </div>
                    <div className="mt-5 grid gap-3.5">
                        <Field label="Risk factors" span>
                            <Textarea
                                value={riskFactors}
                                rows={3}
                                onChange={(e) => setRiskFactors(e.target.value)}
                                placeholder="Known risks, triggers, and safety concerns support workers should be aware of…"
                            />
                        </Field>
                        <Field label="Support strategies" span>
                            <Textarea
                                value={supportStrategies}
                                rows={3}
                                onChange={(e) => setSupportStrategies(e.target.value)}
                                placeholder="The approaches, methods and frameworks used to deliver support…"
                            />
                        </Field>
                        <Field label="Communication preferences" span>
                            <Textarea
                                value={communicationPreferences}
                                rows={3}
                                onChange={(e) => setCommunicationPreferences(e.target.value)}
                                placeholder="How to communicate effectively — methods, assistive tech, language…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* ----------------------------------------------------- domains */}
            {stepKey === 'domains' ? (
                <WizardStepPane key="domains">
                    <StepHead
                        icon={Target}
                        title="Support domains & strategies"
                        blurb="Build the domain cards that appear on the plan — each with strategies and an owner."
                    />
                    <CarePlanDomainsBuilder domains={domains} staff={staffList} errors={errors} onChange={setDomains} />
                </WizardStepPane>
            ) : null}

            {/* ----------------------------------------------------- EGL & funding */}
            {stepKey === 'egl_funding' ? (
                <WizardStepPane key="egl_funding">
                    <StepHead
                        icon={Compass}
                        title="Enabling Good Lives & funding"
                        blurb="A vision statement and the principles that guide this plan, plus NASC / funding context."
                    />
                    <div className="grid gap-3.5">
                        <Field label="Vision statement" span hint="a good life, in the person's words">
                            <Textarea
                                value={eglVision}
                                rows={3}
                                onChange={(e) => setEglVision(e.target.value)}
                                placeholder="What does a good life look like for this person?"
                            />
                        </Field>
                        <Field label="Guiding principles" span>
                            <ChipMulti values={eglPrinciples} onChange={setEglPrinciples} options={EGL_PRINCIPLES} />
                        </Field>
                    </div>
                    <div className="mt-5 mb-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
                        Funding & NASC
                    </div>
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="NASC organisation">
                            <Input
                                value={funding.nasc_organisation}
                                onChange={(e) => setFunding((p) => ({ ...p, nasc_organisation: e.target.value }))}
                                placeholder="e.g. NASC Wellington"
                            />
                        </Field>
                        <Field label="Needs assessment ref">
                            <Input
                                value={funding.needs_assessment_ref}
                                onChange={(e) => setFunding((p) => ({ ...p, needs_assessment_ref: e.target.value }))}
                                placeholder="Reference / package no."
                            />
                        </Field>
                        <Field label="Needs assessment date">
                            <Input
                                type="date"
                                value={funding.needs_assessment_date}
                                onChange={(e) => setFunding((p) => ({ ...p, needs_assessment_date: e.target.value }))}
                            />
                        </Field>
                        <Field label="Allocated hours / week">
                            <Input
                                type="number"
                                min={0}
                                step="0.5"
                                value={funding.allocated_hours}
                                onChange={(e) => setFunding((p) => ({ ...p, allocated_hours: e.target.value }))}
                                placeholder="e.g. 20"
                            />
                        </Field>
                        <Field label="Linked service agreement" span>
                            <SelectInput
                                value={funding.service_agreement_id || '__none'}
                                onChange={(v) => setFunding((p) => ({ ...p, service_agreement_id: v === '__none' ? '' : v }))}
                                placeholder="Link a funding agreement…"
                                options={agreementOptions}
                            />
                        </Field>
                        <Field label="Funding notes" span>
                            <Textarea
                                value={funding.funding_notes}
                                rows={2}
                                onChange={(e) => setFunding((p) => ({ ...p, funding_notes: e.target.value }))}
                                placeholder="Anything else about funding or the needs assessment…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* ----------------------------------------------------- review (create only) */}
            {stepKey === '__review' ? (
                <WizardStepPane key="__review">
                    <StepHead icon={CheckCircle2} title="Review & save" blurb="Check the plan — jump back to any step to edit." />
                    <div className="mb-5 flex items-center gap-4 rounded-xl border border-border bg-muted/30 p-4">
                        <Ring pct={completeness} size={64} />
                        <div className="min-w-0">
                            <div className="text-sm font-semibold">{str(title) || 'Untitled plan'}</div>
                            <p className="text-xs text-muted-foreground">
                                {PLAN_TYPE_OPTIONS.find((o) => o.value === planType)?.label ?? planType} · {status}
                            </p>
                        </div>
                    </div>
                    <div className="space-y-3">
                        <ReviewCard icon={ClipboardList} title="Plan basics" onEdit={() => setStepIndex(0)}>
                            <ReviewRow label="Title" value={str(title) || undefined} />
                            <ReviewRow label="Type" value={PLAN_TYPE_OPTIONS.find((o) => o.value === planType)?.label} />
                            <ReviewRow label="Status" value={status} />
                            <ReviewRow label="Dates" value={[opt(startsAt), opt(endsAt)].filter(Boolean).join(' → ') || undefined} />
                            <ReviewRow label="Next review" value={opt(nextReviewAt)} />
                        </ReviewCard>
                        <ReviewCard icon={Heart} title="About me" onEdit={() => setStepIndex(1)}>
                            <ReviewRow
                                label="Captured"
                                value={ABOUT_FIELDS.filter((f) => str(aboutMe[f.key])).map((f) => f.label).join(' · ') || 'Nothing yet'}
                            />
                        </ReviewCard>
                        <ReviewCard icon={LayoutGrid} title="Needs & risks" onEdit={() => setStepIndex(2)}>
                            <ReviewRow
                                label="Support areas"
                                value={
                                    SUPPORT_NEED_OPTIONS.filter((o) => supportNeeds[o.key]).map((o) => o.label).join(', ') || undefined
                                }
                            />
                            <ReviewRow label="Risks noted" value={str(riskFactors) ? 'Yes' : undefined} />
                        </ReviewCard>
                        <ReviewCard icon={Target} title="Domains" onEdit={() => setStepIndex(3)}>
                            <ReviewRow
                                label="Domains"
                                value={domains.filter((d) => str(d.label)).map((d) => d.label).join(' · ') || 'None yet'}
                            />
                        </ReviewCard>
                        <ReviewCard icon={Compass} title="EGL & funding" onEdit={() => setStepIndex(4)}>
                            <ReviewRow label="Vision" value={opt(eglVision)} />
                            <ReviewRow label="Principles" value={eglPrinciples.join(', ') || undefined} />
                            <ReviewRow label="NASC" value={opt(funding.nasc_organisation)} />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

function ClientChip({ label }: { label: string }) {
    return (
        <div className="mb-4 flex items-center gap-3 rounded-xl border border-primary/40 bg-accent px-3 py-2.5">
            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-card text-primary">
                <Sparkles className="h-[15px] w-[15px]" />
            </span>
            <div className="min-w-0">
                <div className="truncate text-sm font-medium">{label}</div>
                <div className="text-[11px] text-muted-foreground">Locked to the client you opened.</div>
            </div>
        </div>
    );
}
