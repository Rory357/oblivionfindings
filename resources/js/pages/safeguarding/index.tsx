import AppLayout from '@/layouts/app-layout';
import { PageHero } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { formatDateTimeLong } from '@/lib/datetime';
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
    critical: { bg: 'bg-status-critical-bg', text: 'text-status-critical', border: 'border-l-red-600', darkBg: '', darkText: 'dark:text-status-critical' },
    high: { bg: 'bg-status-warning-bg', text: 'text-status-warning', border: 'border-l-orange-500', darkBg: '', darkText: 'dark:text-status-warning' },
    medium: { bg: 'bg-status-warning-bg', text: 'text-status-warning', border: 'border-l-amber-500', darkBg: '', darkText: 'dark:text-status-warning' },
    low: { bg: 'bg-status-info-bg', text: 'text-status-info', border: 'border-l-blue-500', darkBg: '', darkText: 'dark:text-status-info' },
};

const statusConfig: Record<string, { bg: string; text: string; icon: typeof Clock; darkBg: string; darkText: string }> = {
    open: { bg: 'bg-status-info-bg', text: 'text-status-info', icon: Clock, darkBg: '', darkText: 'dark:text-status-info' },
    triaged: { bg: 'bg-status-info-bg', text: 'text-status-info', icon: Eye, darkBg: '', darkText: 'dark:text-status-info' },
    investigating: { bg: 'bg-primary/10', text: 'text-primary', icon: Search, darkBg: 'dark:bg-primary/10', darkText: 'dark:text-primary/70' },
    action_plan: { bg: 'bg-status-warning-bg', text: 'text-status-warning', icon: FileEdit, darkBg: '', darkText: 'dark:text-status-warning' },
    monitoring: { bg: 'bg-status-warning-bg', text: 'text-status-warning', icon: Eye, darkBg: '', darkText: 'dark:text-status-warning' },
    closed: { bg: 'bg-status-success-bg', text: 'text-status-success', icon: CheckCircle2, darkBg: '', darkText: 'dark:text-status-success' },
    referred_external: { bg: 'bg-primary/10', text: 'text-primary', icon: ExternalLink, darkBg: 'dark:bg-primary/10', darkText: 'dark:text-primary/70' },
    no_action_required: { bg: 'bg-muted', text: 'text-foreground', icon: CheckCircle2, darkBg: 'dark:bg-muted-foreground/80/10', darkText: 'dark:text-muted-foreground' },
    reported: { bg: 'bg-muted', text: 'text-foreground', icon: Clock, darkBg: 'dark:bg-muted-foreground/80/10', darkText: 'dark:text-muted-foreground' },
};

/* ------------------------------------------------------------------ */
/*  Stat Card                                                          */
/* ------------------------------------------------------------------ */

const STAT_COLORS = {
    blue: { bg: 'bg-status-info-bg', icon: 'text-status-info dark:text-status-info', ring: 'ring-status-info dark:ring-status-info/20' },
    red: { bg: 'bg-status-critical-bg', icon: 'text-status-critical dark:text-status-critical', ring: 'ring-status-critical dark:ring-status-critical/20' },
    amber: { bg: 'bg-status-warning-bg', icon: 'text-status-warning dark:text-status-warning', ring: 'ring-status-warning dark:ring-status-warning/20' },
    emerald: { bg: 'bg-status-success-bg', icon: 'text-status-success dark:text-status-success', ring: 'ring-status-success dark:ring-status-success/20' },
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
                {/* Hero Header */}
                <PageHero
                    title="Safeguarding Concerns"
                    description="Manage safeguarding concerns, investigations, and external referrals"
                    icon={<Shield className="h-7 w-7 text-white" />}
                    stats={stats ? [
                        { label: 'Open', value: stats.open },
                        { label: 'Critical', value: stats.critical },
                        { label: 'Referral', value: stats.requiring_referral },
                        { label: 'Assigned to Me', value: stats.assigned_to_me },
                    ] : undefined}
                    actions={
                        can.create ? (
                            <Link href="/safeguarding/create">
                                <Button size="sm">
                                    <Plus className="mr-1.5 h-4 w-4" />
                                    New Concern
                                </Button>
                            </Link>
                        ) : undefined
                    }
                />

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
                                                            <Badge variant="outline" className="border-primary bg-primary/10 text-primary dark:border-primary/30 dark:bg-primary/10 dark:text-primary/70 text-[10px]">
                                                                External Referral
                                                            </Badge>
                                                        )}
                                                        {concern.subject_informed === false && (
                                                            <Badge variant="outline" className="border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning text-[10px]">
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
                                                            <span className="flex items-center gap-1"><Calendar className="h-3 w-3" />{formatDateTimeLong(concern.occurred_at)}</span>
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
