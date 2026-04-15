import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Shield,
    AlertCircle,
    Calendar,
    CheckCircle2,
    Clock,
    Copy,
    Eye,
    ExternalLink,
    FileEdit,
    MapPin,
    MoreVertical,
    Plus,
    Search,
    User,
    X,
    UserCheck,
} from 'lucide-react';

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
        critical: number;
        requiring_referral: number;
        assigned_to_me: number;
    };
};

const severityConfig: Record<string, { bg: string; text: string; border: string; darkBg: string; darkText: string }> = {
    critical: { bg: 'bg-red-100', text: 'text-red-800', border: 'border-l-red-600', darkBg: 'dark:bg-red-500/15', darkText: 'dark:text-red-200' },
    high: { bg: 'bg-orange-50', text: 'text-orange-700', border: 'border-l-orange-500', darkBg: 'dark:bg-orange-500/10', darkText: 'dark:text-orange-300' },
    medium: { bg: 'bg-amber-50', text: 'text-amber-700', border: 'border-l-amber-500', darkBg: 'dark:bg-amber-500/10', darkText: 'dark:text-amber-300' },
    low: { bg: 'bg-blue-50', text: 'text-blue-700', border: 'border-l-blue-500', darkBg: 'dark:bg-blue-500/10', darkText: 'dark:text-blue-300' },
};

const statusConfig: Record<string, { bg: string; text: string; icon: typeof Clock; darkBg: string; darkText: string }> = {
    open: { bg: 'bg-blue-100', text: 'text-blue-700', icon: Clock, darkBg: 'dark:bg-blue-500/10', darkText: 'dark:text-blue-300' },
    triaged: { bg: 'bg-sky-100', text: 'text-sky-700', icon: Eye, darkBg: 'dark:bg-sky-500/10', darkText: 'dark:text-sky-300' },
    investigating: { bg: 'bg-purple-100', text: 'text-purple-700', icon: Search, darkBg: 'dark:bg-purple-500/10', darkText: 'dark:text-purple-300' },
    action_plan: { bg: 'bg-amber-100', text: 'text-amber-700', icon: FileEdit, darkBg: 'dark:bg-amber-500/10', darkText: 'dark:text-amber-300' },
    monitoring: { bg: 'bg-yellow-100', text: 'text-yellow-700', icon: Eye, darkBg: 'dark:bg-yellow-500/10', darkText: 'dark:text-yellow-300' },
    closed: { bg: 'bg-green-100', text: 'text-green-700', icon: CheckCircle2, darkBg: 'dark:bg-green-500/10', darkText: 'dark:text-green-300' },
    referred_external: { bg: 'bg-indigo-100', text: 'text-indigo-700', icon: ExternalLink, darkBg: 'dark:bg-indigo-500/10', darkText: 'dark:text-indigo-300' },
    no_action_required: { bg: 'bg-slate-100', text: 'text-slate-700', icon: CheckCircle2, darkBg: 'dark:bg-slate-500/10', darkText: 'dark:text-slate-300' },
    reported: { bg: 'bg-slate-100', text: 'text-slate-700', icon: Clock, darkBg: 'dark:bg-slate-500/10', darkText: 'dark:text-slate-300' },
};

/* ------------------------------------------------------------------ */
/*  Stat Card                                                          */
/* ------------------------------------------------------------------ */

