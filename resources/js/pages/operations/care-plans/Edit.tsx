import {
    CarePlanDomainsBuilder,
    type CarePlanDomainDraft,
} from '@/components/care-plan-domains-builder';
import { PageHero } from '@/components/page';
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
import { CheckCircle2 } from 'lucide-react';

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

function parseContent(raw: any): {
    support_needs: Record<string, boolean>;
    risk_factors: string;
    support_strategies: string;
    communication_preferences: string;
    review_schedule: { frequency_months: number };
    domains: CarePlanDomainDraft[];
} {
    const content =
        typeof raw === 'string'
            ? (() => {
                  try {
                      return JSON.parse(raw);
                  } catch {
                      return {};
                  }
              })()
            : (raw ?? {});
    return {
        support_needs: content.support_needs ?? {},
        risk_factors: content.risk_factors ?? '',
        support_strategies: content.support_strategies ?? '',
        communication_preferences: content.communication_preferences ?? '',
        review_schedule: content.review_schedule ?? { frequency_months: 3 },
        domains: content.domains ?? [],
    };
}

type Props = {
    care_plan: {
        id: number;
        client_id: number;
        title: string;
        plan_type: string;
        status: string;
        starts_at: string | null;
        ends_at: string | null;
        next_review_at: string | null;
        content: any;
        client: { id: number; first_name: string; last_name: string } | null;
    };
    clients: { id: number; first_name: string; last_name: string }[];
    staff?: { id: number; name: string }[];
};

export default function CarePlanEdit({
    care_plan,
    clients = [],
    staff = [],
}: Props) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';

    const parsedContent = parseContent(care_plan?.content);

    const { data, setData, put, processing, errors } = useForm({
        client_id: String(care_plan?.client_id ?? ''),
        plan_type: care_plan?.plan_type ?? 'support_plan',
        title: care_plan?.title ?? '',
        starts_at: care_plan?.starts_at ?? '',
        ends_at: care_plan?.ends_at ?? '',
        next_review_at: care_plan?.next_review_at ?? '',
        status: care_plan?.status ?? 'draft',
        content: parsedContent,
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        put(`/operations/care-plans/${care_plan.id}`);
    };

    const handleCompleteReview = () => {
        router.post(
            `/operations/care-plans/${care_plan.id}/complete-review`,
            {},
            { preserveScroll: true },
        );
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

    return (
        <AppLayout>
            <Head title={`Edit: ${care_plan?.title ?? 'Care Plan'}`} />
            <PageHero
                variant="compact"
                title={`Edit: ${care_plan?.title ?? 'Care Plan'}`}
                backHref={`/operations/care-plans/${care_plan?.id}`}
            />
            <PageShell>
                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Complete Review Banner */}
                    {care_plan?.status === 'review' && (
                        <div className="flex items-center justify-between rounded-lg border border-status-warning/30 bg-status-warning-bg p-4 dark:border-status-warning/30">
                            <div>
                                <p className="text-sm font-medium text-status-warning dark:text-status-warning">
                                    Plan Under Review
                                </p>
                                <p className="mt-0.5 text-xs text-status-warning dark:text-status-warning">
                                    This plan is currently under review. Make
                                    any updates and complete the review when
                                    ready.
                                </p>
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                onClick={handleCompleteReview}
                                className="gap-1.5"
                            >
                                <CheckCircle2 className="h-3.5 w-3.5" />
                                Complete Review
                            </Button>
                        </div>
                    )}

                    {/* Section 1: Plan Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Plan Details
                            </CardTitle>
                        </CardHeader>
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
                                    disabled={care_plan.status !== 'draft'}
                                >
                                    <SelectTrigger
                                        id="status"
                                        className="w-[160px]"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {care_plan.status === 'draft' ? (
                                            <>
                                                <SelectItem value="draft">
                                                    Draft
                                                </SelectItem>
                                                <SelectItem value="active">
                                                    Active
                                                </SelectItem>
                                            </>
                                        ) : (
                                            <SelectItem
                                                value={care_plan.status}
                                            >
                                                {care_plan.status === 'review'
                                                    ? 'In review'
                                                    : care_plan.status ===
                                                        'archived'
                                                      ? 'Archived'
                                                      : 'Active'}
                                            </SelectItem>
                                        )}
                                    </SelectContent>
                                </Select>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 2: Support Needs */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Support Needs
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                                {SUPPORT_NEED_OPTIONS.map((option) => (
                                    <label
                                        key={option.key}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={
                                                !!data.content.support_needs[
                                                    option.key
                                                ]
                                            }
                                            onCheckedChange={() =>
                                                toggleSupportNeed(option.key)
                                            }
                                        />
                                        {option.label}
                                    </label>
                                ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Section 3: Risk Factors */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Risk Factors
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                value={data.content.risk_factors}
                                onChange={(e) =>
                                    setContentField(
                                        'risk_factors',
                                        e.target.value,
                                    )
                                }
                                placeholder="Describe any known risk factors, triggers, or safety concerns..."
                                rows={4}
                            />
                        </CardContent>
                    </Card>

                    {/* Section 4: Support Strategies */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Support Strategies
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                value={data.content.support_strategies}
                                onChange={(e) =>
                                    setContentField(
                                        'support_strategies',
                                        e.target.value,
                                    )
                                }
                                placeholder="Describe the support strategies and approaches to be used..."
                                rows={4}
                            />
                        </CardContent>
                    </Card>

                    {/* Section 5: Communication Preferences */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Communication Preferences
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                value={data.content.communication_preferences}
                                onChange={(e) =>
                                    setContentField(
                                        'communication_preferences',
                                        e.target.value,
                                    )
                                }
                                placeholder="Describe the client's communication preferences, methods, and any assistive technology used..."
                                rows={4}
                            />
                        </CardContent>
                    </Card>

                    {/* Section 6: Review Schedule */}
                    <CarePlanDomainsBuilder
                        domains={data.content.domains}
                        staff={staff}
                        errors={errors as Record<string, string>}
                        onChange={(domains) =>
                            setContentField('domains', domains)
                        }
                    />

                    {/* Section 7: Review Schedule */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Review Schedule
                            </CardTitle>
                        </CardHeader>
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
                                    Next review date will be auto-calculated
                                    when a start date and frequency are set.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex items-center justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                router.get(
                                    `/operations/care-plans/${care_plan.id}`,
                                )
                            }
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Save Changes
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
