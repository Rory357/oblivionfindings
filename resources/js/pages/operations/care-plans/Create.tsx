import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Info } from 'lucide-react';

const PLAN_TEMPLATES: Record<string, any> = {
    standard_support: {
        plan_type: 'support_plan',
        title: 'Support Plan',
        content: {
            support_needs: { daily_living: true, personal_care: true, community_access: true, social_participation: true },
            support_strategies: 'Goals-based support approach focusing on independence and community participation.',
            review_schedule: { frequency_months: 3 },
        },
    },
    behaviour_support: {
        plan_type: 'behaviour_plan',
        title: 'Behaviour Support Plan',
        content: {
            support_needs: { behaviour_support: true, communication: true, health_management: true },
            support_strategies: 'Positive behaviour support framework with proactive and reactive strategies.',
            review_schedule: { frequency_months: 3 },
        },
    },
    health_wellbeing: {
        plan_type: 'health_plan',
        title: 'Health & Wellbeing Plan',
        content: {
            support_needs: { health_management: true, personal_care: true, daily_living: true },
            support_strategies: 'Health monitoring and wellbeing support with allied health coordination.',
            review_schedule: { frequency_months: 6 },
        },
    },
    transition: {
        plan_type: 'transition_plan',
        title: 'Transition Plan',
        content: {
            support_needs: { daily_living: true, employment: true, education_training: true, community_access: true },
            support_strategies: 'Structured transition support with milestone-based goals and gradual independence building.',
            review_schedule: { frequency_months: 1 },
        },
    },
};

