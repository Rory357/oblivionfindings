import { Head, Link, router } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface Job {
    id: number;
    title: string;
    slug: string;
    position_role: string | null;
    employment_type: string;
    summary: string | null;
    site: { id: number; name: string } | null;
    published_at: string | null;
    closing_at: string | null;
}

interface Props {
    jobs: Job[];
    options: {
        position_roles: string[];
        employment_types: string[];
        sites: Array<{ id: number; name: string }>;
    };
    filters: {
        search: string;
        position_role: string | null;
        employment_type: string | null;
        site: number | null;
    };
}

export default function CareersIndex({ jobs, options, filters }: Props) {
    function submitFilters(next: Partial<{ search: string; position_role: string; employment_type: string; site: string }>) {
        const search = next.search ?? filters.search ?? '';
        const positionRole = next.position_role ?? (filters.position_role ?? 'all');
        const employmentType = next.employment_type ?? (filters.employment_type ?? 'all');
        const site = next.site ?? (filters.site ? String(filters.site) : 'all');

        router.get('/careers', {
            ...(search !== '' ? { search } : {}),
            ...(positionRole !== 'all' ? { position_role: positionRole } : {}),
            ...(employmentType !== 'all' ? { employment_type: employmentType } : {}),
            ...(site !== 'all' ? { site } : {}),
        }, { preserveState: true, replace: true });
    }

    return (
        <>
            <Head title="Careers" />
            <div className="mx-auto max-w-5xl px-4 py-10 space-y-6">
                <div className="space-y-2">
                    <h1 className="text-3xl font-bold">Careers</h1>
                    <p className="text-muted-foreground">Join our team and make a meaningful impact.</p>
                </div>

                <div className="grid gap-3 md:grid-cols-4">
                    <Input
                        placeholder="Search jobs..."
                        defaultValue={filters.search}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                submitFilters({ search: (e.target as HTMLInputElement).value });
                            }
                        }}
                    />
                    <select
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                        value={filters.position_role ?? 'all'}
                        onChange={(e) => submitFilters({ position_role: e.target.value })}
                    >
                        <option value="all">All roles</option>
                        {options.position_roles.map((role) => (
                            <option key={role} value={role}>{role.replace(/_/g, ' ')}</option>
                        ))}
                    </select>
                    <select
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                        value={filters.employment_type ?? 'all'}
                        onChange={(e) => submitFilters({ employment_type: e.target.value })}
                    >
                        <option value="all">All types</option>
                        {options.employment_types.map((type) => (
                            <option key={type} value={type}>{type.replace(/_/g, ' ')}</option>
                        ))}
                    </select>
                    <select
                        className="h-10 rounded-md border bg-background px-3 text-sm"
                        value={filters.site ? String(filters.site) : 'all'}
                        onChange={(e) => submitFilters({ site: e.target.value })}
                    >
                        <option value="all">All locations</option>
                        {options.sites.map((site) => (
                            <option key={site.id} value={site.id}>{site.name}</option>
                        ))}
                    </select>
                </div>

                <div className="grid gap-4">
                    {jobs.map((job) => (
                        <Card key={job.id}>
                            <CardHeader>
                                <CardTitle>{job.title}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <p className="text-sm text-muted-foreground">
                                    {job.position_role ? job.position_role.replace('_', ' ') : 'General role'}
                                    {' · '}
                                    {job.employment_type.replace('_', ' ')}
                                    {job.site?.name ? ` · ${job.site.name}` : ''}
                                </p>
                                {job.summary && <p className="text-sm">{job.summary}</p>}
                                <div className="flex items-center justify-between">
                                    <p className="text-xs text-muted-foreground">{job.closing_at ? `Closes ${job.closing_at}` : 'Open until filled'}</p>
                                    <Button asChild>
                                        <Link href={`/careers/jobs/${job.slug}/apply`}>Apply</Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}

                    {jobs.length === 0 && (
                        <Card>
                            <CardContent className="py-10 text-center text-muted-foreground">No open roles right now.</CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}

