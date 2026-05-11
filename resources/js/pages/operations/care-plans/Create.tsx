import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
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
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    Briefcase,
    CalendarClock,
    Check,
    ClipboardList,
    Globe,
    GraduationCap,
    Heart,
    Home,
    Info,
    Lightbulb,
    MapPin,
    MessageCircle,
    MessageSquare,
    Shield,
    ShieldAlert,
    Sparkle,
    Sparkles,
    User,
    Users,
    Wallet,
} from 'lucide-react';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const PLAN_TEMPLATES: Record<
    string,
    { title: string; description: string; plan_type: string; content: any }
> = {
    standard_support: {
        plan_type: 'support_plan',
        title: 'Support Plan',
        description:
            '4 support areas, quarterly reviews. Focused on independence and community participation.',
        content: {
            support_needs: {
                daily_living: true,
                personal_care: true,
                community_access: true,
                social_participation: true,
            },
            support_strategies:
                'Goals-based support approach focusing on independence and community participation.',
            review_schedule: { frequency_months: 3 },
        },
    },
    behaviour_support: {
        plan_type: 'behaviour_plan',
        title: 'Behaviour Support Plan',
        description:
            '3 support areas, quarterly reviews. Positive behaviour support framework.',
        content: {
            support_needs: {
                behaviour_support: true,
                communication: true,
                health_management: true,
            },
            support_strategies:
                'Positive behaviour support framework with proactive and reactive strategies.',
            review_schedule: { frequency_months: 3 },
        },
    },
    health_wellbeing: {
        plan_type: 'health_plan',
        title: 'Health & Wellbeing Plan',
        description:
            '3 support areas, 6-monthly reviews. Health monitoring and allied health coordination.',
        content: {
            support_needs: {
                health_management: true,
                personal_care: true,
                daily_living: true,
            },
            support_strategies:
                'Health monitoring and wellbeing support with allied health coordination.',
            review_schedule: { frequency_months: 6 },
        },
    },
    transition: {
        plan_type: 'transition_plan',
        title: 'Transition Plan',
        description:
            '4 support areas, monthly reviews. Milestone-based goals for building independence.',
        content: {
            support_needs: {
                daily_living: true,
                employment: true,
                education_training: true,
                community_access: true,
            },
            support_strategies:
                'Structured transition support with milestone-based goals and gradual independence building.',
            review_schedule: { frequency_months: 1 },
        },
    },
};

const SUPPORT_NEED_OPTIONS: { key: string; label: string; icon: LucideIcon }[] =
    [
        { key: 'daily_living', label: 'Daily Living', icon: Home },
        { key: 'personal_care', label: 'Personal Care', icon: User },
        { key: 'community_access', label: 'Community Access', icon: MapPin },
        {
            key: 'health_management',
            label: 'Health Management',
            icon: Activity,
        },
        { key: 'communication', label: 'Communication', icon: MessageSquare },
        { key: 'behaviour_support', label: 'Behaviour Support', icon: Shield },
        { key: 'employment', label: 'Employment', icon: Briefcase },
        {
            key: 'education_training',
            label: 'Education/Training',
            icon: GraduationCap,
        },
        {
            key: 'social_participation',
            label: 'Social Participation',
            icon: Users,
        },
        { key: 'cultural_needs', label: 'Cultural Needs', icon: Globe },
        { key: 'spiritual_needs', label: 'Spiritual Needs', icon: Sparkle },
        {
            key: 'financial_management',
            label: 'Financial Management',
            icon: Wallet,
        },
    ];

const FREQUENCY_OPTIONS = [
    { value: 1, label: 'Monthly' },
    { value: 3, label: 'Quarterly' },
    { value: 6, label: '6-Monthly' },
    { value: 12, label: 'Annually' },
];

function addMonths(dateStr: string, months: number): string {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    d.setMonth(d.getMonth() + months);
    return d.toISOString().split('T')[0];
}

// ---------------------------------------------------------------------------
// Section Header helper
// ---------------------------------------------------------------------------

