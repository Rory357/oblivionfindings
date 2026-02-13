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
import { UserPlus } from 'lucide-react';

interface Props {
    sites: Array<{ id: number; name: string }>;
    roles: Array<{ value: string; label: string }>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'Recruitment', href: '/hr/recruitment/candidates' },
    { title: 'New Candidate', href: '/hr/recruitment/candidates/create' },
];

const sourceOptions = [
    { value: 'website', label: 'Website' },
    { value: 'referral', label: 'Referral' },
    { value: 'agency', label: 'Agency' },
    { value: 'job_board', label: 'Job Board' },
    { value: 'internal', label: 'Internal' },
    { value: 'other', label: 'Other' },
];

export default function CandidateCreate({ sites, roles }: Props) {
    const form = useForm({
        first_name: '',
        last_name: '',
        preferred_name: '',
        personal_email: '',
        personal_phone: '',
        source: '',
        source_detail: '',
        notes: '',
        position_title: '',
        position_role: '',
        target_site_id: '',
    });

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/hr/recruitment/candidates');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Candidate" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center gap-3">
                    <UserPlus className="h-7 w-7 text-primary" />
                    <h1 className="text-2xl font-bold">New Candidate</h1>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Personal Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Personal Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <Label htmlFor="first_name">First Name *</Label>
                                    <Input
                                        id="first_name"
                                        value={form.data.first_name}
                                        onChange={(e) => form.setData('first_name', e.target.value)}
                                        required
                                    />
                                    {form.errors.first_name && <p className="mt-1 text-sm text-destructive">{form.errors.first_name}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="last_name">Last Name *</Label>
                                    <Input
                                        id="last_name"
                                        value={form.data.last_name}
                                        onChange={(e) => form.setData('last_name', e.target.value)}
                                        required
                                    />
                                    {form.errors.last_name && <p className="mt-1 text-sm text-destructive">{form.errors.last_name}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="preferred_name">Preferred Name</Label>
                                    <Input
                                        id="preferred_name"
                                        value={form.data.preferred_name}
                                        onChange={(e) => form.setData('preferred_name', e.target.value)}
                                    />
                                    {form.errors.preferred_name && <p className="mt-1 text-sm text-destructive">{form.errors.preferred_name}</p>}
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="personal_email">Email *</Label>
                                    <Input
                                        id="personal_email"
                                        type="email"
                                        value={form.data.personal_email}
                                        onChange={(e) => form.setData('personal_email', e.target.value)}
                                        required
                                    />
                                    {form.errors.personal_email && <p className="mt-1 text-sm text-destructive">{form.errors.personal_email}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="personal_phone">Phone</Label>
                                    <Input
                                        id="personal_phone"
                                        value={form.data.personal_phone}
                                        onChange={(e) => form.setData('personal_phone', e.target.value)}
                                    />
                                    {form.errors.personal_phone && <p className="mt-1 text-sm text-destructive">{form.errors.personal_phone}</p>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Source */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Source</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Source *</Label>
                                    <Select value={form.data.source || '__none__'} onValueChange={(v) => form.setData('source', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select source" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">Select source</SelectItem>
                                            {sourceOptions.map((s) => (
                                                <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.source && <p className="mt-1 text-sm text-destructive">{form.errors.source}</p>}
                                </div>
                                <div>
                                    <Label htmlFor="source_detail">Source Detail</Label>
                                    <Input
                                        id="source_detail"
                                        value={form.data.source_detail}
                                        onChange={(e) => form.setData('source_detail', e.target.value)}
                                        placeholder="e.g. Referrer name, agency name, job board..."
                                    />
                                    {form.errors.source_detail && <p className="mt-1 text-sm text-destructive">{form.errors.source_detail}</p>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Position Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Position Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <Label htmlFor="position_title">Position Title *</Label>
                                    <Input
                                        id="position_title"
                                        value={form.data.position_title}
                                        onChange={(e) => form.setData('position_title', e.target.value)}
                                        placeholder="e.g. Support Worker"
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
                                <div>
                                    <Label>Target Site</Label>
                                    <Select value={form.data.target_site_id || '__none__'} onValueChange={(v) => form.setData('target_site_id', v === '__none__' ? '' : v)}>
                                        <SelectTrigger><SelectValue placeholder="Select site" /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">No specific site</SelectItem>
                                            {sites.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.target_site_id && <p className="mt-1 text-sm text-destructive">{form.errors.target_site_id}</p>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Notes */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Textarea
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                rows={4}
                                placeholder="Any additional notes about the candidate..."
                            />
                            {form.errors.notes && <p className="mt-1 text-sm text-destructive">{form.errors.notes}</p>}
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Creating...' : 'Create Candidate'}
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href="/hr/recruitment/candidates">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
