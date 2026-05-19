import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { Head, Link, usePage, router } from '@inertiajs/react';
import { Building2, Home, MapPin, Warehouse, AlertTriangle, AlertCircle, CheckCircle2, Plus, Search, X, Eye, Pencil, Calendar, ShieldAlert, ClipboardCheck, Wrench } from 'lucide-react';
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
    active_clients_count?: number;
    rooms_total?: number;
    rooms_occupied?: number;
    vacancies?: number;
    open_hazards_count?: number;
    overdue_checklists_count?: number;
    open_maintenance_count?: number;
    readiness?: {
        score: number;
        critical_done: number;
        critical_total: number;
        is_active_but_incomplete: boolean;
    };
    geofence_status?: 'active' | 'inactive' | 'missing' | 'na';
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
        audit?: string;
        hazards?: string;
        maintenance?: string;
        readiness?: string;
        service?: string;
    };
    filterOptions: {
        regions: string[];
        managers: { id: number; name: string }[];
        types: { value: string; label: string }[];
        risks: { value: string; label: string }[];
    };
    savedViewCounts: {
        high_risk: number;
        audit_overdue: number;
        open_hazards: number;
        open_maintenance: number;
        active_incomplete: number;
        respite: number;
        inactive: number;
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
    head_office: 'border-status-info/30 bg-status-info-bg text-status-info dark:border-status-info/30 dark:bg-status-info-bg dark:text-status-info',
    house: 'border-status-success/30 bg-status-success-bg text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success',
    facility: 'border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning',
    residential: 'border-primary bg-primary/10 text-primary dark:border-primary/30 dark:bg-primary/10 dark:text-primary/70',
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
            <Badge variant="outline" className="border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical">
                <AlertTriangle className="mr-1 h-3 w-3" />
                High Risk + Needs
            </Badge>
        );
    }
    if (site.is_high_risk) {
        return (
            <Badge variant="outline" className="border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning">
                <AlertCircle className="mr-1 h-3 w-3" />
                High Risk
            </Badge>
        );
    }
    if (site.is_high_needs) {
        return (
            <Badge variant="outline" className="border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning">
                <AlertCircle className="mr-1 h-3 w-3" />
                High Needs
            </Badge>
        );
    }
    return (
        <Badge variant="outline" className="border-border bg-muted text-muted-foreground dark:border-border/30 dark:bg-muted-foreground/80/10 dark:text-muted-foreground">
            <CheckCircle2 className="mr-1 h-3 w-3" />
            Standard
        </Badge>
    );
}

/* ------------------------------------------------------------------ */
/*  Stat Card                                                          */
/* ------------------------------------------------------------------ */

