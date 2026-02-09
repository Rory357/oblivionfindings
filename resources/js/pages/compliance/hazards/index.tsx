import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ShieldAlert, Plus, Filter, Download, AlertTriangle } from 'lucide-react';
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
    };
    severityOptions: Array<{ key: string; label: string }>;
};

const severityColors: Record<string, string> = {
    low: 'border-slate-500/30 text-slate-400',
    medium: 'border-yellow-500/30 text-yellow-400',
    high: 'border-orange-500/30 text-orange-400',
    critical: 'border-red-500/30 text-red-400 bg-red-500/10',
};

const riskColors: Record<string, string> = {
    low: 'bg-slate-500/20 text-slate-400',
    medium: 'bg-yellow-500/20 text-yellow-400',
    high: 'bg-orange-500/20 text-orange-400',
    extreme: 'bg-red-500/20 text-red-400',
};

const statusColors: Record<string, string> = {
    open: 'border-red-500/30 text-red-400',
    in_progress: 'border-amber-500/30 text-amber-400',
    mitigated: 'border-blue-500/30 text-blue-400',
    closed: 'border-emerald-500/30 text-emerald-400',
};

export default function GlobalHazards({ sites, hazards, filters, severityOptions }: Props) {
    const [siteFilter, setSiteFilter] = useState<string>(filters.site_id?.toString() || 'all');
    const [typeFilter, setTypeFilter] = useState<string>(filters.site_type || 'all');
    const [statusFilter, setStatusFilter] = useState<string>(filters.status || 'all');
    const [severityFilter, setSeverityFilter] = useState<string>(filters.severity || 'all');

    const filteredHazards = useMemo(() => {
        return hazards.filter(hazard => {
            if (siteFilter !== 'all' && hazard.site_id.toString() !== siteFilter) return false;
            if (typeFilter !== 'all' && hazard.site_type !== typeFilter) return false;
            if (statusFilter !== 'all' && hazard.status !== statusFilter) return false;
            if (severityFilter !== 'all' && hazard.severity !== severityFilter) return false;
            return true;
        });
    }, [hazards, siteFilter, typeFilter, statusFilter, severityFilter]);

    const openHazards = filteredHazards.filter(h => h.status === 'open' || h.status === 'in_progress');
    const overdueHazards = openHazards.filter(h => h.due_date && new Date(h.due_date) < new Date());

    return (
        <AppLayout breadcrumbs={[{ title: 'Hazards', href: '/compliance/hazards' }]}>
            <Head title="Home's and Sites Hazards" />

            <div className="m-4 space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <ShieldAlert className="w-5 h-5" />
                            Home's and Sites Hazards
                        </h1>
                        <p className="text-sm text-slate-400">
                            Hazard register across all sites
                        </p>
                    </div>
                    <Button asChild>
                        <Link href="/sites">
                            <Plus className="w-4 h-4 mr-1" />
                            Log Hazard
                        </Link>
                    </Button>
                </div>

                {/* Stats */}
                <div className="grid gap-4 sm:grid-cols-4">
                    <Card className="bg-slate-800/30">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold">{filteredHazards.length}</div>
                            <div className="text-sm text-slate-400">Total Hazards</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-red-500/5 border-red-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-red-400">
                                {openHazards.filter(h => h.severity === 'critical').length}
                            </div>
                            <div className="text-sm text-slate-400">Critical Open</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-amber-500/5 border-amber-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-amber-400">{overdueHazards.length}</div>
                            <div className="text-sm text-slate-400">Overdue</div>
                        </CardContent>
                    </Card>
                    <Card className="bg-emerald-500/5 border-emerald-500/20">
                        <CardContent className="p-4">
                            <div className="text-2xl font-bold text-emerald-400">
                                {filteredHazards.filter(h => h.status === 'closed').length}
                            </div>
                            <div className="text-sm text-slate-400">Closed</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm flex items-center gap-2">
                            <Filter className="w-4 h-4" />
                            Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-4 sm:grid-cols-5">
                            <div>
                                <Label className="text-xs">Site</Label>
                                <Select value={siteFilter} onValueChange={setSiteFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Sites</SelectItem>
                                        {sites.map(site => (
                                            <SelectItem key={site.id} value={site.id.toString()}>
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Site Type</Label>
                                <Select value={typeFilter} onValueChange={setTypeFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Types</SelectItem>
                                        <SelectItem value="head_office">Head Office</SelectItem>
                                        <SelectItem value="house">Houses</SelectItem>
                                        <SelectItem value="facility">Facilities</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Status</Label>
                                <Select value={statusFilter} onValueChange={setStatusFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Status</SelectItem>
                                        <SelectItem value="open">Open</SelectItem>
                                        <SelectItem value="in_progress">In Progress</SelectItem>
                                        <SelectItem value="mitigated">Mitigated</SelectItem>
                                        <SelectItem value="closed">Closed</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Severity</Label>
                                <Select value={severityFilter} onValueChange={setSeverityFilter}>
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Severities</SelectItem>
                                        {severityOptions.map(opt => (
                                            <SelectItem key={opt.key} value={opt.key}>
                                                {opt.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex items-end">
                                <Button variant="outline" className="w-full">
                                    <Download className="w-4 h-4 mr-1" />
                                    Export
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Hazards List */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Hazard Register ({filteredHazards.length})
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {filteredHazards.length === 0 ? (
                            <div className="text-center py-8 text-slate-400">
                                <ShieldAlert className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No hazards match your filters</p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {filteredHazards.map(hazard => (
                                    <div
                                        key={hazard.id}
                                        className={`flex items-center justify-between p-3 rounded-lg border ${
                                            hazard.status === 'open' && hazard.severity === 'critical'
                                                ? 'border-red-500/30 bg-red-500/5'
                                                : 'border-slate-700 hover:bg-slate-800/50'
                                        }`}
                                    >
                                        <div className="flex items-start gap-3">
                                            {hazard.status === 'open' && hazard.severity === 'critical' && (
                                                <AlertTriangle className="w-5 h-5 text-red-400 flex-shrink-0 mt-0.5" />
                                            )}
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <span className="font-medium">{hazard.reference_number}</span>
                                                    <span className="text-slate-400">•</span>
                                                    <span className="text-slate-300">{hazard.site_name}</span>
                                                </div>
                                                <div className="text-sm text-slate-300 mt-0.5">
                                                    {hazard.description.substring(0, 100)}
                                                    {hazard.description.length > 100 && '...'}
                                                </div>
                                                <div className="flex items-center gap-2 mt-2">
                                                    <Badge variant="outline" className={severityColors[hazard.severity]}>
                                                        {hazard.severity}
                                                    </Badge>
                                                    <Badge className={riskColors[hazard.risk_rating]}>
                                                        {hazard.risk_rating} risk
                                                    </Badge>
                                                    <Badge variant="outline" className={statusColors[hazard.status]}>
                                                        {hazard.status.replace('_', ' ')}
                                                    </Badge>
                                                    {hazard.assigned_to_name && (
                                                        <span className="text-xs text-slate-400">
                                                            Assigned: {hazard.assigned_to_name}
                                                        </span>
                                                    )}
                                                    {hazard.due_date && (
                                                        <span className={`text-xs ${
                                                            new Date(hazard.due_date) < new Date() && hazard.status !== 'closed'
                                                                ? 'text-red-400'
                                                                : 'text-slate-400'
                                                        }`}>
                                                            Due: {new Date(hazard.due_date).toLocaleDateString()}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                        <Button asChild variant="ghost" size="sm">
                                            <Link href={`/hazards/${hazard.id}`}>
                                                View
                                            </Link>
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
