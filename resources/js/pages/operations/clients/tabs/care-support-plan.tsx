/* Care & Support Plan tab — the in-profile workspace that replaces the old
 * summary-that-links-out. The PLAN owns structure (domains, person-centred
 * "About me", EGL, funding, reviews, sign-off); GOALS live in the Goals Path tab,
 * so this surface shows only a compact read-only goals roll-up that cross-links.
 * Create/edit run through CarePlanWizardDialog (opened via the on*Plan props);
 * reviews, sign-offs and the PDF export are handled here against the care-plan
 * endpoints. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { CarePlanDomains } from '@/pages/operations/clients/tabs/care-plan-domains';
import { router } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    Clock,
    Compass,
    Download,
    Flag,
    HandHeart,
    Heart,
    History,
    MessageSquare,
    Pencil,
    Plus,
    ShieldAlert,
    Target,
    Trash2,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/* ------------------------------------------------------------------ types */

type SignOff = {
    id: number;
    party_role: string;
    party_name: string;
    relationship?: string | null;
    agreed_on?: string | null;
    method?: string | null;
    acknowledgement?: string | null;
    recorder?: { id: number; name: string } | null;
};

type PlanGoal = {
    id: number;
    title: string;
    status?: string | null;
    progress_percentage?: number | null;
    category?: string | null;
};

type CurrentPlan = {
    id: number;
    title?: string | null;
    status?: string | null;
    plan_type?: string | null;
    version?: number | null;
    starts_at?: string | null;
    ends_at?: string | null;
    next_review_at?: string | null;
    reviewed_at?: string | null;
    content?: unknown;
    creator?: { id: number; name: string } | null;
    reviewer?: { id: number; name: string } | null;
    goals?: PlanGoal[];
    sign_offs?: SignOff[];
};

type PlanVersion = {
    id: number;
    title?: string | null;
    status?: string | null;
    version?: number | null;
    reviewed_at?: string | null;
    reviewer?: { id: number; name: string } | null;
    next_review_at?: string | null;
    starts_at?: string | null;
    created_at?: string | null;
};

type CarePlansSummary = {
    working_plan?: CurrentPlan | null;
    active_plan?: CurrentPlan | null;
    review_plan?: (CurrentPlan & { goals_count?: number }) | null;
    versions?: PlanVersion[];
    versions_total?: number;
    versions_loaded?: number;
    versions_has_more?: boolean;
    total_plans?: number;
    review_due?: boolean;
    recent_notes?: Array<{
        id: number;
        content?: string | null;
        created_at?: string | null;
        author?: { name?: string | null } | null;
        goal?: { title?: string | null } | null;
        is_flagged?: boolean;
    }>;
    recent_notes_total?: number;
    recent_notes_loaded?: number;
    recent_notes_has_more?: boolean;
};

type Props = {
    client: { id: number; first_name?: string | null };
    summary: CarePlansSummary;
    agreements?: Array<{ id: number; title?: string | null }>;
    canEdit: boolean;
    canCreate: boolean;
    onCreatePlan: () => void;
    onEditPlan: (plan: CurrentPlan) => void;
    onGoToGoals: () => void;
};

/* ------------------------------------------------------------------ constants */

const PARTY_ROLES: { value: string; label: string }[] = [
    { value: 'client', label: 'Client' },
    { value: 'whanau', label: 'Whānau' },
    { value: 'eor_guardian', label: 'EOR / guardian' },
    { value: 'key_worker', label: 'Key worker' },
    { value: 'nasc', label: 'NASC' },
    { value: 'other', label: 'Other' },
];
const ROLE_LABEL: Record<string, string> = Object.fromEntries(
    PARTY_ROLES.map((r) => [r.value, r.label]),
);
const METHODS: { value: string; label: string }[] = [
    { value: 'in_person', label: 'In person' },
    { value: 'verbal', label: 'Verbal' },
    { value: 'email', label: 'Email' },
    { value: 'hui', label: 'Hui' },
    { value: 'portal', label: 'Portal' },
];

const STATUS_BADGE: Record<string, string> = {
    active: 'bg-status-success-bg text-status-success',
    draft: 'bg-muted text-muted-foreground',
    review: 'bg-status-warning-bg text-status-warning',
    archived: 'bg-muted text-muted-foreground',
};

