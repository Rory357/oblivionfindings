import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Role = { id: number; name: string; label: string };

type Props = {
    user: any;
    roles: Role[];
};

export default function StaffEdit({ user, roles }: Props) {
    const { labels } = usePage().props as any;
    const staffLabel = labels?.['staff.singular'] ?? 'Staff';

    const form = useForm({
        name: user.name ?? '',
        email: user.email ?? '',
        role_ids: (user.roles ?? []).map((r: Role) => r.id),
        profile: {
            phone: user.staff_profile?.phone ?? '',
            job_title: user.staff_profile?.job_title ?? '',
            employment_type: user.staff_profile?.employment_type ?? '',
            start_date: user.staff_profile?.start_date ?? '',
            is_active: user.staff_profile?.is_active ?? true,
        },
    });

    return (
        <AppLayout
            breadcrumbs={[
                { title: staffLabel, href: '/staff' },
                { title: user.name, href: `/staff/${user.id}` },
                { title: 'Edit', href: `/staff/${user.id}/edit` },
            ]}
        >
            <Head title={`Edit ${staffLabel}`} />

            <div className="p-4 max-w-3xl space-y-6">
                <HeadingSmall title={`Edit ${staffLabel}`} description="Update staff profile and access." />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(`/staff/${user.id}`);
                    }}
                    className="space-y-6"
                >
                    <div className="rounded-md border p-4 space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="name">Name</Label>
                                <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="email">Email</Label>
                                <Input id="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            </div>
                        </div>

                        <div className="space-y-2">
                            <Label>Roles</Label>
                            <div className="grid gap-2 md:grid-cols-2">
                                {roles.map((r) => {
                                    const checked = form.data.role_ids.includes(r.id);
                                    return (
                                        <label key={r.id} className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={(e) => {
                                                    const next = e.target.checked
                                                        ? [...form.data.role_ids, r.id]
                                                        : form.data.role_ids.filter((id: number) => id !== r.id);
                                                    form.setData('role_ids', next);
                                                }}
                                            />
                                            {r.label}
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    </div>

                    <div className="rounded-md border p-4 space-y-4">
                        <div className="font-medium">Profile</div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="phone">Phone</Label>
                                <Input
                                    id="phone"
                                    value={form.data.profile.phone}
                                    onChange={(e) => form.setData('profile', { ...form.data.profile, phone: e.target.value })}
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="job_title">Job title</Label>
                                <Input
                                    id="job_title"
                                    value={form.data.profile.job_title}
                                    onChange={(e) => form.setData('profile', { ...form.data.profile, job_title: e.target.value })}
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="employment_type">Employment type</Label>
                                <Input
                                    id="employment_type"
                                    value={form.data.profile.employment_type}
                                    onChange={(e) => form.setData('profile', { ...form.data.profile, employment_type: e.target.value })}
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="start_date">Start date</Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    value={form.data.profile.start_date ?? ''}
                                    onChange={(e) => form.setData('profile', { ...form.data.profile, start_date: e.target.value })}
                                />
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={!!form.data.profile.is_active}
                                onChange={(e) => form.setData('profile', { ...form.data.profile, is_active: e.target.checked })}
                            />
                            <span className="text-sm">Active</span>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={form.processing}>Save</Button>
                        <Link href={`/staff/${user.id}`} className="text-sm underline">Cancel</Link>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
