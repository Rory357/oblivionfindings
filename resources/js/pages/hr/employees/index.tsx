import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BreadcrumbItem } from '@/types';
import { LaravelPagination } from '@/components/ui/laravel-pagination';

interface Props {
    profiles: {
        data: Array<{
            id: number;
            profile_id: number | null;
            employee_number: string | null;
            position_title: string | null;
            employment_type: string | null;
            is_active: boolean;
            start_date: string | null;
            user: { id: number; name: string; email: string };
            primary_site: { id: number; name: string } | null;
        }>;
        links: any[];
        current_page: number;
        last_page: number;
    };
    sites: Array<{ id: number; name: string }>;
    filters: { q: string; status: string | null; site_id: string | null };
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr/people' },
    { title: 'People', href: '/hr/people' },
];

export default function EmployeesIndex({ profiles, sites, filters, can }: Props) {
    function applyFilter(key: string, value: string | null) {
        router.get('/hr/people', { ...filters, [key]: value || undefined }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="People" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">People</h1>
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        placeholder="Search by name or email..."
                        defaultValue={filters.q}
                        className="w-64"
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') applyFilter('q', (e.target as HTMLInputElement).value);
                        }}
                    />
                    <Select value={filters.status || '__none__'} onValueChange={(v) => applyFilter('status', v === '__none__' ? null : v)}>
                        <SelectTrigger className="w-40"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select value={filters.site_id || '__none__'} onValueChange={(v) => applyFilter('site_id', v === '__none__' ? null : v)}>
                        <SelectTrigger className="w-48"><SelectValue placeholder="Site" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">All Sites</SelectItem>
                            {sites.map((s) => (
                                <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <Card>
                    <CardContent className="p-0">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-muted/50">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium">Name</th>
                                    <th className="px-4 py-3 text-left font-medium">Employee #</th>
                                    <th className="px-4 py-3 text-left font-medium">Position</th>
                                    <th className="px-4 py-3 text-left font-medium">Type</th>
                                    <th className="px-4 py-3 text-left font-medium">Site</th>
                                    <th className="px-4 py-3 text-left font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {profiles.data.map((p) => (
                                    <tr key={p.id} className="hover:bg-muted/30">
                                        <td className="px-4 py-3">
                                            {p.profile_id ? (
                                                <Link href={`/hr/people/${p.profile_id}`} className="font-medium text-primary hover:underline">
                                                    {p.user.name}
                                                </Link>
                                            ) : (
                                                <span className="font-medium">{p.user.name}</span>
                                            )}
                                            <div className="text-xs text-muted-foreground">{p.user.email}</div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">{p.employee_number || '\u2014'}</td>
                                        <td className="px-4 py-3">{p.position_title || '\u2014'}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant="outline">{p.employment_type ? p.employment_type.replace('_', ' ') : '\u2014'}</Badge>
                                        </td>
                                        <td className="px-4 py-3">{p.primary_site?.name || '\u2014'}</td>
                                        <td className="px-4 py-3">
                                            <Badge variant={p.is_active ? 'default' : 'secondary'}>
                                                {p.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </td>
                                    </tr>
                                ))}
                                {profiles.data.length === 0 && (
                                    <tr><td colSpan={6} className="px-4 py-8 text-center text-muted-foreground">No employees found.</td></tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {profiles.last_page > 1 && (
                    <LaravelPagination links={profiles.links} />
                )}
            </div>
        </AppLayout>
    );
}
