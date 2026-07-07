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
import { PageHero, PageLayout } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    Briefcase,
    CheckCircle2,
    DollarSign,
    FileSignature,
    FileText,
    Mail,
    MapPin,
    Phone,
    Shield,
    Star,
    Upload,
} from 'lucide-react';
import { FormEventHandler } from 'react';

interface InterviewSummary {
    type: string;
    status: string;
    rating: number | null;
    outcome: string | null;
    scores: Array<{
        overall_score: number | null;
        recommendation: string | null;
    }>;
}

interface RefSummary {
    referee_name: string;
    status: string;
}

interface DocSummary {
    category: string;
    category_label: string;
    original_name: string;
}

interface Application {
    id: number;
    position_title: string;
    position_role: string | null;
    stage: string;
    candidate: {
        id: number;
        first_name: string;
        last_name: string;
        personal_email: string;
        personal_phone: string | null;
        source: string | null;
    };
    job_posting: {
        title: string;
        department: string | null;
        location: string | null;
        salary_range_min: number | null;
        salary_range_max: number | null;
        show_salary: boolean;
    } | null;
    interviews: InterviewSummary[];
    reference_checks: RefSummary[];
    documents: DocSummary[];
}

interface Props {
    application: Application;
    sites: Array<{ id: number; name: string }>;
    roles: Array<{ value: string; label: string }>;
}

const employmentTypeOptions = [
    { value: 'full_time', label: 'Full Time' },
    { value: 'part_time', label: 'Part Time' },
    { value: 'casual', label: 'Casual' },
    { value: 'fixed_term', label: 'Fixed Term' },
    { value: 'contractor', label: 'Contractor' },
];

const formatCurrency = (amount: number | null) => {
    if (!amount) return null;
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        maximumFractionDigits: 0,
    }).format(amount);
};

const recColors: Record<string, string> = {
    strong_yes: 'text-status-success',
    yes: 'text-status-success',
    maybe: 'text-status-warning',
    no: 'text-status-warning',
    strong_no: 'text-status-critical',
};

const refStatusColors: Record<string, string> = {
    completed: 'border-status-success/30 text-status-success bg-status-success-bg',
    received: 'border-status-info/30 text-status-info bg-status-info-bg',
    requested: 'border-status-warning/30 text-status-warning bg-status-warning-bg',
    pending: 'border-border/30 text-muted-foreground bg-muted-foreground/80/10',
};

