import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileCheck, AlertCircle, CheckCircle2 } from 'lucide-react';

type Props = {
    filters: {
        q: string;
        status: string | null;
        client_id: string | number | null;
        consent_type_id: string | number | null;
        expiring_soon: string | null;
    };
    consents: any;
    clients?: Array<{ id: number; first_name: string; last_name: string }>;
    consentTypes?: Array<{ id: number; name: string }>;
    stats?: {
        active: number;
        pending: number;
        expiring_soon: number;
    };
};

export default function ConsentsIndex({ filters, consents, clients = [], consentTypes = [], stats }: Props) {
    const ANY = '__any__';
    const { auth, labels } = usePage().props as any;
    const can = auth?.can?.consents ?? {};
    const clientSingular = labels?.['client.singular'] ?? 'Client';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/consents', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const getStatusColor = (status: string) => {
        switch (status) {
            case 'given': return 'bg-status-success-bg text-status-success border-status-success/30';
            case 'pending': return 'bg-status-warning-bg text-status-warning border-status-warning/30';
            case 'refused': return 'bg-status-critical-bg text-status-critical border-status-critical/30';
            case 'withdrawn': return 'bg-status-warning-bg text-status-warning border-status-warning/30';
            case 'expired': return 'bg-muted text-foreground border-border';
            default: return 'bg-muted text-foreground border-border';
        }
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Consent Management', href: '/consents' }]}>
            <Head title="Consent Management" />

            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Consent Management</h1>
                        <div className="mt-1 text-sm text-muted-foreground">
                            GDPR-compliant consent tracking with Mental Capacity Act integration
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.manage && (
                            <Link href="/consents/types" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                Consent Types
                            </Link>
                        )}
                        {can.record && (
                            <Link href="/consents/create">
                                <Button size="sm">
                                    <FileCheck className="mr-1.5 h-4 w-4" />
                                    Record Consent
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {stats && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Active Consents</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <CheckCircle2 className="h-5 w-5 text-status-success" />
                                    <div className="text-2xl font-bold">{stats.active}</div>
                                </div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Pending Review</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="text-2xl font-bold">{stats.pending}</div>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-sm font-medium text-muted-foreground">Expiring Soon</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="flex items-center gap-2">
                                    <AlertCircle className="h-5 w-5 text-status-warning" />
                                    <div className="text-2xl font-bold">{stats.expiring_soon}</div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filters</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 sm:grid-cols-5">
                        <div className="sm:col-span-2">
                            <Label className="text-xs text-muted-foreground">Search</Label>
                            <Input
                                placeholder="Search consents"
                                value={filters.q || ''}
                                onChange={(e) => onFilter({ q: e.target.value })}
                            />
                        </div>

                        {clients.length > 0 && (
                            <div>
                                <Label className="text-xs text-muted-foreground">{clientSingular}</Label>
                                <Select
                                    value={filters.client_id ? String(filters.client_id) : ANY}
                                    onValueChange={(v) => onFilter({ client_id: v === ANY ? null : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder={clientSingular} /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        {clients.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}

                        <div>
                            <Label className="text-xs text-muted-foreground">Status</Label>
                            <Select
                                value={filters.status ?? ANY}
                                onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}
                            >
                                <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ANY}>Any</SelectItem>
                                    {['pending', 'given', 'refused', 'withdrawn', 'expired', 'revoked'].map((s) => (
                                        <SelectItem key={s} value={s}>{s}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {consentTypes.length > 0 && (
                            <div>
                                <Label className="text-xs text-muted-foreground">Consent Type</Label>
                                <Select
                                    value={filters.consent_type_id ? String(filters.consent_type_id) : ANY}
                                    onValueChange={(v) => onFilter({ consent_type_id: v === ANY ? null : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        {consentTypes.map((t) => (
                                            <SelectItem key={t.id} value={String(t.id)}>{t.name}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="space-y-2">
                    {consents.data.map((consent: any) => (
                        <Card key={consent.id}>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex-1">
                                            <div className="font-semibold">
                                                {consent.consent_type.name}
                                            </div>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge className={getStatusColor(consent.status)}>
                                                    {consent.status}
                                                </Badge>
                                                {consent.capacity_assessed && (
                                                    <Badge variant="outline" className="border-status-info/30 bg-status-info-bg text-status-info">
                                                        Capacity: {consent.capacity_outcome?.replace(/_/g, ' ')}
                                                    </Badge>
                                                )}
                                                {consent.best_interests_decision && (
                                                    <Badge variant="outline" className="border-primary bg-primary/10 text-primary">
                                                        Best Interests Decision
                                                    </Badge>
                                                )}
                                                {consent.is_expiring_soon && (
                                                    <Badge variant="outline" className="border-status-warning/30 bg-status-warning-bg text-status-warning">
                                                        Expiring Soon
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-2 text-xs text-muted-foreground">
                                                Client: {consent.client.first_name} {consent.client.last_name}
                                                {consent.obtained_at && ` • Obtained: ${new Date(consent.obtained_at).toLocaleDateString()}`}
                                                {consent.expires_at && ` • Expires: ${new Date(consent.expires_at).toLocaleDateString()}`}
                                                {consent.obtained_by && ` • By: ${consent.obtained_by.first_name} ${consent.obtained_by.last_name}`}
                                            </div>
                                        </div>
                                        <Link href={`/consents/${consent.id}`} className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                            View
                                        </Link>
                                    </div>
                                </CardTitle>
                            </CardHeader>
                        </Card>
                    ))}
                    {!consents.data.length && (
                        <div className="py-8 text-center text-sm text-muted-foreground">
                            No consent records found.
                        </div>
                    )}
                </div>

                {consents?.links?.length ? (
                    <div className="flex flex-wrap gap-2">
                        {consents.links.map((l: any) => (
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
