import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';

type StaffUser = {
    id: number;
    name: string;
    email: string;
    role?: string | null;
    roles?: { id: number; name: string; label: string }[];
    staff_profile?: {
        phone?: string | null;
        job_title?: string | null;
        is_active?: boolean;
    } | null;
    assigned_clients_count?: number;
};

type Props = {
    users: {
        data: StaffUser[];
        links: any[];
        meta: any;
    };
    filters: { q?: string };
};

export default function StaffIndex({ users, filters }: Props) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    return (
        <AppLayout breadcrumbs={[{ title: 'Staff', href: '/staff' }]}>
            <Head title="Staff" />

            <div className="space-y-4 p-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="text-lg font-semibold">Staff</div>
                        <div className="text-sm text-muted-foreground">
                            Manage staff profiles, assignments and access.
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Input
                            defaultValue={filters?.q ?? ''}
                            placeholder="Search name or email…"
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    const value = (e.target as HTMLInputElement)
                                        .value;
                                    router.get(
                                        '/staff',
                                        { q: value },
                                        { preserveState: true, replace: true },
                                    );
                                }
                            }}
                            className="w-64"
                        />
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.get(
                                    '/staff',
                                    {},
                                    { preserveState: true, replace: true },
                                )
                            }
                        >
                            Clear
                        </Button>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="p-3 text-left font-medium">
                                    Name
                                </th>
                                <th className="p-3 text-left font-medium">
                                    Email
                                </th>
                                <th className="p-3 text-left font-medium">
                                    Role(s)
                                </th>
                                <th className="p-3 text-left font-medium">
                                    Assigned Clients
                                </th>
                                <th className="p-3 text-right font-medium">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.map((u) => (
                                <tr key={u.id} className="border-t">
                                    <td className="p-3">
                                        <div className="font-medium">
                                            {u.name}
                                        </div>
                                        {u.staff_profile?.job_title ? (
                                            <div className="text-xs text-muted-foreground">
                                                {u.staff_profile.job_title}
                                            </div>
                                        ) : null}
                                    </td>
                                    <td className="p-3">{u.email}</td>
                                    <td className="p-3">
                                        {u.roles?.length
                                            ? u.roles
                                                  .map((r) => r.label)
                                                  .join(', ')
                                            : (u.role ?? '—')}
                                    </td>
                                    <td className="p-3">
                                        {u.assigned_clients_count ?? 0}
                                    </td>
                                    <td className="p-3">
                                        <div className="flex justify-end gap-2">
                                            <Link
                                                href={`/staff/${u.id}`}
                                                className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                            >
                                                View
                                            </Link>
                                            {can?.staff?.update ? (
                                                <Link
                                                    href={`/staff/${u.id}/edit`}
                                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                                >
                                                    Edit
                                                </Link>
                                            ) : null}
                                            {can?.staff?.assignmentsUpdate ? (
                                                <Link
                                                    href={`/staff/${u.id}/assignments`}
                                                    className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                                >
                                                    Assignments
                                                </Link>
                                            ) : null}
                                        </div>
                                    </td>
                                </tr>
                            ))}

                            {users.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        No staff found.
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
