import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import RespiteSubnav from '@/components/respite-subnav';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays, Plus, Search, X, Inbox, CheckCircle2, Clock, AlertCircle } from 'lucide-react';

type Props = {
    referrals: any;
    filters: {
        status?: string | null;
        urgency?: string | null;
        q?: string | null;
    };
    stats?: {
        received: number;
        triaged: number;
        accepted: number;
    };
};

/* ------------------------------------------------------------------ */
/*  Stat Card                                                          */
/* ------------------------------------------------------------------ */

const STAT_COLORS = {
    blue: { bg: 'bg-blue-50 dark:bg-blue-500/10', icon: 'text-blue-600 dark:text-blue-400', ring: 'ring-blue-100 dark:ring-blue-500/20' },
    amber: { bg: 'bg-amber-50 dark:bg-amber-500/10', icon: 'text-amber-600 dark:text-amber-400', ring: 'ring-amber-100 dark:ring-amber-500/20' },
    emerald: { bg: 'bg-emerald-50 dark:bg-emerald-500/10', icon: 'text-emerald-600 dark:text-emerald-400', ring: 'ring-emerald-100 dark:ring-emerald-500/20' },
};

function StatCard({ label, value, icon: Icon, color }: { label: string; value: number; icon: React.ElementType; color: keyof typeof STAT_COLORS }) {
    const c = STAT_COLORS[color];
    return (
        <div className={`relative flex items-center gap-4 rounded-xl p-4 ring-1 ${c.bg} ${c.ring} transition-shadow hover:shadow-md`}>
            <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${c.bg} ${c.icon}`}>
                <Icon className="h-5 w-5" />
            </div>
            <div className="min-w-0">
                <p className="text-2xl font-bold tracking-tight">{value}</p>
                <p className="truncate text-xs font-medium text-muted-foreground">{label}</p>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function RespiteIndex({ referrals, filters, stats }: Props) {
    const { auth, labels } = usePage().props as any;
    const can = auth?.can?.respite ?? {};
    const label = labels?.['respite.plural'] ?? 'Respite';
    const ANY = '__any__';

    const onFilter = (next: Partial<Props['filters']>) => {
        router.get('/respite', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    function clearFilters() {
        router.get('/respite', {}, { preserveState: true, replace: true });
    }

    const hasFilters = !!(filters.q || filters.status || filters.urgency);
    const data = referrals?.data ?? [];

    return (
        <AppLayout breadcrumbs={[{ title: label, href: '/respite' }]}>
            <Head title={label} />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title={`${label} Referrals`}
                    description="Referrals start the intake. Booking requests are reviewed and approved before creating bookings."
                    icon={<CalendarDays className="h-7 w-7 text-white" />}
                    stats={stats ? [
                        { label: 'Received', value: stats.received },
                        { label: 'Triaged', value: stats.triaged },
                        { label: 'Accepted', value: stats.accepted },
                    ] : undefined}
                    actions={
                        can.create ? (
                            <Link href="/respite/referrals/create">
                                <Button size="sm">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Referral
                                </Button>
                            </Link>
                        ) : undefined
                    }
                />

                <RespiteSubnav />

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search referrer or reason..."
                            className="w-64 pl-9"
                            defaultValue={filters.q ?? ''}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') onFilter({ q: (e.target as HTMLInputElement).value });
                            }}
                        />
                    </div>

                    <Select value={filters.status ?? ANY} onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            {['received', 'triaged', 'accepted', 'declined'].map((s) => (
                                <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.urgency ?? ANY} onValueChange={(v) => onFilter({ urgency: v === ANY ? null : v })}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Urgency" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Urgency</SelectItem>
                            {['planned', 'urgent', 'crisis'].map((s) => (
                                <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={clearFilters} className="gap-1.5 text-muted-foreground">
                            <X className="h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Referral list */}
                <Card>
                    <CardContent className="p-0">
                        <div className="divide-y">
                            {data.map((ref: any) => (
                                <div
                                    key={ref.id}
                                    className="group cursor-pointer px-4 py-3 transition-colors hover:bg-muted/40"
                                    onClick={() => router.visit(`/respite/referrals/${ref.id}`)}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-start gap-3">
                                            <div className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 dark:bg-blue-500/10">
                                                <CalendarDays className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                                            </div>
                                            <div>
                                                <div className="font-semibold group-hover:text-primary">
                                                    {ref.client?.first_name} {ref.client?.last_name}
                                                </div>
                                                <div className="mt-1 flex flex-wrap gap-2">
                                                    <Badge variant="outline" className="text-[11px] capitalize">{ref.status}</Badge>
                                                    <Badge variant="outline" className="text-[11px] capitalize">{ref.urgency}</Badge>
                                                </div>
                                                <div className="mt-1.5 text-xs text-muted-foreground">
                                                    Referrer: {ref.referrer_name}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {!data.length && (
                                <div className="px-4 py-16 text-center">
                                    <CalendarDays className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                    <p className="font-medium text-muted-foreground">No respite referrals found</p>
                                    <p className="mt-1 text-sm text-muted-foreground/70">
                                        {hasFilters ? 'Try adjusting your filters' : 'Create a referral to get started'}
                                    </p>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {referrals?.last_page > 1 && (
                    <LaravelPagination links={referrals.links} />
                )}
            </div>
        </AppLayout>
    );
}
