import {
    EligibilityStatusBadge,
    deriveEligibilityStatus,
} from '@/components/eligibility/eligibility-status-badge';
import { OpsStatCard } from '@/components/ops-stat-card';
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
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    Briefcase,
    CalendarDays,
    CheckCircle2,
    Clock,
    Hand,
    MapPin,
    Search,
    UserCheck,
} from 'lucide-react';

const ANY = '__ANY__';

type JobPost = {
    id: number;
    title: string;
    status: string;
    date: string;
    start_time: string;
    end_time: string;
    location: string | null;
    required_skills: string[];
    coverage_roles: string[];
    client: {
        id: number;
        first_name: string;
        last_name: string;
        display_name: string;
        suburb: string | null;
        is_redacted: boolean;
    } | null;
    privacy: {
        can_view_sensitive_details: boolean;
    };
    claimed_by: { id: number; name: string } | null;
    eligibility: {
        is_eligible: boolean;
        blocked_reasons: string[];
        warning_count: number;
        first_warning: string | null;
    } | null;
    viewer_eligibility: {
        is_eligible: boolean;
        blocked_reasons: string[];
        warning_count: number;
        first_warning: string | null;
    } | null;
    replacement: {
        id: number;
        status: string;
        reason: string | null;
        requested_at?: string | null;
        current_staff?: { id: number; name: string } | null;
        requested_by?: { id: number; name: string } | null;
        replacement_staff?: { id: number; name: string } | null;
    } | null;
};

