import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Scale, Plus, Lock } from 'lucide-react';

type Props = {
    filters: {
        q: string;
        status: string | null;
        hold_type: string | null;
    };
    holds: any;
    stats?: {
        total: number;
        active: number;
    };
};

export default function LegalHolds({ filters, holds, stats }: Props) {
    const ANY = '__any__';
    const { auth } = usePage().props as any;
    const can = auth?.can?.privacy ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/privacy/legal-holds', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'active':
                return 'bg-status-critical-bg text-status-critical border-status-critical/30';
            case 'released':
                return 'bg-status-success-bg text-status-success border-status-success/30';
            default:
                return 'bg-muted text-foreground border-border';
        }
    };

    const getHoldTypeLabel = (type: string) => {
        const labels: Record<string, string> = {
            'litigation': 'Litigation',
            'investigation': 'Investigation',
            'regulatory': 'Regulatory',
            'audit': 'Audit',
            'other': 'Other',
        };
        return labels[type] || type;
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-GB', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        });
    };

    return (
        <AppLayout breadcrumbs={[
            { title: 'Privacy & GDPR', href: '/privacy/dashboard' },
            { title: 'Legal Holds', href: '/privacy/legal-holds' }
        ]}>
            <Head title="Legal Holds" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Legal Holds"
                    description="Manage data preservation orders for litigation and investigations"
                    icon={<Scale className="h-7 w-7 text-white" />}
                    stats={stats ? [
                        { label: 'Total Holds', value: stats.total },
                        { label: 'Active', value: stats.active },
                    ] : undefined}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <Link href="/privacy/dashboard">
                                <Button variant="outline" size="sm">Privacy Dashboard</Button>
                            </Link>
                            {can.manageLegalHolds && (
                                <Link href="/privacy/legal-holds/create">
                                    <Button size="sm">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Hold
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
                                placeholder="Search by reference or reason"
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
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="released">Released</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label className="text-xs text-muted-foreground">Hold Type</Label>
                            <Select
                                value={filters.hold_type ?? ANY}
                                onValueChange={(v) => onFilter({ hold_type: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    <SelectItem value="litigation">Litigation</SelectItem>
                                    <SelectItem value="investigation">Investigation</SelectItem>
                                    <SelectItem value="regulatory">Regulatory</SelectItem>
                                    <SelectItem value="audit">Audit</SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {holds.data.map((hold: any) => (
                        <Card key={hold.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-2 font-semibold">
                                                <Scale className="h-4 w-4 text-primary" />
                                                {hold.hold_reference}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={getStatusColor(hold.status)}>
                                                    {hold.status}
                                                </Badge>
                                                <Badge variant="outline">
                                                    {getHoldTypeLabel(hold.hold_type)}
                                                </Badge>
                                            </div>
                                            <div className="mt-2 text-sm text-muted-foreground">
                                                {hold.reason}
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Imposed: {formatDate(hold.imposed_at)}
                                                {hold.imposed_by && ` by ${hold.imposed_by.name}`}
                                                {hold.review_date && ` • Review: ${formatDate(hold.review_date)}`}
                                            </div>
                                        </div>
                                        <Link href={`/privacy/legal-holds/${hold.id}/edit`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            Manage
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!holds.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No legal holds found.
                        </div>
                    )}
                </div>

                {holds?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {holds.links.map((l: any) => (
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
