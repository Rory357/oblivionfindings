import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Search, Briefcase, MapPin, Clock } from 'lucide-react';
import { useState } from 'react';
import { employmentTypeLabels } from '@/lib/job-posting-constants';

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
        router.get('/careers', {
            ...(merged.search ? { search: merged.search } : {}),
            ...(merged.position_role ? { position_role: merged.position_role } : {}),
            ...(merged.employment_type ? { employment_type: merged.employment_type } : {}),
            ...(merged.site ? { site: merged.site } : {}),
        }, { preserveState: true, replace: true });
    }

    return (
        <>
            <Head title="Careers" />
            <div className="mx-auto max-w-5xl px-4 py-10 space-y-6">
                <div className="space-y-2">
                    <h1 className="text-3xl font-bold">Careers</h1>
                    <p className="text-muted-foreground">Join our team and make a meaningful impact in the lives of the people we support.</p>
                </div>

                <div className="grid gap-3 md:grid-cols-4">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                        <Input
                            placeholder="Search positions..."
                            value={searchValue}
                            onChange={e => setSearchValue(e.target.value)}
                            onKeyDown={e => { if (e.key === 'Enter') submitFilters({ search: searchValue || null }); }}
                            className="pl-9"
                        />
                    </div>
                    <select
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                        value={filters.position_role || ''}
                        onChange={e => submitFilters({ position_role: e.target.value || null })}
                    >
                        <option value="">All roles</option>
                        {options.position_roles.map(role => <option key={role} value={role}>{role.replace(/_/g, ' ')}</option>)}
                    </select>
                    <select
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                        value={filters.site ?? ''}
                        onChange={e => submitFilters({ site: e.target.value ? Number(e.target.value) : null })}
                    >
                        <option value="">All sites</option>
                        {options.sites.map(site => <option key={site.id} value={site.id}>{site.name}</option>)}
                    </select>
                    <select
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                        value={filters.employment_type || ''}
                        onChange={e => submitFilters({ employment_type: e.target.value || null })}
                    >
                        <option value="">All types</option>
                        {options.employment_types.map(type => (
                            <option key={type} value={type}>{employmentTypeLabels[type] || type}</option>
                        ))}
                    </select>
                </div>

                <div className="grid gap-4">
                    {jobs.map(job => (
                        <Card key={job.id} className="hover:bg-accent/30 transition-colors">
                            <CardContent className="p-6">
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex-1 min-w-0">
                                        <Link href={`/careers/jobs/${job.slug}/apply`} className="text-lg font-semibold hover:underline">
                                            {job.title}
                                        </Link>
                                        <div className="flex flex-wrap gap-2 mt-2">
                                            <Badge variant="secondary" className="text-xs">
                                                <Briefcase className="mr-1 h-3 w-3" />
                                                {employmentTypeLabels[job.employment_type] || job.employment_type}
                                            </Badge>
                                            {job.position_role && (
                                                <span className="text-xs text-muted-foreground flex items-center gap-1">
                                                    {job.position_role.replace(/_/g, ' ')}
                                                </span>
                                            )}
                                            {job.site && (
                                                <span className="text-xs text-muted-foreground flex items-center gap-1">
                                                    <MapPin className="h-3 w-3" /> {job.site.name}
                                                </span>
                                            )}
                                        </div>
                                        {job.summary && (
                                            <p className="mt-2 text-sm text-muted-foreground line-clamp-2">{job.summary}</p>
                                        )}
                                    </div>
                                    <div className="flex flex-col items-end gap-2 shrink-0">
                                        <Button asChild>
                                            <Link href={`/careers/jobs/${job.slug}/apply`}>Apply</Link>
                                        </Button>
                                        <p className="text-xs text-muted-foreground">
                                            {job.closing_at ? (
                                                <span className="flex items-center gap-1"><Clock className="h-3 w-3" /> Closes {job.closing_at}</span>
                                            ) : 'Open until filled'}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    ))}

                    {jobs.length === 0 && (
                        <Card>
                            <CardContent className="py-16 text-center">
                                <Briefcase className="mx-auto h-12 w-12 text-muted-foreground/40 mb-4" />
                                <p className="text-muted-foreground font-medium">No open positions at this time</p>
                                <p className="text-sm text-muted-foreground mt-1">Check back soon for new opportunities.</p>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}
