import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage, router } from '@inertiajs/react';
import { Building2, Home, MapPin, Warehouse, AlertTriangle, AlertCircle, CheckCircle2, Plus, Search, X, Eye, Pencil, Calendar, ShieldAlert, ClipboardCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { MoreVertical } from 'lucide-react';

type Site = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility' | 'residential';
    region?: string | null;
    address_line_1?: string | null;
    address_line_2?: string | null;
    suburb?: string | null;
    city?: string | null;
    postcode?: string | null;
    country?: string | null;
    is_active: boolean;
    is_high_risk: boolean;
    is_high_needs: boolean;
    primary_contact?: { id: number; name: string } | null;
};

type PageProps = {
    sites: Site[];
    filters: {
        q?: string;
        type?: string;
        status?: string;
        region?: string;
        risk?: string;
        manager_id?: string;
    };
    filterOptions: {
        regions: string[];
        managers: { id: number; name: string }[];
        types: { value: string; label: string }[];
        risks: { value: string; label: string }[];
    };
    auth: { can?: any };
};

const typeIcons: Record<string, typeof Building2> = {
    head_office: Building2,
    house: Home,
    facility: Warehouse,
    residential: Home,
};

const typeLabels: Record<string, string> = {
    head_office: 'Head Office',
    house: 'House',
    facility: 'Facilities',
    residential: 'Residential',
};

const typeColors: Record<string, string> = {
    head_office: 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-300',
    house: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
    facility: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
    residential: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/30 dark:bg-violet-500/10 dark:text-violet-300',
};

function addressFor(site: Site): string {
    const parts = [
        site.address_line_1,
        site.address_line_2,
        site.suburb,
        site.city,
        site.postcode,
    ].filter((v): v is string => typeof v === 'string' && v.trim() !== '');
    return parts.join(', ');
}

function RiskBadge({ site }: { site: Site }) {
    if (site.is_high_risk && site.is_high_needs) {
        return (
            <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300">
                <AlertTriangle className="mr-1 h-3 w-3" />
                High Risk + Needs
            </Badge>
        );
    }
    if (site.is_high_risk) {
        return (
            <Badge variant="outline" className="border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-300">
                <AlertCircle className="mr-1 h-3 w-3" />
                High Risk
            </Badge>
        );
    }
    if (site.is_high_needs) {
        return (
            <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                <AlertCircle className="mr-1 h-3 w-3" />
                High Needs
            </Badge>
        );
    }
    return (
        <Badge variant="outline" className="border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-500/30 dark:bg-slate-500/10 dark:text-slate-400">
            <CheckCircle2 className="mr-1 h-3 w-3" />
            Standard
        </Badge>
    );
}

/* ------------------------------------------------------------------ */
/*  Stat Card                                                          */
/* ------------------------------------------------------------------ */

