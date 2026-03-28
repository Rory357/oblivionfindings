import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    AlertTriangle,
    Calendar,
    CheckCircle2,
    Clock,
    Copy,
    ExternalLink,
    Filter,
    MoreVertical,
    Search,
    ShieldAlert,
    User,
    UserPlus,
} from 'lucide-react';
import { useState, useMemo } from 'react';

type Site = {
    id: number;
    name: string;
    type: 'head_office' | 'house' | 'facility';
};

type Hazard = {
    id: number;
    reference_number: string;
    site_id: number;
    site_name: string;
    site_type: string;
    hazard_type: string;
    severity: 'low' | 'medium' | 'high' | 'critical';
    likelihood: 'rare' | 'unlikely' | 'possible' | 'likely' | 'almost_certain';
    risk_rating: 'low' | 'medium' | 'high' | 'extreme';
    description: string;
    status: 'open' | 'in_progress' | 'mitigated' | 'closed';
    assigned_to_name?: string;
    due_date?: string;
    created_at: string;
};

type Props = {
    sites: Site[];
    hazards: Hazard[];
    filters: {
        site_id?: number;
        site_type?: string;
        status?: string;
        severity?: string;
        risk_rating?: string;
    };
    severityOptions: Array<{ key: string; label: string }>;
};

const riskBorderColors: Record<string, string> = {
    extreme: 'border-l-red-600',
    high: 'border-l-orange-500',
    medium: 'border-l-amber-500',
    low: 'border-l-emerald-500',
};

const riskBgColors: Record<string, { bg: string; text: string }> = {
    extreme: { bg: 'bg-red-100', text: 'text-red-700' },
    high: { bg: 'bg-orange-100', text: 'text-orange-700' },
    medium: { bg: 'bg-amber-100', text: 'text-amber-700' },
    low: { bg: 'bg-emerald-100', text: 'text-emerald-700' },
};

const severityConfig: Record<string, { bg: string; text: string }> = {
    low: { bg: 'bg-emerald-50', text: 'text-emerald-700' },
    medium: { bg: 'bg-amber-50', text: 'text-amber-700' },
    high: { bg: 'bg-orange-50', text: 'text-orange-700' },
    critical: { bg: 'bg-red-100', text: 'text-red-800' },
};

const statusConfig: Record<string, { bg: string; text: string; icon: typeof Clock }> = {
    open: { bg: 'bg-red-100', text: 'text-red-700', icon: AlertTriangle },
    in_progress: { bg: 'bg-blue-100', text: 'text-blue-700', icon: Clock },
    mitigated: { bg: 'bg-purple-100', text: 'text-purple-700', icon: CheckCircle2 },
    closed: { bg: 'bg-green-100', text: 'text-green-700', icon: CheckCircle2 },
};

