import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle2,
    Clock,
    Copy,
    ExternalLink,
    Filter,
    MoreVertical,
    Plus,
    Search,
    User,
    UserPlus,
} from 'lucide-react';

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
        links?: any[];
        total?: number;
    };
    filters: {
        search?: string;
        status?: string;
        severity?: string;
        risk_rating?: string;
    };
    severityOptions: string[];
    recommendedHazards?: EmptyStateSuggestion[];
};

type EmptyStateSuggestion = {
    key: string;
    label: string;
    hint: string;
};

const riskBorderColors: Record<string, string> = {
    extreme: 'border-l-red-600',
    high: 'border-l-orange-500',
    medium: 'border-l-amber-500',
    low: 'border-l-emerald-500',
};

const riskBgColors: Record<string, { bg: string; text: string }> = {
    extreme: { bg: 'bg-status-critical-bg', text: 'text-status-critical' },
    high: { bg: 'bg-status-warning-bg', text: 'text-status-warning' },
    medium: { bg: 'bg-status-warning-bg', text: 'text-status-warning' },
    low: { bg: 'bg-status-success-bg', text: 'text-status-success' },
};

const severityConfig: Record<string, { bg: string; text: string }> = {
    low: { bg: 'bg-status-success-bg', text: 'text-status-success' },
    medium: { bg: 'bg-status-warning-bg', text: 'text-status-warning' },
    high: { bg: 'bg-status-warning-bg', text: 'text-status-warning' },
    critical: { bg: 'bg-status-critical-bg', text: 'text-status-critical' },
};

const statusConfig: Record<
    string,
    { bg: string; text: string; icon: typeof Clock }
> = {
    open: {
        bg: 'bg-status-critical-bg',
        text: 'text-status-critical',
        icon: AlertTriangle,
    },
    in_progress: {
        bg: 'bg-status-info-bg',
        text: 'text-status-info',
        icon: Clock,
    },
    mitigated: {
        bg: 'bg-primary/10',
        text: 'text-primary',
        icon: CheckCircle2,
    },
    closed: {
        bg: 'bg-status-success-bg',
        text: 'text-status-success',
        icon: CheckCircle2,
    },
};

