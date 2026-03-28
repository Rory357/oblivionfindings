import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
    ShieldAlert,
    AlertTriangle,
    Calendar,
    CheckCircle2,
    Clock,
    Copy,
    Eye,
    ExternalLink,
    FileEdit,
    Filter,
    MapPin,
    MoreVertical,
    Plus,
    Search,
    User,
    Users,
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

const severityConfig: Record<string, { bg: string; text: string; dot: string; border: string }> = {
    critical: { bg: 'bg-red-100', text: 'text-red-800', dot: 'bg-red-600', border: 'border-l-red-600' },
    high: { bg: 'bg-orange-50', text: 'text-orange-700', dot: 'bg-orange-500', border: 'border-l-orange-500' },
    medium: { bg: 'bg-amber-50', text: 'text-amber-700', dot: 'bg-amber-500', border: 'border-l-amber-500' },
    low: { bg: 'bg-blue-50', text: 'text-blue-700', dot: 'bg-blue-500', border: 'border-l-blue-500' },
};

const statusConfig: Record<string, { bg: string; text: string; icon: typeof Clock }> = {
    open: { bg: 'bg-blue-100', text: 'text-blue-700', icon: Clock },
    triaged: { bg: 'bg-sky-100', text: 'text-sky-700', icon: Eye },
    investigating: { bg: 'bg-purple-100', text: 'text-purple-700', icon: Search },
    action_plan: { bg: 'bg-amber-100', text: 'text-amber-700', icon: FileEdit },
    monitoring: { bg: 'bg-yellow-100', text: 'text-yellow-700', icon: Eye },
    closed: { bg: 'bg-green-100', text: 'text-green-700', icon: CheckCircle2 },
    referred_external: { bg: 'bg-indigo-100', text: 'text-indigo-700', icon: ExternalLink },
    no_action_required: { bg: 'bg-slate-100', text: 'text-slate-700', icon: CheckCircle2 },
    reported: { bg: 'bg-slate-100', text: 'text-slate-700', icon: Clock },
};

