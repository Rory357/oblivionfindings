import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { ShieldAlert, Plus, AlertTriangle, AlertCircle, CheckCircle2, Clock } from 'lucide-react';
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
    type: string;
};

type Hazard = {
    id: number;
    reference_number: string;
    hazard_type: string;
    severity: 'low' | 'medium' | 'high' | 'critical';
    risk_rating: 'low' | 'medium' | 'high' | 'extreme';
    description: string;
    status: 'open' | 'in_progress' | 'mitigated' | 'closed';
    due_date?: string;
    assigned_to?: { id: number; name: string } | null;
    reported_by: { id: number; name: string };
    created_at: string;
};

type Props = {
    site: Site;
    hazards: {
        data: Hazard[];
    };
    filters: {
        status?: string;
        severity?: string;
    };
    severityOptions: string[];
};

const severityColors = {
    low: 'border-slate-500/30 text-slate-400 bg-slate-500/10',
    medium: 'border-yellow-500/30 text-yellow-400 bg-yellow-500/10',
    high: 'border-orange-500/30 text-orange-400 bg-orange-500/10',
    critical: 'border-red-500/30 text-red-400 bg-red-500/10',
};

const riskColors = {
    low: 'bg-slate-500',
    medium: 'bg-yellow-500',
    high: 'bg-orange-500',
    extreme: 'bg-red-500',
};

const statusIcons = {
    open: AlertCircle,
    in_progress: Clock,
    mitigated: CheckCircle2,
    closed: CheckCircle2,
};

export default function SiteHazards({ site, hazards, filters, severityOptions }: Props) {
    const updateFilter = (key: string, value: string | null) => {
        const newFilters = { ...filters, [key]: value };
        if (value === null || value === 'all') {
            delete newFilters[key as keyof typeof newFilters];
        }
        router.get(`/sites/${site.id}/hazards`, newFilters, { preserveState: true, replace: true });
    };

    const isOverdue = (hazard: Hazard) => {
        if (!hazard.due_date || hazard.status === 'closed' || hazard.status === 'mitigated') return false;
        return new Date(hazard.due_date) < new Date();
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Sites', href: '/sites' }, { title: site.name, href: `/sites/${site.id}` }, { title: 'Hazards', href: `/sites/${site.id}/hazards` }]}>
            <Head title={`${site.name} - Hazards`} />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold flex items-center gap-2">
                            <ShieldAlert className="w-5 h-5" />
                            Site Hazards
                        </h1>
                        <p className="text-sm text-slate-400">{site.name}</p>
                    </div>
                    <Button asChild>
                        <Link href={`/sites/${site.id}/hazards/create`}>
                            <Plus className="w-4 h-4 mr-1" />
                            Log Hazard
                        </Link>
                    </Button>
                </div>

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-2 p-3 rounded-lg border bg-card">
                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(v) => updateFilter('status', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="All Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="open">Open</SelectItem>
                            <SelectItem value="in_progress">In Progress</SelectItem>
                            <SelectItem value="mitigated">Mitigated</SelectItem>
                            <SelectItem value="closed">Closed</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select
                        value={filters.severity ?? 'all'}
                        onValueChange={(v) => updateFilter('severity', v === 'all' ? null : v)}
                    >
                        <SelectTrigger className="w-[140px]">
                            <SelectValue placeholder="All Severities" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Severities</SelectItem>
                            {severityOptions.map((s) => (
                                <SelectItem key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Hazards List */}
                <div className="space-y-3">
                    {hazards.data.length === 0 ? (
                        <Card>
                            <CardContent className="py-8 text-center text-slate-400">
                                <ShieldAlert className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No hazards logged for this site</p>
                            </CardContent>
                        </Card>
                    ) : (
                        hazards.data.map((hazard) => {
                            const StatusIcon = statusIcons[hazard.status];
                            return (
                                <Card key={hazard.id} className="hover:bg-muted/50 transition-colors">
                                    <CardContent className="p-4">
                                        <div className="flex items-start justify-between gap-4">
                                            <div className="flex-1">
                                                <div className="flex items-center gap-2 mb-2">
                                                    <span className="font-mono text-sm text-slate-500">{hazard.reference_number}</span>
                                                    <Badge variant="outline" className={severityColors[hazard.severity]}>
                                                        {hazard.severity}
                                                    </Badge>
                                                    <Badge variant="outline" className="flex items-center gap-1">
                                                        <StatusIcon className="w-3 h-3" />
                                                        {hazard.status.replace('_', ' ')}
                                                    </Badge>
                                                    {isOverdue(hazard) && (
                                                        <Badge variant="outline" className="border-red-500/50 text-red-400">
                                                            <AlertTriangle className="w-3 h-3 mr-1" />
                                                            Overdue
                                                        </Badge>
                                                    )}
                                                </div>
                                                <h3 className="font-medium mb-1">{hazard.hazard_type}</h3>
                                                <p className="text-sm text-slate-400 line-clamp-2">{hazard.description}</p>
                                                <div className="flex items-center gap-4 mt-2 text-xs text-slate-500">
                                                    <span>Reported by: {hazard.reported_by.name}</span>
                                                    {hazard.assigned_to && (
                                                        <span>Assigned to: {hazard.assigned_to.name}</span>
                                                    )}
                                                    {hazard.due_date && (
                                                        <span>Due: {new Date(hazard.due_date).toLocaleDateString()}</span>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex flex-col items-end gap-2">
                                                {/* Risk Rating Indicator */}
                                                <div className="text-center">
                                                    <div className={`w-8 h-8 rounded-full ${riskColors[hazard.risk_rating]} flex items-center justify-center text-white font-bold text-xs`}>
                                                        {hazard.risk_rating.charAt(0).toUpperCase()}
                                                    </div>
                                                    <span className="text-xs text-slate-500 capitalize">{hazard.risk_rating}</span>
                                                </div>
                                                <Button asChild variant="ghost" size="sm">
                                                    <Link href={`/hazards/${hazard.id}`}>View</Link>
                                                </Button>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>
                            );
                        })
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
