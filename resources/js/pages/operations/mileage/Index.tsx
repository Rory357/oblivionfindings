import { OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Car, Clock, DollarSign, Eye, MapPin, Pencil, Plus, Route, Search, Send } from 'lucide-react';

const ANY = '__ANY__';

const nzd = new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' });

type MileageClaim = {
    id: number;
    reference: string;
    status: string;
    origin: string;
    destination: string;
    distance_km: number;
    amount: number;
    claimed_at: string;
    worker: { id: number; name: string } | null;
};

type Props = {
    claims: {
        data: MileageClaim[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        status?: string;
    };
    stats: {
        total: number;
        pending_approval: number;
        total_km: number;
        total_amount: number;
    };
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    submitted: 'secondary',
    approved: 'default',
    rejected: 'destructive',
    paid: 'default',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function MileageIndex({ claims, filters, stats }: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/mileage', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Mileage Claims" />
            <PageHeader
                title="Mileage Claims"
                description="Track and manage travel distance claims for support workers."
                backHref="/operations"
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <OpsStatCard label="Total Claims" value={stats?.total ?? 0} icon={Car} color="indigo" />
                    <OpsStatCard label="Pending Approval" value={stats?.pending_approval ?? 0} icon={Clock} color="amber" />
                    <OpsStatCard label="Total KM" value={`${(stats?.total_km ?? 0).toLocaleString('en-NZ')} km`} icon={Route} color="blue" />
                    <OpsStatCard label="Total Amount" value={nzd.format(stats?.total_amount ?? 0)} icon={DollarSign} color="emerald" />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search mileage claims..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                    <Select value={filters.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[130px] text-xs">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="submitted">Submitted</SelectItem>
                            <SelectItem value="approved">Approved</SelectItem>
                            <SelectItem value="rejected">Rejected</SelectItem>
                            <SelectItem value="paid">Paid</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button asChild size="sm">
                        <Link href="/operations/mileage/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Claim
                        </Link>
                    </Button>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {claims.data.length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <Car className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Mileage Claims</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Create your first mileage claim to get started.</p>
                                <Button asChild size="sm" className="mt-4">
                                    <Link href="/operations/mileage/create">Create Claim</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                    {claims.data.map((claim) => (
                        <Card key={claim.id} className="transition-all hover:border-border hover:shadow-sm">
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                    <Car className="h-5 w-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/operations/mileage/${claim.id}`} className="text-sm font-semibold hover:underline">
                                            {claim.reference}
                                        </Link>
                                        <Badge variant={STATUS_VARIANTS[claim.status] ?? 'outline'} className="h-4 px-1.5 text-[9px] capitalize">
                                            {claim.status}
                                        </Badge>
                                        <span className="text-sm font-semibold text-emerald-700 dark:text-emerald-400">
                                            {nzd.format(claim.amount)}
                                        </span>
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        {claim.worker && <span>{claim.worker.name}</span>}
                                        <span className="flex items-center gap-1">
                                            <MapPin className="h-3 w-3" />
                                            {claim.origin} → {claim.destination}
                                        </span>
                                        <span>{claim.distance_km} km</span>
                                        <span>{formatDate(claim.claimed_at)}</span>
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    {claim.status === 'draft' && (
                                        <Button size="sm" variant="ghost" className="h-7 px-2 text-xs">
                                            <Send className="mr-1 h-3 w-3" /> Submit
                                        </Button>
                                    )}
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/mileage/${claim.id}`}>
                                            <Eye className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/mileage/${claim.id}/edit`}>
                                            <Pencil className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {claims.last_page > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {claims.links.map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