const STAT_COLORS = {
    blue: { bg: 'bg-blue-50 dark:bg-blue-500/10', icon: 'text-blue-600 dark:text-blue-400', ring: 'ring-blue-100 dark:ring-blue-500/20' },
    emerald: { bg: 'bg-emerald-50 dark:bg-emerald-500/10', icon: 'text-emerald-600 dark:text-emerald-400', ring: 'ring-emerald-100 dark:ring-emerald-500/20' },
    amber: { bg: 'bg-amber-50 dark:bg-amber-500/10', icon: 'text-amber-600 dark:text-amber-400', ring: 'ring-amber-100 dark:ring-amber-500/20' },
    red: { bg: 'bg-red-50 dark:bg-red-500/10', icon: 'text-red-600 dark:text-red-400', ring: 'ring-red-100 dark:ring-red-500/20' },
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

export default function SitesIndex({ sites }: { sites: Site[] }) {
    const { auth, filters, filterOptions, labels } = usePage<PageProps & { labels?: Record<string, string> }>().props;
    const can = auth?.can ?? {};
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const sitePlural = labels?.['site.plural'] ?? 'Sites';

    const updateFilter = (key: string, value: string | null) => {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') {
            delete newFilters[key as keyof typeof newFilters];
        }
        router.get('/sites', newFilters, { preserveState: true, replace: true });
    };

    const hasFilters = !!(filters.type || filters.status || filters.region || filters.risk || filters.manager_id || filters.q);

    const activeSites = sites.filter((s) => s.is_active).length;
    const highRiskSites = sites.filter((s) => s.is_high_risk).length;

    return (
        <AppLayout breadcrumbs={[{ title: sitePlural, href: '/sites' }]}>
            <Head title={sitePlural} />

            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">{sitePlural}</h1>
                        <p className="text-sm text-muted-foreground">
                            Manage locations and facilities &mdash; {sites.length} {sites.length === 1 ? siteSingular.toLowerCase() : sitePlural.toLowerCase()} total
                        </p>
                    </div>
                    {can?.sites?.create && (
                        <Button size="sm" asChild>
                            <Link href="/sites/create">
                                <Plus className="mr-1.5 h-4 w-4" />
                                Add {siteSingular}
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Stats */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <StatCard label={`Total ${sitePlural}`} value={sites.length} icon={Building2} color="blue" />
                    <StatCard label="Active" value={activeSites} icon={CheckCircle2} color="emerald" />
                    <StatCard label="High Risk" value={highRiskSites} icon={AlertTriangle} color="red" />
                    <StatCard label="Inactive" value={sites.length - activeSites} icon={Building2} color="amber" />
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder={`Search ${sitePlural.toLowerCase()}...`}
                            className="w-64 pl-9"
                            defaultValue={filters.q}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') updateFilter('q', (e.target as HTMLInputElement).value);
                            }}
                        />
                    </div>

                    <Select value={filters.type ?? 'all'} onValueChange={(v) => updateFilter('type', v === 'all' ? null : v)}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="All Types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Types</SelectItem>
                            {filterOptions.types.map((t) => (
                                <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.status ?? 'active'} onValueChange={(v) => updateFilter('status', v === 'all' ? null : v)}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select value={filters.risk ?? 'all'} onValueChange={(v) => updateFilter('risk', v === 'all' ? null : v)}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All Risk Levels" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Risk Levels</SelectItem>
                            {filterOptions.risks.map((r) => (
                                <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => router.get('/sites', {}, { preserveState: true })}
                            className="gap-1.5 text-muted-foreground"
                        >
                            <X className="h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">{siteSingular} Name</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground sm:table-cell">Type</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground md:table-cell">Region</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground lg:table-cell">Risk</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground xl:table-cell">Manager</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {sites.length === 0 ? (
                                        <tr>
                                            <td className="px-4 py-16 text-center" colSpan={7}>
                                                <Building2 className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                                <p className="font-medium text-muted-foreground">No {sitePlural.toLowerCase()} found</p>
                                                <p className="mt-1 text-sm text-muted-foreground/70">
                                                    {hasFilters ? 'Try adjusting your filters' : `Add a ${siteSingular.toLowerCase()} to get started`}
                                                </p>
                                            </td>
                                        </tr>
                                    ) : (
                                        sites.map((s) => {
                                            const TypeIcon = typeIcons[s.type] ?? Building2;
                                            return (
                                                <tr
                                                    key={s.id}
                                                    className="group cursor-pointer transition-colors hover:bg-muted/40"
                                                    onClick={() => router.visit(`/sites/${s.id}`)}
                                                >
                                                    <td className="px-4 py-3">
                                                        <div>
                                                            <Link
                                                                href={`/sites/${s.id}`}
                                                                className="font-medium text-foreground group-hover:text-primary"
                                                                onClick={(e) => e.stopPropagation()}
                                                            >
                                                                {s.name}
                                                            </Link>
                                                            <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                                                <MapPin className="h-3 w-3" />
                                                                {addressFor(s) || 'No address'}
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td className="hidden px-4 py-3 sm:table-cell">
                                                        <Badge variant="outline" className={typeColors[s.type] || ''}>
                                                            <TypeIcon className="mr-1 h-3 w-3" />
                                                            {typeLabels[s.type] || s.type}
                                                        </Badge>
                                                    </td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground md:table-cell">
                                                        {s.region || '—'}
                                                    </td>
                                                    <td className="hidden px-4 py-3 lg:table-cell">
                                                        <RiskBadge site={s} />
                                                    </td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground xl:table-cell">
                                                        {s.primary_contact?.name || '—'}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Badge
                                                            variant="outline"
                                                            className={s.is_active
                                                                ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                                : 'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-500/30 dark:bg-slate-500/10 dark:text-slate-400'
                                                            }
                                                        >
                                                            {s.is_active ? 'Active' : 'Inactive'}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-3 text-right" onClick={(e) => e.stopPropagation()}>
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <button className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors">
                                                                    <MoreVertical className="h-4 w-4" />
                                                                </button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end" className="w-48">
                                                                <DropdownMenuItem onClick={() => router.visit(`/sites/${s.id}`)}>
                                                                    <Eye className="mr-2 h-4 w-4" />
                                                                    View {siteSingular.toLowerCase()}
                                                                </DropdownMenuItem>
                                                                {can?.sites?.update && (
                                                                    <DropdownMenuItem onClick={() => router.visit(`/sites/${s.id}/edit`)}>
                                                                        <Pencil className="mr-2 h-4 w-4" />
                                                                        Edit
                                                                    </DropdownMenuItem>
                                                                )}
                                                                {can?.calendar?.create && (
                                                                    <DropdownMenuItem onClick={() => router.visit(`/sites/${s.id}/calendar?action=add`)}>
                                                                        <Calendar className="mr-2 h-4 w-4" />
                                                                        Add event
                                                                    </DropdownMenuItem>
                                                                )}
                                                                {can?.hazards?.create && (
                                                                    <DropdownMenuItem onClick={() => router.visit(`/sites/${s.id}/hazards?action=add`)}>
                                                                        <ShieldAlert className="mr-2 h-4 w-4" />
                                                                        Report hazard
                                                                    </DropdownMenuItem>
                                                                )}
                                                                {can?.checklists?.run && (
                                                                    <DropdownMenuItem onClick={() => router.visit(`/sites/${s.id}/checklists/runs`)}>
                                                                        <ClipboardCheck className="mr-2 h-4 w-4" />
                                                                        Run checklist
                                                                    </DropdownMenuItem>
                                                                )}
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    </td>
                                                </tr>
                                            );
                                        })
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
