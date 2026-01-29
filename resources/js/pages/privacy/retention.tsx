import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Database, Plus, Clock } from 'lucide-react';

type Props = {
    filters: {
        q: string;
        active: string | null;
    };
    policies: any;
    stats?: {
        total: number;
        active: number;
    };
};

export default function DataRetentionPolicies({ filters, policies, stats }: Props) {
    const ANY = '__any__';
    const { auth } = usePage().props as any;
    const can = auth?.can?.privacy ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/privacy/retention', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Data Retention Policies', href: '/privacy/retention' }
        ]}>
            <Head title="Data Retention Policies" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Data Retention Policies</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Manage data retention periods and automated deletion rules
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link href="/privacy/dashboard" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Privacy Dashboard
                        </Link>
                        <Link href="/privacy/retention/review" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                            Review Data
                        </Link>
                        {can.manageRetention && (
                            <Link href="/privacy/retention/create">
                                <Button size="sm">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Policy
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Total Policies</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.total}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Active Policies</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold text-green-600">{stats.active}</div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Search by name or model type"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.active ?? ANY}
                                onValueChange={(v) => onFilter({ active: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="1">Active</SelectItem>
                                    <SelectItem value="0">Inactive</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {policies.data.map((policy: any) => (
                        <Card key={policy.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 font-semibold">
                                                <Database className="h-4 w-4 text-purple-500" />
                                                {policy.policy_name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge variant={policy.active ? 'default' : 'secondary'}>
                                                    {policy.active ? 'Active' : 'Inactive'}
                                                </Badge>
                                                <Badge variant="outline">
                                                    {policy.model_type}
                                                </Badge>
                                                <Badge variant="outline" className="border-blue-200 bg-blue-50 text-blue-700">
                                                    <Clock className="mr-1 h-3 w-3" />
                                                    {policy.retention_period_years} year{policy.retention_period_years !== 1 ? 's' : ''} retention
                                                </Badge>
                                                {policy.legal_hold_exemption && (
                                                    <Badge variant="outline" className="border-orange-200 bg-orange-50 text-orange-700">
                                                        Legal Hold Exempt
                                                    </Badge>
                                                )}
                                            </div>
                                            {policy.description && (
                                                <div className="mt-2 text-sm text-slate-600">
                                                    {policy.description}
                                                </div>
                                            )}
                                            <div className="mt-2 text-xs text-slate-500">
                                                {policy.archive_after_years && `Archive after ${policy.archive_after_years} years • `}
                                                {policy.hard_delete_after_years && `Delete after ${policy.hard_delete_after_years} years`}
                                            </div>
                                        </div>
                                        <Link href={`/privacy/retention/${policy.id}/edit`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            Edit
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!policies.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">
                            No retention policies found.
                        </div>
                    )}
                </div>

                {policies?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {policies.links.map((l: any) => (
                            <button
                                key={l.label}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-2 text-xs ${l.active ? 'bg-muted' : 'hover:bg-muted'}`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                ) : null}
            </div>
        </AppLayout>
    );
}
