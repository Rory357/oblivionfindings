import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { FormEventHandler } from 'react';
import { FileSignature, User } from 'lucide-react';

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
    };
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

export default function CreateOffer({ application, sites, roles }: Props) {
    const candidateName = `${application.candidate.first_name} ${application.candidate.last_name}`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr/people' },
        { title: 'Recruitment', href: '/hr/recruitment/candidates' },
        { title: candidateName, href: `/hr/recruitment/candidates/${application.candidate.id}` },
        { title: 'Create Offer', href: `/hr/recruitment/applications/${application.id}/offer/create` },
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
    });

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/hr/recruitment/offers');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Create Offer - ${candidateName}`} />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center gap-3">
                    <FileSignature className="h-7 w-7 text-primary" />
                    <div>
                        <h1 className="text-2xl font-bold">Create Offer</h1>
                        <p className="text-muted-foreground">
                            For {candidateName} &middot; {application.position_title}
                        </p>
                    </div>
                </div>

                {/* Candidate Info */}
                <Card>
                    <CardContent className="flex items-center gap-4 pt-6">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/10">
                            <User className="h-5 w-5 text-primary" />
                        </div>
                        <div>
                            <p className="font-medium">{candidateName}</p>
                            <p className="text-sm text-muted-foreground">{application.candidate.personal_email}</p>
                        </div>
                    </CardContent>
                </Card>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Position Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Position Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="position_title">Position Title *</Label>
                                    <Input
                                        id="position_title"
                                        value={form.data.position_title}
                                        onChange={(e) => form.setData('position_title', e.target.value)}
                                        required
                                    />
                                    {form.errors.position_title && <p className="mt-1 text-sm text-destructive">{form.errors.position_title}</p>}
                                </div>
                                <div>
                                    <Label>Position Role</Label>
                                    <Select value={form.data.position_role || '__none__'} onValueChange={(v) => form.setData('position_role', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select role" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select role</SelectItem>
                                            {roles.map((r) => (
                                                <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.position_role && <p className="mt-1 text-sm text-destructive">{form.errors.position_role}</p>}
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="proposed_start_date">Proposed Start Date *</Label>
                                    <Input
                                        id="proposed_start_date"
                                        type="date"
                                        value={form.data.proposed_start_date}
                                        onChange={(e) => form.setData('proposed_start_date', e.target.value)}
                                        required
                                    />
                                    {form.errors.proposed_start_date && <p className="mt-1 text-sm text-destructive">{form.errors.proposed_start_date}</p>}
                                </div>
                                <div>
                                    <Label>Primary Site</Label>
                                    <Select value={form.data.primary_site_id || '__none__'} onValueChange={(v) => form.setData('primary_site_id', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select site" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select site</SelectItem>
                                            {sites.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.primary_site_id && <p className="mt-1 text-sm text-destructive">{form.errors.primary_site_id}</p>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Employment Terms */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Employment Terms</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Employment Type *</Label>
                                    <Select value={form.data.employment_type || '__none__'} onValueChange={(v) => form.setData('employment_type', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select type" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select type</SelectItem>
                                            {employmentTypeOptions.map((t) => (
                                                <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.employment_type && <p className="mt-1 text-sm text-destructive">{form.errors.employment_type}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="hours_per_week">Hours Per Week</Label>
                                    <Input
                                        id="hours_per_week"
                                        type="number"
                                        step="0.5"
                                        value={form.data.hours_per_week}
                                        onChange={(e) => form.setData('hours_per_week', e.target.value)}
                                        placeholder="e.g. 40"
                                    />
                                    {form.errors.hours_per_week && <p className="mt-1 text-sm text-destructive">{form.errors.hours_per_week}</p>}
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="hourly_rate">Hourly Rate ($)</Label>
                                    <Input
                                        id="hourly_rate"
                                        type="number"
                                        step="0.01"
                                        value={form.data.hourly_rate}
                                        onChange={(e) => form.setData('hourly_rate', e.target.value)}
                                        placeholder="e.g. 30.00"
                                    />
                                    {form.errors.hourly_rate && <p className="mt-1 text-sm text-destructive">{form.errors.hourly_rate}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="annual_salary">Annual Salary ($)</Label>
                                    <Input
                                        id="annual_salary"
                                        type="number"
                                        step="0.01"
                                        value={form.data.annual_salary}
                                        onChange={(e) => form.setData('annual_salary', e.target.value)}
                                        placeholder="e.g. 65000.00"
                                    />
                                    {form.errors.annual_salary && <p className="mt-1 text-sm text-destructive">{form.errors.annual_salary}</p>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Conditions */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Conditions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                value={form.data.conditions}
                                onChange={(e) => form.setData('conditions', e.target.value)}
                                rows={5}
                                placeholder="Any conditions of employment (e.g., subject to police check, first aid certificate required)..."
                            />
                            {form.errors.conditions && <p className="mt-1 text-sm text-destructive">{form.errors.conditions}</p>}
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Creating Offer...' : 'Create Offer'}
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href={`/hr/recruitment/candidates/${application.candidate.id}`}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
