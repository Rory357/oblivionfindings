import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { employmentTypeLabels } from '@/lib/job-posting-constants';
import { Head, Link, router } from '@inertiajs/react';
import { Briefcase, Clock, MapPin, Search } from 'lucide-react';
import { useState } from 'react';

type JobListing = {
    id: number;
    title: string;
    slug: string;
    position_role: string | null;
    employment_type: string;
    summary: string | null;
    site: { id: number; name: string } | null;
    published_at: string | null;
    closing_at: string | null;
};

type SiteOption = { id: number; name: string };

type Props = {
    jobs: JobListing[];
    options: {
        position_roles: string[];
        employment_types: string[];
        sites: SiteOption[];
    };
    filters: {
        search: string | null;
        position_role: string | null;
        employment_type: string | null;
        site: number | null;
    };
};

export default function CareersIndex({ jobs, options, filters }: Props) {
    const [searchValue, setSearchValue] = useState(filters.search || '');

    function submitFilters(next: Partial<Props['filters']>) {
        const merged = { ...filters, ...next };
        router.get(
            '/careers',
            {
                ...(merged.search ? { search: merged.search } : {}),
                ...(merged.position_role
                    ? { position_role: merged.position_role }
                    : {}),
                ...(merged.employment_type
                    ? { employment_type: merged.employment_type }
                    : {}),
                ...(merged.site ? { site: merged.site } : {}),
            },
            { preserveState: true, replace: true },
        );
    }

    const siteCount = options.sites.length;

    return (
        <>
            <Head title="Careers" />
            <div className="mx-auto max-w-5xl px-4 py-10">
                <PageLayout
                    padding="none"
                    hero={
                        <PageHero
                            icon={Briefcase}
                            title="Careers"
                            description="Join our team and make a meaningful impact in the lives of the people we support."
                            stats={[
                                { label: 'Open roles', value: jobs.length },
                                { label: 'Sites hiring', value: siteCount },
                                {
                                    label: 'Role types',
                                    value: options.position_roles.length,
                                },
                            ]}
                        />
                    }
                >
                    <div className="grid gap-3 md:grid-cols-4">
                        <div className="relative">
                            <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Search positions..."
                                value={searchValue}
                                onChange={(e) => setSearchValue(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter')
                                        submitFilters({
                                            search: searchValue || null,
                                        });
                                }}
                                className="pl-9"
                            />
                        </div>
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={filters.position_role || ''}
                            onChange={(e) =>
                                submitFilters({
                                    position_role: e.target.value || null,
                                })
                            }
                        >
                            <option value="">All roles</option>
                            {options.position_roles.map((role) => (
                                <option key={role} value={role}>
                                    {role.replace(/_/g, ' ')}
                                </option>
                            ))}
                        </select>
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={filters.site ?? ''}
                            onChange={(e) =>
                                submitFilters({
                                    site: e.target.value
                                        ? Number(e.target.value)
                                        : null,
                                })
                            }
                        >
                            <option value="">All sites</option>
                            {options.sites.map((site) => (
                                <option key={site.id} value={site.id}>
                                    {site.name}
                                </option>
                            ))}
                        </select>
                        <select
                            className="h-10 rounded-md border bg-background px-3 text-sm"
                            value={filters.employment_type || ''}
                            onChange={(e) =>
                                submitFilters({
                                    employment_type: e.target.value || null,
                                })
                            }
                        >
                            <option value="">All types</option>
                            {options.employment_types.map((type) => (
                                <option key={type} value={type}>
                                    {employmentTypeLabels[type] || type}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="grid gap-4">
                        {jobs.map((job) => (
                            <Card
                                key={job.id}
                                className="transition-colors hover:bg-accent/30"
                            >
                                <CardContent className="p-6">
                                    <div className="flex items-start justify-between gap-4">
                                        <div className="min-w-0 flex-1">
                                            <Link
                                                href={`/careers/jobs/${job.slug}/apply`}
                                                className="text-lg font-semibold hover:underline"
                                            >
                                                {job.title}
                                            </Link>
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                <Badge
                                                    variant="secondary"
                                                    className="text-xs"
                                                >
                                                    <Briefcase className="mr-1 h-3 w-3" />
                                                    {employmentTypeLabels[
                                                        job.employment_type
                                                    ] || job.employment_type}
                                                </Badge>
                                                {job.position_role && (
                                                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                        {job.position_role.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </span>
                                                )}
                                                {job.site && (
                                                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                        <MapPin className="h-3 w-3" />{' '}
                                                        {job.site.name}
                                                    </span>
                                                )}
                                            </div>
                                            {job.summary && (
                                                <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">
                                                    {job.summary}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex shrink-0 flex-col items-end gap-2">
                                            <Button asChild>
                                                <Link
                                                    href={`/careers/jobs/${job.slug}/apply`}
                                                >
                                                    Apply
                                                </Link>
                                            </Button>
                                            <p className="text-xs text-muted-foreground">
                                                {job.closing_at ? (
                                                    <span className="flex items-center gap-1">
                                                        <Clock className="h-3 w-3" />{' '}
                                                        Closes {job.closing_at}
                                                    </span>
                                                ) : (
                                                    'Open until filled'
                                                )}
                                            </p>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}

                        {jobs.length === 0 && (
                            <Card>
                                <CardContent className="py-16 text-center">
                                    <Briefcase className="mx-auto mb-4 h-12 w-12 text-muted-foreground/40" />
                                    <p className="font-medium text-muted-foreground">
                                        No open positions at this time
                                    </p>
                                    <p className="mt-1 text-sm text-muted-foreground">
                                        Check back soon for new opportunities.
                                    </p>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </PageLayout>
            </div>
        </>
    );
}
