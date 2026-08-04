import { PageHero, PageLayout } from '@/components/page';
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
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

interface Profile {
    id: number;
    employee_number: string | null;
    position_title: string;
    employment_type: string;
    contract_type: string | null;
    department_id: number | null;
    team: string | null;
    work_rights_status: string | null;
    visa_type: string | null;
    visa_expires_at: string | null;
    is_active: boolean;
    start_date: string | null;
    end_date: string | null;
    probation_end_date: string | null;
    hours_per_week: number | null;
    hourly_rate?: number | null;
    annual_salary?: number | null;
    pay_frequency?: string | null;
    primary_site_id: number | null;
    emergency_contacts: Array<{
        name: string;
        phone: string;
        relationship: string;
    }>;
    notes: string | null;
    user: { id: number; name: string; email: string };
}

interface Props {
    profile: Profile;
    sites: Array<{ id: number; name: string }>;
    departments: Array<{ id: number; name: string }>;
    employmentTypes: Array<{ value: string; label: string }>;
    contractTypes: Array<{ value: string; label: string }>;
    payFrequencies: Array<{ value: string; label: string }>;
    workRightsStatuses: Array<{ value: string; label: string }>;
}

export default function EmployeeEdit({
    profile,
    sites,
    departments,
    employmentTypes,
    contractTypes,
    payFrequencies,
    workRightsStatuses,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'HR', href: '/hr/people' },
        { title: 'People', href: '/hr/people' },
        { title: profile.user.name, href: `/hr/people/${profile.id}` },
        { title: 'Edit', href: `/hr/people/${profile.id}/edit` },
    ];
    const canEditFinancial = 'hourly_rate' in profile;

    const form = useForm({
        employee_number: profile.employee_number || '',
        position_title: profile.position_title || '',
        employment_type: profile.employment_type || '',
        contract_type: profile.contract_type || '',
        department_id: profile.department_id
            ? String(profile.department_id)
            : '',
        team: profile.team || '',
        is_active: profile.is_active,
        start_date: profile.start_date || '',
        end_date: profile.end_date || '',
        probation_end_date: profile.probation_end_date || '',
        hours_per_week: profile.hours_per_week ?? '',
        ...(canEditFinancial
            ? {
                  hourly_rate: profile.hourly_rate ?? '',
                  pay_frequency: profile.pay_frequency || '',
              }
            : {}),
        primary_site_id: profile.primary_site_id
            ? String(profile.primary_site_id)
            : '',
        emergency_contacts:
            profile.emergency_contacts.length > 0
                ? profile.emergency_contacts
                : [{ name: '', phone: '', relationship: '' }],
        work_rights_status: profile.work_rights_status || '',
        visa_type: profile.visa_type || '',
        visa_expires_at: profile.visa_expires_at || '',
        notes: profile.notes || '',
    });
    const emergencyContact = form.data.emergency_contacts[0] ?? {
        name: '',
        phone: '',
        relationship: '',
    };
    const setEmergencyContact = (
        field: 'name' | 'phone' | 'relationship',
        value: string,
    ) =>
        form.setData('emergency_contacts', [
            { ...emergencyContact, [field]: value },
            ...form.data.emergency_contacts.slice(1),
        ]);

    const handleSubmit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(`/hr/people/${profile.id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${profile.user.name}`} />
            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        variant="compact"
                        backHref="/hr/people"
                        title={`Edit ${profile.user.name}`}
                        actions={
                            <Button variant="outline" asChild>
                                <Link href={`/hr/people/${profile.id}`}>
                                    Cancel
                                </Link>
                            </Button>
                        }
                    />
                }
            >
                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Personal Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Personal Information</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label>Name</Label>
                                    <Input
                                        value={profile.user.name}
                                        disabled
                                        className="bg-muted"
                                    />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Name is managed in user settings
                                    </p>
                                </div>
                                <div>
                                    <Label>Email</Label>
                                    <Input
                                        value={profile.user.email}
                                        disabled
                                        className="bg-muted"
                                    />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Email is managed in user settings
                                    </p>
                                </div>
                                <div>
                                    <Label htmlFor="employee_number">
                                        Employee Number
                                    </Label>
                                    <Input
                                        id="employee_number"
                                        value={form.data.employee_number}
                                        onChange={(e) =>
                                            form.setData(
                                                'employee_number',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.employee_number && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.employee_number}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Work Rights</Label>
                                    <Select
                                        value={
                                            form.data.work_rights_status ||
                                            '__none__'
                                        }
                                        onValueChange={(v) =>
                                            form.setData(
                                                'work_rights_status',
                                                v === '__none__' ? '' : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select work rights" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
                                                Not recorded
                                            </SelectItem>
                                            {workRightsStatuses.map((s) => (
                                                <SelectItem
                                                    key={s.value}
                                                    value={s.value}
                                                >
                                                    {s.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.work_rights_status && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.work_rights_status}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="visa_type">Visa Type</Label>
                                    <Input
                                        id="visa_type"
                                        value={form.data.visa_type}
                                        placeholder="e.g. Accredited Employer Work Visa"
                                        onChange={(e) =>
                                            form.setData(
                                                'visa_type',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.visa_type && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.visa_type}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="visa_expires_at">
                                        Visa Expiry
                                    </Label>
                                    <Input
                                        id="visa_expires_at"
                                        type="date"
                                        value={form.data.visa_expires_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'visa_expires_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Expiry reminders are sent at 90, 60, 30,
                                        14 and 7 days
                                    </p>
                                    {form.errors.visa_expires_at && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.visa_expires_at}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Employment Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Employment Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <Label htmlFor="position_title">
                                        Position Title
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
                                    />
                                    {form.errors.position_title && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.position_title}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Employment Type</Label>
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
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
                                                Select type
                                            </SelectItem>
                                            {employmentTypes.map((t) => (
                                                <SelectItem
                                                    key={t.value}
                                                    value={t.value}
                                                >
                                                    {t.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.employment_type && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.employment_type}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Contract Type</Label>
                                    <Select
                                        value={
                                            form.data.contract_type ||
                                            '__none__'
                                        }
                                        onValueChange={(v) =>
                                            form.setData(
                                                'contract_type',
                                                v === '__none__' ? '' : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select contract type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
                                                Select contract type
                                            </SelectItem>
                                            {contractTypes.map((t) => (
                                                <SelectItem
                                                    key={t.value}
                                                    value={t.value}
                                                >
                                                    {t.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.contract_type && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.contract_type}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label>Department</Label>
                                    <Select
                                        value={
                                            form.data.department_id ||
                                            '__none__'
                                        }
                                        onValueChange={(v) =>
                                            form.setData(
                                                'department_id',
                                                v === '__none__' ? '' : v,
                                            )
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select department" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="__none__">
                                                No department
                                            </SelectItem>
                                            {departments.map((d) => (
                                                <SelectItem
                                                    key={d.id}
                                                    value={String(d.id)}
                                                >
                                                    {d.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.department_id && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.department_id}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="hours_per_week">
                                        Hours Per Week
                                    </Label>
                                    <Input
                                        id="hours_per_week"
                                        type="number"
                                        step="0.5"
                                        value={form.data.hours_per_week}
                                        onChange={(e) =>
                                            form.setData(
                                                'hours_per_week',
                                                e.target.value
                                                    ? Number(e.target.value)
                                                    : '',
                                            )
                                        }
                                    />
                                    {form.errors.hours_per_week && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.hours_per_week}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="start_date">
                                        Start Date
                                    </Label>
                                    <Input
                                        id="start_date"
                                        type="date"
                                        value={form.data.start_date}
                                        onChange={(e) =>
                                            form.setData(
                                                'start_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.start_date && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.start_date}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="team">Team</Label>
                                    <Input
                                        id="team"
                                        value={form.data.team}
                                        onChange={(e) =>
                                            form.setData('team', e.target.value)
                                        }
                                        placeholder="e.g. Community Support"
                                        maxLength={255}
                                    />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Used by Calendar filters and team
                                        audiences. Clear this field to remove
                                        the assignment.
                                    </p>
                                    {form.errors.team && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.team}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="end_date">End Date</Label>
                                    <Input
                                        id="end_date"
                                        type="date"
                                        value={form.data.end_date}
                                        onChange={(e) =>
                                            form.setData(
                                                'end_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.end_date && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.end_date}
                                        </p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="probation_end_date">
                                        Probation End Date
                                    </Label>
                                    <Input
                                        id="probation_end_date"
                                        type="date"
                                        value={form.data.probation_end_date}
                                        onChange={(e) =>
                                            form.setData(
                                                'probation_end_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.probation_end_date && (
                                        <p className="mt-1 text-sm text-destructive">
                                            {form.errors.probation_end_date}
                                        </p>
                                    )}
                                </div>
                                <div className="flex items-end gap-3">
                                    <div className="flex items-center gap-2">
                                        <input
                                            type="checkbox"
                                            id="is_active"
                                            checked={form.data.is_active}
                                            onChange={(e) =>
                                                form.setData(
                                                    'is_active',
                                                    e.target.checked,
                                                )
                                            }
                                            className="h-4 w-4 rounded border-border"
                                        />
                                        <Label htmlFor="is_active">
                                            Active Employee
                                        </Label>
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Financial */}
                    {canEditFinancial && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Financial</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor="hourly_rate">
                                            Pay Rate ($)
                                        </Label>
                                        <Input
                                            id="hourly_rate"
                                            type="number"
                                            step="0.01"
                                            value={form.data.hourly_rate}
                                            onChange={(e) =>
                                                form.setData(
                                                    'hourly_rate',
                                                    e.target.value
                                                        ? Number(e.target.value)
                                                        : '',
                                                )
                                            }
                                        />
                                        {form.errors.hourly_rate && (
                                            <p className="mt-1 text-sm text-destructive">
                                                {form.errors.hourly_rate}
                                            </p>
                                        )}
                                    </div>
                                    <div>
                                        <Label>Pay Frequency</Label>
                                        <Select
                                            value={
                                                form.data.pay_frequency ||
                                                '__none__'
                                            }
                                            onValueChange={(v) =>
                                                form.setData(
                                                    'pay_frequency',
                                                    v === '__none__' ? '' : v,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select frequency" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="__none__">
                                                    Select frequency
                                                </SelectItem>
                                                {payFrequencies.map((f) => (
                                                    <SelectItem
                                                        key={f.value}
                                                        value={f.value}
                                                    >
                                                        {f.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.errors.pay_frequency && (
                                            <p className="mt-1 text-sm text-destructive">
                                                {form.errors.pay_frequency}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    {/* Site Assignment */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Site Assignment</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="max-w-sm">
                                <Label>Primary Site</Label>
                                <Select
                                    value={
                                        form.data.primary_site_id || '__none__'
                                    }
                                    onValueChange={(v) =>
                                        form.setData(
                                            'primary_site_id',
                                            v === '__none__' ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select site" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="__none__">
                                            No site assigned
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
                                    <p className="mt-1 text-sm text-destructive">
                                        {form.errors.primary_site_id}
                                    </p>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Emergency Contact */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Emergency Contact</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-3">
                                <div>
                                    <Label htmlFor="emergency_contact_name">
                                        Name
                                    </Label>
                                    <Input
                                        id="emergency_contact_name"
                                        value={emergencyContact.name}
                                        onChange={(e) =>
                                            setEmergencyContact(
                                                'name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="emergency_contact_phone">
                                        Phone
                                    </Label>
                                    <Input
                                        id="emergency_contact_phone"
                                        value={emergencyContact.phone}
                                        onChange={(e) =>
                                            setEmergencyContact(
                                                'phone',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div>
                                    <Label htmlFor="emergency_contact_relationship">
                                        Relationship
                                    </Label>
                                    <Input
                                        id="emergency_contact_relationship"
                                        value={emergencyContact.relationship}
                                        onChange={(e) =>
                                            setEmergencyContact(
                                                'relationship',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                            </div>
                            {form.errors.emergency_contacts && (
                                <p className="text-sm text-destructive">
                                    {form.errors.emergency_contacts}
                                </p>
                            )}
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
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                rows={4}
                                placeholder="Internal notes about this employee..."
                            />
                            {form.errors.notes && (
                                <p className="mt-1 text-sm text-destructive">
                                    {form.errors.notes}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Saving...' : 'Save Changes'}
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href={`/hr/people/${profile.id}`}>
                                Cancel
                            </Link>
                        </Button>
                    </div>
                </form>
            </PageLayout>
        </AppLayout>
    );
}
