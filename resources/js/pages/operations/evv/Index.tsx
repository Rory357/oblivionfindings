import {
    DonutChart,
    OPS_COLORS,
    OpsStatCard,
} from '@/components/ops-stat-card';
import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Eye,
    Flag,
    MapPin,
    Search,
    Shield,
} from 'lucide-react';

const ANY = '__ANY__';

type EvvRecord = {
    id: number;
    status: string;
    check_in_time: string | null;
    check_out_time: string | null;
    gps_verified: boolean;
    has_issues: boolean;
    issue_description: string | null;
    worker: { id: number; name: string } | null;
    client: { id: number; first_name: string; last_name: string } | null;
    shift_date: string;
};

type Props = {
    records: {
        data: EvvRecord[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        status?: string;
    };
    stats: {
        total: number;
        verified: number;
        pending: number;
        flagged: number;
    };
};

const STATUS_VARIANTS: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    verified: 'default',
    pending: 'outline',
    flagged: 'destructive',
    in_progress: 'secondary',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatTime(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function EvvIndex({
    records = { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
    filters = {} as any,
    stats = {} as any,
}: Props) {
    const updateFilters = (key: string, value: string | null) => {
        router.get(
            '/operations/evv',
            { ...filters, [key]: value },
            { preserveState: true, replace: true },
        );
    };

    const donutSegments = [
        {
            label: 'Verified',
            value: stats?.verified ?? 0,
            color: OPS_COLORS.success,
        },
        {
            label: 'Pending',
            value: stats?.pending ?? 0,
            color: OPS_COLORS.warning,
        },
        {
            label: 'Flagged',
            value: stats?.flagged ?? 0,
            color: OPS_COLORS.danger,
        },
    ];

    return (
        <AppLayout>
            <Head title="Electronic Visit Verification" />
            <PageHero
                icon={MapPin}
                title="Electronic Visit Verification"
                description="Track and verify support worker visits with GPS and time validation."
                stats={[
                    { label: 'Total', value: stats?.total ?? 0 },
                    { label: 'Verified', value: stats?.verified ?? 0 },
                    { label: 'Pending', value: stats?.pending ?? 0 },
                    { label: 'Flagged', value: stats?.flagged ?? 0 },
                ]}
            />
            <PageShell>
                {/* Stats + Donut */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
                    <OpsStatCard
                        label="Total Records"
                        value={stats?.total ?? 0}
                        icon={Shield}
                        color="indigo"
                    />
                    <OpsStatCard
                        label="Verified"
                        value={stats?.verified ?? 0}
                        icon={CheckCircle2}
                        color="emerald"
                    />
                    <OpsStatCard
                        label="Flagged"
                        value={stats?.flagged ?? 0}
                        icon={Flag}
                        color={stats?.flagged > 0 ? 'red' : 'slate'}
                    />
                    <Card className="flex items-center justify-center border p-4">
                        <div className="flex flex-col items-center gap-2">
                            <DonutChart
                                segments={donutSegments}
                                size={100}
                                strokeWidth={14}
                                centerValue={stats?.total ?? 0}
                                centerLabel="total"
                            />
                            <div className="flex gap-3 text-[9px]">
                                {donutSegments.map((s) => (
                                    <span
                                        key={s.label}
                                        className="flex items-center gap-1"
                                    >
                                        <span
                                            className="inline-block h-2 w-2 rounded-full"
                                            style={{ backgroundColor: s.color }}
                                        />
                                        {s.label}
                                    </span>
                                ))}
                            </div>
                        </div>
                    </Card>
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search EVV records..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) =>
                                updateFilters('q', e.target.value || null)
                            }
                        />
                    </div>
                    <Select
                        value={filters?.status ?? ANY}
                        onValueChange={(v) =>
                            updateFilters('status', v === ANY ? null : v)
                        }
                    >
                        <SelectTrigger className="h-9 w-[130px] text-xs">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="verified">Verified</SelectItem>
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="flagged">Flagged</SelectItem>
                            <SelectItem value="in_progress">
                                In Progress
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(records?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <Shield className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">
                                    No EVV Records
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">
                                    Records will appear here when visits are
                                    logged.
                                </p>
                            </CardContent>
                        </Card>
                    )}
                    {(records?.data ?? []).map((rec) => (
                        <Card
                            key={rec.id}
                            className="transition-all hover:border-border hover:shadow-sm"
                        >
                            <CardContent className="flex items-center gap-4 p-4">
                                <div
                                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${rec.has_issues ? 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical' : 'bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70'}`}
                                >
                                    {rec.has_issues ? (
                                        <Flag className="h-5 w-5" />
                                    ) : (
                                        <Shield className="h-5 w-5" />
                                    )}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <Link
                                            href={`/operations/evv/${rec.id}`}
                                            className="text-sm font-semibold hover:underline"
                                        >
                                            Visit #{rec.id}
                                        </Link>
                                        <Badge
                                            variant={
                                                STATUS_VARIANTS[rec.status] ??
                                                'outline'
                                            }
                                            className="h-4 px-1.5 text-[9px] capitalize"
                                        >
                                            {rec.status.replace('_', ' ')}
                                        </Badge>
                                        {rec.gps_verified ? (
                                            <Badge
                                                variant="outline"
                                                className="h-4 px-1.5 text-[9px] text-status-success"
                                            >
                                                <MapPin className="mr-0.5 h-2.5 w-2.5" />{' '}
                                                GPS OK
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="outline"
                                                className="h-4 px-1.5 text-[9px] text-status-warning"
                                            >
                                                <AlertTriangle className="mr-0.5 h-2.5 w-2.5" />{' '}
                                                No GPS
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        {rec.worker && (
                                            <span>{rec.worker.name}</span>
                                        )}
                                        {rec.client && (
                                            <span>
                                                {rec.client.first_name}{' '}
                                                {rec.client.last_name}
                                            </span>
                                        )}
                                        <span>
                                            {formatDate(rec.shift_date)}
                                        </span>
                                        <span className="flex items-center gap-1">
                                            <Clock className="h-3 w-3" />
                                            {formatTime(
                                                rec.check_in_time,
                                            )} -{' '}
                                            {formatTime(rec.check_out_time)}
                                        </span>
                                    </div>
                                    {rec.has_issues &&
                                        rec.issue_description && (
                                            <p className="mt-1 text-xs text-status-critical dark:text-status-critical">
                                                {rec.issue_description}
                                            </p>
                                        )}
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="ghost"
                                        className="h-7 w-7 p-0"
                                    >
                                        <Link
                                            href={`/operations/evv/${rec.id}`}
                                        >
                                            <Eye className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(records?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(records?.links ?? []).map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveState: true },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
