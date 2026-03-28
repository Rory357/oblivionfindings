import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage, router } from '@inertiajs/react';
import { Building2, Home, MapPin, Warehouse, AlertTriangle, AlertCircle, CheckCircle2, Plus } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

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

const typeIcons = {
    head_office: Building2,
    house: Home,
    facility: Warehouse,
    residential: Home,
};

const typeLabels = {
    head_office: 'Head Office',
    house: 'House',
    facility: 'Facilities',
    residential: 'Residential',
};

const typeColors = {
    head_office: 'bg-blue-500/10 text-blue-400 border-blue-500/30',
    house: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/30',
    facility: 'bg-amber-500/10 text-amber-400 border-amber-500/30',
    residential: 'bg-violet-500/10 text-violet-400 border-violet-500/30',
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
            <Badge variant="outline" className="border-red-500/50 text-red-400 bg-red-500/10">
                <AlertTriangle className="w-3 h-3 mr-1" />
                High Risk + Needs
            </Badge>
        );
    }
    if (site.is_high_risk) {
        return (
            <Badge variant="outline" className="border-orange-500/50 text-orange-400 bg-orange-500/10">
                <AlertCircle className="w-3 h-3 mr-1" />
                High Risk
            </Badge>
        );
    }
    if (site.is_high_needs) {
        return (
            <Badge variant="outline" className="border-yellow-500/50 text-yellow-400 bg-yellow-500/10">
                <AlertCircle className="w-3 h-3 mr-1" />
                High Needs
            </Badge>
        );
    }
    return (
        <Badge variant="outline" className="border-slate-500/30 text-slate-400">
            <CheckCircle2 className="w-3 h-3 mr-1" />
            Standard
        </Badge>
    );
}

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

    return (
        <AppLayout breadcrumbs={[{ title: sitePlural, href: '/sites' }]}>
            <Head title={sitePlural} />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">{sitePlural}</h1>
                    {can?.sites?.create && (
                        <Button className="gap-2" asChild>
                            <Link href="/sites/create">
                                <Plus className="w-4 h-4" />
                                Add {siteSingular}
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3 p-4 rounded-xl border bg-card/50 backdrop-blur-sm">
                    <Select
                        value={filters.type ?? 'all'}
                        onValueChange={(v) => updateFilter('type', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="All Types" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Types</SelectItem>
                            {filterOptions.types.map((t) => (
                                <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.status ?? 'active'}
                        onValueChange={(v) => updateFilter('status', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[120px]">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>

                    {filterOptions.regions.length > 0 && (
                        <Select
                            value={filters.region ?? 'all'}
                            onValueChange={(v) => updateFilter('region', v === 'all' ? null : v)}
                        >
                            <SelectTrigger className="w-[140px]">
                                <SelectValue placeholder="All Regions" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Regions</SelectItem>
                                {filterOptions.regions.map((r) => (
                                    <SelectItem key={r} value={r}>{r}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

                    <Select
                        value={filters.risk ?? 'all'}
                        onValueChange={(v) => updateFilter('risk', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="All Risk Levels" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Risk Levels</SelectItem>
                            {filterOptions.risks.map((r) => (
                                <SelectItem key={r.value} value={r.value}>{r.label}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    {filterOptions.managers.length > 0 && (
                        <Select
                            value={filters.manager_id ?? 'all'}
                            onValueChange={(v) => updateFilter('manager_id', v === 'all' ? null : v)}
                        >
                            <SelectTrigger className="w-[160px]">
                                <SelectValue placeholder="All Managers" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Managers</SelectItem>
                                {filterOptions.managers.map((m) => (
                                    <SelectItem key={m.id} value={m.id.toString()}>{m.name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}

                    <div className="ml-auto">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => router.get('/sites', {}, { preserveState: true })}
                        >
                            Clear Filters
                        </Button>
                    </div>
                </div>

                <div className="overflow-hidden rounded-xl border bg-card/50">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-slate-50/5 backdrop-blur-sm">
                            <tr>
                                <th className="px-6 py-4 text-left font-semibold text-slate-200">Site Name</th>
                                <th className="px-6 py-4 text-left font-semibold text-slate-200">Type</th>
                                <th className="px-6 py-4 text-left font-semibold text-slate-200">Region</th>
                                <th className="px-6 py-4 text-left font-semibold text-slate-200">Risk</th>
                                <th className="px-6 py-4 text-left font-semibold text-slate-200">Manager</th>
                                <th className="px-6 py-4 text-left font-semibold text-slate-200">Status</th>
                                <th className="px-6 py-4 text-right font-semibold text-slate-200">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-700/50">
                            {sites.length === 0 ? (
                                <tr>
                                    <td className="px-6 py-12 text-center" colSpan={7}>
                                        <div className="flex flex-col items-center justify-center">
                                            <div className="rounded-full bg-slate-500/10 p-3 mb-3">
                                                <Building2 className="w-6 h-6 text-slate-400" />
                                            </div>
                                            <p className="text-slate-400 font-medium">No {sitePlural.toLowerCase()} found</p>
                                            <p className="text-xs text-slate-500 mt-1">Try adjusting your filters or create a new site</p>
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                sites.map((s) => {
                                    const TypeIcon = typeIcons[s.type];
                                    return (
                                        <tr key={s.id} className="hover:bg-slate-50/5 transition-colors">
                                            <td className="px-6 py-4">
                                                <Link href={`/sites/${s.id}`} className="group">
                                                    <div className="font-semibold text-slate-50 group-hover:text-blue-400 transition-colors">
                                                        {s.name}
                                                    </div>
                                                    <div className="text-xs text-slate-400 group-hover:text-slate-300 transition-colors">
                                                        <MapPin className="w-3 h-3 inline mr-1" />
                                                        {addressFor(s) || 'No address'}
                                                    </div>
                                                </Link>
                                            </td>
                                            <td className="px-6 py-4">
                                                <Badge variant="outline" className={typeColors[s.type]}>
                                                    <TypeIcon className="w-3 h-3 mr-1" />
                                                    {typeLabels[s.type]}
                                                </Badge>
                                            </td>
                                            <td className="px-6 py-4 text-slate-300">
                                                {s.region || '—'}
                                            </td>
                                            <td className="px-6 py-4">
                                                <RiskBadge site={s} />
                                            </td>
                                            <td className="px-6 py-4 text-slate-300">
                                                {s.primary_contact?.name || '—'}
                                            </td>
                                            <td className="px-6 py-4">
                                                <Badge
                                                    variant="outline"
                                                    className={s.is_active
                                                        ? 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10'
                                                        : 'border-slate-500/30 text-slate-400'
                                                    }
                                                >
                                                    {s.is_active ? 'Active' : 'Inactive'}
                                                </Badge>
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    <Button variant="outline" size="xs" asChild>
                                                        <Link href={`/sites/${s.id}`}>View</Link>
                                                    </Button>
                                                    {can?.calendar?.create && (
                                                        <Button variant="outline" size="xs" asChild>
                                                            <Link href={`/sites/${s.id}/calendar?action=add`}>Event</Link>
                                                        </Button>
                                                    )}
                                                    {can?.hazards?.create && (
                                                        <Button variant="outline" size="xs" asChild>
                                                            <Link href={`/sites/${s.id}/hazards?action=add`}>Hazard</Link>
                                                        </Button>
                                                    )}
                                                    {can?.checklists?.run && (
                                                        <Button variant="outline" size="xs" asChild>
                                                            <Link href={`/sites/${s.id}/checklists/runs`}>Check</Link>
                                                        </Button>
                                                    )}
                                                    {can?.sites?.update && (
                                                        <Button variant="outline" size="xs" asChild>
                                                            <Link href={`/sites/${s.id}/edit`}>Edit</Link>
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