const STAT_COLORS = {
    blue: { bg: 'bg-blue-50 dark:bg-blue-500/10', icon: 'text-blue-600 dark:text-blue-400', ring: 'ring-blue-100 dark:ring-blue-500/20' },
    red: { bg: 'bg-red-50 dark:bg-red-500/10', icon: 'text-red-600 dark:text-red-400', ring: 'ring-red-100 dark:ring-red-500/20' },
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

export default function SafeguardingIndex({ filters, concerns, stats }: Props) {
    const ANY = '__any__';
    const { auth } = usePage().props as any;
    const can = auth?.can?.safeguarding ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/safeguarding', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    function clearFilters() {
        router.get('/safeguarding', {}, { preserveState: true, replace: true });
    }

    const hasFilters = !!(filters.q || filters.status || filters.severity || filters.concern_type || filters.requires_external_referral || filters.from || filters.to);
    const data = concerns?.data ?? [];

    return (
        <AppLayout breadcrumbs={[{ title: 'Safeguarding', href: '/safeguarding' }]}>
            <Head title="Safeguarding Concerns" />

            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Safeguarding Concerns</h1>
                        <p className="text-sm text-muted-foreground">
                            Manage safeguarding concerns, investigations, and external referrals
                        </p>
                    </div>
                    {can.create && (
                        <Link href="/safeguarding/create">
                            <Button size="sm">
                                <Plus className="mr-1.5 h-4 w-4" />
                                New Concern
                            </Button>
                        </Link>
                    )}
                </div>

                {/* Stats */}
                {stats && (
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        <StatCard label="Open Concerns" value={stats.open} icon={Shield} color="blue" />
                        <StatCard label="Critical" value={stats.critical} icon={AlertCircle} color="red" />
                        <StatCard label="Requiring Referral" value={stats.requiring_referral} icon={ExternalLink} color="amber" />
                        <StatCard label="Assigned to Me" value={stats.assigned_to_me} icon={UserCheck} color="emerald" />
                    </div>
                )}

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search concerns..."
                            className="w-64 pl-9"
                            defaultValue={filters.q}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') onFilter({ q: (e.target as HTMLInputElement).value });
                            }}
                        />
                    </div>

                    <Select value={filters.status ?? ANY} onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            {['open', 'triaged', 'investigating', 'action_plan', 'monitoring', 'closed', 'referred_external', 'no_action_required', 'reported'].map((s) => (
                                <SelectItem key={s} value={s} className="capitalize">{s.replace(/_/g, ' ')}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.severity ?? ANY} onValueChange={(v) => onFilter({ severity: v === ANY ? null : v })}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Severity" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Severity</SelectItem>
                            {['low', 'medium', 'high', 'critical'].map((s) => (
                                <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.concern_type ?? ANY} onValueChange={(v) => onFilter({ concern_type: v === ANY ? null : v })}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
                            {[
                                { value: 'concern', label: 'Concern' },
                                { value: 'allegation', label: 'Allegation' },
                                { value: 'disclosure', label: 'Disclosure' },
                                { value: 'observation', label: 'Observation' },
                                { value: 'third_party_report', label: 'Third Party Report' },
                            ].map((t) => (
                                <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.requires_external_referral ?? ANY} onValueChange={(v) => onFilter({ requires_external_referral: v === ANY ? null : v })}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Referral" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All</SelectItem>
                            <SelectItem value="yes">Referred</SelectItem>
                            <SelectItem value="no">Not Referred</SelectItem>
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={clearFilters} className="gap-1.5 text-muted-foreground">
                            <X className="h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Concern list */}
                <Card>
                    <CardContent className="p-0">
                        <div className="divide-y">
                            {data.map((concern: any) => {
                                const sev = severityConfig[concern.severity] ?? severityConfig.low;
                                const stat = statusConfig[concern.status] ?? statusConfig.open;
                                const StatusIcon = stat.icon;
                                const subjectName = concern.subject
                                    ? `${concern.subject.first_name ?? ''} ${concern.subject.last_name ?? ''}`.trim()
                                    : concern.other_subject_name ?? null;
                                const preview = concern.description
                                    ? (concern.description.length > 120 ? concern.description.slice(0, 120) + '...' : concern.description)
                                    : null;

                                return (
                                    <div
                                        key={concern.id}
                                        className={`group relative cursor-pointer border-l-4 transition-colors hover:bg-muted/40 ${sev.border}`}
                                        onClick={() => router.visit(`/safeguarding/${concern.id}`)}
                                    >
                                        <div className="block px-4 py-3 pr-12">
                                            <div className="flex items-start gap-4">
                                                <div className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${sev.bg} ${sev.darkBg}`}>
                                                    <Shield className={`h-5 w-5 ${sev.text} ${sev.darkText}`} />
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-semibold">{concern.reference_number}</span>
                                                        <span className="text-muted-foreground/40">|</span>
                                                        <span className="text-sm text-muted-foreground capitalize">{concern.concern_type?.replace(/_/g, ' ')}</span>
                                                    </div>
                                                    <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                                        <Badge variant="outline" className={`${sev.bg} ${sev.text} ${sev.darkBg} ${sev.darkText} border-0 text-[10px] font-medium`}>
                                                            {concern.severity}
                                                        </Badge>
                                                        <Badge variant="outline" className={`${stat.bg} ${stat.text} ${stat.darkBg} ${stat.darkText} border-0 text-[10px] font-medium`}>
                                                            <StatusIcon className="mr-1 h-3 w-3" />
                                                            {concern.status?.replace(/_/g, ' ')}
                                                        </Badge>
                                                        {concern.requires_external_referral && (
                                                            <Badge variant="outline" className="border-purple-200 bg-purple-50 text-purple-700 dark:border-purple-500/30 dark:bg-purple-500/10 dark:text-purple-300 text-[10px]">
                                                                External Referral
                                                            </Badge>
                                                        )}
                                                        {concern.subject_informed === false && (
                                                            <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300 text-[10px]">
                                                                Subject Not Informed
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    {preview && (
                                                        <p className="mt-1 text-sm text-muted-foreground line-clamp-1">{preview}</p>
                                                    )}
                                                    <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                        {subjectName && (
                                                            <span className="flex items-center gap-1"><User className="h-3 w-3" />{subjectName}</span>
                                                        )}
                                                        {concern.occurred_at && (
                                                            <span className="flex items-center gap-1"><Calendar className="h-3 w-3" />{formatDateTime(concern.occurred_at)}</span>
                                                        )}
                                                        {concern.site?.name && (
                                                            <span className="flex items-center gap-1"><MapPin className="h-3 w-3" />{concern.site.name}</span>
                                                        )}
                                                        {concern.assigned_to?.name && (
                                                            <span className="text-muted-foreground/60">Assigned to {concern.assigned_to.name}</span>
                                                        )}
                                                        {concern.abuse_category && (
                                                            <span className="text-muted-foreground/60 capitalize">{concern.abuse_category.replace(/_/g, ' ')}</span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Three-dot menu */}
                                        <div className="absolute right-2 top-2.5 z-10" onClick={(e) => e.stopPropagation()}>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <button className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors">
                                                        <MoreVertical className="h-4 w-4" />
                                                    </button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" className="w-48">
                                                    <DropdownMenuItem onClick={() => router.visit(`/safeguarding/${concern.id}`)}>
                                                        <ExternalLink className="mr-2 h-4 w-4" />
                                                        Open concern
                                                    </DropdownMenuItem>
                                                    {can.update && (
                                                        <DropdownMenuItem onClick={() => router.visit(`/safeguarding/${concern.id}/edit`)}>
                                                            <FileEdit className="mr-2 h-4 w-4" />
                                                            Update status
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuSeparator />
                                                    {concern.subject && (
                                                        <DropdownMenuItem onClick={() => router.visit(`/operations/clients/${concern.subject.id}`)}>
                                                            <User className="mr-2 h-4 w-4" />
                                                            View subject
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuItem onClick={() => {
                                                        navigator.clipboard.writeText(`${window.location.origin}/safeguarding/${concern.id}`);
                                                    }}>
                                                        <Copy className="mr-2 h-4 w-4" />
                                                        Copy link
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </div>
                                );
                            })}

                            {!data.length && (
                                <div className="px-4 py-16 text-center">
                                    <Shield className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                    <p className="font-medium text-muted-foreground">No safeguarding concerns found</p>
                                    <p className="mt-1 text-sm text-muted-foreground/70">
                                        {hasFilters ? 'Try adjusting your filters' : 'Report a new concern to get started'}
                                    </p>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {concerns?.last_page > 1 && (
                    <LaravelPagination links={concerns.links} />
                )}
            </div>
        </AppLayout>
    );
}