export default function SiteHazards({
    site,
    hazards,
    filters,
    severityOptions,
    recommendedHazards = [],
}: Props) {
    const ANY = '__any__';

    const onFilter = (next: Partial<typeof filters>) => {
        const merged = { ...filters, ...next };
        const cleaned: Record<string, string> = {};
        for (const [k, v] of Object.entries(merged)) {
            if (v && v !== ANY && v !== 'all') cleaned[k] = v;
        }
        router.get(`/sites/${site.id}/hazards`, cleaned, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const isOverdue = (hazard: Hazard) => {
        if (
            !hazard.due_date ||
            hazard.status === 'closed' ||
            hazard.status === 'mitigated'
        )
            return false;
        return new Date(hazard.due_date) < new Date();
    };

    const data = hazards.data ?? [];
    const openCount = data.filter(
        (h) => h.status === 'open' || h.status === 'in_progress',
    ).length;
    const criticalCount = data.filter(
        (h) =>
            (h.risk_rating === 'extreme' || h.severity === 'critical') &&
            h.status !== 'closed',
    ).length;
    const overdueCount = data.filter((h) => isOverdue(h)).length;
    const closedCount = data.filter((h) => h.status === 'closed').length;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Hazards', href: `/sites/${site.id}/hazards` },
            ]}
        >
            <Head title={`${site.name} - Hazards`} />

            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-warning-bg">
                            <AlertTriangle className="h-5 w-5 text-status-warning" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold">
                                Hazards - {site.name}
                            </h1>
                            <div className="text-sm text-muted-foreground">
                                Hazard register for this site
                            </div>
                        </div>
                    </div>
                    <Link href={`/sites/${site.id}/hazards/create`}>
                        <Button size="sm">
                            <Plus className="mr-1 h-4 w-4" />
                            Log Hazard
                        </Button>
                    </Link>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Card className="gap-0 rounded-lg p-3 shadow-sm">
                        <div className="text-2xl font-bold">{openCount}</div>
                        <div className="text-xs text-muted-foreground">
                            Open hazards
                        </div>
                    </Card>
                    <Card className="gap-0 rounded-lg p-3 shadow-sm">
                        <div
                            className={`text-2xl font-bold ${criticalCount > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                        >
                            {criticalCount}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Critical / Extreme
                        </div>
                    </Card>
                    <Card className="gap-0 rounded-lg p-3 shadow-sm">
                        <div
                            className={`text-2xl font-bold ${overdueCount > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                        >
                            {overdueCount}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Overdue
                        </div>
                    </Card>
                    <Card className="gap-0 rounded-lg p-3 shadow-sm">
                        <div className="text-2xl font-bold text-status-success">
                            {closedCount}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Closed
                        </div>
                    </Card>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-4">
                        <div className="mb-3 flex items-center gap-2 text-sm font-medium text-foreground">
                            <Filter className="h-4 w-4" />
                            Filters
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-5">
                            <div className="sm:col-span-2">
                                <Label className="text-xs text-muted-foreground">
                                    Search
                                </Label>
                                <div className="relative">
                                    <Search className="absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        placeholder="Reference, type, description..."
                                        className="pl-9"
                                        value={filters.search || ''}
                                        onChange={(e) =>
                                            onFilter({
                                                search:
                                                    e.target.value || undefined,
                                            })
                                        }
                                    />
                                </div>
                            </div>

                            <div>
                                <Label className="text-xs text-muted-foreground">
                                    Status
                                </Label>
                                <Select
                                    value={filters.status ?? ANY}
                                    onValueChange={(v) =>
                                        onFilter({
                                            status: v === ANY ? undefined : v,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        <SelectItem value="open">
                                            Open
                                        </SelectItem>
                                        <SelectItem value="in_progress">
                                            In Progress
                                        </SelectItem>
                                        <SelectItem value="mitigated">
                                            Mitigated
                                        </SelectItem>
                                        <SelectItem value="closed">
                                            Closed
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs text-muted-foreground">
                                    Severity
                                </Label>
                                <Select
                                    value={filters.severity ?? ANY}
                                    onValueChange={(v) =>
                                        onFilter({
                                            severity: v === ANY ? undefined : v,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Severity" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        {severityOptions.map((s) => (
                                            <SelectItem
                                                key={s}
                                                value={s}
                                                className="capitalize"
                                            >
                                                {s.charAt(0).toUpperCase() +
                                                    s.slice(1)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs text-muted-foreground">
                                    Risk Rating
                                </Label>
                                <Select
                                    value={filters.risk_rating ?? ANY}
                                    onValueChange={(v) =>
                                        onFilter({
                                            risk_rating:
                                                v === ANY ? undefined : v,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Risk" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        <SelectItem value="low">Low</SelectItem>
                                        <SelectItem value="medium">
                                            Medium
                                        </SelectItem>
                                        <SelectItem value="high">
                                            High
                                        </SelectItem>
                                        <SelectItem value="extreme">
                                            Extreme
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Hazard rows */}
                <div className="space-y-2">
                    {data.map((hazard) => {
                        const risk =
                            riskBgColors[hazard.risk_rating] ??
                            riskBgColors.low;
                        const sev =
                            severityConfig[hazard.severity] ??
                            severityConfig.low;
                        const stat =
                            statusConfig[hazard.status] ?? statusConfig.open;
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
                                onClick={() =>
                                    router.visit(`/hazards/${hazard.id}`)
                                }
                            >
                                <div className="block px-4 py-3 pr-12">
                                    <div className="flex items-start gap-4">
                                        <div
                                            className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${risk.bg}`}
                                        >
                                            <AlertTriangle
                                                className={`h-5 w-5 ${risk.text}`}
                                            />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-semibold">
                                                    {hazard.reference_number}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    |
                                                </span>
                                                <span className="capitalize">
                                                    {hazard.hazard_type.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </span>
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-2">
                                                <Badge
                                                    className={`${sev.bg} ${sev.text} border-0 text-[10px] font-medium`}
                                                >
                                                    {hazard.severity}
                                                </Badge>
                                                <Badge
                                                    className={`${stat.bg} ${stat.text} border-0 text-[10px] font-medium`}
                                                >
                                                    <StatusIcon className="mr-1 h-3 w-3" />
                                                    {hazard.status.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </Badge>
                                                <Badge
                                                    className={`${risk.bg} ${risk.text} border-0 text-[10px] font-medium`}
                                                >
                                                    {hazard.risk_rating} risk
                                                </Badge>
                                                {overdue && (
                                                    <Badge className="border-0 bg-status-critical-bg text-[10px] font-medium text-status-critical">
                                                        <Clock className="mr-1 h-3 w-3" />
                                                        Overdue
                                                    </Badge>
                                                )}
                                            </div>
                                            {preview && (
                                                <p className="mt-1 line-clamp-1 text-sm text-muted-foreground">
                                                    {preview}
                                                </p>
                                            )}
                                            <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                <span className="flex items-center gap-1">
                                                    <User className="h-3 w-3" />
                                                    {hazard.reported_by.name}
                                                </span>
                                                <span className="flex items-center gap-1">
                                                    <Calendar className="h-3 w-3" />
                                                    {formatDateTime(
                                                        hazard.created_at,
                                                    )}
                                                </span>
                                                {hazard.assigned_to && (
                                                    <span className="text-muted-foreground">
                                                        Assigned to{' '}
                                                        {
                                                            hazard.assigned_to
                                                                .name
                                                        }
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {/* Three-dot menu */}
                                <div
                                    className="absolute top-2.5 right-2 z-10"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <button className="rounded p-1.5 text-muted-foreground transition-colors hover:bg-muted hover:text-muted-foreground">
                                                <MoreVertical className="h-4 w-4" />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            className="w-48"
                                        >
                                            <DropdownMenuItem
                                                onClick={() =>
                                                    router.visit(
                                                        `/hazards/${hazard.id}`,
                                                    )
                                                }
                                            >
                                                <ExternalLink className="mr-2 h-4 w-4" />
                                                Open hazard
                                            </DropdownMenuItem>
                                            {hazard.status !== 'closed' && (
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        router.visit(
                                                            `/hazards/${hazard.id}`,
                                                        )
                                                    }
                                                >
                                                    <UserPlus className="mr-2 h-4 w-4" />
                                                    Assign
                                                </DropdownMenuItem>
                                            )}
                                            {[
                                                'open',
                                                'in_progress',
                                                'mitigated',
                                            ].includes(hazard.status) && (
                                                <DropdownMenuItem
                                                    onClick={() =>
                                                        router.visit(
                                                            `/hazards/${hazard.id}`,
                                                        )
                                                    }
                                                >
                                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                                    Close
                                                </DropdownMenuItem>
                                            )}
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                onClick={() => {
                                                    navigator.clipboard.writeText(
                                                        `${window.location.origin}/hazards/${hazard.id}`,
                                                    );
                                                }}
                                            >
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
                        <Card className="border-dashed">
                            <CardContent className="space-y-4 p-6">
                                <div className="flex items-center gap-3">
                                    <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <AlertTriangle className="h-5 w-5" />
                                    </span>
                                    <div>
                                        <h3 className="font-semibold">
                                            No hazards yet
                                        </h3>
                                        <p className="text-sm text-muted-foreground">
                                            Recommended checks for supported-living sites. Log anything that needs a control or follow-up.
                                        </p>
                                    </div>
                                </div>
                                <ul className="grid gap-2 sm:grid-cols-2">
                                    {recommendedHazards.map((suggestion) => (
                                        <li
                                            key={suggestion.key}
                                            className="flex items-center justify-between gap-3 rounded-lg border bg-card/40 px-3 py-2"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {suggestion.label}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {suggestion.hint}
                                                </p>
                                            </div>
                                            <Button size="sm" variant="outline" asChild>
                                                <Link
                                                    href={`/sites/${site.id}/hazards/create?type=${encodeURIComponent(suggestion.key)}&label=${encodeURIComponent(suggestion.label)}`}
                                                >
                                                    Log this
                                                </Link>
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {/* Pagination */}
                {hazards.links && hazards.links.length > 3 && (
                    <div className="flex flex-wrap items-center justify-center gap-1">
                        {hazards.links.map((l: any, idx: number) => (
                            <Button
                                key={idx}
                                type="button"
                                variant="outline"
                                disabled={!l.url}
                                className={`h-auto rounded-md px-3 py-1.5 text-xs transition-colors ${
                                    l.active
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : l.url
                                          ? 'hover:bg-muted'
                                          : 'cursor-not-allowed opacity-50'
                                }`}
                                onClick={() =>
                                    l.url &&
                                    router.get(
                                        l.url,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