export default function CreateOffer({ application, sites, roles }: Props) {
    const candidateName = `${application.candidate.first_name} ${application.candidate.last_name}`;
    const initials = (
        (application.candidate.first_name?.[0] ?? '') +
        (application.candidate.last_name?.[0] ?? '')
    ).toUpperCase();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr/people' },
        { title: 'Recruitment', href: '/hr/recruitment' },
        {
            title: candidateName,
            href: `/hr/recruitment/candidates/${application.candidate.id}`,
        },
        { title: 'Create Offer', href: '#' },
    ];

    const form = useForm({
        application_id: application.id,
        position_title: application.position_title || '',
        position_role: application.position_role || '',
        proposed_start_date: '',
        employment_type: '',
        hours_per_week: '',
        hourly_rate: '',
        annual_salary: '',
        primary_site_id: '',
        conditions: '',
        offer_letter: null as File | null,
    });

    // Auto-calculate salary <-> hourly rate (52 weeks/year)
    function updateHourlyRate(value: string) {
        const rate = parseFloat(value);
        const hours = parseFloat(form.data.hours_per_week);
        if (rate > 0 && hours > 0) {
            form.setData((prev) => ({
                ...prev,
                hourly_rate: value,
                annual_salary: (rate * hours * 52).toFixed(2),
            }));
        } else {
            form.setData('hourly_rate', value);
        }
    }

    function updateAnnualSalary(value: string) {
        const salary = parseFloat(value);
        const hours = parseFloat(form.data.hours_per_week);
        if (salary > 0 && hours > 0) {
            form.setData((prev) => ({
                ...prev,
                annual_salary: value,
                hourly_rate: (salary / (hours * 52)).toFixed(2),
            }));
        } else {
            form.setData('annual_salary', value);
        }
    }

    function updateHoursPerWeek(value: string) {
        const hours = parseFloat(value);
        const rate = parseFloat(form.data.hourly_rate);
        const salary = parseFloat(form.data.annual_salary);
        if (hours > 0 && rate > 0) {
            form.setData((prev) => ({
                ...prev,
                hours_per_week: value,
                annual_salary: (rate * hours * 52).toFixed(2),
            }));
        } else if (hours > 0 && salary > 0) {
            form.setData((prev) => ({
                ...prev,
                hours_per_week: value,
                hourly_rate: (salary / (hours * 52)).toFixed(2),
            }));
        } else {
            form.setData('hours_per_week', value);
        }
    }

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/hr/recruitment/offers', { forceFormData: true });
    };

    const jp = application.job_posting;
    const completedInterviews = application.interviews.filter(
        (i) => i.status === 'completed',
    );
    const avgRating =
        completedInterviews.length > 0
            ? (
                  completedInterviews.reduce(
                      (sum, i) => sum + (i.rating ?? 0),
                      0,
                  ) / completedInterviews.length
              ).toFixed(1)
            : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Create Offer - ${candidateName}`} />
            <PageLayout
                hero={
                    <PageHero category="hr"
                        avatar={{ fallback: initials }}
                        title="Prepare Offer"
                        description={`${candidateName} · ${application.position_title}`}
                    />
                }
            >
                {/* Two-column layout */}
                <div className="grid gap-6 lg:grid-cols-[1fr_380px]">
                    {/* Left: Offer Form */}
                    <form onSubmit={handleSubmit} className="space-y-5">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Position Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="position_title">
                                            Position Title *
                                        </Label>
                                        <Input
                                            id="position_title"
                                            value={form.data.position_title}
                                            onChange={(e) =>
                                                form.setData(
                                                    'position_title',
                                                    e.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        {form.errors.position_title && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {form.errors.position_title}
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <Label>Position Role</Label>
                                        <Select
                                            value={
                                                form.data.position_role ||
                                                '__none__'
                                            }
                                            onValueChange={(v) =>
                                                form.setData(
                                                    'position_role',
                                                    v === '__none__' ? '' : v,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="mt-1">
                                                <SelectValue placeholder="Select role" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">
                                                    Select role
                                                </SelectItem>
                                                {roles.map((r) => (
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
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="proposed_start_date">
                                            Proposed Start Date *
                                        </Label>
                                        <Input
                                            id="proposed_start_date"
                                            type="date"
                                            value={
                                                form.data.proposed_start_date
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'proposed_start_date',
                                                    e.target.value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        {form.errors.proposed_start_date && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {
                                                    form.errors
                                                        .proposed_start_date
                                                }
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <Label>Primary Site *</Label>
                                        <Select
                                            value={
                                                form.data.primary_site_id ||
                                                '__none__'
                                            }
                                            onValueChange={(v) =>
                                                form.setData(
                                                    'primary_site_id',
                                                    v === '__none__' ? '' : v,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="mt-1">
                                                <SelectValue placeholder="Select site" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">
                                                    Select site
                                                </SelectItem>
                                                {sites.map((s) => (
                                                    <SelectItem
                                                        key={s.id}
                                                        value={String(s.id)}
                                                    >
                                                        {s.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.primary_site_id && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {form.errors.primary_site_id}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Compensation
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {jp &&
                                    (jp.salary_range_min ||
                                        jp.salary_range_max) && (
                                        <div className="flex items-center gap-2 rounded-lg border border-status-success/20 bg-status-success-bg p-3 text-sm">
                                            <DollarSign className="h-4 w-4 shrink-0 text-status-success" />
                                            <span>
                                                <strong>
                                                    Job posting salary range:
                                                </strong>{' '}
                                                {formatCurrency(
                                                    jp.salary_range_min,
                                                )}{' '}
                                                –{' '}
                                                {formatCurrency(
                                                    jp.salary_range_max,
                                                )}
                                            </span>
                                        </div>
                                    )}
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Employment Type *</Label>
                                        <Select
                                            value={
                                                form.data.employment_type ||
                                                '__none__'
                                            }
                                            onValueChange={(v) =>
                                                form.setData(
                                                    'employment_type',
                                                    v === '__none__' ? '' : v,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="mt-1">
                                                <SelectValue placeholder="Select type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">
                                                    Select type
                                                </SelectItem>
                                                {employmentTypeOptions.map(
                                                    (t) => (
                                                        <SelectItem
                                                            key={t.value}
                                                            value={t.value}
                                                        >
                                                            {t.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.employment_type && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {form.errors.employment_type}
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <Label htmlFor="hours_per_week">
                                            Hours Per Week *
                                        </Label>
                                        <Input
                                            id="hours_per_week"
                                            type="number"
                                            step="0.5"
                                            value={form.data.hours_per_week}
                                            onChange={(e) =>
                                                updateHoursPerWeek(
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. 40"
                                            className="mt-1"
                                        />
                                        {form.errors.hours_per_week && (
                                            <p className="mt-1 text-xs text-destructive">
                                                {form.errors.hours_per_week}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="hourly_rate">
                                            Hourly Rate (NZD)
                                        </Label>
                                        <Input
                                            id="hourly_rate"
                                            type="number"
                                            step="0.01"
                                            value={form.data.hourly_rate}
                                            onChange={(e) =>
                                                updateHourlyRate(e.target.value)
                                            }
                                            placeholder="e.g. 30.00"
                                            className="mt-1"
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="annual_salary">
                                            Annual Salary (NZD)
                                        </Label>
                                        <Input
                                            id="annual_salary"
                                            type="number"
                                            step="0.01"
                                            value={form.data.annual_salary}
                                            onChange={(e) =>
                                                updateAnnualSalary(
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. 65000.00"
                                            className="mt-1"
                                        />
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Conditions
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Textarea
                                    value={form.data.conditions}
                                    onChange={(e) =>
                                        form.setData(
                                            'conditions',
                                            e.target.value,
                                        )
                                    }
                                    rows={4}
                                    placeholder="e.g. Subject to police vetting, first aid certificate required, 90-day trial period..."
                                />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Offer Letter
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="mb-3 text-sm text-muted-foreground">
                                    Upload your prepared offer letter
                                    (optional). If not uploaded, you can send
                                    the offer details directly to the candidate.
                                </p>
                                <div className="rounded-lg border-2 border-dashed border-muted-foreground/25 p-6 text-center transition-colors hover:border-primary/50">
                                    <Upload className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                                    <p className="mb-2 text-xs text-muted-foreground">
                                        PDF, DOC, or DOCX (max 20MB)
                                    </p>
                                    <Input
                                        type="file"
                                        accept=".pdf,.doc,.docx"
                                        className="mx-auto max-w-xs"
                                        onChange={(e) =>
                                            form.setData(
                                                'offer_letter',
                                                e.target.files?.[0] ?? null,
                                            )
                                        }
                                    />
                                </div>
                                {form.data.offer_letter && (
                                    <p className="mt-2 flex items-center gap-1 text-sm text-status-success">
                                        <FileText className="h-3.5 w-3.5" />{' '}
                                        {form.data.offer_letter.name}
                                    </p>
                                )}
                                {form.errors.offer_letter && (
                                    <p className="mt-1 text-xs text-destructive">
                                        {form.errors.offer_letter}
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <div className="flex items-center gap-3">
                            <Button
                                type="submit"
                                disabled={form.processing}
                                size="lg"
                            >
                                {form.processing
                                    ? 'Creating Offer...'
                                    : 'Create Offer'}
                            </Button>
                            <Button type="button" variant="outline" asChild>
                                <Link
                                    href={`/hr/recruitment/candidates/${application.candidate.id}`}
                                >
                                    Cancel
                                </Link>
                            </Button>
                        </div>
                    </form>

                    {/* Right: Context Panel */}
                    <div className="space-y-4">
                        {/* Candidate Info */}
                        <Card>
                            <CardHeader className="pb-2">
                                <CardTitle className="text-sm">
                                    Candidate
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                                        {initials}
                                    </div>
                                    <div>
                                        <p className="text-sm font-medium">
                                            {candidateName}
                                        </p>
                                        <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                            <Mail className="h-3 w-3" />
                                            {
                                                application.candidate
                                                    .personal_email
                                            }
                                        </p>
                                        {application.candidate
                                            .personal_phone && (
                                            <p className="flex items-center gap-1 text-xs text-muted-foreground">
                                                <Phone className="h-3 w-3" />
                                                {
                                                    application.candidate
                                                        .personal_phone
                                                }
                                            </p>
                                        )}
                                    </div>
                                </div>
                                {application.candidate.source && (
                                    <Badge
                                        variant="outline"
                                        className="text-xs capitalize"
                                    >
                                        {application.candidate.source.replace(
                                            /_/g,
                                            ' ',
                                        )}
                                    </Badge>
                                )}
                            </CardContent>
                        </Card>

                        {/* Job Posting Info */}
                        {jp && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-1.5 text-sm">
                                        <Briefcase className="h-3.5 w-3.5" />{' '}
                                        Job Posting
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2 text-sm">
                                    <p className="font-medium">{jp.title}</p>
                                    <div className="flex flex-wrap gap-2 text-xs text-muted-foreground">
                                        {jp.department && (
                                            <span className="flex items-center gap-1">
                                                {jp.department}
                                            </span>
                                        )}
                                        {jp.location && (
                                            <span className="flex items-center gap-1">
                                                <MapPin className="h-3 w-3" />
                                                {jp.location}
                                            </span>
                                        )}
                                    </div>
                                    {(jp.salary_range_min ||
                                        jp.salary_range_max) && (
                                        <div className="flex items-center gap-1 font-medium text-status-success">
                                            <DollarSign className="h-3.5 w-3.5" />
                                            {formatCurrency(
                                                jp.salary_range_min,
                                            )}{' '}
                                            –{' '}
                                            {formatCurrency(
                                                jp.salary_range_max,
                                            )}
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Interview Summary */}
                        {completedInterviews.length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-1.5 text-sm">
                                        <Star className="h-3.5 w-3.5" />{' '}
                                        Interview Results
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-2">
                                    {avgRating && (
                                        <div className="flex items-center gap-2">
                                            <div className="flex items-center gap-1 rounded-lg bg-status-warning-bg px-2.5 py-1">
                                                <Star className="h-4 w-4 fill-status-warning text-status-warning" />
                                                <span className="text-sm font-bold">
                                                    {avgRating}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    / 5
                                                </span>
                                            </div>
                                            <span className="text-xs text-muted-foreground">
                                                {completedInterviews.length}{' '}
                                                interview
                                                {completedInterviews.length > 1
                                                    ? 's'
                                                    : ''}
                                            </span>
                                        </div>
                                    )}
                                    {completedInterviews.map(
                                        (interview, idx) => (
                                            <div
                                                key={idx}
                                                className="flex items-center justify-between border-t pt-1.5 text-xs"
                                            >
                                                <span className="capitalize">
                                                    {interview.type.replace(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </span>
                                                <div className="flex items-center gap-2">
                                                    {interview.outcome && (
                                                        <Badge
                                                            variant={
                                                                interview.outcome ===
                                                                'pass'
                                                                    ? 'default'
                                                                    : 'destructive'
                                                            }
                                                            className="text-[10px] capitalize"
                                                        >
                                                            {interview.outcome}
                                                        </Badge>
                                                    )}
                                                    {interview.scores?.[0]
                                                        ?.recommendation && (
                                                        <span
                                                            className={`font-medium capitalize ${recColors[interview.scores[0].recommendation] ?? ''}`}
                                                        >
                                                            {interview.scores[0].recommendation.replace(
                                                                '_',
                                                                ' ',
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        ),
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* References */}
                        {application.reference_checks.length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-1.5 text-sm">
                                        <Shield className="h-3.5 w-3.5" />{' '}
                                        References
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-1.5">
                                    {application.reference_checks.map(
                                        (ref, idx) => (
                                            <div
                                                key={idx}
                                                className="flex items-center justify-between text-xs"
                                            >
                                                <span>{ref.referee_name}</span>
                                                <Badge
                                                    variant="outline"
                                                    className={`text-[10px] capitalize ${refStatusColors[ref.status] ?? ''}`}
                                                >
                                                    {ref.status}
                                                </Badge>
                                            </div>
                                        ),
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Documents on File */}
                        {application.documents.length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-1.5 text-sm">
                                        <FileText className="h-3.5 w-3.5" />{' '}
                                        Documents on File
                                    </CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-1.5">
                                    {application.documents.map((doc, idx) => (
                                        <div
                                            key={idx}
                                            className="flex items-center gap-2 text-xs"
                                        >
                                            <CheckCircle2 className="h-3 w-3 shrink-0 text-status-success" />
                                            <span className="truncate">
                                                {doc.category_label}
                                            </span>
                                        </div>
                                    ))}
                                    {[
                                        'police_vetting',
                                        'first_aid',
                                        'qualification',
                                    ].some(
                                        (cat) =>
                                            !application.documents.find(
                                                (d) => d.category === cat,
                                            ),
                                    ) && (
                                        <div className="mt-2 flex items-start gap-1.5 rounded-md bg-status-warning-bg p-2 text-xs text-status-warning">
                                            <AlertCircle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                            <span>
                                                Missing:{' '}
                                                {[
                                                    'police_vetting',
                                                    'first_aid',
                                                    'qualification',
                                                ]
                                                    .filter(
                                                        (cat) =>
                                                            !application.documents.find(
                                                                (d) =>
                                                                    d.category ===
                                                                    cat,
                                                            ),
                                                    )
                                                    .map((cat) =>
                                                        cat.replace(/_/g, ' '),
                                                    )
                                                    .join(', ')}
                                            </span>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