function SectionHeader({
    icon: Icon,
    iconBg,
    title,
    description,
}: {
    icon: LucideIcon;
    iconBg: string;
    title: string;
    description: string;
}) {
    return (
        <CardHeader>
            <CardTitle className="flex items-center gap-2.5 text-base">
                <div
                    className={`flex h-8 w-8 items-center justify-center rounded-lg ${iconBg}`}
                >
                    <Icon className="h-4 w-4" />
                </div>
                {title}
            </CardTitle>
            <p className="text-sm text-muted-foreground">{description}</p>
        </CardHeader>
    );
}

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

type Props = {
    clients: { id: number; first_name: string; last_name: string }[];
    staff?: { id: number; name: string }[];
};

export default function CarePlanCreate({ clients = [], staff = [] }: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';

    const urlParams = new URLSearchParams(
        typeof window !== 'undefined' ? window.location.search : '',
    );
    const initialClientId = urlParams.get('client_id') ?? '';
    const fromOnboarding = urlParams.get('from_onboarding') === '1';

    const { data, setData, post, processing, errors } = useForm({
        client_id: initialClientId,
        plan_type: 'support_plan',
        title: '',
        starts_at: '',
        ends_at: '',
        next_review_at: '',
        status: 'draft',
        content: {
            about_me: {
                dreams: '',
                important_to_me: '',
                important_for_me: '',
                ideal_day: '',
                likes: '',
                dislikes: '',
                how_to_support: '',
            },
            support_needs: {} as Record<string, boolean>,
            risk_factors: '',
            support_strategies: '',
            communication_preferences: '',
            review_schedule: { frequency_months: 3 },
        },
        from_onboarding: fromOnboarding ? '1' : '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/operations/care-plans');
    };

    const applyTemplate = (templateKey: string) => {
        if (!PLAN_TEMPLATES[templateKey]) return;
        const template = PLAN_TEMPLATES[templateKey];
        setData((prev: any) => ({
            ...prev,
            plan_type: template.plan_type ?? prev.plan_type,
            title: template.title ?? prev.title,
            content: {
                ...prev.content,
                support_needs:
                    template.content?.support_needs ??
                    prev.content.support_needs,
                support_strategies:
                    template.content?.support_strategies ??
                    prev.content.support_strategies,
                review_schedule:
                    template.content?.review_schedule ??
                    prev.content.review_schedule,
            },
        }));
    };

    const toggleSupportNeed = (key: string) => {
        setData((prev: any) => ({
            ...prev,
            content: {
                ...prev.content,
                support_needs: {
                    ...prev.content.support_needs,
                    [key]: !prev.content.support_needs[key],
                },
            },
        }));
    };

    const setContentField = (field: string, value: any) => {
        setData((prev: any) => ({
            ...prev,
            content: { ...prev.content, [field]: value },
        }));
    };

    const setAboutMeField = (field: string, value: string) => {
        setData((prev: any) => ({
            ...prev,
            content: {
                ...prev.content,
                about_me: { ...prev.content.about_me, [field]: value },
            },
        }));
    };

    const handleFrequencyChange = (months: number) => {
        setData((prev: any) => {
            const nextReview = prev.starts_at
                ? addMonths(prev.starts_at, months)
                : prev.next_review_at;
            return {
                ...prev,
                next_review_at: nextReview,
                content: {
                    ...prev.content,
                    review_schedule: { frequency_months: months },
                },
            };
        });
    };

    const selectedNeedsCount = Object.values(data.content.support_needs).filter(
        Boolean,
    ).length;

    return (
        <AppLayout>
            <Head title="Create Care Plan" />
            <PageHeader
                title="Create Care Plan"
                description={`Create a new care plan for a ${clientSingular.toLowerCase()}.`}
                backHref="/operations/care-plans"
            />
            <PageShell>
                {/* Onboarding Banner */}
                {fromOnboarding && (
                    <div className="mb-4 flex items-start gap-3 rounded-lg border border-primary bg-primary/10 p-4 dark:border-primary/30 dark:bg-primary/30">
                        <Info className="mt-0.5 h-5 w-5 shrink-0 text-primary dark:text-primary" />
                        <div>
                            <p className="text-sm font-medium text-primary dark:text-primary/70">
                                Onboarding in Progress
                            </p>
                            <p className="mt-0.5 text-xs text-primary dark:text-primary/70">
                                This care plan is being created as part of the
                                onboarding process. The onboarding step will be
                                auto-completed.
                            </p>
                        </div>
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* ─── Section 1: Plan Details ─── */}
                    <Card>
                        <SectionHeader
                            icon={ClipboardList}
                            iconBg="bg-primary/10 text-primary"
                            title="Plan Details"
                            description="Basic information about this care plan."
                        />
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="client_id">
                                        {clientSingular} *
                                    </Label>
                                    <Select
                                        value={data.client_id}
                                        onValueChange={(v) =>
                                            setData('client_id', v)
                                        }
                                    >
                                        <SelectTrigger id="client_id">
                                            <SelectValue
                                                placeholder={`Select ${clientSingular.toLowerCase()}`}
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(clients ?? []).map((c) => (
                                                <SelectItem
                                                    key={c.id}
                                                    value={String(c.id)}
                                                >
                                                    {c.first_name} {c.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && (
                                        <p className="text-xs text-destructive">
                                            {errors.client_id}
                                        </p>
                                    )}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="plan_type">
                                        Plan Type *
                                    </Label>
                                    <Select
                                        value={data.plan_type}
                                        onValueChange={(v) =>
                                            setData('plan_type', v)
                                        }
                                    >
                                        <SelectTrigger id="plan_type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="support_plan">
                                                Support Plan
                                            </SelectItem>
                                            <SelectItem value="behaviour_plan">
                                                Behaviour Plan
                                            </SelectItem>
                                            <SelectItem value="health_plan">
                                                Health Plan
                                            </SelectItem>
                                            <SelectItem value="transition_plan">
                                                Transition Plan
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="title">Title *</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) =>
                                        setData('title', e.target.value)
                                    }
                                    placeholder="e.g. John's Support Plan 2026"
                                />
                                {errors.title && (
                                    <p className="text-xs text-destructive">
                                        {errors.title}
                                    </p>
                                )}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="starts_at">
                                        Start Date
                                    </Label>
                                    <Input
                                        id="starts_at"
                                        type="date"
                                        value={data.starts_at}
                                        onChange={(e) =>
                                            setData('starts_at', e.target.value)
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="ends_at">End Date</Label>
                                    <Input
                                        id="ends_at"
                                        type="date"
                                        value={data.ends_at}
                                        onChange={(e) =>
                                            setData('ends_at', e.target.value)
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="next_review_at">
                                        Next Review
                                    </Label>
                                    <Input
                                        id="next_review_at"
                                        type="date"
                                        value={data.next_review_at}
                                        onChange={(e) =>
                                            setData(
                                                'next_review_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="status">Status</Label>
                                <Select
                                    value={data.status}
                                    onValueChange={(v) => setData('status', v)}
                                >
                                    <SelectTrigger
                                        id="status"
                                        className="w-[160px]"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="draft">
                                            Draft
                                        </SelectItem>
                                        <SelectItem value="active">
                                            Active
                                        </SelectItem>
                                        <SelectItem value="review">
                                            Review
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ─── Section 2: About Me — Person-Centred ─── */}
                    <Card className="border-status-critical/30 bg-status-critical-bg">
                        <SectionHeader
                            icon={Heart}
                            iconBg="bg-status-critical-bg text-status-critical"
                            title="About Me — What Matters Most"
                            description="Capture the person's voice — their dreams, preferences, and what a good day looks like."
                        />
                        <CardContent className="space-y-5">
                            <div className="space-y-1.5">
                                <Label className="font-medium">
                                    My Dreams & Aspirations
                                </Label>
                                <Textarea
                                    className="min-h-[100px] border-status-critical/30 bg-background"
                                    value={data.content.about_me?.dreams}
                                    onChange={(e) =>
                                        setAboutMeField(
                                            'dreams',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="What are their big dreams, hopes, and goals for the future?"
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="font-medium">
                                        What{"'"}s Important TO Me
                                    </Label>
                                    <Textarea
                                        className="min-h-[100px] border-status-critical/30 bg-background"
                                        value={
                                            data.content.about_me
                                                ?.important_to_me
                                        }
                                        onChange={(e) =>
                                            setAboutMeField(
                                                'important_to_me',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Relationships, routines, interests, passions — the things that matter most to this person"
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="font-medium">
                                        What{"'"}s Important FOR Me
                                    </Label>
                                    <Textarea
                                        className="min-h-[100px] border-status-critical/30 bg-background"
                                        value={
                                            data.content.about_me
                                                ?.important_for_me
                                        }
                                        onChange={(e) =>
                                            setAboutMeField(
                                                'important_for_me',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Health, safety, and wellbeing needs that must be maintained"
                                    />
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="font-medium">
                                    My Ideal Day
                                </Label>
                                <Textarea
                                    className="min-h-[100px] border-status-critical/30 bg-background"
                                    value={data.content.about_me?.ideal_day}
                                    onChange={(e) =>
                                        setAboutMeField(
                                            'ideal_day',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Describe what a good day looks like for this person — morning routine, activities, meals, social time..."
                                />
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label className="font-medium text-status-success">
                                        Things I Like
                                    </Label>
                                    <Textarea
                                        className="min-h-[80px] border-status-success/30 bg-status-success-bg"
                                        value={data.content.about_me?.likes}
                                        onChange={(e) =>
                                            setAboutMeField(
                                                'likes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Favourite foods, activities, music, places, people..."
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label className="font-medium text-status-critical">
                                        Things I Don{"'"}t Like
                                    </Label>
                                    <Textarea
                                        className="min-h-[80px] border-status-critical/30 bg-status-critical-bg"
                                        value={data.content.about_me?.dislikes}
                                        onChange={(e) =>
                                            setAboutMeField(
                                                'dislikes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Things to avoid, triggers, dislikes..."
                                    />
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label className="font-medium">
                                    How to Support Me Best
                                </Label>
                                <Textarea
                                    className="min-h-[100px] border-status-critical/30 bg-background"
                                    value={
                                        data.content.about_me?.how_to_support
                                    }
                                    onChange={(e) =>
                                        setAboutMeField(
                                            'how_to_support',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Tips for support workers — communication style, motivation, boundaries, things to remember..."
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* ─── Section 3: Template ─── */}
                    <Card>
                        <SectionHeader
                            icon={Sparkles}
                            iconBg="bg-primary/10 text-primary"
                            title="Quick Start Template"
                            description="Choose a template to pre-fill support needs and strategies, or start from scratch."
                        />
                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-2">
                                {Object.entries(PLAN_TEMPLATES).map(
                                    ([key, template]) => (
                                        <Button
                                            key={key}
                                            type="button"
                                            variant="outline"
                                            onClick={() => applyTemplate(key)}
                                            className="group hover:bg-primary/10/50 h-auto rounded-lg border-2 border-border p-4 text-left hover:border-primary hover:shadow-sm"
                                        >
                                            <div className="text-sm font-semibold text-foreground group-hover:text-primary">
                                                {template.title}
                                            </div>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {template.description}
                                            </p>
                                        </Button>
                                    ),
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* ─── Section 4: Support Needs ─── */}
                    <Card>
                        <SectionHeader
                            icon={Heart}
                            iconBg="bg-status-critical-bg text-status-critical"
                            title={`Support Needs${selectedNeedsCount > 0 ? ` (${selectedNeedsCount} selected)` : ''}`}
                            description="Select the areas where support is needed. These help structure goals and service delivery."
                        />
                        <CardContent>
                            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4">
                                {SUPPORT_NEED_OPTIONS.map((option) => {
                                    const active =
                                        !!data.content.support_needs[
                                            option.key
                                        ];
                                    const IconComp = option.icon;
                                    return (
                                        <Button
                                            key={option.key}
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                toggleSupportNeed(option.key)
                                            }
                                            className={`h-auto justify-start gap-2.5 rounded-lg border-2 p-3 text-left text-sm font-medium ${
                                                active
                                                    ? 'border-primary bg-primary/10 text-primary shadow-sm'
                                                    : 'border-border bg-white text-muted-foreground hover:border-border hover:bg-muted'
                                            }`}
                                        >
                                            <div
                                                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full transition-colors ${
                                                    active
                                                        ? 'bg-primary text-white'
                                                        : 'border border-border bg-white text-muted-foreground'
                                                }`}
                                            >
                                                {active ? (
                                                    <Check className="h-3.5 w-3.5" />
                                                ) : (
                                                    <IconComp className="h-3.5 w-3.5" />
                                                )}
                                            </div>
                                            {option.label}
                                        </Button>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>

                    {/* ─── Section 5: Risk Factors ─── */}
                    <Card>
                        <SectionHeader
                            icon={ShieldAlert}
                            iconBg="bg-status-warning-bg text-status-warning"
                            title="Risk Factors"
                            description="Document known risks, triggers, and safety concerns that support workers should be aware of."
                        />
                        <CardContent>
                            <Textarea
                                className="min-h-[120px] bg-muted/50"
                                value={data.content.risk_factors}
                                onChange={(e) =>
                                    setContentField(
                                        'risk_factors',
                                        e.target.value,
                                    )
                                }
                                placeholder="Describe any known risk factors, triggers, or safety concerns..."
                            />
                        </CardContent>
                    </Card>

                    {/* ─── Section 6: Support Strategies ─── */}
                    <Card>
                        <SectionHeader
                            icon={Lightbulb}
                            iconBg="bg-status-success-bg text-status-success"
                            title="Support Strategies"
                            description="Describe the approaches, methods, and frameworks used to deliver support."
                        />
                        <CardContent>
                            <Textarea
                                className="min-h-[120px] bg-muted/50"
                                value={data.content.support_strategies}
                                onChange={(e) =>
                                    setContentField(
                                        'support_strategies',
                                        e.target.value,
                                    )
                                }
                                placeholder="Describe the support strategies and approaches to be used..."
                            />
                        </CardContent>
                    </Card>

                    {/* ─── Section 7: Communication Preferences ─── */}
                    <Card>
                        <SectionHeader
                            icon={MessageCircle}
                            iconBg="bg-status-info-bg text-status-info"
                            title="Communication Preferences"
                            description="How to communicate effectively with this person — methods, assistive technology, language."
                        />
                        <CardContent>
                            <Textarea
                                className="min-h-[120px] bg-muted/50"
                                value={data.content.communication_preferences}
                                onChange={(e) =>
                                    setContentField(
                                        'communication_preferences',
                                        e.target.value,
                                    )
                                }
                                placeholder="Describe communication preferences, methods, and any assistive technology used..."
                            />
                        </CardContent>
                    </Card>

                    {/* ─── Section 8: Review Schedule ─── */}
                    <Card>
                        <SectionHeader
                            icon={CalendarClock}
                            iconBg="bg-status-info-bg text-status-info"
                            title="Review Schedule"
                            description="Set how often this plan should be reviewed and updated."
                        />
                        <CardContent>
                            <div className="space-y-1.5">
                                <Label>Review Frequency</Label>
                                <Select
                                    value={String(
                                        data.content.review_schedule
                                            .frequency_months,
                                    )}
                                    onValueChange={(v) =>
                                        handleFrequencyChange(Number(v))
                                    }
                                >
                                    <SelectTrigger className="w-[200px]">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {FREQUENCY_OPTIONS.map((opt) => (
                                            <SelectItem
                                                key={opt.value}
                                                value={String(opt.value)}
                                            >
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    Next review date is auto-calculated when a
                                    start date and frequency are set.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ─── Submit ─── */}
                    <div className="flex items-center justify-between rounded-lg border bg-muted p-4">
                        <p className="text-sm text-muted-foreground">
                            {data.status === 'draft'
                                ? 'This plan will be saved as a draft.'
                                : 'This plan will be created and set to active.'}
                        </p>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.get('/operations/care-plans')
                                }
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={processing}>
                                Create Care Plan
                            </Button>
                        </div>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