type Props = {
    jobs: {
        data: JobPost[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        status?: string;
        scope?: string;
        date_range?: string;
        skill?: string;
    };
    available_skills: string[];
    stats: {
        open: number;
        claimed: number;
        filled_today: number;
    };
};

const STATUS_VARIANTS: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    open: 'outline',
    claimed: 'secondary',
    approved: 'default',
    filled: 'default',
    cancelled: 'destructive',
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function JobBoardIndex({
    jobs = { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
    filters = {} as any,
    available_skills = [],
    stats = {} as any,
}: Props) {
    const updateFilters = (key: string, value: string | null) => {
        const nextFilters: Record<string, string> = {};

        Object.entries({ ...filters, [key]: value }).forEach(
            ([filterKey, filterValue]) => {
                if (filterValue) {
                    nextFilters[filterKey] = filterValue;
                }
            },
        );

        if (key === 'scope' && value === 'mine') {
            delete nextFilters.status;
        }

        router.get('/operations/job-board', nextFilters, {
            preserveState: true,
            replace: true,
        });
    };

    const scope = filters?.scope ?? null;

    return (
        <AppLayout>
            <Head title="Job Board" />
            <PageHero variant="compact"
                title="Job Board"
                description="Open shifts and positions available for support workers."
                backHref="/operations"
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <OpsStatCard
                        label="Open Positions"
                        value={stats?.open ?? 0}
                        icon={Briefcase}
                        color="indigo"
                    />
                    <OpsStatCard
                        label="Claimed"
                        value={stats?.claimed ?? 0}
                        icon={Hand}
                        color="amber"
                    />
                    <OpsStatCard
                        label="Filled Today"
                        value={stats?.filled_today ?? 0}
                        icon={CheckCircle2}
                        color="emerald"
                    />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div
                        role="tablist"
                        aria-label="Job board view"
                        className="flex rounded-md border bg-muted/40 p-1"
                    >
                        <Button
                            type="button"
                            size="sm"
                            variant={scope === 'mine' ? 'ghost' : 'default'}
                            role="tab"
                            aria-selected={scope !== 'mine'}
                            data-test="job-board-all-tab"
                            className="h-7 rounded-sm px-3 text-xs"
                            onClick={() => updateFilters('scope', null)}
                        >
                            All
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={scope === 'mine' ? 'default' : 'ghost'}
                            role="tab"
                            aria-selected={scope === 'mine'}
                            data-test="job-board-my-claims-tab"
                            className="h-7 rounded-sm px-3 text-xs"
                            onClick={() => updateFilters('scope', 'mine')}
                        >
                            My claims
                        </Button>
                    </div>
                    <div className="relative flex-1">
                        <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search positions..."
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
                            <SelectItem value="open">Open</SelectItem>
                            <SelectItem value="claimed">Claimed</SelectItem>
                            <SelectItem value="filled">Filled</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters?.date_range ?? ANY}
                        onValueChange={(v) =>
                            updateFilters('date_range', v === ANY ? null : v)
                        }
                    >
                        <SelectTrigger
                            className="h-9 w-[140px] text-xs"
                            data-test="job-board-date-filter"
                        >
                            <SelectValue placeholder="Date" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>Any Date</SelectItem>
                            <SelectItem value="next_7_days">
                                Next 7 Days
                            </SelectItem>
                            <SelectItem value="this_weekend">
                                This Weekend
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters?.skill ?? ANY}
                        onValueChange={(v) =>
                            updateFilters('skill', v === ANY ? null : v)
                        }
                    >
                        <SelectTrigger
                            className="h-9 w-[150px] text-xs"
                            data-test="job-board-skill-filter"
                        >
                            <SelectValue placeholder="Skill" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>Any Skill</SelectItem>
                            {available_skills.map((skill) => (
                                <SelectItem key={skill} value={skill}>
                                    {skill}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Card Grid */}
                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {(jobs?.data ?? []).length === 0 && (
                        <div className="col-span-full">
                            <Card>
                                <CardContent className="flex flex-col items-center justify-center py-16">
                                    <Briefcase className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                    <h2 className="text-lg font-semibold text-muted-foreground">
                                        {scope === 'mine'
                                            ? 'No Claims'
                                            : 'No Open Positions'}
                                    </h2>
                                    <p className="mt-1 text-sm text-muted-foreground/80">
                                        {scope === 'mine'
                                            ? 'Claims you submit will appear here.'
                                            : 'All shifts are currently filled. Check back later.'}
                                    </p>
                                </CardContent>
                            </Card>
                        </div>
                    )}
                    {(jobs?.data ?? []).map((job) => {
                        const viewerStatus = job.viewer_eligibility
                            ? deriveEligibilityStatus({
                                  ...job.viewer_eligibility,
                                  warning_reasons: job.viewer_eligibility
                                      .first_warning
                                      ? [job.viewer_eligibility.first_warning]
                                      : [],
                              })
                            : null;
                        const claimantStatus = job.eligibility
                            ? deriveEligibilityStatus({
                                  ...job.eligibility,
                                  warning_reasons: job.eligibility.first_warning
                                      ? [job.eligibility.first_warning]
                                      : [],
                              })
                            : null;
                        const claimBlockedReason =
                            job.viewer_eligibility?.blocked_reasons[0] ?? null;
                        const claimDisabled =
                            !!job.viewer_eligibility &&
                            !job.viewer_eligibility.is_eligible;

                        return (
                            <Card
                                key={job.id}
                                data-test="job-board-card"
                                className="transition-all hover:border-border hover:shadow-sm"
                            >
                                <CardContent className="p-4">
                                    <div className="flex items-start justify-between">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold">
                                                    {job.title}
                                                </span>
                                                <Badge
                                                    variant={
                                                        STATUS_VARIANTS[
                                                            job.status
                                                        ] ?? 'outline'
                                                    }
                                                    className="h-4 px-1.5 text-[9px] capitalize"
                                                >
                                                    {job.status}
                                                </Badge>
                                                {viewerStatus && (
                                                    <span data-test="viewer-eligibility">
                                                        <EligibilityStatusBadge
                                                            status={
                                                                viewerStatus.status
                                                            }
                                                            warningCount={
                                                                viewerStatus.warningCount
                                                            }
                                                            className="h-4 px-1.5 text-[9px]"
                                                        />
                                                    </span>
                                                )}
                                            </div>
                                            {job.client && (
                                                <p className="mt-0.5 text-xs text-muted-foreground">
                                                    {job.client.display_name}
                                                    {job.client.is_redacted &&
                                                    job.client.suburb
                                                        ? ` · ${job.client.suburb}`
                                                        : ''}
                                                </p>
                                            )}
                                            {job.replacement?.reason ? (
                                                <p className="mt-1 text-xs text-status-warning">
                                                    Replacement request:{' '}
                                                    {job.replacement.reason}
                                                </p>
                                            ) : null}
                                        </div>
                                    </div>
                                    <div className="mt-3 space-y-1.5 text-xs text-muted-foreground">
                                        <div className="flex items-center gap-1.5">
                                            <CalendarDays className="h-3 w-3" />
                                            <span>{formatDate(job.date)}</span>
                                        </div>
                                        <div className="flex items-center gap-1.5">
                                            <Clock className="h-3 w-3" />
                                            <span>
                                                {job.start_time} -{' '}
                                                {job.end_time}
                                            </span>
                                        </div>
                                        {job.location && (
                                            <div className="flex items-center gap-1.5">
                                                <MapPin className="h-3 w-3" />
                                                <span>{job.location}</span>
                                            </div>
                                        )}
                                        {job.replacement?.current_staff ? (
                                            <div className="flex items-center gap-1.5">
                                                <UserCheck className="h-3 w-3" />
                                                <span>
                                                    Covering for:{' '}
                                                    {
                                                        job.replacement
                                                            .current_staff.name
                                                    }
                                                </span>
                                            </div>
                                        ) : null}
                                    </div>
                                    {job.required_skills.length > 0 && (
                                        <div className="mt-2 flex flex-wrap gap-1">
                                            {job.required_skills.map(
                                                (skill) => (
                                                    <Badge
                                                        key={skill}
                                                        variant="outline"
                                                        className="h-4 px-1.5 text-[9px]"
                                                    >
                                                        {skill}
                                                    </Badge>
                                                ),
                                            )}
                                        </div>
                                    )}
                                    {job.coverage_roles.length > 0 && (
                                        <div className="mt-2 flex flex-wrap gap-1">
                                            {job.coverage_roles.map((role) => (
                                                <Badge
                                                    key={`${job.id}-${role}`}
                                                    variant="secondary"
                                                    className="h-4 px-1.5 text-[9px]"
                                                >
                                                    {role.replace(/_/g, ' ')}
                                                </Badge>
                                            ))}
                                        </div>
                                    )}
                                    {claimBlockedReason ? (
                                        <div
                                            data-test="viewer-eligibility-reason"
                                            className="mt-2 text-xs text-status-critical dark:text-status-critical"
                                        >
                                            {claimBlockedReason}
                                        </div>
                                    ) : null}
                                    {job.viewer_eligibility?.first_warning ? (
                                        <div className="mt-2 text-xs text-status-warning dark:text-status-warning">
                                            {
                                                job.viewer_eligibility
                                                    .first_warning
                                            }
                                        </div>
                                    ) : null}
                                    {job.claimed_by && (
                                        <div className="mt-2 space-y-1">
                                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                <UserCheck className="h-3 w-3" />
                                                <span>
                                                    Claimed by:{' '}
                                                    {job.claimed_by.name}
                                                </span>
                                                {claimantStatus ? (
                                                    <EligibilityStatusBadge
                                                        status={
                                                            claimantStatus.status
                                                        }
                                                        warningCount={
                                                            claimantStatus.warningCount
                                                        }
                                                        className="ml-1"
                                                    />
                                                ) : null}
                                            </div>
                                            {job.eligibility &&
                                            !job.eligibility.is_eligible &&
                                            job.eligibility.blocked_reasons
                                                .length > 0 ? (
                                                <div className="text-xs text-status-critical dark:text-status-critical">
                                                    {
                                                        job.eligibility
                                                            .blocked_reasons[0]
                                                    }
                                                </div>
                                            ) : null}
                                            {job.eligibility?.first_warning ? (
                                                <div className="text-xs text-status-warning dark:text-status-warning">
                                                    {
                                                        job.eligibility
                                                            .first_warning
                                                    }
                                                </div>
                                            ) : null}
                                        </div>
                                    )}
                                    {job.replacement?.requested_by ? (
                                        <div className="mt-1 text-xs text-muted-foreground">
                                            Requested by:{' '}
                                            {job.replacement.requested_by.name}
                                        </div>
                                    ) : null}
                                    <div className="mt-3 flex gap-2">
                                        {job.status === 'open' && (
                                            <Button
                                                size="sm"
                                                data-test="job-board-claim-button"
                                                className="h-7 flex-1 text-xs"
                                                disabled={claimDisabled}
                                                title={
                                                    claimBlockedReason ??
                                                    undefined
                                                }
                                                onClick={() =>
                                                    router.post(
                                                        `/operations/job-board/${job.id}/claim`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Hand className="mr-1 h-3 w-3" />{' '}
                                                Claim
                                            </Button>
                                        )}
                                        {job.status === 'claimed' && (
                                            <Button
                                                size="sm"
                                                variant={
                                                    job.eligibility &&
                                                    !job.eligibility.is_eligible
                                                        ? 'destructive'
                                                        : 'default'
                                                }
                                                className="h-7 flex-1 text-xs"
                                                disabled={
                                                    job.eligibility
                                                        ? !job.eligibility
                                                              .is_eligible
                                                        : false
                                                }
                                                onClick={() =>
                                                    router.post(
                                                        `/operations/job-board/${job.id}/approve`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <CheckCircle2 className="mr-1 h-3 w-3" />{' '}
                                                {job.eligibility &&
                                                !job.eligibility.is_eligible
                                                    ? 'Blocked'
                                                    : 'Approve'}
                                            </Button>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>

                {/* Pagination */}
                {(jobs?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(jobs?.links ?? []).map((link: any, i: number) => (
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