const SUPPORT_NEED_LABELS: Record<string, string> = {
    daily_living: 'Daily living',
    personal_care: 'Personal care',
    community_access: 'Community access',
    health_management: 'Health management',
    communication: 'Communication',
    behaviour_support: 'Behaviour support',
    employment: 'Employment',
    education_training: 'Education / training',
    social_participation: 'Social participation',
    cultural_needs: 'Cultural needs',
    spiritual_needs: 'Spiritual needs',
    financial_management: 'Financial management',
};

/* ------------------------------------------------------------------ helpers */

function parseContent(raw: unknown): Record<string, any> {
    if (!raw) return {};
    if (typeof raw === 'string') {
        try {
            return JSON.parse(raw);
        } catch {
            return {};
        }
    }
    return raw as Record<string, any>;
}

function fmtDate(d?: string | null): string {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function titleCase(v?: string | null): string {
    return String(v ?? '').replace(/[_-]+/g, ' ');
}

/* ------------------------------------------------------------------ component */

export function CareSupportPlanTab({
    client,
    summary,
    agreements = [],
    canEdit,
    canCreate,
    onCreatePlan,
    onEditPlan,
    onGoToGoals,
}: Props) {
    const plan =
        summary.working_plan ??
        summary.review_plan ??
        summary.active_plan ??
        null;
    const reviewPlan =
        summary.review_plan ?? (plan?.status === 'review' ? plan : null);
    const versions = summary.versions ?? [];
    const reviewDue = summary.review_due ?? false;

    const content = parseContent(plan?.content);
    const about = content.about_me ?? {};
    const egl = content.egl ?? {};
    const funding = content.funding ?? {};
    const supportNeeds: Record<string, boolean> = content.support_needs ?? {};
    const activeNeeds = Object.entries(supportNeeds).filter(([, v]) => v);
    const eglPrinciples: string[] = Array.isArray(egl.principles)
        ? egl.principles
        : [];

    const goals = plan?.goals ?? [];
    const goalsCompleted = goals.filter((g) => g.status === 'completed').length;
    const goalsInProgress = goals.filter(
        (g) => g.status === 'in_progress',
    ).length;
    const avgProgress = goals.length
        ? Math.round(
              goals.reduce((s, g) => s + (g.progress_percentage ?? 0), 0) /
                  goals.length,
          )
        : 0;
    const reviewDays = plan?.next_review_at
        ? Math.ceil(
              (new Date(plan.next_review_at).getTime() - Date.now()) / 86400000,
          )
        : null;

    const signOffs = plan?.sign_offs ?? [];
    const linkedAgreement = funding.service_agreement_id
        ? agreements.find((a) => a.id === Number(funding.service_agreement_id))
        : null;
    const hasAbout = Object.values(about).some((v) => v && String(v).trim());
    const hasFunding =
        funding.nasc_organisation ||
        funding.needs_assessment_ref ||
        funding.allocated_hours ||
        funding.funding_notes ||
        linkedAgreement;

    /* ---- review lifecycle ---- */
    const [completing, setCompleting] = useState(false);
    const [reviewNotes, setReviewNotes] = useState('');

    const startReview = () => {
        if (!plan) return;
        router.post(
            `/operations/care-plans/${plan.id}/start-review`,
            {},
            { preserveScroll: true },
        );
    };
    const completeReview = () => {
        if (!reviewPlan) return;
        router.post(
            `/operations/care-plans/${reviewPlan.id}/complete-review`,
            { review_notes: reviewNotes },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCompleting(false);
                    setReviewNotes('');
                },
            },
        );
    };

    const exportPdf = () => {
        if (!plan) return;
        window.location.href = `/operations/care-plans/${plan.id}/pdf`;
    };

    /* ---- sign-off ---- */
    const emptySignOff = {
        party_role: 'client',
        party_name: '',
        relationship: '',
        agreed_on: '',
        method: '',
        acknowledgement: '',
    };
    const [signForm, setSignForm] = useState(emptySignOff);
    const [signOpen, setSignOpen] = useState(false);
    const [signBusy, setSignBusy] = useState(false);
    const [signOffToRemove, setSignOffToRemove] = useState<SignOff | null>(
        null,
    );

    const addSignOff = () => {
        if (!plan) return;
        if (!signForm.party_name.trim() || !signForm.agreed_on) {
            toast.error('A name and date are required.');
            return;
        }
        setSignBusy(true);
        router.post(`/operations/care-plans/${plan.id}/sign-offs`, signForm, {
            preserveScroll: true,
            onSuccess: () => {
                setSignForm(emptySignOff);
                setSignOpen(false);
            },
            onError: (errs) => {
                const first = Object.values(errs ?? {})[0];
                if (first) toast.error(String(first));
            },
            onFinish: () => setSignBusy(false),
        });
    };
    const removeSignOff = (id: number) => {
        if (!plan) return;
        router.delete(`/operations/care-plans/${plan.id}/sign-offs/${id}`, {
            preserveScroll: true,
        });
    };

    /* ---------------------------------------------------------- empty state */

    if (!plan) {
        return (
            <Card className="border-dashed">
                <CardContent className="flex flex-col items-center justify-center py-16">
                    <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-primary/10">
                        <Heart className="h-8 w-8 text-primary" />
                    </div>
                    <p className="font-medium">No care plan yet</p>
                    <p className="mt-1 max-w-sm text-center text-sm text-muted-foreground">
                        Create a care &amp; support plan to capture{' '}
                        {client.first_name ?? 'this person'}&apos;s goals,
                        strategies and the things that matter most.
                    </p>
                    {canCreate ? (
                        <Button
                            className="mt-4"
                            onClick={onCreatePlan}
                            data-test="careplan-create"
                        >
                            <Plus className="mr-1.5 h-4 w-4" /> Create care plan
                        </Button>
                    ) : null}
                </CardContent>
            </Card>
        );
    }

    const statusKey = plan.status ?? 'draft';

    return (
        <div className="space-y-4">
            {/* ---- header ---- */}
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-accent text-primary">
                        <Target className="h-5 w-5" />
                    </span>
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-lg leading-tight font-semibold">
                                {plan.title ?? 'Care & support plan'}
                            </h2>
                            <Badge
                                className={`border-0 capitalize ${STATUS_BADGE[statusKey] ?? 'bg-muted'}`}
                            >
                                {titleCase(statusKey)}
                            </Badge>
                            <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                v{plan.version ?? 1}
                            </span>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {titleCase(plan.plan_type)}
                            {plan.creator
                                ? ` · owned by ${plan.creator.name}`
                                : ''}
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={exportPdf}
                        data-test="careplan-export-pdf"
                    >
                        <Download className="mr-1.5 h-4 w-4" /> Export PDF
                    </Button>
                    {canEdit ? (
                        <>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => onEditPlan(reviewPlan ?? plan)}
                                data-test="careplan-edit"
                            >
                                <Pencil className="mr-1.5 h-4 w-4" /> Edit plan
                            </Button>
                            {plan.status === 'active' && !reviewPlan ? (
                                <Button
                                    size="sm"
                                    onClick={startReview}
                                    data-test="careplan-start-review"
                                >
                                    <CalendarClock className="mr-1.5 h-4 w-4" />{' '}
                                    Start review
                                </Button>
                            ) : null}
                        </>
                    ) : null}
                </div>
            </div>

            {/* ---- review in progress ---- */}
            {reviewPlan ? (
                <Card className="border-status-warning/40 bg-status-warning-bg/50">
                    <CardContent className="flex flex-wrap items-center gap-3 p-4">
                        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                            <History className="h-5 w-5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <p className="text-sm font-semibold text-status-warning">
                                Review in progress — version{' '}
                                {reviewPlan.version ?? ''}
                            </p>
                            <p className="text-xs text-status-warning/90">
                                Update the plan, then complete the review to
                                activate it and archive the current version.
                            </p>
                            {signOffs.length === 0 ? (
                                <p className="mt-1 text-xs font-medium text-status-warning">
                                    Record at least one new sign-off on this
                                    review before completing it.
                                </p>
                            ) : null}
                        </div>
                        {canEdit ? (
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onEditPlan(reviewPlan)}
                                >
                                    <Pencil className="mr-1.5 h-4 w-4" /> Edit
                                    review
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() => setCompleting((v) => !v)}
                                    disabled={signOffs.length === 0}
                                    data-test="careplan-complete-review"
                                >
                                    <CheckCircle2 className="mr-1.5 h-4 w-4" />{' '}
                                    Complete review
                                </Button>
                            </div>
                        ) : null}
                        {completing ? (
                            <div className="w-full space-y-2 border-t border-status-warning/30 pt-3">
                                <Label className="text-xs">
                                    Review notes (optional)
                                </Label>
                                <Textarea
                                    value={reviewNotes}
                                    rows={2}
                                    onChange={(e) =>
                                        setReviewNotes(e.target.value)
                                    }
                                    placeholder="What changed, who attended, agreed actions…"
                                />
                                <div className="flex justify-end gap-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setCompleting(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button size="sm" onClick={completeReview}>
                                        Complete &amp; activate
                                    </Button>
                                </div>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            ) : null}

            {/* ---- review-due alert ---- */}
            {reviewDue && plan.status === 'active' && !reviewPlan ? (
                <div className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-status-warning/30 bg-status-warning-bg p-4">
                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-status-warning-bg text-status-warning">
                        <ShieldAlert className="h-5 w-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-semibold text-status-warning">
                            Care plan review due
                        </p>
                        <p className="text-xs text-status-warning/90">
                            This plan is due for review — update goals and
                            strategies.
                        </p>
                    </div>
                    {canEdit ? (
                        <Button
                            size="sm"
                            className="bg-status-warning text-primary-foreground hover:bg-status-warning/90"
                            onClick={startReview}
                        >
                            Start review
                        </Button>
                    ) : null}
                </div>
            ) : null}

            {/* ---- quick stats ---- */}
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <StatTile
                    value={`${goals.length}`}
                    label="Goals"
                    tone="primary"
                />
                <StatTile
                    value={`${goalsCompleted}`}
                    label="Completed"
                    tone="success"
                />
                <StatTile
                    value={`${goalsInProgress}`}
                    label="In progress"
                    tone="info"
                />
                <StatTile
                    value={
                        reviewDays !== null
                            ? reviewDays < 0
                                ? `${Math.abs(reviewDays)}d`
                                : `${reviewDays}d`
                            : '—'
                    }
                    label={
                        reviewDays !== null && reviewDays < 0
                            ? 'Overdue'
                            : 'Until review'
                    }
                    tone={
                        reviewDays !== null && reviewDays < 0
                            ? 'critical'
                            : 'primary'
                    }
                />
            </div>

            {/* ---- support domains ---- */}
            {(content.domains ?? []).length > 0 ? (
                <CarePlanDomains domains={content.domains ?? []} />
            ) : null}

            {/* ---- about me ---- */}
            {hasAbout ? (
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Heart className="h-4 w-4 text-status-critical" />{' '}
                            About {client.first_name ?? 'me'}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2">
                        {[
                            ['dreams', 'Dreams & aspirations'],
                            ['important_to_me', 'Important TO me'],
                            ['important_for_me', 'Important FOR me'],
                            ['ideal_day', 'My ideal day'],
                            ['likes', 'Things I like'],
                            ['dislikes', "Things I don't like"],
                            ['how_to_support', 'How to support me'],
                        ]
                            .filter(
                                ([k]) => about[k] && String(about[k]).trim(),
                            )
                            .map(([k, label]) => (
                                <AboutCell
                                    key={k}
                                    label={label}
                                    value={about[k]}
                                    wide={
                                        k === 'dreams' ||
                                        k === 'ideal_day' ||
                                        k === 'how_to_support'
                                    }
                                />
                            ))}
                    </CardContent>
                </Card>
            ) : null}

            {/* ---- support needs ---- */}
            {activeNeeds.length > 0 ? (
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">
                            Support needs
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="flex flex-wrap gap-2">
                            {activeNeeds.map(([key]) => (
                                <span
                                    key={key}
                                    className="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary"
                                >
                                    {SUPPORT_NEED_LABELS[key] ?? titleCase(key)}
                                </span>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            ) : null}

            {/* ---- content sections ---- */}
            {content.risk_factors ||
            content.support_strategies ||
            content.communication_preferences ? (
                <div className="grid gap-4 lg:grid-cols-3">
                    <ContentCard
                        icon={ShieldAlert}
                        title="Risk factors"
                        tone="text-status-warning"
                        value={content.risk_factors}
                    />
                    <ContentCard
                        icon={Flag}
                        title="Support strategies"
                        tone="text-status-success"
                        value={content.support_strategies}
                    />
                    <ContentCard
                        icon={MessageSquare}
                        title="Communication"
                        tone="text-primary"
                        value={content.communication_preferences}
                    />
                </div>
            ) : null}

            <div className="grid gap-4 lg:grid-cols-2">
                {/* ---- EGL ---- */}
                {egl.vision || eglPrinciples.length > 0 ? (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Compass className="h-4 w-4 text-primary" />{' '}
                                Enabling Good Lives
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {egl.vision ? (
                                <p className="text-sm whitespace-pre-wrap">
                                    {egl.vision}
                                </p>
                            ) : null}
                            {eglPrinciples.length > 0 ? (
                                <div className="flex flex-wrap gap-1.5">
                                    {eglPrinciples.map((p) => (
                                        <span
                                            key={p}
                                            className="rounded-full border border-primary/30 bg-primary/5 px-2.5 py-0.5 text-[11px] font-medium text-primary"
                                        >
                                            {p}
                                        </span>
                                    ))}
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}

                {/* ---- funding / NASC ---- */}
                {hasFunding ? (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Wallet className="h-4 w-4 text-primary" />{' '}
                                Funding &amp; NASC
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-1.5 text-sm">
                            {funding.nasc_organisation ? (
                                <Row
                                    label="NASC"
                                    value={funding.nasc_organisation}
                                />
                            ) : null}
                            {funding.needs_assessment_ref ? (
                                <Row
                                    label="Needs assessment"
                                    value={`${funding.needs_assessment_ref}${funding.needs_assessment_date ? ` · ${fmtDate(funding.needs_assessment_date)}` : ''}`}
                                />
                            ) : null}
                            {funding.allocated_hours ? (
                                <Row
                                    label="Allocated hours/wk"
                                    value={String(funding.allocated_hours)}
                                />
                            ) : null}
                            {linkedAgreement ? (
                                <Row
                                    label="Agreement"
                                    value={
                                        linkedAgreement.title ??
                                        `Agreement #${linkedAgreement.id}`
                                    }
                                />
                            ) : null}
                            {funding.funding_notes ? (
                                <p className="pt-1 text-xs whitespace-pre-wrap text-muted-foreground">
                                    {funding.funding_notes}
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>
                ) : null}
            </div>

            {/* ---- compact goals summary (NO crud — Goals Path owns goals) ---- */}
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="flex items-center justify-between text-base">
                        <span className="flex items-center gap-2">
                            <Target className="h-4 w-4 text-primary" /> Goals
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-primary"
                            onClick={onGoToGoals}
                            data-test="careplan-go-to-goals"
                        >
                            Open Goals Path{' '}
                            <ArrowRight className="ml-1 h-3.5 w-3.5" />
                        </Button>
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {goals.length === 0 ? (
                        <p className="py-4 text-center text-sm text-muted-foreground">
                            No goals yet. Goals are managed on the Goals Path
                            tab — use &ldquo;Open Goals Path&rdquo; above.
                        </p>
                    ) : (
                        <div className="space-y-3">
                            {[...goals]
                                .sort(
                                    (a, b) =>
                                        (b.progress_percentage ?? 0) -
                                        (a.progress_percentage ?? 0),
                                )
                                .slice(0, 4)
                                .map((g) => (
                                    <div key={g.id}>
                                        <div className="mb-1 flex items-center justify-between gap-2">
                                            <span className="max-w-[75%] truncate text-xs font-medium">
                                                {g.title}
                                            </span>
                                            <span
                                                className={`text-xs font-bold tabular-nums ${g.status === 'completed' ? 'text-status-success' : 'text-primary'}`}
                                            >
                                                {g.progress_percentage ?? 0}%
                                            </span>
                                        </div>
                                        <div className="h-2.5 w-full overflow-hidden rounded-full bg-muted">
                                            <div
                                                className={`h-full rounded-full ${g.status === 'completed' ? 'bg-status-success' : 'bg-primary'}`}
                                                style={{
                                                    width: `${g.progress_percentage ?? 0}%`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                ))}
                            <div className="flex items-center justify-between rounded-lg bg-muted/50 px-3 py-2 text-xs">
                                <span>
                                    {goalsCompleted} completed ·{' '}
                                    {goalsInProgress} in progress ·{' '}
                                    {goals.length -
                                        goalsCompleted -
                                        goalsInProgress}{' '}
                                    not started
                                </span>
                                <span className="font-semibold text-primary">
                                    Avg {avgProgress}%
                                </span>
                            </div>
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* ---- sign-off ---- */}
            <Card>
                <CardHeader className="pb-2">
                    <CardTitle className="flex items-center justify-between text-base">
                        <span className="flex items-center gap-2">
                            <HandHeart className="h-4 w-4 text-primary" />{' '}
                            Agreement &amp; sign-off
                        </span>
                        {canEdit ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setSignOpen((v) => !v)}
                                data-test="careplan-add-signoff"
                            >
                                <Plus className="mr-1.5 h-3.5 w-3.5" /> Add
                                sign-off
                            </Button>
                        ) : null}
                    </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                    {signOpen ? (
                        <div className="space-y-3 rounded-xl border border-dashed border-primary/40 bg-primary/5 p-3">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="text-xs">
                                        Who agreed *
                                    </Label>
                                    <Select
                                        value={signForm.party_role}
                                        onValueChange={(v) =>
                                            setSignForm((p) => ({
                                                ...p,
                                                party_role: v,
                                            }))
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {PARTY_ROLES.map((r) => (
                                                <SelectItem
                                                    key={r.value}
                                                    value={r.value}
                                                >
                                                    {r.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs">Name *</Label>
                                    <Input
                                        value={signForm.party_name}
                                        onChange={(e) =>
                                            setSignForm((p) => ({
                                                ...p,
                                                party_name: e.target.value,
                                            }))
                                        }
                                        placeholder="Full name"
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs">
                                        Relationship
                                    </Label>
                                    <Input
                                        value={signForm.relationship}
                                        onChange={(e) =>
                                            setSignForm((p) => ({
                                                ...p,
                                                relationship: e.target.value,
                                            }))
                                        }
                                        placeholder="e.g. Mother"
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs">
                                        Agreed on *
                                    </Label>
                                    <Input
                                        type="date"
                                        value={signForm.agreed_on}
                                        onChange={(e) =>
                                            setSignForm((p) => ({
                                                ...p,
                                                agreed_on: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="text-xs">Method</Label>
                                    <Select
                                        value={signForm.method || undefined}
                                        onValueChange={(v) =>
                                            setSignForm((p) => ({
                                                ...p,
                                                method: v,
                                            }))
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="How" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {METHODS.map((m) => (
                                                <SelectItem
                                                    key={m.value}
                                                    value={m.value}
                                                >
                                                    {m.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5 sm:col-span-2">
                                    <Label className="text-xs">
                                        Acknowledgement
                                    </Label>
                                    <Textarea
                                        value={signForm.acknowledgement}
                                        rows={2}
                                        onChange={(e) =>
                                            setSignForm((p) => ({
                                                ...p,
                                                acknowledgement: e.target.value,
                                            }))
                                        }
                                        placeholder="Optional note about the agreement…"
                                    />
                                </div>
                            </div>
                            <div className="flex justify-end gap-2">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setSignOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={addSignOff}
                                    disabled={signBusy}
                                >
                                    Record sign-off
                                </Button>
                            </div>
                        </div>
                    ) : null}

                    {signOffs.length === 0 ? (
                        <p className="py-2 text-sm text-muted-foreground">
                            No sign-offs recorded yet. Capture agreement from{' '}
                            {client.first_name ?? 'the client'}, whānau and the
                            key worker.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {signOffs.map((s) => (
                                <div
                                    key={s.id}
                                    className="flex items-start gap-3 rounded-lg border p-3"
                                >
                                    <span className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-status-success-bg text-status-success">
                                        <CheckCircle2 className="h-4 w-4" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold">
                                                {s.party_name}
                                            </span>
                                            <Badge
                                                variant="outline"
                                                className="text-[10px] capitalize"
                                            >
                                                {ROLE_LABEL[s.party_role] ??
                                                    titleCase(s.party_role)}
                                            </Badge>
                                            {s.relationship ? (
                                                <span className="text-[11px] text-muted-foreground">
                                                    {s.relationship}
                                                </span>
                                            ) : null}
                                        </div>
                                        <p className="mt-0.5 text-[11px] text-muted-foreground">
                                            Agreed {fmtDate(s.agreed_on)}
                                            {s.method
                                                ? ` · ${titleCase(s.method)}`
                                                : ''}
                                            {s.recorder
                                                ? ` · recorded by ${s.recorder.name}`
                                                : ''}
                                        </p>
                                        {s.acknowledgement ? (
                                            <p className="mt-1 text-sm">
                                                {s.acknowledgement}
                                            </p>
                                        ) : null}
                                    </div>
                                    {canEdit ? (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="h-7 w-7 text-muted-foreground hover:text-status-critical"
                                            onClick={() =>
                                                setSignOffToRemove(s)
                                            }
                                            aria-label={`Remove sign-off from ${s.party_name}`}
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            {/* ---- versions ---- */}
            {versions.length > 0 ? (
                <Card>
                    <CardHeader className="pb-2">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <History className="h-4 w-4 text-muted-foreground" />{' '}
                            Version history
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {summary.versions_has_more ? (
                            <p className="mb-3 rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                                Showing the latest{' '}
                                {summary.versions_loaded ?? versions.length} of{' '}
                                {summary.versions_total ?? versions.length} plan
                                versions.
                            </p>
                        ) : null}
                        <div className="space-y-2">
                            {versions.map((v) => (
                                <div
                                    key={v.id}
                                    className="flex items-center gap-3 rounded-lg border p-3"
                                >
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-muted text-xs font-bold text-muted-foreground">
                                        v{v.version ?? 1}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium">
                                                {v.title ?? 'Plan'}
                                            </span>
                                            <Badge
                                                className={`border-0 text-[10px] capitalize ${STATUS_BADGE[v.status ?? ''] ?? 'bg-muted'}`}
                                            >
                                                {titleCase(v.status)}
                                            </Badge>
                                        </div>
                                        <p className="mt-0.5 flex flex-wrap items-center gap-x-3 text-[11px] text-muted-foreground">
                                            {v.reviewer ? (
                                                <span>
                                                    Reviewed by{' '}
                                                    {v.reviewer.name}
                                                </span>
                                            ) : null}
                                            {v.reviewed_at ? (
                                                <span className="flex items-center gap-1">
                                                    <Clock className="h-3 w-3" />
                                                    {fmtDate(v.reviewed_at)}
                                                </span>
                                            ) : null}
                                            <span>
                                                Started {fmtDate(v.starts_at)}
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            ) : null}
            <ConfirmDialog
                open={signOffToRemove !== null}
                onClose={() => setSignOffToRemove(null)}
                onConfirm={() =>
                    signOffToRemove && removeSignOff(signOffToRemove.id)
                }
                title="Remove sign-off?"
                description={`Remove the sign-off from ${signOffToRemove?.party_name ?? 'this person'}? This action cannot be undone.`}
                confirmText="Remove sign-off"
            />
        </div>
    );
}

/* ------------------------------------------------------------------ small bits */

function StatTile({
    value,
    label,
    tone,
}: {
    value: string;
    label: string;
    tone: 'primary' | 'success' | 'info' | 'critical';
}) {
    const tones: Record<string, string> = {
        primary: 'bg-primary/10 text-primary',
        success: 'bg-status-success-bg text-status-success',
        info: 'bg-primary/10 text-status-info',
        critical: 'bg-status-critical-bg text-status-critical',
    };
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact stat tile, not a full Card
        <div className="rounded-xl border bg-card p-3 text-center">
            <div className={`text-2xl font-bold ${tones[tone].split(' ')[1]}`}>
                {value}
            </div>
            <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                {label}
            </div>
        </div>
    );
}

function AboutCell({
    label,
    value,
    wide,
}: {
    label: string;
    value: string;
    wide?: boolean;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact person-centred field inside the About card
        <div
            className={`rounded-lg bg-muted/40 p-3 ${wide ? 'sm:col-span-2' : ''}`}
        >
            <p className="text-[10px] font-bold tracking-wider text-muted-foreground uppercase">
                {label}
            </p>
            <p className="mt-1 text-sm whitespace-pre-wrap">{value}</p>
        </div>
    );
}

function ContentCard({
    icon: Icon,
    title,
    tone,
    value,
}: {
    icon: typeof ShieldAlert;
    title: string;
    tone: string;
    value?: string | null;
}) {
    if (!value) return null;
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="flex items-center gap-2 text-sm">
                    <Icon className={`h-4 w-4 ${tone}`} /> {title}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-sm whitespace-pre-wrap">{value}</p>
            </CardContent>
        </Card>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{value}</span>
        </div>
    );
}