const STAT_COLORS = {
    blue: { bg: 'bg-status-info-bg', icon: 'text-status-info dark:text-status-info', ring: 'ring-status-info dark:ring-status-info/20' },
    emerald: { bg: 'bg-status-success-bg', icon: 'text-status-success dark:text-status-success', ring: 'ring-status-success dark:ring-status-success/20' },
    amber: { bg: 'bg-status-warning-bg', icon: 'text-status-warning dark:text-status-warning', ring: 'ring-status-warning dark:ring-status-warning/20' },
    red: { bg: 'bg-status-critical-bg', icon: 'text-status-critical dark:text-status-critical', ring: 'ring-status-critical dark:ring-status-critical/20' },
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

function SavedViewChip({
    label,
    count,
    icon: Icon,
    active,
    onClick,
}: {
    label: string;
    count: number;
    icon: React.ElementType;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <Button
            type="button"
            variant={active ? 'default' : 'outline'}
            size="sm"
            className="gap-1.5"
            onClick={onClick}
        >
            <Icon className="h-3.5 w-3.5" />
            {label}
            <Badge variant="secondary" className="ml-1 px-1.5 py-0 text-[10px]">
                {count}
            </Badge>
        </Button>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function SitesIndex() {
    const { auth, filters, filterOptions, savedViewCounts, sites, labels } = usePage<PageProps & { labels?: Record<string, string> }>().props;
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

    const applySavedView = (nextFilters: Record<string, string>) => {
        router.get('/sites', { ...filters, ...nextFilters }, { preserveState: true, replace: true });
    };

    const hasFilters = !!(filters.type || filters.status || filters.region || filters.risk || filters.manager_id || filters.q || filters.audit || filters.hazards || filters.maintenance || filters.readiness || filters.service);

    const activeSites = sites.filter((s) => s.is_active).length;
    const openHazardsTotal = sites.reduce(
        (sum, site) => sum + (site.open_hazards_count ?? 0),
        0,
    );
    const overdueTotal = sites.reduce(
        (sum, site) =>
            sum +
            (site.overdue_checklists_count ?? 0) +
            (site.open_maintenance_count ?? 0),
        0,
    );

    return (
        <AppLayout breadcrumbs={[{ title: sitePlural, href: '/sites' }]}>
            <Head title={sitePlural} />

            <PageLayout
                hero={
                    <PageHero
                        title={sitePlural}
                        description={`Manage locations and facilities — ${sites.length} ${sites.length === 1 ? siteSingular.toLowerCase() : sitePlural.toLowerCase()} total`}
                        icon={Building2}
                        stats={[
                            { label: 'Total', value: sites.length },
                            { label: 'Active', value: activeSites },
                            { label: 'Active but incomplete', value: savedViewCounts.active_incomplete },
                            { label: 'Open hazards', value: openHazardsTotal },
                            { label: 'Audit overdue', value: overdueTotal },
                        ]}
                        actions={
                            can?.sites?.create ? (
                                <Button size="sm" asChild>
                                    <Link href="/sites/create">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        Add {siteSingular}
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    />
                }
            >
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

                    <Select value={filters.region ?? 'all'} onValueChange={(v) => updateFilter('region', v === 'all' ? null : v)}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="All Regions" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Regions</SelectItem>
                            {filterOptions.regions.map((region) => (
                                <SelectItem key={region} value={region}>{region}</SelectItem>
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

                <div className="flex flex-wrap items-center gap-2">
                    <SavedViewChip label="High risk" count={savedViewCounts.high_risk} icon={AlertTriangle} onClick={() => updateFilter('risk', 'high_risk')} active={filters.risk === 'high_risk'} />
                    <SavedViewChip label="Audit overdue" count={savedViewCounts.audit_overdue} icon={ClipboardCheck} onClick={() => applySavedView({ audit: 'overdue' })} active={filters.audit === 'overdue'} />
                    <SavedViewChip label="Open hazards" count={savedViewCounts.open_hazards} icon={ShieldAlert} onClick={() => applySavedView({ hazards: 'open' })} active={filters.hazards === 'open'} />
                    <SavedViewChip label="Maintenance" count={savedViewCounts.open_maintenance} icon={Wrench} onClick={() => applySavedView({ maintenance: 'open' })} active={filters.maintenance === 'open'} />
                    <SavedViewChip label="Active incomplete" count={savedViewCounts.active_incomplete} icon={AlertCircle} onClick={() => applySavedView({ readiness: 'incomplete' })} active={filters.readiness === 'incomplete'} />
                    <SavedViewChip label="Respite" count={savedViewCounts.respite} icon={Home} onClick={() => applySavedView({ service: 'respite' })} active={filters.service === 'respite'} />
                    <SavedViewChip label="Inactive" count={savedViewCounts.inactive} icon={X} onClick={() => updateFilter('status', 'inactive')} active={filters.status === 'inactive'} />
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
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground lg:table-cell" title="Capacity counts assignable bedrooms only">Capacity (beds)</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground lg:table-cell">Clients</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground xl:table-cell">Site Lead</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground xl:table-cell">Open hazards</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground xl:table-cell">Overdue</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground 2xl:table-cell">Risk</th>
                                        <th className="hidden px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground xl:table-cell">Readiness</th>
                                        <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                        <th className="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {sites.length === 0 ? (
                                        <tr>
                                            <td className="px-4 py-16 text-center" colSpan={12}>
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
                                            const overdueCount =
                                                (s.overdue_checklists_count ?? 0) +
                                                (s.open_maintenance_count ?? 0);
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
                                                    <td className="hidden px-4 py-3 text-muted-foreground lg:table-cell">
                                                        {(s.rooms_total ?? 0) > 0
                                                            ? `${s.rooms_occupied ?? 0}/${s.rooms_total} · ${s.vacancies ?? 0} vac.`
                                                            : '—'}
                                                    </td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground lg:table-cell">
                                                        {s.active_clients_count ?? 0}
                                                    </td>
                                                    <td className="hidden px-4 py-3 text-muted-foreground xl:table-cell">
                                                        {s.primary_contact?.name || '—'}
                                                    </td>
                                                    <td className="hidden px-4 py-3 xl:table-cell">
                                                        {(s.open_hazards_count ?? 0) > 0 ? (
                                                            <Badge variant="outline" className="border-status-critical/30 bg-status-critical-bg text-status-critical">
                                                                {s.open_hazards_count} open
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </td>
                                                    <td className="hidden px-4 py-3 xl:table-cell">
                                                        {overdueCount > 0 ? (
                                                            <Badge variant="outline" className="border-status-critical/30 bg-status-critical-bg text-status-critical">
                                                                {overdueCount} overdue
                                                            </Badge>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </td>
                                                    <td className="hidden px-4 py-3 2xl:table-cell">
                                                        <RiskBadge site={s} />
                                                    </td>
                                                    <td className="hidden px-4 py-3 xl:table-cell">
                                                        <div className="flex flex-wrap items-center gap-1.5">
                                                            {s.readiness ? (
                                                                <Badge variant="outline" className={s.readiness.is_active_but_incomplete ? 'border-status-warning/30 bg-status-warning-bg text-status-warning' : 'border-status-success/30 bg-status-success-bg text-status-success'}>
                                                                    {s.readiness.score}% ready
                                                                </Badge>
                                                            ) : '—'}
                                                            {s.geofence_status === 'active' && (
                                                                <span title="Geofence active" className="h-2 w-2 shrink-0 rounded-full bg-status-success" />
                                                            )}
                                                            {s.geofence_status === 'inactive' && (
                                                                <span title="Geofence disabled" className="h-2 w-2 shrink-0 rounded-full bg-muted-foreground" />
                                                            )}
                                                            {s.geofence_status === 'missing' && (
                                                                <span title="Geofence missing — needed for resident tracking" className="h-2 w-2 shrink-0 rounded-full bg-status-warning animate-pulse" />
                                                            )}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Badge
                                                            variant="outline"
                                                            className={s.is_active
                                                                ? 'border-status-success/30 bg-status-success-bg text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success'
                                                                : 'border-border bg-muted text-muted-foreground dark:border-border/30 dark:bg-muted-foreground/80/10 dark:text-muted-foreground'
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
            </PageLayout>
        </AppLayout>
    );
}