export default function SafeguardingIndex({ filters, concerns, stats }: Props) {
    const ANY = '__any__';
    const { auth } = usePage().props as any;
    const can = auth?.can?.safeguarding ?? {};

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/safeguarding', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    const data = concerns?.data ?? [];

    return (
        <AppLayout breadcrumbs={[{ title: 'Safeguarding', href: '/safeguarding' }]}>
            <Head title="Safeguarding Concerns" />

            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100">
                            <Shield className="h-5 w-5 text-purple-600" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold">Safeguarding Concerns</h1>
                            <div className="text-sm text-slate-500">
                                Manage safeguarding concerns, investigations, and external referrals
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.create && (
                            <Link href="/safeguarding/create">
                                <Button size="sm">
                                    <Plus className="mr-1 h-4 w-4" />
                                    New Concern
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {/* Stats row */}
                {stats && (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div className="rounded-lg border bg-white p-3">
                            <div className="text-2xl font-bold">{stats.open}</div>
                            <div className="text-xs text-slate-500">Open Concerns</div>
                        </div>
                        <div className="rounded-lg border bg-white p-3">
                            <div className={`text-2xl font-bold ${stats.critical > 0 ? 'text-red-600' : 'text-slate-600'}`}>
                                {stats.critical}
                            </div>
                            <div className="text-xs text-slate-500">Critical</div>
                        </div>
                        <div className="rounded-lg border bg-white p-3">
                            <div className="text-2xl font-bold text-indigo-600">{stats.requiring_referral}</div>
                            <div className="text-xs text-slate-500">Requiring Referral</div>
                        </div>
                        <div className="rounded-lg border bg-white p-3">
                            <div className="text-2xl font-bold text-purple-600">{stats.assigned_to_me}</div>
                            <div className="text-xs text-slate-500">Assigned to Me</div>
                        </div>
                    </div>
                )}

                {/* Filters */}
                <Card>
                    <CardContent className="pt-4">
                        <div className="mb-3 flex items-center gap-2 text-sm font-medium text-slate-700">
                            <Filter className="h-4 w-4" />
                            Filters
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-6">
                            <div className="sm:col-span-2">
                                <Label className="text-xs text-slate-500">Search</Label>
                                <div className="relative">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                    <Input
                                        placeholder="Reference, description, name..."
                                        className="pl-9"
                                        value={filters.q || ''}
                                        onChange={(e) => onFilter({ q: e.target.value })}
                                    />
                                </div>
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
                                        {['open', 'triaged', 'investigating', 'action_plan', 'monitoring', 'closed', 'referred_external', 'no_action_required', 'reported'].map((s) => (
                                            <SelectItem key={s} value={s} className="capitalize">{s.replace(/_/g, ' ')}</SelectItem>
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
                                            <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
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

                            <div>
                                <Label className="text-xs text-slate-500">From</Label>
                                <Input type="date" value={filters.from ?? ''} onChange={(e) => onFilter({ from: e.target.value || null })} />
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">To</Label>
                                <Input type="date" value={filters.to ?? ''} onChange={(e) => onFilter({ to: e.target.value || null })} />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Concern list */}
                <div className="space-y-2">
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
                                className={`group relative cursor-pointer rounded-lg border border-l-4 bg-white transition-all hover:shadow-md ${sev.border}`}
                                onClick={() => router.visit(`/safeguarding/${concern.id}`)}
                            >
                                <div className="block px-4 py-3 pr-12">
                                    <div className="flex items-start gap-4">
                                        <div className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${sev.bg}`}>
                                            <Shield className={`h-5 w-5 ${sev.text}`} />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="font-semibold">{concern.reference_number}</span>
                                                <span className="text-slate-300">|</span>
                                                <span className="text-sm text-slate-600 capitalize">{concern.concern_type?.replace(/_/g, ' ')}</span>
                                            </div>
                                            <div className="mt-1.5 flex items-center gap-2 flex-wrap">
                                                <Badge className={`${sev.bg} ${sev.text} border-0 text-[10px] font-medium`}>
                                                    {concern.severity}
                                                </Badge>
                                                <Badge className={`${stat.bg} ${stat.text} border-0 text-[10px] font-medium`}>
                                                    <StatusIcon className="mr-1 h-3 w-3" />
                                                    {concern.status?.replace(/_/g, ' ')}
                                                </Badge>
                                                {concern.requires_external_referral && (
                                                    <Badge className="bg-purple-100 text-purple-700 border-0 text-[10px]">
                                                        External Referral
                                                    </Badge>
                                                )}
                                                {concern.subject_informed === false && (
                                                    <Badge className="bg-amber-100 text-amber-700 border-0 text-[10px]">
                                                        Subject Not Informed
                                                    </Badge>
                                                )}
                                            </div>
                                            {preview && (
                                                <p className="mt-1 text-sm text-slate-600 line-clamp-1">{preview}</p>
                                            )}
                                            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
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
                                                    <span className="text-slate-400">Assigned to {concern.assigned_to.name}</span>
                                                )}
                                                {concern.abuse_category && (
                                                    <span className="text-slate-400 capitalize">{concern.abuse_category.replace(/_/g, ' ')}</span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Three-dot menu */}
                                <div className="absolute right-2 top-2.5 z-10" onClick={(e) => e.stopPropagation()}>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <button className="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors">
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
                        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-12 text-center">
                            <Shield className="h-10 w-10 text-slate-300" />
                            <div className="mt-2 text-sm font-medium text-slate-500">No safeguarding concerns found</div>
                            <div className="text-xs text-slate-400">Try adjusting your filters or report a new concern</div>
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {concerns?.links?.length > 3 && (
                    <div className="flex flex-wrap items-center justify-center gap-1">
                        {concerns.links.map((l: any, idx: number) => (
                            <button
                                key={idx}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-1.5 text-xs transition-colors ${
                                    l.active
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : l.url
                                            ? 'hover:bg-muted'
                                            : 'opacity-50 cursor-not-allowed'
                                }`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
