import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Clock, Shield, Plus } from 'lucide-react';

type Props = {
    filters: {
        q: string;
        status: string | null;
        requires_notification: string | null;
    };
    breaches: any;
    stats?: {
        total: number;
        open: number;
        requiring_notification: number;
        resolved_30_days: number;
    };
};

export default function DataBreaches({ filters, breaches, stats }: Props) {
    const ANY = '__any__';
    const statusLabels: Record<string, string> = {
        discovered: 'discovered',
        under_investigation: 'under investigation',
        contained: 'contained',
        notified: 'notified',
        resolved: 'resolved',
    };
    const { auth } = usePage().props as any;
    const can = auth?.can?.privacy ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/privacy/breaches', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'under_investigation':
                return 'bg-blue-100 text-blue-800 border-blue-200';
            case 'discovered':
                return 'bg-yellow-100 text-yellow-800 border-yellow-200';
            case 'contained':
                return 'bg-orange-100 text-orange-800 border-orange-200';
            case 'resolved':
                return 'bg-green-100 text-green-800 border-green-200';
            case 'notified':
                return 'bg-purple-100 text-purple-800 border-purple-200';
            default:
                return 'bg-slate-100 text-slate-800 border-slate-200';
        }
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Data Breaches', href: '/privacy/breaches' }
        ]}>
            <Head title="Data Breaches" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Data Breach Management"
                    description="GDPR Article 33 — 72 hour ICO notification requirement"
                    icon={<Shield className="h-7 w-7 text-white" />}
                    stats={stats ? [
                        { label: 'Total', value: stats.total },
                        { label: 'Open', value: stats.open },
                        { label: 'ICO Required', value: stats.requiring_notification },
                        { label: 'Resolved (30d)', value: stats.resolved_30_days },
                    ] : undefined}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href="/privacy/dashboard">
                                <Button variant="outline" size="sm">Privacy Dashboard</Button>
                            </Link>
                            {can.reportBreaches && (
                                <Link href="/privacy/breaches/create">
                                    <Button size="sm">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Report Breach
                                    </Button>
                                </Link>
                            )}
                        </div>
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
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
                                    {['discovered', 'under_investigation', 'contained', 'notified', 'resolved'].map((s) => (
                                        <SelectItem key={s} value={s}>{statusLabels[s]}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-slate-500">ICO Notification</Label>
                            <Select
                                value={filters.requires_notification ?? ANY}
                                onValueChange={(v) => onFilter({ requires_notification: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Notification" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="1">Pending Notification</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {breaches.data.map((breach: any) => (
                        <Card key={breach.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 font-semibold">
                                                {breach.requires_authority_notification && !breach.authority_notified_at && (
                                                    <AlertTriangle className="h-4 w-4 text-red-500" />
                                                )}
                                                {breach.breach_reference}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={getStatusColor(breach.status)}>
                                                    {statusLabels[breach.status] ?? breach.status}
                                                </Badge>
                                                {breach.requires_authority_notification && !breach.authority_notified_at && (
                                                    <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700">
                                                        <Clock className="mr-1 h-3 w-3" />
                                                        ICO notification required
                                                    </Badge>
                                                )}
                                                {breach.authority_notified_at && (
                                                    <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700">
                                                        ICO notified
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-2 text-sm text-slate-600">
                                                {breach.nature_of_breach}
                                            </div>
                                            <div className="mt-2 text-xs text-slate-500">
                                                Discovered: {formatDate(breach.discovered_at)}
                                                {breach.approximate_individuals_affected && ` • ~${breach.approximate_individuals_affected} individuals affected`}
                                            </div>
                                        </div>
                                        <Link href={`/privacy/breaches/${breach.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!breaches.data.length && (
                        <div className="py-8 text-center text-sm text-slate-500">
                            No data breaches found.
                        </div>
                    )}
                </div>

                {breaches?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {breaches.links.map((l: any) => (
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