const SUPPORT_NEED_OPTIONS = [
    { key: 'daily_living', label: 'Daily Living' },
    { key: 'personal_care', label: 'Personal Care' },
    { key: 'community_access', label: 'Community Access' },
    { key: 'health_management', label: 'Health Management' },
    { key: 'communication', label: 'Communication' },
    { key: 'behaviour_support', label: 'Behaviour Support' },
    { key: 'employment', label: 'Employment' },
    { key: 'education_training', label: 'Education/Training' },
    { key: 'social_participation', label: 'Social Participation' },
    { key: 'cultural_needs', label: 'Cultural Needs' },
    { key: 'spiritual_needs', label: 'Spiritual Needs' },
    { key: 'financial_management', label: 'Financial Management' },
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

type Props = {
    clients: { id: number; first_name: string; last_name: string }[];
    staff?: { id: number; name: string }[];
};

export default function CarePlanCreate({ clients = [], staff = [] }: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';

    const urlParams = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
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
        if (templateKey === 'blank' || !PLAN_TEMPLATES[templateKey]) return;
        const template = PLAN_TEMPLATES[templateKey];
        setData((prev: any) => ({
            ...prev,
            plan_type: template.plan_type ?? prev.plan_type,
            title: template.title ?? prev.title,
            content: {
                ...prev.content,
                support_needs: template.content?.support_needs ?? prev.content.support_needs,
                support_strategies: template.content?.support_strategies ?? prev.content.support_strategies,
                review_schedule: template.content?.review_schedule ?? prev.content.review_schedule,
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

    const handleFrequencyChange = (months: number) => {
        setData((prev: any) => {
            const nextReview = prev.starts_at ? addMonths(prev.starts_at, months) : prev.next_review_at;
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
                    <div className="mb-4 flex items-start gap-3 rounded-lg border border-indigo-300 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-950/30">
                        <Info className="mt-0.5 h-5 w-5 shrink-0 text-indigo-600 dark:text-indigo-400" />
                        <div>
                            <p className="text-sm font-medium text-indigo-800 dark:text-indigo-200">Onboarding in Progress</p>
                            <p className="mt-0.5 text-xs text-indigo-700 dark:text-indigo-300">
                                This care plan is being created as part of the client onboarding process. Complete the plan details below.
                            </p>
                        </div>
                    </div>
                )}

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Section 1: Plan Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Plan Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="client_id">{clientSingular} *</Label>
                                    <Select value={data.client_id} onValueChange={(v) => setData('client_id', v)}>
                                        <SelectTrigger id="client_id">
                                            <SelectValue placeholder={`Select ${clientSingular.toLowerCase()}`} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(clients ?? []).map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>
                                                    {c.first_name} {c.last_name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {errors.client_id && <p className="text-xs text-destructive">{errors.client_id}</p>}
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="plan_type">Plan Type *</Label>
                                    <Select value={data.plan_type} onValueChange={(v) => setData('plan_type', v)}>
                                        <SelectTrigger id="plan_type">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="support_plan">Support Plan</SelectItem>
                                            <SelectItem value="behaviour_plan">Behaviour Plan</SelectItem>
                                            <SelectItem value="health_plan">Health Plan</SelectItem>
                                            <SelectItem value="transition_plan">Transition Plan</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="title">Title *</Label>
                                <Input
                                    id="title"
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    placeholder="e.g. John's Support Plan 2026"
                                />
                                {errors.title && <p className="text-xs text-destructive">{errors.title}</p>}
                            </div>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor="starts_at">Start Date</Label>
                                    <Input id="starts_at" type="date" value={data.starts_at} onChange={(e) => setData('starts_at', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="ends_at">End Date</Label>
                                    <Input id="ends_at" type="date" value={data.ends_at} onChange={(e) => setData('ends_at', e.target.value)} />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="next_review_at">Next Review</Label>
                                    <Input id="next_review_at" type="date" value={data.next_review_at} onChange={(e) => setData('next_review_at', e.target.value)} />
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor="status">Status</Label>
                                <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                    <SelectTrigger id="status" className="w-[160px]">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="draft">Draft</SelectItem>
                                        <SelectItem value="active">Active</SelectItem>
                                        <SelectItem value="review">Review</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 2: Template */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Template (Optional)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-1.5">
                                <Label>Start from Template</Label>
                                <Select onValueChange={(v) => applyTemplate(v)}>
                                    <SelectTrigger className="w-[280px]">
                                        <SelectValue placeholder="Select a template..." />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="blank">Blank</SelectItem>
                                        <SelectItem value="standard_support">Standard Support Plan</SelectItem>
                                        <SelectItem value="behaviour_support">Behaviour Support Plan</SelectItem>
                                        <SelectItem value="health_wellbeing">Health & Wellbeing Plan</SelectItem>
                                        <SelectItem value="transition">Transition Plan</SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">Selecting a template will pre-fill the sections below.</p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 3: Support Needs */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Support Needs</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                {SUPPORT_NEED_OPTIONS.map((option) => (
                                    <label key={option.key} className="flex items-center gap-2 text-sm">
                                        <Checkbox
                                            checked={!!data.content.support_needs[option.key]}
                                            onCheckedChange={() => toggleSupportNeed(option.key)}
                                        />
                                        {option.label}
                                    </label>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 4: Risk Factors */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Risk Factors</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                value={data.content.risk_factors}
                                onChange={(e) => setContentField('risk_factors', e.target.value)}
                                placeholder="Describe any known risk factors, triggers, or safety concerns..."
                                rows={4}
                            />
                        </CardContent>
                    </Card>

                    {/* Section 5: Support Strategies */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Support Strategies</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                value={data.content.support_strategies}
                                onChange={(e) => setContentField('support_strategies', e.target.value)}
                                placeholder="Describe the support strategies and approaches to be used..."
                                rows={4}
                            />
                        </CardContent>
                    </Card>

                    {/* Section 6: Communication Preferences */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Communication Preferences</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                value={data.content.communication_preferences}
                                onChange={(e) => setContentField('communication_preferences', e.target.value)}
                                placeholder="Describe the client's communication preferences, methods, and any assistive technology used..."
                                rows={4}
                            />
                        </CardContent>
                    </Card>

                    {/* Section 7: Review Schedule */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Review Schedule</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-1.5">
                                <Label>Review Frequency</Label>
                                <Select
                                    value={String(data.content.review_schedule.frequency_months)}
                                    onValueChange={(v) => handleFrequencyChange(Number(v))}
                                >
                                    <SelectTrigger className="w-[200px]">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {FREQUENCY_OPTIONS.map((opt) => (
                                            <SelectItem key={opt.value} value={String(opt.value)}>
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    Next review date will be auto-calculated when a start date and frequency are set.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex items-center justify-end gap-2">
                        <Button type="button" variant="outline" onClick={() => router.get('/operations/care-plans')}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Create Care Plan
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
