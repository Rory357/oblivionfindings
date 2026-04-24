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
                return 'bg-status-info-bg text-status-info border-status-info/30';
            case 'discovered':
                return 'bg-status-warning-bg text-status-warning border-status-warning/30';
            case 'contained':
                return 'bg-status-warning-bg text-status-warning border-status-warning/30';
            case 'resolved':
                return 'bg-status-success-bg text-status-success border-status-success/30';
            case 'notified':
                return 'bg-primary/10 text-primary border-primary';
            default:
                return 'bg-muted text-foreground border-border';
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
                            <Label className="text-xs text-muted-foreground">Search</Label>
                            <Input
                                placeholder="Search by reference or description"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">Status</Label>
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
                            <Label className="text-xs text-muted-foreground">ICO Notification</Label>
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
                                                    <AlertTriangle className="h-4 w-4 text-status-critical" />
                                                )}
                                                {breach.breach_reference}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={getStatusColor(breach.status)}>
                                                    {statusLabels[breach.status] ?? breach.status}
                                                </Badge>
                                                {breach.requires_authority_notification && !breach.authority_notified_at && (
                                                    <Badge variant="outline" className="border-status-critical/30 bg-status-critical-bg text-status-critical">
                                                        <Clock className="mr-1 h-3 w-3" />
                                                        ICO notification required
                                                    </Badge>
                                                )}
                                                {breach.authority_notified_at && (
                                                    <Badge variant="outline" className="border-status-success/30 bg-status-success-bg text-status-success">
                                                        ICO notified
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-2 text-sm text-muted-foreground">
                                                {breach.nature_of_breach}
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
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
                        <div className="py-8 text-center text-sm text-muted-foreground">
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
