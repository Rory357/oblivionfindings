import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Briefcase, Globe, MessageSquare, User, UserPlus } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    sites: Array<{ id: number; name: string }>;
    roles: Array<{ value: string; label: string }>;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'Recruitment', href: '/hr/recruitment' },
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

    const initials = (
        (form.data.first_name?.[0] ?? '') + (form.data.last_name?.[0] ?? '')
    ).toUpperCase();

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post('/hr/recruitment/candidates');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New Candidate" />
            <PageShell>
                <PageHero category="hr" variant="compact"
                    title="New Candidate"
                    description="Add a new candidate to the recruitment pipeline."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href="/hr/recruitment">Cancel</Link>
                        </Button>
                    }
                />

                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardContent className="space-y-8 p-6">
                            {/* Avatar Preview */}
                            <div className="flex items-center gap-4">
                                <div
                                    className={`flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl text-xl font-bold transition-all ${
                                        initials
                                            ? 'border-2 border-primary/20 bg-primary/10 text-primary'
                                            : 'border-2 border-border bg-muted text-muted-foreground'
                                    }`}
                                >
                                    {initials || <User className="h-6 w-6" />}
                                </div>
                                <div>
                                    <p className="text-lg font-semibold">
                                        {form.data.first_name ||
                                        form.data.last_name
                                            ? `${form.data.first_name} ${form.data.last_name}`.trim()
                                            : 'New Candidate'}
                                    </p>
                                    {form.data.personal_email && (
                                        <p className="text-sm text-muted-foreground">
                                            {form.data.personal_email}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <Separator />

                            {/* Personal Details */}
                            <div className="space-y-4">
                                <div className="flex items-center gap-2 text-sm font-semibold">
                                    <UserPlus className="h-4 w-4 text-primary" />
                                    Personal Details
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="first_name">
                                            First Name *
                                        </Label>
                                        <Input
                                            id="first_name"
                                            value={form.data.first_name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'first_name',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                        {form.errors.first_name && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.first_name}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="last_name">
                                            Last Name *
                                        </Label>
                                        <Input
                                            id="last_name"
                                            value={form.data.last_name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'last_name',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                        {form.errors.last_name && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.last_name}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="preferred_name">
                                            Preferred Name
                                        </Label>
                                        <Input
                                            id="preferred_name"
                                            value={form.data.preferred_name}
                                            onChange={(e) =>
                                                form.setData(
                                                    'preferred_name',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="personal_email">
                                            Email *
                                        </Label>
                                        <Input
                                            id="personal_email"
                                            type="email"
                                            value={form.data.personal_email}
                                            onChange={(e) =>
                                                form.setData(
                                                    'personal_email',
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        />
                                        {form.errors.personal_email && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.personal_email}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="personal_phone">
                                            Phone
                                        </Label>
                                        <Input
                                            id="personal_phone"
                                            value={form.data.personal_phone}
                                            onChange={(e) =>
                                                form.setData(
                                                    'personal_phone',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            {/* Source */}
                            <div className="space-y-4">
                                <div className="flex items-center gap-2 text-sm font-semibold">
                                    <Globe className="h-4 w-4 text-primary" />
                                    Source
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label>Source *</Label>
                                        <Select
                                            value={
                                                form.data.source || '__none__'
                                            }
                                            onValueChange={(v) =>
                                                form.setData(
                                                    'source',
                                                    v === '__none__' ? '' : v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select source" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">
                                                    Select source
                                                </SelectItem>
                                                {sourceOptions.map((s) => (
                                                    <SelectItem
                                                        key={s.value}
                                                        value={s.value}
                                                    >
                                                        {s.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.source && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.source}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="source_detail">
                                            Source Detail
                                        </Label>
                                        <Input
                                            id="source_detail"
                                            value={form.data.source_detail}
                                            onChange={(e) =>
                                                form.setData(
                                                    'source_detail',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="e.g. Referrer name, agency..."
                                        />
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            {/* Position */}
                            <div className="space-y-4">
                                <div className="flex items-center gap-2 text-sm font-semibold">
                                    <Briefcase className="h-4 w-4 text-primary" />
                                    Position Details
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-1.5">
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
                                            placeholder="e.g. Support Worker"
                                            required
                                        />
                                        {form.errors.position_title && (
                                            <p className="text-xs text-destructive">
                                                {form.errors.position_title}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-1.5">
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
                                            <SelectTrigger>
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
                                    <div className="space-y-1.5">
                                        <Label>Target Site</Label>
                                        <Select
                                            value={
                                                form.data.target_site_id ||
                                                '__none__'
                                            }
                                            onValueChange={(v) =>
                                                form.setData(
                                                    'target_site_id',
                                                    v === '__none__' ? '' : v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="No specific site" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">
                                                    No specific site
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
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            {/* Notes */}
                            <div className="space-y-4">
                                <div className="flex items-center gap-2 text-sm font-semibold">
                                    <MessageSquare className="h-4 w-4 text-primary" />
                                    Notes
                                </div>
                                <Textarea
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                    rows={3}
                                    placeholder="Any additional notes about the candidate..."
                                />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Sticky Footer */}
                    <div className="sticky bottom-0 -mx-6 mt-6 flex items-center justify-end gap-3 border-t bg-background/95 px-6 py-4 backdrop-blur">
                        <Button type="button" variant="outline" asChild>
                            <Link href="/hr/recruitment">Cancel</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Creating...'
                                : 'Create Candidate'}
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