export default function GlobalHazards({ sites, hazards, filters, severityOptions }: Props) {
    const ANY = '__any__';

    const [searchTerm, setSearchTerm] = useState('');
    const [siteFilter, setSiteFilter] = useState<string>(filters.site_id?.toString() || ANY);
    const [typeFilter, setTypeFilter] = useState<string>(filters.site_type || ANY);
    const [statusFilter, setStatusFilter] = useState<string>(filters.status || ANY);
    const [severityFilter, setSeverityFilter] = useState<string>(filters.severity || ANY);
    const [riskFilter, setRiskFilter] = useState<string>(filters.risk_rating || ANY);

    const filteredHazards = useMemo(() => {
        return hazards.filter(hazard => {
            if (siteFilter !== ANY && hazard.site_id.toString() !== siteFilter) return false;
            if (typeFilter !== ANY && hazard.site_type !== typeFilter) return false;
            if (statusFilter !== ANY && hazard.status !== statusFilter) return false;
            if (severityFilter !== ANY && hazard.severity !== severityFilter) return false;
            if (riskFilter !== ANY && hazard.risk_rating !== riskFilter) return false;
            if (searchTerm) {
                const q = searchTerm.toLowerCase();
                if (
                    !hazard.reference_number.toLowerCase().includes(q) &&
                    !hazard.hazard_type.toLowerCase().includes(q) &&
                    !hazard.description.toLowerCase().includes(q) &&
                    !hazard.site_name.toLowerCase().includes(q)
                ) return false;
            }
            return true;
        });
    }, [hazards, siteFilter, typeFilter, statusFilter, severityFilter, riskFilter, searchTerm]);

    const isOverdue = (h: Hazard) => h.due_date && new Date(h.due_date) < new Date() && !['closed', 'mitigated'].includes(h.status);

    const openHazards = filteredHazards.filter(h => h.status === 'open' || h.status === 'in_progress');
    const criticalOpen = filteredHazards.filter(h => (h.risk_rating === 'extreme' || h.severity === 'critical') && h.status !== 'closed');
    const overdueHazards = filteredHazards.filter(h => isOverdue(h));
    const closedHazards = filteredHazards.filter(h => h.status === 'closed');

    return (
        <AppLayout breadcrumbs={[{ title: 'Hazards', href: '/compliance/hazards' }]}>
            <Head title="Hazard Register" />

            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-100">
                            <ShieldAlert className="h-5 w-5 text-orange-600" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold">Hazard Register</h1>
                            <div className="text-sm text-slate-500">Cross-site hazard overview</div>
                        </div>
                    </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-lg border bg-white p-3">
                        <div className="text-2xl font-bold">{filteredHazards.length}</div>
                        <div className="text-xs text-slate-500">Total hazards</div>
                    </div>
                    <div className="rounded-lg border bg-white p-3">
                        <div className={`text-2xl font-bold ${criticalOpen.length > 0 ? 'text-red-600' : 'text-slate-600'}`}>{criticalOpen.length}</div>
                        <div className="text-xs text-slate-500">Critical / Extreme open</div>
                    </div>
                    <div className="rounded-lg border bg-white p-3">
                        <div className={`text-2xl font-bold ${overdueHazards.length > 0 ? 'text-red-600' : 'text-slate-600'}`}>{overdueHazards.length}</div>
                        <div className="text-xs text-slate-500">Overdue</div>
                    </div>
                    <div className="rounded-lg border bg-white p-3">
                        <div className="text-2xl font-bold text-emerald-600">{closedHazards.length}</div>
                        <div className="text-xs text-slate-500">Closed</div>
                    </div>
                </div>

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
                                        placeholder="Reference, type, site..."
                                        className="pl-9"
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                    />
                                </div>
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">Site</Label>
                                <Select value={siteFilter} onValueChange={setSiteFilter}>
                                    <SelectTrigger><SelectValue placeholder="Site" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>All Sites</SelectItem>
                                        {sites.map(site => (
                                            <SelectItem key={site.id} value={site.id.toString()}>
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">Site Type</Label>
                                <Select value={typeFilter} onValueChange={setTypeFilter}>
                                    <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>All Types</SelectItem>
                                        <SelectItem value="head_office">Head Office</SelectItem>
                                        <SelectItem value="house">Houses</SelectItem>
                                        <SelectItem value="facility">Facilities</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">Status</Label>
                                <Select value={statusFilter} onValueChange={setStatusFilter}>
                                    <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        <SelectItem value="open">Open</SelectItem>
                                        <SelectItem value="in_progress">In Progress</SelectItem>
                                        <SelectItem value="mitigated">Mitigated</SelectItem>
                                        <SelectItem value="closed">Closed</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">Severity</Label>
                                <Select value={severityFilter} onValueChange={setSeverityFilter}>
                                    <SelectTrigger><SelectValue placeholder="Severity" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        {severityOptions.map(opt => (
                                            <SelectItem key={opt.key} value={opt.key}>
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">Risk Rating</Label>
                                <Select value={riskFilter} onValueChange={setRiskFilter}>
                                    <SelectTrigger><SelectValue placeholder="Risk" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        <SelectItem value="low">Low</SelectItem>
                                        <SelectItem value="medium">Medium</SelectItem>
                                        <SelectItem value="high">High</SelectItem>
                                        <SelectItem value="extreme">Extreme</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Hazard rows */}
                <div className="space-y-2">
                    {filteredHazards.map((hazard) => {
                        const risk = riskBgColors[hazard.risk_rating] ?? riskBgColors.low;
                        const sev = severityConfig[hazard.severity] ?? severityConfig.low;
                        const stat = statusConfig[hazard.status] ?? statusConfig.open;
                        const StatusIcon = stat.icon;
                        const overdue = isOverdue(hazard);
                        const preview = hazard.description
                            ? hazard.description.length > 120
                                ? hazard.description.slice(0, 120) + '...'
                                : hazard.description
                            : null;

                        return (
                            <div
                                key={hazard.id}
                                className={`group relative cursor-pointer rounded-lg border border-l-4 bg-white transition-all hover:shadow-md ${riskBorderColors[hazard.risk_rating] ?? 'border-l-slate-300'}`}
                                onClick={() => router.visit(`/hazards/${hazard.id}`)}
                            >
                                <div className="block px-4 py-3 pr-12">
                                    <div className="flex items-start gap-4">
                                        <div className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${risk.bg}`}>
                                            <AlertTriangle className={`h-5 w-5 ${risk.text}`} />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2 flex-wrap">
                                                <span className="font-semibold">{hazard.reference_number}</span>
                                                <span className="text-slate-300">|</span>
                                                <span className="capitalize">{hazard.hazard_type.replace(/_/g, ' ')}</span>
                                                <span className="text-xs text-slate-400">{hazard.site_name}</span>
                                            </div>
                                            <div className="mt-1 flex items-center gap-2 flex-wrap">
                                                <Badge className={`${sev.bg} ${sev.text} border-0 text-[10px] font-medium`}>
                                                    {hazard.severity}
                                                </Badge>
                                                <Badge className={`${stat.bg} ${stat.text} border-0 text-[10px] font-medium`}>
                                                    <StatusIcon className="mr-1 h-3 w-3" />
                                                    {hazard.status.replace(/_/g, ' ')}
                                                </Badge>
                                                <Badge className={`${risk.bg} ${risk.text} border-0 text-[10px] font-medium`}>
                                                    {hazard.risk_rating} risk
                                                </Badge>
                                                {overdue && (
                                                    <Badge className="bg-red-100 text-red-700 border-0 text-[10px] font-medium">
                                                        <Clock className="mr-1 h-3 w-3" />
                                                        Overdue
                                                    </Badge>
                                                )}
                                            </div>
                                            {preview && (
                                                <p className="mt-1 text-sm text-slate-600 line-clamp-1">{preview}</p>
                                            )}
                                            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                                <span className="flex items-center gap-1">
                                                    <Calendar className="h-3 w-3" />
                                                    {formatDateTime(hazard.created_at)}
                                                </span>
                                                {hazard.assigned_to_name && (
                                                    <span className="flex items-center gap-1">
                                                        <User className="h-3 w-3" />
                                                        {hazard.assigned_to_name}
                                                    </span>
                                                )}
                                                {hazard.due_date && (
                                                    <span className={overdue ? 'text-red-600 font-medium' : ''}>
                                                        Due {new Date(hazard.due_date).toLocaleDateString()}
                                                    </span>
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
                                            <DropdownMenuItem onClick={() => router.visit(`/hazards/${hazard.id}`)}>
                                                <ExternalLink className="mr-2 h-4 w-4" />
                                                Open hazard
                                            </DropdownMenuItem>
                                            {hazard.status !== 'closed' && (
                                                <DropdownMenuItem onClick={() => router.visit(`/hazards/${hazard.id}`)}>
                                                    <UserPlus className="mr-2 h-4 w-4" />
                                                    Assign
                                                </DropdownMenuItem>
                                            )}
                                            {['open', 'in_progress', 'mitigated'].includes(hazard.status) && (
                                                <DropdownMenuItem onClick={() => router.visit(`/hazards/${hazard.id}`)}>
                                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                                    Close
                                                </DropdownMenuItem>
                                            )}
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem onClick={() => {
                                                navigator.clipboard.writeText(`${window.location.origin}/hazards/${hazard.id}`);
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

                    {!filteredHazards.length && (
                        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-12 text-center">
                            <ShieldAlert className="h-10 w-10 text-slate-300" />
                            <div className="mt-2 text-sm font-medium text-slate-500">No hazards match your filters</div>
                            <div className="text-xs text-slate-400">Try adjusting your filters</div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
