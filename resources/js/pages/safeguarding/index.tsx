import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Shield, Clock } from 'lucide-react';

type Props = {
    filters: {
        q: string;
        status: string | null;
        severity: string | null;
        concern_type: string | null;
        requires_external_referral: string | null;
        from: string | null;
        to: string | null;
    };
    concerns: any;
    stats?: {
        open: number;
        high_priority: number;
        require_external_referral: number;
    };
};

export default function SafeguardingIndex({ filters, concerns, stats }: Props) {
    const ANY = '__any__';
    const { auth } = usePage().props as any;
    const can = auth?.can?.safeguarding ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/safeguarding', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const getSeverityColor = (severity: string) => {
        switch (severity) {
            case 'critical': return 'bg-red-100 text-red-800 border-red-200';
            case 'high': return 'bg-orange-100 text-orange-800 border-orange-200';
            case 'medium': return 'bg-yellow-100 text-yellow-800 border-yellow-200';
            case 'low': return 'bg-blue-100 text-blue-800 border-blue-200';
            default: return 'bg-slate-100 text-slate-800 border-slate-200';
        }
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'closed': return 'bg-green-100 text-green-800 border-green-200';
            case 'in_progress': return 'bg-blue-100 text-blue-800 border-blue-200';
            case 'under_investigation': return 'bg-purple-100 text-purple-800 border-purple-200';
            case 'escalated': return 'bg-red-100 text-red-800 border-red-200';
            default: return 'bg-slate-100 text-slate-800 border-slate-200';
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Safeguarding', href: '/safeguarding' }]}>
            <Head title="Safeguarding Concerns" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Safeguarding Concerns</h1>
                        <div className="mt-1 text-sm text-slate-500">
                            Manage safeguarding concerns, investigations, and external referrals
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.create && (
                            <Link href="/safeguarding/create">
                                <Button size="sm">
                                    <Shield className="mr-1.5 h-4 w-4" />
                                    New Concern
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">Open Concerns</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.open}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">High Priority</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <AlertTriangle className="h-5 w-5 text-orange-500" />
                                    <div className="text-2xl font-bold">{stats.high_priority}</div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-slate-500">External Referrals</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.require_external_referral}</div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-6">
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-slate-500">Search</Label>
                            <Input
                                placeholder="Search by reference or description"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Status</Label>
                            <Select
                                value={filters.status ?? ANY}
                                onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['open', 'in_progress', 'under_investigation', 'escalated', 'closed', 'no_action_required'].map((s) => (
                                        <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Severity</Label>
                            <Select
                                value={filters.severity ?? ANY}
                                onValueChange={(v) => onFilter({ severity: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Severity" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['low', 'medium', 'high', 'critical'].map((s) => (
                                        <SelectItem key={s} value={s}>{s}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">Concern Type</Label>
                            <Select
                                value={filters.concern_type ?? ANY}
                                onValueChange={(v) => onFilter({ concern_type: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['concern', 'allegation', 'disclosure', 'observation', 'third_party_report'].map((s) => (
                                        <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">External Referral</Label>
                            <Select
                                value={filters.requires_external_referral ?? ANY}
                                onValueChange={(v) => onFilter({ requires_external_referral: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Referral?" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="yes">Yes</SelectItem>
                                    <SelectItem value="no">No</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {concerns.data.map((concern: any) => (
                        <Card key={concern.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 font-semibold">
                                                {concern.severity === 'critical' && <AlertTriangle className="h-4 w-4 text-red-500" />}
                                                {concern.reference_number}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={getSeverityColor(concern.severity)}>
                                                    {concern.severity}
                                                </Badge>
                                                <Badge className={getStatusColor(concern.status)}>
                                                    {concern.status.replace(/_/g, ' ')}
                                                </Badge>
                                                {concern.requires_external_referral && (
                                                    <Badge variant="outline" className="border-purple-200 bg-purple-50 text-purple-700">
                                                        External Referral
                                                    </Badge>
                                                )}
                                                {concern.subject_informed === false && (
                                                    <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700">
                                                        Subject Not Informed
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                {concern.concern_type.replace(/_/g, ' ')}
                                                {concern.abuse_category && ` • ${concern.abuse_category}`}
                                                {concern.subject && ` • Subject: ${concern.subject.first_name} ${concern.subject.last_name}`}
                                                {concern.occurred_at && ` • ${new Date(concern.occurred_at).toLocaleDateString()}`}
                                            </div>
                                            {concern.immediate_action_taken && (
                                                <div className="mt-2 flex items-start gap-1.5 text-xs text-green-600">
                                                    <Shield className="mt-0.5 h-3 w-3" />
                                                    Immediate action taken
                                                </div>
                                            )}
                                        </div>
                                        <Link href={`/safeguarding/${concern.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!concerns.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">
                            No safeguarding concerns found.
                        </div>
                    )}
                </div>

                {concerns?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {concerns.links.map((l: any) => (
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
